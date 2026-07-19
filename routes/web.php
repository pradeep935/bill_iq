<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\GeneralController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\WarehouseController;


Route::get('/', [UserController::class, 'login'])->name('login');
Route::post('/login', [UserController::class, 'postLogin']);
Route::get('/logout', [UserController::class, 'logout']);
Route::post('/uploads-new/file', [GeneralController::class, 'uploadFile']);

Route::middleware('auth')->group(function () {
    Route::prefix('app')->name('app.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/logout', [UserController::class, 'logout'])->name('logout');

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('/workspace', [AdminController::class, 'workspace'])->name('workspace');
            Route::get('/onboarding', [AdminController::class, 'onboarding'])->name('onboarding');
        });

        Route::prefix('staff')->name('staff.')->group(function () {
            Route::get('/workspace', [StaffController::class, 'workspace'])->name('workspace');
        });

        Route::prefix('sales')->name('sales.')->group(function () {
            Route::get('/pos', [PosController::class, 'index'])->name('pos');
            Route::get('/invoices', [SalesController::class, 'invoices'])->name('invoices');
            Route::get('/customers', [SalesController::class, 'customers'])->name('customers');
            Route::get('/stock-outward', [SalesController::class, 'stockOutward'])->name('stock-outward');
            Route::get('/reserved-stock', [SalesController::class, 'reservedStock'])->name('reserved-stock');
        });

        Route::prefix('purchase')->name('purchase.')->group(function () {
            Route::get('/bills', [PurchaseController::class, 'bills'])->name('bills');
            Route::get('/suppliers', [PurchaseController::class, 'suppliers'])->name('suppliers');
            Route::get('/grn', [PurchaseController::class, 'grn'])->name('grn');
            Route::get('/reorder', [PurchaseController::class, 'reorder'])->name('reorder');
            Route::get('/orders', [PurchaseController::class, 'orders'])->name('orders');
        });

        Route::prefix('inventory')
    ->name('inventory.')
    ->group(function () {

        Route::get(
            '/',
            [InventoryController::class, 'dashboard']
        )->name('dashboard');

        Route::get(
            '/products',
            [ProductController::class, 'index']
        )->name('products');

        Route::get(
            '/products/list',
            [ProductController::class, 'products']
        )->name('products.list');

        Route::post(
            '/products/save',
            [ProductController::class, 'save']
        )->name('products.save');

        Route::delete(
            '/products/{product}',
            [ProductController::class, 'destroy']
        )->name('products.destroy');

        Route::get(
            '/hsn-search',
            [ProductController::class, 'hsnSearch']
        )->name('hsn.search');

        Route::get(
            '/add',
            [InventoryController::class, 'add']
        )->name('add');

        Route::get(
            '/current-stock',
            [InventoryController::class, 'currentStock']
        )->name('current-stock');

        Route::get(
            '/vouchers',
            [InventoryController::class, 'vouchers']
        )->name('vouchers');

        Route::get(
            '/batches',
            [InventoryController::class, 'batches']
        )->name('batches');

        Route::get(
            '/serials',
            [InventoryController::class, 'serials']
        )->name('serials');

        Route::get(
            '/barcode-center',
            [InventoryController::class, 'barcodeCenter']
        )->name('barcode-center');

        Route::get(
            '/manufacturing',
            [InventoryController::class, 'manufacturing']
        )->name('manufacturing');
    });

        Route::prefix('warehouse')->name('warehouse.')->group(function () {
            Route::get('/warehouses', [WarehouseController::class, 'warehouses'])->name('warehouses');
            Route::get('/bins', [WarehouseController::class, 'bins'])->name('bins');
            Route::get('/godown-balances', [WarehouseController::class, 'godownBalances'])->name('godown-balances');
            Route::get('/transfer', [WarehouseController::class, 'transfer'])->name('transfer');
            Route::get('/transfer-requests', [WarehouseController::class, 'transferRequests'])->name('transfer-requests');
            Route::get('/adjustment', [WarehouseController::class, 'adjustment'])->name('adjustment');
            Route::get('/audit', [WarehouseController::class, 'audit'])->name('audit');
            Route::get('/allocation', [WarehouseController::class, 'allocation'])->name('allocation');
        });

        Route::prefix('accounting')->name('accounting.')->group(function () {
            Route::get('/chart-of-accounts', [AccountingController::class, 'chartOfAccounts'])->name('chart-of-accounts');
            Route::get('/vouchers', [AccountingController::class, 'vouchers'])->name('vouchers');
            Route::get('/ledgers', [AccountingController::class, 'ledgers'])->name('ledgers');
            Route::get('/expenses', [AccountingController::class, 'expenses'])->name('expenses');
            Route::get('/gst', [AccountingController::class, 'gst'])->name('gst');
            Route::get('/gst-returns', [AccountingController::class, 'gstReturns'])->name('gst-returns');
        });

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/business', [ReportsController::class, 'business'])->name('business');
            Route::get('/inventory', [ReportsController::class, 'inventory'])->name('inventory');
            Route::get('/stock-ledger', [ReportsController::class, 'stockLedger'])->name('stock-ledger');
            Route::get('/stock-valuation', [ReportsController::class, 'stockValuation'])->name('stock-valuation');
            Route::get('/audit-trail', [ReportsController::class, 'auditTrail'])->name('audit-trail');
            Route::get('/acceptance', [ReportsController::class, 'acceptance'])->name('acceptance');
        });

        Route::prefix('setup')->name('setup.')->group(function () {
            Route::get('/branches', [SetupController::class, 'branches'])->name('branches');
            Route::get('/employees', [SetupController::class, 'employees'])->name('employees');
            Route::get('/users', [SetupController::class, 'users'])->name('users');
            Route::get('/saas', [SetupController::class, 'saas'])->name('saas');
            Route::get('/settings', [SetupController::class, 'settings'])->name('settings');
        });
    });


    Route::group(["prefix"=>"upload"], function(){
        Route::post('/photo',[GeneralController::class,'uploadPhoto']);
    });

    Route::post('/uploads/file', [GeneralController::class, 'uploadFile']);
});
