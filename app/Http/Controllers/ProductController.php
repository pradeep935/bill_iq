<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductBulkStatusRequest;
use App\Http\Requests\ProductMasterRequest;
use App\Models\HsnMaster;
use App\Services\ProductMasterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ProductController extends Controller
{
    private ProductMasterService $products;

    public function __construct(ProductMasterService $products)
    {
        $this->products = $products;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('products')) {
            return $redirect;
        }

        return Inertia::render('Product/Index', [
            'page' => 'products',
            'title' => 'Products & Barcode',
            'role_id' => Auth::user()->role_id,
        ]);
    }

    public function products(Request $request)
    {
        $this->authorizeProductView();

        return response()->json(
            $this->products->presentPaginator(
                $this->products->list($request->all())
            )
        );
    }

    public function show(int $product)
    {
        $this->authorizeProductView();

        return response()->json([
            'product' => $this->products->present(
                $this->products->find($product, true)
            ),
        ]);
    }

    public function store(ProductMasterRequest $request)
    {
        $product = $this->products->create($request->validated());

        return response()->json([
            'message' => 'Product saved successfully.',
            'product' => $this->products->present($product),
        ], 201);
    }

    public function update(ProductMasterRequest $request, int $product)
    {
        $productModel = $this->products->find($product);
        $updatedProduct = $this->products->update($productModel, $request->validated());

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $this->products->present($updatedProduct),
        ]);
    }

    public function save(ProductMasterRequest $request)
    {
        $productId = (int) $request->input('id', 0);

        if ($productId > 0) {
            return $this->update($request, $productId);
        }

        return $this->store($request);
    }

    public function destroy(int $product)
    {
        $this->authorizeProductManage();

        $productModel = $this->products->find($product);
        $this->products->softDelete($productModel);

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    public function restore(int $product)
    {
        $this->authorizeProductManage();

        $productModel = $this->products->find($product, true);
        $restoredProduct = $this->products->restore($productModel);

        return response()->json([
            'message' => 'Product restored successfully.',
            'product' => $this->products->present($restoredProduct),
        ]);
    }

    public function forceDelete(int $product)
    {
        $user = Auth::user();

        abort_unless($user && $user->isSuperAdmin(), 403);

        $productModel = $this->products->find($product, true);
        $this->products->forceDelete($productModel);

        return response()->json([
            'message' => 'Product permanently deleted successfully.',
        ]);
    }

    public function duplicate(int $product)
    {
        $this->authorizeProductManage();

        $productModel = $this->products->find($product);
        $duplicatedProduct = $this->products->duplicate($productModel);

        return response()->json([
            'message' => 'Product duplicated successfully.',
            'product' => $this->products->present($duplicatedProduct),
        ], 201);
    }

    public function bulkStatus(ProductBulkStatusRequest $request)
    {
        $data = $request->validated();

        $updatedCount = $this->products->bulkStatus($data['ids'], $data['status']);

        return response()->json([
            'message' => 'Product status updated successfully.',
            'updated_count' => $updatedCount,
        ]);
    }

    public function hsnSearch(Request $request)
    {
        $this->authorizeProductView();

        $search = trim((string) $request->query('q'));

        $hsnMaster = HsnMaster::where('status', 'active')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery
                        ->where('hsn_code', 'like', $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            })
            ->orderBy('hsn_code')
            ->limit(20)
            ->get([
                'id',
                'hsn_code',
                'description',
                'gst_rate',
                'cess_rate',
                'effective_from',
                'notification_number',
                'source_reference',
            ]);

        return response()->json($hsnMaster);
    }

    private function authorizeProductView(): void
    {
        abort_unless(AppController::canOpen('products'), 403);
    }

    private function authorizeProductManage(): void
    {
        $user = Auth::user();

        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()), 403);
    }
}
