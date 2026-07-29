<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrmActivityRequest;
use App\Http\Requests\CrmLeadRequest;
use App\Http\Requests\CrmOpportunityRequest;
use App\Services\CrmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class CrmController extends Controller
{
    private CrmService $crm;

    public function __construct(CrmService $crm)
    {
        $this->crm = $crm;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('crm')) {
            return $redirect;
        }

        return Inertia::render('Crm/Index', [
            'page' => 'crm',
            'title' => 'CRM',
            'role_id' => AppController::roleId(),
            'initial_section' => $this->section(request('section')),
            'endpoints' => $this->endpoints(),
        ]);
    }

    public function references()
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json($this->crm->references());
    }

    public function dashboard(Request $request)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json($this->crm->dashboard($request->all()));
    }

    public function leads(Request $request)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json($this->page('leads', $this->crm->leads($request->all())));
    }

    public function saveLead(CrmLeadRequest $request, ?int $lead = null)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->saveLead($request->validated(), $lead);
        return response()->json(['message' => 'Lead saved successfully.', 'lead' => $row], $lead ? 200 : 201);
    }

    public function showLead(int $lead)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json(['lead' => $this->crm->showLead($lead)]);
    }

    public function destroyLead(int $lead)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $this->crm->destroyLead($lead);
        return response()->json(['message' => 'Lead deleted successfully.']);
    }

    public function assignLead(Request $request, int $lead)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->assignLead($lead, $request->validate([
            'assigned_to' => ['required', 'integer'],
            'assigned_team_id' => ['nullable', 'integer'],
            'assignment_method' => ['nullable', 'string'],
            'assignment_reason' => ['nullable', 'string'],
        ]));
        return response()->json(['message' => 'Lead assigned successfully.', 'lead' => $row]);
    }

    public function bulkAssign(Request $request)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $count = $this->crm->bulkAssign($request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer'],
            'assigned_to' => ['required', 'integer'],
            'assigned_team_id' => ['nullable', 'integer'],
            'assignment_reason' => ['nullable', 'string'],
        ]));
        return response()->json(['message' => $count . ' leads assigned successfully.', 'count' => $count]);
    }

    public function qualifyLead(Request $request, int $lead)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->qualifyLead($lead, $request->validate([
            'budget_status' => ['nullable', 'string'],
            'authority_status' => ['nullable', 'string'],
            'need_status' => ['nullable', 'string'],
            'timeline_status' => ['nullable', 'string'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'decision_maker_name' => ['nullable', 'string'],
            'expected_purchase_date' => ['nullable', 'date'],
            'pain_points' => ['nullable', 'string'],
            'requirement_details' => ['nullable', 'string'],
            'competitor_details' => ['nullable', 'string'],
        ]));
        return response()->json(['message' => 'Lead qualification saved.', 'qualification' => $row]);
    }

    public function convertLead(Request $request, int $lead)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->convertLead($lead, $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'create_opportunity' => ['boolean'],
            'opportunity_name' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
        ]));
        return response()->json(['message' => 'Lead converted successfully.', 'lead' => $row]);
    }

    public function opportunities(Request $request)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json($this->page('opportunities', $this->crm->opportunities($request->all())));
    }

    public function saveOpportunity(CrmOpportunityRequest $request, ?int $opportunity = null)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->saveOpportunity($request->validated(), $opportunity);
        return response()->json(['message' => 'Opportunity saved successfully.', 'opportunity' => $row], $opportunity ? 200 : 201);
    }

    public function moveOpportunity(Request $request, int $opportunity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->moveOpportunity($opportunity, (int) $request->validate([
            'stage_id' => ['required', 'integer'],
            'lost_reason_id' => ['nullable', 'integer'],
            'lost_notes' => ['nullable', 'string'],
            'won_reason' => ['nullable', 'string'],
        ])['stage_id'], $request->only(['lost_reason_id', 'lost_notes', 'won_reason']));
        return response()->json(['message' => 'Opportunity stage updated.', 'opportunity' => $row]);
    }

    public function opportunityQuotation(int $opportunity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $quotation = $this->crm->convertOpportunityToQuotation($opportunity);
        return response()->json(['message' => 'Quotation created from opportunity.', 'quotation' => $quotation], 201);
    }

    public function showOpportunity(int $opportunity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json(['opportunity' => $this->crm->showOpportunity($opportunity)]);
    }

    public function destroyOpportunity(int $opportunity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $this->crm->destroyOpportunity($opportunity);
        return response()->json(['message' => 'Opportunity deleted successfully.']);
    }

    public function markOpportunityWon(Request $request, int $opportunity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->markOpportunityWon($opportunity, $request->validate([
            'won_reason' => ['nullable', 'string'],
            'final_value' => ['nullable', 'numeric', 'min:0'],
        ]));
        return response()->json(['message' => 'Opportunity marked won.', 'opportunity' => $row]);
    }

    public function markOpportunityLost(Request $request, int $opportunity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->markOpportunityLost($opportunity, $request->validate([
            'lost_reason_id' => ['required', 'integer'],
            'lost_notes' => ['nullable', 'string'],
        ]));
        return response()->json(['message' => 'Opportunity marked lost.', 'opportunity' => $row]);
    }

    public function activities(Request $request)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json($this->page('activities', $this->crm->activities($request->all())));
    }

    public function saveActivity(CrmActivityRequest $request, ?int $activity = null)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->saveActivity($request->validated(), $activity);
        return response()->json(['message' => 'Activity saved successfully.', 'activity' => $row], $activity ? 200 : 201);
    }

    public function showActivity(int $activity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json(['activity' => $this->crm->showActivity($activity)]);
    }

    public function completeActivity(Request $request, int $activity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->completeActivity($activity, $request->validate([
            'outcome' => ['required', 'string'],
            'next_action' => ['nullable', 'string'],
            'next_follow_up_at' => ['nullable', 'date'],
        ]));
        return response()->json(['message' => 'Activity completed.', 'activity' => $row]);
    }

    public function cancelActivity(Request $request, int $activity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $row = $this->crm->cancelActivity($activity, $request->validate(['outcome' => ['nullable', 'string']]));
        return response()->json(['message' => 'Activity cancelled.', 'activity' => $row]);
    }

    public function destroyActivity(int $activity)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        $this->crm->destroyActivity($activity);
        return response()->json(['message' => 'Activity deleted successfully.']);
    }

    public function kanban(Request $request)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json(['stages' => $this->crm->kanban($request->all())]);
    }

    public function calendar(Request $request)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json($this->crm->calendar($request->all()));
    }

    public function reports(Request $request)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json($this->crm->reports($request->all()));
    }

    public function leadReport(Request $request) { abort_unless(AppController::canOpen('crm'), 403); return response()->json(['lead_register' => $this->crm->reports($request->all())['lead_register']]); }
    public function opportunityReport(Request $request) { abort_unless(AppController::canOpen('crm'), 403); return response()->json(['opportunity_register' => $this->crm->reports($request->all())['opportunity_register']]); }
    public function followUpReport(Request $request) { abort_unless(AppController::canOpen('crm'), 403); return response()->json(['follow_up_report' => $this->crm->reports($request->all())['follow_up_report']]); }
    public function salesForecastReport(Request $request) { abort_unless(AppController::canOpen('crm'), 403); return response()->json(['sales_forecast' => $this->crm->reports($request->all())['sales_forecast']]); }
    public function exportLeads(Request $request) { abort_unless(AppController::canOpen('crm'), 403); return $this->csv('crm-leads-' . now()->toDateString() . '.csv', $this->crm->reports($request->all())['lead_register']); }
    public function exportOpportunities(Request $request) { abort_unless(AppController::canOpen('crm'), 403); return $this->csv('crm-opportunities-' . now()->toDateString() . '.csv', $this->crm->reports($request->all())['opportunity_register']); }
    public function exportFollowUps(Request $request) { abort_unless(AppController::canOpen('crm'), 403); return $this->csv('crm-follow-ups-' . now()->toDateString() . '.csv', $this->crm->reports($request->all())['follow_up_report']); }
    public function exportSalesForecast(Request $request) { abort_unless(AppController::canOpen('crm'), 403); return $this->csv('crm-sales-forecast-' . now()->toDateString() . '.csv', $this->crm->reports($request->all())['sales_forecast']); }

    public function saveMaster(Request $request, string $type, ?int $id = null)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json(['message' => 'CRM master saved.', 'record' => $this->crm->saveMaster($type, $request->all(), $id)]);
    }

    public function saveSource(Request $request, ?int $id = null) { return $this->saveMaster($request, 'source', $id); }
    public function saveCampaign(Request $request, ?int $id = null) { return $this->saveMaster($request, 'campaign', $id); }
    public function destroySource(int $id) { abort_unless(AppController::canOpen('crm'), 403); $this->crm->destroyMaster('source', $id); return response()->json(['message' => 'CRM source deactivated.']); }
    public function destroyCampaign(int $id) { abort_unless(AppController::canOpen('crm'), 403); $this->crm->destroyMaster('campaign', $id); return response()->json(['message' => 'CRM campaign deactivated.']); }
    public function pipelines() { abort_unless(AppController::canOpen('crm'), 403); return response()->json(['pipelines' => $this->crm->references()['pipelines']]); }
    public function stages() { abort_unless(AppController::canOpen('crm'), 403); return response()->json(['stages' => $this->crm->references()['stages']]); }

    private function page(string $key, $paginator): array
    {
        return [
            $key => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function section(?string $section): string
    {
        return in_array($section, ['dashboard', 'leads', 'opportunities', 'kanban', 'activities', 'calendar', 'reports', 'masters'], true) ? $section : 'dashboard';
    }

    private function endpoints(): array
    {
        $names = [
            'index' => 'app.crm.index',
            'references' => 'app.crm.references',
            'dashboard' => 'app.crm.dashboard',
            'leads' => 'app.crm.leads.list',
            'leadStore' => 'app.crm.leads.store',
            'leadBulkAssign' => 'app.crm.leads.bulk-assign',
            'opportunities' => 'app.crm.opportunities.list',
            'opportunityStore' => 'app.crm.opportunities.store',
            'activities' => 'app.crm.activities.list',
            'activityStore' => 'app.crm.activities.store',
            'kanban' => 'app.crm.kanban',
            'calendar' => 'app.crm.calendar',
            'reports' => 'app.crm.reports',
            'leadExport' => 'app.crm.exports.leads',
            'opportunityExport' => 'app.crm.exports.opportunities',
            'followUpExport' => 'app.crm.exports.follow-ups',
        ];

        return collect($names)
            ->filter(fn ($name) => Route::has($name))
            ->map(fn ($name) => route($name, [], false))
            ->merge([
                'leadUpdate' => route('app.crm.leads.update', ['lead' => '__ID__'], false),
                'leadAssign' => route('app.crm.leads.assign', ['lead' => '__ID__'], false),
                'leadQualify' => route('app.crm.leads.qualify', ['lead' => '__ID__'], false),
                'leadConvert' => route('app.crm.leads.convert', ['lead' => '__ID__'], false),
                'opportunityUpdate' => route('app.crm.opportunities.update', ['opportunity' => '__ID__'], false),
                'opportunityMove' => route('app.crm.opportunities.move', ['opportunity' => '__ID__'], false),
                'opportunityMarkWon' => route('app.crm.opportunities.mark-won', ['opportunity' => '__ID__'], false),
                'opportunityMarkLost' => route('app.crm.opportunities.mark-lost', ['opportunity' => '__ID__'], false),
                'opportunityQuotation' => route('app.crm.opportunities.quotation', ['opportunity' => '__ID__'], false),
                'activityUpdate' => route('app.crm.activities.update', ['activity' => '__ID__'], false),
                'activityComplete' => route('app.crm.activities.complete', ['activity' => '__ID__'], false),
                'activityCancel' => route('app.crm.activities.cancel', ['activity' => '__ID__'], false),
            ])
            ->all();
    }

    private function csv(string $filename, $rows)
    {
        $rows = collect($rows)->map(fn ($row) => collect($row)->reject(fn ($value) => is_array($value) || is_object($value))->all())->values();
        $headers = array_keys($rows->first() ?: ['empty' => '']);
        $lines = [$headers];
        foreach ($rows as $row) {
            $lines[] = array_map(fn ($header) => (string) ($row[$header] ?? ''), $headers);
        }

        $callback = function () use ($lines) {
            $handle = fopen('php://output', 'w');
            foreach ($lines as $line) {
                fputcsv($handle, $line);
            }
            fclose($handle);
        };

        return response()->streamDownload($callback, $filename, ['Content-Type' => 'text/csv']);
    }
}
