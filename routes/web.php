<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\GeneralController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\OpeningStockController;
use App\Http\Controllers\OrderManagementController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SalesReturnController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StockSummaryController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\WarehouseController;


Route::get('/', [UserController::class, 'login'])->name('login');
Route::get('/login', [UserController::class, 'login'])->name('login.form');
Route::post('/login', [UserController::class, 'postLogin']);
Route::get('/logout', [UserController::class, 'logout']);
Route::post('/uploads-new/file', [GeneralController::class, 'uploadFile']);
Route::get('/i/{token}', [PublicInvoiceController::class, 'show'])->name('public.invoice.show');
Route::get('/i/{token}/pdf', [PublicInvoiceController::class, 'pdf'])->name('public.invoice.pdf');
Route::get('/q/{token}', [PublicInvoiceController::class, 'quotation'])->name('public.quotation.show');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [UserController::class, 'logout'])->name('logout');
    Route::get('/business-dashboard', [DashboardController::class, 'index'])->name('business.dashboard');
    Route::get('/profile', [UserController::class, 'profile'])->name('profile.edit');

    Route::prefix('app/sales/stock-outward')->name('inventory.stock-outward.')->group(function () {
        Route::get('/', [SalesController::class, 'stockOutward'])->name('index');
        Route::get('/data', [SalesController::class, 'stockOutwardData'])->name('data');
        Route::get('/create', [SalesController::class, 'stockOutward'])->name('create');
        Route::post('/', fn () => abort(422, 'Manual stock outward creation is Coming Soon. Use Sales Invoice, Delivery Challan, Transfer or Adjustment workflows for posted stock movements.'))->name('store');
        Route::get('/export', [SalesController::class, 'stockOutwardExport'])->name('export');
        Route::get('/reserved', [SalesController::class, 'stockOutwardReserved'])->name('reserved');
        Route::get('/ledger', [SalesController::class, 'stockOutwardLedger'])->name('ledger');
        Route::get('/print', [SalesController::class, 'stockOutwardPrint'])->name('print-list');
        Route::post('/reservations/{order}/release', [SalesController::class, 'releaseStockReservation'])->name('reservations.release');
        Route::get('/{outward}/detail', [SalesController::class, 'stockOutwardDetail'])->name('detail');
        Route::post('/{outward}/workflow', [SalesController::class, 'stockOutwardWorkflow'])->name('workflow');
        Route::get('/{outward}', [SalesController::class, 'stockOutward'])->name('show');
        Route::get('/{outward}/edit', [SalesController::class, 'stockOutward'])->name('edit');
        Route::patch('/{outward}', fn () => abort(422, 'Editing posted stock outward documents is not available here. Edit the source delivery challan while it is draft.'))->name('update');
        Route::post('/{outward}/confirm', fn () => response()->json(['message' => 'Basic picking confirmation is tracked on the source delivery challan.']))->name('confirm');
        Route::post('/{outward}/dispatch', [SalesController::class, 'dispatchStockOutward'])->name('dispatch');
        Route::post('/{outward}/cancel', [SalesController::class, 'cancelStockOutward'])->name('cancel');
        Route::get('/{outward}/print', [SalesController::class, 'stockOutwardPrint'])->name('print');
        Route::get('/{outward}/pdf', [SalesController::class, 'stockOutwardPrint'])->name('pdf');
    });

    Route::prefix('app/sales/reserved-stock')->name('inventory.reserved-stock.')->group(function () {
        Route::get('/data', [SalesController::class, 'reservedStockData'])->name('data');
        Route::get('/create', [SalesController::class, 'reservedStock'])->name('create');
        Route::post('/', fn () => abort(422, 'Manual reservation creation is Coming Soon. Confirm a sales order to reserve stock from the existing source workflow.'))->name('store');
        Route::get('/export', [SalesController::class, 'reservedStockExport'])->name('export');
        Route::get('/availability', [SalesController::class, 'reservedStockAvailability'])->name('availability');
        Route::get('/print', [SalesController::class, 'reservedStockPrint'])->name('print-list');
        Route::get('/{reservation}', [SalesController::class, 'reservedStock'])->name('show');
        Route::get('/{reservation}/edit', [SalesController::class, 'reservedStock'])->name('edit');
        Route::patch('/{reservation}', fn () => abort(422, 'Direct reservation editing is Coming Soon. Update the source sales order before dispatch.'))->name('update');
        Route::post('/{reservation}/confirm', fn () => response()->json(['message' => 'Reservation confirmation is handled by sales order approval.']))->name('confirm');
        Route::post('/{reservation}/release', [SalesController::class, 'reservedStockRelease'])->name('release');
        Route::post('/{reservation}/extend', [SalesController::class, 'reservedStockExtend'])->name('extend');
        Route::post('/{reservation}/cancel', [SalesController::class, 'reservedStockCancel'])->name('cancel');
        Route::post('/{reservation}/dispatch', [SalesController::class, 'reservedStockDispatch'])->name('dispatch');
        Route::get('/{reservation}/print', [SalesController::class, 'reservedStockPrint'])->name('print');
    });

    Route::prefix('app')->name('app.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.clean');
        Route::post('/context', [AppController::class, 'switchContext'])->name('context.switch');
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers');
        Route::get('/purchases', [PurchaseController::class, 'bills'])->name('purchases');
        Route::get('/inventory/stock', [InventoryController::class, 'currentStock'])->name('inventory.stock');
        Route::get('/reports', [ReportsController::class, 'business'])->name('reports');
        Route::get('/settings', [SetupController::class, 'settings'])->name('settings');
        Route::get('/logout', [UserController::class, 'logout'])->name('logout');

        Route::prefix('crm')->name('crm.')->group(function () {
            Route::get('/', [CrmController::class, 'index'])->name('index');
            Route::get('/references', [CrmController::class, 'references'])->name('references');
            Route::get('/dashboard', [CrmController::class, 'dashboard'])->name('dashboard');
            Route::get('/leads', [CrmController::class, 'leads'])->name('leads.index');
            Route::get('/leads/list', [CrmController::class, 'leads'])->name('leads.list');
            Route::get('/leads/{lead}', [CrmController::class, 'showLead'])->name('leads.show');
            Route::get('/leads/{lead}/edit', [CrmController::class, 'showLead'])->name('leads.edit');
            Route::post('/leads', [CrmController::class, 'saveLead'])->name('leads.store');
            Route::put('/leads/{lead}', [CrmController::class, 'saveLead'])->name('leads.update');
            Route::patch('/leads/{lead}', [CrmController::class, 'saveLead']);
            Route::delete('/leads/{lead}', [CrmController::class, 'destroyLead'])->name('leads.destroy');
            Route::post('/leads/{lead}/assign', [CrmController::class, 'assignLead'])->name('leads.assign');
            Route::post('/leads/bulk-assign', [CrmController::class, 'bulkAssign'])->name('leads.bulk-assign');
            Route::post('/leads/{lead}/qualify', [CrmController::class, 'qualifyLead'])->name('leads.qualify');
            Route::post('/leads/{lead}/convert', [CrmController::class, 'convertLead'])->name('leads.convert');
            Route::get('/opportunities', [CrmController::class, 'opportunities'])->name('opportunities.index');
            Route::get('/opportunities/list', [CrmController::class, 'opportunities'])->name('opportunities.list');
            Route::get('/opportunities/{opportunity}', [CrmController::class, 'showOpportunity'])->name('opportunities.show');
            Route::get('/opportunities/{opportunity}/edit', [CrmController::class, 'showOpportunity'])->name('opportunities.edit');
            Route::post('/opportunities', [CrmController::class, 'saveOpportunity'])->name('opportunities.store');
            Route::put('/opportunities/{opportunity}', [CrmController::class, 'saveOpportunity'])->name('opportunities.update');
            Route::patch('/opportunities/{opportunity}', [CrmController::class, 'saveOpportunity']);
            Route::delete('/opportunities/{opportunity}', [CrmController::class, 'destroyOpportunity'])->name('opportunities.destroy');
            Route::post('/opportunities/{opportunity}/move', [CrmController::class, 'moveOpportunity'])->name('opportunities.move');
            Route::post('/opportunities/{opportunity}/stage', [CrmController::class, 'moveOpportunity'])->name('opportunities.update-stage');
            Route::post('/opportunities/{opportunity}/mark-won', [CrmController::class, 'markOpportunityWon'])->name('opportunities.mark-won');
            Route::post('/opportunities/{opportunity}/mark-lost', [CrmController::class, 'markOpportunityLost'])->name('opportunities.mark-lost');
            Route::post('/opportunities/{opportunity}/quotation', [CrmController::class, 'opportunityQuotation'])->name('opportunities.quotation');
            Route::post('/opportunities/{opportunity}/create-quotation', [CrmController::class, 'opportunityQuotation'])->name('opportunities.create-quotation');
            Route::get('/activities', [CrmController::class, 'activities'])->name('activities.index');
            Route::get('/activities/list', [CrmController::class, 'activities'])->name('activities.list');
            Route::get('/activities/{activity}', [CrmController::class, 'showActivity'])->name('activities.show');
            Route::post('/activities', [CrmController::class, 'saveActivity'])->name('activities.store');
            Route::put('/activities/{activity}', [CrmController::class, 'saveActivity'])->name('activities.update');
            Route::patch('/activities/{activity}', [CrmController::class, 'saveActivity']);
            Route::post('/activities/{activity}/complete', [CrmController::class, 'completeActivity'])->name('activities.complete');
            Route::post('/activities/{activity}/cancel', [CrmController::class, 'cancelActivity'])->name('activities.cancel');
            Route::delete('/activities/{activity}', [CrmController::class, 'destroyActivity'])->name('activities.destroy');
            Route::get('/kanban', [CrmController::class, 'kanban'])->name('kanban');
            Route::get('/calendar', [CrmController::class, 'calendar'])->name('calendar');
            Route::get('/calendar/index', [CrmController::class, 'calendar'])->name('calendar.index');
            Route::get('/reports', [CrmController::class, 'reports'])->name('reports');
            Route::get('/reports/leads', [CrmController::class, 'leadReport'])->name('reports.leads');
            Route::get('/reports/opportunities', [CrmController::class, 'opportunityReport'])->name('reports.opportunities');
            Route::get('/reports/follow-ups', [CrmController::class, 'followUpReport'])->name('reports.follow-ups');
            Route::get('/reports/sales-forecast', [CrmController::class, 'salesForecastReport'])->name('reports.sales-forecast');
            Route::get('/exports/leads', [CrmController::class, 'exportLeads'])->name('exports.leads');
            Route::get('/exports/opportunities', [CrmController::class, 'exportOpportunities'])->name('exports.opportunities');
            Route::get('/exports/follow-ups', [CrmController::class, 'exportFollowUps'])->name('exports.follow-ups');
            Route::get('/exports/sales-forecast', [CrmController::class, 'exportSalesForecast'])->name('exports.sales-forecast');
            Route::post('/masters/{type}', [CrmController::class, 'saveMaster'])->name('masters.store');
            Route::put('/masters/{type}/{id}', [CrmController::class, 'saveMaster'])->name('masters.update');
            Route::post('/sources', [CrmController::class, 'saveSource'])->name('sources.store');
            Route::put('/sources/{id}', [CrmController::class, 'saveSource'])->name('sources.update');
            Route::delete('/sources/{id}', [CrmController::class, 'destroySource'])->name('sources.destroy');
            Route::post('/campaigns', [CrmController::class, 'saveCampaign'])->name('campaigns.store');
            Route::put('/campaigns/{id}', [CrmController::class, 'saveCampaign'])->name('campaigns.update');
            Route::delete('/campaigns/{id}', [CrmController::class, 'destroyCampaign'])->name('campaigns.destroy');
            Route::get('/pipelines', [CrmController::class, 'pipelines'])->name('pipelines.index');
            Route::get('/stages', [CrmController::class, 'stages'])->name('stages.index');
        });

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('/workspace', [AdminController::class, 'workspace'])->name('workspace');
            Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');
        });
        Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');

        Route::prefix('staff')->name('staff.')->group(function () {
            Route::get('/workspace', [StaffController::class, 'workspace'])->name('workspace');
            Route::get('/tasks', [StaffController::class, 'tasks'])->name('tasks');
            Route::get('/attendance', [StaffController::class, 'attendance'])->name('attendance');
        });

        Route::prefix('sales')->name('sales.')->group(function () {
            Route::get('/pos', [PosController::class, 'index'])->name('pos');
            Route::get('/invoices', [SalesController::class, 'invoices'])->name('invoices');
            Route::get('/invoices/create', [SalesController::class, 'invoices'])->name('invoices.create');
            Route::get('/invoices/list', [SalesController::class, 'list'])->name('invoices.list');
            Route::get('/invoices/references', [SalesController::class, 'references'])->name('invoices.references');
            Route::get('/invoices/products/search', [SalesController::class, 'searchProducts'])->name('invoices.products.search');
            Route::get('/invoices/products/scan', [SalesController::class, 'scanProduct'])->name('invoices.products.scan');
            Route::get('/invoices/products/last-purchase', [SalesController::class, 'productLastPurchase'])->name('invoices.products.last-purchase');
            Route::get('/invoices/reports', [SalesController::class, 'reports'])->name('invoices.reports');
            Route::get('/invoices/export', [SalesController::class, 'export'])->name('invoices.export');
            Route::post('/invoices', [SalesController::class, 'store'])->name('invoices.store');
            Route::put('/invoices/{sale}', [SalesController::class, 'update'])->name('invoices.update');
            Route::patch('/invoices/{sale}', [SalesController::class, 'update']);
            Route::get('/invoices/{sale}', [SalesController::class, 'show'])->name('invoices.show');
            Route::get('/invoices/{sale}/edit', [SalesController::class, 'show'])->name('invoices.edit');
            Route::post('/invoices/{sale}/post', [SalesController::class, 'approve'])->name('invoices.post');
            Route::post('/invoices/{sale}/hold', [SalesController::class, 'hold'])->name('invoices.hold');
            Route::post('/invoices/{sale}/duplicate', [SalesController::class, 'duplicate'])->name('invoices.duplicate');
            Route::post('/invoices/{sale}/approve', [SalesController::class, 'approve'])->name('invoices.approve');
            Route::post('/invoices/{sale}/cancel', [SalesController::class, 'cancel'])->name('invoices.cancel');
            Route::post('/invoices/{sale}/reverse', [SalesController::class, 'reverse'])->name('invoices.reverse');
            Route::get('/invoices/{sale}/print', [SalesController::class, 'print'])->name('invoices.print');
            Route::get('/invoices/{sale}/pdf', [SalesController::class, 'pdf'])->name('invoices.pdf');
            Route::post('/invoices/{sale}/whatsapp', [SalesController::class, 'whatsappShare'])->name('invoices.whatsapp');
            Route::post('/invoices/{sale}/payments', [SalesController::class, 'addPayment'])->name('invoices.payments.store');
            Route::get('/returns', [SalesReturnController::class, 'index'])->name('returns');
            Route::get('/returns/create', [SalesReturnController::class, 'create'])->name('returns.create');
            Route::get('/returns/list', [SalesReturnController::class, 'list'])->name('returns.list');
            Route::get('/returns/references', [SalesReturnController::class, 'references'])->name('returns.references');
            Route::get('/returns/invoice-search', [SalesReturnController::class, 'searchSales'])->name('returns.invoice-search');
            Route::get('/returns/invoices/search', [SalesReturnController::class, 'searchSales'])->name('returns.invoices.search');
            Route::get('/returns/invoices/{sale}/eligible-items', [SalesReturnController::class, 'saleItems'])->name('returns.eligible-items');
            Route::get('/returns/invoices/{sale}/items', [SalesReturnController::class, 'saleItems'])->name('returns.invoices.items');
            Route::get('/returns/export', [SalesReturnController::class, 'export'])->name('returns.export');
            Route::post('/returns', [SalesReturnController::class, 'store'])->name('returns.store');
            Route::put('/returns/{return}', [SalesReturnController::class, 'update'])->name('returns.update');
            Route::patch('/returns/{return}', [SalesReturnController::class, 'update'])->name('returns.patch');
            Route::get('/returns/{return}', [SalesReturnController::class, 'show'])->name('returns.show');
            Route::get('/returns/{return}/edit', [SalesReturnController::class, 'edit'])->name('returns.edit');
            Route::post('/returns/{return}/approve', [SalesReturnController::class, 'approve'])->name('returns.approve');
            Route::post('/returns/{return}/cancel', [SalesReturnController::class, 'cancel'])->name('returns.cancel');
            Route::post('/returns/{return}/reverse', [SalesReturnController::class, 'reverse'])->name('returns.reverse');
            Route::get('/returns/{return}/print', [SalesReturnController::class, 'print'])->name('returns.print');
            Route::get('/returns/{return}/pdf', [SalesReturnController::class, 'print'])->name('returns.pdf');
            Route::post('/returns/{return}/refunds', [SalesReturnController::class, 'storeRefund'])->name('returns.refunds.store');
            Route::get('/customers', [CustomerController::class, 'index'])->name('customers');
            Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
            Route::get('/customers/list', [CustomerController::class, 'list'])->name('customers.list');
            Route::get('/customers/references', [CustomerController::class, 'references'])->name('customers.references');
            Route::get('/customers/search', [CustomerController::class, 'search'])->name('customers.search');
            Route::get('/customers/lookup', [CustomerController::class, 'lookupByMobile'])->name('customers.lookup');
            Route::post('/customers/quick-create', [CustomerController::class, 'quickCreate'])->name('customers.quick-create');
            Route::get('/customers/export', [CustomerController::class, 'export'])->name('customers.export');
            Route::post('/customers/import', [CustomerController::class, 'import'])->name('customers.import');
            Route::get('/customers/import-template', [CustomerController::class, 'importTemplate'])->name('customers.import-template');
            Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.patch');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::get('/customers/{customer}/insight', [CustomerController::class, 'insight'])->name('customers.insight');
            Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
            Route::post('/customers/{customer}/activate', [CustomerController::class, 'activate'])->name('customers.activate');
            Route::post('/customers/{customer}/deactivate', [CustomerController::class, 'deactivate'])->name('customers.deactivate');
            Route::get('/customers/{customer}/ledger', [CustomerController::class, 'ledger'])->name('customers.ledger');
            Route::get('/customers/{customer}/statement', [CustomerController::class, 'statement'])->name('customers.statement');
            Route::get('/customers/{customer}/sales', [CustomerController::class, 'sales'])->name('customers.sales');
            Route::get('/customers/{customer}/outstanding', [CustomerController::class, 'outstanding'])->name('customers.outstanding');
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
            Route::post('/customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
            Route::get('/orders', [OrderManagementController::class, 'salesPage'])->name('orders');
            Route::get('/order-management/references', [OrderManagementController::class, 'references'])->name('orders.references');
            Route::get('/order-management/products/search', [OrderManagementController::class, 'searchProducts'])->name('orders.products.search');
            Route::get('/order-management/dashboard', [OrderManagementController::class, 'dashboard'])->name('orders.dashboard');
            Route::get('/order-management/reports', [OrderManagementController::class, 'reports'])->name('orders.reports');
            Route::get('/quotations/list', [OrderManagementController::class, 'quotations'])->name('quotations.list');
            Route::post('/quotations', [OrderManagementController::class, 'saveQuotation'])->name('quotations.store');
            Route::put('/quotations/{quotation}', [OrderManagementController::class, 'saveQuotation'])->name('quotations.update');
            Route::post('/quotations/{quotation}/whatsapp', [OrderManagementController::class, 'shareQuotationWhatsApp'])->name('quotations.whatsapp');
            Route::post('/quotations/{quotation}/convert', [OrderManagementController::class, 'convertQuotation'])->name('quotations.convert');
            Route::get('/orders/list', [OrderManagementController::class, 'salesOrders'])->name('orders.list');
            Route::post('/orders', [OrderManagementController::class, 'saveSalesOrder'])->name('orders.store');
            Route::put('/orders/{order}', [OrderManagementController::class, 'saveSalesOrder'])->name('orders.update');
            Route::post('/orders/{order}/approve', [OrderManagementController::class, 'approveSalesOrder'])->name('orders.approve');
            Route::post('/orders/{order}/release-reservation', [OrderManagementController::class, 'releaseSalesOrderReservation'])->name('orders.release-reservation');
            Route::post('/orders/{order}/delivery-challan', [OrderManagementController::class, 'createDeliveryFromOrder'])->name('orders.delivery-challan');
            Route::get('/delivery-challans/list', [OrderManagementController::class, 'deliveryChallans'])->name('delivery-challans.list');
            Route::post('/delivery-challans', [OrderManagementController::class, 'saveDeliveryChallan'])->name('delivery-challans.store');
            Route::put('/delivery-challans/{challan}', [OrderManagementController::class, 'saveDeliveryChallan'])->name('delivery-challans.update');
            Route::post('/delivery-challans/{challan}/dispatch', [OrderManagementController::class, 'dispatchChallan'])->name('delivery-challans.dispatch');
            Route::get('/stock-outward', [SalesController::class, 'stockOutward'])->name('stock-outward');
            Route::get('/reserved-stock', [SalesController::class, 'reservedStock'])->name('reserved-stock');
        });

        Route::prefix('purchase')->name('purchase.')->group(function () {
            Route::get('/bills', [PurchaseController::class, 'bills'])->name('bills');
            Route::get('/bills/list', [PurchaseController::class, 'list'])->name('bills.list');
            Route::get('/bills/references', [PurchaseController::class, 'references'])->name('bills.references');
            Route::get('/bills/products/search', [PurchaseController::class, 'searchProducts'])->name('bills.products.search');
            Route::post('/bills', [PurchaseController::class, 'store'])->name('bills.store');
            Route::put('/bills/{purchase}', [PurchaseController::class, 'update'])->name('bills.update');
            Route::post('/bills/{purchase}/duplicate', [PurchaseController::class, 'duplicate'])->name('bills.duplicate');
            Route::post('/bills/{purchase}/approve', [PurchaseController::class, 'approve'])->name('bills.approve');
            Route::post('/bills/{purchase}/cancel', [PurchaseController::class, 'cancel'])->name('bills.cancel');
            Route::post('/bills/{purchase}/reverse', [PurchaseController::class, 'reverse'])->name('bills.reverse');

            Route::get('/returns', [PurchaseReturnController::class, 'index'])->name('returns');
            Route::get('/returns/list', [PurchaseReturnController::class, 'list'])->name('returns.list');
            Route::get('/returns/references', [PurchaseReturnController::class, 'references'])->name('returns.references');
            Route::get('/returns/products/search', [PurchaseReturnController::class, 'searchProducts'])->name('returns.products.search');
            Route::get('/returns/purchases/search', [PurchaseReturnController::class, 'searchPurchases'])->name('returns.purchases.search');
            Route::get('/returns/purchases/{purchase}/items', [PurchaseReturnController::class, 'purchaseItems'])->name('returns.purchases.items');
            Route::post('/returns', [PurchaseReturnController::class, 'store'])->name('returns.store');
            Route::put('/returns/{return}', [PurchaseReturnController::class, 'update'])->name('returns.update');
            Route::post('/returns/{return}/approve', [PurchaseReturnController::class, 'approve'])->name('returns.approve');
            Route::post('/returns/{return}/cancel', [PurchaseReturnController::class, 'cancel'])->name('returns.cancel');
            Route::post('/returns/{return}/reverse', [PurchaseReturnController::class, 'reverse'])->name('returns.reverse');

            Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers');
            Route::get('/suppliers/list', [SupplierController::class, 'list'])->name('suppliers.list');
            Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
            Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
            Route::post('/suppliers/{supplier}/restore', [SupplierController::class, 'restore'])->name('suppliers.restore');
            Route::get('/grn', [PurchaseController::class, 'grn'])->name('grn');
            Route::get('/reorder', [PurchaseController::class, 'reorder'])->name('reorder');
            Route::get('/orders', [OrderManagementController::class, 'purchasePage'])->name('orders');
            Route::get('/order-management/references', [OrderManagementController::class, 'references'])->name('orders.references');
            Route::get('/order-management/products/search', [OrderManagementController::class, 'searchProducts'])->name('orders.products.search');
            Route::get('/order-management/dashboard', [OrderManagementController::class, 'dashboard'])->name('orders.dashboard');
            Route::get('/order-management/reports', [OrderManagementController::class, 'reports'])->name('orders.reports');
            Route::get('/requisitions/list', [OrderManagementController::class, 'requisitions'])->name('requisitions.list');
            Route::post('/requisitions', [OrderManagementController::class, 'saveRequisition'])->name('requisitions.store');
            Route::put('/requisitions/{requisition}', [OrderManagementController::class, 'saveRequisition'])->name('requisitions.update');
            Route::get('/purchase-orders/list', [OrderManagementController::class, 'purchaseOrders'])->name('purchase-orders.list');
            Route::post('/purchase-orders', [OrderManagementController::class, 'savePurchaseOrder'])->name('purchase-orders.store');
            Route::put('/purchase-orders/{order}', [OrderManagementController::class, 'savePurchaseOrder'])->name('purchase-orders.update');
            Route::post('/purchase-orders/{order}/confirm', [OrderManagementController::class, 'confirmPurchaseOrder'])->name('purchase-orders.confirm');
            Route::get('/goods-receipts/list', [OrderManagementController::class, 'goodsReceipts'])->name('goods-receipts.list');
            Route::post('/goods-receipts', [OrderManagementController::class, 'saveGoodsReceipt'])->name('goods-receipts.store');
            Route::put('/goods-receipts/{receipt}', [OrderManagementController::class, 'saveGoodsReceipt'])->name('goods-receipts.update');
            Route::post('/goods-receipts/{receipt}/receive', [OrderManagementController::class, 'receiveGoods'])->name('goods-receipts.receive');
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

        Route::get(
            '/products/references',
            [ProductController::class, 'references']
        )->name('products.references');

        Route::post(
            '/products/save',
            [ProductController::class, 'save']
        )->name('products.save');

        Route::post(
            '/products',
            [ProductController::class, 'store']
        )->name('products.store');

        Route::patch(
            '/products/bulk-status',
            [ProductController::class, 'bulkStatus']
        )->name('products.bulk-status');

        Route::post(
            '/products/{product}/restore',
            [ProductController::class, 'restore']
        )->name('products.restore');

        Route::post(
            '/products/{product}/duplicate',
            [ProductController::class, 'duplicate']
        )->name('products.duplicate');

        Route::delete(
            '/products/{product}/force',
            [ProductController::class, 'forceDelete']
        )->name('products.force-delete');

        Route::get(
            '/products/{product}',
            [ProductController::class, 'show']
        )->name('products.show');

        Route::put(
            '/products/{product}',
            [ProductController::class, 'update']
        )->name('products.update');

        Route::patch(
            '/products/{product}',
            [ProductController::class, 'update']
        );

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
            [OpeningStockController::class, 'index']
        )->name('opening-stock');

        Route::get(
            '/opening-stock/list',
            [OpeningStockController::class, 'list']
        )->name('opening-stock.list');

        Route::get(
            '/opening-stock/references',
            [OpeningStockController::class, 'references']
        )->name('opening-stock.references');

        Route::get(
            '/opening-stock/products/search',
            [OpeningStockController::class, 'searchProducts']
        )->name('opening-stock.products.search');

        Route::post(
            '/opening-stock',
            [OpeningStockController::class, 'store']
        )->name('opening-stock.store');

        Route::put(
            '/opening-stock/{voucher}',
            [OpeningStockController::class, 'update']
        )->name('opening-stock.update');

        Route::delete(
            '/opening-stock/{voucher}',
            [OpeningStockController::class, 'destroy']
        )->name('opening-stock.destroy');

        Route::post(
            '/opening-stock/{voucher}/approve',
            [OpeningStockController::class, 'approve']
        )->name('opening-stock.approve');

        Route::post(
            '/opening-stock/{voucher}/reverse',
            [OpeningStockController::class, 'reverse']
        )->name('opening-stock.reverse');

        Route::get(
            '/current-stock',
            [StockSummaryController::class, 'index']
        )->name('current-stock');

        Route::get(
            '/current-stock/list',
            [StockSummaryController::class, 'list']
        )->name('current-stock.list');

        Route::get(
            '/current-stock/references',
            [StockSummaryController::class, 'references']
        )->name('current-stock.references');

        Route::get(
            '/current-stock/products/{product}',
            [StockSummaryController::class, 'show']
        )->name('current-stock.products.show');

        Route::get(
            '/vouchers',
            [InventoryController::class, 'vouchers']
        )->name('vouchers');

        Route::get('/control/references', [InventoryController::class, 'references'])->name('control.references');
        Route::get('/control/products/search', [InventoryController::class, 'products'])->name('control.products.search');
        Route::get('/control/dashboard', [InventoryController::class, 'dashboardData'])->name('control.dashboard');
        Route::get('/control/reports', [InventoryController::class, 'inventoryReports'])->name('control.reports');
        Route::get('/control/valuation', [InventoryController::class, 'valuation'])->name('control.valuation');
        Route::get('/adjustment-reasons/list', [InventoryController::class, 'reasons'])->name('adjustment-reasons.list');
        Route::post('/adjustment-reasons', [InventoryController::class, 'saveReason'])->name('adjustment-reasons.store');
        Route::put('/adjustment-reasons/{reason}', [InventoryController::class, 'saveReason'])->name('adjustment-reasons.update');
        Route::delete('/adjustment-reasons/{reason}', [InventoryController::class, 'deleteReason'])->name('adjustment-reasons.delete');
        Route::get('/stock-adjustments/list', [InventoryController::class, 'adjustments'])->name('stock-adjustments.list');
        Route::post('/stock-adjustments', [InventoryController::class, 'saveAdjustment'])->name('stock-adjustments.store');
        Route::put('/stock-adjustments/{adjustment}', [InventoryController::class, 'saveAdjustment'])->name('stock-adjustments.update');
        Route::post('/stock-adjustments/{adjustment}/post', [InventoryController::class, 'postAdjustment'])->name('stock-adjustments.post');
        Route::post('/stock-adjustments/{adjustment}/reverse', [InventoryController::class, 'reverseAdjustment'])->name('stock-adjustments.reverse');
        Route::get('/stock-counts/list', [InventoryController::class, 'countSessions'])->name('stock-counts.list');
        Route::post('/stock-counts', [InventoryController::class, 'saveCountSession'])->name('stock-counts.store');
        Route::put('/stock-counts/{session}', [InventoryController::class, 'saveCountSession'])->name('stock-counts.update');
        Route::post('/stock-counts/{session}/scan', [InventoryController::class, 'scanCountLine'])->name('stock-counts.scan');
        Route::post('/stock-counts/{session}/post-variance', [InventoryController::class, 'postCountVariance'])->name('stock-counts.post-variance');
        Route::get('/stock-transfers/list', [InventoryController::class, 'transfers'])->name('stock-transfers.list');
        Route::post('/stock-transfers', [InventoryController::class, 'saveTransfer'])->name('stock-transfers.store');
        Route::put('/stock-transfers/{transfer}', [InventoryController::class, 'saveTransfer'])->name('stock-transfers.update');
        Route::post('/stock-transfers/{transfer}/dispatch', [InventoryController::class, 'dispatchTransfer'])->name('stock-transfers.dispatch');
        Route::post('/stock-transfers/{transfer}/receive', [InventoryController::class, 'receiveTransfer'])->name('stock-transfers.receive');
        Route::get('/location-transfers/list', [InventoryController::class, 'locationTransfers'])->name('location-transfers.list');
        Route::post('/location-transfers', [InventoryController::class, 'saveLocationTransfer'])->name('location-transfers.store');
        Route::put('/location-transfers/{movement}', [InventoryController::class, 'saveLocationTransfer'])->name('location-transfers.update');
        Route::get('/warehouse-locations/list', [InventoryController::class, 'warehouseLocations'])->name('warehouse-locations.list');
        Route::post('/warehouse-locations', [InventoryController::class, 'saveWarehouseLocation'])->name('warehouse-locations.store');
        Route::put('/warehouse-locations/{location}', [InventoryController::class, 'saveWarehouseLocation'])->name('warehouse-locations.update');

        Route::get(
            '/batches',
            [InventoryController::class, 'batches']
        )->name('batches');

        Route::get('/batches/references', [InventoryController::class, 'batchReferences'])->name('batches.references');
        Route::get('/batches/list', [InventoryController::class, 'batchList'])->name('batches.list');
        Route::get('/batches/reports', [InventoryController::class, 'batchReports'])->name('batches.reports');
        Route::get('/batches/fefo', [InventoryController::class, 'batchFefo'])->name('batches.fefo');
        Route::get('/batches/{batch}', [InventoryController::class, 'batchShow'])->name('batches.show');
        Route::get('/batches/{batch}/ledger', [InventoryController::class, 'batchLedger'])->name('batches.ledger');
        Route::post('/batches/{batch}/status', [InventoryController::class, 'batchStatus'])->name('batches.status');
        Route::post('/batches/{batch}/transfer', [InventoryController::class, 'batchTransfer'])->name('batches.transfer');
        Route::post('/batches/{batch}/split', [InventoryController::class, 'batchSplit'])->name('batches.split');
        Route::post('/batches/{batch}/merge', [InventoryController::class, 'batchMerge'])->name('batches.merge');

        Route::get(
            '/serials',
            [InventoryController::class, 'serials']
        )->name('serials');
        Route::get('/serials/references', [InventoryController::class, 'serialReferences'])->name('serials.references');
        Route::get('/serials/list', [InventoryController::class, 'serialList'])->name('serials.list');
        Route::get('/serials/reports', [InventoryController::class, 'serialReports'])->name('serials.reports');
        Route::get('/serials/import-sample', [InventoryController::class, 'serialSample'])->name('serials.sample');
        Route::post('/serials', [InventoryController::class, 'serialStore'])->name('serials.store');
        Route::post('/serials/bulk', [InventoryController::class, 'serialBulk'])->name('serials.bulk');
        Route::get('/serials/{serial}', [InventoryController::class, 'serialShow'])->name('serials.show');
        Route::put('/serials/{serial}', [InventoryController::class, 'serialUpdate'])->name('serials.update');
        Route::post('/serials/{serial}/status', [InventoryController::class, 'serialStatus'])->name('serials.status');
        Route::post('/serials/{serial}/transfer', [InventoryController::class, 'serialTransfer'])->name('serials.transfer');
        Route::delete('/serials/{serial}', [InventoryController::class, 'serialDestroy'])->name('serials.destroy');

        Route::get(
            '/barcode-center',
            [InventoryController::class, 'barcodeCenter']
        )->name('barcode-center');
        Route::get('/barcode-center/references', [InventoryController::class, 'barcodeReferences'])->name('barcode-center.references');
        Route::get('/barcode-center/list', [InventoryController::class, 'barcodeList'])->name('barcode-center.list');
        Route::get('/barcode-center/reports', [InventoryController::class, 'barcodeReports'])->name('barcode-center.reports');
        Route::get('/barcode-center/import-sample', [InventoryController::class, 'barcodeSample'])->name('barcode-center.sample');
        Route::post('/barcode-center/assign', [InventoryController::class, 'barcodeAssign'])->name('barcode-center.assign');
        Route::post('/barcode-center/generate', [InventoryController::class, 'barcodeGenerate'])->name('barcode-center.generate');
        Route::post('/barcode-center/bulk-generate', [InventoryController::class, 'barcodeBulkGenerate'])->name('barcode-center.bulk-generate');
        Route::post('/barcode-center/scan', [InventoryController::class, 'barcodeScan'])->name('barcode-center.scan');
        Route::post('/barcode-center/print', [InventoryController::class, 'barcodePrint'])->name('barcode-center.print');
        Route::post('/barcode-center/{barcode}/primary', [InventoryController::class, 'barcodePrimary'])->name('barcode-center.primary');
        Route::post('/barcode-center/{barcode}/toggle', [InventoryController::class, 'barcodeToggle'])->name('barcode-center.toggle');

        Route::get(
            '/manufacturing',
            [InventoryController::class, 'manufacturing']
        )->name('manufacturing');
        Route::get('/manufacturing/references', [InventoryController::class, 'manufacturingReferences'])->name('manufacturing.references');
        Route::get('/manufacturing/dashboard', [InventoryController::class, 'manufacturingDashboard'])->name('manufacturing.dashboard');
        Route::get('/manufacturing/boms/list', [InventoryController::class, 'bomList'])->name('manufacturing.boms.list');
        Route::post('/manufacturing/boms', [InventoryController::class, 'bomStore'])->name('manufacturing.boms.store');
        Route::put('/manufacturing/boms/{bom}', [InventoryController::class, 'bomUpdate'])->name('manufacturing.boms.update');
        Route::post('/manufacturing/boms/{bom}/duplicate', [InventoryController::class, 'bomDuplicate'])->name('manufacturing.boms.duplicate');
        Route::post('/manufacturing/boms/{bom}/activate', [InventoryController::class, 'bomActivate'])->name('manufacturing.boms.activate');
        Route::get('/manufacturing/orders/list', [InventoryController::class, 'productionOrderList'])->name('manufacturing.orders.list');
        Route::post('/manufacturing/orders', [InventoryController::class, 'productionOrderStore'])->name('manufacturing.orders.store');
        Route::put('/manufacturing/orders/{order}', [InventoryController::class, 'productionOrderUpdate'])->name('manufacturing.orders.update');
        Route::get('/manufacturing/orders/{order}/materials', [InventoryController::class, 'productionCheck'])->name('manufacturing.orders.materials');
        Route::post('/manufacturing/orders/{order}/transition', [InventoryController::class, 'productionTransition'])->name('manufacturing.orders.transition');
        Route::post('/manufacturing/orders/{order}/complete', [InventoryController::class, 'productionComplete'])->name('manufacturing.orders.complete');
        Route::get('/manufacturing/reports', [InventoryController::class, 'manufacturingReports'])->name('manufacturing.reports');
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
            Route::get('/references', [AccountingController::class, 'references'])->name('references');
            Route::get('/accounts/list', [AccountingController::class, 'accounts'])->name('accounts.list');
            Route::post('/accounts', [AccountingController::class, 'saveAccount'])->name('accounts.store');
            Route::put('/accounts/{account}', [AccountingController::class, 'saveAccount'])->name('accounts.update');
            Route::post('/settings', [AccountingController::class, 'settings'])->name('settings');
            Route::get('/vouchers', [AccountingController::class, 'vouchers'])->name('vouchers');
            Route::get('/journals/list', [AccountingController::class, 'journalList'])->name('journals.list');
            Route::post('/journals', [AccountingController::class, 'journalStore'])->name('journals.store');
            Route::post('/journals/{journal}/approve', [AccountingController::class, 'journalApprove'])->name('journals.approve');
            Route::post('/journals/{journal}/reverse', [AccountingController::class, 'journalReverse'])->name('journals.reverse');
            Route::get('/receipts/list', [AccountingController::class, 'receipts'])->name('receipts.list');
            Route::post('/receipts', [AccountingController::class, 'receiptStore'])->name('receipts.store');
            Route::get('/payments/list', [AccountingController::class, 'payments'])->name('payments.list');
            Route::post('/payments', [AccountingController::class, 'paymentStore'])->name('payments.store');
            Route::post('/contra', [AccountingController::class, 'contraStore'])->name('contra.store');
            Route::get('/ledgers', [AccountingController::class, 'ledgers'])->name('ledgers');
            Route::get('/ledger', [AccountingController::class, 'ledger'])->name('ledger');
            Route::get('/cash-bank-book', [AccountingController::class, 'cashBankBook'])->name('cash-bank-book');
            Route::get('/customer-outstanding', [AccountingController::class, 'customerOutstanding'])->name('customer-outstanding');
            Route::get('/supplier-outstanding', [AccountingController::class, 'supplierOutstanding'])->name('supplier-outstanding');
            Route::get('/expenses', [AccountingController::class, 'expenses'])->name('expenses');
            Route::get('/expenses/references', [AccountingController::class, 'expenseReferences'])->name('expenses.references');
            Route::get('/expense-categories/list', [AccountingController::class, 'expenseCategoryList'])->name('expense-categories.list');
            Route::post('/expense-categories', [AccountingController::class, 'saveExpenseCategory'])->name('expense-categories.store');
            Route::put('/expense-categories/{category}', [AccountingController::class, 'saveExpenseCategory'])->name('expense-categories.update');
            Route::delete('/expense-categories/{category}', [AccountingController::class, 'deleteExpenseCategory'])->name('expense-categories.delete');
            Route::get('/income-categories/list', [AccountingController::class, 'incomeCategoryList'])->name('income-categories.list');
            Route::post('/income-categories', [AccountingController::class, 'saveIncomeCategory'])->name('income-categories.store');
            Route::put('/income-categories/{category}', [AccountingController::class, 'saveIncomeCategory'])->name('income-categories.update');
            Route::delete('/income-categories/{category}', [AccountingController::class, 'deleteIncomeCategory'])->name('income-categories.delete');
            Route::get('/expense-vouchers/list', [AccountingController::class, 'expenseList'])->name('expense-vouchers.list');
            Route::post('/expense-vouchers', [AccountingController::class, 'expenseStore'])->name('expense-vouchers.store');
            Route::put('/expense-vouchers/{expense}', [AccountingController::class, 'expenseUpdate'])->name('expense-vouchers.update');
            Route::post('/expense-vouchers/{expense}/post', [AccountingController::class, 'expensePost'])->name('expense-vouchers.post');
            Route::post('/expense-vouchers/{expense}/reverse', [AccountingController::class, 'expenseReverse'])->name('expense-vouchers.reverse');
            Route::get('/other-income/list', [AccountingController::class, 'otherIncomeList'])->name('other-income.list');
            Route::post('/other-income', [AccountingController::class, 'otherIncomeStore'])->name('other-income.store');
            Route::put('/other-income/{income}', [AccountingController::class, 'otherIncomeUpdate'])->name('other-income.update');
            Route::post('/other-income/{income}/post', [AccountingController::class, 'otherIncomePost'])->name('other-income.post');
            Route::post('/other-income/{income}/reverse', [AccountingController::class, 'otherIncomeReverse'])->name('other-income.reverse');
            Route::get('/recurring-expenses/list', [AccountingController::class, 'recurringList'])->name('recurring-expenses.list');
            Route::post('/recurring-expenses', [AccountingController::class, 'recurringStore'])->name('recurring-expenses.store');
            Route::put('/recurring-expenses/{template}', [AccountingController::class, 'recurringUpdate'])->name('recurring-expenses.update');
            Route::get('/petty-cash/list', [AccountingController::class, 'pettyCashList'])->name('petty-cash.list');
            Route::post('/petty-cash', [AccountingController::class, 'pettyCashStore'])->name('petty-cash.store');
            Route::get('/bank-statement-imports/list', [AccountingController::class, 'bankImportList'])->name('bank-statement-imports.list');
            Route::post('/bank-statement-imports', [AccountingController::class, 'bankImportStore'])->name('bank-statement-imports.store');
            Route::get('/bank-statement-lines', [AccountingController::class, 'bankStatementLines'])->name('bank-statement-lines');
            Route::get('/bank-ledger-entries', [AccountingController::class, 'bankLedgerEntries'])->name('bank-ledger-entries');
            Route::get('/bank-reconciliations/list', [AccountingController::class, 'reconciliationList'])->name('bank-reconciliations.list');
            Route::post('/bank-reconciliations', [AccountingController::class, 'reconciliationStore'])->name('bank-reconciliations.store');
            Route::get('/expense-reports', [AccountingController::class, 'expenseReports'])->name('expense-reports');
            Route::get('/gst', [AccountingController::class, 'gst'])->name('gst');
            Route::get('/gst-returns', [AccountingController::class, 'gstReturns'])->name('gst-returns');
        });

        Route::prefix('fixed-assets')->name('fixed-assets.')->group(function () {
            Route::get('/', [FixedAssetController::class, 'index'])->name('index');
            Route::get('/references', [FixedAssetController::class, 'references'])->name('references');
            Route::get('/dashboard', [FixedAssetController::class, 'dashboard'])->name('dashboard');
            Route::post('/settings', [FixedAssetController::class, 'settings'])->name('settings');
            Route::get('/categories/list', [FixedAssetController::class, 'categories'])->name('categories.list');
            Route::post('/categories', [FixedAssetController::class, 'saveCategory'])->name('categories.store');
            Route::put('/categories/{category}', [FixedAssetController::class, 'saveCategory'])->name('categories.update');
            Route::get('/locations/list', [FixedAssetController::class, 'locations'])->name('locations.list');
            Route::post('/locations', [FixedAssetController::class, 'saveLocation'])->name('locations.store');
            Route::put('/locations/{location}', [FixedAssetController::class, 'saveLocation'])->name('locations.update');
            Route::get('/assets/list', [FixedAssetController::class, 'list'])->name('assets.list');
            Route::post('/assets', [FixedAssetController::class, 'save'])->name('assets.store');
            Route::put('/assets/{asset}', [FixedAssetController::class, 'save'])->name('assets.update');
            Route::get('/acquisitions/list', [FixedAssetController::class, 'acquisitions'])->name('acquisitions.list');
            Route::post('/acquisitions', [FixedAssetController::class, 'saveAcquisition'])->name('acquisitions.store');
            Route::put('/acquisitions/{acquisition}', [FixedAssetController::class, 'saveAcquisition'])->name('acquisitions.update');
            Route::post('/acquisitions/{acquisition}/post', [FixedAssetController::class, 'postAcquisition'])->name('acquisitions.post');
            Route::post('/capitalizations', [FixedAssetController::class, 'capitalize'])->name('capitalizations.store');
            Route::get('/depreciation-runs/list', [FixedAssetController::class, 'depreciationRuns'])->name('depreciation-runs.list');
            Route::post('/depreciation-runs', [FixedAssetController::class, 'depreciationRun'])->name('depreciation-runs.store');
            Route::post('/depreciation-runs/{run}/post', [FixedAssetController::class, 'postDepreciation'])->name('depreciation-runs.post');
            Route::post('/assignments', [FixedAssetController::class, 'assign'])->name('assignments.store');
            Route::post('/assignments/{assignment}/return', [FixedAssetController::class, 'returnAssignment'])->name('assignments.return');
            Route::post('/transfers', [FixedAssetController::class, 'transfer'])->name('transfers.store');
            Route::put('/transfers/{transfer}', [FixedAssetController::class, 'transfer'])->name('transfers.update');
            Route::post('/maintenance', [FixedAssetController::class, 'maintenance'])->name('maintenance.store');
            Route::put('/maintenance/{maintenance}', [FixedAssetController::class, 'maintenance'])->name('maintenance.update');
            Route::post('/simple/{type}', [FixedAssetController::class, 'simple'])->name('simple.store');
            Route::post('/revaluations', [FixedAssetController::class, 'revalue'])->name('revaluations.store');
            Route::post('/impairments', [FixedAssetController::class, 'impair'])->name('impairments.store');
            Route::post('/disposals', [FixedAssetController::class, 'dispose'])->name('disposals.store');
            Route::post('/verifications', [FixedAssetController::class, 'verification'])->name('verifications.store');
            Route::get('/reports', [FixedAssetController::class, 'reports'])->name('reports');
        });

        Route::prefix('payroll')->name('payroll.')->group(function () {
            Route::get('/', [PayrollController::class, 'index'])->name('index');
            Route::get('/references', [PayrollController::class, 'references'])->name('references');
            Route::get('/dashboard', [PayrollController::class, 'dashboard'])->name('dashboard');
            Route::post('/settings', [PayrollController::class, 'settings'])->name('settings');
            Route::get('/employees/list', [PayrollController::class, 'employees'])->name('employees.list');
            Route::post('/employees', [PayrollController::class, 'saveEmployee'])->name('employees.store');
            Route::put('/employees/{employee}', [PayrollController::class, 'saveEmployee'])->name('employees.update');
            Route::post('/masters/{type}', [PayrollController::class, 'saveMaster'])->name('masters.store');
            Route::put('/masters/{type}/{id}', [PayrollController::class, 'saveMaster'])->name('masters.update');
            Route::get('/attendance/list', [PayrollController::class, 'attendance'])->name('attendance.list');
            Route::post('/attendance', [PayrollController::class, 'saveAttendance'])->name('attendance.store');
            Route::put('/attendance/{attendance}', [PayrollController::class, 'saveAttendance'])->name('attendance.update');
            Route::post('/salary-structures', [PayrollController::class, 'saveSalaryStructure'])->name('salary-structures.store');
            Route::put('/salary-structures/{structure}', [PayrollController::class, 'saveSalaryStructure'])->name('salary-structures.update');
            Route::post('/salary-assignments', [PayrollController::class, 'assignSalary'])->name('salary-assignments.store');
            Route::get('/runs/list', [PayrollController::class, 'runs'])->name('runs.list');
            Route::post('/runs', [PayrollController::class, 'createRun'])->name('runs.store');
            Route::post('/runs/{run}/post', [PayrollController::class, 'postRun'])->name('runs.post');
            Route::post('/runs/{run}/payslips', [PayrollController::class, 'payslips'])->name('runs.payslips');
            Route::post('/payment-batches', [PayrollController::class, 'paymentBatch'])->name('payment-batches.store');
            Route::post('/advances', [PayrollController::class, 'advance'])->name('advances.store');
            Route::post('/loans', [PayrollController::class, 'loan'])->name('loans.store');
            Route::get('/reports', [PayrollController::class, 'reports'])->name('reports');
        });

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/business', [ReportsController::class, 'business'])->name('business');
            Route::get('/financial/references', [ReportsController::class, 'financialReferences'])->name('financial.references');
            Route::post('/financial/classifications', [ReportsController::class, 'saveClassification'])->name('financial.classifications');
            Route::get('/financial/dashboard', [ReportsController::class, 'financialDashboard'])->name('financial.dashboard');
            Route::get('/financial/day-book', [ReportsController::class, 'dayBook'])->name('financial.day-book');
            Route::get('/financial/journal-register', [ReportsController::class, 'journalRegister'])->name('financial.journal-register');
            Route::get('/financial/ledger', [ReportsController::class, 'ledger'])->name('financial.ledger');
            Route::get('/financial/trial-balance', [ReportsController::class, 'trialBalance'])->name('financial.trial-balance');
            Route::get('/financial/profit-and-loss', [ReportsController::class, 'profitAndLoss'])->name('financial.profit-and-loss');
            Route::get('/financial/balance-sheet', [ReportsController::class, 'balanceSheet'])->name('financial.balance-sheet');
            Route::get('/financial/cash-flow', [ReportsController::class, 'cashFlow'])->name('financial.cash-flow');
            Route::get('/financial/receivables', [ReportsController::class, 'receivables'])->name('financial.receivables');
            Route::get('/financial/payables', [ReportsController::class, 'payables'])->name('financial.payables');
            Route::get('/financial/comparative', [ReportsController::class, 'comparative'])->name('financial.comparative');
            Route::get('/financial/branch-financials', [ReportsController::class, 'branchFinancials'])->name('financial.branch-financials');
            Route::get('/financial/schedule', [ReportsController::class, 'schedule'])->name('financial.schedule');
            Route::get('/financial/ratios', [ReportsController::class, 'ratios'])->name('financial.ratios');
            Route::get('/financial/exceptions', [ReportsController::class, 'exceptions'])->name('financial.exceptions');
            Route::post('/financial/closing/checklist', [ReportsController::class, 'closingChecklist'])->name('financial.closing.checklist');
            Route::post('/financial/closing', [ReportsController::class, 'closeYear'])->name('financial.closing.store');
            Route::post('/financial/closing/{closure}/reopen', [ReportsController::class, 'reopenYear'])->name('financial.closing.reopen');
            Route::post('/financial/snapshots', [ReportsController::class, 'snapshot'])->name('financial.snapshots');
            Route::get('/inventory', [ReportsController::class, 'inventory'])->name('inventory');
            Route::get('/stock-ledger', [ReportsController::class, 'stockLedger'])->name('stock-ledger');
            Route::get('/stock-valuation', [ReportsController::class, 'stockValuation'])->name('stock-valuation');
            Route::get('/audit-trail', [ReportsController::class, 'auditTrail'])->name('audit-trail');
            Route::get('/acceptance', [ReportsController::class, 'acceptance'])->name('acceptance');
        });

        Route::prefix('setup')->name('setup.')->group(function () {
            Route::get('/masters', [MasterDataController::class, 'index'])->name('masters');
            Route::get('/masters/references', [MasterDataController::class, 'references'])->name('masters.references');
            Route::get('/masters/{type}/list', [MasterDataController::class, 'list'])->name('masters.list');
            Route::post('/masters/{type}', [MasterDataController::class, 'store'])->name('masters.store');
            Route::put('/masters/{type}/{id}', [MasterDataController::class, 'update'])->name('masters.update');
            Route::delete('/masters/{type}/{id}', [MasterDataController::class, 'destroy'])->name('masters.destroy');
            Route::get('/branches', [SetupController::class, 'branches'])->name('branches');
            Route::get('/employees', [SetupController::class, 'employees'])->name('employees');
            Route::get('/users', [SetupController::class, 'users'])->name('users');
            Route::get('/saas', [SetupController::class, 'saas'])->name('saas');
            Route::get('/acceptance', [ReportsController::class, 'setupAcceptance'])->name('acceptance');
            Route::get('/settings', [SetupController::class, 'settings'])->name('settings');
            Route::post('/settings/business-profile', [SetupController::class, 'updateBusinessProfile'])->name('settings.business-profile');
        });
    });


    Route::group(["prefix"=>"upload"], function(){
        Route::post('/photo',[GeneralController::class,'uploadPhoto']);
    });

    Route::post('/uploads/file', [GeneralController::class, 'uploadFile']);
});
