<?php

use App\Models\User;
use App\Services\CompanyAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$action = $argv[1] ?? 'state';

function assert_browser_sqlite(): string
{
    $connection = config('database.default');
    $database = (string) config('database.connections.' . $connection . '.database');
    $expected = str_replace('\\', '/', realpath(__DIR__ . '/../../storage/framework/testing') ?: (__DIR__ . '/../../storage/framework/testing'));
    $actual = str_replace('\\', '/', $database);

    if ($connection !== 'sqlite' || !str_contains($actual, $expected) || !str_ends_with($actual, 'browser-e2e.sqlite')) {
        throw new RuntimeException('Refusing to reset a non-browser SQLite database.');
    }

    return $database;
}

function table_count(string $table): int
{
    return DB::getSchemaBuilder()->hasTable($table) ? DB::table($table)->count() : 0;
}

function business_counts(): array
{
    return [
        'companies' => table_count('companies'),
        'gst_import_batches' => table_count('gst_import_batches'),
        'gst_import_rows' => table_count('gst_import_rows'),
        'sales_vouchers' => table_count('sales_vouchers'),
        'purchase_vouchers' => table_count('purchase_vouchers'),
        'stock_ledgers' => table_count('stock_ledgers'),
        'journal_vouchers' => table_count('journal_vouchers'),
        'products' => table_count('products'),
    ];
}

if ($action === 'reset') {
    $database = assert_browser_sqlite();
    DB::disconnect();
    if (file_exists($database)) {
        unlink($database);
    }
    if (!is_dir(dirname($database))) {
        mkdir(dirname($database), 0777, true);
    }
    touch($database);
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $action = 'setup';
}

if ($action === 'setup') {
    $now = now();

    $abcId = DB::table('companies')->insertGetId([
        'name' => 'ABC Retail Pvt Ltd',
        'gstin' => '09ABCDE1234F1Z5',
        'state' => 'Uttar Pradesh',
        'financial_year' => '2026-27',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $gstFixtureId = DB::table('companies')->insertGetId([
        'name' => 'Disposable GST Fixture Ltd',
        'gstin' => '05AABCP5818M1Z7',
        'state' => 'Uttarakhand',
        'financial_year' => '2026-27',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ([[$abcId, 'Main Branch', 'MB'], [$gstFixtureId, 'GST Fixture Branch', 'GFB']] as [$companyId, $name, $code]) {
        if (DB::getSchemaBuilder()->hasTable('branches')) {
            DB::table('branches')->insert([
                'business_id' => $companyId,
                'name' => $name,
                'code' => $code,
                'state' => 'Uttarakhand',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (DB::getSchemaBuilder()->hasTable('warehouses')) {
            DB::table('warehouses')->insert([
                'business_id' => $companyId,
                'name' => $name . ' Warehouse',
                'code' => $code . 'W',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    $user = User::query()->create([
        'name' => 'Browser Tester',
        'email' => 'browser.tester@example.test',
        'password' => Hash::make('browser-safe-password'),
        'role_id' => 1,
        'tenant_id' => $abcId,
        'status' => 'active',
        'is_active' => 1,
        'email_verified_at' => $now,
    ]);

    app(CompanyAccessService::class)->grant($user, $abcId, 'owner', true, $user->id);
    app(CompanyAccessService::class)->grant($user, $gstFixtureId, 'admin', false, $user->id);

    echo json_encode([
        'user' => ['email' => $user->email, 'password' => 'browser-safe-password'],
        'companies' => ['abc' => $abcId, 'gst_fixture' => $gstFixtureId],
        'counts' => business_counts(),
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'state') {
    $connection = config('database.default');
    $database = config('database.connections.' . $connection . '.database');

    echo json_encode([
        'db' => $connection === 'sqlite' ? $database : (DB::selectOne('select database() as db')->db ?? $database),
        'counts' => business_counts(),
        'companies' => DB::getSchemaBuilder()->hasTable('companies')
            ? DB::table('companies')->select('id', 'name', 'gstin')->orderBy('id')->get()
            : [],
        'phooltas_count' => DB::getSchemaBuilder()->hasTable('companies')
            ? DB::table('companies')->where('name', 'PHOOLTAS TRANSRAIL LIMITED')->orWhere('gstin', '05AABCP5818M1Z7')->count()
            : 0,
    ], JSON_PRETTY_PRINT);
}
