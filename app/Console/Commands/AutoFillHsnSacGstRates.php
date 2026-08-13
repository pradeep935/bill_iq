<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AutoFillHsnSacGstRates extends Command
{
    protected $signature = 'hsn-sac:auto-fill-gst-rates
        {--rules= : JSON file containing prefix rate rules}
        {--apply-residual-18 : Fill unmatched taxable HSN/SAC records with 18% suggested rate}
        {--overwrite : Replace existing non-verified suggested/pending rates}
        {--dry-run : Show counts without updating records}';

    protected $description = 'Auto-fill suggested GST rates on HSN/SAC master records using prefix rules.';

    public function handle(): int
    {
        if (!Schema::hasTable('hsn_masters')) {
            $this->error('hsn_masters table does not exist.');
            return self::FAILURE;
        }

        $rules = $this->loadRules();
        if (!$rules) {
            $this->error('No GST rate rules found.');
            return self::FAILURE;
        }

        $columns = Schema::getColumnListing('hsn_masters');
        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');
        $residual = (bool) $this->option('apply-residual-18');
        $now = now();
        $counts = ['matched' => 0, 'residual' => 0, 'skipped' => 0, 'verified' => 0];

        DB::table('hsn_masters')
            ->where(function ($query) {
                $query->whereNull('business_id')->orWhereNotNull('business_id');
            })
            ->orderBy('id')
            ->chunkById(500, function ($records) use ($rules, $columns, $dryRun, $overwrite, $residual, $now, &$counts) {
                foreach ($records as $record) {
                    $status = strtolower(trim((string) ($record->verification_status ?? '')));
                    if (in_array($status, ['verified', 'rate_verified', 'rate verified'], true)) {
                        $counts['verified']++;
                        continue;
                    }

                    if (!$overwrite && $record->gst_rate !== null && !in_array($status, ['', 'unverified', 'classification_verified', 'classification verified'], true)) {
                        $counts['skipped']++;
                        continue;
                    }

                    $match = $this->matchRule($rules, (string) $record->code_type, (string) $record->hsn_code);
                    $usedResidual = false;

                    if (!$match && $residual) {
                        $match = [
                            'gst_rate' => 18,
                            'cess_rate' => 0,
                            'taxability' => 'taxable',
                            'description' => 'Residual taxable GST rate suggestion.',
                            'source_reference' => 'Auto-fill residual 18% suggestion. Verify exact entry, condition and date from latest CBIC notification before billing.',
                        ];
                        $usedResidual = true;
                    }

                    if (!$match) {
                        $counts['skipped']++;
                        continue;
                    }

                    $payload = [
                        'gst_rate' => (float) $match['gst_rate'],
                        'cess_rate' => (float) ($match['cess_rate'] ?? 0),
                        'taxability' => $match['taxability'] ?? 'taxable',
                        'verification_status' => 'rate_suggested',
                        'effective_from' => $record->effective_from ?: '2017-07-01',
                        'effective_to' => null,
                        'source_reference' => $match['source_reference'] ?? 'GST rate seed rule. Verify with latest CBIC notification before billing.',
                        'notes' => trim('Auto-filled suggested GST rate. ' . ($match['description'] ?? '')),
                        'updated_at' => $now,
                    ];

                    $payload = collect($payload)->only($columns)->all();

                    if (!$dryRun) {
                        DB::table('hsn_masters')->where('id', $record->id)->update($payload);
                    }

                    $counts[$usedResidual ? 'residual' : 'matched']++;
                }
            });

        $this->table(['Type', 'Records'], [
            ['Prefix matched', $counts['matched']],
            ['Residual 18%', $counts['residual']],
            ['Already verified', $counts['verified']],
            ['Skipped', $counts['skipped']],
        ]);

        $this->warn('Rates are marked as rate_suggested, not legally verified. Verify exact CBIC/GST notification before billing.');

        return self::SUCCESS;
    }

    private function loadRules(): array
    {
        $path = $this->option('rules') ?: database_path('data/gst_rate_seed_rules.json');
        if (!File::exists($path)) {
            return [];
        }

        $rules = json_decode(File::get($path), true);
        if (!is_array($rules)) {
            return [];
        }

        usort($rules, function (array $first, array $second) {
            return strlen((string) ($second['prefix'] ?? '')) <=> strlen((string) ($first['prefix'] ?? ''));
        });

        return $rules;
    }

    private function matchRule(array $rules, string $codeType, string $code): ?array
    {
        $codeType = strtoupper(trim($codeType));
        $code = preg_replace('/\D+/', '', $code);

        foreach ($rules as $rule) {
            if (strtoupper((string) ($rule['code_type'] ?? 'HSN')) !== $codeType) {
                continue;
            }

            $prefix = preg_replace('/\D+/', '', (string) ($rule['prefix'] ?? ''));
            if ($prefix !== '' && str_starts_with($code, $prefix)) {
                return $rule;
            }
        }

        return null;
    }
}
