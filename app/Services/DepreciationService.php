<?php

namespace App\Services;

use App\Models\AssetDepreciationSchedule;
use App\Models\FixedAsset;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class DepreciationService
{
    public function calculateStraightLine(FixedAsset $asset, string $periodStart, string $periodEnd): float
    {
        if (!$asset->useful_life_months) return 0.0;
        $monthly = (float) $asset->depreciable_amount / max(1, (int) $asset->useful_life_months);
        return round($monthly * $this->periodMonths($periodStart, $periodEnd), 2);
    }

    public function calculateWrittenDownValue(FixedAsset $asset, string $periodStart, string $periodEnd): float
    {
        $rate = (float) ($asset->depreciation_rate ?: 0);
        if ($rate <= 0) return 0.0;
        $annual = max(0, (float) $asset->net_book_value - (float) $asset->residual_value) * $rate / 100;
        return round($annual / 12 * $this->periodMonths($periodStart, $periodEnd), 2);
    }

    public function calculateUnitsOfProduction(FixedAsset $asset, float $units = 0): float
    {
        if ($units <= 0 || !$asset->useful_life_months) return 0.0;
        return round(((float) $asset->depreciable_amount / max(1, (int) $asset->useful_life_months)) * $units, 2);
    }

    public function calculatePeriodDepreciation(FixedAsset $asset, string $periodStart, string $periodEnd): float
    {
        $this->validateDepreciationEligibility($asset, $periodStart, $periodEnd);
        switch ($asset->depreciation_method) {
            case 'written_down_value':
                $amount = $this->calculateWrittenDownValue($asset, $periodStart, $periodEnd);
                break;
            case 'units_of_production':
                $amount = $this->calculateUnitsOfProduction($asset, 0);
                break;
            case 'no_depreciation':
                $amount = 0.0;
                break;
            default:
                $amount = $this->calculateStraightLine($asset, $periodStart, $periodEnd);
        }
        $max = max(0, (float) $asset->net_book_value - (float) $asset->residual_value);
        return round(min($amount, $max), 2);
    }

    public function calculateAssetSchedule(FixedAsset $asset, string $financialYear, string $periodStart, string $periodEnd, ?int $runId = null): AssetDepreciationSchedule
    {
        $amount = $this->calculatePeriodDepreciation($asset, $periodStart, $periodEnd);
        return AssetDepreciationSchedule::query()->updateOrCreate(
            ['fixed_asset_id' => $asset->id, 'asset_component_id' => null, 'period_start' => $periodStart, 'period_end' => $periodEnd],
            [
                'business_id' => $asset->business_id,
                'financial_year' => $financialYear,
                'opening_gross_value' => $asset->capitalized_cost,
                'opening_accumulated_depreciation' => $asset->accumulated_depreciation,
                'depreciation_amount' => $amount,
                'closing_accumulated_depreciation' => round((float) $asset->accumulated_depreciation + $amount, 2),
                'closing_net_book_value' => round((float) $asset->net_book_value - $amount, 2),
                'status' => 'calculated',
                'depreciation_run_id' => $runId,
                'calculated_at' => now(),
            ]
        );
    }

    public function getNetBookValue(FixedAsset $asset): float
    {
        return round((float) $asset->capitalized_cost - (float) $asset->accumulated_depreciation - (float) $asset->accumulated_impairment, 2);
    }

    public function validateDepreciationEligibility(FixedAsset $asset, string $periodStart, string $periodEnd): void
    {
        if (!in_array($asset->asset_status, ['active', 'impaired'], true)) throw ValidationException::withMessages(['asset' => 'Only active assets can be depreciated.']);
        if (!$asset->capitalization_date) throw ValidationException::withMessages(['capitalization_date' => 'Asset is not capitalized.']);
        if ($asset->disposal_date && $asset->disposal_date->lte(Carbon::parse($periodEnd))) throw ValidationException::withMessages(['asset' => 'Disposed asset cannot be depreciated.']);
        if (Carbon::parse($periodEnd)->lt($asset->capitalization_date)) throw ValidationException::withMessages(['period_end' => 'Depreciation period is before capitalization.']);
    }

    private function periodMonths(string $periodStart, string $periodEnd): float
    {
        return max(1, Carbon::parse($periodStart)->startOfDay()->diffInMonths(Carbon::parse($periodEnd)->endOfDay()) + 1);
    }
}
