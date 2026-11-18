<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds GSTR-1 and GSTR-3B working figures from approved sales, sales returns (credit notes) and purchases
 * of ONE business for a date range. These are working papers: verify against the GST portal / offline tool before filing.
 */
class GstReturnService
{
    private const B2CL_LIMIT = 250000;

    public function build(int $businessId, string $from, string $to): array
    {
        $company = Company::find($businessId);
        $homeState = substr((string) ($company->gstin ?? ''), 0, 2);
        // GST state codes follow LGD codes except these three.
        $override = ['AP' => 37, 'LA' => 38, 'DN' => 26];
        $states = Schema::hasTable('states')
            ? DB::table('states')->get(['id', 'code', 'lgd_code'])->mapWithKeys(fn ($r) => [$r->id => str_pad((string) ($override[$r->code] ?? $r->lgd_code), 2, '0', STR_PAD_LEFT)])
            : collect();

        $items = DB::table('sales_items as i')
            ->join('sales_vouchers as v', 'v.id', '=', 'i.sales_voucher_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->where('v.business_id', $businessId)->where('v.status', 'approved')
            ->whereBetween('v.invoice_date', [$from, $to])
            ->get(['v.id as vid', 'v.invoice_number', 'v.invoice_date', 'v.grand_total', 'v.customer_name_snapshot', 'v.customer_gstin_snapshot',
                'v.place_of_supply_state_id', DB::raw("COALESCE(NULLIF(i.hsn_code_snapshot, ''), p.hsn_code) as hsn"), 'i.product_name_snapshot as pname', 'u.code as uqc', 'i.quantity',
                'i.taxable_amount', 'i.gst_rate', 'i.cgst_amount', 'i.sgst_amount', 'i.igst_amount', 'i.cess_amount']);

        $invoices = $items->groupBy('vid')->map(function (Collection $lines) use ($states, $homeState) {
            $f = $lines->first();
            $gstin = strtoupper(trim((string) $f->customer_gstin_snapshot));
            $pos = $states[$f->place_of_supply_state_id] ?? ($gstin !== '' ? substr($gstin, 0, 2) : $homeState);
            $igst = (float) $lines->sum('igst_amount');
            return [
                'invoice_number' => $f->invoice_number, 'invoice_date' => $f->invoice_date, 'customer' => $f->customer_name_snapshot,
                'gstin' => $gstin, 'pos' => str_pad((string) $pos, 2, '0', STR_PAD_LEFT), 'value' => round((float) $f->grand_total, 2),
                'inter_state' => $igst > 0,
                'rates' => $this->byRate($lines),
            ];
        })->values();

        $b2b = $invoices->filter(fn ($i) => $i['gstin'] !== '')->values();
        $unreg = $invoices->filter(fn ($i) => $i['gstin'] === '');
        $b2cl = $unreg->filter(fn ($i) => $i['inter_state'] && $i['value'] > self::B2CL_LIMIT)->values();
        $b2cs = $unreg->reject(fn ($i) => $i['inter_state'] && $i['value'] > self::B2CL_LIMIT)
            ->flatMap(fn ($i) => collect($i['rates'])->map(fn ($r) => $r + ['pos' => $i['pos'], 'supply' => $i['inter_state'] ? 'INTER' : 'INTRA']))
            ->groupBy(fn ($r) => $r['pos'] . '|' . $r['supply'] . '|' . $r['rate'])
            ->map(fn ($g) => ['pos' => $g[0]['pos'], 'supply' => $g[0]['supply'], 'rate' => $g[0]['rate']] + $this->sum($g))->values();

        $hsn = $items->groupBy(fn ($r) => ($r->hsn ?: 'NA') . '|' . ($r->uqc ?: 'NOS') . '|' . (string) (float) $r->gst_rate)->map(fn ($g) => [
            'hsn' => $g[0]->hsn ?: 'NA', 'description' => $g[0]->pname, 'uqc' => strtoupper($g[0]->uqc ?: 'NOS'), 'rate' => (float) $g[0]->gst_rate,
            'quantity' => round((float) $g->sum('quantity'), 3),
        ] + $this->sum($g->map(fn ($r) => ['taxable' => $r->taxable_amount, 'cgst' => $r->cgst_amount, 'sgst' => $r->sgst_amount, 'igst' => $r->igst_amount, 'cess' => $r->cess_amount])))->values();

        $credit = DB::table('sales_return_vouchers as r')
            ->leftJoin('customers as c', 'c.id', '=', 'r.customer_id')
            ->where('r.business_id', $businessId)->where('r.status', 'approved')->whereBetween('r.return_date', [$from, $to])
            ->get(['r.credit_note_number', 'r.voucher_number', 'r.return_date', 'r.grand_total', 'r.taxable_amount', 'r.cgst_amount', 'r.sgst_amount', 'r.igst_amount', 'r.cess_amount', 'c.customer_name', 'c.gstin'])
            ->map(fn ($r) => [
                'note_number' => $r->credit_note_number ?: $r->voucher_number, 'note_date' => $r->return_date, 'customer' => $r->customer_name, 'gstin' => strtoupper((string) $r->gstin),
                'value' => round((float) $r->grand_total, 2), 'taxable' => round((float) $r->taxable_amount, 2), 'cgst' => round((float) $r->cgst_amount, 2),
                'sgst' => round((float) $r->sgst_amount, 2), 'igst' => round((float) $r->igst_amount, 2), 'cess' => round((float) $r->cess_amount, 2),
            ])->values();

        $purchase = DB::table('purchase_vouchers')->where('business_id', $businessId)->where('status', 'approved')->whereBetween('purchase_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(taxable_amount),0) taxable, COALESCE(SUM(cgst_amount),0) cgst, COALESCE(SUM(sgst_amount),0) sgst, COALESCE(SUM(igst_amount),0) igst, COALESCE(SUM(cess_amount),0) cess')->first();

        $out = $this->sum($items->map(fn ($r) => ['taxable' => $r->taxable_amount, 'cgst' => $r->cgst_amount, 'sgst' => $r->sgst_amount, 'igst' => $r->igst_amount, 'cess' => $r->cess_amount]));
        $cn = ['taxable' => $credit->sum('taxable'), 'cgst' => $credit->sum('cgst'), 'sgst' => $credit->sum('sgst'), 'igst' => $credit->sum('igst'), 'cess' => $credit->sum('cess')];
        $net = array_map(fn ($k) => round($out[$k] - $cn[$k], 2), array_combine(['taxable', 'cgst', 'sgst', 'igst', 'cess'], ['taxable', 'cgst', 'sgst', 'igst', 'cess']));
        $itc = array_map(fn ($v) => round((float) $v, 2), (array) $purchase);
        $payable = [];
        foreach (['igst', 'cgst', 'sgst', 'cess'] as $k) $payable[$k] = round($net[$k] - $itc[$k], 2);

        return [
            'business' => ['name' => $company->name ?? '', 'gstin' => $company->gstin ?? ''],
            'period' => ['from' => $from, 'to' => $to],
            'gstr1' => [
                'b2b' => $b2b, 'b2cl' => $b2cl, 'b2cs' => $b2cs, 'cdn' => $credit, 'hsn' => $hsn,
                'counts' => ['invoices' => $invoices->count(), 'b2b' => $b2b->count(), 'b2cl' => $b2cl->count(), 'b2cs_rows' => $b2cs->count(), 'credit_notes' => $credit->count()],
                'missing_hsn' => $items->filter(fn ($r) => !$r->hsn)->pluck('vid')->unique()->count(),
                'odd_rates' => $items->pluck('gst_rate')->map(fn ($r) => (float) $r)->unique()->reject(fn ($r) => in_array($r, [0.0, 0.1, 0.25, 1.5, 3.0, 5.0, 6.0, 7.5, 12.0, 18.0, 28.0, 40.0], true))->sort()->values()->all(),
            ],
            'gstr3b' => ['outward' => $out, 'credit_notes' => $cn, 'net_outward' => $net, 'itc' => $itc, 'net_payable' => $payable],
        ];
    }

    private function byRate(Collection $lines): array
    {
        return $lines->groupBy(fn ($r) => (string) (float) $r->gst_rate)->map(fn ($g, $rate) => ['rate' => (float) $rate] + $this->sum($g->map(
            fn ($r) => ['taxable' => $r->taxable_amount, 'cgst' => $r->cgst_amount, 'sgst' => $r->sgst_amount, 'igst' => $r->igst_amount, 'cess' => $r->cess_amount]
        )))->values()->all();
    }

    private function sum(Collection $rows): array
    {
        $rows = $rows->map(fn ($r) => (array) $r);
        return [
            'taxable' => round((float) $rows->sum('taxable'), 2), 'cgst' => round((float) $rows->sum('cgst'), 2),
            'sgst' => round((float) $rows->sum('sgst'), 2), 'igst' => round((float) $rows->sum('igst'), 2), 'cess' => round((float) $rows->sum('cess'), 2),
        ];
    }

    /** GSTR-1 JSON in the shape of the GST offline tool. Always verify in the tool before uploading. */
    public function gstr1Json(array $d): array
    {
        $g = $d['gstr1'];
        $fp = date('mY', strtotime($d['period']['to']));
        $itm = fn ($r, $n) => ['num' => $n, 'itm_det' => ['rt' => $r['rate'], 'txval' => $r['taxable'], 'iamt' => $r['igst'], 'camt' => $r['cgst'], 'samt' => $r['sgst'], 'csamt' => $r['cess']]];
        $b2b = collect($g['b2b'])->groupBy('gstin')->map(fn ($invs, $ctin) => ['ctin' => $ctin, 'inv' => $invs->map(fn ($i) => [
            'inum' => $i['invoice_number'], 'idt' => date('d-m-Y', strtotime($i['invoice_date'])), 'val' => $i['value'], 'pos' => $i['pos'], 'rchrg' => 'N', 'inv_typ' => 'R',
            'itms' => collect($i['rates'])->values()->map(fn ($r, $k) => $itm($r, $k + 1))->all(),
        ])->values()->all()])->values()->all();

        return array_filter([
            'gstin' => $d['business']['gstin'], 'fp' => $fp, 'version' => 'GST3.0.4', 'hash' => 'hash',
            'b2b' => $b2b,
            'b2cs' => collect($g['b2cs'])->map(fn ($r) => ['sply_ty' => $r['supply'], 'rt' => $r['rate'], 'typ' => 'OE', 'pos' => $r['pos'], 'txval' => $r['taxable'], 'iamt' => $r['igst'], 'camt' => $r['cgst'], 'samt' => $r['sgst'], 'csamt' => $r['cess']])->all(),
            'hsn' => ['data' => collect($g['hsn'])->values()->map(fn ($h, $k) => ['num' => $k + 1, 'hsn_sc' => $h['hsn'], 'desc' => mb_substr((string) $h['description'], 0, 30), 'uqc' => $h['uqc'], 'qty' => $h['quantity'], 'rt' => $h['rate'], 'txval' => $h['taxable'], 'iamt' => $h['igst'], 'camt' => $h['cgst'], 'samt' => $h['sgst'], 'csamt' => $h['cess']])->all()],
        ], fn ($v) => $v !== [] && $v !== ['data' => []]);
    }
}
