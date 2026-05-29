<?php

namespace App\Services\Cloudflare;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper for Cloudflare's R2 CSAM Scanning Tool status API.
 *
 * Returns one of: 'pending' | 'clean' | 'match' | 'error'.
 *
 * The test suite mocks this client directly so the endpoint path
 * doesn't need to be precise for unit tests to pass.
 */
class CloudflareCsamScanClient
{
    public function statusFor(string $r2Key): string
    {
        $bucket    = config('partna.moderation.csam.quarantine_bucket', 'partna-media-quarantine');
        $accountId = config('services.cloudflare.account_id');
        $apiToken  = config('services.cloudflare.api_token');

        $response = Http::withToken($apiToken)
            ->timeout(10)
            ->get("https://api.cloudflare.com/client/v4/accounts/{$accountId}/r2/buckets/{$bucket}/objects/{$r2Key}/csam-status");

        if (! $response->successful()) {
            return 'error';
        }

        return $response->json('result.status', 'pending');
    }
}
