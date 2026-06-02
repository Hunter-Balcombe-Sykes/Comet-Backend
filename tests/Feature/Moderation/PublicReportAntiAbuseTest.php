<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Queue::fake();
    Redis::flushdb();
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupModerationCasesTable();
    setupModerationCaseSignalsTable();
    setupModerationEvidenceTable();
    setupPartnaStaffTable();
    config(['partna.bot_protection.mode' => 'off']);
});

function antiAbusePayload(string $handle = 'joeplumber'): array
{
    return [
        'target_type' => 'Site',
        'target_handle' => $handle,
        'reason_code' => 'spam',
        'turnstile_token' => 'cf-fixture',
    ];
}

it('rate-limits at the framework IP throttle (5 per minute)', function () {
    // Force-enable the framework throttle regardless of SIDEST_THROTTLE_ENABLED env setting.
    // AppServiceProvider captures the env flag at boot, so we re-register the limiter here
    // to ensure throttle logic is exercised in test environments where throttle is disabled.
    RateLimiter::for('partna.moderation.report', function (Request $request) {
        $cfg = config('partna.moderation.reporting.public_throttle', ['requests' => 5, 'minutes' => 1]);

        return Limit::perMinutes($cfg['minutes'], $cfg['requests'])
            ->by($request->ip())
            ->response(fn () => response()->json([
                'error' => 'RATE_LIMITED',
                'message' => 'Hold on a sec, try again in a minute.',
            ], 429));
    });

    // Create 5 different targets so per-target throttle doesn't fire
    for ($i = 0; $i < 5; $i++) {
        $u = User::factory()->create(['handle' => "u{$i}", 'handle_lc' => "u{$i}"]);
        Site::factory()->for($u, 'user')->create();
        $this->postJson('/api/v1/public/report', antiAbusePayload("u{$i}"))->assertStatus(202);
    }

    $u = User::factory()->create(['handle' => 'u-overflow', 'handle_lc' => 'u-overflow']);
    Site::factory()->for($u, 'user')->create();
    $this->postJson('/api/v1/public/report', antiAbusePayload('u-overflow'))->assertStatus(429);
});

it('rate-limits at the per-target throttle (3 per hour) from same IP', function () {
    $user = User::factory()->create(['handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    // First 3 succeed (different reporter emails = different dedup_hash)
    for ($i = 0; $i < 3; $i++) {
        $payload = antiAbusePayload();
        $payload['reporter_email'] = "r{$i}@e.com";
        $this->postJson('/api/v1/public/report', $payload)->assertStatus(202);
    }

    // 4th from same IP to same target → per-target throttle fires
    $payload = antiAbusePayload();
    $payload['reporter_email'] = 'r-overflow@e.com';
    $res = $this->postJson('/api/v1/public/report', $payload);
    $res->assertStatus(429);
    $res->assertJsonPath('error', 'TARGET_RATE_LIMITED');
});

it('rejects a duplicate (same reporter, same target, same reason) with 409', function () {
    if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Dedup unique constraint requires PostgreSQL.');
    }

    $user = User::factory()->create(['handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    Site::factory()->for($user, 'user')->create();

    $payload = antiAbusePayload();
    $payload['reporter_email'] = 'reporter@example.com';
    $this->postJson('/api/v1/public/report', $payload)->assertStatus(202);

    $res = $this->postJson('/api/v1/public/report', $payload);
    $res->assertStatus(409);
    $res->assertJsonPath('error', 'DUPLICATE_REPORT');
})->group('postgres');
