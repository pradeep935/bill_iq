<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Campaign;
use App\Models\CrmActivity;
use App\Models\CrmLostReason;
use App\Models\CrmReminder;
use App\Models\CrmSetting;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadContact;
use App\Models\LeadQualification;
use App\Models\LeadScoreLog;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SalesPipeline;
use App\Models\SalesTarget;
use App\Models\SalesTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrmService
{
    private CustomerService $customers;
    private OrderManagementService $orders;

    public function __construct(CustomerService $customers, OrderManagementService $orders)
    {
        $this->customers = $customers;
        $this->orders = $orders;
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        $pipeline = SalesPipeline::query()->where('business_id', $businessId)->where('is_default', true)->first();

        return [
            'settings' => $this->settings(),
            'sources' => LeadSource::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('source_name')->get(),
            'statuses' => LeadStatus::query()->where('business_id', $businessId)->where('active', true)->orderBy('display_order')->get(),
            'lost_reasons' => CrmLostReason::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('reason_name')->get(),
            'campaigns' => Campaign::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('campaign_name')->get(),
            'teams' => SalesTeam::query()->where('business_id', $businessId)->where('status', 'active')->with('members.user')->orderBy('team_name')->get(),
            'pipelines' => SalesPipeline::query()->where('business_id', $businessId)->where('status', 'active')->with('stages')->orderByDesc('is_default')->get(),
            'stages' => $pipeline ? $pipeline->stages()->get() : collect(),
            'users' => User::query()->where('status', 'active')->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'email', 'phone', 'branch_id', 'role_id']),
            'branches' => DB::table('branches')->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'customers' => Customer::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('customer_name')->limit(300)->get(['id', 'customer_code', 'customer_name', 'mobile', 'email', 'gstin', 'billing_address', 'shipping_address']),
            'products' => Product::query()->where(function (Builder $q) use ($businessId) { $q->where('business_id', $businessId)->orWhere('company_id', $businessId); })->where('status', 'active')->orderBy('name')->limit(300)->get(['id', 'name', 'sku', 'selling_price', 'gst_rate']),
        ];
    }

    public function settings(): CrmSetting
    {
        return CrmSetting::query()->firstOrCreate(
            ['business_id' => AppController::businessId()],
            ['assignment_method' => 'manual', 'duplicate_check_fields_json' => ['mobile', 'email', 'gstin'], 'default_follow_up_days' => 2, 'status' => 'active']
        );
    }

    public function dashboard(array $filters = []): array
    {
        $businessId = AppController::businessId();
        $leads = $this->leadScope($filters);
        $opportunities = $this->opportunityScope($filters);
        $today = now()->toDateString();

        return [
            'new_leads' => (clone $leads)->whereHas('statusModel', fn (Builder $q) => $q->where('is_initial', true))->count(),
            'unassigned_leads' => (clone $leads)->whereNull('assigned_to')->count(),
            'qualified_leads' => (clone $leads)->where('qualification_status', 'sales_qualified')->count(),
            'leads_converted' => (clone $leads)->where('conversion_status', 'converted')->count(),
            'open_opportunities' => (clone $opportunities)->where('status', 'open')->count(),
            'pipeline_value' => (float) (clone $opportunities)->where('status', 'open')->sum('estimated_value'),
            'weighted_pipeline_value' => (float) (clone $opportunities)->where('status', 'open')->sum('weighted_value'),
            'won_revenue' => (float) (clone $opportunities)->where('status', 'won')->sum('estimated_value'),
            'lost_opportunities' => (clone $opportunities)->where('status', 'lost')->count(),
            'overdue_followups' => CrmActivity::query()->where('business_id', $businessId)->whereIn('status', ['planned', 'in_progress'])->whereDate('activity_date', '<', $today)->count(),
            'activities_due_today' => CrmActivity::query()->where('business_id', $businessId)->whereDate('activity_date', $today)->count(),
            'quotations_pending' => SchemaGuard::tableExists('quotations') ? Quotation::query()->where('business_id', $businessId)->whereIn('status', ['draft', 'sent', 'viewed'])->count() : 0,
            'quotation_conversion_rate' => $this->quotationConversionRate($businessId),
            'leads_by_source' => $this->groupCount('leads', 'lead_sources', 'lead_source_id', 'source_name', $filters),
            'leads_by_status' => $this->groupCount('leads', 'lead_statuses', 'status_id', 'status_name', $filters),
            'pipeline_by_stage' => $this->pipelineByStage($filters),
        ];
    }

    public function leads(array $filters)
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        return $this->leadScope($filters)
            ->with(['source', 'statusModel', 'owner', 'team', 'contacts', 'convertedCustomer'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function saveLead(array $data, ?int $id = null): Lead
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $contacts = $data['contacts'] ?? [];
            unset($data['contacts']);
            $lead = $id ? Lead::query()->where('business_id', $businessId)->findOrFail($id) : new Lead(['business_id' => $businessId, 'lead_number' => $this->nextNumber('LEAD', Lead::class, 'lead_number'), 'created_by' => Auth::id()]);
            if ($lead->exists && $lead->conversion_status === 'converted' && AppController::roleId() !== 1) {
                throw ValidationException::withMessages(['lead' => 'Converted leads need admin permission to edit.']);
            }
            $this->validateLeadReferences($data);
            $settings = $this->settings();
            if ($settings->require_lead_source && empty($data['lead_source_id'])) throw ValidationException::withMessages(['lead_source_id' => 'Lead source is required.']);
            $duplicates = $this->duplicates($data, $lead->id);
            if ($settings->duplicate_check_enabled && $duplicates->isNotEmpty() && empty($data['duplicate_override'])) {
                throw ValidationException::withMessages(['duplicate' => 'Possible duplicate lead found: ' . $duplicates->pluck('lead_number')->implode(', ')]);
            }
            $oldOwner = $lead->assigned_to;
            $lead->fill(array_merge($data, [
                'status_id' => $data['status_id'] ?? $this->initialStatus()->id,
                'assigned_to' => $data['assigned_to'] ?? $this->autoOwner($data),
                'conversion_status' => $lead->conversion_status ?: 'not_converted',
                'updated_by' => Auth::id(),
            ]))->save();
            $this->syncContacts($lead, $contacts);
            if ((int) $oldOwner !== (int) $lead->assigned_to && $lead->assigned_to) $this->assignment($lead, $oldOwner, $lead->assigned_to, $data['assigned_team_id'] ?? null, 'manual', 'Lead owner changed');
            $this->activity('status_change', 'Lead saved', 'lead', $lead->id, ['lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to, 'status' => 'completed']);
            return $lead->fresh(['source', 'statusModel', 'owner', 'contacts']);
        });
    }

    public function assignLead(int $id, array $data): Lead
    {
        return DB::transaction(function () use ($id, $data) {
            $lead = $this->lead($id);
            $user = $this->activeUser((int) $data['assigned_to']);
            $old = $lead->assigned_to;
            $lead->update(['assigned_to' => $user->id, 'assigned_team_id' => $data['assigned_team_id'] ?? $lead->assigned_team_id, 'updated_by' => Auth::id()]);
            $this->assignment($lead, $old, $user->id, $lead->assigned_team_id, $data['assignment_method'] ?? 'manual', $data['assignment_reason'] ?? 'Manual assignment');
            return $lead->fresh(['owner', 'assignments']);
        });
    }

    public function bulkAssign(array $data): int
    {
        $count = 0;
        foreach ($data['lead_ids'] ?? [] as $id) {
            $this->assignLead((int) $id, $data);
            $count++;
        }
        return $count;
    }

    public function qualifyLead(int $id, array $data): LeadQualification
    {
        return DB::transaction(function () use ($id, $data) {
            $lead = $this->lead($id);
            $score = collect(['budget_status', 'authority_status', 'need_status', 'timeline_status'])->sum(fn ($field) => !empty($data[$field]) && $data[$field] !== 'unknown' ? 25 : 0);
            $status = $score >= 75 ? 'sales_qualified' : ($score >= 50 ? 'marketing_qualified' : 'unqualified');
            $qualification = LeadQualification::query()->updateOrCreate(
                ['business_id' => $lead->business_id, 'lead_id' => $lead->id],
                array_merge($data, ['qualification_score' => $score, 'qualification_status' => $status, 'qualified_by' => Auth::id(), 'qualified_at' => now()])
            );
            $lead->update(['qualification_status' => $status, 'score' => max((int) $lead->score, $score)]);
            $this->score($lead, 'qualification_updated', min(25, $score), null, null, 'Lead qualification updated');
            $this->activity('status_change', 'Lead qualification updated', 'lead', $lead->id, ['lead_id' => $lead->id, 'status' => 'completed', 'outcome' => $status]);
            return $qualification;
        });
    }

    public function opportunities(array $filters)
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        return $this->opportunityScope($filters)->with(['lead', 'customer', 'stage', 'pipeline', 'owner', 'items.product'])->latest('id')->paginate($perPage);
    }

    public function saveOpportunity(array $data, ?int $id = null): Opportunity
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            if (empty($data['lead_id']) && empty($data['customer_id'])) throw ValidationException::withMessages(['customer_id' => 'Opportunity must belong to a lead or customer.']);
            $items = $data['items'] ?? [];
            unset($data['items']);
            $stage = $this->stage((int) ($data['stage_id'] ?? 0), $data['pipeline_id'] ?? null);
            $probability = (float) ($data['probability_percent'] ?? $stage->probability_percent);
            if ($stage->is_lost && $this->settings()->require_lost_reason && empty($data['lost_reason_id'])) throw ValidationException::withMessages(['lost_reason_id' => 'Lost reason is required.']);
            $opportunity = $id ? Opportunity::query()->where('business_id', $businessId)->findOrFail($id) : new Opportunity(['business_id' => $businessId, 'opportunity_number' => $this->nextNumber('OPP', Opportunity::class, 'opportunity_number'), 'created_by' => Auth::id()]);
            $opportunity->fill(array_merge($data, [
                'pipeline_id' => $stage->sales_pipeline_id,
                'stage_id' => $stage->id,
                'owner_id' => $data['owner_id'] ?? Auth::id(),
                'probability_percent' => $probability,
                'weighted_value' => round((float) $data['estimated_value'] * $probability / 100, 2),
                'status' => $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : $data['status']),
                'actual_closing_date' => ($stage->is_won || $stage->is_lost) ? now()->toDateString() : ($data['actual_closing_date'] ?? null),
                'updated_by' => Auth::id(),
            ]))->save();
            $opportunity->items()->delete();
            foreach ($items as $row) $opportunity->items()->create($this->opportunityItem($row));
            if ($opportunity->lead_id) $this->activity('status_change', 'Opportunity updated', 'lead', $opportunity->lead_id, ['lead_id' => $opportunity->lead_id, 'opportunity_id' => $opportunity->id, 'status' => 'completed']);
            return $opportunity->fresh(['lead', 'customer', 'stage', 'items.product']);
        });
    }

    public function moveOpportunity(int $id, int $stageId, array $data = []): Opportunity
    {
        $opportunity = $this->opportunity($id);
        return $this->saveOpportunity(array_merge([
            'branch_id' => $opportunity->branch_id,
            'lead_id' => $opportunity->lead_id,
            'customer_id' => $opportunity->customer_id,
            'contact_id' => $opportunity->contact_id,
            'pipeline_id' => $opportunity->pipeline_id,
            'stage_id' => $stageId,
            'opportunity_name' => $opportunity->opportunity_name,
            'description' => $opportunity->description,
            'owner_id' => $opportunity->owner_id,
            'sales_team_id' => $opportunity->sales_team_id,
            'source_id' => $opportunity->source_id,
            'campaign_id' => $opportunity->campaign_id,
            'currency_id' => $opportunity->currency_id,
            'estimated_value' => (float) $opportunity->estimated_value,
            'probability_percent' => (float) $opportunity->probability_percent,
            'expected_closing_date' => optional($opportunity->expected_closing_date)->format('Y-m-d'),
            'actual_closing_date' => optional($opportunity->actual_closing_date)->format('Y-m-d'),
            'priority' => $opportunity->priority,
            'competitor_name' => $opportunity->competitor_name,
            'next_step' => $opportunity->next_step,
            'next_follow_up_at' => optional($opportunity->next_follow_up_at)->format('Y-m-d H:i:s'),
            'quotation_id' => $opportunity->quotation_id,
            'sales_order_id' => $opportunity->sales_order_id,
            'won_reason' => $opportunity->won_reason,
            'lost_reason_id' => $opportunity->lost_reason_id,
            'lost_notes' => $opportunity->lost_notes,
            'status' => $opportunity->status,
            'items' => $opportunity->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'product_variant_id' => $i->product_variant_id,
                'description' => $i->description,
                'quantity' => (float) $i->quantity,
                'estimated_unit_price' => (float) $i->estimated_unit_price,
                'estimated_discount' => (float) $i->estimated_discount,
                'estimated_tax' => (float) $i->estimated_tax,
                'probability_percent' => $i->probability_percent,
                'notes' => $i->notes,
            ])->all(),
        ], $data), $opportunity->id);
    }

    public function activities(array $filters)
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 30), 1), 100);
        return CrmActivity::query()
            ->where('business_id', AppController::businessId())
            ->with(['lead', 'opportunity', 'customer', 'assignee'])
            ->when($filters['related_type'] ?? null, fn (Builder $q, string $type) => $q->where('related_type', $type))
            ->when($filters['related_id'] ?? null, fn (Builder $q, int $id) => $q->where('related_id', $id))
            ->when($filters['assigned_to'] ?? null, fn (Builder $q, int $id) => $q->where('assigned_to', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['activity_type'] ?? null, fn (Builder $q, string $type) => $q->where('activity_type', $type))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('activity_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('activity_date', '<=', $date))
            ->latest('activity_date')
            ->paginate($perPage);
    }

    public function saveActivity(array $data, ?int $id = null): CrmActivity
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $reminderAt = $data['reminder_at'] ?? null;
            $channel = $data['reminder_channel'] ?? 'in_app';
            unset($data['reminder_at'], $data['reminder_channel']);
            $activity = $id ? CrmActivity::query()->where('business_id', $businessId)->findOrFail($id) : new CrmActivity(['business_id' => $businessId, 'activity_number' => $this->nextNumber('ACT', CrmActivity::class, 'activity_number'), 'created_by' => Auth::id()]);
            if (($data['status'] ?? '') === 'completed' && empty($data['outcome']) && in_array($data['activity_type'], ['call', 'meeting', 'follow_up', 'demo'], true)) throw ValidationException::withMessages(['outcome' => 'Outcome is required to complete this activity.']);
            $activity->fill(array_merge($data, ['assigned_to' => $data['assigned_to'] ?? Auth::id(), 'completed_at' => ($data['status'] ?? '') === 'completed' ? now() : null, 'completed_by' => ($data['status'] ?? '') === 'completed' ? Auth::id() : null]))->save();
            if ($reminderAt) {
                CrmReminder::query()->updateOrCreate(['business_id' => $businessId, 'activity_id' => $activity->id], ['related_type' => $activity->related_type, 'related_id' => $activity->related_id, 'user_id' => $activity->assigned_to ?: Auth::id(), 'reminder_at' => $reminderAt, 'reminder_channel' => $channel, 'status' => 'pending']);
            }
            $this->touchRelatedFollowup($activity);
            return $activity->fresh(['lead', 'opportunity', 'customer', 'assignee']);
        });
    }

    public function convertLead(int $id, array $data): Lead
    {
        return DB::transaction(function () use ($id, $data) {
            $lead = $this->lead($id);
            if ($lead->conversion_status === 'converted') return $lead->fresh(['convertedCustomer', 'opportunities']);
            $customer = !empty($data['customer_id']) ? Customer::query()->where('business_id', $lead->business_id)->findOrFail($data['customer_id']) : $this->customers->create([
                'customer_name' => $lead->company_name ?: $lead->contact_person_name,
                'customer_type' => $lead->lead_type === 'business' ? 'corporate' : 'retail',
                'contact_person' => $lead->contact_person_name,
                'mobile' => $lead->mobile,
                'phone' => $lead->alternate_mobile,
                'email' => $lead->email,
                'gstin' => $lead->gstin,
                'pan' => $lead->pan,
                'billing_address' => data_get($lead->billing_address_json, 'address') ?: $lead->requirement_summary,
                'shipping_address' => data_get($lead->shipping_address_json, 'address'),
                'state_id' => $lead->state_id,
                'city' => $lead->city,
                'pincode' => $lead->pincode,
                'status' => 'active',
            ]);
            $opportunity = null;
            if (!empty($data['create_opportunity'])) {
                $opportunity = $this->saveOpportunity([
                    'lead_id' => $lead->id,
                    'customer_id' => $customer->id,
                    'opportunity_name' => $data['opportunity_name'] ?? ('Opportunity - ' . $lead->contact_person_name),
                    'estimated_value' => $lead->estimated_value ?: 0,
                    'priority' => $lead->priority,
                    'status' => 'open',
                    'owner_id' => $lead->assigned_to ?: Auth::id(),
                    'source_id' => $lead->lead_source_id,
                    'campaign_id' => $lead->campaign_id,
                    'expected_closing_date' => $lead->expected_closing_date,
                    'items' => $data['items'] ?? [],
                ]);
            }
            $convertedStatus = LeadStatus::query()->where('business_id', $lead->business_id)->where('is_converted', true)->first();
            $lead->update(['conversion_status' => 'converted', 'converted_customer_id' => $customer->id, 'converted_opportunity_id' => $opportunity->id ?? null, 'converted_at' => now(), 'status_id' => $convertedStatus->id ?? $lead->status_id]);
            $this->activity('status_change', 'Lead converted', 'lead', $lead->id, ['lead_id' => $lead->id, 'customer_id' => $customer->id, 'opportunity_id' => $opportunity->id ?? null, 'status' => 'completed']);
            return $lead->fresh(['convertedCustomer', 'opportunities', 'statusModel']);
        });
    }

    public function convertOpportunityToQuotation(int $id): Quotation
    {
        $opportunity = $this->opportunity($id);
        $customerId = $opportunity->customer_id ?: optional($opportunity->lead)->converted_customer_id;
        if (!$customerId) throw ValidationException::withMessages(['customer_id' => 'Convert the lead to customer before creating quotation.']);
        $quotation = $this->orders->saveQuotation([
            'branch_id' => $opportunity->branch_id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(15)->toDateString(),
            'customer_id' => $customerId,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'shipping_amount' => 0,
            'status' => 'draft',
            'notes' => $opportunity->description,
            'terms_conditions' => null,
            'items' => $opportunity->items->map(fn ($item) => ['product_id' => $item->product_id, 'description' => $item->description, 'quantity' => (float) $item->quantity, 'unit_price' => (float) $item->estimated_unit_price, 'discount' => (float) $item->estimated_discount, 'gst_rate' => 0])->all(),
        ]);
        $opportunity->update(['quotation_id' => $quotation->id]);
        $this->activity('quotation', 'Quotation created from opportunity', 'opportunity', $opportunity->id, ['opportunity_id' => $opportunity->id, 'customer_id' => $customerId, 'status' => 'completed']);
        return $quotation;
    }

    public function kanban(array $filters = []): array
    {
        $pipeline = SalesPipeline::query()->where('business_id', AppController::businessId())->where('is_default', true)->with('stages')->first();
        if (!$pipeline) return [];
        return $pipeline->stages->map(function (PipelineStage $stage) use ($filters) {
            $cards = $this->opportunityScope($filters)->where('stage_id', $stage->id)->with(['lead', 'customer', 'owner'])->orderBy('expected_closing_date')->limit(100)->get();
            return ['stage' => $stage, 'count' => $cards->count(), 'total_value' => (float) $cards->sum('estimated_value'), 'weighted_value' => (float) $cards->sum('weighted_value'), 'cards' => $cards];
        })->values()->all();
    }

    public function calendar(array $filters = []): array
    {
        $start = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $end = $filters['date_to'] ?? now()->endOfMonth()->toDateString();
        return [
            'activities' => CrmActivity::query()->where('business_id', AppController::businessId())->with(['lead', 'opportunity', 'customer'])->whereBetween('activity_date', [$start, $end])->orderBy('activity_date')->get(),
            'opportunity_closings' => $this->opportunityScope($filters)->whereBetween('expected_closing_date', [$start, $end])->get(),
            'quotation_expiries' => SchemaGuard::tableExists('quotations') ? Quotation::query()->where('business_id', AppController::businessId())->whereBetween('valid_until', [$start, $end])->get() : [],
        ];
    }

    public function reports(array $filters = []): array
    {
        $leads = $this->leadScope($filters);
        $opportunities = $this->opportunityScope($filters);
        return [
            'lead_register' => (clone $leads)->with(['source', 'statusModel', 'owner'])->latest('id')->limit(200)->get(),
            'opportunity_register' => (clone $opportunities)->with(['lead', 'customer', 'stage', 'owner'])->latest('id')->limit(200)->get(),
            'follow_up_report' => CrmActivity::query()->where('business_id', AppController::businessId())->whereIn('activity_type', ['follow_up', 'call', 'meeting', 'task'])->latest('activity_date')->limit(200)->get(),
            'lost_reason_analysis' => Opportunity::query()->where('business_id', AppController::businessId())->where('status', 'lost')->select('lost_reason_id', DB::raw('count(*) as count'), DB::raw('sum(estimated_value) as value'))->groupBy('lost_reason_id')->with('lostReason')->get(),
            'campaign_performance' => Campaign::query()->where('business_id', AppController::businessId())->withCount(['leads', 'opportunities'])->get(),
            'sales_forecast' => $this->forecast($filters),
        ];
    }

    public function forecast(array $filters = []): array
    {
        return $this->opportunityScope($filters)
            ->select(DB::raw("DATE_FORMAT(expected_closing_date, '%Y-%m') as month"), 'owner_id', DB::raw('sum(estimated_value) as open_value'), DB::raw('sum(weighted_value) as weighted_value'), DB::raw("sum(case when probability_percent >= 75 then estimated_value else 0 end) as commit_value"), DB::raw("sum(case when status = 'won' then estimated_value else 0 end) as won_value"))
            ->groupBy(DB::raw("DATE_FORMAT(expected_closing_date, '%Y-%m')"), 'owner_id')
            ->orderBy('month')
            ->get();
    }

    public function saveMaster(string $type, array $data, ?int $id = null)
    {
        $businessId = AppController::businessId();
        $map = ['source' => LeadSource::class, 'status' => LeadStatus::class, 'lost_reason' => CrmLostReason::class, 'campaign' => Campaign::class, 'team' => SalesTeam::class, 'pipeline' => SalesPipeline::class, 'target' => SalesTarget::class];
        $class = $map[$type] ?? null;
        if (!$class) abort(404);
        $model = $id ? $class::query()->where('business_id', $businessId)->findOrFail($id) : new $class(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $model->fill($data)->save();
        return $model->fresh();
    }

    public function importLeads(array $rows): array
    {
        $imported = $duplicates = $failed = 0;
        foreach ($rows as $row) {
            try {
                $payload = array_intersect_key($row, array_flip(['lead_type', 'company_name', 'contact_person_name', 'email', 'mobile', 'lead_source_id', 'priority', 'estimated_value', 'requirement_summary', 'status']));
                $payload['lead_type'] = $payload['lead_type'] ?? 'individual';
                $payload['priority'] = $payload['priority'] ?? 'medium';
                $payload['status'] = $payload['status'] ?? 'active';
                if ($this->duplicates($payload)->isNotEmpty()) { $duplicates++; continue; }
                $this->saveLead($payload);
                $imported++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }
        return ['imported_rows' => $imported, 'duplicate_rows' => $duplicates, 'failed_rows' => $failed];
    }

    private function leadScope(array $filters = []): Builder
    {
        return Lead::query()
            ->where('business_id', AppController::businessId())
            ->when($filters['search'] ?? $filters['q'] ?? null, function (Builder $q, string $search) {
                $like = '%' . $search . '%';
                $q->where(fn (Builder $s) => $s->where('lead_number', 'like', $like)->orWhere('contact_person_name', 'like', $like)->orWhere('company_name', 'like', $like)->orWhere('mobile', 'like', $like)->orWhere('email', 'like', $like)->orWhere('gstin', 'like', $like));
            })
            ->when($filters['status_id'] ?? null, fn (Builder $q, int $id) => $q->where('status_id', $id))
            ->when($filters['lead_source_id'] ?? null, fn (Builder $q, int $id) => $q->where('lead_source_id', $id))
            ->when($filters['assigned_to'] ?? null, fn (Builder $q, int $id) => $q->where('assigned_to', $id))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $priority) => $q->where('priority', $priority));
    }

    private function opportunityScope(array $filters = []): Builder
    {
        return Opportunity::query()
            ->where('business_id', AppController::businessId())
            ->when($filters['search'] ?? $filters['q'] ?? null, function (Builder $q, string $search) {
                $like = '%' . $search . '%';
                $q->where(fn (Builder $s) => $s->where('opportunity_number', 'like', $like)->orWhere('opportunity_name', 'like', $like)->orWhereHas('customer', fn (Builder $c) => $c->where('customer_name', 'like', $like))->orWhereHas('lead', fn (Builder $l) => $l->where('contact_person_name', 'like', $like)->orWhere('company_name', 'like', $like)));
            })
            ->when($filters['stage_id'] ?? null, fn (Builder $q, int $id) => $q->where('stage_id', $id))
            ->when($filters['owner_id'] ?? null, fn (Builder $q, int $id) => $q->where('owner_id', $id))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status));
    }

    private function validateLeadReferences(array $data): void
    {
        if (!empty($data['assigned_to'])) $this->activeUser((int) $data['assigned_to']);
        if (!empty($data['lead_source_id'])) LeadSource::query()->where('business_id', AppController::businessId())->findOrFail($data['lead_source_id']);
        if (!empty($data['status_id'])) LeadStatus::query()->where('business_id', AppController::businessId())->findOrFail($data['status_id']);
    }

    private function syncContacts(Lead $lead, array $contacts): void
    {
        if (!$contacts) return;
        $lead->contacts()->delete();
        $primarySeen = false;
        foreach ($contacts as $contact) {
            $isPrimary = !empty($contact['is_primary']) && !$primarySeen;
            $primarySeen = $primarySeen || $isPrimary;
            $lead->contacts()->create(array_merge($contact, ['business_id' => $lead->business_id, 'is_primary' => $isPrimary]));
        }
        if (!$primarySeen && $lead->contacts()->exists()) $lead->contacts()->oldest('id')->first()->update(['is_primary' => true]);
    }

    private function duplicates(array $data, ?int $ignoreId = null)
    {
        $settings = $this->settings();
        $fields = $settings->duplicate_check_fields_json ?: ['mobile', 'email', 'gstin'];
        return Lead::query()->where('business_id', AppController::businessId())->when($ignoreId, fn (Builder $q) => $q->where('id', '<>', $ignoreId))->where(function (Builder $q) use ($data, $fields) {
            foreach ($fields as $field) if (!empty($data[$field])) $q->orWhere($field, $data[$field]);
        })->limit(10)->get(['id', 'lead_number', 'contact_person_name', 'mobile', 'email']);
    }

    private function activeUser(int $id): User
    {
        return User::query()->where('id', $id)->where('status', 'active')->where('is_active', 1)->firstOrFail();
    }

    private function autoOwner(array $data): ?int
    {
        $settings = $this->settings();
        if (!$settings->auto_assign_leads) return $settings->default_lead_owner_id ?: Auth::id();
        if ($settings->assignment_method === 'round_robin') return User::query()->where('status', 'active')->where('is_active', 1)->orderBy('id')->value('id');
        return $settings->default_lead_owner_id ?: Auth::id();
    }

    private function assignment(Lead $lead, ?int $from, int $to, ?int $teamId, string $method, ?string $reason): void
    {
        LeadAssignment::query()->where('lead_id', $lead->id)->whereNull('unassigned_at')->update(['unassigned_at' => now()]);
        LeadAssignment::query()->create(['business_id' => $lead->business_id, 'lead_id' => $lead->id, 'assigned_from' => $from, 'assigned_to' => $to, 'assigned_team_id' => $teamId, 'assignment_method' => $method, 'assignment_reason' => $reason, 'assigned_by' => Auth::id(), 'assigned_at' => now()]);
        $this->activity('assignment', 'Lead assigned', 'lead', $lead->id, ['lead_id' => $lead->id, 'assigned_to' => $to, 'status' => 'completed']);
    }

    private function activity(string $type, string $subject, string $relatedType, int $relatedId, array $extra = []): CrmActivity
    {
        return CrmActivity::query()->create(array_merge(['business_id' => AppController::businessId(), 'activity_number' => $this->nextNumber('ACT', CrmActivity::class, 'activity_number'), 'activity_type' => $type, 'subject' => $subject, 'related_type' => $relatedType, 'related_id' => $relatedId, 'activity_date' => now()->toDateString(), 'created_by' => Auth::id(), 'assigned_to' => Auth::id(), 'status' => 'completed'], $extra));
    }

    private function score(Lead $lead, string $event, int $change, ?string $type, ?int $id, ?string $remarks): void
    {
        $old = (int) $lead->score;
        $new = max(0, $old + $change);
        $lead->update(['score' => $new]);
        LeadScoreLog::query()->create(['business_id' => $lead->business_id, 'lead_id' => $lead->id, 'event_type' => $event, 'score_change' => $change, 'previous_score' => $old, 'new_score' => $new, 'reference_type' => $type, 'reference_id' => $id, 'remarks' => $remarks]);
    }

    private function stage(int $id = 0, ?int $pipelineId = null): PipelineStage
    {
        $businessId = AppController::businessId();
        if ($id) return PipelineStage::query()->whereHas('pipeline', fn (Builder $q) => $q->where('business_id', $businessId))->findOrFail($id);
        $pipeline = $pipelineId ? SalesPipeline::query()->where('business_id', $businessId)->findOrFail($pipelineId) : SalesPipeline::query()->where('business_id', $businessId)->where('is_default', true)->firstOrFail();
        return $pipeline->stages()->where('status', 'active')->orderBy('stage_order')->firstOrFail();
    }

    private function opportunityItem(array $row): array
    {
        $qty = (float) ($row['quantity'] ?? 1);
        $rate = (float) ($row['estimated_unit_price'] ?? 0);
        $discount = (float) ($row['estimated_discount'] ?? 0);
        $tax = (float) ($row['estimated_tax'] ?? 0);
        return array_merge($row, ['quantity' => $qty, 'estimated_unit_price' => $rate, 'estimated_discount' => $discount, 'estimated_tax' => $tax, 'estimated_total' => round(max(0, $qty * $rate - $discount) + $tax, 2)]);
    }

    private function touchRelatedFollowup(CrmActivity $activity): void
    {
        $payload = ['last_activity_at' => now(), 'next_follow_up_at' => $activity->next_follow_up_at];
        if ($activity->lead_id) Lead::query()->where('id', $activity->lead_id)->where('business_id', $activity->business_id)->update($payload);
        if ($activity->opportunity_id) Opportunity::query()->where('id', $activity->opportunity_id)->where('business_id', $activity->business_id)->update(['next_follow_up_at' => $activity->next_follow_up_at]);
    }

    private function initialStatus(): LeadStatus
    {
        return LeadStatus::query()->where('business_id', AppController::businessId())->where('is_initial', true)->firstOrFail();
    }

    private function lead(int $id): Lead
    {
        return Lead::query()->where('business_id', AppController::businessId())->with(['contacts', 'statusModel', 'source', 'owner', 'opportunities.items'])->findOrFail($id);
    }

    private function opportunity(int $id): Opportunity
    {
        return Opportunity::query()->where('business_id', AppController::businessId())->with(['lead', 'customer', 'stage', 'items'])->findOrFail($id);
    }

    private function nextNumber(string $prefix, string $model, string $column): string
    {
        $prefix .= '-' . date('Y') . '-';
        $last = $model::query()->where('business_id', AppController::businessId())->where($column, 'like', $prefix . '%')->orderByDesc('id')->value($column);
        return $prefix . str_pad((string) ($last ? ((int) substr($last, strlen($prefix)) + 1) : 1), 5, '0', STR_PAD_LEFT);
    }

    private function quotationConversionRate(int $businessId): float
    {
        if (!SchemaGuard::tableExists('quotations')) return 0;
        $total = Quotation::query()->where('business_id', $businessId)->count();
        return $total ? round(Quotation::query()->where('business_id', $businessId)->where('status', 'converted')->count() / $total * 100, 2) : 0;
    }

    private function groupCount(string $base, string $join, string $foreign, string $label, array $filters): array
    {
        return DB::table($base)->leftJoin($join, $base . '.' . $foreign, '=', $join . '.id')->where($base . '.business_id', AppController::businessId())->select(DB::raw("coalesce($join.$label, 'Unspecified') as label"), DB::raw('count(*) as count'))->groupBy('label')->orderBy('label')->get()->all();
    }

    private function pipelineByStage(array $filters): array
    {
        return $this->opportunityScope($filters)->join('pipeline_stages', 'opportunities.stage_id', '=', 'pipeline_stages.id')->select('pipeline_stages.stage_name as label', DB::raw('count(*) as count'), DB::raw('sum(opportunities.estimated_value) as value'), DB::raw('sum(opportunities.weighted_value) as weighted_value'))->groupBy('pipeline_stages.stage_name')->get()->all();
    }
}

class SchemaGuard
{
    public static function tableExists(string $table): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($table);
    }
}
