<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MobileNumberService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tally ledger names often end with the party's mobile, e.g. "Aditi Dixit Ji (8960946071)", while BillIQ's
 * mobile/WhatsApp fields stayed empty. This copies a valid 10-digit mobile out of the name (the name is left as is).
 * A number already used by another party in the same company is skipped (BillIQ enforces unique mobiles).
 */
class FillPartyMobiles extends Command
{
    protected $signature = 'billiq:fill-party-mobiles {--gstin=05AWGPV0859A1ZM} {--commit}';
    protected $description = 'Fill empty customer/supplier mobile numbers from the 10-digit number inside the party name (dry-run by default)';

    public function handle(MobileNumberService $mobiles): int
    {
        $bid = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail()->id;
        $used = DB::table('customers')->where('business_id', $bid)->whereNotNull('normalized_mobile')->pluck('id', 'normalized_mobile')->all();
        $stats = ['customers_filled' => 0, 'customers_duplicate_skipped' => 0, 'suppliers_filled' => 0, 'no_number' => 0];
        $updates = [];

        foreach (DB::table('customers')->where('business_id', $bid)->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('mobile')->orWhere('mobile', ''))->get(['id', 'customer_name']) as $c) {
            $m = $this->extract($c->customer_name, $mobiles);
            if (!$m) { $stats['no_number']++; continue; }
            if (isset($used[$m])) { $stats['customers_duplicate_skipped']++; continue; }
            $used[$m] = $c->id;
            $updates['customers'][$c->id] = ['mobile' => $m, 'normalized_mobile' => $m, 'whatsapp_number' => $m, 'whatsapp_same_as_mobile' => 1];
            $stats['customers_filled']++;
        }
        foreach (DB::table('suppliers')->where('business_id', $bid)->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('mobile')->orWhere('mobile', ''))->get(['id', 'supplier_name']) as $s) {
            $m = $this->extract($s->supplier_name, $mobiles);
            if (!$m) continue;
            $updates['suppliers'][$s->id] = ['mobile' => $m, 'phone' => $m];
            $stats['suppliers_filled']++;
        }
        foreach ($stats as $k => $v) $this->line(sprintf('  %-30s %d', $k, $v));

        if (!$this->option('commit')) { $this->info('DRY RUN: nothing written.'); return self::SUCCESS; }
        DB::transaction(function () use ($updates) {
            foreach ($updates as $table => $rows) foreach ($rows as $id => $data) DB::table($table)->where('id', $id)->update($data + ['updated_at' => now()]);
        });
        $this->info('COMMITTED.');
        return self::SUCCESS;
    }

    private function extract(string $name, MobileNumberService $mobiles): ?string
    {
        if (!preg_match_all('/(?<!\d)(?:\+?91[\s-]?)?([6-9]\d{9})(?!\d)/', $name, $m) || !$m[1]) return null;
        return $mobiles->normalize(end($m[1]));
    }
}
