<?php

namespace App\Http\Controllers;

use App\Services\InvoiceDocumentRenderer;
use App\Services\PublicDocumentShareService;
use App\Services\SalesService;

class PublicInvoiceController extends Controller
{
    public function show(string $token, PublicDocumentShareService $shares, SalesService $sales, InvoiceDocumentRenderer $renderer)
    {
        $voucher = $shares->resolveSalesInvoice($token);

        return response($renderer->renderSalesInvoice($sales->present($voucher), true));
    }

    public function quotation(string $token, PublicDocumentShareService $shares, InvoiceDocumentRenderer $renderer)
    {
        return response($renderer->renderQuotation($shares->resolveQuotation($token)));
    }

    public function pdf(string $token, PublicDocumentShareService $shares, SalesService $sales, InvoiceDocumentRenderer $renderer)
    {
        $voucher = $shares->resolveSalesInvoice($token);

        return $renderer->salesInvoicePdf($sales->present($voucher, true));
    }
}
