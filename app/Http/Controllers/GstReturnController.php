<?php

namespace App\Http\Controllers;

use App\Services\GstReturnService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GstReturnController extends Controller
{
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        return [date('Y-m-d', strtotime($data['from'])), date('Y-m-d', strtotime($data['to']))];
    }

    public function data(Request $request, GstReturnService $service)
    {
        abort_unless(AppController::canOpen('accounts'), 403);
        [$from, $to] = $this->period($request);
        $d = $service->build(AppController::businessId(), $from, $to);
        // keep the screen light: summaries + the first rows only
        $d['gstr1']['b2b'] = collect($d['gstr1']['b2b'])->take(200)->values();
        $d['gstr1']['b2cs'] = collect($d['gstr1']['b2cs'])->take(200)->values();
        $d['gstr1']['hsn'] = collect($d['gstr1']['hsn'])->take(200)->values();
        return response()->json($d);
    }

    public function export(Request $request, GstReturnService $service)
    {
        abort_unless(AppController::canOpen('accounts'), 403);
        [$from, $to] = $this->period($request);
        $format = $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'json'], true), 422);
        $d = $service->build(AppController::businessId(), $from, $to);
        $base = 'GSTR-' . date('Ym', strtotime($to));

        if ($format === 'json') {
            return response()->streamDownload(fn () => print(json_encode($service->gstr1Json($d), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)), $base . '-GSTR1.json', ['Content-Type' => 'application/json']);
        }

        $book = new Spreadsheet();
        $g = $d['gstr1'];
        $sheet = fn (string $title, array $head, iterable $rows, bool $first = false) => (function () use ($book, $title, $head, $rows, $first) {
            $ws = $first ? $book->getActiveSheet() : $book->createSheet();
            $ws->setTitle($title);
            $ws->fromArray($head, null, 'A1');
            $ws->getStyle('A1:' . $ws->getHighestColumn() . '1')->getFont()->setBold(true);
            $r = 2;
            foreach ($rows as $row) { $ws->fromArray(array_values($row), null, 'A' . $r++); }
            foreach (range('A', $ws->getHighestColumn()) as $col) $ws->getColumnDimension($col)->setAutoSize(true);
        })();

        $sheet('GSTR1-B2B', ['GSTIN', 'Customer', 'Invoice No', 'Invoice Date', 'Place of Supply', 'Invoice Value', 'Rate %', 'Taxable', 'IGST', 'CGST', 'SGST', 'Cess'],
            collect($g['b2b'])->flatMap(fn ($i) => collect($i['rates'])->map(fn ($r) => [$i['gstin'], $i['customer'], $i['invoice_number'], $i['invoice_date'], $i['pos'], $i['value'], $r['rate'], $r['taxable'], $r['igst'], $r['cgst'], $r['sgst'], $r['cess']])), true);
        $sheet('B2CL', ['Invoice No', 'Invoice Date', 'Place of Supply', 'Invoice Value', 'Rate %', 'Taxable', 'IGST', 'Cess'],
            collect($g['b2cl'])->flatMap(fn ($i) => collect($i['rates'])->map(fn ($r) => [$i['invoice_number'], $i['invoice_date'], $i['pos'], $i['value'], $r['rate'], $r['taxable'], $r['igst'], $r['cess']])));
        $sheet('B2CS', ['Supply Type', 'Place of Supply', 'Rate %', 'Taxable', 'IGST', 'CGST', 'SGST', 'Cess'],
            collect($g['b2cs'])->map(fn ($r) => [$r['supply'], $r['pos'], $r['rate'], $r['taxable'], $r['igst'], $r['cgst'], $r['sgst'], $r['cess']]));
        $sheet('Credit Notes', ['Note No', 'Note Date', 'Customer', 'GSTIN', 'Note Value', 'Taxable', 'IGST', 'CGST', 'SGST', 'Cess'],
            collect($g['cdn'])->map(fn ($r) => [$r['note_number'], $r['note_date'], $r['customer'], $r['gstin'], $r['value'], $r['taxable'], $r['igst'], $r['cgst'], $r['sgst'], $r['cess']]));
        $sheet('HSN Summary', ['HSN', 'Description', 'UQC', 'Quantity', 'Rate %', 'Taxable', 'IGST', 'CGST', 'SGST', 'Cess'],
            collect($g['hsn'])->map(fn ($h) => [$h['hsn'], $h['description'], $h['uqc'], $h['quantity'], $h['rate'], $h['taxable'], $h['igst'], $h['cgst'], $h['sgst'], $h['cess']]));
        $t = $d['gstr3b'];
        $sheet('GSTR3B', ['Particular', 'Taxable', 'IGST', 'CGST', 'SGST', 'Cess'], [
            ['3.1(a) Outward taxable supplies', $t['outward']['taxable'], $t['outward']['igst'], $t['outward']['cgst'], $t['outward']['sgst'], $t['outward']['cess']],
            ['Less: Credit notes', $t['credit_notes']['taxable'], $t['credit_notes']['igst'], $t['credit_notes']['cgst'], $t['credit_notes']['sgst'], $t['credit_notes']['cess']],
            ['Net outward tax liability', $t['net_outward']['taxable'], $t['net_outward']['igst'], $t['net_outward']['cgst'], $t['net_outward']['sgst'], $t['net_outward']['cess']],
            ['4(A) ITC available (from purchases)', $t['itc']['taxable'], $t['itc']['igst'], $t['itc']['cgst'], $t['itc']['sgst'], $t['itc']['cess']],
            ['Net tax payable (negative = ITC carry forward)', '', $t['net_payable']['igst'], $t['net_payable']['cgst'], $t['net_payable']['sgst'], $t['net_payable']['cess']],
        ]);
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) { (new Xlsx($book))->save('php://output'); }, $base . '-working.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
