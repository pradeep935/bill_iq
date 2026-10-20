<?php

namespace Tests\Feature;

use App\Models\TallyConnection;
use App\Services\TallyIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TallyIntegrationQaTest extends TestCase
{
    use RefreshDatabase;

    public function test_connector_request_id_makes_retried_batch_idempotent(): void
    {
        $businessId = $this->business();
        $connection = TallyConnection::create([
            'business_id' => $businessId,
            'name' => 'QA Tally',
            'location' => 'local',
            'status' => 'connected',
        ]);
        $payload = ['mode' => 'incremental', 'company_name' => 'QA Company', 'records' => [['type' => 'ledger', 'key' => 'ledger-qa-1', 'name' => 'Cash', 'payload' => ['xml' => null]]]];
        $service = app(TallyIntegrationService::class);

        $first = $service->ingest($connection, $payload, 'qa-request-1');
        $second = $service->ingest($connection, $payload, 'qa-request-1');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('tally_sync_batches', 1);
        $this->assertDatabaseCount('tally_source_records', 1);
    }

    public function test_connector_rejects_reversed_date_range_before_ingestion(): void
    {
        $businessId = $this->business();
        $token = 'qa-token';
        TallyConnection::create([
            'business_id' => $businessId,
            'name' => 'QA Tally',
            'location' => 'local',
            'status' => 'connected',
            'pairing_token_hash' => hash('sha256', $token),
        ]);

        $this->withHeader('X-BillIQ-Connector-Token', $token)->postJson('/api/tally/connector/ingest', [
            'date_from' => '2026-04-02',
            'date_to' => '2026-04-01',
            'records' => [],
        ])->assertStatus(422)->assertJsonPath('message', 'The connector date range is invalid: date_from cannot be later than date_to.');

        $this->assertDatabaseCount('tally_sync_batches', 0);
    }

    public function test_connector_rejects_invalid_token(): void
    {
        $this->withHeader('X-BillIQ-Connector-Token', 'not-valid')->postJson('/api/tally/connector/ingest', ['records' => []])->assertUnauthorized();
    }

    private function business(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'Tally QA Company',
            'state' => 'UP',
            'financial_year' => '2026-27',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
