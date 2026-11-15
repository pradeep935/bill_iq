<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\SalesVoucher;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The client confirmed that every credit sale is already paid except the hotel parties' last two months,
 * but Tally has no receipts for them. This settles those invoices with one receipt per invoice, booked to a
 * dedicated clearing account (never the real cash/bank ledgers) so the client can reclassify it later.
 * Simulation by default.
 */
class SettleTallySales extends Command
{
    protected $signature = 'billiq:settle-tally-sales
        {--gstin=05AWGPV0859A1ZM}
        {--hotel=TMGP HOSPITALITY : Comma list of customer names that stay pending}
        {--pending-from=2026-09-13 : Hotel invoices on/after this date stay pending}
        {--account=Unreconciled Receipts (not in Tally)}
        {--limit=}
        {--user=3}
        {--commit}';

    protected $description = 'Settle old Tally credit sales with receipts booked to a clearing account (hotel parties\' recent bills stay pending)';

    public function handle(AccountingService $accounting): int
    {
        $company = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail();
        Auth::login(User::findOrFail((int) $this->option('user')));
        config(['session.driver' => 'array']);
        session(['business_id' => $company->id, 'company_id' => $company->id, 'tenant_id' => $company->id]);
        $bid = $company->id;
        $hotels = array_map(fn ($n) => mb_strtolower(trim($n)), explode(',', $this->option('hotel')));
        $from = $this->option('pending-from');
        $limit = $this->option('limit') !== null && $this->option('limit') !== '' ? (int) $this->option('limit') : null;

        DB::beginTransaction();
        try {
            $group = AccountGroup::where('business_id', $bid)->where('group_name', 'Bank Accounts')->first();
            $clearing = Account::firstOrCreate(
                ['business_id' => $bid, 'account_name' => $this->option('account')],
                ['name' => $this->option('account'), 'account_code' => 'TLY-UNREC', 'code' => 'TLY-UNREC', 'account_group_id' => $group?->id, 'account_type' => 'bank',
                 'opening_balance' => 0, 'opening_balance_type' => 'debit', 'current_balance' => 0, 'status' => 'active', 'created_by' => Auth::id()]
            );

            $q = SalesVoucher::where('business_id', $bid)->whereIn('status', ['approved', 'confirmed'])->where('balance_amount', '>', 0)
                ->whereNotNull('customer_id')->with('customer')->orderBy('invoice_date')->orderBy('id');
            $kept = []; $done = 0; $amount = 0.0; $failed = [];
            foreach ($q->cursor() as $inv) {
                $isHotel = in_array(mb_strtolower($inv->customer->customer_name ?? ''), $hotels, true);
                if ($isHotel && $inv->invoice_date >= $from) {
                    $k = $inv->customer->customer_name; $kept[$k]['n'] = ($kept[$k]['n'] ?? 0) + 1; $kept[$k]['amt'] = round(($kept[$k]['amt'] ?? 0) + $inv->balance_amount, 2);
                    continue;
                }
                if ($limit !== null && $done >= $limit) break;
                $bal = round((float) $inv->balance_amount, 2);
                try {
                    DB::transaction(fn () => $accounting->createReceipt([
                        'customer_id' => $inv->customer_id, 'cash_bank_account_id' => $clearing->id, 'amount' => $bal, 'receipt_date' => $inv->invoice_date,
                        'receipt_mode' => 'bank', 'reference_number' => 'SETTLE-' . $inv->invoice_number,
                        'remarks' => 'Settlement of ' . $inv->invoice_number . ' (client confirmed bill is paid; receipt not recorded in Tally)',
                        'status' => 'approved', 'discount_allowed' => 0, 'write_off_amount' => 0,
                        'allocations' => [['reference_type' => SalesVoucher::class, 'reference_id' => $inv->id, 'allocated_amount' => $bal]],
                    ]));
                    $done++; $amount += $bal;
                } catch (\Throwable $e) {
                    $failed[] = [$inv->invoice_number, $inv->customer->customer_name ?? '', substr($e->getMessage(), 0, 120)];
                }
            }
            $this->info("Receipts created: $done | amount settled: " . number_format($amount, 2) . ' | failed: ' . count($failed));
            foreach (array_slice($failed, 0, 10) as $f) $this->line('  FAILED ' . implode(' | ', $f));
            foreach ($kept as $name => $k) $this->line("  Kept pending: $name - {$k['n']} bills, " . number_format($k['amt'], 2));
            $left = SalesVoucher::where('business_id', $bid)->whereIn('status', ['approved', 'confirmed'])->sum('balance_amount');
            $this->line('Total invoice outstanding now: ' . number_format((float) $left, 2));
            $this->option('commit') ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $this->info($this->option('commit') ? 'COMMITTED.' : 'SIMULATION ONLY: rolled back.');
        return self::SUCCESS;
    }
}
