<?php

namespace App\Http\Controllers;

use App\Models\TallyConnection;
use App\Models\TallySyncBatch;
use App\Services\TallyIntegrationService;
use App\Services\TallyPostingImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TallyIntegrationController extends Controller
{
    public function index(TallyIntegrationService $service)
    {
        $businessId = AppController::businessId();
        $company = DB::table('companies')->where('id', $businessId)->first();
        return Inertia::render('Accounting/TallyIntegration', [
            'page' => 'tally-integration', 'title' => 'Tally Integration Center',
            'company' => ['id' => $businessId, 'name' => $company->name ?? 'Current Company', 'gstin' => $company->gstin ?? null],
            'overview' => $service->overview($businessId),
        ]);
    }

    public function storeConnection(Request $request, TallyIntegrationService $service)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'], 'location' => ['required', Rule::in(['local', 'cloud'])],
            'cloud_type' => ['nullable', Rule::in(['api', 'hosted_windows'])], 'provider' => ['nullable', 'string', 'max:80'], 'endpoint_url' => ['nullable', 'url', 'max:500'],
            'branch_id' => ['nullable', 'integer'], 'credentials' => ['nullable', 'array'], 'import_types' => ['nullable', 'array'],
        ]);
        if (($data['location'] ?? null) === 'cloud' && ($data['cloud_type'] ?? null) === 'api') {
            return response()->json(['message' => 'No provider-specific Tally API is enabled. Use Hosted Windows / RDP connector until a provider adapter is configured and tested.'], 422);
        }
        $connection = $service->createConnection($data, AppController::businessId(), $request->user()->id);
        return response()->json(['connection' => $service->connectionPayload($connection), 'pairing_code' => $service->issuePairingCode($connection)], 201);
    }

    public function pair(Request $request, TallyConnection $connection, TallyIntegrationService $service)
    {
        $this->authorizeConnection($connection);
        return response()->json([
            'message' => 'A new pairing code was created. Enter it in the Windows connector within 15 minutes.',
            'pairing_code' => $service->issuePairingCode($connection),
            'connection' => $service->connectionPayload($connection->fresh()),
        ]);
    }

    public function connectorPair(Request $request, TallyIntegrationService $service)
    {
        $data = $request->validate(['pairing_code' => ['required', 'string', 'max:20']]);
        $result = $service->exchangePairingCode(strtoupper(trim($data['pairing_code'])));
        abort_unless($result, 401, 'Pairing code is invalid, expired, already used, or the connection was revoked.');

        return response()->json([
            'connector_token' => $result['connector_token'],
            'connection' => $service->connectionPayload($result['connection']),
        ]);
    }

    public function test(TallyConnection $connection, TallyIntegrationService $service)
    {
        $this->authorizeConnection($connection);
        return response()->json($service->testEndpoint($connection));
    }

    public function revoke(TallyConnection $connection, TallyIntegrationService $service)
    {
        $this->authorizeConnection($connection);
        abort_unless(request()->user()->isAdmin() || request()->user()->isSuperAdmin(), 403);
        $service->revoke($connection);
        return response()->json(['message' => 'Connector revoked.']);
    }

    public function batch(TallySyncBatch $batch, TallyIntegrationService $service)
    {
        abort_unless((int) $batch->business_id === AppController::businessId(), 404);
        return response()->json(['batch' => $service->batchPayload($batch), 'records' => $batch->records()->latest()->limit(500)->get(), 'reconciliation' => $batch->reconciliationItems()->get()]);
    }

    public function mapping(Request $request, TallyIntegrationService $service)
    {
        $data = $request->validate(['source_type' => ['required', 'string'], 'source_key' => ['required', 'string'], 'source_name' => ['nullable', 'string'], 'target_type' => ['required', 'string'], 'target_id' => ['nullable', 'integer']]);
        return response()->json(['mapping' => $service->saveMapping(AppController::businessId(), $data, $request->user()->id)]);
    }

    public function approve(Request $request, TallySyncBatch $batch, TallyIntegrationService $service)
    {
        abort_unless((int) $batch->business_id === AppController::businessId(), 404);
        abort_unless($request->user()->isAdmin() || $request->user()->isSuperAdmin(), 403);
        return response()->json(['batch' => $service->batchPayload($service->approve($batch, $request->user()->id)), 'message' => 'Batch approved. Posting is still a separate explicit step.']);
    }

    public function reconcile(TallySyncBatch $batch, TallyIntegrationService $service)
    {
        abort_unless((int) $batch->business_id === AppController::businessId(), 404);
        return response()->json(['items' => $service->reconcile($batch)]);
    }

    public function import(Request $request, TallySyncBatch $batch, TallyPostingImportService $imports, TallyIntegrationService $service)
    {
        abort_unless((int) $batch->business_id === AppController::businessId(), 404);
        abort_unless($request->user()->isAdmin() || $request->user()->isSuperAdmin(), 403);
        return response()->json(['batch' => $service->batchPayload($imports->import($batch, $request->user()->id)), 'message' => 'Approved records posted through BillIQ services.']);
    }

    public function connectorIngest(Request $request, TallyIntegrationService $service)
    {
        $token = (string) $request->header('X-BillIQ-Connector-Token');
        $connection = $service->authenticateConnector($token);
        abort_unless($connection, 401, 'Connector token is invalid, expired or revoked.');
        if ($request->filled('date_from') && $request->filled('date_to') && strtotime((string) $request->input('date_from')) > strtotime((string) $request->input('date_to'))) return response()->json(['message' => 'The connector date range is invalid: date_from cannot be later than date_to.'], 422);
        $payload = $request->validate(['records' => ['required', 'array'], 'mode' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'company_name' => ['nullable', 'string'], 'company_guid' => ['nullable', 'string']]);
        $requestId = substr((string) $request->header('X-Connector-Request-Id'), 0, 64) ?: null;
        return response()->json(['batch' => $service->batchPayload($service->ingest($connection, $payload, $requestId))]);
    }

    private function authorizeConnection(TallyConnection $connection): void { abort_unless((int) $connection->business_id === AppController::businessId(), 404); }
}
