<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class TallyXmlNormalizer
{
    private \DOMXPath $query;

    public function normalize(array $record): array
    {
        $xml = data_get($record, 'payload.xml');
        if (!$xml) return $record;
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw ValidationException::withMessages(['xml' => 'DTD and external entities are not accepted.']);

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) throw ValidationException::withMessages(['xml' => 'Tally returned malformed XML.']);
        $node = $dom->documentElement;
        $this->query = new \DOMXPath($dom);
        if ($node->localName === 'ENVELOPE') {
            $supported = $this->query->query('//*[local-name()="COMPANY" or local-name()="GROUP" or local-name()="LEDGER" or local-name()="UNIT" or local-name()="TALLYUNIT" or local-name()="STOCKITEM" or local-name()="VOUCHER"]');
            $node = $supported->length === 1 ? $supported->item(0) : null;
            if (!$node instanceof \DOMElement) throw ValidationException::withMessages(['xml' => 'Tally envelope must contain exactly one supported company, group, ledger, unit, stock item or voucher.']);
        }
        $name = $this->text($node, 'NAME') ?: ($record['name'] ?? null);
        $guid = $this->text($node, 'GUID') ?: ($record['guid'] ?? null);
        $alterId = $this->text($node, 'ALTERID');
        $sourceKey = $guid ?: ($alterId ? 'alterid:' . $alterId : ($record['key'] ?? null));
        if (!$sourceKey) throw ValidationException::withMessages(['xml' => 'Tally record has no stable GUID, ALTERID or key.']);

        if ($node->localName === 'VOUCHER' || $record['type'] === 'voucher') return $this->voucher($node, $record, $sourceKey);
        if ($node->localName === 'LEDGER') {
            $parent = $this->text($node, 'PARENT');
            $type = str_contains(strtolower((string) $parent), 'debtor') ? 'customer' : (str_contains(strtolower((string) $parent), 'creditor') ? 'supplier' : 'ledger');
            return $this->master($type, $node, $record, $sourceKey, ['parent' => $parent, 'opening_balance' => $this->signedAmount($this->text($node, 'OPENINGBALANCE')), 'closing_balance' => $this->signedAmount($this->text($node, 'CLOSINGBALANCE')), 'gstin' => $this->text($node, 'PARTYGSTIN') ?: $this->text($node, 'GSTIN')]);
        }
        if ($node->localName === 'GROUP') return $this->master('group', $node, $record, $sourceKey, ['parent' => $this->text($node, 'PARENT')]);
        if ($node->localName === 'STOCKITEM') return $this->master('stock_item', $node, $record, $sourceKey, ['parent' => $this->text($node, 'PARENT'), 'unit' => $this->text($node, 'BASEUNITS') ?: $this->text($node, 'UNIT'), 'opening_quantity' => $this->number($this->text($node, 'OPENINGBALANCE')), 'opening_value' => $this->amount($this->text($node, 'OPENINGVALUE')), 'hsn_code' => $this->text($node, 'HSNCODE') ?: $this->text($node, 'HSN')]);
        if ($node->localName === 'UNIT') return $this->master('unit', $node, $record, $sourceKey, ['formal_name' => $this->text($node, 'NAME')]);
        if ($node->localName === 'COMPANY') return $this->master('company', $node, $record, $sourceKey, ['gstin' => $this->text($node, 'GSTIN'), 'books_from' => $this->text($node, 'BOOKSFROM')]);
        throw ValidationException::withMessages(['xml' => 'Unsupported Tally XML node: ' . $node->localName]);
    }

    private function voucher(\DOMElement $node, array $record, string $sourceKey): array
    {
        $voucherName = strtoupper(trim($this->text($node, 'VOUCHERTYPENAME') ?: $this->text($node, 'VOUCHERTYPE')));
        $alterId = $this->text($node, 'ALTERID');
        $type = match (true) {
            str_contains($voucherName, 'SALES RETURN'), str_contains($voucherName, 'CREDIT NOTE') => 'sales_return',
            str_contains($voucherName, 'PURCHASE RETURN'), str_contains($voucherName, 'DEBIT NOTE') => 'purchase_return',
            str_contains($voucherName, 'SALES') => 'sales', str_contains($voucherName, 'PURCHASE') => 'purchase',
            str_contains($voucherName, 'RECEIPT') => 'receipt', str_contains($voucherName, 'PAYMENT') => 'payment',
            str_contains($voucherName, 'CONTRA') => 'contra', str_contains($voucherName, 'JOURNAL') => 'journal',
            default => null,
        };
        $date = $this->date($this->text($node, 'DATE'));
        if (!$type || !$date) throw ValidationException::withMessages(['xml' => "Unsupported or incomplete Tally voucher type '{$voucherName}'."]);
        $ledgerEntries = [];
        $debit = $credit = 0.0;
        foreach ($this->nodes($node, 'ALLLEDGERENTRIES.LIST') as $entry) {
            $amount = $this->amount($this->text($entry, 'AMOUNT'));
            if (!$amount) continue;
            $isDebit = strtoupper($this->text($entry, 'ISDEEMEDPOSITIVE')) === 'YES';
            $isDebit ? $debit += $amount : $credit += $amount;
            $ledgerEntries[] = ['name' => $this->text($entry, 'LEDGERNAME'), 'amount' => $amount, 'debit' => $isDebit ? $amount : 0, 'credit' => $isDebit ? 0 : $amount, 'bill_allocations' => $this->billAllocations($entry)];
        }
        $inventory = [];
        foreach ($this->nodes($node, 'ALLINVENTORYENTRIES.LIST') as $entry) {
            $quantity = $this->number($this->text($entry, 'ACTUALQTY') ?: $this->text($entry, 'BILLEDQTY'));
            $rate = $this->amount($this->text($entry, 'RATE'));
            $inventory[] = ['item_name' => $this->text($entry, 'STOCKITEMNAME'), 'quantity' => $quantity, 'rate' => $rate, 'amount' => $this->amount($this->text($entry, 'AMOUNT')), 'unit' => $this->text($entry, 'UOM') ?: $this->text($entry, 'UNIT'), 'godown' => $this->text($entry, 'GODOWNNAME'), 'batch' => $this->text($entry, 'BATCHNAME')];
        }
        if (!$ledgerEntries && !$inventory) throw ValidationException::withMessages(['xml' => 'Tally voucher contains no ledger or inventory allocations.']);
        $balanced = abs($debit - $credit) < 0.01;
        if (!$balanced && in_array($type, ['journal', 'contra'], true)) throw ValidationException::withMessages(['xml' => 'Tally journal/contra voucher is not balanced.']);
        $tax = ['cgst_amount' => 0.0, 'sgst_amount' => 0.0, 'igst_amount' => 0.0, 'cess_amount' => 0.0, 'taxable_amount' => 0.0];
        foreach ($ledgerEntries as $entry) {
            $ledgerName = strtolower((string) $entry['name']); $amount = (float) $entry['amount'];
            if (str_contains($ledgerName, 'cgst')) $tax['cgst_amount'] += $amount;
            elseif (str_contains($ledgerName, 'sgst')) $tax['sgst_amount'] += $amount;
            elseif (str_contains($ledgerName, 'igst')) $tax['igst_amount'] += $amount;
            elseif (str_contains($ledgerName, 'cess')) $tax['cess_amount'] += $amount;
            elseif (str_contains($ledgerName, $type)) $tax['taxable_amount'] += $amount;
        }
        $tax['grand_total'] = round(max($debit, $credit), 2);
        return ['type' => $type, 'key' => $sourceKey, 'guid' => $this->text($node, 'GUID'), 'name' => $this->text($node, 'PARTYLEDGERNAME') ?: $this->text($node, 'PARTYNAME'), 'date' => $date, 'number' => $this->text($node, 'VOUCHERNUMBER') ?: $this->text($node, 'REFERENCE'), 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'payload' => array_merge(['xml' => $node->ownerDocument->saveXML($node), 'voucher_type' => $voucherName, 'voucher_date' => $date, 'voucher_number' => $this->text($node, 'VOUCHERNUMBER'), 'reference_number' => $this->text($node, 'REFERENCE'), 'party_name' => $this->text($node, 'PARTYLEDGERNAME') ?: $this->text($node, 'PARTYNAME'), 'narration' => $this->text($node, 'NARRATION'), 'cancelled' => strtoupper($this->text($node, 'ISCANCELLED')) === 'YES', 'altered' => $alterId !== null, 'ledger_entries' => $ledgerEntries, 'inventory_allocations' => $inventory, 'tally_totals_balanced' => $balanced], $tax)];
    }

    private function master(string $type, \DOMElement $node, array $record, string $key, array $extra): array { return ['type' => $type, 'key' => $key, 'guid' => $this->text($node, 'GUID'), 'name' => $this->text($node, 'NAME') ?: ($record['name'] ?? null), 'group' => $this->text($node, 'PARENT'), 'payload' => array_merge(['xml' => $node->ownerDocument->saveXML($node)], $extra)]; }
    private function text(\DOMNode $node, string $name): ?string { $found = $node->localName === $name ? $node : $this->query->query(".//*[local-name()='$name']", $node)->item(0); return $found ? trim((string) $found->textContent) : null; }
    private function nodes(\DOMNode $node, string $name): array { $list = $this->query->query(".//*[local-name()='$name']", $node); $rows = []; foreach ($list as $item) $rows[] = $item; return $rows; }
    private function amount(?string $value): float { if ($value === null || trim($value) === '') return 0.0; $value = str_replace([',', ' '], '', $value); return round(abs((float) preg_replace('/[^0-9.\-]/', '', $value)), 2); }
    private function signedAmount(?string $value): float { if ($value === null || trim($value) === '') return 0.0; $value = str_replace([',', ' '], '', $value); return round((float) preg_replace('/[^0-9.\-]/', '', $value), 2); }
    private function number(?string $value): float { if (!$value) return 0.0; return (float) preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value)); }
    private function date(?string $value): ?string { if (!$value) return null; $value = preg_replace('/[^0-9]/', '', $value); return strlen($value) === 8 ? substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2) : null; }
    private function billAllocations(\DOMNode $entry): array { $rows = []; foreach ($this->nodes($entry, 'BILLALLOCATIONS.LIST') as $bill) $rows[] = ['name' => $this->text($bill, 'NAME'), 'type' => $this->text($bill, 'BILLTYPE'), 'amount' => $this->amount($this->text($bill, 'AMOUNT'))]; return $rows; }
}
