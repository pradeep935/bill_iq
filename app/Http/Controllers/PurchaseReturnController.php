<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\PurchaseReturnVoucherRequest;
use App\Models\PurchaseReturnVoucher;
use App\Services\PurchaseReturnService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PurchaseReturnController extends Controller
{
    private PurchaseReturnService $returns;

    public function __construct(PurchaseReturnService $returns)
    {
        $this->returns = $returns;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('purchase-returns')) {
            return $redirect;
        }

        return Inertia::render('Purchase/PurchaseReturns', [
            'page' => 'purchase-returns',
            'title' => 'Purchase Returns',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function list(Request $request)
    {
        abort_unless(AppController::canOpen('purchase-returns'), 403);

        $paginator = $this->returns->list($request->all());

        return response()->json([
            'returns' => $paginator->getCollection()->map(fn (PurchaseReturnVoucher $voucher) => $this->returns->present($voucher))->values(),
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
        abort_unless(AppController::canOpen('purchase-returns'), 403);

        return response()->json($this->returns->references());
    }

    public function searchProducts(Request $request)
    {
        abort_unless(AppController::canOpen('purchase-returns'), 403);

        return response()->json($this->returns->searchProducts(trim((string) $request->query('q'))));
    }

    public function searchPurchases(Request $request)
    {
        abort_unless(AppController::canOpen('purchase-returns'), 403);

        return response()->json($this->returns->searchPurchases(trim((string) $request->query('q'))));
    }

    public function purchaseItems(int $purchase)
    {
        abort_unless(AppController::canOpen('purchase-returns'), 403);

        return response()->json($this->returns->purchaseItems($purchase));
    }

    public function store(PurchaseReturnVoucherRequest $request)
    {
        $voucher = $this->returns->create($request->validated());

        return response()->json([
            'message' => 'Purchase return saved successfully.',
            'return' => $this->returns->present($voucher),
        ], 201);
    }

    public function update(PurchaseReturnVoucherRequest $request, int $return)
    {
        $voucher = $this->returns->update($this->voucher($return), $request->validated());

        return response()->json([
            'message' => 'Purchase return updated successfully.',
            'return' => $this->returns->present($voucher),
        ]);
    }

    public function approve(int $return)
    {
        $voucher = $this->returns->post($this->voucher($return), 'approved');

        return response()->json([
            'message' => 'Purchase return posted successfully.',
            'return' => $this->returns->present($voucher),
        ]);
    }

    public function cancel(int $return)
    {
        $voucher = $this->returns->cancel($this->voucher($return));

        return response()->json([
            'message' => 'Purchase return cancelled successfully.',
            'return' => $this->returns->present($voucher),
        ]);
    }

    public function reverse(OpeningStockReverseRequest $request, int $return)
    {
        $voucher = $this->returns->reverse($this->voucher($return), $request->validated()['remarks']);

        return response()->json([
            'message' => 'Purchase return reversed successfully.',
            'return' => $this->returns->present($voucher),
        ]);
    }

    private function voucher(int $id): PurchaseReturnVoucher
    {
        return PurchaseReturnVoucher::query()
            ->where('business_id', AppController::businessId())
            ->with(['supplier', 'purchase', 'branch', 'warehouse', 'creator', 'items.product', 'items.variant', 'items.batch'])
            ->where('id', $id)
            ->firstOrFail();
    }
}
