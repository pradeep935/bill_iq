<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\BusinessAccountSetting;
use App\Models\Customer;
use App\Models\JournalVoucher;
use App\Models\ReceiptVoucher;
use App\Models\SalesReturnVoucher;
use App\Models\SalesVoucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(
        private MobileNumberService $mobileNumbers,
        private CustomerAnalyticsService $analytics
    ) {
    }

    public function list(array $filters = [])
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $this->query($filters)->paginate($perPage)->through(fn (Customer $customer) => $this->present($customer));
    }

    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $data = $this->normalize($data);
            $this->validateDuplicatePolicy($businessId, $data);
            $customerPayload = array_merge($this->attributes($data), [
                'business_id' => $businessId,
                'customer_code' => ($data['customer_code'] ?? '') ?: $this->nextCode($businessId),
                'created_by' => Auth::id(),
            ]);

            if (Schema::hasColumn('customers', 'company_id')) {
                $customerPayload['company_id'] = $businessId;
            }

            if (Schema::hasColumn('customers', 'name')) {
                $customerPayload['name'] = $data['customer_name'];
            }

            $customer = Customer::query()->create($customerPayload);

            $this->postOpeningBalance($customer);

            AuditLogger::record([
                'module_name' => 'Customer',
                'record_id' => $customer->id,
                'action_type' => 'Create',
                'business_id' => $businessId,
                'summary' => 'Customer created',
            ]);

            return $customer->fresh();
        });
    }

    public function update(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            $this->assertBusiness($customer);
            $data = $this->normalize($data);
            $this->validateDuplicatePolicy($customer->business_id, $data, $customer->id);

            if ($customer->opening_balance_voucher_id && round((float) $customer->opening_balance, 2) !== round((float) ($data['opening_balance'] ?? 0), 2)) {
                throw ValidationException::withMessages(['opening_balance' => 'Posted opening balance cannot be changed here. Use an adjustment voucher.']);
            }

            $old = $customer->only(['gstin', 'pan', 'credit_limit', 'credit_days', 'price_type', 'status']);
            $customer->update(array_merge($this->attributes($data), ['updated_by' => Auth::id()]));

            AuditLogger::record([
                'module_name' => 'Customer',
                'record_id' => $customer->id,
                'action_type' => 'Update',
                'business_id' => $customer->business_id,
                'summary' => 'Customer updated',
                'old_values' => $old,
                'new_values' => $customer->only(['gstin', 'pan', 'credit_limit', 'credit_days', 'price_type', 'status']),
            ]);

            return $customer->fresh();
        });
    }

    public function delete(Customer $customer): void
    {
        $this->assertBusiness($customer);

        if ($this->hasTransactions($customer)) {
            throw ValidationException::withMessages(['customer' => 'Customer has transactions and cannot be hard-deleted. Deactivate or block the customer instead.']);
        }

        $customer->delete();
    }

    public function restore(int $id): Customer
    {
        $customer = Customer::withTrashed()->where('business_id', AppController::businessId())->where('id', $id)->firstOrFail();
        $customer->restore();

        return $customer;
    }

    public function setStatus(Customer $customer, string $status, ?string $reason = null): Customer
    {
        $this->assertBusiness($customer);
        if (!in_array($status, ['active', 'inactive', 'blocked'], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid customer status.']);
        }

        $customer->update([
            'status' => $status,
            'blocked_reason' => $status === 'blocked' ? $reason : null,
            'blocked_at' => $status === 'blocked' ? now() : null,
            'blocked_by' => $status === 'blocked' ? Auth::id() : null,
            'updated_by' => Auth::id(),
        ]);

        AuditLogger::record([
            'module_name' => 'Customer',
            'record_id' => $customer->id,
            'action_type' => ucfirst($status),
            'business_id' => $customer->business_id,
            'summary' => 'Customer status changed to ' . $status,
        ]);

        return $customer->fresh();
    }

    public function defaultWalkIn(): Customer
    {
        $businessId = AppController::businessId();

        $customer = Customer::withTrashed()->firstOrCreate(
            ['business_id' => $businessId, 'customer_type' => 'walk_in', 'customer_code' => 'WALK-IN'],
            array_filter([
                'company_id' => \Illuminate\Support\Facades\Schema::hasColumn('customers', 'company_id') ? $businessId : null,
                'customer_name' => 'Walk-in Customer',
                'name' => \Illuminate\Support\Facades\Schema::hasColumn('customers', 'name') ? 'Walk-in Customer' : null,
                'status' => 'active',
                'opening_balance' => 0,
                'created_by' => Auth::id(),
            ], fn ($value) => $value !== null)
        );

        if ($customer->trashed()) {
            $customer->restore();
        }

        return $customer;
    }

    public function search(string $search)
    {
        $normalized = $this->mobileNumbers->normalize($search);

        return Customer::query()
            ->where('business_id', AppController::businessId())
            ->where('status', 'active')
            ->where(function (Builder $q) use ($search, $normalized) {
                $like = '%' . $search . '%';
                $q->where('customer_name', 'like', $like)
                    ->orWhere('customer_code', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->when($normalized, fn (Builder $query) => $query->orWhere('normalized_mobile', $normalized)->orWhere('whatsapp_number', $normalized))
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('gstin', 'like', $like);
            })
            ->limit(20)
            ->get()
            ->map(fn (Customer $customer) => $this->present($customer));
    }

    public function references(): array
    {
        $businessId = AppController::businessId();

        return [
            'customer_types' => [
                ['value' => 'retail', 'label' => 'Retail'],
                ['value' => 'wholesale', 'label' => 'Wholesale'],
                ['value' => 'distributor', 'label' => 'Distributor'],
                ['value' => 'corporate', 'label' => 'Corporate'],
                ['value' => 'government', 'label' => 'Government'],
                ['value' => 'export', 'label' => 'Export'],
                ['value' => 'walk_in', 'label' => 'Walk-in'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'price_types' => [
                ['value' => 'retail', 'label' => 'Retail Price'],
                ['value' => 'wholesale', 'label' => 'Wholesale Price'],
                ['value' => 'dealer', 'label' => 'Dealer Price'],
                ['value' => 'distributor', 'label' => 'Distributor Price'],
                ['value' => 'online', 'label' => 'Online Price'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'states' => $this->states(),
            'cities' => $this->cities(),
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code'])
                : collect(),
        ];
    }

    public function detail(Customer $customer): array
    {
        $this->assertBusiness($customer);
        $presented = $this->present($customer);

        return array_merge($presented, [
            'financial_summary' => $this->financialSummary($customer),
            'crm_summary' => $this->analytics->summary($customer),
            'recent_sales' => $this->sales($customer, ['per_page' => 5])['sales'],
            'product_history' => $this->analytics->productHistory($customer),
            'recent_returns' => $this->returns($customer, 5),
            'recent_payments' => $this->payments($customer, 5),
            'ledger_preview' => $this->ledger($customer, ['limit' => 10])['entries'],
        ]);
    }

    public function ledger(Customer $customer, array $filters = []): array
    {
        $this->assertBusiness($customer);
        $entries = DB::table('journal_entries')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_entries.journal_voucher_id')
            ->leftJoin('branches', 'branches.id', '=', 'journal_entries.branch_id')
            ->where('journal_entries.business_id', $customer->business_id)
            ->where('journal_entries.customer_id', $customer->id)
            ->whereIn('journal_vouchers.status', ['posted', 'approved'])
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('journal_vouchers.voucher_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('journal_vouchers.voucher_date', '<=', $filters['date_to']))
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('journal_entries.branch_id', $filters['branch_id']))
            ->when(!empty($filters['voucher_type']), fn ($q) => $q->where('journal_vouchers.voucher_type', $filters['voucher_type']))
            ->orderBy('journal_vouchers.voucher_date')
            ->orderBy('journal_entries.id')
            ->limit((int) ($filters['limit'] ?? 500))
            ->get([
                'journal_vouchers.voucher_date as date',
                'journal_vouchers.voucher_type',
                'journal_vouchers.voucher_number',
                'journal_vouchers.reference_number',
                'journal_entries.narration',
                'journal_entries.debit_amount',
                'journal_entries.credit_amount',
                'journal_entries.due_date',
                'branches.name as branch',
            ]);

        $running = 0.0;
        $rows = $entries->map(function ($row) use (&$running) {
            $running += (float) $row->debit_amount - (float) $row->credit_amount;

            return [
                'date' => (string) $row->date,
                'voucher_type' => $row->voucher_type,
                'reference' => $row->reference_number ?: $row->voucher_number,
                'debit' => (float) $row->debit_amount,
                'credit' => (float) $row->credit_amount,
                'running_balance' => round($running, 2),
                'due_date' => $row->due_date,
                'branch' => $row->branch,
                'narration' => $row->narration,
            ];
        })->values();

        return ['customer' => $this->present($customer), 'entries' => $rows, 'closing_balance' => round($running, 2)];
    }

    public function statement(Customer $customer, array $filters = []): array
    {
        $ledger = $this->ledger($customer, $filters);
        $debit = collect($ledger['entries'])->sum('debit');
        $credit = collect($ledger['entries'])->sum('credit');

        return [
            'customer' => $ledger['customer'],
            'opening_balance' => (float) $customer->opening_balance,
            'transactions' => $ledger['entries'],
            'total_debit' => round($debit, 2),
            'total_credit' => round($credit, 2),
            'closing_balance' => $ledger['closing_balance'],
            'ageing' => $this->ageing($customer),
        ];
    }

    public function sales(Customer $customer, array $filters = []): array
    {
        $this->assertBusiness($customer);
        $perPage = min(max((int) ($filters['per_page'] ?? 10), 1), 50);
        $paginator = SalesVoucher::query()
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->latest('invoice_date')
            ->paginate($perPage);

        return [
            'sales' => $paginator->getCollection()->map(fn (SalesVoucher $sale) => [
                'id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'invoice_date' => optional($sale->invoice_date)->format('Y-m-d'),
                'due_date' => optional($sale->due_date)->format('Y-m-d'),
                'grand_total' => (float) $sale->grand_total,
                'paid_amount' => (float) $sale->paid_amount,
                'balance_amount' => (float) $sale->balance_amount,
                'payment_status' => $sale->payment_status,
                'status' => $sale->status,
            ])->values(),
            'pagination' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ];
    }

    public function outstanding(Customer $customer): array
    {
        $this->assertBusiness($customer);

        return [
            'customer' => $this->present($customer),
            'items' => $this->outstandingRows($customer),
            'ageing' => $this->ageing($customer),
        ];
    }

    public function exportRows(array $filters = [])
    {
        return $this->query($filters)->get()->map(function (Customer $customer) {
            $summary = $this->financialSummary($customer);

            return [
                $customer->customer_code,
                $customer->customer_name,
                $customer->customer_type,
                $customer->contact_person,
                $customer->mobile,
                $customer->phone,
                $customer->email,
                $customer->gstin,
                $customer->pan,
                $this->stateName($customer->state_id),
                $customer->city,
                $customer->pincode,
                $customer->billing_address,
                $customer->shipping_address,
                (float) $customer->opening_balance,
                $summary['current_outstanding'],
                (float) $customer->credit_limit,
                $summary['available_credit'],
                (int) $customer->credit_days,
                $customer->price_type,
                $customer->status,
                optional($customer->created_at)->format('Y-m-d'),
            ];
        });
    }

    public function importCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle) ?: [];
        $created = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $data = array_combine($header, array_pad($row, count($header), null));
            if (!$data) {
                $errors[] = ['row' => $rowNumber, 'error' => 'Invalid CSV row.'];
                continue;
            }

            try {
                DB::transaction(function () use ($data, &$created) {
                    $payload = [
                        'customer_code' => $data['customer_code'] ?? null,
                        'customer_name' => $data['customer_name'] ?? null,
                        'customer_type' => $data['customer_type'] ?? 'retail',
                        'contact_person' => $data['contact_person'] ?? null,
                        'mobile' => $data['mobile'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'email' => $data['email'] ?? null,
                        'gstin' => $data['gstin'] ?? null,
                        'pan' => $data['pan'] ?? null,
                        'billing_address' => $data['billing_address'] ?? null,
                        'shipping_address' => $data['shipping_address'] ?? null,
                        'city' => $data['city'] ?? null,
                        'state_id' => $data['state_id'] ?? null,
                        'pincode' => $data['pincode'] ?? null,
                        'opening_balance' => $data['opening_balance'] ?? 0,
                        'opening_balance_type' => $data['balance_type'] ?? 'debit',
                        'credit_limit' => $data['credit_limit'] ?? null,
                        'credit_days' => $data['credit_days'] ?? null,
                        'price_type' => $data['price_list'] ?? 'retail',
                        'status' => $data['status'] ?? 'active',
                    ];

                    if (blank($payload['customer_name'])) {
                        throw ValidationException::withMessages(['customer_name' => 'Customer name is required.']);
                    }

                    $this->create($payload);
                    $created++;
                });
            } catch (\Throwable $exception) {
                $errors[] = ['row' => $rowNumber, 'error' => $exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : $exception->getMessage()];
            }
        }

        fclose($handle);

        if ($errors) {
            return ['message' => 'Import completed with row errors.', 'created' => $created, 'errors' => $errors];
        }

        return ['message' => 'Customers imported successfully.', 'created' => $created, 'errors' => []];
    }

    private function attributes(array $data): array
    {
        $attributes = [
            'customer_name' => $data['customer_name'],
            'customer_type' => $data['customer_type'],
            'contact_person' => $data['contact_person'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'normalized_mobile' => $data['normalized_mobile'] ?? null,
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'whatsapp_same_as_mobile' => (bool) ($data['whatsapp_same_as_mobile'] ?? true),
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'gstin' => $data['gstin'] ?? null,
            'pan' => $data['pan'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
            'shipping_address' => $data['shipping_address'] ?? null,
            'state_id' => $data['state_id'] ?? null,
            'city' => $data['city'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'opening_balance' => $data['opening_balance'] ?? 0,
            'opening_balance_type' => $data['opening_balance_type'] ?? null,
            'credit_limit' => $data['credit_limit'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'price_type' => $data['price_type'] ?? null,
            'status' => $data['status'],
        ];

        if (Schema::hasColumn('customers', 'blocked_reason')) {
            $attributes['blocked_reason'] = $data['status'] === 'blocked' ? ($data['blocked_reason'] ?? null) : null;
        }

        if (Schema::hasColumn('customers', 'blocked_at')) {
            $attributes['blocked_at'] = $data['status'] === 'blocked' ? now() : null;
        }

        if (Schema::hasColumn('customers', 'blocked_by')) {
            $attributes['blocked_by'] = $data['status'] === 'blocked' ? Auth::id() : null;
        }

        if (!empty($data['customer_code'])) {
            $attributes['customer_code'] = $data['customer_code'];
        }

        return $attributes;
    }

    private function nextCode(int $businessId): string
    {
        $prefix = 'CUS-';
        $last = Customer::query()
            ->where('business_id', $businessId)
            ->where('customer_code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('customer_code');
        $next = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function normalize(array $data): array
    {
        foreach (['customer_code', 'customer_name', 'contact_person', 'mobile', 'whatsapp_number', 'phone', 'email', 'gstin', 'pan', 'city', 'pincode'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = trim((string) $data[$field]);
            }
        }

        $data['customer_name'] = trim((string) ($data['customer_name'] ?? ''));
        $data['customer_type'] = $data['customer_type'] ?? 'retail';
        $data['email'] = !empty($data['email']) ? strtolower($data['email']) : null;
        $data['gstin'] = !empty($data['gstin']) ? strtoupper($data['gstin']) : null;
        $data['pan'] = !empty($data['pan']) ? strtoupper($data['pan']) : null;
        $data['mobile'] = $this->mobileNumbers->normalize($data['mobile'] ?? null);
        $data['normalized_mobile'] = $data['mobile'];
        $data['whatsapp_same_as_mobile'] = filter_var($data['whatsapp_same_as_mobile'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $data['whatsapp_number'] = $data['whatsapp_same_as_mobile']
            ? $data['mobile']
            : $this->mobileNumbers->normalize($data['whatsapp_number'] ?? null);
        $data['opening_balance_type'] = $data['opening_balance_type'] ?? 'debit';
        $data['status'] = $data['status'] ?? 'active';

        return $data;
    }

    private function validateDuplicatePolicy(int $businessId, array $data, ?int $ignoreId = null): void
    {
        if (!empty($data['normalized_mobile'])) {
            $exists = Customer::withTrashed()
                ->where('business_id', $businessId)
                ->where('normalized_mobile', $data['normalized_mobile'])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['mobile' => 'Another customer already uses this mobile number.']);
            }
        }

        if (!empty($data['gstin'])) {
            $exists = Customer::withTrashed()->where('business_id', $businessId)->where('gstin', $data['gstin'])->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
            if ($exists) {
                throw ValidationException::withMessages(['gstin' => 'Another customer already uses this GSTIN.']);
            }
        }
    }

    public function possibleDuplicates(array $data, ?int $ignoreId = null)
    {
        $businessId = AppController::businessId();
        $data = $this->normalize($data);

        return Customer::query()
            ->where('business_id', $businessId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function (Builder $q) use ($data) {
                foreach (['mobile', 'email', 'gstin', 'pan'] as $field) {
                    if (!empty($data[$field])) {
                        $q->orWhere($field, $data[$field]);
                    }
                }
                if (!empty($data['normalized_mobile'])) {
                    $q->orWhere('normalized_mobile', $data['normalized_mobile']);
                }
                if (!empty($data['customer_name'])) {
                    $q->orWhere(function (Builder $name) use ($data) {
                        $name->where('customer_name', 'like', '%' . $data['customer_name'] . '%')
                            ->when(!empty($data['city']), fn ($city) => $city->where('city', $data['city']));
                    });
                }
            })
            ->limit(5)
            ->get()
            ->map(fn (Customer $customer) => $this->present($customer));
    }

    private function postOpeningBalance(Customer $customer): void
    {
        $amount = round((float) $customer->opening_balance, 2);
        if ($amount <= 0 || !Schema::hasTable('journal_vouchers') || !Schema::hasTable('business_account_settings')) {
            return;
        }

        $settings = BusinessAccountSetting::query()->where('business_id', $customer->business_id)->first();
        $receivable = $settings?->accounts_receivable_id;
        $equity = Account::query()->where('business_id', $customer->business_id)->whereIn('account_type', ['equity', 'capital'])->value('id');

        if (!$receivable || !$equity) {
            throw ValidationException::withMessages(['opening_balance' => 'Accounting accounts are not configured for customer opening balance.']);
        }

        $entries = $customer->opening_balance_type === 'credit'
            ? [
                ['account_id' => $equity, 'debit_amount' => $amount, 'credit_amount' => 0, 'narration' => 'Customer credit opening balance'],
                ['account_id' => $receivable, 'debit_amount' => 0, 'credit_amount' => $amount, 'customer_id' => $customer->id, 'narration' => 'Customer credit opening balance'],
            ]
            : [
                ['account_id' => $receivable, 'debit_amount' => $amount, 'credit_amount' => 0, 'customer_id' => $customer->id, 'narration' => 'Customer debit opening balance'],
                ['account_id' => $equity, 'debit_amount' => 0, 'credit_amount' => $amount, 'narration' => 'Customer debit opening balance'],
            ];

        $journal = app(AccountingPostingService::class)->createJournalVoucher([
            'business_id' => $customer->business_id,
            'voucher_type' => 'opening_balance',
            'voucher_date' => now()->toDateString(),
            'reference_type' => Customer::class,
            'reference_id' => $customer->id,
            'reference_number' => $customer->customer_code,
            'narration' => 'Customer opening balance',
            'status' => 'approved',
            'is_system_generated' => true,
            'entries' => $entries,
        ]);

        if (Schema::hasColumn('customers', 'opening_balance_voucher_id')) {
            $customer->update(['opening_balance_voucher_id' => $journal->id]);
        }
    }

    private function query(array $filters = []): Builder
    {
        $businessId = AppController::businessId();
        $sort = $this->sort($filters);

        return Customer::query()
            ->where('business_id', $businessId)
            ->when(($filters['status'] ?? '') === 'deleted', fn (Builder $q) => $q->onlyTrashed())
            ->when(!empty($filters['status']) && ($filters['status'] ?? '') !== 'deleted', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['type']), fn (Builder $q) => $q->where('customer_type', $filters['type']))
            ->when(!empty($filters['state_id']), fn (Builder $q) => $q->where('state_id', $filters['state_id']))
            ->when(!empty($filters['price_type']), fn (Builder $q) => $q->where('price_type', $filters['price_type']))
            ->when(!empty($filters['crm_status']), fn (Builder $q) => $q->whereIn('id', $this->crmCustomerIds($businessId, $filters['crm_status'])))
            ->when(!empty($filters['created_from']), fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['created_from']))
            ->when(!empty($filters['created_to']), fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['created_to']))
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $search = '%' . trim((string) $filters['search']) . '%';
                $q->where(function (Builder $query) use ($search) {
                    $query->where('customer_name', 'like', $search)
                        ->orWhere('customer_code', 'like', $search)
                        ->orWhere('contact_person', 'like', $search)
                        ->orWhere('mobile', 'like', $search)
                        ->orWhere('whatsapp_number', 'like', $search)
                        ->orWhere('normalized_mobile', 'like', $search)
                        ->orWhere('phone', 'like', $search)
                        ->orWhere('email', 'like', $search)
                        ->orWhere('gstin', 'like', $search)
                        ->orWhere('pan', 'like', $search)
                        ->orWhere('city', 'like', $search);
                });
            })
            ->orderBy($sort['column'], $sort['direction']);
    }

    private function sort(array $filters): array
    {
        $map = [
            'code' => 'customer_code',
            'name' => 'customer_name',
            'credit_limit' => 'credit_limit',
            'created' => 'created_at',
            'status' => 'status',
        ];
        $key = $filters['sort'] ?? 'name';
        return ['column' => $map[$key] ?? 'customer_name', 'direction' => ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
    }

    private function crmCustomerIds(int $businessId, string $status): array
    {
        $classifier = app(CustomerClassificationService::class);

        return Customer::query()
            ->where('business_id', $businessId)
            ->get(['id', 'business_id'])
            ->filter(fn (Customer $customer) => $classifier->classify($customer) === $status)
            ->pluck('id')
            ->all();
    }

    public function present(Customer $customer): array
    {
        $summary = $this->financialSummary($customer);

        return [
            'id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'customer_name' => $customer->customer_name,
            'customer_type' => $customer->customer_type,
            'contact_person' => $customer->contact_person,
            'mobile' => $customer->mobile,
            'normalized_mobile' => $customer->normalized_mobile ?? null,
            'whatsapp_number' => $customer->whatsapp_number ?? null,
            'whatsapp_same_as_mobile' => (bool) ($customer->whatsapp_same_as_mobile ?? true),
            'phone' => $customer->phone,
            'email' => $customer->email,
            'gstin' => $customer->gstin,
            'pan' => $customer->pan,
            'billing_address' => $customer->billing_address,
            'shipping_address' => $customer->shipping_address,
            'state_id' => $customer->state_id,
            'state' => $this->stateName($customer->state_id),
            'city' => $customer->city,
            'pincode' => $customer->pincode,
            'opening_balance' => (float) $customer->opening_balance,
            'opening_balance_type' => $customer->opening_balance_type ?: 'debit',
            'credit_limit' => (float) $customer->credit_limit,
            'credit_days' => (int) $customer->credit_days,
            'price_type' => $customer->price_type,
            'status' => $customer->status,
            'blocked_reason' => $customer->blocked_reason ?? null,
            'deleted_at' => optional($customer->deleted_at)->format('Y-m-d H:i:s'),
            'created_at' => optional($customer->created_at)->format('Y-m-d'),
            'current_outstanding' => $summary['current_outstanding'],
            'available_credit' => $summary['available_credit'],
            'last_sale_date' => $summary['last_sale_date'],
            'crm_summary' => $this->analytics->summary($customer),
        ];
    }

    private function financialSummary(Customer $customer): array
    {
        $outstanding = $this->ledgerBalance($customer);
        $creditLimit = (float) ($customer->credit_limit ?? 0);

        return [
            'opening_balance' => (float) $customer->opening_balance,
            'current_outstanding' => round($outstanding, 2),
            'overdue_amount' => round(collect($this->outstandingRows($customer))->where('days_overdue', '>', 0)->sum('balance'), 2),
            'unapplied_credit' => max(0, round($outstanding * -1, 2)),
            'credit_limit' => $creditLimit,
            'available_credit' => $creditLimit > 0 ? round($creditLimit - max(0, $outstanding), 2) : null,
            'last_sale_date' => SalesVoucher::query()->where('business_id', $customer->business_id)->where('customer_id', $customer->id)->whereIn('status', ['approved', 'confirmed'])->max('invoice_date'),
        ];
    }

    private function ledgerBalance(Customer $customer): float
    {
        if (!Schema::hasTable('journal_entries')) {
            return (float) $customer->opening_balance;
        }

        $row = DB::table('journal_entries')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_entries.journal_voucher_id')
            ->where('journal_entries.business_id', $customer->business_id)
            ->where('journal_entries.customer_id', $customer->id)
            ->whereIn('journal_vouchers.status', ['posted', 'approved'])
            ->selectRaw('SUM(journal_entries.debit_amount) as debit, SUM(journal_entries.credit_amount) as credit')
            ->first();

        return round((float) ($row->debit ?? 0) - (float) ($row->credit ?? 0), 2);
    }

    private function outstandingRows(Customer $customer): array
    {
        return SalesVoucher::query()
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['approved', 'confirmed'])
            ->get()
            ->map(function (SalesVoucher $invoice) {
                $allocated = Schema::hasTable('ledger_allocations')
                    ? DB::table('ledger_allocations')->where('business_id', $invoice->business_id)->where('reference_type', SalesVoucher::class)->where('reference_id', $invoice->id)->sum('allocated_amount')
                    : (float) $invoice->paid_amount;
                $balance = round((float) $invoice->grand_total - (float) $allocated, 2);
                $due = $invoice->due_date ?: $invoice->invoice_date;
                $days = $due ? max(0, now()->startOfDay()->diffInDays($due, false) * -1) : 0;

                return [
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => optional($invoice->invoice_date)->format('Y-m-d'),
                    'due_date' => optional($due)->format('Y-m-d'),
                    'invoice_total' => (float) $invoice->grand_total,
                    'paid' => (float) $allocated,
                    'balance' => $balance,
                    'days_overdue' => $days,
                    'ageing_bucket' => $this->bucket($days),
                ];
            })
            ->filter(fn (array $row) => $row['balance'] > 0)
            ->values()
            ->all();
    }

    private function ageing(Customer $customer): array
    {
        $buckets = ['Current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($this->outstandingRows($customer) as $row) {
            $buckets[$row['ageing_bucket']] += $row['balance'];
        }

        return array_map(fn ($amount) => round($amount, 2), $buckets);
    }

    private function bucket(int $days): string
    {
        return match (true) {
            $days <= 0 => 'Current',
            $days <= 30 => '1-30',
            $days <= 60 => '31-60',
            $days <= 90 => '61-90',
            default => '90+',
        };
    }

    private function returns(Customer $customer, int $limit): array
    {
        return SalesReturnVoucher::query()->where('business_id', $customer->business_id)->where('customer_id', $customer->id)->latest('return_date')->limit($limit)->get()->map(fn ($row) => [
            'credit_note_number' => $row->credit_note_number,
            'return_date' => optional($row->return_date)->format('Y-m-d'),
            'grand_total' => (float) $row->grand_total,
            'status' => $row->status,
        ])->values()->all();
    }

    private function payments(Customer $customer, int $limit): array
    {
        return ReceiptVoucher::query()->where('business_id', $customer->business_id)->where('customer_id', $customer->id)->latest('receipt_date')->limit($limit)->get()->map(fn ($row) => [
            'voucher_number' => $row->voucher_number,
            'receipt_date' => optional($row->receipt_date)->format('Y-m-d'),
            'amount' => (float) $row->amount,
            'status' => $row->status,
        ])->values()->all();
    }

    private function hasTransactions(Customer $customer): bool
    {
        return SalesVoucher::query()->where('business_id', $customer->business_id)->where('customer_id', $customer->id)->exists()
            || SalesReturnVoucher::query()->where('business_id', $customer->business_id)->where('customer_id', $customer->id)->exists()
            || ReceiptVoucher::query()->where('business_id', $customer->business_id)->where('customer_id', $customer->id)->exists()
            || (Schema::hasTable('journal_entries') && DB::table('journal_entries')->where('business_id', $customer->business_id)->where('customer_id', $customer->id)->exists());
    }

    private function states()
    {
        if (!Schema::hasTable('states')) {
            return collect();
        }

        return DB::table('states')->orderBy('name')->get(['id', 'name', 'code']);
    }

    private function cities()
    {
        if (!Schema::hasTable('cities')) {
            return collect();
        }

        $columns = Schema::getColumnListing('cities');
        $select = array_values(array_intersect(['id', 'state_id', 'name', 'code'], $columns));
        $query = DB::table('cities');

        if (in_array('status', $columns, true)) {
            $query->where(function ($query) {
                $query->whereNull('status')->orWhere('status', 'active')->orWhere('status', 1);
            });
        }

        return $query->orderBy('name')->get($select);
    }

    private function stateName($stateId): ?string
    {
        if (!$stateId || !Schema::hasTable('states')) {
            return null;
        }

        return DB::table('states')->where('id', $stateId)->value('name');
    }

    private function assertBusiness(Customer $customer): void
    {
        abort_unless((int) $customer->business_id === AppController::businessId(), 404);
    }
}
