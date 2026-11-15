<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Services\FinancialReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * BillIQ's statements (P&L, Balance Sheet, ratios, reports dashboard) only read accounts whose GROUP has a
 * financial_statement_type, grouped by the account's financial_statement_section. Imported Tally accounts and the
 * system accounts (Sales, Purchase, AR, AP, taxes...) had neither, so every report showed zero.
 * This command maps the company's accounts. Dry-run by default.
 */
class MapFinancialStatements extends Command
{
    protected $signature = 'billiq:map-financial-statements {--gstin=05AWGPV0859A1ZM} {--user=3} {--commit}';
    protected $description = 'Map accounts/groups to financial statement type and section so P&L, Balance Sheet and report dashboards work';

    private const BS = 'balance_sheet';
    private const PL = 'profit_and_loss';

    /** Tally group name => statement type (group level) */
    private const GROUP_TYPE = [
        'Sales Accounts' => self::PL, 'Purchase Accounts' => self::PL, 'Direct Expenses' => self::PL, 'Indirect Expenses' => self::PL,
        'Direct Incomes' => self::PL, 'Indirect Incomes' => self::PL,
    ];

    public function handle(FinancialReportService $reports): int
    {
        $company = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail();
        $bid = $company->id;
        Auth::loginUsingId((int) $this->option('user'));
        config(['session.driver' => 'array']);
        session(['business_id' => $bid, 'company_id' => $bid, 'tenant_id' => $bid]);

        $accounts = Account::where('business_id', $bid)->with('group')->orderBy('id')->get();
        $plan = [];
        foreach ($accounts as $a) {
            [$type, $section] = $this->classify($a);
            $plan[] = [$a, $type, $section];
        }

        $rows = collect($plan)->map(fn ($p) => [$p[0]->account_name, $p[0]->group->group_name ?? '(none)', $p[1] === self::PL ? 'P&L' : 'BS', $p[2]])->all();
        $bySection = collect($plan)->groupBy(fn ($p) => ($p[1] === self::PL ? 'P&L: ' : 'BS: ') . $p[2])->map->count()->sortKeys();
        foreach ($bySection as $s => $n) $this->line(sprintf('  %-40s %d accounts', $s, $n));
        $this->info('Accounts to map: ' . count($plan));

        if (!$this->option('commit')) { $this->info('DRY RUN: nothing written.'); return self::SUCCESS; }

        DB::transaction(function () use ($bid, $plan) {
            // 1. Tally groups: set statement type (balance sheet unless the group is P&L)
            foreach (AccountGroup::where('business_id', $bid)->get() as $g) {
                $g->update(['financial_statement_type' => self::GROUP_TYPE[$g->group_name] ?? self::BS]);
            }
            // 2. System accounts have no group: one group per (statement type, nature) so the group's nature never
            //    disagrees with the account's own nature (AccountingPostingService uses it for current_balance).
            $sys = [];
            foreach ([[self::BS, 'debit'], [self::BS, 'credit'], [self::PL, 'debit'], [self::PL, 'credit']] as [$t, $n]) {
                $label = ($t === self::BS ? 'System Balance Sheet' : 'System Profit & Loss') . ' - ' . ucfirst($n);
                $sys["$t|$n"] = AccountGroup::firstOrCreate(['business_id' => $bid, 'group_name' => $label],
                    ['group_code' => 'SYS-' . strtoupper(substr($t, 0, 2)) . '-' . strtoupper(substr($n, 0, 2)), 'group_type' => $t === self::BS ? ($n === 'debit' ? 'asset' : 'liability') : ($n === 'debit' ? 'expense' : 'income'),
                     'nature' => $n, 'normal_balance' => $n, 'financial_statement_type' => $t, 'is_system' => true, 'status' => 'active']);
            }
            foreach ($plan as [$a, $type, $section]) {
                $upd = ['financial_statement_section' => $section];
                if (!$a->account_group_id) {
                    $nature = in_array($a->account_type, ['asset', 'expense', 'cash', 'bank', 'customer', 'inventory'], true) ? 'debit' : 'credit';
                    $upd['account_group_id'] = $sys["$type|$nature"]->id;
                }
                Account::where('id', $a->id)->update($upd);
            }
        });

        $this->info('COMMITTED. Resulting statements (all dates):');
        $pl = $reports->getProfitAndLoss([]);
        $this->line(sprintf('  Profit & Loss: gross profit %s | net profit %s', number_format($pl['gross_profit'], 2), number_format($pl['net_profit'], 2)));
        foreach ($pl['sections'] as $s) $this->line(sprintf('    %-26s %s', $s['section'], number_format($s['total'], 2)));
        $bs = $reports->getBalanceSheet([]);
        $this->line(sprintf('  Balance Sheet: assets %s | liabilities %s | equity %s | current profit %s | balanced: %s (difference %s)',
            number_format($bs['assets_total'], 2), number_format($bs['liabilities_total'], 2), number_format($bs['equity_total'], 2), number_format($bs['current_period_profit'], 2), $bs['is_balanced'] ? 'yes' : 'NO', number_format($bs['difference'], 2)));
        foreach ($bs['sections'] as $s) $this->line(sprintf('    %-26s %s', $s['section'], number_format($s['total'], 2)));
        return self::SUCCESS;
    }

