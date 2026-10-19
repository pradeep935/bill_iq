<?php

namespace App\Services;

use App\Models\TallyConnection;
use Illuminate\Support\Facades\Http;

/**
 * Provider-neutral contract for authenticated hosted APIs.
 * A provider must publish a compatible API contract before it is enabled.
 */
class TallyCloudApiAdapter
{
    public function test(TallyConnection $connection): array
    {
        if ($connection->location !== 'cloud' || $connection->cloud_type !== 'api') return ['ok' => false, 'message' => 'This connection is not configured as a cloud API.'];
        if (!$connection->provider) return ['ok' => false, 'message' => 'No cloud provider is selected. BillIQ does not assume provider API compatibility.'];
        if (!$connection->endpoint_url) return ['ok' => false, 'message' => 'The provider HTTPS API endpoint is missing.'];
        $token = $connection->credentials['access_token'] ?? null;
        if (!$token) return ['ok' => false, 'message' => 'Provider access credentials are missing.'];
        try {
            $response = Http::timeout(12)->withToken($token)->acceptJson()->get(rtrim($connection->endpoint_url, '/') . '/companies');
            return ['ok' => $response->successful(), 'message' => $response->successful() ? "Provider '{$connection->provider}' API responded." : 'Provider API returned HTTP ' . $response->status()];
        } catch (\Throwable $e) { return ['ok' => false, 'message' => 'Provider API is unreachable.']; }
    }
}
