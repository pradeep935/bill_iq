<?php

namespace App\Services;

use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvoiceDocumentRenderer
{
    public function renderSalesInvoice(array $sale, bool $public = false): string
    {
        $business = $this->business((int) ($sale['business_id'] ?? 0));
        if (!blank($sale['branch_address'] ?? null)) {
            $business['address'] = $sale['branch_address'];
        }
        $money = fn ($value): string => 'Rs. ' . number_format((float) $value, 2);
        $date = $this->dateTime($sale['invoice_datetime'] ?? null, $sale['invoice_date'] ?? null);
        $invoiceType = $this->invoiceTypeLabel((string) ($sale['invoice_type'] ?? 'tax_invoice'), blank($sale['customer_gstin'] ?? null));
        $paymentMode = collect($sale['payments'] ?? [])->pluck('payment_method')->filter()->unique()->implode(', ') ?: ucfirst((string) ($sale['sale_type'] ?? 'cash'));
        $totalDiscount = (float) ($sale['item_discount_amount'] ?? 0) + (float) ($sale['voucher_discount_amount'] ?? 0);
        $taxRows = $this->salesTaxRows($sale);
        $itemRows = collect($sale['items'] ?? [])->values()->map(function ($item, $index) use ($money) {
            $tax = (float) ($item['line_total'] ?? 0) - (float) ($item['taxable_amount'] ?? 0);
            $mrp = (float) ($item['mrp'] ?? 0);
            $rate = (float) ($item['selling_rate'] ?? 0);
            $saving = $mrp > $rate ? ($mrp - $rate) * (float) ($item['quantity'] ?? 0) : 0;

            return '<tr>'
                . '<td class="center">' . ($index + 1) . '</td>'
                . '<td><strong>' . e($item['product'] ?? 'Item') . '</strong><small>' . e($item['sku_snapshot'] ?? $item['barcode_snapshot'] ?? '') . '</small>' . ($saving > 0 ? '<small>MRP: ' . $money($mrp) . ' | You Saved: ' . $money($saving) . '</small>' : '') . '</td>'
                . '<td class="center">' . e($item['hsn_code_snapshot'] ?? '-') . '</td>'
                . '<td class="right">' . $this->qty($item['quantity'] ?? 0) . '</td>'
                . '<td class="center">' . e($item['unit'] ?? 'PCS') . '</td>'
                . '<td class="right">' . $money($item['selling_rate'] ?? 0) . '</td>'
                . '<td class="right">' . $money($item['discount_amount'] ?? 0) . '</td>'
                . '<td class="right">' . $money($item['taxable_amount'] ?? 0) . '</td>'
                . '<td class="center">' . number_format((float) ($item['gst_rate'] ?? 0), 2) . '%</td>'
                . '<td class="right">' . $money($tax) . '</td>'
                . '<td class="right strong">' . $money($item['line_total'] ?? 0) . '</td>'
                . '</tr>';
        })->implode('');
        $paymentRows = collect($sale['payments'] ?? [])->map(fn ($payment) => '<p><span>' . e($payment['payment_method'] ?: 'Payment') . '</span><strong>' . $money($payment['amount'] ?? 0) . '</strong></p>')->implode('');
        $taxSummaryRows = $taxRows ?: '<tr><td>GST</td><td class="center">0%</td><td class="right">' . $money($sale['taxable_amount'] ?? 0) . '</td><td class="right">' . $money(0) . '</td></tr>';
        $terms = e($sale['terms_and_conditions'] ?: "Goods once sold will not be taken back.\nWarranty as per manufacturer policy.\nSubject to local jurisdiction.");
        $terms = '<li>' . str_replace("\n", '</li><li>', $terms) . '</li>';
        $printAction = '<div class="no-print action-bar"><button onclick="window.print()">Print / Save PDF</button></div>';
        $billToAddress = $sale['billing_address'] ?: $sale['shipping_address'] ?: '-';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($sale['invoice_number']) . '</title>' . $this->invoiceCss() . '</head><body>' . $printAction . '<main class="invoice-page">'
            . '<section class="hero"><div class="brand"><div class="logo-mark"><span></span><b></b><i></i></div><div><h1>BillI</h1><p>Smart Billing - Easy Growth</p></div></div><div class="invoice-title"><strong>TAX INVOICE</strong><span>' . e($invoiceType) . '</span></div></section>'
            . '<section class="top-grid"><div><h2>' . e($business['name']) . '</h2><p>' . e($business['address']) . '</p><p>' . e($business['phone']) . ' <span>' . e($business['email']) . '</span></p><p>GSTIN: ' . e($business['gstin']) . '</p></div><dl><dt>Invoice No.</dt><dd>' . e($sale['invoice_number']) . '</dd><dt>Invoice Date/Time</dt><dd>' . e($date) . '</dd><dt>Place of Supply</dt><dd>' . e($business['state']) . '</dd><dt>Payment Mode</dt><dd>' . e($paymentMode) . '</dd></dl></section>'
            . '<section class="bill-to"><div class="bill-ribbon">BILL TO</div><div><h3>' . e($sale['customer'] ?: 'Walk-in Customer') . '</h3><p>' . e($sale['customer_mobile'] ?: '-') . '</p><p>' . e($billToAddress) . '</p><p>GSTIN: ' . e($sale['customer_gstin'] ?: '-') . '</p></div><div class="bag-art"><span></span><b></b></div></section>'
            . '<table class="items"><thead><tr><th>#</th><th>Item Name</th><th>HSN/SAC</th><th>Qty</th><th>Unit</th><th>Rate</th><th>Discount</th><th>Taxable</th><th>GST %</th><th>GST Amt</th><th>Amount</th></tr></thead><tbody>' . ($itemRows ?: '<tr><td colspan="11" class="center muted">No items</td></tr>') . '</tbody></table>'
            . '<section class="summary-layout"><div class="left-stack"><div class="info-card words"><div class="coin">Rs</div><div><h4>Amount in Words</h4><p>' . e($this->amountInWords((float) ($sale['grand_total'] ?? 0))) . '</p></div></div><div class="info-card"><h4>Payment Summary</h4>' . ($paymentRows ?: '<p><span>Paid Amount</span><strong>' . $money($sale['paid_amount'] ?? 0) . '</strong></p>') . '<p><span>Balance Due</span><strong>' . $money($sale['balance_amount'] ?? 0) . '</strong></p><em>' . e(ucfirst((string) ($sale['payment_status'] ?? 'paid'))) . '</em></div></div>'
            . '<div class="total-stack"><p><span>Sub Total</span><strong>' . $money($sale['subtotal'] ?? 0) . '</strong></p><p><span>Total Discount</span><strong>' . $money($totalDiscount) . '</strong></p><p><span>Taxable Amount</span><strong>' . $money($sale['taxable_amount'] ?? 0) . '</strong></p><table class="tax"><thead><tr><th>Tax Type</th><th>Rate</th><th>Taxable Amt.</th><th>Tax Amount</th></tr></thead><tbody>' . $taxSummaryRows . '</tbody></table><p><span>Round Off</span><strong>' . $money($sale['round_off'] ?? 0) . '</strong></p><div class="grand"><span>GRAND TOTAL</span><strong>' . $money($sale['grand_total'] ?? 0) . '</strong></div></div></section>'
            . '<section class="footer-grid"><div><h4>Payment Details</h4><p>Bank Name : ' . e($business['bank']) . '</p><p>A/C Name : ' . e($business['name']) . '</p><p>UPI ID : ' . e($business['upi']) . '</p>' . (!blank($sale['remarks'] ?? null) ? '<p>Notes : ' . e($sale['remarks']) . '</p>' : '') . '<div class="thanks">Thank you for your business!</div></div><div><h4>Scan & Pay (UPI)</h4><div class="qr"><span></span><span></span><span></span><span></span></div><p class="center">UPI: ' . e($business['upi']) . '</p></div><div><h4>Terms & Conditions</h4><ul>' . $terms . '</ul><div class="signature"><strong>For ' . e($business['name']) . '</strong><span>Authorized Signatory</span></div></div></section>'
            . '<footer><span>' . e($business['phone']) . '</span><span>' . e($business['email']) . '</span><span>' . e($business['address']) . '</span></footer></main></body></html>';
    }

    public function renderQuotation(Quotation $quotation): string
    {
        $money = fn ($value): string => 'Rs. ' . number_format((float) $value, 2);
        $items = $quotation->items->map(fn ($item) => '<tr><td>' . e($item->product?->name ?: $item->description ?: 'Item') . '</td><td class="right">' . number_format((float) $item->quantity, 3) . '</td><td class="right">' . $money($item->unit_price) . '</td><td class="right">' . $money($item->discount) . '</td><td class="right">' . $money($item->total) . '</td></tr>')->implode('');
        $date = optional($quotation->quotation_date)->format('d/m/Y') ?: '-';
        $validUntil = optional($quotation->valid_until)->format('d/m/Y') ?: '-';
        $customer = $quotation->customer;

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($quotation->quotation_number) . '</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#111;background:#fff}.top{display:flex;justify-content:space-between;gap:24px}.muted{color:#667085}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{padding:9px;border-bottom:1px solid #ddd;text-align:left}th{background:#f8fafc;font-size:12px;text-transform:uppercase}.right{text-align:right}.totals{margin-left:auto;width:320px}.print{margin-top:24px}@media print{.print{display:none}body{margin:0}}</style></head><body><div class="top"><div><h1>Quotation</h1><p class="muted">' . e($quotation->quotation_number) . ' | ' . e($date) . '</p></div><div class="right"><strong>' . e($quotation->branch?->name ?: 'BillIQ') . '</strong><p class="muted">Valid until: ' . e($validUntil) . '</p></div></div><hr><p><strong>Customer:</strong> ' . e($customer?->customer_name ?: 'Customer') . '<br><strong>Mobile:</strong> ' . e($customer?->mobile ?: '-') . '</p><table><thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Discount</th><th class="right">Total</th></tr></thead><tbody>' . $items . '</tbody></table><table class="totals"><tr><td>Subtotal</td><td class="right">' . $money($quotation->subtotal) . '</td></tr><tr><td>Discount</td><td class="right">' . $money($quotation->discount_amount) . '</td></tr><tr><th>Grand Total</th><th class="right">' . $money($quotation->grand_total) . '</th></tr></table><div class="print"><button onclick="window.print()">Print</button></div></body></html>';
    }

    public function salesInvoicePdf(array $sale)
    {
        return Pdf::loadHTML($this->renderSalesInvoice($sale, true))
            ->setPaper('a4', 'portrait')
            ->download($this->salesInvoiceFilename($sale));
    }

    public function renderThermalReceipt(array $sale): string
    {
        $business = $this->business((int) ($sale['business_id'] ?? 0));
        if (!blank($sale['branch_address'] ?? null)) {
            $business['address'] = $sale['branch_address'];
        }

        $money = fn ($value): string => number_format((float) $value, 2);
        $qty = fn ($value): string => $this->qty($value);
        $paymentMode = collect($sale['payments'] ?? [])->pluck('payment_method')->filter()->unique()->implode(', ') ?: ucfirst((string) ($sale['sale_type'] ?? 'cash'));
        $tax = (float) ($sale['cgst_amount'] ?? 0) + (float) ($sale['sgst_amount'] ?? 0) + (float) ($sale['igst_amount'] ?? 0) + (float) ($sale['cess_amount'] ?? 0);
        $discount = (float) ($sale['item_discount_amount'] ?? 0) + (float) ($sale['voucher_discount_amount'] ?? 0);
        $dateTime = $sale['invoice_datetime'] ?? $sale['invoice_date'] ?? now()->format('Y-m-d H:i');
        $customer = $sale['customer'] ?: 'Walk-in Customer';

        $items = collect($sale['items'] ?? [])->values()->map(function ($item) use ($money, $qty) {
            $mrp = (float) ($item['mrp'] ?? 0);
            $rate = (float) ($item['selling_rate'] ?? 0);
            $saving = $mrp > $rate ? ($mrp - $rate) * (float) ($item['quantity'] ?? 0) : 0;

            return '<div class="item">'
                . '<div class="item-name">' . e($item['product'] ?? 'Item') . '</div>'
                . '<div class="line"><span>' . $qty($item['quantity'] ?? 0) . ' x ' . $money($rate) . '</span><strong>' . $money($item['line_total'] ?? 0) . '</strong></div>'
                . ($saving > 0 ? '<div class="saving">MRP ' . $money($mrp) . ' | Saved ' . $money($saving) . '</div>' : '')
                . '</div>';
        })->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($sale['invoice_number']) . ' Receipt</title><style>'
            . '@page{size:80mm auto;margin:2mm}*{box-sizing:border-box}body{margin:0;background:#fff;color:#000;font-family:"Courier New",monospace;font-size:12px;line-height:1.3}.no-print{padding:8px;text-align:center}.no-print button{min-height:34px;padding:6px 12px;border:1px solid #000;background:#fff;color:#000;font-weight:700}.receipt{width:76mm;margin:0 auto;padding:2mm}.center{text-align:center}.store{font-size:15px;font-weight:800;text-transform:uppercase}.muted{font-size:11px}.rule{border-top:1px dashed #000;margin:7px 0}.meta div,.line{display:flex;justify-content:space-between;gap:8px}.meta span,.line span{min-width:0}.line strong{white-space:nowrap}.item{margin:7px 0}.item-name{font-weight:700;word-break:break-word}.saving{font-size:10px}.total{font-size:14px;font-weight:900}.thanks{margin-top:8px;text-align:center;font-weight:800}@media print{html,body{width:80mm;background:#fff}.no-print{display:none}.receipt{width:76mm;margin:0;padding:0}}'
            . '</style></head><body><div class="no-print"><button onclick="window.print()">Print 80mm Receipt</button></div><main class="receipt">'
            . '<div class="center store">' . e($business['name']) . '</div>'
            . '<div class="center muted">' . e($business['address']) . '</div>'
            . '<div class="center muted">GSTIN: ' . e($business['gstin']) . '</div>'
            . '<div class="center muted">Phone: ' . e($business['phone']) . '</div>'
            . '<div class="rule"></div><div class="meta">'
            . '<div><span>Invoice</span><strong>' . e($sale['invoice_number']) . '</strong></div>'
            . '<div><span>Date</span><strong>' . e($dateTime) . '</strong></div>'
            . '<div><span>Customer</span><strong>' . e($customer) . '</strong></div>'
            . (!blank($sale['customer_mobile'] ?? null) ? '<div><span>Mobile</span><strong>' . e($sale['customer_mobile']) . '</strong></div>' : '')
            . '</div><div class="rule"></div>'
            . ($items ?: '<div class="center">No items</div>')
            . '<div class="rule"></div>'
            . '<div class="line"><span>Subtotal</span><strong>' . $money($sale['subtotal'] ?? 0) . '</strong></div>'
            . '<div class="line"><span>Discount</span><strong>' . $money($discount) . '</strong></div>'
            . '<div class="line"><span>Taxable</span><strong>' . $money($sale['taxable_amount'] ?? 0) . '</strong></div>'
            . '<div class="line"><span>Tax</span><strong>' . $money($tax) . '</strong></div>'
            . '<div class="line"><span>Round Off</span><strong>' . $money($sale['round_off'] ?? 0) . '</strong></div>'
            . '<div class="rule"></div><div class="line total"><span>TOTAL</span><strong>' . $money($sale['grand_total'] ?? 0) . '</strong></div><div class="rule"></div>'
            . '<div class="line"><span>Payment</span><strong>' . e($paymentMode) . '</strong></div>'
            . '<div class="line"><span>Paid</span><strong>' . $money($sale['paid_amount'] ?? 0) . '</strong></div>'
            . '<div class="line"><span>Due</span><strong>' . $money($sale['balance_amount'] ?? 0) . '</strong></div>'
            . '<div class="thanks">Thank You</div>'
            . '</main></body></html>';
    }

    private function business(int $businessId): array
    {
        $company = $businessId && Schema::hasTable('companies') ? DB::table('companies')->where('id', $businessId)->first() : null;
        $branch = $businessId && Schema::hasTable('branches') ? DB::table('branches')->where('business_id', $businessId)->where('status', 'active')->orderBy('id')->first() : null;

        $value = fn ($source, string $key, $fallback = '') => $source && property_exists($source, $key) && $source->{$key} ? $source->{$key} : $fallback;

        return [
            'name' => $value($company, 'name', 'ABC Enterprises'),
            'gstin' => $value($company, 'gstin', '09XXXXXXXXXXXXX'),
            'state' => $value($company, 'state', $value($branch, 'state', 'Uttar Pradesh')),
            'address' => $value($branch, 'address', $value($company, 'address', '123, Business Park, Meerut, Uttar Pradesh - 250001, India')),
            'phone' => $value($company, 'phone', '98XXXXXXXX'),
            'email' => $value($company, 'email', 'info@abc.in'),
            'bank' => config('invoice.bank_name', 'HDFC Bank'),
            'upi' => config('invoice.upi_id', 'abc@upi'),
        ];
    }

    private function salesTaxRows(array $sale): string
    {
        $money = fn ($value): string => 'Rs. ' . number_format((float) $value, 2);
        $groups = collect($sale['items'] ?? [])->groupBy(fn ($item) => number_format((float) ($item['gst_rate'] ?? 0), 2));
        $rows = [];

        foreach ($groups as $rate => $items) {
            $taxable = $items->sum(fn ($item) => (float) ($item['taxable_amount'] ?? 0));
            $tax = $items->sum(fn ($item) => max(0, (float) ($item['line_total'] ?? 0) - (float) ($item['taxable_amount'] ?? 0)));

            if (($sale['tax_type'] ?? 'intrastate') === 'interstate') {
                $rows[] = '<tr><td>IGST</td><td class="center">' . $rate . '%</td><td class="right">' . $money($taxable) . '</td><td class="right">' . $money($tax) . '</td></tr>';
                continue;
            }

            $halfRate = number_format(((float) $rate) / 2, 2);
            $rows[] = '<tr><td>CGST</td><td class="center">' . $halfRate . '%</td><td class="right">' . $money($taxable) . '</td><td class="right">' . $money($tax / 2) . '</td></tr>';
            $rows[] = '<tr><td>SGST</td><td class="center">' . $halfRate . '%</td><td class="right">' . $money($taxable) . '</td><td class="right">' . $money($tax / 2) . '</td></tr>';
        }

        return implode('', $rows);
    }

    private function invoiceTypeLabel(string $type, bool $withoutGstin): string
    {
        if ($type === 'bill_of_supply') {
            return 'Bill of Supply';
        }

        return $withoutGstin ? 'B2C (Without GSTIN)' : 'B2B (With GSTIN)';
    }

    private function date(?string $date): string
    {
        return $date ? date('d-m-Y', strtotime($date)) : '-';
    }

    private function dateTime(?string $dateTime, ?string $fallbackDate = null): string
    {
        if ($dateTime) {
            return date('d-m-Y h:i A', strtotime($dateTime));
        }

        return $this->date($fallbackDate);
    }

    private function qty($value): string
    {
        $number = (float) $value;

        return rtrim(rtrim(number_format($number, 3), '0'), '.');
    }

    private function amountInWords(float $amount): string
    {
        $whole = (int) round($amount);

        return 'Rupees ' . $this->numberWords($whole) . ' Only';
    }

    private function salesInvoiceFilename(array $sale): string
    {
        $number = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($sale['invoice_number'] ?? 'invoice')) ?: 'invoice';

        return $number . '.pdf';
    }

    private function numberWords(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $parts = [];

        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $value => $label) {
            if ($number >= $value) {
                $parts[] = $this->numberWords((int) floor($number / $value)) . ' ' . $label;
                $number %= $value;
            }
        }

        if ($number > 0) {
            $parts[] = $number < 20 ? $ones[$number] : trim($tens[(int) floor($number / 10)] . ' ' . $ones[$number % 10]);
        }

        return implode(' ', $parts);
    }

    private function invoiceCss(): string
    {
        return '<style>
@page{size:A4 portrait;margin:0}*{box-sizing:border-box}body{margin:0;background:#eef3f8;color:#061d3b;font-family:Arial,Helvetica,sans-serif;font-size:13px}.invoice-page{width:210mm;min-height:297mm;max-width:100%;margin:0 auto;background:#fff;padding:12mm 10mm 0;box-shadow:0 18px 45px rgba(7,31,61,.14);overflow:hidden}.action-bar{position:sticky;top:0;z-index:5;text-align:center;padding:10px;background:#eef3f8}.action-bar button{min-height:40px;padding:8px 18px;border:0;border-radius:8px;background:#082747;color:#fff;font-weight:800;cursor:pointer}.hero,.top-grid,.bill-to,.summary-layout,.footer-grid{display:grid;gap:12px}.hero{grid-template-columns:1fr auto;align-items:start}.brand{display:flex;align-items:center;gap:10px}.brand h1{margin:0;color:#082747;font-size:34px;line-height:1}.brand h1::after{content:"Q";color:#07835e}.brand p{margin:4px 0 0;text-transform:uppercase;color:#263f5d;font-size:9px;font-weight:800}.logo-mark{position:relative;width:50px;height:54px}.logo-mark span,.logo-mark b,.logo-mark i{position:absolute;bottom:7px;width:10px;background:#07835e;border-radius:8px 8px 0 0}.logo-mark span{left:8px;height:34px}.logo-mark b{left:21px;height:48px}.logo-mark i{left:34px;height:26px}.logo-mark::after{content:"";position:absolute;left:3px;bottom:0;width:44px;height:24px;border:7px solid #07835e;border-top:0;border-radius:0 0 34px 34px;transform:rotate(20deg)}.invoice-title{text-align:center}.invoice-title strong{display:block;padding:10px 22px;border-radius:6px;background:#082747;color:#fff;font-size:24px;letter-spacing:.5px}.invoice-title span{display:inline-block;margin-top:0;padding:7px 14px;border-radius:0 0 6px 6px;background:#ddf5ec;color:#061d3b;font-weight:800}.top-grid{grid-template-columns:1fr 1fr;margin-top:16px}.top-grid h2{margin:0 0 8px;font-size:22px;text-transform:uppercase}.top-grid p{margin:6px 0;color:#111827;font-size:12px;line-height:1.35}.top-grid dl{display:grid;grid-template-columns:120px 1fr;gap:9px 14px;margin:0;padding-left:26px;border-left:1px solid #c8d0da}.top-grid dt{color:#111827}.top-grid dd{margin:0;font-weight:800;color:#111827}.bill-to{position:relative;grid-template-columns:150px 1fr 150px;align-items:center;margin-top:14px;padding:12px;border:1px solid #cdd6e0;border-radius:8px;overflow:hidden}.bill-ribbon{margin-left:-12px;padding:18px 22px;border-radius:0 34px 34px 0;background:#082747;color:#fff;font-size:17px;font-weight:900}.bill-to h3{margin:0 0 6px;color:#111827}.bill-to p{margin:5px 0;color:#111827}.bag-art{height:76px;position:relative}.bag-art span,.bag-art b{position:absolute;bottom:0;border-radius:4px 4px 0 0}.bag-art span{right:58px;width:48px;height:62px;background:#8bddae}.bag-art b{right:24px;width:36px;height:52px;background:#07835e}.bag-art::before,.bag-art::after{content:"";position:absolute;border:3px solid #07835e;border-bottom:0;border-radius:20px 20px 0 0}.bag-art::before{right:69px;bottom:53px;width:20px;height:20px}.bag-art::after{right:31px;bottom:44px;width:16px;height:16px}.items{width:100%;border-collapse:separate;border-spacing:0;margin-top:10px;overflow:hidden;border-radius:6px}.items th{background:#006b52;color:#fff;font-size:10px}.items th:first-child{border-radius:6px 0 0 0}.items th:last-child{border-radius:0 6px 0 0}.items td,.items th{padding:7px 6px;border-right:1px solid #dde3ea;border-bottom:1px solid #dde3ea}.items td:first-child,.items th:first-child{border-left:1px solid #dde3ea}.items small{display:block;margin-top:2px;color:#637083}.center{text-align:center}.right{text-align:right}.strong{font-weight:900}.muted{color:#667085}.summary-layout{grid-template-columns:270px 1fr;margin-top:12px}.left-stack{display:grid;gap:10px;align-content:start}.info-card{border:1px solid #d8e0e8;border-radius:8px;padding:13px;background:#fff}.info-card h4,.footer-grid h4{margin:0 0 8px;color:#082747;text-transform:uppercase;font-size:13px}.info-card p,.total-stack p{display:flex;justify-content:space-between;gap:10px;margin:7px 0}.info-card em{display:inline-block;margin-top:6px;padding:7px 14px;border-radius:6px;background:#ddf5ec;color:#0c7b56;font-style:normal;font-weight:900}.words{display:grid;grid-template-columns:42px 1fr;align-items:center}.coin{display:grid;place-items:center;width:38px;height:38px;border-radius:50%;background:#39a756;color:#fff;font-weight:900}.total-stack>p{padding-bottom:6px;border-bottom:1px dashed #c9d2dc;font-size:13px}.tax{width:100%;border-collapse:separate;border-spacing:0;margin:9px 0;border-radius:6px;overflow:hidden}.tax th{background:#138461;color:#fff}.tax td,.tax th{padding:6px;border:1px solid #dde3ea}.grand{display:flex;justify-content:space-between;align-items:center;margin-top:9px;padding:11px;border-radius:8px;background:#d9f7ed;color:#082747;font-size:16px;font-weight:900}.grand strong{font-size:24px;color:#02735a}.footer-grid{grid-template-columns:1fr 1fr 1.35fr;margin-top:12px;padding:12px;border:1px solid #d8e0e8;border-radius:8px;font-size:11px}.footer-grid p{margin:5px 0}.footer-grid ul{margin:0;padding-left:16px}.footer-grid>div+div{border-left:1px solid #d8e0e8;padding-left:14px}.thanks{margin-top:12px;padding:10px;border-radius:0 6px 6px 0;background:#082747;color:#fff;font-weight:800}.qr{width:82px;height:82px;margin:6px auto;background:repeating-linear-gradient(45deg,#111 0 6px,#fff 6px 12px);border:8px solid #fff;box-shadow:0 0 0 1px #d7dfe8}.signature{margin-top:14px;text-align:right}.signature strong,.signature span{display:block}.signature::before{content:"";display:block;width:130px;margin:18px 0 6px auto;border-top:1px solid #94a3b8}footer{display:flex;justify-content:center;gap:22px;margin:12px -10mm 0;padding:10px 10mm;background:#082747;color:#fff;font-size:11px}@media(max-width:820px){.invoice-page{width:100%;padding:18px 14px 0}.hero,.top-grid,.summary-layout,.footer-grid{grid-template-columns:1fr}.bill-to{grid-template-columns:1fr}.bill-ribbon{margin:0;border-radius:8px}.bag-art{display:none}.top-grid dl{padding-left:0;border-left:0}.footer-grid>div+div{border-left:0;border-top:1px solid #d8e0e8;padding-left:0;padding-top:16px}footer{display:grid;gap:8px;margin-left:-14px;margin-right:-14px}}@media print{html,body{width:210mm;height:297mm;background:#fff}.no-print{display:none}.invoice-page{width:210mm;min-height:297mm;box-shadow:none;margin:0;page-break-after:avoid}.footer-grid,.summary-layout,.bill-to,.top-grid{break-inside:avoid;page-break-inside:avoid}footer{margin-top:10px}}</style>';
    }
}
