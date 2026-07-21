<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\PurchaseVoucherRequest;
use App\Models\PurchaseVoucher;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PurchaseController extends Controller
{
    private PurchaseService $purchases;

    public function __construct(PurchaseService $purchases)
    {
        $this->purchases = $purchases;
    }

    public function bills()
    {
        if ($redirect = AppController::guardPage('purchases')) {
            return $redirect;
        }

        return Inertia::render('Purchase/Purchases', [
            'page' => 'purchases',
            'title' => 'Purchases',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function suppliers()
    {
        return ModuleController::render('suppliers', 'Suppliers');
    }

    public function grn()
    {
        return ModuleController::render('inventory-inward', 'Stock Inward / GRN');
    }

    public function reorder()
    {
        return ModuleController::render('inventory-reorder', 'Reorder Suggestions');
    }

    public function orders()
    {
        return ModuleController::render('inventory-orders', 'Inventory Orders');
    }

    public function list(Request $request)
    {
        abort_unless(AppController::canOpen('purchases'), 403);

        $paginator = $this->purchases->list($request->all());

        return response()->json([
            'purchases' => $paginator->getCollection()->map(fn (PurchaseVoucher $voucher) => $this->purchases->present($voucher))->values(),
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
        abort_unless(AppController::canOpen('purchases'), 403);

        return response()->json($this->purchases->references());
    }

    public function searchProducts(Request $request)
    {
        abort_unless(AppController::canOpen('purchases'), 403);

        return response()->json($this->purchases->searchProducts(trim((string) $request->query('q'))));
    }

    public function store(PurchaseVoucherRequest $request)
    {
        $voucher = $this->purchases->create($request->validated());

        return response()->json([
            'message' => 'Purchase saved successfully.',
            'purchase' => $this->purchases->present($voucher),
        ], 201);
    }

    public function update(PurchaseVoucherRequest $request, int $purchase)
    {
        $voucher = $this->voucher($purchase);
        $voucher = $this->purchases->update($voucher, $request->validated());

        return response()->json([
            'message' => 'Purchase updated successfully.',
            'purchase' => $this->purchases->present($voucher),
        ]);
    }

    public function duplicate(int $purchase)
    {
        $voucher = $this->purchases->duplicate($this->voucher($purchase));

        return response()->json([
            'message' => 'Purchase duplicated successfully.',
            'purchase' => $this->purchases->present($voucher),
        ], 201);
    }

    public function approve(int $purchase)
    {
        $voucher = $this->purchases->post($this->voucher($purchase), 'approved');

        return response()->json([
            'message' => 'Purchase posted successfully.',
            'purchase' => $this->purchases->present($voucher),
        ]);
    }

    public function cancel(int $purchase)
    {
        $voucher = $this->purchases->cancel($this->voucher($purchase));

        return response()->json([
            'message' => 'Purchase cancelled successfully.',
            'purchase' => $this->purchases->present($voucher),
        ]);
    }

    public function reverse(OpeningStockReverseRequest $request, int $purchase)
    {
        $voucher = $this->purchases->reverse($this->voucher($purchase), $request->validated()['remarks']);

        return response()->json([
            'message' => 'Purchase reversed successfully.',
            'purchase' => $this->purchases->present($voucher),
        ]);
    }

    private function voucher(int $id): PurchaseVoucher
    {
        return PurchaseVoucher::query()
            ->where('business_id', AppController::businessId())
            ->with(['supplier', 'branch', 'warehouse', 'creator', 'items.product', 'items.variant', 'items.batch'])
            ->where('id', $id)
            ->firstOrFail();
    }
}
