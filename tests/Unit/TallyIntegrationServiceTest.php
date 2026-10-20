<?php

namespace Tests\Unit;

use App\Models\TallyConnection;
use App\Models\TallySyncBatch;
use App\Services\TallyIntegrationService;
use Tests\TestCase;

class TallyIntegrationServiceTest extends TestCase
{
    public function test_connection_payload_does_not_expose_credentials_or_pairing_token(): void
    {
        $connection = new TallyConnection([
            'id' => 4, 'name' => 'Local Tally', 'location' => 'local', 'status' => 'connected',
            'credentials' => ['secret' => 'never-return-this'], 'pairing_token_hash' => hash('sha256', 'secret'),
        ]);

        $payload = (new TallyIntegrationService())->connectionPayload($connection);

        $this->assertArrayNotHasKey('credentials', $payload);
        $this->assertArrayNotHasKey('pairing_token_hash', $payload);
        $this->assertSame('connected', $payload['status']);
    }

    public function test_batch_payload_is_safe_for_review_screen(): void
    {
        $batch = new TallySyncBatch(['id' => 7, 'tally_connection_id' => 4, 'status' => 'ready_for_review', 'mode' => 'incremental', 'records_received' => 3, 'summary_json' => ['ledger' => 3]]);
        $payload = (new TallyIntegrationService())->batchPayload($batch);

        $this->assertSame(7, $payload['id']);
        $this->assertSame('ready_for_review', $payload['status']);
        $this->assertSame(['ledger' => 3], $payload['summary']);
    }
}
