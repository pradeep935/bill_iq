<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerAnalyticsService;
use App\Services\CustomerService;
use App\Services\MobileNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
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

    public function lookupByMobile(Request $request, MobileNumberService $mobileNumbers, CustomerAnalyticsService $analytics)
    {
        abort_unless(AppController::canOpen('customers') || AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        $mobile = trim((string) $request->query('mobile'));
        $normalized = $mobileNumbers->normalize($mobile);

        if (!$mobileNumbers->isValidIndianMobile($mobile)) {
            return response()->json([
                'status' => 'invalid',
                'normalized_mobile' => $normalized,
                'message' => 'Enter a valid 10-digit mobile number.',
            ], 422);
        }

        $matches = Customer::query()
            ->where('business_id', AppController::businessId())
            ->where('status', 'active')
            ->where('normalized_mobile', $normalized)
            ->limit(5)
            ->get();

        if ($matches->count() > 1) {
            return response()->json([
                'status' => 'multiple',
                'normalized_mobile' => $normalized,
                'customers' => $matches->map(fn (Customer $customer) => $this->customers->present($customer))->values(),
                'message' => 'Multiple customers found for this mobile number. Please select manually.',
            ]);
        }

        if ($matches->count() === 1) {
            $customer = $matches->first();

            return response()->json([
                'status' => 'found',
                'normalized_mobile' => $normalized,
                'customer' => $this->customers->present($customer),
                'insight' => $analytics->insight($customer),
            ]);
        }

        return response()->json([
            'status' => 'new',
            'normalized_mobile' => $normalized,
            'message' => 'No customer found. You can quick-create this customer.',
        ]);
    }

    public function quickCreate(Request $request)
    {
        abort_unless(AppController::canOpen('customers') || AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:30'],
            'whatsapp_number' => ['nullable', 'string', 'max:30'],
            'whatsapp_same_as_mobile' => ['nullable', 'boolean'],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'shipping_address' => ['nullable', 'string', 'max:2000'],
            'customer_type' => ['nullable', Rule::in(['retail', 'wholesale', 'dealer', 'distributor', 'corporate', 'government', 'export', 'other'])],
        ]);

        $customer = $this->customers->create(array_merge([
            'customer_type' => 'retail',
            'status' => 'active',
            'opening_balance' => 0,
            'opening_balance_type' => 'debit',
            'price_type' => 'retail',
        ], $data));

        return response()->json([
            'message' => 'Customer created successfully.',
            'customer' => $this->customers->present($customer),
            'insight' => app(CustomerAnalyticsService::class)->insight($customer),
        ], 201);
    }

    public function insight(int $customer, CustomerAnalyticsService $analytics)
    {
        abort_unless(AppController::canOpen('customers') || AppController::canOpen('sales') || AppController::canOpen('pos'), 403);

        return response()->json($analytics->insight($this->customer($customer)));
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
