<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PurchaseCalculationService
{
    public function calculateLineTax(array $item, string $taxType): array
    {
        $quantity = (float) ($item['quantity'] ?? 0);
        $rate = (float) ($item['purchase_rate'] ?? 0);
        $gross = round($quantity * $rate, 2);
        $discount = $this->calculateDiscount($gross, $item['discount_type'] ?? null, (float) ($item['discount_value'] ?? 0));
        $afterDiscount = max(0, $gross - $discount);
        $gstRate = (float) ($item['gst_rate'] ?? 0);
        $cessRate = (float) ($item['cess_rate'] ?? 0);
        $taxInclusive = (bool) ($item['tax_inclusive'] ?? false);
        $taxBase = $taxInclusive
            ? $this->calculateInclusiveTax($afterDiscount, $gstRate + $cessRate)['taxable_amount']
            : $afterDiscount;
        $tax = $taxInclusive
            ? $this->calculateInclusiveTax($afterDiscount, $gstRate + $cessRate)['tax_amount']
            : $this->calculateExclusiveTax($taxBase, $gstRate + $cessRate);
        $gstAmount = $gstRate + $cessRate > 0 ? round($tax * ($gstRate / ($gstRate + $cessRate)), 2) : 0;
        $cessAmount = round($tax - $gstAmount, 2);

        $cgstRate = $taxType === 'intrastate' ? round($gstRate / 2, 2) : 0;
        $sgstRate = $taxType === 'intrastate' ? round($gstRate / 2, 2) : 0;
        $igstRate = $taxType === 'interstate' ? $gstRate : 0;
        $cgstAmount = $taxType === 'intrastate' ? round($gstAmount / 2, 2) : 0;
        $sgstAmount = $taxType === 'intrastate' ? round($gstAmount / 2, 2) : 0;
        $igstAmount = $taxType === 'interstate' ? $gstAmount : 0;

        if ($taxType === 'exempt') {
            $cgstRate = $sgstRate = $igstRate = 0;
            $cgstAmount = $sgstAmount = $igstAmount = $cessAmount = 0;
            $taxBase = $afterDiscount;
        }

        return [
            'gross_amount' => $gross,
            'discount_amount' => $discount,
            'taxable_amount' => round($taxBase, 2),
            'gst_rate' => $taxType === 'exempt' ? 0 : $gstRate,
            'cgst_rate' => $cgstRate,
            'sgst_rate' => $sgstRate,
            'igst_rate' => $igstRate,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => $igstAmount,
            'cess_rate' => $taxType === 'exempt' ? 0 : $cessRate,
            'cess_amount' => $cessAmount,
            'line_total' => round($taxBase + $cgstAmount + $sgstAmount + $igstAmount + $cessAmount, 2),
        ];
    }

    public function calculateDiscount(float $amount, ?string $type, float $value): float
    {
        if ($value <= 0 || !$type) {
            return 0.0;
        }

        if ($type === 'percentage') {
            return round($amount * min($value, 100) / 100, 2);
        }

        return round(min($amount, $value), 2);
    }

    public function calculateVoucherTotals(array $data): array
    {
        $taxType = $data['tax_type'];
        $items = [];
        $subtotal = $taxable = $cgst = $sgst = $igst = $cess = 0.0;

        foreach ($data['items'] as $item) {
            $line = $this->calculateLineTax($item, $taxType);
            $subtotal += $line['gross_amount'];
            $taxable += $line['taxable_amount'];
            $cgst += $line['cgst_amount'];
            $sgst += $line['sgst_amount'];
            $igst += $line['igst_amount'];
            $cess += $line['cess_amount'];
            $items[] = array_merge($item, $line);
        }

        $voucherDiscount = $this->calculateDiscount($taxable, $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0));
        $taxableAfterVoucherDiscount = max(0, round($taxable - $voucherDiscount, 2));
        $grossTotal = round($taxableAfterVoucherDiscount + $cgst + $sgst + $igst + $cess, 2);
        $roundedTotal = round($grossTotal);
        $roundOff = round($roundedTotal - $grossTotal, 2);
        $paid = round((float) ($data['paid_amount'] ?? 0), 2);
        $balance = round(max(0, $roundedTotal - $paid), 2);

        return [
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'discount_amount' => $voucherDiscount,
            'taxable_amount' => $taxableAfterVoucherDiscount,
            'cgst_amount' => round($cgst, 2),
            'sgst_amount' => round($sgst, 2),
            'igst_amount' => round($igst, 2),
            'cess_amount' => round($cess, 2),
            'round_off' => $roundOff,
            'grand_total' => $roundedTotal,
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'payment_status' => $this->paymentStatus($roundedTotal, $paid),
        ];
    }

    public function determineTaxType(?int $businessStateId, ?int $supplierStateId): string
    {
        if (!$businessStateId || !$supplierStateId) {
            return 'intrastate';
        }

        return (int) $businessStateId === (int) $supplierStateId ? 'intrastate' : 'interstate';
    }

    public function calculateInclusiveTax(float $amount, float $rate): array
    {
        if ($rate <= 0) {
            return ['taxable_amount' => round($amount, 2), 'tax_amount' => 0.0];
        }

        $taxable = round($amount * 100 / (100 + $rate), 2);

        return [
            'taxable_amount' => $taxable,
            'tax_amount' => round($amount - $taxable, 2),
        ];
    }

    public function calculateExclusiveTax(float $amount, float $rate): float
    {
        return round($amount * $rate / 100, 2);
    }

    public function validatePurchaseTotals(array $totals): void
    {
        if ((float) $totals['paid_amount'] > (float) $totals['grand_total']) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Paid amount cannot be greater than grand total.',
            ]);
        }
    }

    private function paymentStatus(float $grandTotal, float $paid): string
    {
        if ($paid <= 0) {
            return 'unpaid';
        }

        if ($paid >= $grandTotal) {
            return 'paid';
        }

        return 'partial';
    }
}
