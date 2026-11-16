<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tally parties that are only suppliers (we buy from them, never sold to them) were also created as customers because a
 * receipt/journal touched them. This moves those ledger lines to the supplier record and removes the stray customer.
 * A customer is only touched when it has NO sales invoices and a supplier with the same name already exists. Dry-run by default.
 */
class FixSupplierOnlyCustomers extends Command
{
    protected $signature = 'billiq:fix-supplier-only-customers {--gstin=05AWGPV0859A1ZM} {--commit}';
    protected $description = 'Move ledger lines of supplier-only parties from the customer record to the supplier and remove the stray customer';

    public function handle(): int
    {
        $bid = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail()->id;
        $pairs = DB::table('customers as c')->join('suppliers as s', fn ($j) => $j->on('s.business_id', '=', 'c.business_id')->on('s.name', '=', 'c.name'))
            ->where('c.business_id', $bid)->whereNull('c.deleted_at')->whereNull('s.deleted_at')->get(['c.id as cid', 'c.name', 's.id as sid']);
        $sales = DB::table('sales_vouchers')->where('business_id', $bid)->groupBy('customer_id')->pluck(DB::raw('count(*)'), 'customer_id');
        $refs = array_values(array_filter(['receipt_vouchers', 'sales_return_vouchers', 'sales_orders', 'sales_quotations'], fn ($t) => Schema::hasTable($t) && Schema::hasColumn($t, 'customer_id')));
        $plan = [];
        foreach ($pairs as $p) {
            if (($sales[$p->cid] ?? 0) > 0) { $this->line("  keep (has sales): {$p->name}"); continue; }
            $used = [];
            foreach ($refs as $t) { $n = DB::table($t)->where('business_id', $bid)->where('customer_id', $p->cid)->count(); if ($n) $used[$t] = $n; }
            if ($used) { $this->warn("  skip (customer used in " . json_encode($used) . "): {$p->name}"); continue; }
            $je = DB::table('journal_entries')->where('business_id', $bid)->where('customer_id', $p->cid)->count();
            $plan[] = [$p, $je];
            $this->line(sprintf('  move: %-44s customer #%d -> supplier #%d | %d journal line(s)', $p->name, $p->cid, $p->sid, $je));
        }
        $this->info(count($plan) . ' stray customer(s).');
        if (!$this->option('commit')) { $this->info('DRY RUN: nothing written. Add --commit.'); return self::SUCCESS; }
        DB::transaction(function () use ($plan, $bid) {
            foreach ($plan as [$p]) {
                DB::table('journal_entries')->where('business_id', $bid)->where('customer_id', $p->cid)->whereNull('supplier_id')->update(['supplier_id' => $p->sid, 'customer_id' => null]);
                if (Schema::hasTable('ledger_allocations')) { /* allocations hang off journal entries, nothing to change */ }
                DB::table('customers')->where('id', $p->cid)->update(['deleted_at' => now(), 'status' => 'inactive']);
            }
        });
        $this->info('COMMITTED.');
        return self::SUCCESS;
    }
}
