<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\HsnMaster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Products got their HSN straight from the BillIQ reference master, so the company's own HSN/SAC Master screen stayed empty.
 * This creates one business HSN/SAC record per distinct product HSN (rate = the most used GST rate on products with that code),
 * linked to the reference record. Products and their own GST rates are NOT changed. Dry-run by default.
 */
class SyncBusinessHsnMaster extends Command
{
    protected $signature = 'billiq:sync-hsn-master {--gstin=05AWGPV0859A1ZM} {--user=3} {--commit}';
    protected $description = 'Create the company HSN/SAC master records from the HSN codes used on its products';

    public function handle(): int
    {
        $bid = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail()->id;
        $groups = DB::table('products')->where('business_id', $bid)->whereNull('deleted_at')->whereNotNull('hsn_code')->where('hsn_code', '!=', '')
            ->selectRaw('hsn_code, gst_rate, count(*) n, max(hsn_master_id) ref_id')->groupBy('hsn_code', 'gst_rate')->get()->groupBy('hsn_code');
        $existing = HsnMaster::where('business_id', $bid)->get()->keyBy(fn ($r) => $r->code_type . '|' . $r->hsn_code);
        $created = 0; $skipped = 0; $mixed = [];
        foreach ($groups as $code => $rates) {
            $top = $rates->sortByDesc('n')->first();
            $ref = $rates->pluck('ref_id')->filter()->first();
            $reference = $ref ? HsnMaster::whereNull('business_id')->find($ref) : HsnMaster::whereNull('business_id')->where('hsn_code', $code)->first();
            $type = $reference->code_type ?? (str_starts_with($code, '99') ? 'SAC' : 'HSN');
            if ($existing->has($type . '|' . $code)) { $skipped++; continue; }
            $rate = (float) $top->gst_rate;
            if ($rates->count() > 1) $mixed[] = $code . ' (' . $rates->map(fn ($r) => (float) $r->gst_rate . '%x' . $r->n)->implode(', ') . ')';
            $payload = [
                'business_id' => $bid, 'reference_hsn_master_id' => $reference->id ?? null, 'code_type' => $type, 'hsn_code' => (string) $code,
                'description' => $reference->description ?? ('HSN ' . $code), 'chapter_code' => $reference->chapter_code ?? substr($code, 0, 2),
                'gst_rate' => $rate, 'cess_rate' => 0, 'taxability' => $rate > 0 ? 'taxable' : 'nil_rated',
                'verification_status' => $reference ? 'rate_suggested' : 'unverified', 'effective_from' => now()->toDateString(), 'status' => 'active',
                'source_reference' => 'Tally import - product HSN', 'created_by' => (int) $this->option('user'),
            ];
            $this->line(sprintf('  %-8s %-4s %5.1f%%  %s', $code, $type, $rate, mb_substr($payload['description'], 0, 60)));
            if ($this->option('commit')) HsnMaster::create($payload);
            $created++;
        }
        $this->info("$created record(s) " . ($this->option('commit') ? 'CREATED' : 'to create (DRY RUN, add --commit)') . ", $skipped already present.");
        if ($mixed) $this->warn(count($mixed) . ' code(s) have different GST rates across products (master uses the most common; product rates are untouched): ' . implode(' | ', $mixed));
        return self::SUCCESS;
    }
}
