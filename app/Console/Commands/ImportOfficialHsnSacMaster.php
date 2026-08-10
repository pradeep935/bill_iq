<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportOfficialHsnSacMaster extends Command
{
    protected $signature = 'hsn-sac:import-official
        {path : SQL file exported in BillIQ HSN/SAC staging format}
        {--activate-verified : Keep source-active rows active only when taxability and rates are verified}';

    protected $description = 'Import official shared HSN/SAC classifications into hsn_masters with business_id NULL.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
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
}
