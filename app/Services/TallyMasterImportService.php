<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TallyMapping;
use App\Models\TallySourceRecord;
use App\Models\TallySyncBatch;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\OpeningStockVoucher;
use App\Models\JournalVoucher;
use App\Models\BusinessAccountSetting;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TallyMasterImportService
{
    public function import(TallySyncBatch $batch): void
    {
        abort_unless((int) $batch->business_id === AppController::businessId(), 404);
        DB::transaction(function () use ($batch) {
            foreach ($batch->records()->whereIn('source_type', ['group', 'ledger', 'customer', 'supplier', 'unit', 'stock_item'])->get() as $record) {
                if ($record->status === 'imported') continue;
                $target = match ($record->source_type) {
                    'group' => $this->group($record), 'ledger' => $this->account($record), 'customer' => $this->customer($record),
                    'supplier' => $this->supplier($record), 'unit' => $this->unit($record), 'stock_item' => $this->product($record), default => null,
                };
                if (!$target) throw ValidationException::withMessages(['master' => "Unable to resolve Tally master '{$record->source_name}'."]);
                if ($record->source_type === 'stock_item') {
                    $this->importOpeningStock($batch, $record, $target);
                }
                if (in_array($record->source_type, ['customer', 'supplier', 'ledger'], true)) {
                    $this->importOpeningBalance($batch, $record, $target);
                }
                TallyMapping::updateOrCreate(['business_id' => $record->business_id, 'source_type' => $record->source_type, 'source_key' => $record->source_key], ['source_name' => $record->source_name, 'target_type' => get_class($target), 'target_id' => $target->id, 'status' => 'approved', 'confidence' => 100, 'evidence_json' => ['exact_name_or_source_key' => true]]);
                $record->update(['status' => 'imported', 'imported_type' => get_class($target), 'imported_id' => $target->id, 'imported_at' => now()]);
                $batch->increment('records_imported');
            }
        });
    }

    private function group(TallySourceRecord $record): AccountGroup
    {
        $payload = $record->payload_json ?: [];
        return AccountGroup::firstOrCreate(['business_id' => $record->business_id, 'group_name' => $record->source_name], ['group_code' => 'TALLY-' . Str::upper(substr(hash('sha1', $record->source_key), 0, 12)), 'group_type' => $this->groupType($record->source_group), 'nature' => $this->nature($record->source_group), 'status' => 'active']);
    }

    private function account(TallySourceRecord $record): Account
    {
        $existing = Account::where('business_id', $record->business_id)->where(function ($q) use ($record) { $q->where('account_name', $record->source_name)->orWhere('name', $record->source_name); })->first();
        if ($existing) return $existing;
        $payload = $record->payload_json ?: [];
        $group = AccountGroup::where('business_id', $record->business_id)->where('group_name', $record->source_group)->first();
        $opening = (float) ($payload['opening_balance'] ?? 0);
        return Account::create(['business_id' => $record->business_id, 'account_group_id' => $group?->id, 'account_code' => 'TALLY-' . Str::upper(substr(hash('sha1', $record->source_key), 0, 12)), 'code' => 'TALLY-' . substr(hash('sha1', $record->source_key), 0, 12), 'account_name' => $record->source_name, 'name' => $record->source_name, 'account_type' => $this->nature($record->source_group), 'opening_balance' => 0, 'opening_balance_type' => 'debit', 'status' => 'active']);
    }

    private function customer(TallySourceRecord $record): Customer
    {
        $opening = (float) data_get($record->payload_json, 'opening_balance', 0);
        $customer = Customer::firstOrCreate(['business_id' => $record->business_id, 'customer_name' => $record->source_name], ['name' => $record->source_name, 'gstin' => data_get($record->payload_json, 'gstin'), 'opening_balance' => abs($opening), 'opening_balance_type' => $opening < 0 ? 'credit' : 'debit', 'status' => 'active']);
        $this->partyAccount($record, $customer->customer_name, 'asset');
        return $customer;
    }

    private function supplier(TallySourceRecord $record): Supplier
    {
        $opening = (float) data_get($record->payload_json, 'opening_balance', 0);
        $supplier = Supplier::firstOrCreate(['business_id' => $record->business_id, 'supplier_name' => $record->source_name], ['name' => $record->source_name, 'gstin' => data_get($record->payload_json, 'gstin'), 'opening_balance' => abs($opening), 'opening_balance_type' => $opening < 0 ? 'credit' : 'debit', 'status' => 'active']);
        $this->partyAccount($record, $supplier->supplier_name, 'liability');
        return $supplier;
    }

    private function unit(TallySourceRecord $record): Unit { return Unit::firstOrCreate(['code' => Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $record->source_name), 12, ''))], ['name' => $record->source_name, 'status' => 'active']); }

    private function product(TallySourceRecord $record): Product
    {
        $existing = Product::where('business_id', $record->business_id)->where('name', $record->source_name)->first();
        if ($existing) return $existing;
        $payload = $record->payload_json ?: [];
        $unit = !empty($payload['unit']) ? Unit::where('name', $payload['unit'])->orWhere('code', $payload['unit'])->first() : null;
        return Product::create(['business_id' => $record->business_id, 'name' => $record->source_name, 'sku' => 'TALLY-' . Str::upper(substr(hash('sha1', $record->source_key), 0, 12)), 'product_type' => 'goods', 'item_type' => 'stock', 'unit_id' => $unit?->id, 'hsn_code' => $payload['hsn_code'] ?? null, 'opening_stock' => 0, 'current_stock' => 0, 'gst_rate' => $payload['gst_rate'] ?? 0, 'status' => 'active']);
    }

    private function groupType(?string $group): string { return $this->nature($group); }
    private function partyAccount(TallySourceRecord $record, string $name, string $type): Account
    {
        return Account::firstOrCreate(['business_id' => $record->business_id, 'account_name' => $name], ['name' => $name, 'account_code' => 'TALLY-' . Str::upper(substr(hash('sha1', $record->source_key), 0, 12)), 'code' => 'TALLY-' . substr(hash('sha1', $record->source_key), 0, 12), 'account_type' => $type, 'opening_balance' => 0, 'opening_balance_type' => 'debit', 'status' => 'active']);
    }

    private function importOpeningStock(TallySyncBatch $batch, TallySourceRecord $record, Product $product): void
    {
        $payload = $record->payload_json ?: [];
        $quantity = (float) ($payload['opening_quantity'] ?? 0);
        if ($quantity <= 0) return;
        $branchId = $batch->connection?->branch_id ?: AppController::branchId();
        if (!$branchId) $branchId = Branch::where('business_id', $record->business_id)->where('status', 'active')->value('id');
        $warehouseName = $payload['warehouse'] ?? $payload['godown'] ?? null;
        $warehouse = Warehouse::where('business_id', $record->business_id)->when($warehouseName, fn ($q) => $q->where('name', $warehouseName))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->where('status', 'active')->first();
        if (!$warehouse) throw ValidationException::withMessages(['opening_stock' => "No active BillIQ warehouse could be matched for Tally item '{$record->source_name}'."]);
        if (!$branchId) $branchId = $warehouse->branch_id;
        $sourceUnit = strtoupper(trim((string) ($payload['unit'] ?? '')));
        $unitModel = $product->unit_id ? Unit::find($product->unit_id) : null;
        $productUnit = strtoupper(trim((string) ($unitModel?->code ?: $unitModel?->name ?: $product->unit ?: '')));
        if ($sourceUnit && $productUnit && $sourceUnit !== $productUnit && strtolower($sourceUnit) !== strtolower((string) ($unitModel?->name ?: $product->unit ?: ''))) {
            throw ValidationException::withMessages(['opening_stock' => "Unit '{$sourceUnit}' for '{$record->source_name}' has no verified conversion to '{$productUnit}'."]);
        }
        $scopeLedger = DB::table('stock_ledgers')->where('business_id', $record->business_id)->where('branch_id', $branchId)->where('warehouse_id', $warehouse->id)->where('product_id', $product->id);
        if ($scopeLedger->exists() || (float) $product->current_stock != 0.0 || (float) $product->opening_stock != 0.0) {
            throw ValidationException::withMessages(['opening_stock' => "Existing stock was found for '{$record->source_name}'. Opening stock requires review and will not overwrite it."]);
        }
        $value = (float) ($payload['opening_value'] ?? 0);
        $unitCost = $value > 0 ? round($value / $quantity, 2) : (float) ($product->cost_price ?: 0);
        app(OpeningStockService::class)->create(['branch_id' => $branchId, 'warehouse_id' => $warehouse->id, 'opening_date' => $batch->date_from ?: now()->toDateString(), 'remarks' => 'Tally opening stock [' . $record->source_key . ']', 'status' => 'posted', 'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'purchase_cost' => $unitCost, 'remarks' => 'Tally source ' . $record->source_key]]]);
    }

    private function importOpeningBalance(TallySyncBatch $batch, TallySourceRecord $record, object $target): void
    {
        $amount = (float) data_get($record->payload_json, 'opening_balance', 0);
        if ($amount == 0.0) return;
        $existing = (float) ($target->opening_balance ?? 0);
        if ($existing != 0.0 && abs(abs($existing) - abs($amount)) > 0.01) throw ValidationException::withMessages(['opening_balance' => "Existing opening balance for '{$record->source_name}' differs from Tally; review is required."]);
        if ($existing != 0.0) return;
        $settings = BusinessAccountSetting::where('business_id', $record->business_id)->first();
        $control = $record->source_type === 'customer' ? $settings?->accounts_receivable_id : ($record->source_type === 'supplier' ? $settings?->accounts_payable_id : $target->id);
        $offset = Account::where('business_id', $record->business_id)->whereIn('account_type', ['equity', 'capital'])->value('id');
        if (!$control || !$offset) throw ValidationException::withMessages(['opening_balance' => 'Opening balance control accounts are not configured.']);
        $debit = $amount > 0 ? abs($amount) : 0; $credit = $amount < 0 ? abs($amount) : 0;
        if ($record->source_type === 'supplier') { $debit = $amount < 0 ? abs($amount) : 0; $credit = $amount > 0 ? abs($amount) : 0; }
        $entries = [['account_id' => $control, 'debit_amount' => $debit, 'credit_amount' => $credit, $record->source_type === 'customer' ? 'customer_id' : ($record->source_type === 'supplier' ? 'supplier_id' : null) => $record->source_type === 'ledger' ? null : $target->id], ['account_id' => $offset, 'debit_amount' => $credit, 'credit_amount' => $debit]];
        $voucher = app(\App\Services\AccountingPostingService::class)->createJournalVoucher(['business_id' => $record->business_id, 'voucher_type' => 'opening_balance', 'voucher_date' => $batch->date_from ?: now()->toDateString(), 'reference_type' => get_class($target), 'reference_id' => $target->id, 'reference_number' => 'TALLY-OPENING-' . $record->source_key, 'narration' => 'Tally opening balance', 'status' => 'approved', 'is_system_generated' => true, 'entries' => $entries]);
        $target->update(['opening_balance' => abs($amount), 'opening_balance_type' => $amount < 0 ? 'credit' : 'debit']);
        if ($record->source_type === 'customer' && \Schema::hasColumn('customers', 'opening_balance_voucher_id')) $target->update(['opening_balance_voucher_id' => $voucher->id]);
    }
    private function nature(?string $group): string { $value = strtolower((string) $group); return str_contains($value, 'expense') || str_contains($value, 'purchase') ? 'expense' : (str_contains($value, 'income') || str_contains($value, 'sales') ? 'income' : (str_contains($value, 'capital') || str_contains($value, 'liabil') || str_contains($value, 'creditor') ? 'liability' : 'asset')); }
}
