<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\SalesVoucherRequest;
use App\Models\Customer;
use App\Models\SalesVoucher;
use App\Services\CustomerAnalyticsService;
use App\Services\InvoiceDocumentRenderer;
use App\Services\ReservedStockService;
use App\Services\SalesService;
use App\Services\StockOutwardService;
use App\Services\WhatsAppShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class SalesController extends Controller
{
    private SalesService $sales;
    private StockOutwardService $stockOutward;
    private ReservedStockService $reservedStock;

    public function __construct(SalesService $sales, StockOutwardService $stockOutward, ReservedStockService $reservedStock)
    {
        $this->sales = $sales;
        $this->stockOutward = $stockOutward;
        $this->reservedStock = $reservedStock;
    }

    public function invoices()
    {
        if ($redirect = AppController::guardPage('sales')) {
            return $redirect;
        }

        return Inertia::render('Sales/SalesInvoices', [
            'page' => 'sales',
            'title' => 'Sales Invoices',
            'role_id' => AppController::roleId(),
            'endpoints' => $this->endpoints(),
        ]);
    }

    public function customers()
    {
        return app(CustomerController::class)->index();
    }

    public function stockOutward(Request $request)
    {
        if ($redirect = AppController::guardPage('sales')) return $redirect;
        abort_unless(AppController::canOpen('inventory-outward') || AppController::canOpen('stock-ledger'), 403);

        return Inertia::render('Sales/StockOperations', array_merge([
            'page' => 'inventory-outward',
            'title' => 'Stock Outward',
            'initial_tab' => 'ready',
            'endpoints' => $this->stockOutwardEndpoints(),
        ], $this->stockOutward->payload($request->query())));
    }

    public function reservedStock(Request $request)
    {
        if ($redirect = AppController::guardPage('sales')) return $redirect;
        abort_unless(AppController::canOpen('inventory-reserved'), 403);

        return Inertia::render('Sales/StockOperations', array_merge([
            'page' => 'inventory-reserved',
            'title' => 'Reserved Stock',
            'initial_tab' => 'reserved',
            'endpoints' => $this->reservedStockEndpoints(),
        ], $this->reservedStock->payload($request->query())));
    }

    public function reservedStockData(Request $request)
    {
        abort_unless(AppController::canOpen('inventory-reserved'), 403);

        return response()->json($this->reservedStock->payload($request->query()));
    }

    public function reservedStockAvailability(Request $request)
    {
        abort_unless(AppController::canOpen('inventory-reserved'), 403);

        return response()->json($this->reservedStock->availability($request->query()));
    }

    public function reservedStockExport(Request $request)
    {
        abort_unless(AppController::roleId() !== 3 && (AppController::canOpen('inventory-reserved') || AppController::canOpen('reports')), 403);
        $export = $this->reservedStock->export($request->query());

        return response()->streamDownload(function () use ($export) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $export['headers']);
            foreach ($export['rows'] as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $export['filename'], ['Content-Type' => 'text/csv']);
    }

    public function reservedStockRelease(Request $request, int $reservation)
    {
        $this->reservedStock->release($reservation, $request->input('reason'));

        return response()->json(['message' => 'Reservation released successfully.']);
    }

    public function reservedStockExtend(Request $request, int $reservation)
    {
        $data = $request->validate([
            'expiry_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->reservedStock->extend($reservation, $data['expiry_date'], $data['reason'] ?? null);

        return response()->json(['message' => 'Reservation extended successfully.']);
    }

    public function reservedStockDispatch(int $reservation)
    {
        $this->reservedStock->dispatch($reservation);

        return response()->json(['message' => 'Dispatch created and posted from reservation.']);
    }

    public function reservedStockCancel(Request $request, int $reservation)
    {
        return $this->reservedStockRelease($request, $reservation);
    }

    public function reservedStockPrint(Request $request, ?int $reservation = null)
    {
        abort_unless(AppController::canOpen('inventory-reserved'), 403);

        return Response::make($this->reservedStock->printHtml($request->query()));
    }

    public function stockOutwardData(Request $request)
    {
        abort_unless(AppController::canOpen('inventory-outward') || AppController::canOpen('inventory-reserved') || AppController::canOpen('stock-ledger'), 403);

        return response()->json($this->stockOutward->payload($request->query()));
    }

    public function stockOutwardReserved(Request $request)
    {
        abort_unless(AppController::canOpen('inventory-reserved'), 403);

        return response()->json(['reserved' => $this->stockOutward->reserved(array_merge($request->query(), ['tab' => 'reserved']))]);
    }

    public function stockOutwardLedger(Request $request)
    {
        abort_unless(AppController::canOpen('stock-ledger') || AppController::canOpen('inventory-outward'), 403);

        return response()->json(['ledger' => $this->stockOutward->ledger(array_merge($request->query(), ['tab' => 'ledger']))]);
    }

    public function dispatchStockOutward(int $outward)
    {
        return response()->json([
            'message' => 'Stock outward posted successfully.',
            'outward' => $this->stockOutward->dispatchChallan($outward),
        ]);
    }

    public function stockOutwardDetail(Request $request, int $outward)
    {
        abort_unless(AppController::canOpen('inventory-outward') || AppController::canOpen('stock-ledger'), 403);

        return response()->json(['dispatch' => $this->stockOutward->detail($outward, $request->query('row_type', 'challan'))]);
    }

    public function stockOutwardWorkflow(Request $request, int $outward)
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:pick,pack,dispatch,deliver,cancel'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'message' => 'Dispatch workflow updated successfully.',
            'outward' => $this->stockOutward->workflow($outward, $data['action'], $data),
        ]);
    }

    public function cancelStockOutward(Request $request, int $outward)
    {
        abort_unless(AppController::roleId() !== 3 && AppController::canOpen('inventory-outward'), 403);

        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            return response()->json(['message' => 'Cancellation reason is required.'], 422);
        }

        return response()->json(['message' => 'Posted dispatch reversal is Coming Soon. Existing posted documents are preserved for audit.'], 422);
    }

    public function releaseStockReservation(Request $request, int $order)
    {
        $this->stockOutward->releaseReservation($order, $request->input('reason'));

        return response()->json(['message' => 'Reservation released successfully.']);
    }

    public function stockOutwardExport(Request $request)
    {
        abort_unless(AppController::roleId() !== 3 && (AppController::canOpen('inventory-outward') || AppController::canOpen('reports')), 403);
        $export = $this->stockOutward->export($request->query());

        return response()->streamDownload(function () use ($export) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $export['headers']);
            foreach ($export['rows'] as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $export['filename'], ['Content-Type' => 'text/csv']);
    }

    public function stockOutwardPrint(Request $request, ?int $outward = null)
    {
        abort_unless(AppController::canOpen('inventory-outward') || AppController::canOpen('stock-ledger'), 403);

        return Response::make($this->stockOutward->printHtml($request->query()));
    }

    public function list(Request $request)
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('pos'), 403);
        $paginator = $this->sales->list($request->all());

        return response()->json([
            'sales' => $paginator->getCollection()->map(fn (SalesVoucher $voucher) => $this->sales->present($voucher, AppController::roleId() === 1))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function references()
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        return response()->json($this->sales->references());
    }

    public function searchProducts(Request $request)
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        return response()->json($this->sales->searchProducts(trim((string) $request->query('q')), $request->only(['branch_id', 'warehouse_id', 'price_type'])));
    }

    public function scanProduct(Request $request)
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:120'],
            'branch_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'price_type' => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json($this->sales->scanProduct($data['barcode'], $request->only(['branch_id', 'warehouse_id', 'price_type'])));
    }

    public function store(SalesVoucherRequest $request)
    {
        $voucher = $this->sales->create($request->validated());

        return response()->json(['message' => 'Sales invoice saved successfully.', 'sale' => $this->sales->present($voucher)], 201);
    }

    public function update(SalesVoucherRequest $request, int $sale)
    {
        $voucher = $this->sales->update($this->voucher($sale), $request->validated());

        return response()->json(['message' => 'Sales invoice updated successfully.', 'sale' => $this->sales->present($voucher)]);
    }

    public function duplicate(int $sale)
    {
        $voucher = $this->sales->duplicate($this->voucher($sale));

        return response()->json(['message' => 'Sales invoice duplicated successfully.', 'sale' => $this->sales->present($voucher)]);
    }

    public function approve(int $sale)
    {
        $voucher = $this->sales->post($this->voucher($sale), 'approved');

        return response()->json(['message' => 'Sales invoice posted successfully.', 'sale' => $this->sales->present($voucher)]);
    }

    public function hold(int $sale)
    {
        $voucher = $this->sales->hold($this->voucher($sale));

        return response()->json(['message' => 'Sales invoice held successfully.', 'sale' => $this->sales->present($voucher)]);
    }

    public function cancel(Request $request, int $sale)
    {
        $voucher = $this->sales->cancel($this->voucher($sale), $request->input('reason'));

        return response()->json(['message' => 'Sales invoice cancelled successfully.', 'sale' => $this->sales->present($voucher)]);
    }

    public function reverse(OpeningStockReverseRequest $request, int $sale)
    {
        $voucher = $this->sales->reverse($this->voucher($sale), $request->validated()['remarks']);

        return response()->json(['message' => 'Sales invoice reversed successfully.', 'sale' => $this->sales->present($voucher)]);
    }

    public function show(int $sale)
    {
        return response()->json($this->sales->present($this->voucher($sale), AppController::roleId() === 1));
    }

    public function addPayment(Request $request, int $sale)
    {
        $voucher = $this->sales->addPayment($this->voucher($sale), $request->validate([
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]));

        return response()->json(['message' => 'Payment added successfully.', 'sale' => $this->sales->present($voucher)]);
    }

    public function productLastPurchase(Request $request, CustomerAnalyticsService $analytics)
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
        ]);

        $customer = Customer::query()
            ->where('business_id', AppController::businessId())
            ->where('id', $data['customer_id'])
            ->firstOrFail();

        return response()->json([
            'last_purchase' => $analytics->lastProductPurchase($customer, (int) $data['product_id']),
        ]);
    }

    public function whatsappShare(Request $request, int $sale, WhatsAppShareService $whatsApp)
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        $data = $request->validate([
            'whatsapp_number' => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json($whatsApp->salesInvoiceShare($this->voucher($sale), $data['whatsapp_number'] ?? null));
    }

    public function print(Request $request, int $sale, InvoiceDocumentRenderer $renderer)
    {
        $voucher = $this->voucher($sale);
        $saleData = $this->sales->present($voucher, AppController::roleId() === 1);

        if ($request->query('format') === 'thermal') {
            return response($renderer->renderThermalReceipt($saleData));
        }

        return response($renderer->renderSalesInvoice($saleData));
    }

    public function pdf(int $sale, InvoiceDocumentRenderer $renderer)
    {
        $voucher = $this->voucher($sale);

        return $renderer->salesInvoicePdf($this->sales->present($voucher, AppController::roleId() === 1));
    }

    public function export(Request $request)
    {
        abort_unless(AppController::canOpen('sales'), 403);
        $rows = $this->sales->exportRows($request->all());
        $filename = 'sales-invoices-' . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Invoice number', 'Date', 'Customer', 'GSTIN', 'Branch', 'Warehouse', 'Invoice type', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Cess', 'Grand total', 'Paid', 'Balance', 'Payment status', 'Invoice status']);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function reports(Request $request)
    {
        abort_unless(AppController::canOpen('sales'), 403);

        return response()->json($this->sales->reports($request->all()));
    }

    private function endpoints(): array
    {
        $names = [
            'index' => 'app.sales.invoices',
            'create' => 'app.sales.invoices.create',
            'list' => 'app.sales.invoices.list',
            'references' => 'app.sales.invoices.references',
            'productSearch' => 'app.sales.invoices.products.search',
            'productScan' => 'app.sales.invoices.products.scan',
            'productLastPurchase' => 'app.sales.invoices.products.last-purchase',
            'reports' => 'app.sales.invoices.reports',
            'store' => 'app.sales.invoices.store',
            'export' => 'app.sales.invoices.export',
        ];

        return collect($names)
            ->filter(fn ($name) => Route::has($name))
            ->map(fn ($name) => route($name, [], false))
            ->merge([
                'show' => route('app.sales.invoices.show', ['sale' => '__ID__'], false),
                'update' => route('app.sales.invoices.update', ['sale' => '__ID__'], false),
                'duplicate' => route('app.sales.invoices.duplicate', ['sale' => '__ID__'], false),
                'approve' => route('app.sales.invoices.approve', ['sale' => '__ID__'], false),
                'post' => route('app.sales.invoices.post', ['sale' => '__ID__'], false),
                'hold' => route('app.sales.invoices.hold', ['sale' => '__ID__'], false),
                'cancel' => route('app.sales.invoices.cancel', ['sale' => '__ID__'], false),
                'reverse' => route('app.sales.invoices.reverse', ['sale' => '__ID__'], false),
                'print' => route('app.sales.invoices.print', ['sale' => '__ID__'], false),
                'pdf' => route('app.sales.invoices.pdf', ['sale' => '__ID__'], false),
                'whatsapp' => route('app.sales.invoices.whatsapp', ['sale' => '__ID__'], false),
                'paymentStore' => route('app.sales.invoices.payments.store', ['sale' => '__ID__'], false),
            ])
            ->all();
    }

    private function stockOutwardEndpoints(): array
    {
        $routes = [
            'index' => 'inventory.stock-outward.index',
            'data' => 'inventory.stock-outward.data',
            'export' => 'inventory.stock-outward.export',
            'print' => 'inventory.stock-outward.print-list',
            'reserved' => 'inventory.stock-outward.reserved',
            'ledger' => 'inventory.stock-outward.ledger',
        ];

        return collect($routes)
            ->filter(fn ($name) => Route::has($name))
            ->map(fn ($name) => route($name, [], false))
            ->merge([
                'dispatch' => Route::has('inventory.stock-outward.dispatch') ? route('inventory.stock-outward.dispatch', ['outward' => '__ID__'], false) : null,
                'detail' => Route::has('inventory.stock-outward.detail') ? route('inventory.stock-outward.detail', ['outward' => '__ID__'], false) : null,
                'workflow' => Route::has('inventory.stock-outward.workflow') ? route('inventory.stock-outward.workflow', ['outward' => '__ID__'], false) : null,
                'cancel' => Route::has('inventory.stock-outward.cancel') ? route('inventory.stock-outward.cancel', ['outward' => '__ID__'], false) : null,
                'releaseReservation' => Route::has('inventory.stock-outward.reservations.release') ? route('inventory.stock-outward.reservations.release', ['order' => '__ID__'], false) : null,
            ])
            ->filter()
            ->all();
    }

    private function reservedStockEndpoints(): array
    {
        $routes = [
            'index' => 'app.sales.reserved-stock',
            'data' => 'inventory.reserved-stock.data',
            'export' => 'inventory.reserved-stock.export',
            'print' => 'inventory.reserved-stock.print-list',
            'availability' => 'inventory.reserved-stock.availability',
        ];

        return collect($routes)
            ->filter(fn ($name) => Route::has($name))
            ->map(fn ($name) => route($name, [], false))
            ->merge([
                'releaseReservation' => Route::has('inventory.reserved-stock.release') ? route('inventory.reserved-stock.release', ['reservation' => '__ID__'], false) : null,
                'extendReservation' => Route::has('inventory.reserved-stock.extend') ? route('inventory.reserved-stock.extend', ['reservation' => '__ID__'], false) : null,
                'dispatchReservation' => Route::has('inventory.reserved-stock.dispatch') ? route('inventory.reserved-stock.dispatch', ['reservation' => '__ID__'], false) : null,
                'cancelReservation' => Route::has('inventory.reserved-stock.cancel') ? route('inventory.reserved-stock.cancel', ['reservation' => '__ID__'], false) : null,
            ])
            ->filter()
            ->all();
    }

    private function printHtml(array $sale): string
    {
        $money = function ($value): string {
            $formatted = number_format((float) $value, 2, '.', '');
            [$whole, $decimal] = explode('.', $formatted);
            $lastThree = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            if ($rest !== '') {
                $lastThree = ',' . $lastThree;
                $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            }

            return '₹' . $rest . $lastThree . '.' . $decimal;
        };
        $items = collect($sale['items'] ?? [])->map(fn ($item) => '<tr><td>' . e($item['product']) . '</td><td>' . e($item['hsn_code_snapshot'] ?? '') . '</td><td class="right">' . e($item['quantity']) . '</td><td class="right">' . $money($item['selling_rate']) . '</td><td class="right">' . $money($item['taxable_amount']) . '</td><td class="right">' . $money(($item['line_total'] ?? 0) - ($item['taxable_amount'] ?? 0)) . '</td><td class="right">' . $money($item['line_total']) . '</td></tr>')->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . e($sale['invoice_number']) . '</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#111}.top{display:flex;justify-content:space-between;gap:24px}.muted{color:#667085}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{padding:9px;border-bottom:1px solid #ddd;text-align:left}th{background:#f8fafc;font-size:12px;text-transform:uppercase}.right{text-align:right}.totals{margin-left:auto;width:320px}.print{margin-top:24px}@media print{.print{display:none}body{margin:0}}</style></head><body><div class="top"><div><h1>Tax Invoice</h1><p class="muted">' . e($sale['invoice_number']) . ' | ' . e($sale['invoice_date']) . '</p></div><div class="right"><strong>BillIQ</strong><p class="muted">Authorized invoice</p></div></div><hr><p><strong>Customer:</strong> ' . e($sale['customer'] ?: 'Walk-in Customer') . '<br><strong>Mobile:</strong> ' . e($sale['customer_mobile'] ?: '-') . '</p><table><thead><tr><th>Item</th><th>HSN</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Taxable</th><th class="right">GST</th><th class="right">Total</th></tr></thead><tbody>' . $items . '</tbody></table><table class="totals"><tr><td>Taxable</td><td class="right">' . $money($sale['taxable_amount']) . '</td></tr><tr><td>CGST</td><td class="right">' . $money($sale['cgst_amount']) . '</td></tr><tr><td>SGST</td><td class="right">' . $money($sale['sgst_amount']) . '</td></tr><tr><td>IGST</td><td class="right">' . $money($sale['igst_amount']) . '</td></tr><tr><th>Grand Total</th><th class="right">' . $money($sale['grand_total']) . '</th></tr><tr><td>Paid</td><td class="right">' . $money($sale['paid_amount']) . '</td></tr><tr><td>Balance</td><td class="right">' . $money($sale['balance_amount']) . '</td></tr></table><button class="print" onclick="window.print()">Print</button></body></html>';
    }

    private function voucher(int $id): SalesVoucher
    {
        $query = SalesVoucher::query()
            ->with(['customer', 'branch', 'warehouse', 'salesperson', 'creator', 'items.product', 'items.variant', 'items.batch', 'payments.method']);

        AppController::applyTenantScope($query, 'sales_vouchers');

        return $query
            ->where('id', $id)
            ->firstOrFail();
    }
}
