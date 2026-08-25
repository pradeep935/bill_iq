<?php

namespace App\Services;

use App\Models\DocumentShareLog;
use App\Models\Quotation;
use App\Models\SalesVoucher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class WhatsAppShareService
{
    public function __construct(
        private MobileNumberService $mobileNumbers,
        private PublicDocumentShareService $publicShares
    ) {
    }

    public function salesInvoiceShare(SalesVoucher $voucher, ?string $recipient = null): array
    {
        $number = $recipient
            ?: ($voucher->customer?->whatsapp_number ?: $voucher->customer?->mobile ?: $voucher->customer_mobile_snapshot);
        if (!$this->mobileNumbers->isValidIndianMobile($number)) {
            throw ValidationException::withMessages(['whatsapp_number' => 'Valid WhatsApp number is required.']);
        }

        $waNumber = $this->mobileNumbers->waMeNumber($number);

        $publicUrl = $this->publicShares->salesInvoiceUrl($voucher);
        $pdfUrl = $this->publicShares->salesInvoicePdfUrl($voucher);
        $businessName = $voucher->branch?->name ?: 'BillIQ';
        $customerName = $voucher->customer_name_snapshot ?: $voucher->customer?->customer_name ?: 'Customer';
        $message = $this->salesInvoiceMessage($customerName, $businessName, $voucher, $pdfUrl);
        $url = 'https://wa.me/' . $waNumber . '?text=' . rawurlencode($message);

        DocumentShareLog::query()->create([
            'business_id' => $voucher->business_id,
            'customer_id' => $voucher->customer_id,
            'sales_voucher_id' => $voucher->id,
            'document_type' => $voucher->invoice_type ?: 'sales_invoice',
            'channel' => 'whatsapp',
            'recipient' => $waNumber,
            'status' => 'initiated',
            'sent_by' => Auth::id(),
            'message' => $message,
            'provider' => 'deep_link_pdf',
        ]);

        return [
            'url' => $url,
            'recipient' => $waNumber,
            'message' => $message,
            'public_url' => $publicUrl,
            'attachment_url' => $pdfUrl,
            'attachment_filename' => $this->invoiceFilename($voucher),
            'status' => 'initiated',
            'provider' => 'deep_link_pdf',
        ];
    }

    public function quotationShare(Quotation $quotation, ?string $recipient = null): array
    {
        $number = $recipient ?: ($quotation->customer?->whatsapp_number ?: $quotation->customer?->mobile);
        if (!$this->mobileNumbers->isValidIndianMobile($number)) {
            throw ValidationException::withMessages(['whatsapp_number' => 'Valid WhatsApp number is required.']);
        }

        $waNumber = $this->mobileNumbers->waMeNumber($number);

        $publicUrl = $this->publicShares->quotationUrl($quotation);
        $businessName = $quotation->branch?->name ?: 'BillIQ';
        $customerName = $quotation->customer?->customer_name ?: 'Customer';
        $message = $this->quotationMessage($customerName, $businessName, $quotation, $publicUrl);
        $url = 'https://wa.me/' . $waNumber . '?text=' . rawurlencode($message);

        DocumentShareLog::query()->create([
            'business_id' => $quotation->business_id,
            'customer_id' => $quotation->customer_id,
            'quotation_id' => $quotation->id,
            'document_type' => 'quotation',
            'channel' => 'whatsapp',
            'recipient' => $waNumber,
            'status' => 'initiated',
            'sent_by' => Auth::id(),
            'message' => $message,
            'provider' => 'deep_link',
        ]);

        return [
            'url' => $url,
            'recipient' => $waNumber,
            'message' => $message,
            'public_url' => $publicUrl,
            'status' => 'initiated',
            'provider' => 'deep_link',
        ];
    }

    private function salesInvoiceMessage(string $customerName, string $businessName, SalesVoucher $voucher, string $pdfUrl): string
    {
        return "Hi {$customerName},\n\n"
            . "Thank you for your purchase from {$businessName}.\n\n"
            . "Invoice: {$voucher->invoice_number}\n"
            . 'Date: ' . optional($voucher->invoice_date)->format('d M Y') . "\n"
            . 'Amount: Rs. ' . number_format((float) $voucher->grand_total, 2) . "\n\n"
            . "Download Invoice PDF:\n{$pdfUrl}\n\n"
            . "Thank you,\n{$businessName}";
    }

    private function invoiceFilename(SalesVoucher $voucher): string
    {
        $number = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $voucher->invoice_number) ?: 'invoice';

        return $number . '.pdf';
    }

    private function quotationMessage(string $customerName, string $businessName, Quotation $quotation, string $publicUrl): string
    {
        return "Hi {$customerName},\n\n"
            . "Please find your quotation from {$businessName}.\n\n"
            . "Quotation: {$quotation->quotation_number}\n"
            . 'Date: ' . optional($quotation->quotation_date)->format('d M Y') . "\n"
            . 'Amount: Rs. ' . number_format((float) $quotation->grand_total, 2) . "\n\n"
            . "View / Download Quotation:\n{$publicUrl}\n\n"
            . "Thank you,\n{$businessName}";
    }
}
