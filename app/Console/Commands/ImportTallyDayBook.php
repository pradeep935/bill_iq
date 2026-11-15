<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\TallyDayBookImporter;
use App\Services\TallyDayBookParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ImportTallyDayBook extends Command
{
    protected $signature = 'billiq:import-tally-daybook
        {--file= : Day Book xlsx (default ~/Downloads/DayBook_12112026.xlsx)}
        {--gstin=05AWGPV0859A1ZM : Company is looked up by GSTIN}
        {--types=sales,purchase : Comma list: sales,purchase,receipt,payment,stock_journal,delivery_note}
        {--from= : Only vouchers on/after this date (Y-m-d)}
        {--to= : Only vouchers on/before this date (Y-m-d)}
        {--limit= : Max vouchers per run}
        {--rows= : Only vouchers whose Day Book header row is in this comma list}
        {--user=1}
        {--failures= : Write failed vouchers to this CSV file}
        {--commit : Persist. Without it everything is rolled back (simulation)}';

    protected $description = 'Import Tally Day Book vouchers into the company, via BillIQ services (simulation by default)';

    public function handle(TallyDayBookImporter $importer): int
    {
        $file = $this->option('file') ?: getenv('HOME') . '/Downloads/DayBook_12112026.xlsx';
        $company = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail();
        Auth::login(User::findOrFail((int) $this->option('user')));
        config(['session.driver' => 'array']);
        session(['business_id' => $company->id, 'company_id' => $company->id, 'tenant_id' => $company->id]);

        $parser = new TallyDayBookParser();
        $vouchers = $parser->parse($file);
        $from = $this->option('from'); $to = $this->option('to');
        $vouchers = array_values(array_filter($vouchers, fn ($v) => (!$from || $v['date'] >= $from) && (!$to || $v['date'] <= $to)));
        $vouchers = $importer->normalize($vouchers);
        if ($this->option('rows')) { $rows = array_map('intval', explode(',', $this->option('rows'))); $vouchers = array_values(array_filter($vouchers, fn ($v) => in_array($v['row'], $rows, true))); }
        usort($vouchers, fn ($a, $b) => [$a['date'], $a['row']] <=> [$b['date'], $b['row']]);
        $this->info('Company #' . $company->id . ' ' . $company->name . ' | vouchers in scope: ' . count($vouchers));

        $types = array_map('trim', explode(',', $this->option('types')));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $commit = (bool) $this->option('commit');

        DB::beginTransaction();
        try {
            $importer->prepare($company->id, $vouchers);
            $importer->run($vouchers, $types, $limit);
            $this->report($importer, $company->id);
            $commit ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $this->newLine();
        $this->info($commit ? 'COMMITTED.' : 'SIMULATION ONLY: everything was rolled back.');
        if ($path = $this->option('failures')) $this->writeCsv($path, $importer->failures);
        return self::SUCCESS;
    }

    private function report(TallyDayBookImporter $i, int $bid): void
    {
        $this->table(['Stat', 'Value'], collect($i->stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($i->skipped) {
            $this->warn('SKIPPED (no effect on Tally books): ' . count($i->skipped));
            foreach ($i->skipped as $r) $this->line("   row {$r['row']} {$r['date']} {$r['type']} #{$r['no']} {$r['party']} amount {$r['amount']} - {$r['reason']}");
        }
        foreach ($i->failures as $kind => $rows) {
            $this->warn(strtoupper($kind) . ' failures: ' . count($rows));
            $byReason = collect($rows)->groupBy(fn ($r) => preg_replace('/[\d.]+/', '#', substr($r['error'], 0, 60)))->map->count()->sortDesc()->take(12);
            foreach ($byReason as $reason => $n) $this->line("   $n x $reason");
            foreach (array_slice($rows, 0, 5) as $r) $this->line("   e.g. row {$r['row']} #{$r['no']} {$r['date']} {$r['party']}: {$r['error']}");
        }
        $q = fn ($t) => DB::table($t)->where('business_id', $bid)->count();
        $this->line('DB now: sales_vouchers=' . $q('sales_vouchers') . ' purchase_vouchers=' . $q('purchase_vouchers') . ' journal_vouchers=' . $q('journal_vouchers') . ' stock_ledgers=' . $q('stock_ledgers'));
    }

    private function writeCsv(string $path, array $failures): void
    {
        $h = fopen($path, 'w');
        fputcsv($h, ['kind', 'row', 'number', 'date', 'party', 'error']);
        foreach ($failures as $kind => $rows) foreach ($rows as $r) fputcsv($h, [$kind, $r['row'], $r['no'], $r['date'], $r['party'], $r['error']]);
        fclose($h);
        $this->line("Failures written to $path");
    }
}
