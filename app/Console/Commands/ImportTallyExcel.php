<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\BusinessAccountSetting;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingPostingService;
use App\Services\CompanyOnboardingService;
use App\Services\OpeningStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Imports TallyPrime Excel exports (List of Ledgers + Stock Summary) into ONE company.
 * Dry-run by default; nothing is written unless --commit is passed.
 */
class ImportTallyExcel extends Command
{
    protected $signature = 'billiq:import-tally-excel
        {--dir= : Folder containing the Excel exports (default ~/Downloads)}
        {--ledgers=Master_Ledger12112026_2.xlsx : Ledger master export}
        {--stock=StkSum_12112026.xlsx : Stock summary export}
        {--company-name=HARI SIVANANDA JI ENTERPRISES}
        {--gstin=05AWGPV0859A1ZM}
        {--state=Uttarakhand}
        {--opening-date=2023-04-01}
        {--user=1 : User id that owns the company and is recorded as creator}
        {--commit : Actually write to the database (otherwise dry-run report only)}';

    protected $description = 'Import Tally Excel exports (ledgers, stock items, opening balances) into a single BillIQ company';

    private array $warnings = [];

    public function handle(): int
    {
        $dir = rtrim($this->option('dir') ?: (getenv('HOME') . '/Downloads'), '/');
        ini_set('memory_limit', '4G');

        $ledgers = $this->readLedgers($dir . '/' . $this->option('ledgers'));
        $items = $this->readStock($dir . '/' . $this->option('stock'));
        $this->report($ledgers, $items);

        if (!$this->option('commit')) {
            $this->newLine();
            $this->info('DRY RUN: nothing was written. Re-run with --commit to import.');
            return self::SUCCESS;
        }

        $this->commit($ledgers, $items);
        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- parsing

    private function readLedgers(string $file): array
    {
        $reader = IOFactory::createReaderForFile($file);
        $ws = $reader->load($file)->getActiveSheet();
        $rows = [];
        $started = false;
        $seen = [];
        for ($i = 1, $hi = $ws->getHighestRow(); $i <= $hi; $i++) {
            $first = $ws->getCell("A$i")->getValue();
            if ($first === 'Sl. No.') { $started = true; continue; }
            if (!$started || !is_numeric($first)) continue;

            $name = $this->clean($ws->getCell("B$i")->getValue());
            if ($name === '') continue;
            $key = mb_strtolower($name);
            if (isset($seen[$key])) { $this->warnings[] = "Duplicate ledger name skipped: $name"; continue; }
            $seen[$key] = true;

            $under = $this->clean($ws->getCell("C$i")->getValue());
            $gstin = strtoupper($this->clean($ws->getCell("F$i")->getValue()));
            $gstinOk = (bool) preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/', $gstin);
            if ($gstin !== '' && !$gstinOk) {
                $this->warnings[] = "Invalid GSTIN ignored for '$name': $gstin";
                $gstin = '';
            }

            $formatted = trim((string) $ws->getCell("G$i")->getFormattedValue());
            $raw = $ws->getCell("G$i")->getValue();
            $opening = is_numeric($raw) ? round((float) $raw, 2) : 0.0;
            $side = preg_match('/\b(Dr|Cr)\s*$/i', $formatted, $m) ? strtolower($m[1]) : null;

            $rows[] = [
                'name' => $name,
                'under' => $under,
                'state' => $this->clean($ws->getCell("D$i")->getValue()),
                'gst_type' => $this->clean($ws->getCell("E$i")->getValue()),
                'gstin' => $gstin ?: null,
                'opening' => $opening,
                'side' => $side,
            ];
        }
        return $rows;
    }

    private function readStock(string $file): array
    {
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return in_array($columnAddress, ['A', 'B', 'D', 'E'], true) && $row >= 11;
            }
        });
        $ws = $reader->load($file)->getActiveSheet();
        $items = [];
        for ($i = 11, $hi = $ws->getHighestRow(); $i <= $hi; $i++) {
            $name = $this->clean($ws->getCell("A$i")->getValue());
            if ($name === '' || strcasecmp($name, 'Grand Total') === 0) continue;
            $qtyText = trim((string) $ws->getCell("B$i")->getFormattedValue());
            $qty = (float) $ws->getCell("B$i")->getValue();
            $value = (float) $ws->getCell("E$i")->getValue();
            $unit = preg_match('/([A-Za-z]+)\s*$/', $qtyText, $m) ? strtoupper($m[1]) : null;
            $items[] = ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'value' => round($value, 2)];
        }
        return $items;
    }

    // ---------------------------------------------------------------- mapping

    private function clean(?string $v): string
    {
        $v = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $v);
        return trim(preg_replace('/\s{2,}/u', ' ', $v));
    }

    /** Role of a Tally ledger in BillIQ. */
    private function role(array $l): string
    {
        return match (true) {
            $l['under'] === 'Sundry Debtors' => 'customer',
            $l['under'] === 'Sundry Creditors' => 'supplier',
            $l['under'] === 'Primary' => 'skip', // Profit & Loss A/c: absorbed in the opening equity balance
            default => 'account',
        };
    }

    /** [account_type, nature(debit|credit)] for a Tally group/ledger. */
    private function accountKind(array $l): array
    {
        $name = strtolower($l['name']);
        return match ($l['under']) {
            'Cash-in-Hand' => ['cash', 'debit'],
            'Bank Accounts' => ['bank', 'debit'],
            'Current Assets' => ['asset', 'debit'],
            'Sales Accounts' => ['income', 'credit'],
            'Purchase Accounts', 'Direct Expenses', 'Indirect Expenses' => ['expense', 'debit'],
            'Unsecured Loans' => ['liability', 'credit'],
            'Capital Account' => ['equity', 'credit'],
            'Duties & Taxes' => str_starts_with($name, 'input') ? ['asset', 'debit'] : ['liability', 'credit'],
            default => ['asset', 'debit'],
        };
    }

    private function report(array $ledgers, array $items): void
    {
        $roles = collect($ledgers)->groupBy(fn ($l) => $this->role($l))->map->count();
        $this->info('=== LEDGERS ===');
        $this->line('Total: ' . count($ledgers) . ' | customers: ' . ($roles['customer'] ?? 0) . ' | suppliers: ' . ($roles['supplier'] ?? 0)
            . ' | other accounts: ' . ($roles['account'] ?? 0) . ' | skipped (P&L): ' . ($roles['skip'] ?? 0));

        $dr = $cr = 0.0;
        $rows = [];
        foreach ($ledgers as $l) {
            if ($l['opening'] == 0.0) continue;
            $role = $this->role($l);
            if ($role === 'skip') { $rows[] = [$l['name'], $l['under'], $l['opening'], $l['side'] ?? '?', 'P&L - not posted']; continue; }
            $nature = match ($role) { 'customer' => 'debit', 'supplier' => 'credit', default => $this->accountKind($l)[1] };
            $side = $l['side'] ? ($l['side'] === 'dr' ? 'debit' : 'credit') : $nature;
            $flag = !$l['side'] ? 'no Dr/Cr in file, assumed ' . $nature : ($side !== $nature ? 'abnormal side' : '');
            if ($flag !== '') $this->warnings[] = "Opening balance '{$l['name']}' ({$l['under']}): $flag";
            $side === 'debit' ? $dr += $l['opening'] : $cr += $l['opening'];
            $rows[] = [$l['name'], $l['under'], $l['opening'], strtoupper(substr($side, 0, 2)), $flag];
        }
        $this->table(['Ledger', 'Under', 'Opening', 'Side', 'Note'], $rows);
        $this->line(sprintf('Ledger openings: Dr %s | Cr %s | difference to Opening Balance Equity: %s', number_format($dr, 2), number_format($cr, 2), number_format($dr - $cr, 2)));

        $this->info('=== STOCK ITEMS ===');
        $withOpening = array_filter($items, fn ($i) => $i['qty'] > 0 && $i['value'] >= 0);
        $negative = array_filter($items, fn ($i) => $i['qty'] < 0 || $i['value'] < 0);
        foreach ($negative as $i) $this->warnings[] = "Negative opening stock skipped: {$i['name']} qty {$i['qty']} value {$i['value']}";
        foreach (array_filter($items, fn ($i) => $i['qty'] == 0 && $i['value'] > 0) as $i) $this->warnings[] = "Value-only item (no quantity) imported as a SERVICE item, opening value {$i['value']} NOT posted as stock: {$i['name']}";
        $units = collect($items)->pluck('unit')->filter()->countBy()->all();
        $this->line('Items: ' . count($items) . ' | with positive opening: ' . count($withOpening) . ' | opening value: ' . number_format(array_sum(array_column($withOpening, 'value')), 2) . ' | negative skipped: ' . count($negative));
        $this->line('Units seen: ' . json_encode($units));
        $this->line('GST rate / HSN are NOT in these exports: products are created with 0% GST and no HSN (to be reviewed).');

        $this->info('=== WARNINGS (' . count($this->warnings) . ') ===');
        foreach ($this->warnings as $w) $this->line(' - ' . $w);
    }

    // ----------------------------------------------------------------- commit

    private function commit(array $ledgers, array $items): void
    {
        $user = User::findOrFail((int) $this->option('user'));
        Auth::login($user);
        $gstin = strtoupper($this->option('gstin'));
        $openingDate = $this->option('opening-date');
        $stateName = $this->option('state');
        $stateId = DB::table('states')->where('name', $stateName)->value('id');

        DB::transaction(function () use ($ledgers, $items, $user, $gstin, $openingDate, $stateName, $stateId) {
            $company = Company::whereRaw('UPPER(gstin) = ?', [$gstin])->first();
            if ($company) {
                $this->warn("Company with GSTIN $gstin already exists (ID {$company->id}); importing into it idempotently.");
            } else {
                $created = app(CompanyOnboardingService::class)->create([
                    'legal_name' => $this->option('company-name'),
                    'gstin' => $gstin,
                    'state' => $stateName,
                    'financial_year' => '2023-24',
                    'period_start_date' => '2023-04-01',
                    'period_end_date' => '2024-03-31',
                    'default_branch_name' => 'Main Branch',
                    'default_branch_code' => 'MAIN',
                    'default_branch_city' => 'Haridwar',
                    'default_warehouse_name' => 'Main Warehouse',
                    'default_warehouse_code' => 'MAIN',
                    'registered_address' => 'G 33 Shivalik Nagar Ranipur Range BHEL, Haridwar',
                ], $user);
                $company = $created['company'];
                $company->update([
                    'address' => 'G 33 Shivalik Nagar Ranipur Range BHEL, Haridwar',
                    'phone' => '+91 8171710265',
                    'email' => 'hari.sivanandaji.enterprises@gmail.com',
                ]);
                foreach (['2024-25' => 2024, '2025-26' => 2025, '2026-27' => 2026] as $fy => $y) {
                    DB::table('accounting_periods')->updateOrInsert(
                        ['business_id' => $company->id, 'financial_year' => $fy],
                        ['start_date' => "$y-04-01", 'end_date' => ($y + 1) . '-03-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
            $bid = (int) $company->id;
            $this->info("Company ID: $bid");

            // Tenant context for services that read session/Auth
            config(['session.driver' => 'array']);
            session(['business_id' => $bid, 'company_id' => $bid, 'tenant_id' => $bid]);

            $branch = DB::table('branches')->where('business_id', $bid)->orderBy('id')->first();
            $warehouse = Warehouse::where('business_id', $bid)->orderBy('id')->first();

            $groups = $this->importGroups($bid, $ledgers);
            $settings = $this->ensureSettings($bid);
            $equityId = $this->ensureEquity($bid);

            $entries = [];
            $stats = ['accounts' => 0, 'customers' => 0, 'suppliers' => 0, 'opening_lines' => 0];
            $customerIds = $supplierIds = [];

            foreach ($ledgers as $l) {
                $role = $this->role($l);
                if ($role === 'skip') continue;
                $side = $l['side'] ? ($l['side'] === 'dr' ? 'debit' : 'credit') : null;
                $stateRef = $l['state'] ? DB::table('states')->where('name', $l['state'])->value('id') : null;

                if ($role === 'customer') {
                    $c = Customer::firstOrCreate(
                        ['business_id' => $bid, 'customer_name' => $l['name']],
                        $this->partyAttrs($bid, $l, $stateRef, 'CUS-', Customer::class, 'customer_code') + [
                            'name' => $l['name'], 'company_id' => $bid, 'customer_type' => 'retail', 'whatsapp_same_as_mobile' => false,
                        ]
                    );
                    $c->wasRecentlyCreated && $stats['customers']++;
                    if ($l['opening'] > 0 && $c->wasRecentlyCreated) {
                        $side ??= 'debit';
                        $customerIds[$c->id] = $c;
                        $entries[] = $this->jline($settings->accounts_receivable_id, $side, $l['opening'], ['customer_id' => $c->id], $c);
                    }
                } elseif ($role === 'supplier') {
                    $s = Supplier::firstOrCreate(
                        ['business_id' => $bid, 'supplier_name' => $l['name']],
                        $this->partyAttrs($bid, $l, $stateRef, 'SUP-', Supplier::class, 'supplier_code') + ['name' => $l['name']]
                    );
                    $s->wasRecentlyCreated && $stats['suppliers']++;
                    if ($l['opening'] > 0 && $s->wasRecentlyCreated) {
                        $side ??= 'credit';
                        $supplierIds[$s->id] = $s;
                        $entries[] = $this->jline($settings->accounts_payable_id, $side, $l['opening'], ['supplier_id' => $s->id], $s);
                    }
                } else {
                    [$type, $nature] = $this->accountKind($l);
                    $existing = Account::where('business_id', $bid)->where('account_name', $l['name'])->first();
                    $side ??= $nature;
                    if ($existing) {
                        // Default system account with the same name (e.g. Input CGST): adopt it and post its opening once.
                        if ($existing->is_system && (float) $existing->opening_balance == 0.0 && $l['opening'] > 0
                            && !DB::table('journal_entries')->where('account_id', $existing->id)->exists()) {
                            $existing->update(['opening_balance' => $l['opening'], 'opening_balance_type' => $side]);
                            $entries[] = $this->jline($existing->id, $side, $l['opening']);
                            $stats['accounts_adopted'] = ($stats['accounts_adopted'] ?? 0) + 1;
                        }
                        continue;
                    }
                    $acc = Account::create([
                        'business_id' => $bid, 'account_group_id' => $groups[$l['under']] ?? null,
                        'account_code' => 'TLY-' . strtoupper(substr(sha1($bid . $l['name']), 0, 10)),
                        'code' => 'TLY-' . strtoupper(substr(sha1($bid . $l['name']), 0, 10)),
                        'account_name' => $l['name'], 'name' => $l['name'], 'account_type' => $type,
                        'opening_balance' => $l['opening'], 'opening_balance_type' => $side, 'current_balance' => 0,
                        'status' => 'active', 'created_by' => Auth::id(),
                    ]);
                    $stats['accounts']++;
                    $this->mapSettings($settings, $acc);
                    if ($l['opening'] > 0) $entries[] = $this->jline($acc->id, $side, $l['opening']);
                }
            }

            // Opening balance journal (one voucher, balanced against Opening Balance Equity)
            if ($entries) {
                $dr = array_sum(array_column($entries, 'debit_amount'));
                $cr = array_sum(array_column($entries, 'credit_amount'));
                $diff = round($dr - $cr, 2);
                if ($diff != 0.0) {
                    $entries[] = ['account_id' => $equityId, 'debit_amount' => $diff < 0 ? abs($diff) : 0, 'credit_amount' => $diff > 0 ? $diff : 0, 'narration' => 'Opening balance difference (includes P&L brought forward)'];
                }
                $voucher = app(AccountingPostingService::class)->createJournalVoucher([
                    'business_id' => $bid, 'branch_id' => $branch->id ?? null, 'voucher_type' => 'opening_balance', 'voucher_date' => $openingDate,
                    'reference_number' => 'TALLY-EXCEL-OPENING', 'narration' => 'Opening balances imported from Tally Excel export',
                    'status' => 'approved', 'is_system_generated' => true, 'entries' => $entries,
                ]);
                foreach ($customerIds as $c) $c->update(['opening_balance_voucher_id' => $voucher->id]);
                $stats['opening_lines'] = count($entries);
                $this->line("Opening journal #{$voucher->voucher_number}: lines " . count($entries) . ", balancing equity amount " . number_format(abs($diff), 2) . ($diff > 0 ? ' Cr' : ' Dr'));
            }

            $this->importSettingsCleanup($bid);
            $this->importProducts($bid, $items, $branch, $warehouse, $openingDate, $stats);

            $this->table(['Imported', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        });

        $this->info('Import committed.');
    }

    private function importGroups(int $bid, array $ledgers): array
    {
        $map = [];
        foreach (collect($ledgers)->pluck('under')->filter()->unique() as $name) {
            if ($name === 'Primary') continue;
            [$type, $nature] = in_array($name, ['Sundry Debtors'], true) ? ['asset', 'debit']
                : (in_array($name, ['Sundry Creditors'], true) ? ['liability', 'credit'] : $this->accountKind(['under' => $name, 'name' => '']));
            $g = AccountGroup::firstOrCreate(
                ['business_id' => $bid, 'group_name' => $name],
                ['group_code' => 'TLY-' . strtoupper(Str::limit(Str::slug($name, ''), 20, '')), 'group_type' => $type, 'nature' => $nature, 'normal_balance' => $nature, 'status' => 'active']
            );
            $map[$name] = $g->id;
        }
        return $map;
    }

    private function ensureSettings(int $bid): BusinessAccountSetting
    {
        $svc = app(AccountingPostingService::class);
        $m = new \ReflectionMethod($svc, 'settings');
        $m->setAccessible(true);
        return $m->invoke($svc, $bid);
    }

    private function ensureEquity(int $bid): int
    {
        return (int) Account::firstOrCreate(
            ['business_id' => $bid, 'account_code' => 'OPEN-EQ'],
            ['account_name' => 'Opening Balance Equity', 'name' => 'Opening Balance Equity', 'code' => 'OPEN-EQ', 'account_type' => 'equity',
             'opening_balance' => 0, 'opening_balance_type' => 'credit', 'current_balance' => 0, 'is_system' => true, 'status' => 'active', 'created_by' => Auth::id()]
        )->id;
    }

    /** Re-point system defaults at the matching imported Tally ledgers. */
    private array $replaced = [];

    private function mapSettings(BusinessAccountSetting $s, Account $acc): void
    {
        $n = strtolower($acc->account_name);
        $key = match (true) {
            $n === 'cash' => 'cash_account_id',
            $acc->account_type === 'bank' => 'default_bank_account_id',
            $n === 'sales' => 'sales_account_id',
            $n === 'purchase' || $n === 'purchases' => 'purchase_account_id',
            $n === 'input cgst' => 'input_cgst_account_id', $n === 'input sgst' => 'input_sgst_account_id', $n === 'input igst' => 'input_igst_account_id',
            $n === 'output cgst' => 'output_cgst_account_id', $n === 'output sgst' => 'output_sgst_account_id', $n === 'output igst' => 'output_igst_account_id',
            $n === 'round off' || $n === 'rounded off' => 'round_off_account_id',
            default => null,
        };
        if (!$key || isset($this->replaced[$key])) return;
        $this->replaced[$key] = $s->{$key};
        $s->{$key} = $acc->id;
        $s->save();
    }

    private function importSettingsCleanup(int $bid): void
    {
        foreach ($this->replaced as $key => $oldId) {
            $used = DB::table('journal_entries')->where('account_id', $oldId)->exists();
            if (!$used) Account::where('business_id', $bid)->where('id', $oldId)->where('is_system', true)->delete();
        }
    }

    private function jline(int $accountId, string $side, float $amount, array $extra = [], ?object $party = null): array
    {
        return array_merge([
            'account_id' => $accountId,
            'debit_amount' => $side === 'debit' ? $amount : 0,
            'credit_amount' => $side === 'credit' ? $amount : 0,
            'narration' => 'Opening balance' . ($party ? ' - ' . ($party->customer_name ?? $party->supplier_name) : ''),
        ], $extra);
    }

    private function partyAttrs(int $bid, array $l, ?int $stateId, string $prefix, string $model, string $codeCol): array
    {
        $next = $model::withTrashed()->where('business_id', $bid)->count() + 1;
        $attrs = [
            $codeCol => $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            'gstin' => $l['gstin'], 'state_id' => $stateId,
            'opening_balance' => $l['opening'] ?: 0,
            'opening_balance_type' => $l['side'] ? ($l['side'] === 'dr' ? 'debit' : 'credit') : ($prefix === 'SUP-' ? 'credit' : 'debit'),
            'status' => 'active', 'created_by' => Auth::id(),
        ];
        return $attrs;
    }

    private function importProducts(int $bid, array $items, ?object $branch, ?Warehouse $warehouse, string $openingDate, array &$stats): void
    {
        $unitCache = [];
        $unitFor = function (?string $code) use (&$unitCache) {
            $code = $code ?: 'NOS';
            if ($code === 'PAC') $code = 'PKT';
            return $unitCache[$code] ??= Unit::firstOrCreate(['code' => $code], ['name' => ucfirst(strtolower($code)), 'status' => 'active']);
        };

        $stats['products'] = 0;
        $lines = [];
        $seq = Product::withTrashed()->where('business_id', $bid)->count();
        foreach ($items as $it) {
            $product = Product::where('business_id', $bid)->where('name', $it['name'])->first();
            $serviceOnly = $it['qty'] == 0 && $it['value'] > 0;
            if (!$product) {
                $unit = $unitFor($it['unit']);
                $product = Product::create([
                    'business_id' => $bid, 'company_id' => $bid, 'name' => $it['name'], 'product_name' => $it['name'],
                    'sku' => 'ITM-' . str_pad((string) ++$seq, 5, '0', STR_PAD_LEFT),
                    'product_type' => $serviceOnly ? 'service' : 'goods', 'item_type' => $serviceOnly ? 'service' : 'stock', 'unit_id' => $unit->id, 'unit' => $unit->code,
                    'gst_rate' => 0, 'track_inventory' => !$serviceOnly, 'opening_stock' => 0, 'current_stock' => 0, 'status' => 'active',
                    'created_by' => Auth::id(),
                ]);
                $stats['products']++;
            }
            if ($it['qty'] > 0 && $it['value'] >= 0 && (float) $product->opening_stock == 0.0 && !DB::table('stock_ledgers')->where('business_id', $bid)->where('product_id', $product->id)->exists()) {
                $lines[] = ['product_id' => $product->id, 'quantity' => $it['qty'], 'purchase_cost' => round($it['value'] / $it['qty'], 4), 'remarks' => 'Tally Excel opening stock'];
            }
        }

        $stats['opening_stock_items'] = 0;
        if ($lines && $warehouse) {
            app(OpeningStockService::class)->create([
                'branch_id' => $branch->id ?? $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'opening_date' => $openingDate,
                'remarks' => 'Opening stock imported from Tally Stock Summary export', 'status' => 'posted', 'items' => $lines,
            ]);
            $stats['opening_stock_items'] = count($lines);
        }
    }
}
