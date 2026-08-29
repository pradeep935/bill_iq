<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_ledgers') || !Schema::hasTable('stock_adjustment_items') || !Schema::hasTable('stock_adjustment_vouchers')) {
            return;
        }

        $conditions = ['damaged', 'expired', 'defective', 'quarantined'];

        DB::table('stock_adjustment_items')
            ->join('stock_adjustment_vouchers', 'stock_adjustment_vouchers.id', '=', 'stock_adjustment_items.stock_adjustment_voucher_id')
            ->leftJoin('stock_adjustment_reasons', 'stock_adjustment_reasons.id', '=', 'stock_adjustment_vouchers.adjustment_reason_id')
            ->where('stock_adjustment_vouchers.status', 'posted')
            ->where('stock_adjustment_items.direction', 'out')
            ->whereIn('stock_adjustment_items.condition_status', $conditions)
            ->where(function ($query) {
                $query->whereNull('stock_adjustment_reasons.id')
                    ->orWhere(function ($reason) {
                        $reason->where('stock_adjustment_reasons.reason_code', 'not like', '%SCRAP%')
                            ->where('stock_adjustment_reasons.reason_code', 'not like', '%DISPOS%')
                            ->where('stock_adjustment_reasons.reason_code', 'not like', '%WRIT%')
                            ->where('stock_adjustment_reasons.reason_name', 'not like', '%Scrap%')
                            ->where('stock_adjustment_reasons.reason_name', 'not like', '%Disposal%')
                            ->where('stock_adjustment_reasons.reason_name', 'not like', '%Write-off%')
                            ->where('stock_adjustment_reasons.reason_name', 'not like', '%Write Off%');
                    });
            })
            ->select([
                'stock_adjustment_items.*',
                'stock_adjustment_vouchers.business_id',
                'stock_adjustment_vouchers.branch_id',
                'stock_adjustment_vouchers.warehouse_id',
                'stock_adjustment_vouchers.adjustment_date',
            ])
            ->orderBy('stock_adjustment_items.id')
            ->chunkById(100, function ($items): void {
                foreach ($items as $item) {
                    $outbound = DB::table('stock_ledgers')
                        ->where('reference_type', App\Models\StockAdjustmentVoucher::class)
                        ->where('reference_id', $item->stock_adjustment_voucher_id)
                        ->where('business_id', $item->business_id)
                        ->where('product_id', $item->product_id)
                        ->where('quantity_out', (float) $item->adjustment_quantity)
                        ->where('quantity_in', 0)
                        ->when($item->product_variant_id, fn ($q) => $q->where('product_variant_id', $item->product_variant_id), fn ($q) => $q->whereNull('product_variant_id'))
                        ->when($item->batch_id, fn ($q) => $q->where('batch_id', $item->batch_id), fn ($q) => $q->whereNull('batch_id'))
                        ->when($item->serial_id, fn ($q) => $q->where('serial_id', $item->serial_id), fn ($q) => $q->whereNull('serial_id'))
                        ->first();

                    if (!$outbound) {
                        continue;
                    }

                    $hasConditionIn = DB::table('stock_ledgers')
                        ->where('reference_type', App\Models\StockAdjustmentVoucher::class)
                        ->where('reference_id', $item->stock_adjustment_voucher_id)
                        ->where('business_id', $item->business_id)
                        ->where('product_id', $item->product_id)
                        ->where('stock_status', $item->condition_status)
                        ->where('quantity_in', (float) $item->adjustment_quantity)
                        ->where('quantity_out', 0)
                        ->when($item->product_variant_id, fn ($q) => $q->where('product_variant_id', $item->product_variant_id), fn ($q) => $q->whereNull('product_variant_id'))
                        ->when($item->batch_id, fn ($q) => $q->where('batch_id', $item->batch_id), fn ($q) => $q->whereNull('batch_id'))
                        ->when($item->serial_id, fn ($q) => $q->where('serial_id', $item->serial_id), fn ($q) => $q->whereNull('serial_id'))
                        ->exists();

                    if ($hasConditionIn) {
                        continue;
                    }

                    $label = str($item->condition_status)->replace('_', ' ')->title();
                    DB::table('stock_ledgers')->where('id', $outbound->id)->update([
                        'transaction_type' => 'stock_reclassification_out',
                        'stock_status' => 'saleable',
                        'remarks' => trim((string) ($outbound->remarks ?: '') . ' Saleable -> ' . $label),
                        'updated_at' => now(),
                    ]);

                    DB::table('stock_ledgers')->insert([
                        'business_id' => $outbound->business_id,
                        'branch_id' => $outbound->branch_id,
                        'warehouse_id' => $outbound->warehouse_id,
                        'product_id' => $outbound->product_id,
                        'product_variant_id' => $outbound->product_variant_id,
                        'batch_id' => $outbound->batch_id,
                        'serial_id' => $outbound->serial_id ?? null,
                        'warehouse_location' => $outbound->warehouse_location ?? null,
                        'stock_status' => $item->condition_status,
                        'transaction_type' => 'stock_reclassification_in',
                        'reference_type' => App\Models\StockAdjustmentVoucher::class,
                        'reference_id' => $item->stock_adjustment_voucher_id,
                        'quantity_in' => (float) $item->adjustment_quantity,
                        'quantity_out' => 0,
                        'unit_cost' => (float) $item->unit_cost,
                        'stock_value' => round((float) $item->adjustment_quantity * (float) $item->unit_cost, 2),
                        'running_quantity' => null,
                        'running_value' => null,
                        'transaction_date' => $outbound->transaction_date ?: $item->adjustment_date,
                        'remarks' => 'Backfilled condition balance: Saleable -> ' . $label,
                        'created_by' => $outbound->created_by ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }, 'stock_adjustment_items.id', 'id');
    }

    public function down(): void
    {
        if (!Schema::hasTable('stock_ledgers')) {
            return;
        }

        DB::table('stock_ledgers')
            ->where('transaction_type', 'stock_reclassification_in')
            ->where('remarks', 'like', 'Backfilled condition balance:%')
            ->delete();
    }
};
