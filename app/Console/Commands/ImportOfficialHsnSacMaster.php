<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ImportOfficialHsnSacMaster extends Command
{
    protected $signature = 'hsn-sac:import-official
        {path? : JSON or SQL file exported in BillIQ HSN/SAC staging format}
        {--activate-verified : Keep source-active rows active only when taxability and rates are verified}
        {--json : Force JSON import mode}
        {--truncate-official : Remove existing official shared HSN/SAC rows before import}';

    protected $description = 'Import official shared HSN/SAC classifications into hsn_masters with business_id NULL.';

    public function handle(): int
    {
        if (!$this->argument('path')) {
            $status = self::SUCCESS;

            foreach ([database_path('data/gst_hsn_codes.json'), database_path('data/gst_sac_codes.json')] as $defaultPath) {
                if ($this->importPath($defaultPath) !== self::SUCCESS) {
                    $status = self::FAILURE;
                }
            }

            return $status;
        }

        return $this->importPath((string) $this->argument('path'));
    }

    private function importPath(string $path): int
    {

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        if ($this->option('json') || Str::endsWith(strtolower($path), '.json')) {
            return $this->importJson($path);
        }

        $stagingTable = 'hsn_sac_master_import_' . now()->format('YmdHis');
        $sql = file_get_contents($path);
        $sql = str_replace('`hsn_sac_master`', "`{$stagingTable}`", $sql);

        DB::statement("DROP TABLE IF EXISTS `{$stagingTable}`");
        DB::unprepared($sql);

        $rows = DB::table($stagingTable)->orderBy('code_type')->orderBy('code')->get();
        $now = now();
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, $now, &$created, &$updated) {
            foreach ($rows as $row) {
                $codeType = strtoupper(trim((string) $row->code_type));
                $code = trim((string) $row->code);

                if ($code === '' || !in_array($codeType, ['HSN', 'SAC'], true)) {
                    continue;
                }

                $isVerified = $row->taxability !== 'unverified'
                    && $row->gst_rate !== null
                    && $row->cess_rate !== null;

                $payload = [
                    'business_id' => null,
                    'code_type' => $codeType,
                    'hsn_code' => $code,
                    'chapter_code' => $row->chapter_code ?: null,
                    'description' => $row->description,
                    'taxability' => $isVerified ? $row->taxability : 'taxable',
                    'verification_status' => $isVerified ? 'verified' : 'unverified',
                    'gst_rate' => $row->gst_rate ?? 0,
                    'cess_rate' => $row->cess_rate ?? 0,
                    'effective_from' => $row->effective_from ?: '2017-07-01',
                    'effective_to' => $row->effective_to ?: null,
                    'source_reference' => $row->source_reference ?: 'Official HSN/SAC classification import',
                    'notes' => $isVerified ? null : 'Imported classification only. Verify GST/CESS rate before activation.',
                    'status' => $isVerified && (int) $row->status === 1 ? 'active' : 'inactive',
                    'updated_at' => $now,
                ];

                $query = DB::table('hsn_masters')
                    ->whereNull('business_id')
                    ->where('code_type', $codeType)
                    ->where('hsn_code', $code);

                $exists = $query->exists();

                if ($exists) {
                    $query->update($payload);
                    $updated++;
                    continue;
                }

                $payload['created_at'] = $now;

                DB::table('hsn_masters')->insert($payload);
                $created++;
            }
        });

        DB::statement("DROP TABLE IF EXISTS `{$stagingTable}`");

        $this->info("Imported official shared HSN/SAC records. Created: {$created}, Updated: {$updated}.");
        $this->warn('Unverified rows are inactive until GST/CESS rates are reviewed and activated.');

        return self::SUCCESS;
    }

    private function importJson(string $path): int
    {
        $records = json_decode((string) file_get_contents($path), true);

        if (!is_array($records)) {
            $this->error("Invalid JSON file: {$path}");
            return self::FAILURE;
        }

        if ($this->option('truncate-official')) {
            DB::table('hsn_masters')->whereNull('business_id')->delete();
        }

        $now = now();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $columns = Schema::getColumnListing('hsn_masters');
        $bar = $this->output->createProgressBar(count($records));
        $bar->start();

        DB::transaction(function () use ($records, $now, $columns, &$created, &$updated, &$skipped, $bar) {
            foreach ($records as $row) {
                $code = preg_replace('/\D+/', '', (string) ($row['code'] ?? $row['hsn_code'] ?? ''));
                $codeType = strtoupper((string) ($row['code_type'] ?? (($row['type'] ?? '') === 'service' ? 'SAC' : 'HSN')));
                $description = trim((string) ($row['description'] ?? ''));

                if ($code === '' || $description === '' || !in_array($codeType, ['HSN', 'SAC'], true)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $payload = [
                    'business_id' => null,
                    'code_type' => $codeType,
                    'hsn_code' => $code,
                    'description' => $description,
                    'chapter_code' => $row['chapter_code'] ?? substr($code, 0, 2),
                    'gst_rate' => null,
                    'cess_rate' => null,
                    'taxability' => 'taxable',
                    'verification_status' => 'classification_verified',
                    'effective_from' => '2017-07-01',
                    'effective_to' => null,
                    'status' => 'active',
                    'source_reference' => $row['source_reference'] ?? ($codeType === 'SAC'
                        ? 'CBIC - Classification Scheme for Services under GST'
                        : 'GST HSN Directory / Indian ITC(HS) classification'),
                    'notes' => 'Official classification imported. GST/CESS rate must be verified from applicable CBIC rate notifications before billing.',
                    'updated_at' => $now,
                ];

                if (in_array('search_keywords', $columns, true)) {
                    $payload['search_keywords'] = $row['search_keywords'] ?? $this->keywords($code, $description);
                }

                if (in_array('source_dataset', $columns, true)) {
                    $payload['source_dataset'] = $codeType === 'SAC' ? 'cbic_gst_services_classification' : 'gst_hsn_itc_hs_classification';
                }

                if (in_array('classification_verified', $columns, true)) {
                    $payload['classification_verified'] = true;
                }

                if (in_array('classification_verified_at', $columns, true)) {
                    $payload['classification_verified_at'] = $now;
                }

                if (in_array('rate_verified', $columns, true)) {
                    $payload['rate_verified'] = false;
                }

                $payload = collect($payload)->only($columns)->all();

                $query = DB::table('hsn_masters')
                    ->whereNull('business_id')
                    ->where('code_type', $codeType)
                    ->where('hsn_code', $code);

                if ($query->exists()) {
                    $query->update($payload);
                    $updated++;
                } else {
                    $payload['created_at'] = $now;
                    DB::table('hsn_masters')->insert($payload);
                    $created++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Imported official shared HSN/SAC JSON records. Created: {$created}, Updated: {$updated}, Skipped: {$skipped}.");
        $this->warn('Classification is verified; GST/CESS rate remains unverified unless a rate-notification rule is mapped separately.');

        return self::SUCCESS;
    }

    private function keywords(string $code, string $description): string
    {
        return Str::of($code . ' ' . $description)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
