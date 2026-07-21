<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class SalesCalculationService
{
    public function calculateLineSubtotal(array $item): float
    {
        return round((float) ($item['quantity'] ?? 0) * (float) ($item['selling_rate'] ?? 0), 2);
    }

    public function calculateLineDiscount(float $amount, ?string $type, float $value): float
    {
        if ($value <= 0 || !$type) {
            return 0.0;
        }

        if ($type === 'percentage') {
            return round($amount * min($value, 100) / 100, 2);
        }

        return round(min($amount, $value), 2);
    }

    public function calculateInclusiveTax(float $amount, float $rate): array
    {
        if ($rate <= 0) {
            return ['taxable_amount' => round($amount, 2), 'tax_amount' => 0.0];
        }

        $taxable = round($amount * 100 / (100 + $rate), 2);

        return ['taxable_amount' => $taxable, 'tax_amount' => round($amount - $taxable, 2)];
    }

    public function calculateExclusiveTax(float $amount, float $rate): float
    {
        return round($amount * $rate / 100, 2);
    }

    public function calculateLineTax(array $item, string $taxType, string $invoiceType = 'tax_invoice'): array
    {
        $gross = $this->calculateLineSubtotal($item);
        $discount = $this->calculateLineDiscount($gross, $item['discount_type'] ?? null, (float) ($item['discount_value'] ?? 0));
        $afterDiscount = max(0, $gross - $discount);
        $taxInclusive = (bool) ($item['tax_inclusive'] ?? false);
        $gstRate = in_array($taxType, ['exempt', 'nil_rated'], true) || $invoiceType === 'bill_of_supply' ? 0 : (float) ($item['gst_rate'] ?? 0);
        $cessRate = in_array($taxType, ['exempt', 'nil_rated'], true) || $invoiceType === 'bill_of_supply' ? 0 : (float) ($item['cess_rate'] ?? 0);
        $combinedRate = $gstRate + $cessRate;
        $inclusive = $taxInclusive ? $this->calculateInclusiveTax($afterDiscount, $combinedRate) : null;
        $taxable = $inclusive ? $inclusive['taxable_amount'] : $afterDiscount;
        $tax = $inclusive ? $inclusive['tax_amount'] : $this->calculateExclusiveTax($taxable, $combinedRate);
        $gstAmount = $combinedRate > 0 ? round($tax * ($gstRate / $combinedRate), 2) : 0;
        $cessAmount = round($tax - $gstAmount, 2);

        return [
            'gross_amount' => $gross,
            'discount_amount' => $discount,
            'taxable_amount' => round($taxable, 2),
            'gst_rate' => $gstRate,
            'cgst_rate' => $taxType === 'intrastate' ? round($gstRate / 2, 2) : 0,
            'sgst_rate' => $taxType === 'intrastate' ? round($gstRate / 2, 2) : 0,
            'igst_rate' => $taxType === 'interstate' ? $gstRate : 0,
            'cgst_amount' => $taxType === 'intrastate' ? round($gstAmount / 2, 2) : 0,
            'sgst_amount' => $taxType === 'intrastate' ? round($gstAmount / 2, 2) : 0,
            'igst_amount' => $taxType === 'interstate' ? $gstAmount : 0,
            'cess_rate' => $cessRate,
            'cess_amount' => $cessAmount,
            'line_total' => round($taxable + ($taxType === 'intrastate' ? $gstAmount : 0) + ($taxType === 'interstate' ? $gstAmount : 0) + $cessAmount, 2),
        ];
    }

    public function calculateVoucherDiscount(float $taxable, ?string $type, float $value): float
    {
        return $this->calculateLineDiscount($taxable, $type, $value);
    }

    public function calculateVoucherTotals(array $data): array
    {
        $items = [];
        $subtotal = $itemDiscount = $taxable = $cgst = $sgst = $igst = $cess = 0.0;

        foreach ($data['items'] as $item) {
            $line = $this->calculateLineTax($item, $data['tax_type'], $data['invoice_type']);
            $subtotal += $line['gross_amount'];
            $itemDiscount += $line['discount_amount'];
            $taxable += $line['taxable_amount'];
            $cgst += $line['cgst_amount'];
            $sgst += $line['sgst_amount'];
            $igst += $line['igst_amount'];
            $cess += $line['cess_amount'];
            $items[] = array_merge($item, $line);
        }

        $voucherDiscount = $this->calculateVoucherDiscount($taxable, $data['voucher_discount_type'] ?? null, (float) ($data['voucher_discount_value'] ?? 0));
        $taxableAfterDiscount = max(0, round($taxable - $voucherDiscount, 2));
        $beforeRound = round($taxableAfterDiscount + $cgst + $sgst + $igst + $cess + (float) ($data['shipping_amount'] ?? 0) + (float) ($data['other_charges'] ?? 0), 2);
        $roundOff = $this->calculateRoundOff($beforeRound);
        $grand = round($beforeRound + $roundOff, 2);
        $paid = collect($data['payments'] ?? [])->sum(fn ($payment) => (float) ($payment['amount'] ?? 0));
        $change = $this->calculateChangeReturned($grand, $paid);
        $paidForInvoice = min($paid, $grand);

        return [
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'item_discount_amount' => round($itemDiscount, 2),
            'voucher_discount_amount' => round($voucherDiscount, 2),
            'taxable_amount' => $taxableAfterDiscount,
            'cgst_amount' => round($cgst, 2),
            'sgst_amount' => round($sgst, 2),
            'igst_amount' => round($igst, 2),
            'cess_amount' => round($cess, 2),
            'round_off' => $roundOff,
            'grand_total' => $grand,
            'paid_amount' => $paidForInvoice,
            'balance_amount' => round(max(0, $grand - $paid), 2),
            'change_returned' => $change,
            'payment_status' => $this->paymentStatus($grand, $paid),
        ];
    }

    public function determineTaxType(?int $businessStateId, ?int $placeOfSupplyStateId): string
    {
        if (!$businessStateId || !$placeOfSupplyStateId) {
            return 'intrastate';
        }

        return (int) $businessStateId === (int) $placeOfSupplyStateId ? 'intrastate' : 'interstate';
    }

    public function calculateRoundOff(float $amount): float
    {
        return round(round($amount) - $amount, 2);
    }

    public function validateSaleTotals(array $totals): void
    {
        if ((float) $totals['grand_total'] < 0) {
            throw ValidationException::withMessages(['grand_total' => 'Invoice total cannot be negative.']);
        }
    }

    public function calculateChangeReturned(float $grandTotal, float $paidAmount): float
    {
        return round(max(0, $paidAmount - $grandTotal), 2);
    }

    private function paymentStatus(float $grandTotal, float $paidAmount): string
    {
        if ($paidAmount <= 0) {
            return 'unpaid';
        }

        if ($paidAmount > $grandTotal) {
            return 'overpaid';
        }

        return $paidAmount >= $grandTotal ? 'paid' : 'partial';
    }
}
