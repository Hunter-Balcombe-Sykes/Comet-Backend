<?php

use App\Services\Auth\AuthFactorEventRepository;
use Illuminate\Support\Str;

beforeEach(function () {
    setupAuthFactorEventsTable();
    config([
        'supabase.auth_hook_secret' => 'whsec_test_secret_at_least_32_bytes_long_xx',
        'partna.mfa.verify_max_failures' => 5,
        'partna.mfa.verify_failure_window_seconds' => 300,
    ]);
});

function postSignedHook(array $payload, ?string $overrideBody = null, ?string $id = null): \Illuminate\Testing\TestResponse
{
    $body = $overrideBody ?? json_encode($payload);
    $id = $id ?? 'msg_'.Str::uuid();
    $ts = (string) time();
    $secret = config('supabase.auth_hook_secret');
    $sig = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $secret, true));

    // Laravel's call() takes $server as the 6th arg — headers must be in HTTP_* format.
    // withHeaders() only feeds the convenience helpers (postJson etc.), not call() directly.
    return test()->call('POST', '/api/webhooks/supabase/auth/mfa-verification', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK-ID' => $id,
        'HTTP_WEBHOOK-TIMESTAMP' => $ts,
        'HTTP_WEBHOOK-SIGNATURE' => $sig,
    ], $body);
}

it('returns 401 for an unsigned request', function () {
    test()
        ->postJson('/api/webhooks/supabase/auth/mfa-verification', ['user_id' => 'abc', 'valid' => true])
        ->assertStatus(401);
});

it('returns continue and records verify_success for a valid signed success', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    postSignedHook([
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => true,
    ])->assertOk()->assertJson(['decision' => 'continue']);

    $event = \DB::connection('pgsql')->table('core.auth_factor_events')
        ->where('user_id', $userId)->first();
    expect($event->event_type)->toBe('verify_success');
});

it('returns continue and records verify_failed for the first few failures', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    foreach (range(1, 4) as $i) {
        $response = postSignedHook([
            'user_id' => $userId,
            'factor_id' => $factorId,
            'factor_type' => 'totp',
            'valid' => false,
        ]);
        $response->assertOk()->assertJson(['decision' => 'continue']);
    }

    $count = \DB::connection('pgsql')->table('core.auth_factor_events')
        ->where('user_id', $userId)->where('event_type', 'verify_failed')->count();
    expect($count)->toBe(4);
});

it('WEBHOOK-3: a redelivered webhook-id is deduplicated — event recorded once', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();
    $webhookId = 'msg_'.Str::uuid();

    $payload = [
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => false,
    ];

    // First delivery: processed and recorded.
    postSignedHook($payload, null, $webhookId)
        ->assertOk()->assertJson(['decision' => 'continue']);

    // Retry with the SAME webhook-id: deduplicated, returns continue, no re-record.
    postSignedHook($payload, null, $webhookId)
        ->assertOk()->assertJson(['decision' => 'continue']);

    $count = \DB::connection('pgsql')->table('core.auth_factor_events')
        ->where('user_id', $userId)->where('event_type', 'verify_failed')->count();
    expect($count)->toBe(1);
});

it('returns reject on the 6th failed attempt in the window', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();
    $repo = app(AuthFactorEventRepository::class);

    // Seed 5 prior failures
    foreach (range(1, 5) as $_) {
        $repo->record($userId, 'verify_failed', $factorId, 'totp');
    }

    postSignedHook([
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => false,
    ])
        ->assertOk()
        ->assertJson(['decision' => 'reject'])
        ->assertJsonStructure(['decision', 'message']);

    // The rejection itself is recorded as verify_rejected_by_hook
    $rejection = \DB::connection('pgsql')->table('core.auth_factor_events')
        ->where('user_id', $userId)
        ->where('event_type', 'verify_rejected_by_hook')
        ->first();
    expect($rejection)->not->toBeNull();
});
