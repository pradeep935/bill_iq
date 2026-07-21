<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\OpeningStockVoucherRequest;
use App\Models\OpeningStockVoucher;
use App\Services\OpeningStockService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OpeningStockController extends Controller
{
    private OpeningStockService $openingStock;

    public function __construct(OpeningStockService $openingStock)
    {
        $this->openingStock = $openingStock;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('opening-stock')) {
            return $redirect;
        }

        return Inertia::render('Inventory/OpeningStock', [
            'page' => 'opening-stock',
            'title' => 'Opening Stock',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function list(Request $request)
    {
        $this->authorizeView();

        $paginator = $this->openingStock->list($request->all());

        return response()->json([
            'vouchers' => $paginator->getCollection()->map(fn (OpeningStockVoucher $voucher) => $this->present($voucher))->values(),
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
        $this->authorizeView();

        return response()->json($this->openingStock->references());
    }

    public function searchProducts(Request $request)
    {
        $this->authorizeView();

        return response()->json(
            $this->openingStock->searchProducts(trim((string) $request->query('q')))
        );
    }

    public function store(OpeningStockVoucherRequest $request)
    {
        $voucher = $this->openingStock->create($request->validated());

        return response()->json([
            'message' => 'Opening stock voucher saved successfully.',
            'voucher' => $this->present($voucher),
        ], 201);
    }

    public function update(OpeningStockVoucherRequest $request, int $voucher)
    {
        $voucherModel = $this->voucher($voucher);
        $voucherModel = $this->openingStock->update($voucherModel, $request->validated());

        return response()->json([
            'message' => 'Opening stock voucher updated successfully.',
            'voucher' => $this->present($voucherModel),
        ]);
    }

    public function approve(int $voucher)
    {
        $this->authorizeManage();

        $voucherModel = $this->openingStock->post($this->voucher($voucher), 'approved');

        return response()->json([
            'message' => 'Opening stock posted successfully.',
            'voucher' => $this->present($voucherModel),
        ]);
    }

    public function reverse(OpeningStockReverseRequest $request, int $voucher)
    {
        $voucherModel = $this->openingStock->reverse(
            $this->voucher($voucher),
            $request->validated()['remarks']
        );

        return response()->json([
            'message' => 'Opening stock reversed successfully.',
            'voucher' => $this->present($voucherModel),
        ]);
    }

    private function voucher(int $id): OpeningStockVoucher
    {
        return OpeningStockVoucher::query()
            ->where('business_id', AppController::businessId())
            ->with(['branch', 'warehouse', 'items.product', 'items.variant', 'items.batch'])
            ->where('id', $id)
            ->firstOrFail();
    }

    private function present(OpeningStockVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            'opening_date' => optional($voucher->opening_date)->format('Y-m-d'),
            'branch_id' => $voucher->branch_id,
            'branch' => optional($voucher->branch)->name,
            'warehouse_id' => $voucher->warehouse_id,
            'warehouse' => optional($voucher->warehouse)->name,
            'remarks' => $voucher->remarks,
            'status' => $voucher->status,
            'approved_at' => optional($voucher->approved_at)->toDateTimeString(),
            'items_count' => $voucher->items->count(),
            'total_quantity' => (float) $voucher->items->sum('quantity'),
            'items' => $voucher->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => optional($item->product)->name,
                'sku' => optional($item->product)->sku,
                'product_variant_id' => $item->product_variant_id,
                'variant' => optional($item->variant)->sku,
                'batch_id' => $item->batch_id,
                'batch_no' => $item->batch_no ?: optional($item->batch)->batch_no,
                'quantity' => (float) $item->quantity,
                'purchase_cost' => (float) $item->purchase_cost,
                'selling_price' => (float) $item->selling_price,
                'mrp' => $item->mrp !== null ? (float) $item->mrp : null,
                'warehouse_location' => $item->warehouse_location,
                'manufacturing_date' => optional($item->manufacturing_date)->format('Y-m-d'),
                'expiry_date' => optional($item->expiry_date)->format('Y-m-d'),
                'remarks' => $item->remarks,
            ])->values(),
        ];
    }

    private function authorizeView(): void
    {
        abort_unless(AppController::canOpen('opening-stock'), 403);
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();

        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()), 403);
    }
}
