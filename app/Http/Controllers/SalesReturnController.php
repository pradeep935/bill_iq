<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\SalesReturnVoucherRequest;
use App\Models\SalesReturnVoucher;
use App\Services\SalesReturnService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesReturnController extends Controller
{
    private SalesReturnService $returns;

    public function __construct(SalesReturnService $returns)
    {
        $this->returns = $returns;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('sales-returns')) {
            return $redirect;
        }

        return Inertia::render('Sales/SalesReturns', [
            'page' => 'sales-returns',
            'title' => 'Sales Returns',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function list(Request $request)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);
        $paginator = $this->returns->list($request->all());

        return response()->json([
            'returns' => $paginator->getCollection()->map(fn (SalesReturnVoucher $voucher) => $this->returns->present($voucher))->values(),
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
        abort_unless(AppController::canOpen('sales-returns'), 403);

        return response()->json($this->returns->references());
    }

    public function searchProducts(Request $request)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);

        return response()->json($this->returns->searchProducts(trim((string) $request->query('q')), $request->only(['branch_id', 'warehouse_id', 'price_type'])));
    }

    public function searchSales(Request $request)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);

        return response()->json($this->returns->searchSales(trim((string) $request->query('q'))));
    }

    public function saleItems(int $sale)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);

        return response()->json($this->returns->saleItems($sale));
    }

    public function store(SalesReturnVoucherRequest $request)
    {
        $voucher = $this->returns->create($request->validated());

        return response()->json(['message' => 'Sales return saved successfully.', 'return' => $this->returns->present($voucher)], 201);
    }

    public function update(SalesReturnVoucherRequest $request, int $return)
    {
        $voucher = $this->returns->update($this->voucher($return), $request->validated());

        return response()->json(['message' => 'Sales return updated successfully.', 'return' => $this->returns->present($voucher)]);
    }

    public function approve(int $return)
    {
        $voucher = $this->returns->post($this->voucher($return), 'approved');

        return response()->json(['message' => 'Sales return posted successfully.', 'return' => $this->returns->present($voucher)]);
    }

    public function cancel(int $return)
    {
        $voucher = $this->returns->cancel($this->voucher($return));

        return response()->json(['message' => 'Sales return cancelled successfully.', 'return' => $this->returns->present($voucher)]);
    }

    public function reverse(OpeningStockReverseRequest $request, int $return)
    {
        $voucher = $this->returns->reverse($this->voucher($return), $request->validated()['remarks']);

        return response()->json(['message' => 'Sales return reversed successfully.', 'return' => $this->returns->present($voucher)]);
    }

    private function voucher(int $id): SalesReturnVoucher
    {
        return SalesReturnVoucher::query()
            ->where('business_id', AppController::businessId())
            ->with(['customer', 'sale', 'branch', 'warehouse', 'creator', 'items.product', 'items.variant', 'items.batch', 'refunds.method'])
            ->where('id', $id)
            ->firstOrFail();
    }
}
