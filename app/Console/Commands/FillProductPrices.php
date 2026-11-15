<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tally's Excel exports carry no item rates, so imported products have no price. This fills the product
 * price fields from the company's own history (never overwrites a price that is already set):
 *   selling price = list rate on the latest sales invoice line
 *   cost price    = latest purchase rate, else opening-stock cost (stock-journal costs are not used: they are an average over all outputs)
 * Dry-run by default.
 */
class FillProductPrices extends Command
{
    protected $signature = 'billiq:fill-product-prices
        {--gstin=05AWGPV0859A1ZM}
        {--csv= : Write a CSV of what was / was not filled}
        {--commit}';

    protected $description = 'Fill missing product cost/selling prices from the latest sale, purchase and stock history';

    public function handle(): int
    {
        $company = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail();
        $bid = $company->id;

        $sale = $this->latest("select i.product_id pid, i.selling_rate r from sales_items i join sales_vouchers v on v.id=i.sales_voucher_id
            where v.business_id=? and v.status in ('approved','confirmed') and i.selling_rate>0 order by v.invoice_date desc, i.id desc", $bid);
        $purchase = $this->latest("select i.product_id pid, i.purchase_rate r from purchase_items i join purchase_vouchers v on v.id=i.purchase_voucher_id
            where v.business_id=? and v.status in ('approved','confirmed') and i.purchase_rate>0 order by v.purchase_date desc, i.id desc", $bid);
        $opening = $this->latest("select product_id pid, unit_cost r from opening_stock_items where business_id=? and unit_cost>0 order by id desc", $bid);

        $products = DB::table('products')->where('business_id', $bid)->orderBy('name')->get(['id', 'name', 'product_type', 'cost_price', 'selling_price', 'purchase_price', 'sale_price']);
        $rows = []; $both = $costOnly = $sellOnly = $none = 0; $updates = [];
        foreach ($products as $p) {
            $hasCost = (float) ($p->cost_price ?: $p->purchase_price) > 0;
            $hasSell = (float) ($p->selling_price ?: $p->sale_price) > 0;
            $cost = $hasCost ? null : ($purchase[$p->id] ?? $opening[$p->id] ?? null);
            $costSrc = $hasCost ? 'existing' : (isset($purchase[$p->id]) ? 'latest purchase' : (isset($opening[$p->id]) ? 'opening stock' : '-'));
            $sell = $hasSell ? null : ($sale[$p->id] ?? null);
            $sellSrc = $hasSell ? 'existing' : (isset($sale[$p->id]) ? 'latest sale' : '-');
            $upd = [];
            if ($cost !== null) $upd += ['cost_price' => $cost, 'purchase_price' => $cost, 'default_purchase_price' => $cost];
            if ($sell !== null) $upd += ['selling_price' => $sell, 'sale_price' => $sell, 'default_selling_price' => $sell];
            if ($upd) $updates[$p->id] = $upd;
            $finalCost = $cost ?? ($hasCost ? (float) ($p->cost_price ?: $p->purchase_price) : null);
            $finalSell = $sell ?? ($hasSell ? (float) ($p->selling_price ?: $p->sale_price) : null);
            ($finalCost && $finalSell) ? $both++ : ($finalCost ? $costOnly++ : ($finalSell ? $sellOnly++ : $none++));
            $rows[] = [$p->name, $finalCost, $costSrc, $finalSell, $sellSrc, ($finalCost && $finalSell && $finalSell < $finalCost) ? 'SELL < COST' : ''];
        }
        $this->info('Products: ' . count($products) . " | both prices: $both | cost only: $costOnly | selling only: $sellOnly | neither: $none");
        $below = count(array_filter($rows, fn ($r) => $r[5] !== ''));
        $this->line("Selling price below cost (check): $below");
        $this->line('Neither price (no sale/purchase/stock history): ' . implode(' | ', array_slice(array_map(fn ($r) => $r[0], array_filter($rows, fn ($r) => !$r[1] && !$r[3])), 0, 25)));

        if ($csv = $this->option('csv')) {
            $h = fopen($csv, 'w'); fputcsv($h, ['Product', 'Cost price', 'Cost source', 'Selling price', 'Selling source', 'Flag']);
            foreach ($rows as $r) fputcsv($h, $r); fclose($h); $this->line("CSV: $csv");
        }
        if (!$this->option('commit')) { $this->info('DRY RUN: nothing written.'); return self::SUCCESS; }
        DB::transaction(function () use ($updates) {
            foreach ($updates as $id => $upd) DB::table('products')->where('id', $id)->update($upd + ['updated_at' => now()]);
        });
        $this->info('COMMITTED: ' . count($updates) . ' products updated.');
        return self::SUCCESS;
    }

    /** First (= latest, given the ORDER BY) value per product. */
    private function latest(string $sql, int $bid): array
    {
        $out = [];
        foreach (DB::select($sql, [$bid]) as $r) if (!isset($out[$r->pid])) $out[$r->pid] = round((float) $r->r, 2);
        return $out;
    }
}
