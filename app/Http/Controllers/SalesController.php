<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\SalesVoucherRequest;
use App\Models\SalesVoucher;
use App\Services\SalesService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesController extends Controller
{
    private SalesService $sales;

    public function __construct(SalesService $sales)
    {
        $this->sales = $sales;
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
        ]);
    }

    public function customers()
    {
        return app(CustomerController::class)->index();
    }

    public function stockOutward()
    {
        return ModuleController::render('inventory-outward', 'Stock Outward');
    }

    public function reservedStock()
    {
        return ModuleController::render('inventory-reserved', 'Reserved Stock');
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

    public function reports(Request $request)
    {
        abort_unless(AppController::canOpen('sales'), 403);

        return response()->json($this->sales->reports($request->all()));
    }

    private function voucher(int $id): SalesVoucher
    {
        return SalesVoucher::query()
            ->where('business_id', AppController::businessId())
            ->with(['customer', 'branch', 'warehouse', 'salesperson', 'creator', 'items.product', 'items.variant', 'items.batch', 'payments.method'])
            ->where('id', $id)
            ->firstOrFail();
    }
}
