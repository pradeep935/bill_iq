<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HsnGstMasterSeeder extends Seeder
{
    private const EFFECTIVE_FROM = '2025-09-22';
    private const SOURCE_DATASET = 'billiq_full_hsn_sac_master_with_tables.sql';

    public function run(): void
    {
        $this->seedGstRateSlabs();
        $this->seedMasterFile(database_path('data/gst_hsn_codes.json'), 'HSN');
        $this->seedMasterFile(database_path('data/gst_sac_codes.json'), 'SAC');
    }

    private function seedGstRateSlabs(): void
    {
        if (!Schema::hasTable('gst_rate_slabs')) {
            return;
        }

        $now = now();
        $active = $this->statusValue('gst_rate_slabs', 'active');
        $inactive = $this->statusValue('gst_rate_slabs', 'inactive');
        $slabs = [
            ['rate' => 0, 'label' => '0%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 10, 'notes' => 'Common selectable rate. Taxability still distinguishes zero-rated, nil-rated, exempt and non-GST.'],
            ['rate' => 0.1, 'label' => '0.1%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 20, 'notes' => 'Special/rare rate. Not shown as a common Product Master default.'],
            ['rate' => 0.25, 'label' => '0.25%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 30, 'notes' => 'Special/rare rate. Not shown as a common Product Master default.'],
            ['rate' => 1, 'label' => '1%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 40, 'notes' => 'Special rate/scheme. Not shown as a common Product Master default.'],
            ['rate' => 1.5, 'label' => '1.5%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 50, 'notes' => 'Special/rare rate. Not shown as a common Product Master default.'],
            ['rate' => 3, 'label' => '3%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 60, 'notes' => 'Special rate commonly used for notified precious goods.'],
            ['rate' => 5, 'label' => '5%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 70, 'notes' => 'Common selectable rate.'],
            ['rate' => 6, 'label' => '6%', 'is_common' => false, 'selectable' => false, 'status' => $inactive, 'sort_order' => 80, 'notes' => 'Retained for historical/reference safety; not selectable as a normal product GST slab.'],
            ['rate' => 7.5, 'label' => '7.5%', 'is_common' => false, 'selectable' => false, 'status' => $inactive, 'sort_order' => 90, 'notes' => 'Retained for historical/reference safety; not selectable as a normal product GST slab.'],
            ['rate' => 12, 'label' => '12%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 100, 'notes' => 'Common selectable rate.'],
            ['rate' => 18, 'label' => '18%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 110, 'notes' => 'Common selectable rate.'],
            ['rate' => 28, 'label' => '28%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 120, 'notes' => 'Common selectable high rate.'],
            ['rate' => 40, 'label' => '40%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 130, 'notes' => 'Special high rate where specifically applicable.'],
        ];

        foreach ($slabs as $slab) {
            DB::table('gst_rate_slabs')->updateOrInsert(
                ['rate' => $slab['rate']],
                $this->onlyExistingColumns('gst_rate_slabs', array_merge($slab, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]))
            );
        }
    }

    private function seedMasterFile(string $path, string $codeType): void
    {
        if (!is_file($path) || !Schema::hasTable('hsn_masters')) {
            return;
        }

        $records = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach (array_chunk($records, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $codeType) {
                foreach ($chunk as $record) {
                    $this->seedMasterRecord($record, $codeType);
                }
            });
        }
    }

    private function seedMasterRecord(array $record, string $codeType): void
    {
        $code = trim((string) ($record['code'] ?? ''));

        if ($code === '') {
            return;
        }

        $igst = $record['igst_rate'] ?? null;
        $hasImportedRate = $igst !== null && $igst !== '';
        $gstRate = $hasImportedRate ? (float) $igst : null;
        $now = now();

        $payload = $this->onlyExistingColumns('hsn_masters', [
            'business_id' => null,
            'hsn_code' => $code,
            'code_type' => $codeType,
            'description' => trim((string) ($record['description'] ?? '')),
            'chapter_code' => substr($code, 0, 2),
            'section_name' => $record['section'] ?? null,
            'heading_code' => $record['heading'] ?? substr($code, 0, 4),
            'heading_description' => $record['heading_description'] ?? null,
            'group_code' => $record['group'] ?? null,
            'group_description' => $record['group_description'] ?? null,
            'taxability' => 'taxable',
            'classification_verified' => true,
            'classification_verified_at' => $now,
            'rate_verified' => false,
            'gst_rate' => $gstRate,
            'cess_rate' => null,
            'effective_from' => self::EFFECTIVE_FROM,
            'effective_to' => null,
            'status' => 'active',
            'source_reference' => $record['source_reference'] ?? self::SOURCE_DATASET,
            'source_dataset' => self::SOURCE_DATASET,
            'notes' => $hasImportedRate ? 'Imported classification with reference rate metadata; tax rule remains unverified until reviewed.' : 'Imported master classification only; GST rate requires verification.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $existing = DB::table('hsn_masters')
            ->whereNull('business_id')
            ->where('code_type', $codeType)
            ->where('hsn_code', $code)
            ->first();

        if ($existing) {
            DB::table('hsn_masters')->where('id', $existing->id)->update(collect($payload)->except('created_at')->all());
            $hsnId = $existing->id;
        } else {
            $hsnId = DB::table('hsn_masters')->insertGetId($payload);
        }

        unset($hsnId);
    }

    private function onlyExistingColumns(string $table, array $payload): array
    {
        $columns = Schema::getColumnListing($table);

        return collect($payload)->only($columns)->all();
    }

    private function statusValue(string $table, string $status)
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !Schema::hasColumn($table, 'status')) {
            return $status;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM {$table} WHERE Field = 'status'");
        $type = strtolower((string) ($column->Type ?? ''));

        if (str_contains($type, 'tinyint') || str_contains($type, 'int') || str_contains($type, 'bool')) {
            return $status === 'active' ? 1 : 0;
        }

        return $status;
    }
}
