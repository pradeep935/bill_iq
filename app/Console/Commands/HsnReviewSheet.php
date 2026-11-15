<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * export: Excel of the top-selling items (last 12 months) with the suggested HSN/GST for the client to confirm.
 * apply:  read the sheet back and apply the client's confirmed HSN/GST (marks tax_source=manual_confirmation).
 */
class HsnReviewSheet extends Command
{
    protected $signature = 'billiq:hsn-review-sheet {mode : export|apply}
        {--gstin=05AWGPV0859A1ZM}
        {--file=storage/app/tally-recovery/top100_hsn_gst_confirmation.xlsx}
        {--top=100}
        {--commit : (apply) persist, otherwise preview}';

    protected $description = 'Export top-selling items for HSN/GST confirmation, or apply the client-confirmed sheet';

    public function handle(): int
    {
        $company = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail();
        return $this->argument('mode') === 'apply' ? $this->apply($company) : $this->export($company);
    }

    private function export(Company $company): int
    {
        $bid = $company->id;
        $since = now()->subMonths(12)->toDateString();
        $rows = DB::select("select p.id, p.name, p.hsn_code, p.gst_rate, p.tax_source,
                round(sum(i.taxable_amount),2) value, round(sum(i.quantity),2) qty, count(distinct v.id) bills, max(v.invoice_date) last_sold
            from sales_items i join sales_vouchers v on v.id=i.sales_voucher_id join products p on p.id=i.product_id
            where v.business_id=? and v.status in ('approved','confirmed') and v.invoice_date >= ? and p.product_type='goods'
            group by p.id, p.name, p.hsn_code, p.gst_rate, p.tax_source order by value desc limit " . (int) $this->option('top'), [$bid, $since]);

        $basis = [];
        $csv = base_path('storage/app/tally-recovery/hsn_gst_review_company2.csv');
        if (is_file($csv)) { $h = fopen($csv, 'r'); fgetcsv($h); while (($r = fgetcsv($h)) !== false) $basis[$r[0]] = [$r[3], $r[5]]; fclose($h); }

        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet()->setTitle('Top items');
        $head = ['#', 'Product ID (do not change)', 'Product', 'Sales value last 12m (Rs, excl. GST)', 'Qty sold', 'Bills', 'Last sold',
                 'Suggested HSN (4-digit)', 'Suggested GST %', 'How the rate was found', 'Needs check?', 'CONFIRMED HSN (fill)', 'CONFIRMED GST % (fill)'];
        $ws->fromArray($head, null, 'A1');
        $n = 1;
        foreach ($rows as $r) {
            $b = $basis[$r->name] ?? ['', ''];
            $ws->fromArray([$n, $r->id, $r->name, (float) $r->value, (float) $r->qty, (int) $r->bills, $r->last_sold, $r->hsn_code, (float) $r->gst_rate, $b[0], $b[1] ?: '', null, null], null, 'A' . ($n + 1));
            $n++;
        }
        $ws->getStyle('A1:M1')->getFont()->setBold(true);
        $ws->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAD3');
        $ws->getStyle('L1:M1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $ws->getStyle('L2:M' . ($n))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFBE6');
        foreach (range('A', 'M') as $c) $ws->getColumnDimension($c)->setAutoSize(true);
        $ws->freezePane('D2');

        $help = $ss->createSheet()->setTitle('How to fill');
        $help->fromArray([
            ['HARI SIVANANDA JI ENTERPRISES - HSN / GST confirmation'],
            ['1. Sheet "Top items" has the 100 items that sold the most in the last 12 months.'],
            ['2. Columns H-I are our SUGGESTION taken from your Tally invoices or keyword rules. They are not yet confirmed.'],
            ['3. If a suggestion is correct, leave columns L-M empty. If it is wrong, type the right HSN code in L and the right GST % in M.'],
            ['4. Do not change column B (Product ID). Return the file; we will apply your corrections in one step.'],
            ['5. Rows marked YES in "Needs check?" were NOT found on any of your Tally invoices, so please look at these first.'],
        ], null, 'A1');
        $help->getColumnDimension('A')->setWidth(120);
        $help->getStyle('A1')->getFont()->setBold(true);

        $path = base_path($this->option('file'));
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        IOFactory::createWriter($ss, 'Xlsx')->save($path);
        $need = count(array_filter($rows, fn ($r) => ($basis[$r->name][1] ?? '') === 'YES'));
        $this->info("Wrote $path | items: " . count($rows) . " | of which NOT verified by any Tally invoice: $need | value covered: " . number_format(array_sum(array_map(fn ($r) => $r->value, $rows)), 0));
        return self::SUCCESS;
    }

    private function apply(Company $company): int
    {
        $bid = $company->id;
        $path = base_path($this->option('file'));
        if (!is_file($path)) { $this->error("File not found: $path"); return self::FAILURE; }
        $rows = IOFactory::load($path)->getSheetByName('Top items')->toArray(null, false, false, false);
        $hsnRows = DB::table('hsn_masters')->whereNull('business_id')->where('code_type', 'HSN')->pluck('id', 'hsn_code')->all();
        $changes = [];
        foreach (array_slice($rows, 1) as $r) {
            $id = (int) ($r[1] ?? 0); $hsn = trim((string) ($r[11] ?? '')); $gst = $r[12] ?? null;
            if (!$id || ($hsn === '' && ($gst === null || $gst === ''))) continue;
            $p = Product::where('business_id', $bid)->find($id);
            if (!$p) { $this->warn("Row skipped, unknown product id $id"); continue; }
            $upd = ['tax_source' => 'manual_confirmation', 'tax_confirmed_by' => 3, 'tax_confirmed_at' => now()];
            if ($hsn !== '') {
                if (!preg_match('/^\d{4}(\d{2}|\d{4})?$/', $hsn)) { $this->warn("Row skipped, invalid HSN '$hsn' for {$p->name}"); continue; }
                $upd += ['hsn_code' => $hsn, 'hsn' => $hsn, 'hsn_id' => $hsnRows[$hsn] ?? null, 'hsn_master_id' => $hsnRows[$hsn] ?? null];
            }
            if ($gst !== null && $gst !== '') {
                $g = (float) $gst;
                if (!in_array($g, [0.0, 0.25, 3.0, 5.0, 12.0, 18.0, 28.0], true)) { $this->warn("Row skipped, unusual GST $g for {$p->name}"); continue; }
                $upd += ['gst_rate' => $g, 'taxability' => $g > 0 ? 'taxable' : 'nil_rated'];
            }
            $changes[] = [$p, $upd];
            $this->line(sprintf('  %-45s HSN %s -> %s | GST %s -> %s', mb_substr($p->name, 0, 45), $p->hsn_code, $upd['hsn_code'] ?? '-', $p->gst_rate, $upd['gst_rate'] ?? '-'));
        }
        $this->info(count($changes) . ' product(s) to update.');
        if ($this->option('commit')) { DB::transaction(fn () => array_walk($changes, fn ($c) => Product::where('id', $c[0]->id)->update($c[1]))); $this->info('COMMITTED.'); }
        else $this->info('PREVIEW only. Add --commit to apply.');
        return self::SUCCESS;
    }
}
