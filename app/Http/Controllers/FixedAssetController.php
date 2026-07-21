<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssetAcquisitionRequest;
use App\Http\Requests\AssetCategoryRequest;
use App\Http\Requests\AssetLocationRequest;
use App\Http\Requests\DepreciationRunRequest;
use App\Http\Requests\FixedAssetRequest;
use App\Services\FixedAssetService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FixedAssetController extends Controller
{
    private FixedAssetService $assets;

    public function __construct(FixedAssetService $assets)
    {
        $this->assets = $assets;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('fixed-assets')) return $redirect;
        return Inertia::render('FixedAssets/Index', ['page' => 'fixed-assets', 'title' => 'Fixed Assets', 'role_id' => AppController::roleId()]);
    }

    public function references() { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json($this->assets->references()); }
    public function dashboard(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json($this->assets->dashboard($request->all())); }
    public function reports() { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json($this->assets->reports()); }
    public function settings(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Fixed asset settings saved.', 'settings' => $this->assets->saveSettings($request->all())]); }

    public function categories(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json($this->page('categories', $this->assets->categories($request->all()))); }
    public function saveCategory(AssetCategoryRequest $request, ?int $category = null) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset category saved.', 'category' => $this->assets->saveCategory($request->validated(), $category)], $category ? 200 : 201); }

    public function locations(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json($this->page('locations', $this->assets->locations($request->all()))); }
    public function saveLocation(AssetLocationRequest $request, ?int $location = null) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset location saved.', 'location' => $this->assets->saveLocation($request->validated(), $location)], $location ? 200 : 201); }

    public function list(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json($this->page('assets', $this->assets->assets($request->all()))); }
    public function save(FixedAssetRequest $request, ?int $asset = null) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset saved.', 'asset' => $this->assets->saveAsset($request->validated(), $asset)], $asset ? 200 : 201); }

    public function acquisitions(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json($this->page('acquisitions', $this->assets->acquisitions($request->all()))); }
    public function saveAcquisition(AssetAcquisitionRequest $request, ?int $acquisition = null) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset acquisition saved.', 'acquisition' => $this->assets->saveAcquisition($request->validated(), $acquisition)], $acquisition ? 200 : 201); }
    public function postAcquisition(int $acquisition) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset acquisition posted.', 'acquisition' => $this->assets->postAcquisition($acquisition)]); }

    public function capitalize(Request $request) {
        abort_unless(AppController::canOpen('fixed-assets'), 403);
        return response()->json(['message' => 'Asset capitalized.', 'capitalization' => $this->assets->capitalize($request->validate([
            'branch_id' => ['required', 'integer'], 'capitalization_date' => ['required', 'date'], 'source_type' => ['required', 'string'], 'source_id' => ['required', 'integer'], 'fixed_asset_id' => ['nullable', 'integer'],
            'asset_category_id' => ['required', 'integer'], 'capitalized_amount' => ['required', 'numeric', 'min:0'], 'put_to_use_date' => ['nullable', 'date'], 'asset_location_id' => ['nullable', 'integer'], 'assigned_employee_id' => ['nullable', 'integer'], 'asset_name' => ['nullable', 'string'], 'asset_tag' => ['nullable', 'string'], 'status' => ['required', 'in:draft,submitted,approved,posted,cancelled,reversed'], 'narration' => ['nullable', 'string'],
        ]))], 201);
    }

    public function depreciationRuns(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json($this->page('runs', $this->assets->depreciationRuns($request->all()))); }
    public function depreciationRun(DepreciationRunRequest $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Depreciation run calculated.', 'run' => $this->assets->createDepreciationRun($request->validated())], 201); }
    public function postDepreciation(int $run) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Depreciation posted.', 'run' => $this->assets->postDepreciation($run)]); }

    public function assign(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset assigned.', 'assignment' => $this->assets->assignAsset($request->validate(['fixed_asset_id' => ['required', 'integer'], 'assigned_to_employee_id' => ['nullable', 'integer'], 'assigned_to_department_id' => ['nullable', 'integer'], 'assigned_location_id' => ['nullable', 'integer'], 'assignment_date' => ['required', 'date'], 'expected_return_date' => ['nullable', 'date'], 'condition_at_issue' => ['nullable', 'string'], 'issue_notes' => ['nullable', 'string']]))], 201); }
    public function returnAssignment(Request $request, int $assignment) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset returned.', 'assignment' => $this->assets->returnAssignment($assignment, $request->all())]); }

    public function transfer(Request $request, ?int $transfer = null) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset transfer saved.', 'transfer' => $this->assets->saveTransfer($request->validate(['transfer_date' => ['required', 'date'], 'source_branch_id' => ['required', 'integer'], 'destination_branch_id' => ['required', 'integer'], 'source_location_id' => ['nullable', 'integer'], 'destination_location_id' => ['nullable', 'integer'], 'transfer_type' => ['required', 'string'], 'status' => ['required', 'in:draft,submitted,approved,dispatched,received,cancelled,reversed'], 'remarks' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.fixed_asset_id' => ['required', 'integer'], 'items.*.source_employee_id' => ['nullable', 'integer'], 'items.*.destination_employee_id' => ['nullable', 'integer'], 'items.*.condition_before' => ['nullable', 'string'], 'items.*.condition_after' => ['nullable', 'string']]), $transfer)], $transfer ? 200 : 201); }

    public function maintenance(Request $request, ?int $maintenance = null) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Maintenance request saved.', 'maintenance' => $this->assets->saveMaintenance($request->validate(['fixed_asset_id' => ['required', 'integer'], 'request_date' => ['required', 'date'], 'maintenance_type' => ['required', 'string'], 'issue_description' => ['required', 'string'], 'priority' => ['required', 'string'], 'assigned_vendor_id' => ['nullable', 'integer'], 'assigned_employee_id' => ['nullable', 'integer'], 'expected_start_date' => ['nullable', 'date'], 'expected_completion_date' => ['nullable', 'date'], 'estimated_cost' => ['nullable', 'numeric', 'min:0'], 'actual_cost' => ['nullable', 'numeric', 'min:0'], 'downtime_hours' => ['nullable', 'numeric', 'min:0'], 'status' => ['required', 'string'], 'resolution_notes' => ['nullable', 'string']]), $maintenance)], $maintenance ? 200 : 201); }
    public function simple(Request $request, string $type) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset record saved.', 'record' => $this->assets->simpleCreate($type, $request->all())], 201); }

    public function revalue(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset revaluation saved.', 'revaluation' => $this->assets->revalue($request->all())], 201); }
    public function impair(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset impairment saved.', 'impairment' => $this->assets->impair($request->all())], 201); }
    public function dispose(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset disposal saved.', 'disposal' => $this->assets->dispose($request->all())], 201); }
    public function verification(Request $request) { abort_unless(AppController::canOpen('fixed-assets'), 403); return response()->json(['message' => 'Asset verification session saved.', 'session' => $this->assets->verification($request->all())], 201); }

    private function page(string $key, $paginator): array
    {
        return [$key => $paginator->items(), 'pagination' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()]];
    }
}
