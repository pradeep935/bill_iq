<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Product;
use App\Models\SalesVoucher;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a received sales bill into a filled-in purchase form. Nothing is posted here: stock and accounts change only
 * when the buyer checks the form and saves it. Seller-only data (cost, notes) is never read into the result.
 */
class NetworkImportService
{
    public function __construct(
        private NetworkService $network,
        private SupplierService $suppliers,
        private ProductMasterService $products,
        private PurchaseCalculationService $calculator,
    ) {
    }

    public function preview(array $source): array
    {
        $voucher = $this->load($source);
        $me = AppController::businessId();
        $company = DB::table('companies')->where('id', $voucher->business_id)->first();
        $supplier = $this->matchSupplier($me, $company);
        $this->network->assertNotEntered($voucher, $supplier?->id);

        return [
            'supplier' => [
                'name' => $company->name, 'gstin' => $company->gstin, 'mobile' => $company->phone, 'state' => $company->state,
                'matched' => $supplier ? ['id' => $supplier->id, 'name' => $supplier->supplier_name ?: $supplier->name] : null,
            ],
            'invoice' => [
                'number' => $voucher->invoice_number, 'date' => optional($voucher->invoice_date)->format('Y-m-d'),
                'grand_total' => (float) $voucher->grand_total,
            ],
            'items' => $voucher->items->map(function ($item) use ($me, $voucher) {
                $match = $this->matchProduct($me, $voucher, $item);

                return [
                    'source_item_id' => $item->id,
                    'name' => $item->product_name_snapshot,
                    'hsn' => $item->hsn_code_snapshot,
                    'quantity' => (float) $item->quantity,
                    'free_quantity' => (float) $item->free_quantity,
                    'unit' => optional($item->product)->getRawOriginal('unit') ?: 'PCS',
                    'rate' => (float) $item->selling_rate,
                    'gst_rate' => (float) $item->gst_rate,
                    'amount' => (float) $item->line_total,
                    'is_service' => $this->isService($item),
                    'match' => $match ? ['id' => $match->id, 'name' => $match->name] : null,
                ];
            })->values(),
        ];
    }

    /**
     * $choices: supplier_id (existing) or null to create one; items: one entry per bill item with
     * action = link (product_id) | create | charge (add the amount to other charges, for freight and services).
     */
    public function prepare(array $source, array $choices): array
    {
        return DB::transaction(function () use ($source, $choices) {
            $voucher = $this->load($source);
            $me = AppController::businessId();
            $company = DB::table('companies')->where('id', $voucher->business_id)->first();

            $supplier = !empty($choices['supplier_id'])
                ? Supplier::query()->where('business_id', $me)->where('status', 'active')->find($choices['supplier_id'])
                : $this->matchSupplier($me, $company);
            if (!empty($choices['supplier_id']) && !$supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Select one of your own active suppliers.']);
            }
            $this->network->assertNotEntered($voucher, $supplier?->id);

            $chosen = collect($choices['items'] ?? [])->keyBy('source_item_id');
            $voucherRatio = $this->voucherDiscountRatio($voucher);
            $lines = [];
            // Money that is not stock: [amount without tax, GST %, amount with tax]
            $extra = array_values(array_filter([(float) $voucher->shipping_amount + (float) $voucher->other_charges]));
            $extra = array_map(fn ($amount) => [$amount, 0.0, $amount], $extra);

            foreach ($voucher->items->values() as $index => $item) {
                $choice = $chosen->get($item->id);
                if (!$choice) {
                    throw ValidationException::withMessages(['items' => 'Choose what to do with item ' . ($index + 1) . ': ' . $item->product_name_snapshot]);
                }
                if ($choice['action'] === 'charge') {
                    $extra[] = [round((float) $item->taxable_amount * $voucherRatio, 2), (float) $item->gst_rate, round((float) $item->line_total * $voucherRatio, 2)];
                    continue;
                }

                $price = $this->price($item, $voucherRatio);
                $product = $choice['action'] === 'create'
                    ? $this->createProduct($me, $item, $price)
                    : $this->ownProduct($me, (int) ($choice['product_id'] ?? 0), $index, $item->product_name_snapshot);
                $this->remember($me, $voucher->business_id, $item->product_id, $product->id);
                $lines[] = $this->line($product, $item, $price);
            }

            if (!$lines) {
                throw ValidationException::withMessages(['items' => 'Add at least one item as a product. A purchase cannot be made of charges only.']);
            }

            if (!$supplier) {
                $supplier = $this->createSupplier($company);
            }

            $taxType = in_array($voucher->tax_type, ['intrastate', 'interstate'], true) && $voucher->invoice_type !== 'bill_of_supply' ? $voucher->tax_type : 'exempt';
            // A purchase has one GST rate for other charges. With one rate the tax stays separate (input credit);
            // with different rates the full amounts are added without a tax split.
            $oneRate = count(array_unique(array_column($extra, 1))) === 1;
            $chargeRate = $oneRate && $taxType !== 'exempt' ? $extra[0][1] : 0;
            $charges = round(array_sum(array_column($extra, $chargeRate > 0 ? 0 : 2)), 2);
            $totals = $this->calculator->calculateVoucherTotals(['tax_type' => $taxType, 'other_charges' => $charges, 'other_charges_gst_rate' => $chargeRate, 'items' => $lines]);

            return [
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => $voucher->invoice_number,
                'supplier_invoice_date' => optional($voucher->invoice_date)->format('Y-m-d'),
                'purchase_date' => optional($voucher->invoice_date)->format('Y-m-d'),
                'tax_type' => $taxType,
                'other_charges' => $charges,
                'other_charges_gst_rate' => $chargeRate,
                'items' => $lines,
                'network_source' => $source,
                'supplier_total' => (float) $voucher->grand_total,
                'total' => (float) $totals['grand_total'],
            ];
        });
    }

