<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Tally data has no price for items that never sold (or were never purchased). This exports every product that still
 * lacks a selling or cost price, with a SUGGESTED selling price (cost x the median markup of the same category) for the
 * client to confirm, and applies the client's confirmed sheet. Nothing is applied without a confirmed value
 * unless --use-suggested is passed.
 */
class PriceReviewSheet extends Command
{
    protected $signature = 'billiq:price-sheet {mode : export|apply}
        {--gstin=05AWGPV0859A1ZM}
        {--file=storage/app/tally-recovery/products_price_confirmation.xlsx}
        {--use-suggested : (apply) also apply the suggested selling price where the confirmed cell is empty}
        {--commit : (apply) persist, otherwise preview}';

    protected $description = 'Export products missing prices with suggestions, or apply the client-confirmed price sheet';

    public function handle(): int
    {
        $bid = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail()->id;
        return $this->argument('mode') === 'apply' ? $this->apply($bid) : $this->export($bid);
    }

    private function export(int $bid): int
    {
        $products = DB::table('products')->where('business_id', $bid)->whereNull('deleted_at')->where('status', 'active')
            ->get(['id', 'name', 'category', 'unit', 'product_type', 'cost_price', 'selling_price']);

        // median markup (selling / cost) per category, from products that have both prices
        $ratios = [];
        foreach ($products as $p) if ($p->cost_price > 0 && $p->selling_price >= $p->cost_price) $ratios[$p->category ?: '-'][] = $p->selling_price / $p->cost_price;
        $all = array_merge(...array_values($ratios ?: [[1.3]]));
        $median = function (array $a) { sort($a); $n = count($a); return $n ? ($n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2) : 1.3; };
        $overall = $median($all);
        $byCat = array_map(fn ($a) => count($a) >= 5 ? $median($a) : $overall, $ratios);

        $rows = [];
        foreach ($products as $p) {
            $needSell = (float) $p->selling_price <= 0; $needCost = (float) $p->cost_price <= 0;
            if (!$needSell && !$needCost) continue;
            $suggest = null; $basis = '';
            if ($needSell && $p->cost_price > 0) {
                $k = $byCat[$p->category ?: '-'] ?? $overall;
                $raw = $p->cost_price * $k;
                $suggest = $raw >= 20 ? round($raw) : round($raw * 2) / 2;
                $basis = sprintf('cost %.2f x %.2f (%s median markup)', $p->cost_price, $k, $p->category ?: 'overall');
            }
            $rows[] = [$p->id, $p->name, $p->category, $p->unit, $needCost ? 'MISSING' : (float) $p->cost_price, $needSell ? 'MISSING' : (float) $p->selling_price, $suggest, $basis, null, null];
        }
        usort($rows, fn ($a, $b) => [$a[2], $a[1]] <=> [$b[2], $b[1]]);

        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet()->setTitle('Prices');
        $ws->fromArray(['Product ID (do not change)', 'Product', 'Category', 'Unit', 'Cost price now', 'Selling price now', 'Suggested selling price', 'How suggested', 'CONFIRMED selling price (fill)', 'CONFIRMED cost price (fill)'], null, 'A1');
        $ws->fromArray($rows, null, 'A2');
        $n = count($rows) + 1;
        $ws->getStyle('A1:J1')->getFont()->setBold(true);
        $ws->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAD3');
        $ws->getStyle('I1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $ws->getStyle("I2:J$n")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFBE6');
        foreach (range('A', 'J') as $c) $ws->getColumnDimension($c)->setAutoSize(true);
        $ws->freezePane('C2');
        $help = $ss->createSheet()->setTitle('How to fill');
        $help->fromArray([
            ['HARI SIVANANDA JI ENTERPRISES - product price confirmation'],
            ['1. These products have no selling price and/or no cost price in BillIQ (Tally never recorded a rate for them).'],
            ['2. Column G is only a SUGGESTION (cost x the usual markup of the same category). It is NOT applied until you confirm it.'],
            ['3. Type the correct selling price (excl. GST) in column I, and the cost/purchase price (excl. GST) in column J. Leave blank to keep as is.'],
            ['4. Do not change column A (Product ID). Return the file and we will apply it in one step.'],
        ], null, 'A1');
        $help->getColumnDimension('A')->setWidth(120); $help->getStyle('A1')->getFont()->setBold(true);
        IOFactory::createWriter($ss, 'Xlsx')->save(base_path($this->option('file')));
        $withSuggest = count(array_filter($rows, fn ($r) => $r[6] !== null));
        $this->info("Wrote {$this->option('file')} | products listed: " . count($rows) . " | with a suggested selling price: $withSuggest | overall median markup: " . round($overall, 2));
        return self::SUCCESS;
    }

    private function apply(int $bid): int
    {
        $path = base_path($this->option('file'));
        if (!is_file($path)) { $this->error("File not found: $path"); return self::FAILURE; }
        $rows = array_slice(IOFactory::load($path)->getSheetByName('Prices')->toArray(null, false, false, false), 1);
        $changes = [];
        foreach ($rows as $r) {
            $id = (int) ($r[0] ?? 0);
            if (!$id || !DB::table('products')->where('business_id', $bid)->where('id', $id)->exists()) continue;
            $sell = is_numeric($r[8] ?? null) ? round((float) $r[8], 2) : (($this->option('use-suggested') && is_numeric($r[6] ?? null)) ? round((float) $r[6], 2) : null);
            $cost = is_numeric($r[9] ?? null) ? round((float) $r[9], 2) : null;
            if (($sell !== null && $sell < 0) || ($cost !== null && $cost < 0)) { $this->warn("Skipped negative price for {$r[1]}"); continue; }
            $upd = [];
            if ($sell !== null) $upd += ['selling_price' => $sell, 'sale_price' => $sell, 'default_selling_price' => $sell];
            if ($cost !== null) $upd += ['cost_price' => $cost, 'purchase_price' => $cost, 'default_purchase_price' => $cost];
            if ($upd) { $changes[$id] = $upd; $this->line(sprintf('  %-46s sell %s | cost %s', mb_substr($r[1], 0, 46), $sell ?? '-', $cost ?? '-')); }
        }
        $this->info(count($changes) . ' product(s) to update.');
        if (!$this->option('commit')) { $this->info('PREVIEW only. Add --commit to apply.'); return self::SUCCESS; }
        DB::transaction(function () use ($changes) { foreach ($changes as $id => $u) DB::table('products')->where('id', $id)->update($u + ['updated_at' => now()]); });
        $this->info('COMMITTED.');
        return self::SUCCESS;
    }
}
