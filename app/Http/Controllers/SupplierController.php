<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierController extends Controller
{
    private SupplierService $suppliers;

    public function __construct(SupplierService $suppliers)
    {
        $this->suppliers = $suppliers;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('suppliers')) {
            return $redirect;
        }

        return Inertia::render('Purchase/Suppliers', [
            'page' => 'suppliers',
            'title' => 'Suppliers',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function list(Request $request)
    {
        abort_unless(AppController::canOpen('suppliers'), 403);

        $paginator = $this->suppliers->list($request->all());

        return response()->json([
            'suppliers' => $paginator->getCollection()->map(fn (Supplier $supplier) => $this->suppliers->present($supplier))->values(),
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

    public function store(SupplierRequest $request)
    {
        $supplier = $this->suppliers->create($request->validated());

        return response()->json([
            'message' => 'Supplier saved successfully.',
            'supplier' => $this->suppliers->present($supplier),
        ], 201);
    }

    public function update(SupplierRequest $request, int $supplier)
    {
        $supplierModel = Supplier::query()->where('business_id', AppController::businessId())->where('id', $supplier)->firstOrFail();
        $supplierModel = $this->suppliers->update($supplierModel, $request->validated());

        return response()->json([
            'message' => 'Supplier updated successfully.',
            'supplier' => $this->suppliers->present($supplierModel),
        ]);
    }

    public function destroy(int $supplier)
    {
        $supplierModel = Supplier::query()->where('business_id', AppController::businessId())->where('id', $supplier)->firstOrFail();
        $this->suppliers->delete($supplierModel);

        return response()->json(['message' => 'Supplier deleted successfully.']);
    }

    public function restore(int $supplier)
    {
        $supplierModel = $this->suppliers->restore($supplier);

        return response()->json([
            'message' => 'Supplier restored successfully.',
            'supplier' => $this->suppliers->present($supplierModel),
        ]);
    }
}
