<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\BusinessAccountSetting;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\StockAdjustmentVoucher;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\PurchaseVoucher;
use App\Models\SalesVoucher;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Posts parsed Tally Day Book vouchers into ONE BillIQ company through the normal
 * Sales / Purchase / Accounting / Stock services, one voucher per savepoint.
 * A voucher that cannot be posted exactly (totals differ, unknown master, ...) is
 * recorded in $failures and skipped; it never produces a partial posting.
 */
class TallyDayBookImporter
{
    private const STD_RATES = [0, 5, 12, 18, 28, 3, 0.25, 1.5];

    public array $failures = [];
    public array $stats = [];
    public array $notes = [];
    public array $skipped = [];

    private int $bid;
    private int $branchId;
    private int $warehouseId;
    private int $stateId;
    private int $cashMethodId;
    private array $products = [];   // name => Product
    private array $customers = [];  // name => Customer
    private array $suppliers = [];  // name => Supplier
    private array $accounts = [];   // name => Account
    private array $rates = [];      // product id => gst rate
    private array $billRefs = [];   // party key => [bill ref / invoice no => sales voucher id]
    private int $seq = 0;

    public function __construct(
        private SalesService $sales,
        private PurchaseService $purchases,
        private SalesCalculationService $salesCalc,
        private PurchaseCalculationService $purchaseCalc,
        private AccountingPostingService $posting,
        private AccountingService $accounting,
        private StockService $stock,
    ) {}

    /**
     * Tally sometimes exports a purchase that was altered in "receipt format" as a Payment with negative amounts
     * (confirmed by the client for CHEF MASALE, purchase no. 90 on 21-Mar-2026). Re-type those as Purchases.
     */
    public function normalize(array $vouchers): array
    {
        foreach ($vouchers as &$v) {
            $hasPurchaseLedger = (bool) array_filter($v['ledgers'], fn ($l) => in_array(strtolower($l['name']), ['purchases', 'purchase'], true));
            if ($v['type'] === 'Payment' && $v['items'] && $hasPurchaseLedger) {
                $v['type'] = 'Purchase';
                $v['credit'] = abs($v['credit'] ?: $v['debit']);
                $v['debit'] = 0.0;
                $v['number'] = $v['number'] === '1' ? '90' : $v['number'];
                $v['text'][] = 'Re-typed from Payment (Tally receipt-format alteration)';
            }
        }
        return $vouchers;
    }

    public function prepare(int $bid, array $vouchers): void
    {
        $this->bid = $bid;
        $this->branchId = (int) Branch::where('business_id', $bid)->orderBy('id')->value('id');
        $this->warehouseId = (int) Warehouse::where('business_id', $bid)->orderBy('id')->value('id');
        $this->stateId = (int) DB::table('states')->where('name', 'Uttarakhand')->value('id');
        $this->cashMethodId = (int) PaymentMethod::where('name', 'Cash')->value('id');

        foreach (Product::where('business_id', $bid)->get() as $p) $this->products[$this->key($p->name)] = $p;
        foreach (Customer::where('business_id', $bid)->get() as $c) $this->customers[$this->key($c->customer_name)] = $c;
        foreach (Supplier::where('business_id', $bid)->get() as $s) $this->suppliers[$this->key($s->supplier_name)] = $s;
        foreach (Account::where('business_id', $bid)->get() as $a) $this->accounts[$this->key($a->account_name)] = $a;

        // Tally was run with negative stock allowed (verified: sales exceed recorded stock).
        Product::where('business_id', $bid)->update(['allow_negative_stock' => true]);

        $this->ensureMasters($vouchers);
        $this->learnRates($vouchers);
    }

    /** Create missing counterpart/stock masters up front so a failed voucher never leaves stale cached models. */
    private function ensureMasters(array $vouchers): void
    {
        foreach ($vouchers as $v) {
            if ($v['type'] === 'Sales' && !isset($this->accounts[$this->key($v['party'])])) $this->customer($v['party']);
            if ($v['type'] === 'Purchase') $this->supplier($v['party']);
            if (in_array($v['type'], ['Stock Journal', 'Delivery Note'], true)) {
                $this->product($v['party']);
                foreach ($v['items'] as $i) $this->product($i['name'], $i['unit'] ?? null);
                if ($v['type'] === 'Delivery Note' && ($v['text'][0] ?? null) && !isset($this->accounts[$this->key($v['text'][0])])) $this->customer($v['text'][0]);
            }
            if ($v['type'] === 'Receipt') $this->customer($v['party']);
            if (in_array($v['type'], ['Sales', 'Purchase'], true)) {
                foreach ($v['items'] as $i) !empty($i['value_only']) ? $this->serviceProduct($i['name']) : $this->product($i['name'], $i['unit'] ?? null);
            }
        }
    }