    private function load(array $source): SalesVoucher
    {
        return $this->network->resolveSource($source)->load(['items.product', 'items.batch']);
    }

    private function matchSupplier(int $me, object $company): ?Supplier
    {
        $suppliers = Supplier::query()->where('business_id', $me)->where('status', 'active')->orderBy('id')->get();
        $gstin = strtoupper((string) $company->gstin);
        $phone = $this->network->phone($company->phone);

        return ($gstin !== '' ? $suppliers->first(fn ($s) => strtoupper((string) $s->gstin) === $gstin) : null)
            // A phone number or name match is not trusted when that supplier has a different GSTIN.
            ?: $suppliers->first(fn ($s) => ($gstin === '' || !$s->gstin)
                && (($phone && $this->network->phone($s->mobile ?: $s->phone) === $phone)
                    || mb_strtolower(trim((string) ($s->supplier_name ?: $s->name))) === mb_strtolower(trim($company->name))));
    }

    private function createSupplier(object $company): Supplier
    {
        $stateId = $company->state ? DB::table('states')->where('name', $company->state)->value('id') : null;

        return $this->suppliers->create([
            'supplier_code' => '', 'supplier_name' => $company->name, 'mobile' => $company->phone ?: null,
            'email' => $company->email ?: null, 'gstin' => $company->gstin ? strtoupper($company->gstin) : null,
            'billing_address' => $company->address ?: null, 'state_id' => $stateId,
            'opening_balance' => 0, 'opening_balance_type' => 'credit', 'status' => 'active',
        ]);
    }

    private function stockProducts(int $me): Builder
    {
        return Product::query()
            ->where(fn (Builder $q) => $q->where('business_id', $me)->orWhere('company_id', $me))
            ->where('status', 'active')->where('product_type', '!=', 'service')->where('item_type', '!=', 'non_stock');
    }

