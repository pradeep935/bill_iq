<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentShareLog;
use App\Services\MobileNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Customers who owe money, with one-click WhatsApp reminders (wa.me deep link, same as invoice sharing). */
class PaymentReminderController extends Controller
{
    public function index()
    {
        if ($redirect = AppController::guardPage('payment-reminders')) return $redirect;

        return Inertia::render('Sales/PaymentReminders', ['page' => 'payment-reminders', 'title' => 'Payment Reminders']);
    }

    private function dueInvoices(int $businessId, ?int $customerId = null)
    {
        return DB::table('sales_vouchers')
            ->where('business_id', $businessId)
            ->where('status', 'approved')
            ->where('balance_amount', '>', 0.009)
            ->whereNotNull('customer_id')
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->orderBy('invoice_date')
            ->get(['id', 'customer_id', 'invoice_number', 'invoice_date', 'due_date', 'grand_total', 'balance_amount']);
    }

    public function data(Request $request)
    {
        abort_unless(AppController::canOpen('payment-reminders'), 403);
        $businessId = AppController::businessId();
        $today = now()->startOfDay();
        $invoices = $this->dueInvoices($businessId)->groupBy('customer_id');
        $customers = Customer::query()->where('business_id', $businessId)->whereIn('id', $invoices->keys())->get()->keyBy('id');
        $mobiles = app(MobileNumberService::class);

        $rows = $invoices->map(function ($list, $customerId) use ($customers, $today, $mobiles) {
            $c = $customers[$customerId] ?? null;
            $number = $c ? ($c->whatsapp_number ?: $c->mobile) : null;
            $overdue = 0.0; $oldest = 0;
            foreach ($list as $inv) {
                $due = \Carbon\Carbon::parse($inv->due_date ?: $inv->invoice_date)->startOfDay();
                if ($due->lt($today)) {
                    $overdue += (float) $inv->balance_amount;
                    $oldest = max($oldest, $due->diffInDays($today));
                }
            }
            return [
                'customer_id' => (int) $customerId,
                'name' => $c->customer_name ?? $c->name ?? 'Customer #' . $customerId,
                'mobile' => $number,
                'has_whatsapp' => $mobiles->isValidIndianMobile($number),
                'invoices' => $list->count(),
                'total_due' => round((float) $list->sum('balance_amount'), 2),
                'overdue_due' => round($overdue, 2),
                'oldest_overdue_days' => $oldest,
            ];
        })->sortByDesc('overdue_due')->values();

        return response()->json([
            'rows' => $rows,
            'totals' => ['total_due' => round($rows->sum('total_due'), 2), 'overdue_due' => round($rows->sum('overdue_due'), 2), 'customers' => $rows->count()],
        ]);
    }

    public function whatsapp(Request $request, int $customer, MobileNumberService $mobiles)
    {
        abort_unless(AppController::canOpen('payment-reminders'), 403);
        $businessId = AppController::businessId();
        $c = Customer::query()->where('business_id', $businessId)->findOrFail($customer);
        $data = $request->validate(['whatsapp_number' => ['nullable', 'string', 'max:30']]);

        $number = $data['whatsapp_number'] ?? ($c->whatsapp_number ?: $c->mobile);
        if (!$mobiles->isValidIndianMobile($number)) {
            throw ValidationException::withMessages(['whatsapp_number' => 'A valid WhatsApp number is required for this customer.']);
        }

        $invoices = $this->dueInvoices($businessId, $c->id);
        abort_if($invoices->isEmpty(), 422, 'This customer has no pending balance.');

        $business = Company::query()->find($businessId)?->name ?: 'BillIQ';
        $lines = $invoices->take(10)->map(fn ($i) => '- ' . $i->invoice_number . ' (' . \Carbon\Carbon::parse($i->invoice_date)->format('d M Y') . '): Rs. ' . number_format((float) $i->balance_amount, 2))->implode("\n");
        $more = $invoices->count() > 10 ? "\n...and " . ($invoices->count() - 10) . ' more invoices' : '';
        $name = $c->customer_name ?: $c->name ?: 'Customer';
        $message = "Hi {$name},\n\nThis is a friendly reminder from {$business}. Your outstanding balance is Rs. "
            . number_format((float) $invoices->sum('balance_amount'), 2) . ".\n\nPending invoices:\n{$lines}{$more}\n\nKindly arrange the payment at the earliest. Please ignore this message if already paid.\n\nThank you,\n{$business}";

        $wa = $mobiles->waMeNumber($number);
        DocumentShareLog::query()->create([
            'business_id' => $businessId, 'customer_id' => $c->id, 'document_type' => 'payment_reminder', 'channel' => 'whatsapp',
            'recipient' => $wa, 'status' => 'initiated', 'sent_by' => Auth::id(), 'message' => $message, 'provider' => 'deep_link',
        ]);

        return response()->json(['url' => 'https://wa.me/' . $wa . '?text=' . rawurlencode($message), 'recipient' => $wa, 'message' => $message]);
    }
}
