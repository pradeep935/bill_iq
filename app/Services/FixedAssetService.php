<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\AssetAcquisition;
use App\Models\AssetAssignment;
use App\Models\AssetCapitalization;
use App\Models\AssetCategory;
use App\Models\AssetDepreciationSchedule;
use App\Models\AssetDisposalVoucher;
use App\Models\AssetImpairmentVoucher;
use App\Models\AssetInsurancePolicy;
use App\Models\AssetLocation;
use App\Models\AssetMaintenanceRequest;
use App\Models\AssetMaintenanceSchedule;
use App\Models\AssetMeterReading;
use App\Models\AssetRevaluationVoucher;
use App\Models\AssetTransferVoucher;
use App\Models\AssetVerificationSession;
use App\Models\AssetWarranty;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\FixedAssetSetting;
use App\Models\JournalVoucher;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetService
{
    private AccountingPostingService $posting;
    private DepreciationService $depreciation;

    public function __construct(AccountingPostingService $posting, DepreciationService $depreciation)
    {
        $this->posting = $posting;
        $this->depreciation = $depreciation;
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        return [
            'settings' => $this->settings(),
            'accounts' => Account::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('account_name')->get(),
            'branches' => DB::table('branches')->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => DB::table('warehouses')->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'branch_id', 'name', 'code']),
            'suppliers' => Supplier::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->limit(300)->get(['id', 'name', 'phone', 'gstin']),
            'categories' => AssetCategory::query()->where('business_id', $businessId)->where('status', 'active')->with(['assetAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount'])->orderBy('category_name')->get(),
            'locations' => AssetLocation::query()->where('business_id', $businessId)->where('status', 'active')->with(['branch', 'warehouse'])->orderBy('location_name')->get(),
            'assets' => FixedAsset::query()->where('business_id', $businessId)->with(['category', 'location'])->orderBy('asset_name')->limit(500)->get(),
        ];
    }

    public function settings(): FixedAssetSetting
    {
        return FixedAssetSetting::query()->firstOrCreate(['business_id' => AppController::businessId()], ['status' => 'active']);
    }

    public function saveSettings(array $data): FixedAssetSetting
    {
        $allowed = ['default_depreciation_method', 'depreciation_posting_frequency', 'depreciation_start_rule', 'allow_backdated_capitalization', 'allow_manual_depreciation_override', 'require_asset_tag', 'auto_generate_asset_tag', 'require_asset_verification', 'default_asset_clearing_account_id', 'default_asset_disposal_account_id', 'default_profit_on_sale_account_id', 'default_loss_on_sale_account_id', 'default_impairment_loss_account_id', 'default_accumulated_impairment_account_id', 'status'];
        return FixedAssetSetting::query()->updateOrCreate(['business_id' => AppController::businessId()], array_intersect_key($data, array_flip($allowed)));
    }

    public function categories(array $filters = [])
    {
        return AssetCategory::query()->with(['parent', 'assetAccount'])->where('business_id', AppController::businessId())->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q->where(fn (Builder $i) => $i->where('category_name', 'like', "%$s%")->orWhere('category_code', 'like', "%$s%")))->orderBy('category_name')->paginate(50);
    }

    public function saveCategory(array $data, ?int $id = null): AssetCategory
    {
        $businessId = AppController::businessId();
        foreach (['asset_account_id', 'accumulated_depreciation_account_id', 'depreciation_expense_account_id', 'maintenance_expense_account_id', 'impairment_loss_account_id', 'profit_on_sale_account_id', 'loss_on_sale_account_id'] as $field) {
            if (!empty($data[$field])) $this->account((int) $data[$field]);
        }
        $category = $id ? AssetCategory::query()->where('business_id', $businessId)->findOrFail($id) : new AssetCategory(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $category->fill(array_merge($data, ['updated_by' => Auth::id()]))->save();
        return $category->fresh(['assetAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount']);
    }

    public function locations(array $filters = [])
    {
        return AssetLocation::query()->with(['branch', 'warehouse', 'parent'])->where('business_id', AppController::businessId())->latest('id')->paginate(50);
    }

    public function saveLocation(array $data, ?int $id = null): AssetLocation
    {
        $businessId = AppController::businessId();
        $this->branch((int) $data['branch_id']);
        $location = $id ? AssetLocation::query()->where('business_id', $businessId)->findOrFail($id) : new AssetLocation(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $location->fill($data)->save();
        return $location->fresh(['branch', 'warehouse']);
    }

    public function assets(array $filters = [])
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        return FixedAsset::query()->with(['category', 'branch', 'location', 'supplier'])->where('business_id', AppController::businessId())
            ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q->where(fn (Builder $i) => $i->where('asset_number', 'like', "%$s%")->orWhere('asset_tag', 'like', "%$s%")->orWhere('asset_name', 'like', "%$s%")->orWhere('serial_number', 'like', "%$s%")))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->when($filters['asset_category_id'] ?? null, fn (Builder $q, int $id) => $q->where('asset_category_id', $id))
            ->when($filters['current_location_id'] ?? null, fn (Builder $q, int $id) => $q->where('current_location_id', $id))
            ->when($filters['asset_status'] ?? null, fn (Builder $q, string $s) => $q->where('asset_status', $s))
            ->latest('id')->paginate($perPage);
    }

    public function saveAsset(array $data, ?int $id = null): FixedAsset
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $category = $this->category((int) $data['asset_category_id']);
            $capitalized = round((float) $data['purchase_cost'] + (float) ($data['additional_cost'] ?? 0), 2);
            $residual = (float) ($data['residual_value'] ?? round($capitalized * (float) $category->default_residual_value_percent / 100, 2));
            if ($residual > $capitalized) throw ValidationException::withMessages(['residual_value' => 'Residual value cannot exceed capitalized cost.']);
            $asset = $id ? FixedAsset::query()->where('business_id', $businessId)->findOrFail($id) : new FixedAsset(['business_id' => $businessId, 'asset_number' => $this->nextNumber('AST', FixedAsset::class, 'asset_number'), 'created_by' => Auth::id()]);
            if ($asset->exists && !in_array($asset->asset_status, ['draft', 'pending_approval', 'inactive', 'active', 'under_maintenance'], true)) throw ValidationException::withMessages(['asset_status' => 'This asset status cannot be directly edited.']);
            $tag = $data['asset_tag'] ?? null;
            if (!$tag && $this->settings()->auto_generate_asset_tag) $tag = $this->nextNumber('TAG', FixedAsset::class, 'asset_tag');
            if ($this->settings()->require_asset_tag && !$tag) throw ValidationException::withMessages(['asset_tag' => 'Asset tag is required.']);
            $this->uniqueAssetTag($tag, $asset->id);
            if (!empty($data['serial_number'])) $this->uniqueSerial($data['serial_number'], $asset->id);
            $asset->fill(array_merge($data, [
                'asset_tag' => $tag,
                'capitalized_cost' => $capitalized,
                'residual_value' => $residual,
                'depreciable_amount' => max(0, $capitalized - $residual),
                'depreciation_method' => $data['depreciation_method'] ?? $category->default_depreciation_method,
                'useful_life_months' => $data['useful_life_months'] ?? $category->default_useful_life_months,
                'depreciation_rate' => $data['depreciation_rate'] ?? $category->default_depreciation_rate,
                'net_book_value' => round($capitalized - (float) ($asset->accumulated_depreciation ?? 0) - (float) ($asset->accumulated_impairment ?? 0), 2),
            ]))->save();
            return $asset->fresh(['category', 'branch', 'location', 'supplier']);
        });
    }

    public function acquisitions(array $filters = []) { return AssetAcquisition::query()->with(['category', 'branch', 'supplier'])->where('business_id', AppController::businessId())->latest('id')->paginate(20); }

    public function saveAcquisition(array $data, ?int $id = null): AssetAcquisition
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $total = round((float) $data['base_cost'] + (float) ($data['additional_cost'] ?? 0) + (float) ($data['non_creditable_tax_amount'] ?? 0), 2);
            $acq = $id ? AssetAcquisition::query()->where('business_id', $businessId)->findOrFail($id) : new AssetAcquisition(['business_id' => $businessId, 'acquisition_number' => $this->nextNumber('ACQ', AssetAcquisition::class, 'acquisition_number'), 'created_by' => Auth::id()]);
            if ($acq->status === 'posted') throw ValidationException::withMessages(['status' => 'Posted acquisition cannot be edited.']);
            $acq->fill(array_merge($data, ['total_capitalizable_cost' => $total]))->save();
            if ($data['status'] === 'posted') $this->postAcquisition($acq->id);
            return $acq->fresh(['category', 'supplier']);
        });
    }

    public function postAcquisition(int $id): AssetAcquisition
    {
        return DB::transaction(function () use ($id) {
            $acq = AssetAcquisition::query()->where('business_id', AppController::businessId())->with('category')->findOrFail($id);
            if ($acq->journal_voucher_id) return $acq;
            $settings = $this->settings();
            $debitAccount = $settings->default_asset_clearing_account_id ?: $acq->category->asset_account_id;
            $creditAccount = $settings->default_asset_clearing_account_id ?: $acq->category->asset_account_id;
            if ($debitAccount === $creditAccount) {
                $acq->update(['status' => 'posted', 'approved_by' => Auth::id()]);
                return $acq;
            }
            $journal = $this->journal('asset_acquisition', $acq, $acq->acquisition_date->format('Y-m-d'), $acq->acquisition_number, [
                ['account_id' => $debitAccount, 'debit_amount' => (float) $acq->total_capitalizable_cost, 'credit_amount' => 0],
                ['account_id' => $creditAccount, 'debit_amount' => 0, 'credit_amount' => (float) $acq->total_capitalizable_cost, 'supplier_id' => $acq->supplier_id],
            ]);
            $acq->update(['status' => 'posted', 'approved_by' => Auth::id(), 'journal_voucher_id' => $journal->id]);
            return $acq->fresh();
        });
    }

    public function capitalize(array $data): AssetCapitalization
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $category = $this->category((int) $data['asset_category_id']);
            $asset = !empty($data['fixed_asset_id']) ? FixedAsset::query()->where('business_id', $businessId)->findOrFail($data['fixed_asset_id']) : $this->saveAsset([
                'branch_id' => $data['branch_id'], 'asset_category_id' => $category->id, 'asset_name' => $data['asset_name'] ?? 'Capitalized Asset',
                'asset_tag' => $data['asset_tag'] ?? null, 'acquisition_date' => $data['capitalization_date'], 'capitalization_date' => $data['capitalization_date'],
                'put_to_use_date' => $data['put_to_use_date'] ?? $data['capitalization_date'], 'purchase_cost' => $data['capitalized_amount'], 'additional_cost' => 0,
                'depreciation_method' => $category->default_depreciation_method, 'useful_life_months' => $category->default_useful_life_months,
                'depreciation_rate' => $category->default_depreciation_rate, 'current_location_id' => $data['asset_location_id'] ?? null,
                'assigned_employee_id' => $data['assigned_employee_id'] ?? null, 'ownership_type' => 'owned', 'condition_status' => 'new', 'asset_status' => 'active',
            ]);
            $cap = AssetCapitalization::query()->create(array_merge($data, ['business_id' => $businessId, 'fixed_asset_id' => $asset->id, 'capitalization_number' => $this->nextNumber('CAP', AssetCapitalization::class, 'capitalization_number'), 'created_by' => Auth::id()]));
            $asset->update(['capitalization_date' => $cap->capitalization_date, 'put_to_use_date' => $cap->put_to_use_date, 'asset_status' => 'active', 'approved_by' => Auth::id(), 'approved_at' => now()]);
            return $cap->fresh(['asset', 'category', 'location']);
        });
    }

    public function depreciationRuns(array $filters = []) { return DepreciationRun::query()->with('schedules.asset.category')->where('business_id', AppController::businessId())->latest('id')->paginate(20); }

    public function createDepreciationRun(array $data): DepreciationRun
    {
        return DB::transaction(function () use ($data) {
            $run = DepreciationRun::query()->create(array_merge($data, ['business_id' => AppController::businessId(), 'run_number' => $this->nextNumber('DEP', DepreciationRun::class, 'run_number'), 'created_by' => Auth::id(), 'status' => 'calculated']));
            $assets = FixedAsset::query()->where('business_id', $run->business_id)->whereIn('asset_status', ['active', 'impaired'])->when($run->branch_id, fn (Builder $q) => $q->where('branch_id', $run->branch_id))->with('category')->get();
            $processed = $skipped = 0; $total = 0;
            foreach ($assets as $asset) {
                if (AssetDepreciationSchedule::query()->where('fixed_asset_id', $asset->id)->where('period_start', $run->period_start)->where('period_end', $run->period_end)->where('status', 'posted')->exists()) { $skipped++; continue; }
                try {
                    $schedule = $this->depreciation->calculateAssetSchedule($asset, $run->financial_year, $run->period_start->format('Y-m-d'), $run->period_end->format('Y-m-d'), $run->id);
                    $total += (float) $schedule->depreciation_amount; $processed++;
                } catch (\Throwable $e) { $skipped++; }
            }
            $run->update(['total_assets' => $assets->count(), 'processed_assets' => $processed, 'skipped_assets' => $skipped, 'total_depreciation' => round($total, 2), 'status' => $data['status'] === 'posted' ? 'calculated' : 'calculated']);
            if ($data['status'] === 'posted') $this->postDepreciation($run->id);
            return $run->fresh('schedules.asset.category');
        });
    }

    public function postDepreciation(int $id): DepreciationRun
    {
        return DB::transaction(function () use ($id) {
            $run = DepreciationRun::query()->where('business_id', AppController::businessId())->with('schedules.asset.category')->findOrFail($id);
            if ($run->journal_voucher_id) return $run;
            $entries = [];
            foreach ($run->schedules as $schedule) {
                if ((float) $schedule->depreciation_amount <= 0) continue;
                $category = $schedule->asset->category;
                $entries[] = ['account_id' => $category->depreciation_expense_account_id, 'fixed_asset_id' => $schedule->fixed_asset_id, 'debit_amount' => (float) $schedule->depreciation_amount, 'credit_amount' => 0, 'narration' => 'Depreciation ' . $run->run_number];
                $entries[] = ['account_id' => $category->accumulated_depreciation_account_id, 'fixed_asset_id' => $schedule->fixed_asset_id, 'debit_amount' => 0, 'credit_amount' => (float) $schedule->depreciation_amount, 'narration' => 'Accumulated depreciation ' . $run->run_number];
            }
            if (!$entries) throw ValidationException::withMessages(['depreciation' => 'No depreciation amount to post.']);
            $journal = $this->journal('depreciation', $run, $run->posting_date->format('Y-m-d'), $run->run_number, $entries);
            foreach ($run->schedules as $schedule) {
                if ((float) $schedule->depreciation_amount <= 0) continue;
                $asset = $schedule->asset;
                $asset->increment('accumulated_depreciation', (float) $schedule->depreciation_amount);
                $asset->update(['net_book_value' => $this->depreciation->getNetBookValue($asset->fresh())]);
                $schedule->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'posted_at' => now()]);
            }
            $run->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'posted_by' => Auth::id(), 'posted_at' => now()]);
            return $run->fresh('schedules.asset');
        });
    }

    public function assignAsset(array $data): AssetAssignment
    {
        return DB::transaction(function () use ($data) {
            $asset = $this->asset((int) $data['fixed_asset_id']);
            if (in_array($asset->asset_status, ['disposed', 'sold', 'written_off', 'lost', 'stolen'], true)) throw ValidationException::withMessages(['asset' => 'Disposed asset cannot be assigned.']);
            AssetAssignment::query()->where('fixed_asset_id', $asset->id)->where('status', 'assigned')->update(['status' => 'returned', 'actual_return_date' => $data['assignment_date'], 'returned_by' => Auth::id()]);
            $assignment = AssetAssignment::query()->create(array_merge($data, ['business_id' => $asset->business_id, 'assigned_by' => Auth::id(), 'status' => 'assigned']));
            $asset->update(['assigned_employee_id' => $data['assigned_to_employee_id'] ?? null, 'current_location_id' => $data['assigned_location_id'] ?? $asset->current_location_id]);
            return $assignment->fresh(['asset', 'location']);
        });
    }

    public function returnAssignment(int $id, array $data): AssetAssignment
    {
        $assignment = AssetAssignment::query()->where('business_id', AppController::businessId())->findOrFail($id);
        $assignment->update(['status' => 'returned', 'actual_return_date' => $data['actual_return_date'] ?? now()->toDateString(), 'condition_at_return' => $data['condition_at_return'] ?? null, 'return_notes' => $data['return_notes'] ?? null, 'returned_by' => Auth::id()]);
        $assignment->asset()->update(['assigned_employee_id' => null]);
        return $assignment->fresh('asset');
    }

    public function saveTransfer(array $data, ?int $id = null): AssetTransferVoucher
    {
        return DB::transaction(function () use ($data, $id) {
            if ((int) $data['source_branch_id'] === (int) $data['destination_branch_id'] && (int) ($data['source_location_id'] ?? 0) === (int) ($data['destination_location_id'] ?? 0)) throw ValidationException::withMessages(['destination_branch_id' => 'Source and destination cannot be same.']);
            $items = $data['items'] ?? []; unset($data['items']);
            $voucher = $id ? AssetTransferVoucher::query()->where('business_id', AppController::businessId())->findOrFail($id) : new AssetTransferVoucher(['business_id' => AppController::businessId(), 'transfer_number' => $this->nextNumber('ATR', AssetTransferVoucher::class, 'transfer_number'), 'created_by' => Auth::id()]);
            $voucher->fill($data)->save();
            $voucher->items()->delete();
            foreach ($items as $item) $voucher->items()->create($item);
            if ($data['status'] === 'received') $this->receiveTransfer($voucher->id);
            return $voucher->fresh(['items.asset', 'sourceBranch', 'destinationBranch']);
        });
    }

    public function receiveTransfer(int $id): AssetTransferVoucher
    {
        return DB::transaction(function () use ($id) {
            $voucher = AssetTransferVoucher::query()->where('business_id', AppController::businessId())->with('items.asset')->findOrFail($id);
            foreach ($voucher->items as $item) {
                $item->asset->update(['branch_id' => $voucher->destination_branch_id, 'current_location_id' => $voucher->destination_location_id, 'assigned_employee_id' => $item->destination_employee_id, 'asset_status' => 'active']);
                $item->update(['status' => 'received']);
            }
            $voucher->update(['status' => 'received', 'received_by' => Auth::id(), 'received_at' => now()]);
            return $voucher->fresh(['items.asset']);
        });
    }

    public function saveMaintenance(array $data, ?int $id = null): AssetMaintenanceRequest
    {
        $asset = $this->asset((int) $data['fixed_asset_id']);
        $request = $id ? AssetMaintenanceRequest::query()->where('business_id', AppController::businessId())->findOrFail($id) : new AssetMaintenanceRequest(['business_id' => $asset->business_id, 'request_number' => $this->nextNumber('AMR', AssetMaintenanceRequest::class, 'request_number'), 'requested_by' => Auth::id()]);
        $request->fill($data)->save();
        $asset->update(['asset_status' => in_array($data['status'], ['open', 'approved', 'scheduled', 'in_progress'], true) ? 'under_maintenance' : 'active']);
        return $request->fresh(['asset', 'vendor']);
    }

    public function simpleCreate(string $type, array $data)
    {
        $asset = !empty($data['fixed_asset_id']) ? $this->asset((int) $data['fixed_asset_id']) : null;
        $map = ['maintenance_schedule' => AssetMaintenanceSchedule::class, 'warranty' => AssetWarranty::class, 'insurance' => AssetInsurancePolicy::class, 'meter' => AssetMeterReading::class];
        $class = $map[$type] ?? null; if (!$class) abort(404);
        if ($type === 'meter') {
            $last = AssetMeterReading::query()->where('fixed_asset_id', $asset->id)->where('meter_type', $data['meter_type'])->orderByDesc('reading_date')->orderByDesc('id')->first();
            if ($last && (float) $data['reading_value'] < (float) $last->reading_value) throw ValidationException::withMessages(['reading_value' => 'Meter reading cannot be below previous reading.']);
            $data['recorded_by'] = Auth::id();
        }
        return $class::query()->create(array_merge($data, ['business_id' => AppController::businessId(), 'created_by' => Auth::id()]));
    }

    public function revalue(array $data): AssetRevaluationVoucher
    {
        return DB::transaction(function () use ($data) {
            $asset = $this->asset((int) $data['fixed_asset_id']);
            $difference = round((float) $data['revalued_amount'] - (float) $asset->net_book_value, 2);
            $voucher = AssetRevaluationVoucher::query()->create(array_merge($data, ['business_id' => $asset->business_id, 'branch_id' => $asset->branch_id, 'revaluation_number' => $this->nextNumber('ARV', AssetRevaluationVoucher::class, 'revaluation_number'), 'previous_gross_value' => $asset->capitalized_cost, 'previous_accumulated_depreciation' => $asset->accumulated_depreciation, 'previous_net_book_value' => $asset->net_book_value, 'revaluation_difference' => $difference, 'revaluation_type' => $difference >= 0 ? 'upward' : 'downward', 'created_by' => Auth::id(), 'status' => $data['status'] ?? 'draft']));
            if (($data['status'] ?? '') === 'posted') $this->postRevaluation($voucher->id);
            return $voucher->fresh('asset');
        });
    }

    public function impair(array $data): AssetImpairmentVoucher
    {
        return DB::transaction(function () use ($data) {
            $asset = $this->asset((int) $data['fixed_asset_id']);
            if ((float) $data['recoverable_amount'] > (float) $asset->net_book_value) throw ValidationException::withMessages(['recoverable_amount' => 'Recoverable amount cannot exceed carrying amount.']);
            $loss = round((float) $asset->net_book_value - (float) $data['recoverable_amount'], 2);
            $voucher = AssetImpairmentVoucher::query()->create(array_merge($data, ['business_id' => $asset->business_id, 'branch_id' => $asset->branch_id, 'impairment_number' => $this->nextNumber('IMP', AssetImpairmentVoucher::class, 'impairment_number'), 'carrying_amount_before' => $asset->net_book_value, 'impairment_loss' => $loss, 'created_by' => Auth::id(), 'status' => $data['status'] ?? 'draft']));
            if (($data['status'] ?? '') === 'posted') $this->postImpairment($voucher->id);
            return $voucher->fresh('asset');
        });
    }

    public function dispose(array $data): AssetDisposalVoucher
    {
        return DB::transaction(function () use ($data) {
            $asset = $this->asset((int) $data['fixed_asset_id']);
            if (in_array($asset->asset_status, ['disposed', 'sold', 'written_off', 'lost', 'stolen'], true)) throw ValidationException::withMessages(['asset' => 'Asset is already disposed.']);
            $profit = round((float) ($data['sale_value'] ?? 0) - (float) $asset->net_book_value - (float) ($data['disposal_expense'] ?? 0), 2);
            $voucher = AssetDisposalVoucher::query()->create(array_merge($data, ['business_id' => $asset->business_id, 'branch_id' => $asset->branch_id, 'disposal_number' => $this->nextNumber('DIS', AssetDisposalVoucher::class, 'disposal_number'), 'gross_book_value' => $asset->capitalized_cost, 'accumulated_depreciation' => $asset->accumulated_depreciation, 'accumulated_impairment' => $asset->accumulated_impairment, 'net_book_value' => $asset->net_book_value, 'profit_or_loss' => $profit, 'created_by' => Auth::id(), 'status' => $data['status'] ?? 'draft']));
            if (($data['status'] ?? '') === 'posted') $this->postDisposal($voucher->id);
            return $voucher->fresh('asset');
        });
    }

    public function verification(array $data): AssetVerificationSession
    {
        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? []; unset($data['items']);
            $session = AssetVerificationSession::query()->create(array_merge($data, ['business_id' => AppController::businessId(), 'session_number' => $this->nextNumber('VER', AssetVerificationSession::class, 'session_number'), 'created_by' => Auth::id()]));
            foreach ($items as $item) $session->items()->create($item);
            return $session->fresh(['items.asset', 'branch', 'location']);
        });
    }

    public function dashboard(array $filters = []): array
    {
        $assets = FixedAsset::query()->where('business_id', AppController::businessId());
        return [
            'total_assets' => (clone $assets)->count(),
            'gross_asset_value' => (float) (clone $assets)->sum('capitalized_cost'),
            'accumulated_depreciation' => (float) (clone $assets)->sum('accumulated_depreciation'),
            'net_asset_value' => (float) (clone $assets)->sum('net_book_value'),
            'current_period_depreciation' => (float) AssetDepreciationSchedule::query()->where('business_id', AppController::businessId())->where('status', 'posted')->whereMonth('period_end', now()->month)->sum('depreciation_amount'),
            'assets_added' => (clone $assets)->whereDate('created_at', '>=', now()->startOfMonth())->count(),
            'assets_disposed' => (clone $assets)->whereIn('asset_status', ['disposed', 'sold', 'written_off'])->count(),
            'fully_depreciated_assets' => (clone $assets)->whereColumn('net_book_value', '<=', 'residual_value')->count(),
            'assets_under_maintenance' => (clone $assets)->where('asset_status', 'under_maintenance')->count(),
            'maintenance_due' => AssetMaintenanceSchedule::query()->where('business_id', AppController::businessId())->whereDate('next_service_date', '<=', now()->addDays(7))->count(),
            'warranty_expiring' => FixedAsset::query()->where('business_id', AppController::businessId())->whereBetween('warranty_end_date', [now(), now()->addDays(30)])->count(),
            'insurance_expiring' => AssetInsurancePolicy::query()->where('business_id', AppController::businessId())->whereBetween('end_date', [now(), now()->addDays(30)])->count(),
            'missing_assets' => DB::table('asset_verification_items')->join('asset_verification_sessions', 'asset_verification_sessions.id', '=', 'asset_verification_items.asset_verification_session_id')->where('asset_verification_sessions.business_id', AppController::businessId())->where('verification_status', 'not_found')->count(),
            'unverified_assets' => DB::table('asset_verification_items')->join('asset_verification_sessions', 'asset_verification_sessions.id', '=', 'asset_verification_items.asset_verification_session_id')->where('asset_verification_sessions.business_id', AppController::businessId())->where('verification_status', 'not_verified')->count(),
            'value_by_category' => FixedAsset::query()->where('fixed_assets.business_id', AppController::businessId())->join('asset_categories', 'asset_categories.id', '=', 'fixed_assets.asset_category_id')->select('asset_categories.category_name as label', DB::raw('sum(fixed_assets.net_book_value) as value'), DB::raw('count(*) as count'))->groupBy('asset_categories.category_name')->get(),
        ];
    }

    public function reports(): array
    {
        $businessId = AppController::businessId();
        return [
            'asset_register' => FixedAsset::query()->where('business_id', $businessId)->with(['category', 'branch', 'location'])->latest('id')->limit(300)->get(),
            'depreciation_report' => AssetDepreciationSchedule::query()->where('business_id', $businessId)->with('asset.category')->latest('period_end')->limit(300)->get(),
            'addition_report' => AssetAcquisition::query()->where('business_id', $businessId)->latest('id')->limit(200)->get(),
            'disposal_report' => AssetDisposalVoucher::query()->where('business_id', $businessId)->with('asset')->latest('id')->limit(200)->get(),
            'transfer_report' => AssetTransferVoucher::query()->where('business_id', $businessId)->with('items.asset')->latest('id')->limit(200)->get(),
            'maintenance_due_report' => AssetMaintenanceSchedule::query()->where('business_id', $businessId)->whereDate('next_service_date', '<=', now()->addDays(30))->with('asset')->get(),
            'warranty_expiry_report' => AssetWarranty::query()->where('business_id', $businessId)->whereDate('end_date', '<=', now()->addDays(30))->with('asset')->get(),
            'insurance_expiry_report' => AssetInsurancePolicy::query()->where('business_id', $businessId)->whereDate('end_date', '<=', now()->addDays(30))->with('items.asset')->get(),
            'reconciliation' => $this->reconciliation(),
        ];
    }

    public function reconciliation(): array
    {
        $businessId = AppController::businessId();
        return [
            'register_net_value' => (float) FixedAsset::query()->where('business_id', $businessId)->sum('net_book_value'),
            'missing_journal_assets' => FixedAsset::query()->where('business_id', $businessId)->whereIn('asset_status', ['active', 'impaired'])->whereNull('journal_voucher_id')->limit(100)->get(),
            'negative_nbv' => FixedAsset::query()->where('business_id', $businessId)->where('net_book_value', '<', 0)->limit(100)->get(),
            'without_location' => FixedAsset::query()->where('business_id', $businessId)->whereNull('current_location_id')->limit(100)->get(),
            'without_tag' => FixedAsset::query()->where('business_id', $businessId)->whereNull('asset_tag')->limit(100)->get(),
        ];
    }

    private function postRevaluation(int $id): void
    {
        $voucher = AssetRevaluationVoucher::query()->where('business_id', AppController::businessId())->with('asset.category')->findOrFail($id);
        if ($voucher->journal_voucher_id) return;
        $asset = $voucher->asset; $settings = $this->settings(); $difference = abs((float) $voucher->revaluation_difference);
        if ($difference <= 0) return;
        $offset = $voucher->revaluation_type === 'upward' ? ($settings->default_profit_on_sale_account_id ?: $asset->category->profit_on_sale_account_id) : ($asset->category->loss_on_sale_account_id ?: $settings->default_loss_on_sale_account_id);
        if (!$offset) throw ValidationException::withMessages(['account' => 'Revaluation offset account is missing.']);
        $entries = $voucher->revaluation_type === 'upward'
            ? [['account_id' => $asset->category->asset_account_id, 'fixed_asset_id' => $asset->id, 'debit_amount' => $difference, 'credit_amount' => 0], ['account_id' => $offset, 'fixed_asset_id' => $asset->id, 'debit_amount' => 0, 'credit_amount' => $difference]]
            : [['account_id' => $offset, 'fixed_asset_id' => $asset->id, 'debit_amount' => $difference, 'credit_amount' => 0], ['account_id' => $asset->category->asset_account_id, 'fixed_asset_id' => $asset->id, 'debit_amount' => 0, 'credit_amount' => $difference]];
        $journal = $this->journal('asset_revaluation', $voucher, $voucher->revaluation_date->format('Y-m-d'), $voucher->revaluation_number, $entries);
        $asset->update(['capitalized_cost' => (float) $asset->capitalized_cost + (float) $voucher->revaluation_difference, 'net_book_value' => $voucher->revalued_amount]);
        $voucher->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'approved_by' => Auth::id()]);
    }

    private function postImpairment(int $id): void
    {
        $voucher = AssetImpairmentVoucher::query()->where('business_id', AppController::businessId())->with('asset.category')->findOrFail($id);
        if ($voucher->journal_voucher_id) return;
        $asset = $voucher->asset; $settings = $this->settings();
        $lossAccount = $asset->category->impairment_loss_account_id ?: $settings->default_impairment_loss_account_id;
        $accumAccount = $settings->default_accumulated_impairment_account_id ?: $asset->category->accumulated_depreciation_account_id;
        if (!$lossAccount || !$accumAccount) throw ValidationException::withMessages(['account' => 'Impairment accounts are missing.']);
        $journal = $this->journal('asset_impairment', $voucher, $voucher->impairment_date->format('Y-m-d'), $voucher->impairment_number, [
            ['account_id' => $lossAccount, 'fixed_asset_id' => $asset->id, 'debit_amount' => (float) $voucher->impairment_loss, 'credit_amount' => 0],
            ['account_id' => $accumAccount, 'fixed_asset_id' => $asset->id, 'debit_amount' => 0, 'credit_amount' => (float) $voucher->impairment_loss],
        ]);
        $asset->increment('accumulated_impairment', (float) $voucher->impairment_loss);
        $asset->update(['net_book_value' => $voucher->recoverable_amount, 'asset_status' => 'impaired']);
        $voucher->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'approved_by' => Auth::id()]);
    }

    private function postDisposal(int $id): void
    {
        $voucher = AssetDisposalVoucher::query()->where('business_id', AppController::businessId())->with('asset.category')->findOrFail($id);
        if ($voucher->journal_voucher_id) return;
        $asset = $voucher->asset; $settings = $this->settings(); $entries = [];
        $cashOrDisposal = $settings->default_asset_disposal_account_id ?: $asset->category->asset_account_id;
        if ((float) $voucher->sale_value > 0) $entries[] = ['account_id' => $cashOrDisposal, 'fixed_asset_id' => $asset->id, 'debit_amount' => (float) $voucher->sale_value, 'credit_amount' => 0, 'customer_id' => $voucher->buyer_customer_id];
        if ((float) $voucher->accumulated_depreciation > 0) $entries[] = ['account_id' => $asset->category->accumulated_depreciation_account_id, 'fixed_asset_id' => $asset->id, 'debit_amount' => (float) $voucher->accumulated_depreciation, 'credit_amount' => 0];
        $entries[] = ['account_id' => $asset->category->asset_account_id, 'fixed_asset_id' => $asset->id, 'debit_amount' => 0, 'credit_amount' => (float) $voucher->gross_book_value];
        if ((float) $voucher->profit_or_loss > 0) $entries[] = ['account_id' => $settings->default_profit_on_sale_account_id ?: $asset->category->profit_on_sale_account_id, 'fixed_asset_id' => $asset->id, 'debit_amount' => 0, 'credit_amount' => (float) $voucher->profit_or_loss];
        if ((float) $voucher->profit_or_loss < 0) $entries[] = ['account_id' => $settings->default_loss_on_sale_account_id ?: $asset->category->loss_on_sale_account_id, 'fixed_asset_id' => $asset->id, 'debit_amount' => abs((float) $voucher->profit_or_loss), 'credit_amount' => 0];
        if (collect($entries)->contains(fn ($e) => empty($e['account_id']))) throw ValidationException::withMessages(['account' => 'Disposal account mapping is missing.']);
        $journal = $this->journal('asset_disposal', $voucher, $voucher->disposal_date->format('Y-m-d'), $voucher->disposal_number, $entries);
        $asset->update(['asset_status' => $voucher->disposal_type === 'sale' ? 'sold' : ($voucher->disposal_type === 'write_off' ? 'written_off' : 'disposed'), 'disposal_date' => $voucher->disposal_date, 'net_book_value' => 0]);
        $voucher->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'posted_at' => now(), 'approved_by' => Auth::id()]);
    }

    private function journal(string $type, $source, string $date, string $number, array $entries): JournalVoucher
    {
        return $this->posting->createJournalVoucher(['business_id' => $source->business_id, 'branch_id' => $source->branch_id ?? null, 'voucher_type' => $type, 'voucher_date' => $date, 'reference_type' => get_class($source), 'reference_id' => $source->id, 'reference_number' => $number, 'narration' => ucfirst(str_replace('_', ' ', $type)) . ' ' . $number, 'status' => 'approved', 'is_system_generated' => true, 'entries' => $entries]);
    }

    private function account(int $id): Account { return Account::query()->where('business_id', AppController::businessId())->findOrFail($id); }
    private function branch(int $id) { return DB::table('branches')->where('business_id', AppController::businessId())->where('id', $id)->first() ?: abort(404); }
    private function category(int $id): AssetCategory { return AssetCategory::query()->where('business_id', AppController::businessId())->findOrFail($id); }
    private function asset(int $id): FixedAsset { return FixedAsset::query()->where('business_id', AppController::businessId())->with(['category', 'location'])->findOrFail($id); }
    private function uniqueAssetTag(?string $tag, ?int $ignoreId): void { if ($tag && FixedAsset::query()->where('business_id', AppController::businessId())->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))->where('asset_tag', $tag)->exists()) throw ValidationException::withMessages(['asset_tag' => 'Asset tag already exists.']); }
    private function uniqueSerial(?string $serial, ?int $ignoreId): void { if ($serial && FixedAsset::query()->where('business_id', AppController::businessId())->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))->where('serial_number', $serial)->exists()) throw ValidationException::withMessages(['serial_number' => 'Serial number already exists.']); }
    private function nextNumber(string $prefix, string $model, string $column): string { $prefix .= '-' . date('Y') . '-'; $last = $model::query()->where('business_id', AppController::businessId())->where($column, 'like', $prefix . '%')->orderByDesc('id')->value($column); return $prefix . str_pad((string) ($last ? ((int) substr($last, strlen($prefix)) + 1) : 1), 5, '0', STR_PAD_LEFT); }
}
