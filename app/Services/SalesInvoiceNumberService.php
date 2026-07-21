<?php

namespace App\Services;

use App\Models\SalesVoucher;

class SalesInvoiceNumberService
{
    public function next(int $businessId, ?int $branchId = null): array
    {
        $year = date('Y');
        $voucherPrefix = 'SAL-' . $year . '-';
        $invoicePrefix = 'INV-' . $year . '-';

        return [
            'voucher_number' => $this->nextForPrefix($businessId, 'voucher_number', $voucherPrefix),
            'invoice_number' => $this->nextForPrefix($businessId, 'invoice_number', $invoicePrefix),
        ];
    }

    public function nextCreditNote(int $businessId, ?int $branchId = null): array
    {
        $year = date('Y');
        $voucherPrefix = 'SR-' . $year . '-';
        $creditPrefix = 'CN-' . $year . '-';

        return [
            'voucher_number' => $this->nextReturnForPrefix($businessId, 'voucher_number', $voucherPrefix),
            'credit_note_number' => $this->nextReturnForPrefix($businessId, 'credit_note_number', $creditPrefix),
        ];
    }

    private function nextForPrefix(int $businessId, string $column, string $prefix): string
    {
        $last = SalesVoucher::query()
            ->where('business_id', $businessId)
            ->where($column, 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value($column);
        $next = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function nextReturnForPrefix(int $businessId, string $column, string $prefix): string
    {
        $last = \App\Models\SalesReturnVoucher::query()
            ->where('business_id', $businessId)
            ->where($column, 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value($column);
        $next = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