    /** Learn each product's GST rate from vouchers where the effective rate is unambiguous. */
    private function learnRates(array $vouchers): void
    {
        $votes = [];
        foreach ($vouchers as $v) {
            if (!in_array($v['type'], ['Sales', 'Purchase'], true)) continue;
            $parts = $this->split($v);
            if ($parts['taxable'] <= 0) continue;
            $names = array_unique(array_column(array_filter($v['items'], fn ($i) => empty($i['value_only'])), 'name'));
            if (count($names) !== 1) continue;
            $eff = $parts['tax'] * 100 / max($parts['taxable'] - $parts['discount'], 0.01);
            $std = $this->nearestStd($eff);
            if ($std === null) continue;
            $votes[$names[0]][(string) $std] = ($votes[$names[0]][(string) $std] ?? 0) + 1;
        }
        foreach ($votes as $name => $byRate) {
            $prod = $this->products[$this->key($name)] ?? null;
            if (!$prod) continue;
            arsort($byRate);
            $rate = (float) array_key_first($byRate);
            $this->rates[$prod->id] = $rate;
            $prod->update(['gst_rate' => $rate]);
        }
        $this->stats['gst_rates_learned'] = count($votes);
    }

    /** Tally exports differ from ledger masters in spacing around brackets; compare on a whitespace-free key. */
    private function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $name));
    }

    private function product(string $name, ?string $unit = null): Product
    {
        $k = $this->key($name);
        if (isset($this->products[$k])) return $this->products[$k];
        $unitModel = \App\Models\Unit::firstOrCreate(['code' => $unit ?: 'NOS'], ['name' => ucfirst(strtolower($unit ?: 'NOS')), 'status' => 'active']);
        $seq = Product::withTrashed()->where('business_id', $this->bid)->count() + 1;
        $p = Product::create([
            'business_id' => $this->bid, 'company_id' => $this->bid, 'name' => $name, 'product_name' => $name,
            'sku' => 'ITM-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT), 'product_type' => 'goods', 'item_type' => 'stock',
            'unit_id' => $unitModel->id, 'unit' => $unitModel->code, 'gst_rate' => 0, 'track_inventory' => true, 'allow_negative_stock' => true,
            'opening_stock' => 0, 'current_stock' => 0, 'status' => 'active', 'created_by' => Auth::id(),
        ]);
        $this->stats['products_created_from_vouchers'] = ($this->stats['products_created_from_vouchers'] ?? 0) + 1;
        return $this->products[$k] = $p;
    }

    /** Parties can be Sundry Debtors or Creditors in Tally; create the missing counterpart master on demand. */
    private function customer(string $name): ?Customer
    {
        $k = $this->key($name);
        if (isset($this->customers[$k])) return $this->customers[$k];
        $sup = $this->suppliers[$k] ?? null;
        $acc = $this->accounts[$k] ?? null;
        if (!$sup && !$acc) return null;
        $sup ??= (object) ['supplier_name' => $acc->account_name, 'gstin' => null, 'state_id' => null];
        $c = Customer::create([
            'business_id' => $this->bid, 'company_id' => $this->bid, 'customer_code' => 'CUS-' . str_pad((string) (Customer::withTrashed()->where('business_id', $this->bid)->count() + 1), 5, '0', STR_PAD_LEFT),
            'customer_name' => $sup->supplier_name, 'name' => $sup->supplier_name, 'customer_type' => 'retail', 'gstin' => $sup->gstin, 'state_id' => $sup->state_id,
            'opening_balance' => 0, 'opening_balance_type' => 'debit', 'status' => 'active', 'whatsapp_same_as_mobile' => false, 'created_by' => Auth::id(),
        ]);
        $this->stats['customers_created_for_dual_role_party'] = ($this->stats['customers_created_for_dual_role_party'] ?? 0) + 1;
        return $this->customers[$k] = $c;
    }

    private function supplier(string $name): ?Supplier
    {
        $k = $this->key($name);
        if (isset($this->suppliers[$k])) return $this->suppliers[$k];
        $cus = $this->customers[$k] ?? null;
        $acc = $this->accounts[$k] ?? null;
        if (!$cus && !$acc) return null;
        $cus ??= (object) ['customer_name' => $acc->account_name, 'gstin' => null, 'state_id' => null];
        $s = Supplier::create([
            'business_id' => $this->bid, 'supplier_code' => 'SUP-' . str_pad((string) (Supplier::withTrashed()->where('business_id', $this->bid)->count() + 1), 5, '0', STR_PAD_LEFT),
            'supplier_name' => $cus->customer_name, 'name' => $cus->customer_name, 'gstin' => $cus->gstin, 'state_id' => $cus->state_id,
            'opening_balance' => 0, 'opening_balance_type' => 'credit', 'status' => 'active', 'created_by' => Auth::id(),
        ]);
        $this->stats['suppliers_created_for_dual_role_party'] = ($this->stats['suppliers_created_for_dual_role_party'] ?? 0) + 1;
        return $this->suppliers[$k] = $s;
    }

    private function nearestStd(float $eff): ?float
    {
        foreach (self::STD_RATES as $r) if (abs($eff - $r) <= 0.15) return (float) $r;
        return null;
    }

    // ------------------------------------------------------------------ runner

    public function run(array $vouchers, array $types, ?int $limit = null): void
    {
        $done = 0;
        foreach ($vouchers as $v) {
            $kind = $this->kind($v['type']);
            if (!in_array($kind, $types, true)) continue;
            if (str_starts_with($v['number'], '(Optional)') || strcasecmp($v['party'], '(cancelled)') === 0) {
                $reason = str_starts_with($v['number'], '(Optional)') ? 'optional (memorandum) voucher' : 'cancelled voucher';
                $this->skipped[] = ['row' => $v['row'], 'no' => $v['number'], 'date' => $v['date'], 'party' => $v['party'], 'type' => $v['type'], 'amount' => max($v['debit'], $v['credit']), 'reason' => $reason];
                continue;
            }
            if ($limit !== null && $done >= $limit) break;
            $done++;
            try {
                DB::transaction(function () use ($v, $kind) {
                    match ($kind) {
                        'sales' => $this->sale($v),
                        'purchase' => $this->purchase($v),
                        'receipt' => $this->receipt($v),
                        'payment' => $this->payment($v),
                        'stock_journal' => $this->stockJournal($v),
                        'delivery_note' => $this->deliveryNote($v),
                        default => throw new \RuntimeException("No importer for {$v['type']} yet."),
                    };
                });
                $this->stats[$kind . '_posted'] = ($this->stats[$kind . '_posted'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $msg = $e instanceof \Illuminate\Validation\ValidationException
                    ? implode('; ', array_map(fn ($m) => implode(',', $m), $e->errors()))
                    : $e->getMessage();
                $this->failures[$kind][] = ['row' => $v['row'], 'no' => $v['number'], 'date' => $v['date'], 'party' => $v['party'], 'error' => substr($msg, 0, 220)];
            }
        }
    }

    private function kind(string $type): string
    {
        return match ($type) {
            'Sales' => 'sales', 'Purchase' => 'purchase', 'Receipt' => 'receipt', 'Payment' => 'payment',
            'Stock Journal' => 'stock_journal', 'Delivery Note' => 'delivery_note', default => 'other',
        };
    }

    // ---------------------------------------------------------------- helpers

    /** Break a voucher's ledger lines into taxable, tax split, discount, round-off and other charges. */
    private function split(array $v): array
    {
        $o = ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'tax' => 0.0, 'discount' => 0.0, 'roundoff' => 0.0, 'other' => 0.0, 'other_lines' => []];
        $sales = $v['type'] === 'Sales';
        foreach ($v['items'] as $i) $o['taxable'] += (float) $i['amount'];
        foreach ($v['ledgers'] as $l) {
            $n = strtolower($l['name']);
            $net = $sales ? $l['credit'] - $l['debit'] : $l['debit'] - $l['credit']; // amount that increases the bill
            if (preg_match('/\b(cgst|sgst|igst)\b/', $n, $m)) { $o[$m[1]] += $net; $o['tax'] += $net; }
            elseif (str_contains($n, 'r/off') || str_contains($n, 'round')) $o['roundoff'] += $net;
            elseif (str_contains($n, 'discount')) $o['discount'] += -$net;
            elseif ($n === 'sale' || $n === 'sales' || $n === 'purchases' || $n === 'purchase') continue;
            else { $o['other'] += $net; $o['other_lines'][$l['name']] = ($o['other_lines'][$l['name']] ?? 0) + $net; }
        }
        foreach ($o as $k => $x) if (!is_array($x)) $o[$k] = round($x, 2);
        return $o;
    }

    private function itemsFor(array $v, array $parts, string $rateKey, bool $foldValueOnly = false): array
    {
        $base = $parts['taxable'] - $parts['discount'];
        $effective = $base > 0 ? round($parts['tax'] * 100 / $base, 4) : 0.0;
        $lines = $v['items'];
        if ($foldValueOnly) {
            // BillIQ purchase vouchers cannot carry service lines: capitalise FREIGHT/CARTAGE into the goods (landed cost).
            $extra = array_sum(array_map(fn ($i) => !empty($i['value_only']) ? $i['amount'] : 0, $lines));
            $goods = array_values(array_filter($lines, fn ($i) => empty($i['value_only'])));
            $goodsTotal = array_sum(array_column($goods, 'amount'));
            if ($extra > 0 && $goodsTotal > 0) {
                $left = $extra;
                foreach ($goods as $k => &$g) {
                    $add = $k === array_key_last($goods) ? $left : round($extra * $g['amount'] / $goodsTotal, 2);
                    $left = round($left - $add, 2);
                    $g['amount'] = round($g['amount'] + $add, 2);
                    $g['rate'] = round($g['amount'] / max($g['qty'], 0.0001), 4);
                }
                unset($g);
                $lines = $goods;
                $this->stats['purchase_value_only_capitalised'] = ($this->stats['purchase_value_only_capitalised'] ?? 0) + 1;
            }
        }
        $items = [];
        foreach ($lines as $it) {
            $p = !empty($it['value_only']) ? $this->serviceProduct($it['name']) : $this->product($it['name'], $it['unit'] ?? null);
            $row = [
                'product_id' => $p->id, 'unit_id' => $p->unit_id, 'quantity' => $it['qty'], $rateKey => $it['rate'],
                'gst_rate' => $this->rates[$p->id] ?? ($p->gst_rate ?: $effective), 'tax_inclusive' => false,
            ];
            // Tally shows the list rate; the line amount is net of its trade discount.
            $gross = round($it['qty'] * $it['rate'], 2);
            if ($it['amount'] !== null && abs($gross - $it['amount']) > 0.01) {
                if ($gross > $it['amount']) { $row['discount_type'] = 'fixed'; $row['discount_value'] = round($gross - $it['amount'], 2); }
                else $row[$rateKey] = round($it['amount'] / max($it['qty'], 0.0001), 6);
            }
            $items[] = $row;
        }
        return [$items, $effective];
    }

    private function serviceProduct(string $name): Product
    {
        $k = $this->key($name);
        if (isset($this->products[$k])) return $this->products[$k];
        $unit = \App\Models\Unit::firstOrCreate(['code' => 'NOS'], ['name' => 'Numbers', 'status' => 'active']);
        $seq = Product::withTrashed()->where('business_id', $this->bid)->count() + 1;
        $p = Product::create([
            'business_id' => $this->bid, 'company_id' => $this->bid, 'name' => $name, 'product_name' => $name,
            'sku' => 'ITM-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT), 'product_type' => 'service', 'item_type' => 'service',
            'unit_id' => $unit->id, 'unit' => 'NOS', 'gst_rate' => 0, 'track_inventory' => false, 'opening_stock' => 0, 'current_stock' => 0,
            'status' => 'active', 'created_by' => Auth::id(),
        ]);
        return $this->products[$k] = $p;
    }

    private function taxClose(array $totals, array $parts): bool
    {
        $calc = $totals['cgst_amount'] + $totals['sgst_amount'] + $totals['igst_amount'];
        return abs($calc - $parts['tax']) <= 0.02 + 0.01 * count($totals['items']);
    }

    // ------------------------------------------------------------------ sales

    private function sale(array $v): void
    {
        if (!$v['items']) throw new \RuntimeException('Sales voucher has no inventory items.');
        $parts = $this->split($v);
        $expected = $v['debit'];
        $acct = $this->accounts[$this->key($v['party'])] ?? null;
        $cashParty = $acct && in_array($acct->account_type, ['cash', 'bank'], true);
        $customer = $cashParty ? null : $this->customer($v['party']);
        if (!$cashParty && !$customer) throw new \RuntimeException("Customer '{$v['party']}' is not imported.");

        foreach ([false, true] as $tryUniform) {
            [$items, $effective] = $this->itemsFor($v, $parts, 'selling_rate');
            if ($tryUniform) foreach ($items as &$it) $it['gst_rate'] = $effective;
            unset($it);
            $data = [
                'branch_id' => $this->branchId, 'warehouse_id' => $this->warehouseId, 'invoice_date' => $v['date'],
                'sale_type' => $cashParty ? 'cash' : 'credit', 'invoice_type' => 'tax_invoice',
                'tax_type' => $parts['igst'] > 0 ? 'interstate' : 'intrastate',
                'place_of_supply_state_id' => $parts['igst'] > 0 ? ($customer->state_id ?? null) : $this->stateId,
                'items' => $items, 'reference_number' => $v['number'], 'status' => 'approved',
                'remarks' => 'Tally Day Book sales voucher ' . $v['number'] . ($v['text'] ? ' | ' . implode(' | ', array_slice($v['text'], 0, 2)) : ''),
                'customer_id' => $customer?->id,
            ];
            if ($parts['discount'] > 0) { $data['voucher_discount_type'] = 'fixed'; $data['voucher_discount_value'] = $parts['discount']; }
            $totals = $this->salesCalc->calculateVoucherTotals($data);
            if ($this->taxClose($totals, $parts) || $tryUniform) break;
        }
        $residual = $this->checkResidual('sales', $expected, $totals, $parts);
        if ($cashParty) {
            $data['payments'] = [['payment_method_id' => $this->cashMethodId, 'amount' => $totals['grand_total'], 'payment_date' => $v['date']]];
        }
        $voucher = $this->sales->create($data);
        // Keep the Tally voucher number as the visible invoice number when it is free.
        if (!SalesVoucher::where('business_id', $this->bid)->where('invoice_number', $v['number'])->where('id', '!=', $voucher->id)->exists()) {
            $voucher->update(['invoice_number' => $v['number']]);
        }
        $this->postExtras('sales', $v, $parts, $residual, $customer?->id, $cashParty);
        if ($customer) {
            $pk = $this->key($v['party']);
            $this->billRefs[$pk][$v['number']] = $voucher->id;
            foreach ($v['refs'] as $r) if ($r['kind'] === 'New Ref') $this->billRefs[$pk][$r['name']] = $voucher->id;
        }
    }

    // --------------------------------------------------------------- purchase

    private function purchase(array $v): void
    {
        if (!$v['items']) throw new \RuntimeException('Purchase voucher has no inventory items.');
        $parts = $this->split($v);
        $expected = $v['credit'];
        $supplier = $this->supplier($v['party']);
        if (!$supplier) throw new \RuntimeException("Supplier '{$v['party']}' is not imported.");

        foreach ([false, true] as $tryUniform) {
            [$items, $effective] = $this->itemsFor($v, $parts, 'purchase_rate', true);
            if ($tryUniform) foreach ($items as &$it) $it['gst_rate'] = $effective;
            unset($it);
            $data = [
                'branch_id' => $this->branchId, 'warehouse_id' => $this->warehouseId, 'supplier_id' => $supplier->id,
                'purchase_date' => $v['date'], 'purchase_type' => 'credit', 'tax_type' => $parts['igst'] > 0 ? 'interstate' : 'intrastate',
                'items' => $items, 'status' => 'approved', 'paid_amount' => 0,
                'supplier_invoice_number' => ($v['refs'][0]['name'] ?? '') ?: $v['number'],
                'remarks' => 'Tally Day Book purchase voucher ' . $v['number'] . ($v['text'] ? ' | ' . implode(' | ', array_slice($v['text'], 0, 2)) : ''),
            ];
            if ($parts['discount'] > 0) { $data['discount_type'] = 'fixed'; $data['discount_value'] = $parts['discount']; }
            $totals = $this->purchaseCalc->calculateVoucherTotals($data);
            if ($this->taxClose($totals, $parts) || $tryUniform) break;
        }
        $residual = $this->checkResidual('purchase', $expected, $totals, $parts);
        $this->purchases->create($data);
        $this->postExtras('purchase', $v, $parts, $residual, $supplier->id, false);
    }

    // ------------------------------------------------- Tally-vs-BillIQ residual

    /**
     * BillIQ rounds the invoice to the rupee and cannot carry arbitrary extra ledgers. The remaining difference to
     * Tally is accepted only when Tally itself explains it (explicit freight ledgers + its own R/Off line).
     */
    private function checkResidual(string $kind, float $expected, array $totals, array $parts): float
    {
        $residual = round($expected - $totals['grand_total'], 2);
        $explained = round($parts['other'] + $parts['roundoff'], 2);
        if (abs($residual - $explained) > 1.0) {
            throw new \RuntimeException("Total mismatch: Tally {$expected} vs BillIQ {$totals['grand_total']} (Tally tax {$parts['tax']}, other ledgers {$parts['other']}, R/Off {$parts['roundoff']})");
        }
        if (abs($residual) >= 0.01) $this->stats[$kind . '_adjustment_journals'] = ($this->stats[$kind . '_adjustment_journals'] ?? 0) + 1;
        return $residual;
    }

    /** Balanced journal that brings the party/cash balance to Tally's figure (freight ledgers + R/Off). */
    private function postExtras(string $kind, array $v, array $parts, float $residual, ?int $partyId, bool $cash): void
    {
        if (abs($residual) < 0.01) return;
        $s = BusinessAccountSetting::where('business_id', $this->bid)->firstOrFail();
        $sales = $kind === 'sales';
        $partyAcct = $sales ? ($cash ? $s->cash_account_id : $s->accounts_receivable_id) : $s->accounts_payable_id;
        $entries = [];
        $partyDebit = $sales ? $residual > 0 : $residual < 0;
        $partyEntry = ['account_id' => $partyAcct, 'debit_amount' => $partyDebit ? abs($residual) : 0, 'credit_amount' => $partyDebit ? 0 : abs($residual)];
        if ($partyId && !$cash) $partyEntry[$sales ? 'customer_id' : 'supplier_id'] = $partyId;
        $entries[] = $partyEntry;
        $used = 0.0;
        foreach ($parts['other_lines'] as $name => $net) {
            if (abs($net) < 0.01) continue;
            $account = $this->accounts[$this->key($name)] ?? null;
            if (!$account) throw new \RuntimeException("Ledger '$name' is not imported.");
            $isCredit = $sales ? $net > 0 : $net < 0;
            $entries[] = ['account_id' => $account->id, 'debit_amount' => $isCredit ? 0 : abs($net), 'credit_amount' => $isCredit ? abs($net) : 0];
            $used += $net;
        }
        $rpart = round($residual - $used, 2);
        if (abs($rpart) >= 0.01) {
            $isCredit = $sales ? $rpart > 0 : $rpart < 0;
            $entries[] = ['account_id' => $s->round_off_account_id, 'debit_amount' => $isCredit ? 0 : abs($rpart), 'credit_amount' => $isCredit ? abs($rpart) : 0];
        }
        $this->posting->createJournalVoucher([
            'business_id' => $this->bid, 'branch_id' => $this->branchId, 'voucher_type' => 'journal', 'voucher_date' => $v['date'],
            'reference_number' => 'TALLY-' . $v['number'], 'narration' => 'Tally freight/round-off adjustment for ' . $v['type'] . ' ' . $v['number'],
            'status' => 'approved', 'is_system_generated' => true, 'entries' => $entries,
        ]);
    }

    // ---------------------------------------------------------- receipt / payment

    private function receipt(array $v): void
    {
        $customer = $this->customer($v['party']);
        $amount = $v['credit'];
        $bank = null;
        foreach ($v['ledgers'] as $l) {
            $a = $this->accounts[$this->key($l['name'])] ?? null;
            if ($a && in_array($a->account_type, ['cash', 'bank'], true) && $l['debit'] > 0) $bank = $a;
        }
        if (!$customer || !$bank || $amount <= 0) {
            $this->journalFromLedgers($v, 'Receipt');
            $this->stats['receipt_posted_as_journal'] = ($this->stats['receipt_posted_as_journal'] ?? 0) + 1;
            return;
        }
        $pk = $this->key($v['party']);
        $alloc = [];
        $refTotal = 0.0;
        foreach ($v['refs'] as $r) {
            if ($r['kind'] !== 'Agst Ref') continue;
            $id = $this->billRefs[$pk][$r['name']] ?? null;
            if ($id) { $alloc[$id] = ($alloc[$id] ?? 0) + $r['amount']; $refTotal += $r['amount']; }
        }
        if ($alloc && abs($refTotal - $amount) < 0.01) {
            try {
                DB::transaction(function () use ($v, $customer, $bank, $amount, $alloc) {
                    $this->accounting->createReceipt([
                        'customer_id' => $customer->id, 'cash_bank_account_id' => $bank->id, 'amount' => $amount, 'receipt_date' => $v['date'],
                        'receipt_mode' => $bank->account_type === 'cash' ? 'cash' : 'bank', 'reference_number' => 'TALLY-RCPT-' . $v['number'],
                        'remarks' => 'Tally receipt ' . $v['number'], 'status' => 'approved', 'discount_allowed' => 0, 'write_off_amount' => 0,
                        'allocations' => collect($alloc)->map(fn ($amt, $id) => ['reference_type' => SalesVoucher::class, 'reference_id' => $id, 'allocated_amount' => round($amt, 2)])->values()->all(),
                    ]);
                });
                $this->stats['receipt_allocated'] = ($this->stats['receipt_allocated'] ?? 0) + 1;
                return;
            } catch (\Throwable $e) {
                $this->notes[] = "Receipt {$v['number']} row {$v['row']}: bill allocation failed (" . substr($e->getMessage(), 0, 80) . "); posted unallocated.";
            }
        }
        $this->journalFromLedgers($v, 'Receipt');
        $this->stats['receipt_posted_unallocated'] = ($this->stats['receipt_posted_unallocated'] ?? 0) + 1;
    }

    private function payment(array $v): void
    {
        if ($v['items'] || $v['debit'] < 0 || $v['credit'] < 0) {
            throw new \RuntimeException('Payment voucher carries inventory lines / negative amounts; needs manual review.');
        }
        $this->journalFromLedgers($v, 'Payment');
    }

    /** Generic balanced journal from a Tally voucher (header party + ledger lines). Parties map to AR/AP control accounts. */
    private function journalFromLedgers(array $v, string $label): void
    {
        $s = BusinessAccountSetting::where('business_id', $this->bid)->firstOrFail();
        $entries = [];
        $add = function (string $name, float $debit, float $credit) use (&$entries, $s) {
            if ($debit == 0.0 && $credit == 0.0) return;
            $k = $this->key($name);
            $line = ['debit_amount' => $debit, 'credit_amount' => $credit];
            if (isset($this->accounts[$k]) && !isset($this->customers[$k]) && !isset($this->suppliers[$k])) $line['account_id'] = $this->accounts[$k]->id;
            elseif (isset($this->customers[$k])) { $line['account_id'] = $s->accounts_receivable_id; $line['customer_id'] = $this->customers[$k]->id; }
            elseif (isset($this->suppliers[$k])) { $line['account_id'] = $s->accounts_payable_id; $line['supplier_id'] = $this->suppliers[$k]->id; }
            elseif (isset($this->accounts[$k])) $line['account_id'] = $this->accounts[$k]->id;
            else throw new \RuntimeException("Ledger '$name' is not imported.");
            $entries[] = $line;
        };
        $add($v['party'], $v['debit'], $v['credit']);
        foreach ($v['ledgers'] as $l) $add($l['name'], $l['debit'], $l['credit']);
        $this->posting->createJournalVoucher([
            'business_id' => $this->bid, 'branch_id' => $this->branchId, 'voucher_type' => strtolower($label) === 'receipt' ? 'journal' : 'journal',
            'voucher_date' => $v['date'], 'reference_number' => 'TALLY-' . strtoupper(substr($label, 0, 3)) . '-' . $v['number'],
            'narration' => 'Tally ' . $label . ' ' . $v['number'] . ($v['text'] ? ' | ' . $v['text'][0] : ''),
            'status' => 'approved', 'is_system_generated' => true, 'entries' => $entries,
        ]);
    }

    // ----------------------------------------------------- stock journal / DN

    private function scope(int $productId): array
    {
        return ['business_id' => $this->bid, 'branch_id' => $this->branchId, 'warehouse_id' => $this->warehouseId, 'product_id' => $productId];
    }

    private function stockJournal(array $v): void
    {
        $moves = [];
        $headerQty = $v['debit'] > 0 ? $v['debit'] : $v['credit'];
        if ($headerQty > 0) $moves[] = ['name' => $v['party'], 'qty' => $headerQty, 'dir' => $v['debit'] > 0 ? 'in' : 'out', 'unit' => null];
        foreach ($v['items'] as $i) $moves[] = ['name' => $i['name'], 'qty' => $i['qty'], 'dir' => $i['dir'] ?? 'out', 'unit' => $i['unit']];
        if (!$moves) throw new \RuntimeException('Stock journal has no movements.');

        $voucher = StockAdjustmentVoucher::create([
            'business_id' => $this->bid, 'branch_id' => $this->branchId, 'warehouse_id' => $this->warehouseId,
            'voucher_number' => 'TSJ-' . str_pad((string) ++$this->seq, 5, '0', STR_PAD_LEFT), 'adjustment_date' => $v['date'],
            'adjustment_type' => 'stock_journal', 'source' => 'tally_import', 'status' => 'posted',
            'remarks' => 'Tally Stock Journal ' . $v['number'] . ($v['text'] ? ' | ' . implode(' | ', array_slice($v['text'], 0, 2)) : ''),
            'created_by' => Auth::id(), 'approved_by' => Auth::id(), 'approved_at' => now(),
        ]);
        $costOut = 0.0; $qtyIn = 0.0; $totals = ['in' => 0.0, 'out' => 0.0, 'vin' => 0.0, 'vout' => 0.0];
        $rows = [];
        foreach (array_filter($moves, fn ($m) => $m['dir'] === 'out') as $m) {          // consume first, to learn the cost
            $p = $this->product($m['name'], $m['unit']);
            $cost = $this->stock->getAverageCost($this->scope($p->id));
            $this->stock->decreaseStock($this->scope($p->id) + ['transaction_type' => 'stock_adjustment_out', 'reference_type' => StockAdjustmentVoucher::class, 'reference_id' => $voucher->id,
                'quantity' => $m['qty'], 'unit_cost' => $cost, 'transaction_date' => $v['date'], 'remarks' => 'Tally Stock Journal ' . $v['number']]);
            $costOut += $cost * $m['qty'];
            $rows[] = [$p, $m, $cost]; $totals['out'] += $m['qty']; $totals['vout'] += $cost * $m['qty'];
        }
        $qtyIn = array_sum(array_map(fn ($m) => $m['dir'] === 'in' ? $m['qty'] : 0, $moves));
        foreach (array_filter($moves, fn ($m) => $m['dir'] === 'in') as $m) {
            $p = $this->product($m['name'], $m['unit']);
            $cost = $costOut > 0 && $qtyIn > 0 ? round($costOut / $qtyIn, 4) : $this->stock->getAverageCost($this->scope($p->id));
            $this->stock->increaseStock($this->scope($p->id) + ['transaction_type' => 'stock_adjustment_in', 'reference_type' => StockAdjustmentVoucher::class, 'reference_id' => $voucher->id,
                'quantity' => $m['qty'], 'unit_cost' => $cost, 'transaction_date' => $v['date'], 'remarks' => 'Tally Stock Journal ' . $v['number']]);
            $rows[] = [$p, $m, $cost]; $totals['in'] += $m['qty']; $totals['vin'] += $cost * $m['qty'];
        }
        foreach ($rows as [$p, $m, $cost]) {
            $voucher->items()->create(['product_id' => $p->id, 'unit_id' => $p->unit_id, 'adjustment_quantity' => $m['qty'], 'direction' => $m['dir'],
                'unit_cost' => round($cost, 2), 'adjustment_value' => round($cost * $m['qty'], 2), 'condition_status' => 'saleable']);
        }
        $voucher->update(['total_quantity_in' => $totals['in'], 'total_quantity_out' => $totals['out'], 'total_value_in' => round($totals['vin'], 2), 'total_value_out' => round($totals['vout'], 2)]);
    }

    private function deliveryNote(array $v): void
    {
        $moves = [];
        $headerQty = $v['credit'] > 0 ? $v['credit'] : $v['debit'];
        if ($headerQty > 0) $moves[] = ['name' => $v['party'], 'qty' => $headerQty, 'unit' => null];
        foreach ($v['items'] as $i) $moves[] = ['name' => $i['name'], 'qty' => $i['qty'], 'unit' => $i['unit']];
        if (!$moves) throw new \RuntimeException('Delivery note has no items.');
        $customerName = $v['text'][0] ?? null;
        $customer = $customerName ? $this->customer($customerName) : null;
        if (!$customer) {
            $customer = app(CustomerService::class)->defaultWalkIn();
            $this->stats['delivery_note_walk_in'] = ($this->stats['delivery_note_walk_in'] ?? 0) + 1;
        }
        $challan = DeliveryChallan::create([
            'business_id' => $this->bid, 'branch_id' => $this->branchId, 'warehouse_id' => $this->warehouseId,
            'challan_number' => 'TDN-' . str_pad((string) ++$this->seq, 5, '0', STR_PAD_LEFT), 'challan_date' => $v['date'], 'customer_id' => $customer->id,
            'status' => 'dispatched', 'remarks' => 'Tally Delivery Note ' . $v['number'] . ($v['text'] ? ' | ' . implode(' | ', array_slice($v['text'], 0, 3)) : ''),
            'created_by' => Auth::id(), 'dispatched_by' => Auth::id(), 'dispatched_at' => $v['date'] . ' 00:00:00',
        ]);
        foreach ($moves as $m) {
            $p = $this->product($m['name'], $m['unit']);
            $cost = $this->stock->getAverageCost($this->scope($p->id));
            $this->stock->decreaseStock($this->scope($p->id) + ['transaction_type' => 'delivery_challan', 'reference_type' => DeliveryChallan::class, 'reference_id' => $challan->id,
                'quantity' => $m['qty'], 'unit_cost' => $cost, 'transaction_date' => $v['date'], 'remarks' => 'Tally Delivery Note ' . $v['number']]);
            $challan->items()->create(['product_id' => $p->id, 'dispatch_quantity' => $m['qty'], 'unit_cost' => round($cost, 2)]);
        }
    }
}
