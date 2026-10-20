<?php

namespace Tests\Feature;

use App\Models\TallyConnection;
use App\Services\TallyIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TallyIntegrationQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost');
    }

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
            'connector_token_hash' => hash('sha256', $token),
            'connector_token_issued_at' => now(),
        ]);

        $this->withHeader('X-BillIQ-Connector-Token', $token)->postJson(route('tally.connector.ingest'), [
            'date_from' => '2026-04-02',
            'date_to' => '2026-04-01',
            'records' => [],
        ])->assertStatus(422)->assertJsonPath('message', 'The connector date range is invalid: date_from cannot be later than date_to.');

        $this->assertDatabaseCount('tally_sync_batches', 0);
    }

    public function test_connector_rejects_invalid_token(): void
    {
        $this->withHeader('X-BillIQ-Connector-Token', 'not-valid')->postJson(route('tally.connector.ingest'), ['records' => []])->assertUnauthorized();
    }

    public function test_pairing_code_is_exchanged_once_for_a_separate_connector_token(): void
    {
        $connection = TallyConnection::create([
            'business_id' => $this->business(), 'name' => 'Pairing QA', 'location' => 'local', 'status' => 'pending',
        ]);
        $code = app(TallyIntegrationService::class)->issuePairingCode($connection);

        $response = $this->postJson(route('tally.connector.pair'), ['pairing_code' => $code])
            ->assertOk()
            ->assertJsonPath('connection.id', $connection->id);
        $token = $response->json('connector_token');

        $this->assertIsString($token);
        $this->assertNotSame($code, $token);
        $this->assertDatabaseHas('tally_connections', [
            'id' => $connection->id, 'status' => 'connected', 'connector_token_hash' => hash('sha256', $token),
            'pairing_token_hash' => null, 'pairing_expires_at' => null,
        ]);
        $this->postJson(route('tally.connector.pair'), ['pairing_code' => $code])->assertUnauthorized();
        $this->withHeader('X-BillIQ-Connector-Token', $token)
            ->postJson(route('tally.connector.ingest'), ['records' => [['type' => 'ledger', 'key' => 'pair-auth-check', 'payload' => ['xml' => null]]]])
            ->assertOk();
    }

    public function test_expired_pairing_code_and_old_pairing_code_as_token_are_rejected(): void
    {
        $code = 'EXPIRED-CODE';
        TallyConnection::create([
            'business_id' => $this->business(), 'name' => 'Expired pairing', 'location' => 'local', 'status' => 'pairing',
            'pairing_token_hash' => hash('sha256', $code), 'pairing_expires_at' => now()->subSecond(),
        ]);

        $this->postJson(route('tally.connector.pair'), ['pairing_code' => $code])->assertUnauthorized();
        $this->withHeader('X-BillIQ-Connector-Token', $code)
            ->postJson(route('tally.connector.ingest'), ['records' => []])
            ->assertUnauthorized();
    }

    public function test_connector_token_only_ingests_into_its_own_business(): void
    {
        $firstBusiness = $this->business();
        $secondBusiness = $this->business('Other Tally QA Company');
        $connection = TallyConnection::create([
            'business_id' => $firstBusiness, 'name' => 'Isolated connector', 'location' => 'local', 'status' => 'connected',
            'connector_token_hash' => hash('sha256', 'isolated-token'), 'connector_token_issued_at' => now(),
        ]);

        $this->withHeader('X-BillIQ-Connector-Token', 'isolated-token')
            ->postJson(route('tally.connector.ingest'), ['records' => [['type' => 'ledger', 'key' => 'isolated-auth-check', 'payload' => ['xml' => null]]], 'company_name' => 'First company'])
            ->assertOk();

        $this->assertDatabaseHas('tally_sync_batches', ['business_id' => $firstBusiness, 'tally_connection_id' => $connection->id]);
        $this->assertDatabaseMissing('tally_sync_batches', ['business_id' => $secondBusiness]);
    }

    private function business(string $name = 'Tally QA Company'): int
    {
        return DB::table('companies')->insertGetId([
            'name' => $name,
            'state' => 'UP',
            'financial_year' => '2026-27',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
