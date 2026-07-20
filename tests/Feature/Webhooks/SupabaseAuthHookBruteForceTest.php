<?php

use App\Http\Controllers\Api\Webhooks\SupabaseAuthHookController;
use App\Services\Auth\AuthFactorEventRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    setupAuthFactorEventsTable();
    config([
        'services.supabase.auth_hook_secret' => 'whsec_test_secret_at_least_32_bytes_long_xx',
        'partna.mfa.verify_max_failures' => 5,
        'partna.mfa.verify_failure_window_seconds' => 300,
    ]);
});

function postSignedHook(array $payload, ?string $overrideBody = null, ?string $id = null): TestResponse
{
    $body = $overrideBody ?? json_encode($payload);
    $id = $id ?? 'msg_'.Str::uuid();
    $ts = (string) time();
    $secret = config('services.supabase.auth_hook_secret');
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
        ->assertStatus(401)
        ->assertJson(['error' => 'invalid_signature']);
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

    $event = DB::connection('pgsql')->table('audit.auth_factor_events')
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

    $count = DB::connection('pgsql')->table('audit.auth_factor_events')
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

    $count = DB::connection('pgsql')->table('audit.auth_factor_events')
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
    $rejection = DB::connection('pgsql')->table('audit.auth_factor_events')
        ->where('user_id', $userId)
        ->where('event_type', 'verify_rejected_by_hook')
        ->first();
    expect($rejection)->not->toBeNull();
});

it('WHK-1: reverts the dedup anchor and returns 500 when record() throws', function () {
    $webhookId = 'msg_'.Str::uuid();

    // Simulate a DB outage: every record() write throws.
    $this->mock(AuthFactorEventRepository::class, function ($mock) {
        $mock->shouldReceive('record')->andThrow(new RuntimeException('db outage'));
        $mock->shouldReceive('countRecentFailures')->andReturn(0);
    });

    postSignedHook([
        'user_id' => (string) Str::uuid(),
        'factor_id' => (string) Str::uuid(),
        'factor_type' => 'totp',
        'valid' => true,
    ], null, $webhookId)->assertStatus(500);

    // The anchor must be gone so Supabase's retry re-evaluates rather than
    // short-circuiting to {decision: continue}.
    expect(Cache::has("supabase:auth-hook:{$webhookId}"))->toBeFalse();
});

it('WHK-1: a record() failure on the reject path does not degrade to allow on retry', function () {
    $webhookId = 'msg_'.Str::uuid();
    $calls = 0;

    // User is already at the lockout threshold. record() throws on the first
    // delivery (DB outage), then succeeds on Supabase's retry.
    $this->mock(AuthFactorEventRepository::class, function ($mock) use (&$calls) {
        $mock->shouldReceive('countRecentFailures')->andReturn(5);
        $mock->shouldReceive('record')->andReturnUsing(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('db outage');
            }

            return (string) Str::uuid();
        });
    });

    $payload = [
        'user_id' => (string) Str::uuid(),
        'factor_id' => (string) Str::uuid(),
        'factor_type' => 'totp',
        'valid' => false,
    ];

    // First delivery: the rejection write fails → 500, anchor reverted.
    postSignedHook($payload, null, $webhookId)->assertStatus(500);
    expect(Cache::has("supabase:auth-hook:{$webhookId}"))->toBeFalse();

    // Retry with the SAME webhook-id must still REJECT — never flip to continue.
    postSignedHook($payload, null, $webhookId)
        ->assertOk()
        ->assertJson(['decision' => 'reject']);
});

it('WHK-101: a redelivered webhook-id is deduplicated at the DB layer even after the Redis anchor is lost', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();
    $webhookId = 'msg_'.Str::uuid();

    $payload = [
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => false,
    ];

    postSignedHook($payload, null, $webhookId)
        ->assertOk()->assertJson(['decision' => 'continue']);

    $count = DB::connection('pgsql')->table('audit.auth_factor_events')
        ->where('user_id', $userId)->where('event_type', 'verify_failed')->count();
    expect($count)->toBe(1);

    // Simulate a Redis wipe — the WEBHOOK-3 Cache::add anchor is gone, so
    // without the DB backstop this redelivery would double-record.
    Cache::flush();

    postSignedHook($payload, null, $webhookId)
        ->assertOk()->assertJson(['decision' => 'continue']);

    $count = DB::connection('pgsql')->table('audit.auth_factor_events')
        ->where('user_id', $userId)->where('event_type', 'verify_failed')->count();
    expect($count)->toBe(1);
});

it('WHK-101: the DB backstop does not suppress a genuinely different delivery', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    $payload = [
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => false,
    ];

    postSignedHook($payload, null, 'msg_'.Str::uuid())
        ->assertOk()->assertJson(['decision' => 'continue']);

    // Simulate a Redis wipe between two genuinely distinct deliveries — the
    // partial unique index must not accidentally collapse them (that would
    // silently disarm the brute-force counter).
    Cache::flush();

    postSignedHook($payload, null, 'msg_'.Str::uuid())
        ->assertOk()->assertJson(['decision' => 'continue']);

    $count = DB::connection('pgsql')->table('audit.auth_factor_events')
        ->where('user_id', $userId)->where('event_type', 'verify_failed')->count();
    expect($count)->toBe(2);
});

