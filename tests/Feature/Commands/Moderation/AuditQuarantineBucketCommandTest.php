<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['partna.moderation.csam.quarantine_bucket' => 'partna-media-quarantine']);
    config(['services.cloudflare.account_id' => 'acct-123']);
    config(['services.cloudflare.api_token'  => 'token']);
});

it('passes when bucket has no public access policy', function () {
    Http::fake([
        '*' => Http::response([
            'result' => [
                'public_access' => false,
                'cors_origins'  => [],
            ],
        ], 200),
    ]);

    $this->artisan('moderation:audit-quarantine-bucket')->assertSuccessful();
});

it('emits critical alert when bucket has any public access', function () {
    Log::spy();
    Http::fake([
        '*' => Http::response([
            'result' => [
                'public_access' => true,
                'cors_origins'  => [],
            ],
        ], 200),
    ]);

    $this->artisan('moderation:audit-quarantine-bucket')->assertFailed();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'quarantine_bucket.public_drift'))
        ->once();
});