    /** [statement type, section] for an account. */
    private function classify(Account $a): array
    {
        $n = mb_strtolower($a->account_name);
        $code = (string) $a->account_code;
        $group = $a->group->group_name ?? '';
        $t = $a->account_type;

        return match (true) {
            $code === 'TLY-UNREC' => [self::BS, 'Current Assets'],
            $code === 'AR' => [self::BS, 'Accounts Receivable'],
            $code === 'AP' => [self::BS, 'Accounts Payable'],
            $code === 'INV' => [self::BS, 'Inventory'],
            $code === 'OPEN-EQ' => [self::BS, 'Retained Earnings'],
            $code === 'PETTY-ADV' => [self::BS, 'Loans and Advances'],
            str_starts_with($code, 'OUT-') => [self::BS, 'Tax Liabilities'],
            str_starts_with($code, 'IN-') => [self::BS, 'Current Assets'],
            in_array($n, ['output cgst', 'output sgst', 'output igst', 'output cess'], true) => [self::BS, 'Tax Liabilities'],
            in_array($n, ['input cgst', 'input sgst', 'input igst', 'input cess'], true) => [self::BS, 'Current Assets'],
            $t === 'cash' => [self::BS, 'Cash and Cash Equivalents'],
            $t === 'bank' => [self::BS, 'Bank Accounts'],
            $code === 'SALES' || $group === 'Sales Accounts' => [self::PL, 'Sales Revenue'],
            $code === 'SALES-RET' => [self::PL, 'Direct Expenses'],
            $code === 'PUR-RET' => [self::PL, 'Other Operating Revenue'],
            $code === 'SHIP-I' => [self::PL, 'Other Operating Revenue'],
            $code === 'PURCHASE' || $code === 'COGS' || $group === 'Purchase Accounts' => [self::PL, 'Cost of Goods Sold'],
            $group === 'Direct Expenses' => [self::PL, 'Direct Expenses'],
            $group === 'Capital Account' => [self::BS, 'Capital'],
            $group === 'Unsecured Loans' => [self::BS, 'Loans and Borrowings'],
            $group === 'Current Assets' => [self::BS, 'Current Assets'],
            $t === 'expense' => [self::PL, 'Indirect Expenses'],
            $t === 'income' => [self::PL, 'Other Income'],
            $t === 'equity' => [self::BS, 'Capital'],
            $t === 'liability' => [self::BS, 'Current Liabilities'],
            default => [self::BS, 'Current Assets'],
        };
    }
}
