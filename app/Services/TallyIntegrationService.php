<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TallyConnection;
use App\Models\TallyMapping;
use App\Models\TallyReconciliationItem;
use App\Models\TallySourceRecord;
use App\Models\TallySyncBatch;
use App\Models\JournalVoucher;
use App\Models\PurchaseVoucher;
use App\Models\SalesVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TallyIntegrationService
{
    public function overview(int $businessId): array
    {
        $connections = TallyConnection::where('business_id', $businessId)->latest()->get();
        $batches = TallySyncBatch::where('business_id', $businessId)->latest()->limit(20)->get();
        $latest = $batches->first();
        return [
            'connections' => $connections->map(fn ($c) => $this->connectionPayload($c))->values(),
            'batches' => $batches->map(fn ($b) => $this->batchPayload($b))->values(),
            'latest_batch' => $latest ? $this->batchPayload($latest) : null,
            'counts' => [
                'connections' => $connections->count(),
                'unmatched' => TallySourceRecord::where('business_id', $businessId)->whereIn('status', ['unmatched', 'conflict'])->count(),
                'pending_approval' => $batches->where('status', 'ready_for_review')->count(),
            ],
        ];
    }

    public function createConnection(array $data, int $businessId, ?int $userId): TallyConnection
    {
        return TallyConnection::create([
            'business_id' => $businessId, 'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'] ?? 'Tally connection', 'location' => $data['location'],
            'cloud_type' => $data['cloud_type'] ?? null, 'provider' => $data['provider'] ?? null, 'endpoint_url' => $data['endpoint_url'] ?? null,
            'credentials' => $data['credentials'] ?? null, 'status' => 'pending', 'created_by' => $userId,
            'settings_json' => ['import_types' => $data['import_types'] ?? ['masters', 'vouchers', 'inventory']],
        ]);
    }

    public function issuePairingCode(TallyConnection $connection): string
    {
        $token = strtoupper(Str::random(6)) . '-' . strtoupper(Str::random(6));
        $connection->update(['pairing_token_hash' => hash('sha256', $token), 'pairing_expires_at' => now()->addMinutes(15), 'status' => 'pairing']);
        return $token;
    }

    /**
     * Exchanges a short-lived, single-use code for the credential used by the Windows connector.
     * The plaintext credential is intentionally returned only once, to the holder of the code.
     */
    public function exchangePairingCode(string $code): ?array
    {
        return DB::transaction(function () use ($code) {
            $connection = TallyConnection::where('pairing_token_hash', hash('sha256', $code))
                ->whereIn('status', ['pending', 'pairing', 'connected'])
                ->whereNull('revoked_at')
                ->where('pairing_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$connection) return null;

            $token = bin2hex(random_bytes(32));
            $connection->update([
                'connector_token_hash' => hash('sha256', $token),
                'connector_token_issued_at' => now(),
                'pairing_token_hash' => null,
                'pairing_expires_at' => null,
                'status' => 'connected',
            ]);

            return ['connection' => $connection->fresh(), 'connector_token' => $token];
        });
    }

    public function authenticateConnector(string $token): ?TallyConnection
    {
        if ($token === '') return null;
        return TallyConnection::where('connector_token_hash', hash('sha256', $token))
            ->where('status', 'connected')->whereNull('revoked_at')->first();
    }

    public function ingest(TallyConnection $connection, array $payload, ?string $requestId = null): TallySyncBatch
    {
        $records = $payload['records'] ?? [];
        if (!is_array($records)) throw ValidationException::withMessages(['records' => 'Connector records must be an array.']);
        return DB::transaction(function () use ($connection, $payload, $records, $requestId) {
            if ($requestId) {
                $existingBatch = TallySyncBatch::where('business_id', $connection->business_id)->where('connector_request_id', $requestId)->first();
                if ($existingBatch) return $existingBatch->fresh();
            }
            $batch = TallySyncBatch::create([
                'business_id' => $connection->business_id, 'tally_connection_id' => $connection->id,
                'connector_request_id' => $requestId,
                'status' => 'analyzing', 'mode' => $payload['mode'] ?? 'incremental',
                'date_from' => $payload['date_from'] ?? null, 'date_to' => $payload['date_to'] ?? null,
            ]);
            $ready = 0;
            foreach ($records as $record) {
                try {
                    $record = app(TallyXmlNormalizer::class)->normalize($record);
                    $normalizationError = null;
                } catch (ValidationException $exception) {
                    $normalizationError = collect($exception->errors())->flatten()->first() ?: $exception->getMessage();
                    $record = ['type' => 'unsupported', 'key' => $record['key'] ?? Str::uuid(), 'name' => $record['name'] ?? null, 'payload' => $record['payload'] ?? []];
                }
                $type = (string) ($record['type'] ?? 'unknown');
                $key = (string) ($record['key'] ?? $record['guid'] ?? $record['number'] ?? Str::uuid());
                $fingerprint = hash('sha256', json_encode([$type, $key, $record['date'] ?? null, $record['amount'] ?? null]));
                $existing = TallySourceRecord::where('business_id', $connection->business_id)->where('source_type', $type)->where('source_key', $key)->first();
                if ($existing) { $existing->update(['tally_sync_batch_id' => $batch->id, 'payload_json' => $record, 'fingerprint' => $fingerprint]); continue; }
                $mapping = $normalizationError ? ['status' => 'error', 'target_type' => 'source', 'target_id' => null] : $this->suggestMapping($connection->business_id, $type, $key, $record);
                TallySourceRecord::create([
                    'business_id' => $connection->business_id, 'tally_sync_batch_id' => $batch->id,
                    'source_type' => $type, 'source_key' => $key, 'source_name' => $record['name'] ?? $record['ledger_name'] ?? null,
                    'source_group' => $record['group'] ?? $record['parent'] ?? null, 'source_date' => $record['date'] ?? null,
                    'source_number' => $record['number'] ?? null, 'debit_amount' => $record['debit'] ?? 0, 'credit_amount' => $record['credit'] ?? 0,
                    'quantity' => $record['quantity'] ?? null, 'status' => $mapping['status'], 'fingerprint' => $fingerprint,
                    'payload_json' => $record['payload'] ?? $record, 'validation_json' => ['mapping' => $mapping, 'normalization_error' => $normalizationError],
                ]);
                if ($mapping['status'] === 'matched') $ready++;
            }
            $batch->update(['status' => 'ready_for_review', 'records_received' => count($records), 'records_ready' => $ready, 'summary_json' => ['types' => collect($records)->countBy('type')->all()]]);
            $connection->update(['status' => 'connected', 'last_seen_at' => now(), 'tally_company_name' => $payload['company_name'] ?? $connection->tally_company_name, 'tally_company_guid' => $payload['company_guid'] ?? $connection->tally_company_guid]);
            return $batch->fresh();
        });
    }

    public function saveMapping(int $businessId, array $data, int $userId): TallyMapping
    {
        $mapping = TallyMapping::updateOrCreate(
            ['business_id' => $businessId, 'source_type' => $data['source_type'], 'source_key' => $data['source_key']],
            ['source_name' => $data['source_name'] ?? null, 'target_type' => $data['target_type'], 'target_id' => $data['target_id'] ?? null, 'status' => 'approved', 'confidence' => 100, 'approved_by' => $userId, 'approved_at' => now(), 'evidence_json' => ['manual' => true]]
        );
        TallySourceRecord::where('business_id', $businessId)->where('source_type', $data['source_type'])->where('source_key', $data['source_key'])->update(['status' => 'matched']);
        return $mapping;
    }

    public function approve(TallySyncBatch $batch, int $userId): TallySyncBatch
    {
        $unresolved = $batch->records()->whereIn('status', ['unmatched', 'conflict', 'error'])->count();
        if ($unresolved) throw ValidationException::withMessages(['batch' => "Resolve {$unresolved} unmatched, conflicting or unsupported records before import approval."]);
        $batch->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);
        return $batch->fresh();
    }

    public function reconcile(TallySyncBatch $batch): array
    {
        $businessId = $batch->business_id;
        $items = [];
        foreach ([['sales', SalesVoucher::class, 'grand_total'], ['purchase', PurchaseVoucher::class, 'grand_total']] as [$type, $model, $amountColumn]) {
            $records = $batch->records()->where('source_type', $type)->get();
            $tally = $records->sum(fn ($record) => (float) ($record->payload_json['grand_total'] ?? $record->payload_json['amount'] ?? abs((float) $record->debit_amount - (float) $record->credit_amount)));
            $ids = $records->where('imported_type', $model)->pluck('imported_id')->filter();
            $billiq = $ids->isEmpty() ? null : (float) $model::where('business_id', $businessId)->whereIn('id', $ids)->sum($amountColumn);
            $items[] = $this->comparisonItem($type . '_total', $tally, $billiq, ['source_records' => $records->count(), 'imported_records' => $ids->count()]);
        }
        $journalRecords = $batch->records()->whereIn('source_type', ['journal', 'contra'])->get();
        $journalIds = $journalRecords->where('imported_type', JournalVoucher::class)->pluck('imported_id')->filter();
        $tallyDebits = (float) $journalRecords->sum('debit_amount'); $tallyCredits = (float) $journalRecords->sum('credit_amount');
        $billiqDebits = $billiqCredits = null;
        if ($journalIds->isNotEmpty()) { $billiqDebits = (float) DB::table('journal_entries')->whereIn('journal_voucher_id', $journalIds)->sum('debit_amount'); $billiqCredits = (float) DB::table('journal_entries')->whereIn('journal_voucher_id', $journalIds)->sum('credit_amount'); }
        $items[] = $this->comparisonItem('trial_balance_debits', $tallyDebits, $billiqDebits, ['source_records' => $journalRecords->count()]);
        $items[] = $this->comparisonItem('trial_balance_credits', $tallyCredits, $billiqCredits, ['source_records' => $journalRecords->count()]);
        foreach ([['customer', Customer::class, 'customer_outstanding'], ['supplier', Supplier::class, 'supplier_outstanding']] as [$type, $model, $category]) {
            $records = $batch->records()->where('source_type', $type)->get();
            $source = $records->sum(fn ($record) => (float) data_get($record->payload_json, 'closing_balance', data_get($record->payload_json, 'opening_balance', 0)));
            $ids = $records->where('imported_type', $model)->pluck('imported_id')->filter();
            $actual = $ids->isEmpty() ? null : $this->partyClosingBalance($type, $model, $ids, $batch);
            $items[] = $this->comparisonItem($category, $source, $actual, ['source_records' => $records->count(), 'imported_records' => $ids->count(), 'note' => 'Tally closing balances are compared with BillIQ opening balances plus imported invoice movements less allocations.']);
        }
        $openingRecords = $batch->records()->whereIn('source_type', ['ledger', 'customer', 'supplier'])->get();
        $openingSource = $openingRecords->sum(fn ($record) => (float) data_get($record->payload_json, 'opening_balance', 0));
        $openingActual = null;
        if ($openingRecords->isNotEmpty() && !$openingRecords->whereNull('imported_id')->count()) {
            $openingActual = (float) Account::where('business_id', $businessId)->whereIn('id', $openingRecords->where('source_type', 'ledger')->pluck('imported_id'))->sum('opening_balance');
            $openingActual += (float) Customer::where('business_id', $businessId)->whereIn('id', $openingRecords->where('source_type', 'customer')->pluck('imported_id'))->sum('opening_balance');
            $openingActual += (float) Supplier::where('business_id', $businessId)->whereIn('id', $openingRecords->where('source_type', 'supplier')->pluck('imported_id'))->sum('opening_balance');
        }
        $items[] = $this->comparisonItem('opening_balances', (float) $openingSource, $openingActual, ['source_records' => $openingRecords->count(), 'note' => 'Opening balances are compared only for successfully imported masters.']);
        $stockRecords = $batch->records()->where('source_type', 'stock_item')->get();
        $stockQty = $stockRecords->sum(fn ($record) => (float) data_get($record->payload_json, 'closing_quantity', data_get($record->payload_json, 'opening_quantity', 0)));
        $stockSourceKeys = $stockRecords->pluck('source_name')->filter();
        foreach ($batch->records()->whereIn('source_type', ['sales', 'purchase', 'sales_return', 'purchase_return'])->get() as $movement) {
            foreach ((array) data_get($movement->payload_json, 'inventory_allocations', []) as $line) {
                if ($stockSourceKeys->contains($line['item_name'] ?? null)) {
                    $quantity = abs((float) ($line['quantity'] ?? 0));
                    $stockQty += in_array($movement->source_type, ['sales', 'purchase_return'], true) ? -$quantity : $quantity;
                }
            }
        }
        $stockValue = $stockRecords->sum(fn ($record) => (float) data_get($record->payload_json, 'closing_value', data_get($record->payload_json, 'opening_value', 0)));
        $stockActual = null;
        if ($stockRecords->isNotEmpty()) {
            $productIds = $stockRecords->where('imported_type', Product::class)->pluck('imported_id')->filter();
            if ($productIds->isNotEmpty()) $stockActual = (float) DB::table('stock_ledgers')->where('business_id', $businessId)->whereIn('product_id', $productIds)->sum(DB::raw('quantity_in - quantity_out'));
        }
        $items[] = $this->comparisonItem('stock_quantity', $stockQty, $stockActual, ['source_records' => $stockRecords->count(), 'warehouse_level' => true]);
        $items[] = $this->comparisonItem('inventory_valuation', $stockValue, null, ['source_records' => $stockRecords->count(), 'reason' => 'BillIQ valuation method and Tally valuation basis must be explicitly selected.']);
        $gstSource = 0.0; $gstActual = 0.0; $gstRecords = $batch->records()->whereIn('source_type', ['sales', 'purchase', 'sales_return', 'purchase_return'])->get();
        foreach ($gstRecords as $record) foreach ((array) data_get($record->payload_json, 'ledger_entries', []) as $entry) if (preg_match('/(cgst|sgst|igst|cess)/i', (string) ($entry['name'] ?? ''))) $gstSource += (float) ($entry['amount'] ?? 0);
        $salesIds = $gstRecords->where('imported_type', SalesVoucher::class)->pluck('imported_id')->filter(); $purchaseIds = $gstRecords->where('imported_type', PurchaseVoucher::class)->pluck('imported_id')->filter();
        if ($salesIds->isNotEmpty()) $gstActual += (float) SalesVoucher::where('business_id', $businessId)->whereIn('id', $salesIds)->sum(DB::raw('cgst_amount + sgst_amount + igst_amount + cess_amount'));
        if ($purchaseIds->isNotEmpty()) $gstActual += (float) PurchaseVoucher::where('business_id', $businessId)->whereIn('id', $purchaseIds)->sum(DB::raw('cgst_amount + sgst_amount + igst_amount + cess_amount'));
        $items[] = $this->comparisonItem('gst_total', $gstSource, ($salesIds->isNotEmpty() || $purchaseIds->isNotEmpty()) ? $gstActual : null, ['source_records' => $gstRecords->count(), 'note' => 'GST ledger names are compared against posted voucher tax totals.']);
        $items[] = ['category' => 'source_records', 'tally' => (float) $batch->records()->count(), 'billiq' => (float) $batch->records()->where('status', 'imported')->count(), 'status' => $batch->records()->where('status', 'error')->exists() ? 'mismatch' : 'match', 'details' => ['reason' => 'Counts of staged versus imported source records.']];
        foreach ($items as $item) TallyReconciliationItem::updateOrCreate(['tally_sync_batch_id' => $batch->id, 'category' => $item['category']], ['business_id' => $businessId, 'status' => $item['status'], 'tally_amount' => $item['tally'], 'billiq_amount' => $item['billiq'], 'difference_amount' => $item['billiq'] === null ? null : $item['tally'] - $item['billiq'], 'details_json' => $item['details']]);
        return $batch->reconciliationItems()->get()->map(fn ($i) => ['category' => $i->category, 'status' => $i->status, 'tally_amount' => (float) $i->tally_amount, 'billiq_amount' => $i->billiq_amount === null ? null : (float) $i->billiq_amount, 'difference_amount' => $i->difference_amount === null ? null : (float) $i->difference_amount, 'details' => $i->details_json])->all();
    }

    private function comparisonItem(string $category, float $tally, ?float $billiq, array $details): array
    {
        return ['category' => $category, 'tally' => $tally, 'billiq' => $billiq, 'status' => $billiq === null ? 'unavailable' : (abs($tally - $billiq) < 0.01 ? 'match' : 'mismatch'), 'details' => $details];
    }

    private function partyClosingBalance(string $type, string $model, $ids, TallySyncBatch $batch): float
    {
        $parties = $model::where('business_id', $batch->business_id)->whereIn('id', $ids)->get();
        $balance = $parties->sum(function ($party) use ($type) {
            $opening = (float) $party->opening_balance;
            return ($type === 'customer' && ($party->opening_balance_type ?? 'debit') === 'credit') || ($type === 'supplier' && ($party->opening_balance_type ?? 'credit') === 'debit') ? -$opening : $opening;
        });
        $partyColumn = $type === 'customer' ? 'customer_id' : 'supplier_id';
        $journalMovement = DB::table('journal_entries')->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_entries.journal_voucher_id')->where('journal_entries.business_id', $batch->business_id)->whereIn("journal_entries.$partyColumn", $ids)->whereIn('journal_vouchers.status', ['approved', 'posted'])->selectRaw('COALESCE(SUM(journal_entries.debit_amount), 0) - COALESCE(SUM(journal_entries.credit_amount), 0) as movement')->value('movement');
        $balance += (float) $journalMovement;
        return round($balance, 2);
    }

    public function testEndpoint(TallyConnection $connection): array
    {
        if ($connection->location === 'cloud' && $connection->cloud_type === 'api') return app(TallyCloudApiAdapter::class)->test($connection);
        if (!$connection->endpoint_url) return ['ok' => false, 'message' => 'No endpoint is configured.'];
        try {
            $xml = '<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST><TYPE>Data</TYPE><ID>List of Companies</ID></HEADER><BODY><DESC><STATICVARIABLES><SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT></STATICVARIABLES></DESC></BODY></ENVELOPE>';
            $response = Http::timeout(8)->accept('application/xml')->withBody($xml, 'application/xml')->post(rtrim($connection->endpoint_url, '/') . '/');
            return ['ok' => $response->successful(), 'message' => $response->successful() ? 'Tally endpoint responded.' : 'Tally endpoint returned HTTP ' . $response->status()];
        } catch (\Throwable $e) { return ['ok' => false, 'message' => 'Tally endpoint is unreachable. Check that Tally is open and the connector is running.']; }
    }

    public function revoke(TallyConnection $connection): void
    {
        $connection->update(['status' => 'revoked', 'revoked_at' => now(), 'pairing_token_hash' => null, 'pairing_expires_at' => null, 'connector_token_hash' => null, 'connector_token_issued_at' => null]);
    }

    private function suggestMapping(int $businessId, string $type, string $key, array $record): array
    {
        $targetType = match ($type) { 'ledger', 'group' => 'account', 'customer' => 'customer', 'supplier' => 'supplier', 'stock_item' => 'product', default => 'source' };
        $name = trim((string) ($record['name'] ?? $record['ledger_name'] ?? ''));
        $target = null;
        if ($targetType === 'account' && $name) $target = Account::where('business_id', $businessId)->where(function ($q) use ($name) { $q->where('account_name', $name)->orWhere('name', $name); })->first();
        if ($targetType === 'customer' && $name) $target = Customer::where('business_id', $businessId)->where('customer_name', $name)->first();
        if ($targetType === 'supplier' && $name) $target = Supplier::where('business_id', $businessId)->where('supplier_name', $name)->first();
        if ($targetType === 'product' && $name) $target = Product::where('business_id', $businessId)->where('name', $name)->first();
        if ($target) TallyMapping::updateOrCreate(['business_id' => $businessId, 'source_type' => $type, 'source_key' => $key], ['source_name' => $name, 'target_type' => $targetType, 'target_id' => $target->id, 'status' => 'approved', 'confidence' => 100, 'evidence_json' => ['exact_name' => true]]);
        $safeMaster = in_array($type, ['group', 'ledger', 'customer', 'supplier', 'unit', 'stock_item'], true);
        $supportedVoucher = in_array($type, ['sales', 'purchase', 'receipt', 'payment', 'journal', 'contra', 'sales_return', 'purchase_return'], true);
        return ['status' => ($target || $safeMaster || $supportedVoucher) ? 'matched' : 'unmatched', 'target_type' => $targetType, 'target_id' => $target?->id, 'evidence' => $target ? 'exact_name' : ($safeMaster ? 'safe_master_create' : ($supportedVoucher ? 'normalized_voucher_review' : null))];
    }

    public function connectionPayload(TallyConnection $c): array { return ['id' => $c->id, 'name' => $c->name, 'location' => $c->location, 'cloud_type' => $c->cloud_type, 'provider' => $c->provider, 'status' => $c->status, 'endpoint_url' => $c->endpoint_url, 'company_name' => $c->tally_company_name, 'last_seen_at' => optional($c->last_seen_at)->toDateTimeString()]; }
    public function batchPayload(TallySyncBatch $b): array { return ['id' => $b->id, 'connection_id' => $b->tally_connection_id, 'status' => $b->status, 'mode' => $b->mode, 'records_received' => $b->records_received, 'records_ready' => $b->records_ready, 'records_imported' => $b->records_imported, 'records_failed' => $b->records_failed, 'summary' => $b->summary_json ?? [], 'created_at' => optional($b->created_at)->toDateTimeString()]; }
}
