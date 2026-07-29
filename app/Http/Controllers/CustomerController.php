<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
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

    public function create()
    {
        return $this->index();
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

    public function references()
    {
        abort_unless(AppController::canOpen('customers'), 403);

        return response()->json($this->customers->references());
    }

    public function search(Request $request)
    {
        abort_unless(AppController::canOpen('customers') || AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        return response()->json($this->customers->search(trim((string) $request->query('q'))));
    }

    public function store(CustomerRequest $request)
    {
        $customer = $this->customers->create($request->validated());

        return response()->json(['message' => 'Customer saved successfully.', 'customer' => $this->customers->present($customer)], 201);
    }

    public function update(CustomerRequest $request, int $customer)
    {
        $model = Customer::query()->where('business_id', AppController::businessId())->where('id', $customer)->firstOrFail();

        return response()->json([
            'message' => 'Customer updated successfully.',
            'customer' => $this->customers->present($this->customers->update($model, $request->validated())),
        ]);
    }

    public function show(int $customer)
    {
        abort_unless(AppController::canOpen('customers'), 403);

        return response()->json(['customer' => $this->customers->detail($this->customer($customer))]);
    }

    public function edit(int $customer)
    {
        return $this->show($customer);
    }

    public function activate(int $customer)
    {
        return response()->json([
            'message' => 'Customer activated successfully.',
            'customer' => $this->customers->present($this->customers->setStatus($this->customer($customer), 'active')),
        ]);
    }

    public function deactivate(int $customer)
    {
        return response()->json([
            'message' => 'Customer deactivated successfully.',
            'customer' => $this->customers->present($this->customers->setStatus($this->customer($customer), 'inactive')),
        ]);
    }

    public function ledger(Request $request, int $customer)
    {
        abort_unless(AppController::canOpen('customers'), 403);

        return response()->json($this->customers->ledger($this->customer($customer), $request->all()));
    }

    public function statement(Request $request, int $customer)
    {
        abort_unless(AppController::canOpen('customers'), 403);

        return response()->json($this->customers->statement($this->customer($customer), $request->all()));
    }

    public function sales(Request $request, int $customer)
    {
        abort_unless(AppController::canOpen('customers'), 403);

        return response()->json($this->customers->sales($this->customer($customer), $request->all()));
    }

    public function outstanding(int $customer)
    {
        abort_unless(AppController::canOpen('customers'), 403);

        return response()->json($this->customers->outstanding($this->customer($customer)));
    }

    public function export(Request $request)
    {
        abort_unless(AppController::canOpen('customers'), 403);

        $filename = 'customers-' . now()->format('Y-m-d') . '.csv';

        return Response::streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Customer code', 'Customer name', 'Customer type', 'Contact person', 'Mobile', 'Phone', 'Email',
                'GSTIN', 'PAN', 'State', 'City', 'Pincode', 'Billing address', 'Shipping address',
                'Opening balance', 'Current outstanding', 'Credit limit', 'Available credit', 'Credit days',
                'Price list', 'Status', 'Created date',
            ]);

            foreach ($this->customers->exportRows($request->all()) as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function import(Request $request)
    {
        abort_unless(AppController::canOpen('customers'), 403);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);

        return response()->json($this->customers->importCsv($data['file']));
    }

    public function importTemplate()
    {
        abort_unless(AppController::canOpen('customers'), 403);

        $columns = 'customer_code,customer_name,customer_type,contact_person,mobile,phone,email,gstin,pan,billing_address,shipping_address,city,state_id,pincode,opening_balance,balance_type,credit_limit,credit_days,price_list,status';

        return response($columns . "\n", 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="customer-import-template.csv"',
        ]);
    }

    public function destroy(int $customer)
    {
        $this->customers->delete($this->customer($customer));

        return response()->json(['message' => 'Customer deleted successfully.']);
    }

    public function restore(int $customer)
    {
        return response()->json([
            'message' => 'Customer restored successfully.',
            'customer' => $this->customers->present($this->customers->restore($customer)),
        ]);
    }

    private function customer(int $id): Customer
    {
        return Customer::withTrashed()
            ->where('business_id', AppController::businessId())
            ->where('id', $id)
            ->firstOrFail();
    }
}
