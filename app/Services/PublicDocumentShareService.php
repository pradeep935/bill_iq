<?php

namespace App\Services;

use App\Models\SalesVoucher;
use App\Models\Quotation;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicDocumentShareService
{
    public function ensureSalesInvoiceToken(SalesVoucher $voucher): string
    {
        if (!in_array($voucher->status, CustomerClassificationService::VALID_SALE_STATUSES, true)) {
            throw ValidationException::withMessages(['invoice' => 'Only saved posted invoices can be shared.']);
        }

        if ($voucher->public_token && $voucher->public_share_enabled) {
            return $voucher->public_token;
        }

        do {
            $token = Str::random(48);
        } while (SalesVoucher::query()->where('public_token', $token)->exists());

        $voucher->update([
            'public_token' => $token,
            'public_share_enabled' => true,
            'public_token_created_at' => now(),
        ]);

        return $token;
    }

    public function salesInvoiceUrl(SalesVoucher $voucher): string
    {
        return url('/i/' . $this->ensureSalesInvoiceToken($voucher));
    }

    public function salesInvoicePdfUrl(SalesVoucher $voucher): string
    {
        return url('/i/' . $this->ensureSalesInvoiceToken($voucher) . '/pdf');
    }

    public function resolveSalesInvoice(string $token): SalesVoucher
    {
        return SalesVoucher::query()
            ->with(['customer', 'branch', 'warehouse', 'salesperson', 'creator', 'items.product', 'items.variant', 'items.batch', 'payments.method'])
            ->where('public_token', $token)
            ->where('public_share_enabled', true)
            ->whereIn('status', CustomerClassificationService::VALID_SALE_STATUSES)
            ->firstOrFail();
    }

    public function ensureQuotationToken(Quotation $quotation): string
    {
        if (!in_array($quotation->status, ['sent', 'viewed', 'accepted'], true)) {
            throw ValidationException::withMessages(['quotation' => 'Only sent, viewed or accepted quotations can be shared.']);
        }

        if ($quotation->public_token && $quotation->public_share_enabled) {
            return $quotation->public_token;
        }

        do {
            $token = Str::random(48);
        } while (Quotation::query()->where('public_token', $token)->exists());

        $quotation->update([
            'public_token' => $token,
            'public_share_enabled' => true,
            'public_token_created_at' => now(),
        ]);

        return $token;
    }

    public function quotationUrl(Quotation $quotation): string
    {
        return url('/q/' . $this->ensureQuotationToken($quotation));
    }

    public function resolveQuotation(string $token): Quotation
    {
        return Quotation::query()
            ->with(['customer', 'branch', 'items.product'])
            ->where('public_token', $token)
            ->where('public_share_enabled', true)
            ->whereIn('status', ['sent', 'viewed', 'accepted'])
            ->firstOrFail();
    }
}
