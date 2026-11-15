<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses a TallyPrime "Day Book" Excel export (detailed, with inventory) into structured vouchers.
 *
 * Voucher shape:
 *  [date, type, number, party, debit, credit, refs[], ledgers[], items[], narration, row, text_lines[]]
 *  ledgers: [name, debit, credit]   items: [name, qty, rate, amount, unit, direction(in|out|null)]
 *  refs: [kind(New Ref|Agst Ref|On Account|Advance), name, amount, side]
 */
class TallyDayBookParser
{
    private const REF_KINDS = ['New Ref', 'Agst Ref', 'On Account', 'Advance'];

    public array $anomalies = [];

    public function parse(string $file): array
    {
        ini_set('memory_limit', '4G');
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($file)->getActiveSheet()->toArray(null, false, false, false);

        $vouchers = [];
        $current = null;
        for ($i = 8, $n = count($rows); $i < $n; $i++) {
            $r = $rows[$i];
            $rowNo = $i + 1;
            if ($this->isHeader($r)) {
                if ($current) $vouchers[] = $current;
                $current = [
                    'row' => $rowNo,
                    'date' => $this->excelDate($r[0]),
                    'type' => trim((string) $r[6]),
                    'number' => trim((string) $r[7]),
                    'party' => $this->clean($r[1]),
                    'header_qty' => null,
                    'debit' => $this->num($r[8]),
                    'credit' => $this->num($r[9]),
                    'refs' => [], 'ledgers' => [], 'items' => [], 'text' => [],
                    'header_raw' => [$r[8] ?? null, $r[9] ?? null],
                ];
                continue;
            }
            if (!$current) continue;
            $this->line($current, $r, $rowNo);
        }
        if ($current) $vouchers[] = $current;
        return $vouchers;
    }

    private function isHeader(array $r): bool
    {
        return $r[0] !== null && $r[0] !== '' && is_numeric($r[0]) && trim((string) ($r[6] ?? '')) !== '';
    }

    private function line(array &$v, array $r, int $rowNo): void
    {
        $name = $this->clean($r[1] ?? null);
        $c2 = $r[2] ?? null; $c3 = $r[3] ?? null; $c4 = $r[4] ?? null; $c5 = $r[5] ?? null;
        $d = $r[8] ?? null; $c = $r[9] ?? null;

        if ($name !== '' && in_array($name, self::REF_KINDS, true)) {
            $v['refs'][] = ['kind' => $name, 'name' => $this->clean($c2), 'amount' => $this->num($c4), 'side' => $this->clean($c5)];
            return;
        }
        if ($name === '' && $c2 !== null) { // bank instrument rows: "Cheque/DD", "Available Only in Books"
            return;
        }
        if ($name === '') return;

        // Inventory line with quantity/rate/amount (sales/purchase style)
        if (is_numeric($c2) && is_numeric($c3) && $c4 !== null && $d === null && $c === null) {
            $v['items'][] = ['name' => $name, 'qty' => (float) $c2, 'rate' => (float) $c3, 'amount' => $this->num($c4), 'unit' => null, 'dir' => null, 'row' => $rowNo];
            return;
        }
        // Inventory line with quantity text in the Inwards/Outwards columns (stock journal / delivery note)
        foreach ([[8, 'in'], [9, 'out']] as [$col, $dir]) {
            $val = $r[$col] ?? null;
            if (is_string($val) && preg_match('/^\s*(-?[\d,]+(?:\.\d+)?)\s*([A-Za-z]+)\s*$/', $val, $m)) {
                $v['items'][] = ['name' => $name, 'qty' => (float) str_replace(',', '', $m[1]), 'rate' => null, 'amount' => null, 'unit' => strtoupper($m[2]), 'dir' => $dir, 'row' => $rowNo];
                return;
            }
        }
        // Inventory line with quantity but no rate (e.g. amount only)
        if (is_numeric($c2) && $d === null && $c === null && ($c3 !== null || $c4 !== null)) {
            $v['items'][] = ['name' => $name, 'qty' => (float) $c2, 'rate' => is_numeric($c3) ? (float) $c3 : null, 'amount' => $this->num($c4), 'unit' => null, 'dir' => null, 'row' => $rowNo];
            $this->anomalies[] = "Row $rowNo: item line without full qty/rate/amount: $name";
            return;
        }
        // Ledger line with Dr/Cr amount
        if (($d !== null || $c !== null) && $c2 === null && $c3 === null && $c4 === null) {
            $v['ledgers'][] = ['name' => $name, 'debit' => $this->num($d), 'credit' => $this->num($c), 'row' => $rowNo];
            return;
        }
        // Value-only line inside a sale/purchase allocation (e.g. FREIGHT, CARTAGE): amount without qty/rate
        if ($c2 === null && $c3 === null && is_numeric($c4) && $d === null && $c === null) {
            $v['items'][] = ['name' => $name, 'qty' => 1.0, 'rate' => $this->num($c4), 'amount' => $this->num($c4), 'unit' => null, 'dir' => null, 'row' => $rowNo, 'value_only' => true];
            return;
        }
        // Plain text (party reference in delivery note, narration, destination, ledger with no amount)
        $v['text'][] = $name;
    }

    private function excelDate($serial): string
    {
        return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $serial)->format('Y-m-d');
    }

    private function num($v): float
    {
        if ($v === null || $v === '') return 0.0;
        if (is_numeric($v)) return round((float) $v, 2);
        return round((float) str_replace(',', '', (string) $v), 2);
    }

    private function clean($v): string
    {
        return trim(preg_replace('/\s{2,}/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $v)));
    }
}
