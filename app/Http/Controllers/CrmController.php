<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrmActivityRequest;
use App\Http\Requests\CrmLeadRequest;
use App\Http\Requests\CrmOpportunityRequest;
use App\Services\CrmService;
use Illuminate\Http\Request;
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

    public function saveMaster(Request $request, string $type, ?int $id = null)
    {
        abort_unless(AppController::canOpen('crm'), 403);
        return response()->json(['message' => 'CRM master saved.', 'record' => $this->crm->saveMaster($type, $request->all(), $id)]);
    }

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
}
