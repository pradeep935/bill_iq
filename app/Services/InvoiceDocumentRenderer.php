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
        $a4Options = $business['a4_print_options'];
        $money = fn ($value): string => 'Rs. ' . number_format((float) $value, 2);
        $date = $this->date($sale['invoice_date'] ?? null);
        $taxRows = $this->salesHsnTaxRows($sale);
        $itemRows = collect($sale['items'] ?? [])->values()->map(function ($item, $index) use ($money, $a4Options) {
            $tax = max(0, (float) ($item['line_total'] ?? 0) - (float) ($item['taxable_amount'] ?? 0));
            $rateText = $money($item['selling_rate'] ?? 0);
            $gstText = $money($tax) . ' (' . rtrim(rtrim(number_format((float) ($item['gst_rate'] ?? 0), 2), '0'), '.') . '%)';

            return '<tr>'
                . '<td>' . ($index + 1) . '</td>'
                . '<td><strong>' . e($item['product'] ?? 'Item') . '</strong></td>'
                . ($a4Options['show_hsn'] ? '<td>' . e($item['hsn_code_snapshot'] ?? '-') . '</td>' : '')
                . '<td class="right">' . $this->qty($item['quantity'] ?? 0) . '</td>'
                . '<td class="right">' . e($item['unit'] ?? 'PCS') . '</td>'
                . '<td class="right">' . $rateText . '</td>'
                . '<td class="right">' . $gstText . '</td>'
                . '<td class="right">' . $money($item['line_total'] ?? 0) . '</td>'
                . '</tr>';
        })->implode('');
        $taxSummaryRows = $taxRows ?: '<tr><td>-</td><td class="right">' . $money($sale['taxable_amount'] ?? 0) . '</td><td class="center">0%</td><td class="right">' . $money(0) . '</td><td class="center">0%</td><td class="right">' . $money(0) . '</td><td class="right">' . $money(0) . '</td></tr>';
        $terms = e($sale['terms_and_conditions'] ?: $business['terms']);
        $terms = nl2br($terms);
        $printAction = '<div class="no-print action-bar"><button onclick="window.print()">Print / Save PDF</button></div>';
        $billToAddress = $sale['billing_address'] ?: $sale['shipping_address'] ?: '-';
        $received = (float) ($sale['paid_amount'] ?? 0);
        $balance = (float) ($sale['balance_amount'] ?? 0);
        $totalTax = (float) ($sale['cgst_amount'] ?? 0) + (float) ($sale['sgst_amount'] ?? 0) + (float) ($sale['igst_amount'] ?? 0) + (float) ($sale['cess_amount'] ?? 0);
        $logo = ($business['show_logo_on_invoice'] ?? true) && !blank($business['logo_url'] ?? '')
            ? '<img class="seller-logo" src="' . e($business['logo_url']) . '" alt="' . e($business['name']) . ' logo">'
            : '';
        $seller = $a4Options['show_business_info']
            ? '<section class="invoice-head"><div class="seller"><div class="seller-top">' . $logo . '<div><h2>' . e($business['name']) . '</h2><p>' . e($business['address']) . '</p><p>Phone no.: ' . e($business['phone'] ?: '-') . '</p><p>Email: ' . e($business['email'] ?: '-') . '</p><p>GSTIN: ' . e($business['gstin'] ?: '-') . '</p><p>State: ' . e($business['state'] ?: '-') . '</p></div></div></div><div class="meta"><div><span>Invoice No.</span><strong>' . e($sale['invoice_number']) . '</strong></div><div><span>Date</span><strong>' . e($date) . '</strong></div><div><span>Place of supply</span><strong>' . e($business['state'] ?: '-') . '</strong></div></div></section>'
            : '<section class="invoice-head"><div class="seller"><h2>Tax Invoice</h2></div><div class="meta"><div><span>Invoice No.</span><strong>' . e($sale['invoice_number']) . '</strong></div><div><span>Date</span><strong>' . e($date) . '</strong></div><div><span>Place of supply</span><strong>' . e($business['state'] ?: '-') . '</strong></div></div></section>';
        $billTo = $a4Options['show_customer_info']
            ? '<section class="bill-to"><span>Bill To</span><h3>' . e($sale['customer'] ?: 'Walk-in Customer') . '</h3><p>' . e($billToAddress) . '</p><p>Contact No.: ' . e($sale['customer_mobile'] ?: '-') . '</p><p>GSTIN: ' . e($sale['customer_gstin'] ?: '-') . '</p><p>State: ' . e($business['state'] ?: '-') . '</p></section>'
            : '';
        $hsnHeader = $a4Options['show_hsn'] ? '<th>HSN/SAC</th>' : '';
        $emptyCols = $a4Options['show_hsn'] ? 8 : 7;
        $totalHsnCell = $a4Options['show_hsn'] ? '<td></td>' : '';
        $taxSummary = $a4Options['show_tax_summary']
            ? '<table class="tax-summary"><thead><tr><th rowspan="2">HSN/SAC</th><th rowspan="2">Taxable amount</th><th colspan="2">CGST</th><th colspan="2">SGST</th><th rowspan="2">Total Tax Amount</th></tr><tr><th>Rate</th><th>Amount</th><th>Rate</th><th>Amount</th></tr></thead><tbody>' . $taxSummaryRows . '<tr class="total-row"><td>Total</td><td class="right">' . $money($sale['taxable_amount'] ?? 0) . '</td><td></td><td class="right">' . $money($sale['cgst_amount'] ?? 0) . '</td><td></td><td class="right">' . $money($sale['sgst_amount'] ?? 0) . '</td><td class="right">' . $money($totalTax) . '</td></tr></tbody></table>'
            : '';
        $bankBlock = $a4Options['show_bank_details'] ? '<div><h4>Bank Details</h4><p>Name : ' . e($business['bank']) . '</p><p>Account No. : ' . e($business['account_number'] ?: '-') . '</p><p>IFSC code : ' . e($business['ifsc'] ?: '-') . '</p><p>Account holder name : ' . e($business['account_holder'] ?: $business['name']) . '</p></div>' : '<div></div>';
        $termsBlock = $a4Options['show_terms'] ? '<div><h4>Terms and conditions</h4><p>' . $terms . '</p></div>' : '<div></div>';
        $signatureBlock = $a4Options['show_signature'] ? '<div class="signature"><p>For : ' . e($business['name']) . '</p><strong>Authorized Signatory</strong></div>' : '<div></div>';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($sale['invoice_number']) . '</title>' . $this->invoiceCss() . '</head><body>' . $printAction . '<main class="invoice-page">'
            . '<h1 class="title">Tax Invoice</h1>'
            . $seller
            . $billTo
            . '<table class="items"><thead><tr><th>#</th><th>Item name</th>' . $hsnHeader . '<th>Quantity</th><th>Unit</th><th>Price / Unit</th><th>GST</th><th>Amount</th></tr></thead><tbody>' . ($itemRows ?: '<tr><td colspan="' . $emptyCols . '" class="center">No items</td></tr>') . '<tr class="total-row"><td></td><td>Total</td>' . $totalHsnCell . '<td class="right">' . $this->qty(collect($sale['items'] ?? [])->sum(fn ($item) => (float) ($item['quantity'] ?? 0))) . '</td><td></td><td></td><td class="right">' . $money($totalTax) . '</td><td class="right">' . $money($sale['grand_total'] ?? 0) . '</td></tr></tbody></table>'
            . '<section class="amount-row"><div><span>Invoice Amount in Words</span><strong>' . e($this->amountInWords((float) ($sale['grand_total'] ?? 0))) . '</strong></div><table><tr><th colspan="2">Amounts</th></tr><tr><td>Sub Total</td><td class="right">' . $money($sale['subtotal'] ?? 0) . '</td></tr><tr><td>Total</td><td class="right strong">' . $money($sale['grand_total'] ?? 0) . '</td></tr><tr><td>Received</td><td class="right">' . $money($received) . '</td></tr><tr><td>Balance</td><td class="right">' . $money($balance) . '</td></tr></table></section>'
            . $taxSummary
            . '<section class="footer-grid">' . $bankBlock . $termsBlock . $signatureBlock . '</section>'
            . '</main></body></html>';
    }

    public function renderQuotation(Quotation $quotation): string
    {
        $money = fn ($value): string => 'Rs. ' . number_format((float) $value, 2);
        $items = $quotation->items->map(fn ($item) => '<tr><td>' . e(optional($item->product)->name ?: $item->description ?: 'Item') . '</td><td class="right">' . number_format((float) $item->quantity, 3) . '</td><td class="right">' . $money($item->unit_price) . '</td><td class="right">' . $money($item->discount) . '</td><td class="right">' . $money($item->total) . '</td></tr>')->implode('');
        $date = optional($quotation->quotation_date)->format('d/m/Y') ?: '-';
        $validUntil = optional($quotation->valid_until)->format('d/m/Y') ?: '-';
        $customer = $quotation->customer;

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($quotation->quotation_number) . '</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#111;background:#fff}.top{display:flex;justify-content:space-between;gap:24px}.muted{color:#667085}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{padding:9px;border-bottom:1px solid #ddd;text-align:left}th{background:#f8fafc;font-size:12px;text-transform:uppercase}.right{text-align:right}.totals{margin-left:auto;width:320px}.print{margin-top:24px}@media print{.print{display:none}body{margin:0}}</style></head><body><div class="top"><div><h1>Quotation</h1><p class="muted">' . e($quotation->quotation_number) . ' | ' . e($date) . '</p></div><div class="right"><strong>' . e(optional($quotation->branch)->name ?: 'BillIQ') . '</strong><p class="muted">Valid until: ' . e($validUntil) . '</p></div></div><hr><p><strong>Customer:</strong> ' . e(optional($customer)->customer_name ?: 'Customer') . '<br><strong>Mobile:</strong> ' . e(optional($customer)->mobile ?: '-') . '</p><table><thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Discount</th><th class="right">Total</th></tr></thead><tbody>' . $items . '</tbody></table><table class="totals"><tr><td>Subtotal</td><td class="right">' . $money($quotation->subtotal) . '</td></tr><tr><td>Discount</td><td class="right">' . $money($quotation->discount_amount) . '</td></tr><tr><th>Grand Total</th><th class="right">' . $money($quotation->grand_total) . '</th></tr></table><div class="print"><button onclick="window.print()">Print</button></div></body></html>';
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
        $thermalOptions = $business['thermal_print_options'];

        $money = fn ($value): string => number_format((float) $value, 2);
        $qty = fn ($value): string => $this->qty($value);
        $paymentMode = collect($sale['payments'] ?? [])->pluck('payment_method')->filter()->unique()->implode(', ') ?: ucfirst((string) ($sale['sale_type'] ?? 'cash'));
        $tax = (float) ($sale['cgst_amount'] ?? 0) + (float) ($sale['sgst_amount'] ?? 0) + (float) ($sale['igst_amount'] ?? 0) + (float) ($sale['cess_amount'] ?? 0);
        $discount = (float) ($sale['item_discount_amount'] ?? 0) + (float) ($sale['voucher_discount_amount'] ?? 0);
        $dateTime = $sale['invoice_datetime'] ?? $sale['invoice_date'] ?? now()->format('Y-m-d H:i');
        $customer = $sale['customer'] ?: 'Walk-in Customer';
        $paperWidth = $business['thermal_paper_width'] === '58mm' ? '58mm' : '80mm';
        $receiptWidth = $paperWidth === '58mm' ? '54mm' : '76mm';
        $logo = ($business['show_logo_on_thermal_receipt'] ?? false) && !blank($business['logo_url'] ?? '')
            ? '<div class="center"><img class="receipt-logo" src="' . e($business['logo_url']) . '" alt="' . e($business['name']) . ' logo"></div>'
            : '';
        $businessBlock = $thermalOptions['show_business_info']
            ? $logo . '<div class="center store">' . e($business['name']) . '</div><div class="center muted">' . e($business['address']) . '</div>' . ($thermalOptions['show_gstin'] ? '<div class="center muted">GSTIN: ' . e($business['gstin']) . '</div>' : '') . '<div class="center muted">Phone: ' . e($business['phone']) . '</div>'
            : '';

        $items = collect($sale['items'] ?? [])->values()->map(function ($item) use ($money, $qty, $thermalOptions) {
            $mrp = (float) ($item['mrp'] ?? 0);
            $rate = (float) ($item['selling_rate'] ?? 0);
            $saving = $mrp > $rate ? ($mrp - $rate) * (float) ($item['quantity'] ?? 0) : 0;

            return '<div class="item">'
                . '<div class="item-name">' . e($item['product'] ?? 'Item') . '</div>'
                . '<div class="line"><span>' . $qty($item['quantity'] ?? 0) . ' x ' . $money($rate) . '</span><strong>' . $money($item['line_total'] ?? 0) . '</strong></div>'
                . ($thermalOptions['show_item_savings'] && $saving > 0 ? '<div class="saving">MRP ' . $money($mrp) . ' | Saved ' . $money($saving) . '</div>' : '')
                . '</div>';
        })->implode('');
        $customerBlock = $thermalOptions['show_customer'] ? '<div><span>Customer</span><strong>' . e($customer) . '</strong></div>' . (!blank($sale['customer_mobile'] ?? null) ? '<div><span>Mobile</span><strong>' . e($sale['customer_mobile']) . '</strong></div>' : '') : '';
        $taxBlock = $thermalOptions['show_tax_breakup'] ? '<div class="line"><span>Taxable</span><strong>' . $money($sale['taxable_amount'] ?? 0) . '</strong></div><div class="line"><span>Tax</span><strong>' . $money($tax) . '</strong></div>' : '';
        $paymentBlock = $thermalOptions['show_payment_details'] ? '<div class="line"><span>Payment</span><strong>' . e($paymentMode) . '</strong></div><div class="line"><span>Paid</span><strong>' . $money($sale['paid_amount'] ?? 0) . '</strong></div><div class="line"><span>Due</span><strong>' . $money($sale['balance_amount'] ?? 0) . '</strong></div>' : '';
        $footerText = trim((string) ($business['thermal_footer_text'] ?? 'Thank You')) ?: 'Thank You';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($sale['invoice_number']) . ' Receipt</title><style>'
            . '@page{size:' . $paperWidth . ' auto;margin:2mm}*{box-sizing:border-box}body{margin:0;background:#fff;color:#000;font-family:"Courier New",monospace;font-size:12px;line-height:1.3}.no-print{padding:8px;text-align:center}.no-print button{min-height:34px;padding:6px 12px;border:1px solid #000;background:#fff;color:#000;font-weight:700}.receipt{width:' . $receiptWidth . ';margin:0 auto;padding:2mm}.receipt-logo{width:28mm;max-height:18mm;object-fit:contain;margin-bottom:4px}.center{text-align:center}.store{font-size:15px;font-weight:800;text-transform:uppercase}.muted{font-size:11px}.rule{border-top:1px dashed #000;margin:7px 0}.meta div,.line{display:flex;justify-content:space-between;gap:8px}.meta span,.line span{min-width:0}.line strong{white-space:nowrap}.item{margin:7px 0}.item-name{font-weight:700;word-break:break-word}.saving{font-size:10px}.total{font-size:14px;font-weight:900}.thanks{margin-top:8px;text-align:center;font-weight:800}@media print{html,body{width:' . $paperWidth . ';background:#fff}.no-print{display:none}.receipt{width:' . $receiptWidth . ';margin:0;padding:0}}'
            . '</style></head><body><div class="no-print"><button onclick="window.print()">Print ' . e($paperWidth) . ' Receipt</button></div><main class="receipt">'
            . $businessBlock
            . '<div class="rule"></div><div class="meta">'
            . '<div><span>Invoice</span><strong>' . e($sale['invoice_number']) . '</strong></div>'
            . '<div><span>Date</span><strong>' . e($dateTime) . '</strong></div>'
            . $customerBlock
            . '</div><div class="rule"></div>'
            . ($items ?: '<div class="center">No items</div>')
            . '<div class="rule"></div>'
            . '<div class="line"><span>Subtotal</span><strong>' . $money($sale['subtotal'] ?? 0) . '</strong></div>'
            . '<div class="line"><span>Discount</span><strong>' . $money($discount) . '</strong></div>'
            . $taxBlock
            . '<div class="line"><span>Round Off</span><strong>' . $money($sale['round_off'] ?? 0) . '</strong></div>'
            . '<div class="rule"></div><div class="line total"><span>TOTAL</span><strong>' . $money($sale['grand_total'] ?? 0) . '</strong></div><div class="rule"></div>'
            . $paymentBlock
            . '<div class="thanks">' . e($footerText) . '</div>'
            . '</main></body></html>';
    }

    private function business(int $businessId): array
    {
        $company = $businessId && Schema::hasTable('companies') ? DB::table('companies')->where('id', $businessId)->first() : null;
        $branch = $businessId && Schema::hasTable('branches') ? DB::table('branches')->where('business_id', $businessId)->where('status', 'active')->orderBy('id')->first() : null;

        $value = fn ($source, string $key, $fallback = '') => $source && property_exists($source, $key) && $source->{$key} !== null ? $source->{$key} : $fallback;

        return [
            'name' => $value($company, 'name', 'ABC Enterprises'),
            'gstin' => $value($company, 'gstin', '09XXXXXXXXXXXXX'),
            'state' => $value($company, 'state', $value($branch, 'state', 'Uttar Pradesh')),
            'address' => $value($branch, 'address', $value($company, 'address', '123, Business Park, Meerut, Uttar Pradesh - 250001, India')),
            'phone' => $value($company, 'phone', '98XXXXXXXX'),
            'email' => $value($company, 'email', 'info@abc.in'),
            'bank' => $value($company, 'bank_name', config('invoice.bank_name', 'HDFC Bank')),
            'account_number' => $value($company, 'bank_account_number', ''),
            'ifsc' => $value($company, 'bank_ifsc', ''),
            'account_holder' => $value($company, 'bank_account_holder', $value($company, 'name', '')),
            'terms' => $value($company, 'invoice_terms', "We declare that this invoice shows actual price of the goods described and that particulars are true & correct.\nAll disputes subject to local jurisdiction only."),
            'logo_path' => $value($company, 'logo_path', ''),
            'logo_url' => $this->publicFileUrl($value($company, 'logo_path', '')),
            'show_logo_on_invoice' => (bool) $value($company, 'show_logo_on_invoice', true),
            'show_logo_on_thermal_receipt' => (bool) $value($company, 'show_logo_on_thermal_receipt', false),
            'thermal_paper_width' => $value($company, 'thermal_paper_width', '80mm'),
            'thermal_footer_text' => $value($company, 'thermal_footer_text', 'Thank You'),
            'a4_print_options' => $this->printOptions($value($company, 'a4_print_options', null), $this->defaultA4PrintOptions()),
            'thermal_print_options' => $this->printOptions($value($company, 'thermal_print_options', null), $this->defaultThermalPrintOptions()),
            'upi' => config('invoice.upi_id', 'abc@upi'),
        ];
    }

    private function printOptions($stored, array $defaults): array
    {
        if (is_string($stored) && $stored !== '') {
            $stored = json_decode($stored, true);
        }

        return array_merge($defaults, is_array($stored) ? array_intersect_key($stored, $defaults) : []);
    }

    private function defaultA4PrintOptions(): array
    {
        return [
            'show_business_info' => true,
            'show_customer_info' => true,
            'show_hsn' => true,
            'show_tax_summary' => true,
            'show_bank_details' => true,
            'show_terms' => true,
            'show_signature' => true,
        ];
    }

    private function defaultThermalPrintOptions(): array
    {
        return [
            'show_business_info' => true,
            'show_gstin' => true,
            'show_customer' => true,
            'show_item_savings' => true,
            'show_tax_breakup' => true,
            'show_payment_details' => true,
        ];
    }

    private function publicFileUrl(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) || substr($path, 0, 1) === '/') {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }

    private function salesHsnTaxRows(array $sale): string
    {
        $money = fn ($value): string => 'Rs. ' . number_format((float) $value, 2);
        $groups = collect($sale['items'] ?? [])->groupBy(fn ($item) => (string) ($item['hsn_code_snapshot'] ?? '-'));
        $rows = [];

        foreach ($groups as $hsn => $items) {
            $rate = (float) $items->avg(fn ($item) => (float) ($item['gst_rate'] ?? 0));
            $taxable = $items->sum(fn ($item) => (float) ($item['taxable_amount'] ?? 0));
            $tax = $items->sum(fn ($item) => max(0, (float) ($item['line_total'] ?? 0) - (float) ($item['taxable_amount'] ?? 0)));
            $halfRate = $rate / 2;
            $cgst = ($sale['tax_type'] ?? 'intrastate') === 'interstate' ? 0 : $tax / 2;
            $sgst = ($sale['tax_type'] ?? 'intrastate') === 'interstate' ? 0 : $tax / 2;

            $rows[] = '<tr><td>' . e($hsn) . '</td><td class="right">' . $money($taxable) . '</td><td class="center">' . $this->percent($halfRate) . '</td><td class="right">' . $money($cgst) . '</td><td class="center">' . $this->percent($halfRate) . '</td><td class="right">' . $money($sgst) . '</td><td class="right">' . $money($tax) . '</td></tr>';
        }

        return implode('', $rows);
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

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.') . '%';
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
@page{size:A4 portrait;margin:8mm}*{box-sizing:border-box}body{margin:0;background:#f3f4f6;color:#000;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.25}.action-bar{position:sticky;top:0;text-align:center;padding:10px;background:#f3f4f6}.action-bar button{min-height:36px;padding:7px 14px;border:1px solid #111;background:#fff;color:#111;font-weight:700;cursor:pointer}.invoice-page{width:198mm;min-height:281mm;margin:0 auto;background:#fff}.title{margin:0;padding:5px 0;border:1px solid #555;border-bottom:0;text-align:center;font-size:14px;font-weight:800}.invoice-head{display:grid;grid-template-columns:1fr 1fr;border:1px solid #555}.seller{min-height:76px;padding:7px;border-right:1px solid #555}.seller-top{display:flex;align-items:flex-start;gap:8px}.seller-logo{width:54px;max-height:54px;object-fit:contain;flex:0 0 auto}.seller h2{margin:0 0 4px;font-size:16px;text-transform:uppercase}.seller p,.bill-to p,.footer-grid p{margin:3px 0}.meta{display:grid;grid-template-columns:1fr 1fr}.meta div{min-height:30px;padding:6px;border-right:1px solid #555;border-bottom:1px solid #555}.meta div:nth-child(2n){border-right:0}.meta div:last-child{grid-column:1 / -1;border-bottom:0}.meta span,.bill-to>span,.amount-row span{display:block;font-size:10px}.meta strong{display:block;margin-top:2px}.bill-to{min-height:86px;padding:6px;border:1px solid #555;border-top:0}.bill-to h3{margin:6px 0 6px;font-size:12px;text-transform:uppercase}.items,.amount-row table,.tax-summary{width:100%;border-collapse:collapse}.items th,.items td,.amount-row td,.amount-row th,.tax-summary th,.tax-summary td{padding:5px;border:1px solid #555;vertical-align:top}.items th,.tax-summary th{font-weight:800}.items strong{font-size:11px}.right{text-align:right}.center{text-align:center}.strong,.total-row td{font-weight:800}.total-row td{background:#fafafa}.amount-row{display:grid;grid-template-columns:1fr 1fr;border-left:1px solid #555;border-right:1px solid #555}.amount-row>div{min-height:84px;padding:7px;border-right:1px solid #555}.amount-row table th,.amount-row table td{border-top:0;border-right:0}.amount-row table tr:last-child td{border-bottom:0}.tax-summary th{text-align:center}.tax-summary .total-row td:first-child{text-align:right}.footer-grid{display:grid;grid-template-columns:1fr 1fr 1fr;border:1px solid #555;border-top:0}.footer-grid>div{min-height:92px;padding:8px;border-right:1px solid #555}.footer-grid>div:last-child{border-right:0}.footer-grid h4{margin:0 0 7px;font-size:11px}.signature{text-align:center}.signature p{margin-bottom:52px}.signature strong{display:block}.invoice-page table{page-break-inside:auto}.invoice-page tr{page-break-inside:avoid;page-break-after:auto}@media(max-width:820px){body{background:#fff}.invoice-page{width:100%;min-height:auto}.invoice-head,.amount-row,.footer-grid{grid-template-columns:1fr}.seller,.amount-row>div,.footer-grid>div{border-right:0}.meta{grid-template-columns:1fr}.meta div{border-right:0}.items,.tax-summary{font-size:10px}}@media print{body{background:#fff}.no-print{display:none}.invoice-page{width:194mm;min-height:auto;margin:0}}</style>';
    }
}