it('WHK-2: fails closed (400) when the webhook-id header is absent', function () {
    // Exercises the controller guard directly — the signature middleware would
    // normally 401 an empty webhook-id before the controller, so this proves the
    // controller is independently hardened (defense-in-depth).
    $controller = app(SupabaseAuthHookController::class);
    $request = Request::create('/api/webhooks/supabase/auth/mfa-verification', 'POST');

    $response = $controller->mfaVerification($request);

    expect($response->getStatusCode())->toBe(400);
});

it('WHK-1: a stored reject replays as a reject on redelivery — not the hardcoded continue', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();
    $webhookId = 'msg_'.Str::uuid();
    $repo = app(AuthFactorEventRepository::class);

    // Seed 5 prior failures so the very first delivery already trips the
    // lockout threshold (mirrors the existing "returns reject on the 6th
    // failed attempt" test above).
    foreach (range(1, 5) as $_) {
        $repo->record($userId, 'verify_failed', $factorId, 'totp');
    }

    $payload = [
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => false,
    ];

    // First delivery: computed fresh, rejected and recorded.
    postSignedHook($payload, null, $webhookId)
        ->assertOk()->assertJson(['decision' => 'reject']);

    // Redelivery with the SAME webhook-id hits the dedup anchor. The
    // pre-fix controller hardcoded {"decision":"continue"} here — this must
    // instead replay the reject that actually happened.
    postSignedHook($payload, null, $webhookId)
        ->assertOk()
        ->assertJson(['decision' => 'reject'])
        ->assertJsonStructure(['decision', 'message']);

    $count = DB::connection('pgsql')->table('audit.auth_factor_events')
        ->where('user_id', $userId)->where('event_type', 'verify_rejected_by_hook')->count();
    expect($count)->toBe(1);
});

it('WHK-1: a reject still resolves correctly on redelivery after a full cache flush', function () {
    // This is the scenario the audit's proposed fix (a second Cache::put
    // beside the dedup anchor) would fail: that second key lives on the same
    // default cache store as the anchor, so a Cache::flush() — a raw FLUSHDB —
    // wipes it too, and the audit's "default to continue when none is found"
    // fallback would fire on the very path this fix exists to close. Our fix
    // has no second cache key at all — the decision is recoverable from
    // Postgres — so it is immune to this class of cache loss by construction.
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();
    $webhookId = 'msg_'.Str::uuid();
    $repo = app(AuthFactorEventRepository::class);

    foreach (range(1, 5) as $_) {
        $repo->record($userId, 'verify_failed', $factorId, 'totp');
    }

    $payload = [
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => false,
    ];

    postSignedHook($payload, null, $webhookId)
        ->assertOk()->assertJson(['decision' => 'reject']);

    Cache::flush();

    postSignedHook($payload, null, $webhookId)
        ->assertOk()->assertJson(['decision' => 'reject']);

    // No double-count either way — WHK-101's webhook_id unique index backstops
    // this even when the flush also drops the Redis dedup anchor, forcing a
    // full recompute rather than a cache-served replay.
    $count = DB::connection('pgsql')->table('audit.auth_factor_events')
        ->where('user_id', $userId)->where('event_type', 'verify_rejected_by_hook')->count();
    expect($count)->toBe(1);
});

it('WHK-1: fails closed (not continue) when the cache store is unavailable', function () {
    // Do NOT wrap Cache::add in a try/catch to "fail open" — an uncaught
    // exception here is already correct (500, verification fails). This
    // guards against a future regression that would soften it.
    Cache::partialMock();
    Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('redis unreachable'));

    $response = postSignedHook([
        'user_id' => (string) Str::uuid(),
        'factor_id' => (string) Str::uuid(),
        'factor_type' => 'totp',
        'valid' => true,
    ]);

    $response->assertStatus(500);
    expect($response->json('decision'))->not->toBe('continue');
});

it('WHK-1: a dedup hit with no durable row recomputes instead of replaying continue', function () {
    $webhookId = 'msg_'.Str::uuid();

    // Pre-seed the anchor directly so the controller sees this as a dedup
    // hit on the very first request we send it.
    Cache::add("supabase:auth-hook:{$webhookId}", true, 60);

    // The repository has no row for this webhook-id (e.g. it was never
    // durably recorded, or the lookup itself is flaky) — replayDecision()
    // must return null and the controller must recompute, NOT assume continue.
    $this->mock(AuthFactorEventRepository::class, function ($mock) {
        $mock->shouldReceive('findByWebhookId')->once()->andReturn(null);
        $mock->shouldReceive('countRecentFailures')->andReturn(5);
        $mock->shouldReceive('record')->andReturn((string) Str::uuid());
    });

    postSignedHook([
        'user_id' => (string) Str::uuid(),
        'factor_id' => (string) Str::uuid(),
        'factor_type' => 'totp',
        'valid' => false,
    ], null, $webhookId)
        ->assertOk()
        ->assertJson(['decision' => 'reject']);
});

it('WHK-1: a stored continue (verify_success) replays as continue — does not over-reject', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();
    $webhookId = 'msg_'.Str::uuid();

    $payload = [
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => true,
    ];

    postSignedHook($payload, null, $webhookId)
        ->assertOk()->assertJson(['decision' => 'continue']);

    // Redelivery must replay 'continue', not flip to reject — over-rejecting
    // a legitimately-verified user is its own failure mode.
    postSignedHook($payload, null, $webhookId)
        ->assertOk()->assertJson(['decision' => 'continue']);

    $count = DB::connection('pgsql')->table('audit.auth_factor_events')
        ->where('user_id', $userId)->where('event_type', 'verify_success')->count();
    expect($count)->toBe(1);
});