    private function matchProduct(int $me, SalesVoucher $voucher, $item): ?Product
    {
        $mapped = DB::table('network_product_maps')->where('business_id', $me)->where('from_business_id', $voucher->business_id)
            ->where('from_product_id', $item->product_id)->value('product_id');
        if ($mapped && ($product = $this->stockProducts($me)->find($mapped))) {
            return $product;
        }

        // Only exact matches are suggested; a close name is left for the buyer to pick.
        $barcode = $item->barcode_snapshot ?: optional($item->product)->primary_barcode;
        if ($barcode) {
            $found = $this->stockProducts($me)->where(fn (Builder $q) => $q->where('primary_barcode', $barcode)->orWhere('barcode', $barcode))->limit(2)->get();
            if ($found->count() === 1) {
                return $found->first();
            }
        }
        $found = $this->stockProducts($me)->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim((string) $item->product_name_snapshot))])->limit(2)->get();

        return $found->count() === 1 ? $found->first() : null;
    }

    private function ownProduct(int $me, int $productId, int $index, string $name): Product
    {
        $product = $this->stockProducts($me)->find($productId);
        if (!$product) {
            throw ValidationException::withMessages(['items' => 'Select your product for item ' . ($index + 1) . ': ' . $name]);
        }

        return $product;
    }

    private function createProduct(int $me, $item, array $price): Product
    {
        $name = trim((string) $item->product_name_snapshot);
        // The same new name twice in one bill, or pressing Continue twice, must not create two products.
        $existing = $this->stockProducts($me)->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return $existing;
        }

        $unit = strtoupper((string) (optional($item->product)->getRawOriginal('unit') ?: 'PCS'));

        return $this->products->create([
            'name' => $name, 'product_type' => 'goods', 'item_type' => 'stock', 'tracking_type' => 'none',
            'sku' => $this->nextSku($me), 'unit' => $unit,
            'unit_id' => DB::table('units')->whereRaw('UPPER(code) = ?', [$unit])->value('id'),
            'hsn_code' => (string) $item->hsn_code_snapshot,
            'taxability' => (float) $item->gst_rate > 0 ? 'taxable' : 'nil_rated',
            'gst_rate' => (float) $item->gst_rate, 'cess_rate' => (float) $item->cess_rate,
            'tax_source' => 'manual_confirmation', 'reverse_charge' => 'no',
            'cost_price' => $price['net_rate'], 'selling_price' => 0, 'mrp' => $item->mrp, 'status' => 'active',
        ]);
    }

    private function nextSku(int $me): string
    {
        $number = DB::table('products')->where('sku', 'like', 'ITM-%')->where(fn ($q) => $q->where('business_id', $me)->orWhere('company_id', $me))
            ->pluck('sku')->map(fn ($sku) => (int) substr($sku, 4))->max() ?: 0;
        do {
            $sku = 'ITM-' . str_pad((string) ++$number, 5, '0', STR_PAD_LEFT);
        } while (DB::table('products')->where('sku', $sku)->exists());

        return $sku;
    }

    private function remember(int $me, int $from, int $fromProduct, int $product): void
    {
        $key = ['business_id' => $me, 'from_business_id' => $from, 'from_product_id' => $fromProduct];
        if (DB::table('network_product_maps')->where($key)->exists()) {
            DB::table('network_product_maps')->where($key)->update(['product_id' => $product, 'updated_at' => now()]);
        } else {
            DB::table('network_product_maps')->insert($key + ['product_id' => $product, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** A discount on the whole sales bill also lowers its tax, so it is spread over the items. */
    private function voucherDiscountRatio(SalesVoucher $voucher): float
    {
        $items = (float) $voucher->items->sum('taxable_amount');
        $discount = (float) $voucher->voucher_discount_amount;

        return $items > 0 && $discount > 0 ? max(0, $items - $discount) / $items : 1.0;
    }

    /** Sales items do not store whether the rate included tax, so it is worked out from the saved amounts. */
    private function price($item, float $voucherRatio): array
    {
        $quantity = (float) $item->quantity;
        $rate = (float) $item->selling_rate;
        $gross = round($quantity * $rate, 2);
        $after = round($gross - (float) $item->discount_amount, 2);
        $taxable = (float) $item->taxable_amount;
        $inclusive = null;

        if (abs($after - $taxable) <= 0.02) {
            $inclusive = false;
        } elseif (abs($after - (float) $item->line_total) <= 0.02) {
            $inclusive = true;
        }

        if ($inclusive === null) {
            $inclusive = false;
            $rate = $quantity > 0 ? round($taxable / $quantity, 2) : 0;
            $gross = round($quantity * $rate, 2);
            $after = $gross;
        }

        $discount = round($gross - $after * $voucherRatio, 2);
        $tax = 100 + (float) $item->gst_rate + (float) $item->cess_rate;

        return [
            'rate' => $rate,
            'tax_inclusive' => $inclusive,
            'discount' => max(0, $discount),
            'net_rate' => $inclusive ? round($rate * 100 / $tax, 2) : $rate,
        ];
    }

    private function line(Product $product, $item, array $price): array
    {
        $batchWanted = $product->batch_required || in_array($product->tracking_type, ['batch', 'batch_expiry', 'batch_serial'], true);
        $batch = $item->batch;

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit' => $product->getRawOriginal('unit'),
            'unit_id' => $product->unit_id,
            'tracking_type' => $product->tracking_type ?: 'none',
            'batch_required' => (bool) $product->batch_required,
            'expiry_required' => (bool) $product->expiry_required,
            'product_variant_id' => '',
            'batch_id' => '',
            'variants' => [],
            'quantity' => (float) $item->quantity,
            'free_quantity' => (float) $item->free_quantity,
            'purchase_rate' => $price['rate'],
            'tax_inclusive' => $price['tax_inclusive'],
            'discount_type' => $price['discount'] > 0 ? 'amount' : '',
            'discount_value' => $price['discount'],
            'gst_rate' => (float) $item->gst_rate,
            'cess_rate' => (float) $item->cess_rate,
            'selling_price' => (float) ($product->selling_price ?: $product->sale_price),
            'mrp' => (float) $product->mrp > 0 ? (float) $product->mrp : '',
            'batch_number' => $batchWanted ? (string) ($batch?->batch_no ?: $batch?->batch_number) : '',
            'manufacturing_date' => $batchWanted ? (string) optional($batch?->manufacturing_date)->format('Y-m-d') : '',
            'expiry_date' => $batchWanted ? (string) optional($batch?->expiry_date)->format('Y-m-d') : '',
            'warehouse_location' => '',
            'remarks' => '',
        ];
    }

    private function isService($item): bool
    {
        $product = $item->product;

        return $product && ($product->product_type === 'service' || $product->item_type === 'non_stock');
    }
}
