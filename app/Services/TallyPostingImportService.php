<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\PaymentVoucher;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\PurchaseReturnVoucher;
use App\Models\PurchaseVoucher;
use App\Models\ReceiptVoucher;
use App\Models\SalesReturnVoucher;
use App\Models\SalesVoucher;
use App\Models\TallySourceRecord;
use App\Models\TallySyncBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TallyPostingImportService
{
    public function __construct(
        private AccountingService $accounting,
        private SalesService $sales,
        private PurchaseService $purchases,
        private SalesReturnService $salesReturns,
        private PurchaseReturnService $purchaseReturns,
        private TallyMasterImportService $masters,
    ) {}

    public function import(TallySyncBatch $batch, int $userId): TallySyncBatch
    {
        abort_unless((int) $batch->business_id === AppController::businessId(), 404);
        if ($batch->status !== 'approved') throw ValidationException::withMessages(['batch' => 'Only an approved batch can be imported.']);

        return DB::transaction(function () use ($batch, $userId) {
            $this->masters->import($batch);
            $batch->load('records');
            foreach ($batch->records as $record) {
                if ($record->status === 'imported') continue;
                try {
                    $result = $this->postRecord($record);
                    $record->update(['status' => 'imported', 'imported_type' => get_class($result), 'imported_id' => $result->id, 'imported_at' => now(), 'import_error' => null]);
                    $batch->increment('records_imported');
                } catch (\Throwable $e) {
                    $record->update(['status' => 'error', 'import_error' => $e->getMessage()]);
                    $batch->increment('records_failed');
                    throw $e;
                }
            }
            $batch->update(['status' => 'imported', 'completed_at' => now()]);
            return $batch->fresh();
        });
    }

    private function postRecord(TallySourceRecord $record): object
    {
        $data = $record->payload_json ?: [];
        return match ($record->source_type) {
            'sales' => $this->postSales($record, $data),
            'purchase' => $this->postPurchase($record, $data),
            'sales_return', 'credit_note' => $this->postSalesReturn($record, $data),
            'purchase_return', 'debit_note' => $this->postPurchaseReturn($record, $data),
            'receipt' => $this->postReceipt($record, $data),
            'payment' => $this->postPayment($record, $data),
            'journal' => $this->postJournal($record, $data),
            'contra' => $this->postContra($record, $data),
            default => throw ValidationException::withMessages(['record' => "Unsupported Tally source type '{$record->source_type}'."]),
        };
    }

    private function postSales(TallySourceRecord $record, array $d): SalesVoucher
    {
        $this->assertNoVoucherConflict(SalesVoucher::class, $record, $d['invoice_number'] ?? $record->source_number);
        $d = $this->voucherData($record, $d, 'sales');
        return $this->sales->create(array_merge($d, ['status' => 'approved', 'invoice_number' => $d['invoice_number'] ?? $record->source_number, 'reference_number' => $d['reference_number'] ?: ($record->source_number ?: $record->source_key)]));
    }

    private function postPurchase(TallySourceRecord $record, array $d): PurchaseVoucher
    {
        $this->assertNoVoucherConflict(PurchaseVoucher::class, $record, $d['voucher_number'] ?? $record->source_number);
        $d = $this->voucherData($record, $d, 'purchase');
        return $this->purchases->create(array_merge($d, ['status' => 'approved', 'voucher_number' => $d['voucher_number'] ?? $record->source_number, 'supplier_invoice_number' => $d['supplier_invoice_number'] ?? $record->source_number]));
    }

    private function postSalesReturn(TallySourceRecord $record, array $d): SalesReturnVoucher
    {
        $this->assertNoVoucherConflict(SalesReturnVoucher::class, $record, $d['credit_note_number'] ?? $record->source_number);
        return $this->salesReturns->create(array_merge($d, ['status' => 'approved', 'credit_note_number' => $d['credit_note_number'] ?? $record->source_number]));
    }

    private function postPurchaseReturn(TallySourceRecord $record, array $d): PurchaseReturnVoucher
    {
        $this->assertNoVoucherConflict(PurchaseReturnVoucher::class, $record, $d['voucher_number'] ?? $record->source_number);
        return $this->purchaseReturns->create(array_merge($d, ['status' => 'approved', 'voucher_number' => $d['voucher_number'] ?? $record->source_number]));
    }

    private function postReceipt(TallySourceRecord $record, array $d): ReceiptVoucher
    {
        $this->assertNoVoucherConflict(ReceiptVoucher::class, $record, $record->source_number);
        return $this->accounting->createReceipt(array_merge($this->simpleVoucherData($record, $d, 'receipt'), ['status' => 'approved', 'voucher_number' => $record->source_number]));
    }

    private function postPayment(TallySourceRecord $record, array $d): PaymentVoucher
    {
        $this->assertNoVoucherConflict(PaymentVoucher::class, $record, $record->source_number);
        return $this->accounting->createPayment(array_merge($this->simpleVoucherData($record, $d, 'payment'), ['status' => 'approved', 'voucher_number' => $record->source_number]));
    }

    private function postJournal(TallySourceRecord $record, array $d): object
    {
        $this->assertNoVoucherConflict(\App\Models\JournalVoucher::class, $record, $d['voucher_number'] ?? $record->source_number);
        if (empty($d['entries'])) $d['entries'] = $this->entriesFromTally($record, $d);
        return $this->accounting->createJournal(array_merge($d, ['status' => 'approved', 'voucher_type' => $d['voucher_type'] ?? 'journal', 'voucher_number' => $d['voucher_number'] ?? $record->source_number]));
    }

    private function postContra(TallySourceRecord $record, array $d): object
    {
        if (empty($d['from_account_id']) || empty($d['to_account_id'])) {
            $entries = $this->entriesFromTally($record, $d);
            if (count($entries) !== 2) throw ValidationException::withMessages(['record' => 'Contra voucher must contain exactly two mapped cash/bank ledgers.']);
            $debit = collect($entries)->firstWhere('debit_amount', '>', 0); $credit = collect($entries)->firstWhere('credit_amount', '>', 0);
            if (!$debit || !$credit) throw ValidationException::withMessages(['record' => 'Contra voucher debit/credit sides could not be resolved.']);
            $d['to_account_id'] = $debit['account_id']; $d['from_account_id'] = $credit['account_id']; $d['amount'] = $debit['debit_amount'];
        }
        return $this->accounting->postContra(array_merge($d, ['voucher_date' => $d['voucher_date'] ?? $record->source_date, 'reference_number' => $d['reference_number'] ?? $record->source_number]));
    }

    private function voucherData(TallySourceRecord $record, array $data, string $type): array
    {
        if (!empty($data['items']) && (!empty($data['customer_id']) || !empty($data['supplier_id']))) return $data;
        $entries = $this->entriesFromTally($record, $data);
        $partyName = $data['party_name'] ?? $record->source_name;
        $party = $type === 'sales' ? Customer::where('business_id', $record->business_id)->where(function ($q) use ($partyName) { $q->where('customer_name', $partyName)->orWhere('name', $partyName); })->first() : Supplier::where('business_id', $record->business_id)->where(function ($q) use ($partyName) { $q->where('supplier_name', $partyName)->orWhere('name', $partyName); })->first();
        if (!$party) throw ValidationException::withMessages(['record' => "Party '{$partyName}' is not mapped in BillIQ."]);
        $inventory = $data['inventory_allocations'] ?? [];
        if (!$inventory) throw ValidationException::withMessages(['record' => 'Voucher has no inventory allocations; it cannot be imported as an item voucher.']);
        $tax = ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'cess' => 0.0, 'taxable' => 0.0];
        foreach ($entries as $entry) {
            $name = strtolower((string) ($entry['ledger_name'] ?? ''));
            if (str_contains($name, 'cgst')) $tax['cgst'] += $entry['debit_amount'] + $entry['credit_amount'];
            elseif (str_contains($name, 'sgst')) $tax['sgst'] += $entry['debit_amount'] + $entry['credit_amount'];
            elseif (str_contains($name, 'igst')) $tax['igst'] += $entry['debit_amount'] + $entry['credit_amount'];
            elseif (str_contains($name, 'cess')) $tax['cess'] += $entry['debit_amount'] + $entry['credit_amount'];
            elseif (str_contains($name, $type)) $tax['taxable'] += $entry['debit_amount'] + $entry['credit_amount'];
        }
        $taxable = $tax['taxable'] ?: max(0, abs((float) $record->debit_amount - (float) $record->credit_amount) - $tax['cgst'] - $tax['sgst'] - $tax['igst'] - $tax['cess']);
        $gstRate = $taxable > 0 ? round(($tax['cgst'] + $tax['sgst'] + $tax['igst']) * 100 / $taxable, 2) : 0;
        $items = [];
        foreach ($inventory as $line) {
            $product = Product::where('business_id', $record->business_id)->where('name', $line['item_name'] ?? '')->first();
            if (!$product) throw ValidationException::withMessages(['record' => "Stock item '{$line['item_name']}' is not mapped in BillIQ."]);
            $items[] = ['product_id' => $product->id, 'unit_id' => $product->unit_id, 'quantity' => abs((float) ($line['quantity'] ?? 0)), 'selling_rate' => abs((float) ($line['rate'] ?? 0)), 'purchase_rate' => abs((float) ($line['rate'] ?? 0)), 'gst_rate' => $gstRate, 'cess_rate' => $taxable > 0 ? round($tax['cess'] * 100 / $taxable, 2) : 0, 'tax_inclusive' => false];
        }
        $godown = data_get($inventory, '0.godown');
        $warehouseId = $data['warehouse_id'] ?? ($godown ? Warehouse::where('business_id', $record->business_id)->where('name', $godown)->value('id') : null);
        return array_merge($data, [$type === 'sales' ? 'customer_id' : 'supplier_id' => $party->id, 'items' => $items, $type === 'sales' ? 'invoice_date' : 'purchase_date' => $record->source_date, 'branch_id' => $data['branch_id'] ?? AppController::branchId(), 'warehouse_id' => $warehouseId, 'tax_type' => $tax['igst'] > 0 ? 'interstate' : 'intrastate', 'invoice_type' => 'tax_invoice', 'sale_type' => 'credit', 'purchase_type' => 'credit']);
    }

    private function simpleVoucherData(TallySourceRecord $record, array $data, string $type): array
    {
        $entries = $this->entriesFromTally($record, $data);
        $partyType = $type === 'receipt' ? 'customer' : 'supplier';
        $partyName = $data['party_name'] ?? $record->source_name;
        $party = $partyType === 'customer'
            ? Customer::where('business_id', $record->business_id)->where('customer_name', $partyName)->first()
            : Supplier::where('business_id', $record->business_id)->where('supplier_name', $partyName)->first();
        if (!$party) throw ValidationException::withMessages(['record' => "Party '{$partyName}' is not mapped in BillIQ."]);
        $cashEntry = collect($entries)->first(fn ($entry) => ($entry['debit_amount'] > 0 && $type === 'receipt') || ($entry['credit_amount'] > 0 && $type === 'payment'));
        $partyEntry = collect($entries)->first(fn ($entry) => strcasecmp((string) $entry['ledger_name'], (string) $partyName) === 0);
        if (!$cashEntry || !$partyEntry) throw ValidationException::withMessages(['record' => ucfirst($type) . ' requires a mapped cash/bank ledger and party ledger.']);
        $result = [$partyType . '_id' => $party->id, 'cash_bank_account_id' => $cashEntry['account_id'], 'amount' => $type === 'receipt' ? $cashEntry['debit_amount'] : $cashEntry['credit_amount'], $type === 'receipt' ? 'receipt_date' : 'payment_date' => $record->source_date, $type === 'receipt' ? 'receipt_mode' : 'payment_mode' => 'bank', 'branch_id' => $data['branch_id'] ?? AppController::branchId(), 'reference_number' => $data['reference_number'] ?? $record->source_number, 'remarks' => $data['narration'] ?? null, 'write_off_amount' => 0];
        $result[$type === 'receipt' ? 'discount_allowed' : 'discount_received'] = 0;
        return $result;
    }

    private function entriesFromTally(TallySourceRecord $record, array $data): array
    {
        $entries = [];
        foreach (($data['ledger_entries'] ?? []) as $line) {
            $name = $line['name'] ?? $line['ledger_name'] ?? null;
            $account = Account::where('business_id', $record->business_id)->where(function ($q) use ($name) { $q->where('account_name', $name)->orWhere('name', $name); })->first();
            if (!$account) throw ValidationException::withMessages(['record' => "Ledger '{$name}' is not mapped in BillIQ."]);
            $entries[] = ['account_id' => $account->id, 'ledger_name' => $name, 'debit_amount' => (float) ($line['debit'] ?? 0), 'credit_amount' => (float) ($line['credit'] ?? 0), 'narration' => $data['narration'] ?? null];
        }
        return $entries;
    }

    private function assertNoVoucherConflict(string $model, TallySourceRecord $record, ?string $number): void
    {
        if (!$number) throw ValidationException::withMessages(['voucher' => 'Tally voucher has no stable voucher number.']);
        if ($model::where('business_id', $record->business_id)->where(function ($q) use ($number, $model) {
            $q->where('voucher_number', $number);
            if ($model === SalesVoucher::class) $q->orWhere('invoice_number', $number);
        })->exists()) throw ValidationException::withMessages(['voucher' => "BillIQ already contains voucher '{$number}'. Resolve the conflict before importing."]);
    }
}
