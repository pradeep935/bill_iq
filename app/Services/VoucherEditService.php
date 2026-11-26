<?php

namespace App\Services;

use App\Models\LedgerAllocation;
use App\Models\SalesReturnVoucher;
use App\Models\SalesVoucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Rules shared by editing a posted, reversed or cancelled sales invoice or purchase bill. */
class VoucherEditService
{
    public const ROUND_OFF_TOLERANCE = 1.00;

    public function assertAdmin(bool $isAdmin, string $message): void
    {
        if (!$isAdmin) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    public function assertCancelledEditable(string $status, bool $isAdmin): void
    {
        if ($status === 'cancelled') {
            $this->assertAdmin($isAdmin, 'Only an admin can edit a cancelled bill.');
        }
    }

    /** "<number>-R1" rows are earlier versions kept for the record. */
    public function assertNotArchived(string $voucherNumber): void
    {
        if (preg_match('/-R\d+$/', $voucherNumber)) {
            throw ValidationException::withMessages(['status' => 'This is an old version of an edited bill. Edit the current bill with the same number.']);
        }
    }

    public function archiveSuffix(string $model, int $businessId, string $column, string $number): string
    {
        return '-R' . ($model::query()->where('business_id', $businessId)->where($column, 'like', $number . '-R%')->count() + 1);
    }

    /** Amount settled on the bill by receipt or payment vouchers. */
    public function settledAmount(int $businessId, string $type, int $id): float
    {
        if (!Schema::hasTable('ledger_allocations')) {
            return 0.0;
        }

        return max(0.0, round((float) LedgerAllocation::query()->where('business_id', $businessId)
            ->where('reference_type', $type)->where('reference_id', $id)->sum('allocated_amount'), 2));
    }

    /** Moves the vouchers' allocations to the corrected bill and adds their amount to what is paid on it. */
    public function applySettlement(Model $bill, string $type, int $oldId, float $settled): void
    {
        LedgerAllocation::query()->where('business_id', $bill->business_id)->where('reference_type', $type)
            ->where('reference_id', $oldId)->update(['reference_id' => $bill->id]);

        $bill->refresh();
        $grand = (float) $bill->grand_total;
        $paid = round(min($grand, (float) $bill->paid_amount + $settled), 2);
        $creditNote = $type === SalesVoucher::class ? SalesReturnVoucher::invoiceAdjustmentTotal($bill->business_id, $bill->id) : 0.0;
        $balance = round(max(0, $grand - $paid - $creditNote), 2);

        $bill->update(['paid_amount' => $paid, 'balance_amount' => $balance, 'payment_status' => $balance <= 0 ? 'paid' : ($paid <= 0 ? 'unpaid' : 'partial')]);
    }

    /** The share link is moved by the caller; these point at the invoice by id. */
    public function moveSaleReferences(int $from, int $to): void
    {
        foreach (['document_share_logs', 'network_bills', 'network_imports'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('sales_voucher_id', $from)->update(['sales_voucher_id' => $to]);
            }
        }
    }
}
