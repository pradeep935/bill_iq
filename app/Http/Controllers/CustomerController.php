<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    private CustomerService $customers;

    public function __construct(CustomerService $customers)
    {
        $this->customers = $customers;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('customers')) {
            return $redirect;
        }

        return Inertia::render('Sales/Customers', [
            'page' => 'customers',
            'title' => 'Customers',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function list(Request $request)
    {
        abort_unless(AppController::canOpen('customers'), 403);
        $paginator = $this->customers->list($request->all());

        return response()->json([
            'customers' => $paginator->getCollection()->values(),
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

    public function search(Request $request)
    {
        abort_unless(AppController::canOpen('customers') || AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        return response()->json($this->customers->search(trim((string) $request->query('q'))));
    }

    public function store(CustomerRequest $request)
    {
        $customer = $this->customers->create($request->validated());

        return response()->json(['message' => 'Customer saved successfully.', 'customer' => $customer], 201);
    }

    public function update(CustomerRequest $request, int $customer)
    {
        $model = Customer::query()->where('business_id', AppController::businessId())->where('id', $customer)->firstOrFail();

        return response()->json([
            'message' => 'Customer updated successfully.',
            'customer' => $this->customers->update($model, $request->validated()),
        ]);
    }

    public function destroy(int $customer)
    {
        $model = Customer::query()->where('business_id', AppController::businessId())->where('id', $customer)->firstOrFail();
        $this->customers->delete($model);

        return response()->json(['message' => 'Customer deleted successfully.']);
    }

    public function restore(int $customer)
    {
        return response()->json([
            'message' => 'Customer restored successfully.',
            'customer' => $this->customers->restore($customer),
        ]);
    }
}
