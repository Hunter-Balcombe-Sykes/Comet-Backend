<?php

// Moved to the applied-schema lane (tranche 3 of COV-LANE). All three cases
// seeded their targets via User::factory()->create() against tests/Pest.php's
// SQLite stand-in schema, which has no FK from core.users.auth_user_id onto
// auth.users — all three passed under SQLite for that reason (two
// unconditionally, one gated behind a `group('postgres')` skip for the
// dedup UNIQUE index) and all three failed against a real migrated Postgres
// with `users_auth_user_id_fkey`. SeedsAuthUsers::seedAuthUser() unblocks all
// three without touching PublicReportController/ContentReportService; nothing
// here documents a real app defect, so the whole file moves — no green SQLite
// coverage is lost, and the previously-gated dedup case now runs
// unconditionally instead of skipping in CI.
//
// This hits the real HTTP route (`postJson`), same as it did in Feature/ —
// SchemaTestCase extends the framework TestCase directly, which still boots
// the full application (routes, middleware) exactly like Tests\TestCase does.
// `partna.bot_protection.mode = 'off'` (set below, same as before the move)
// means this file never depends on the Feature/-directory-scoped FakeProvider
// binding — unlike BootstrapEmailRaceTest, there is nothing to re-establish
// here.

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Schema\Concerns\SeedsAuthUsers;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class, SeedsAuthUsers::class)->in(__FILE__);

beforeEach(function () {
    Queue::fake();
    Redis::flushdb();
    config(['partna.bot_protection.mode' => 'off']);
});

/**
 * moderation.cases.reportable_owner_user_id is ON DELETE SET NULL (not
 * CASCADE) — cleanupSeededUser() alone would orphan every case a successful
 * report opens instead of removing it. Deletes the cases each user owns first
 * (real-Postgres CASCADEs that into case_signals / evidence), then the users
 * themselves, keeping this persistent, shared lane database clean.
 *
 * @param  list<User>  $users
 */
function cleanupReportAbuseUsers(array $users): void
{
    foreach ($users as $user) {
        ModerationCase::where('reportable_owner_user_id', $user->id)->delete();
    }
    foreach ($users as $user) {
        test()->cleanupSeededUser($user);
    }
}

function antiAbusePayload(string $handle): array
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

    // Create 5 different targets so per-target throttle doesn't fire.
    $users = [];
    for ($i = 0; $i < 5; $i++) {
        $handle = "u{$i}".Str::lower(Str::random(6));
        $u = $this->seedAuthUser(['handle' => $handle]);
        $users[] = $u;
        Site::factory()->create(['user_id' => $u->id]);
        $this->postJson('/api/v1/public/report', antiAbusePayload($handle))->assertStatus(202);
    }

    try {
        $overflowHandle = 'u-overflow'.Str::lower(Str::random(6));
        $overflowUser = $this->seedAuthUser(['handle' => $overflowHandle]);
        $users[] = $overflowUser;
        Site::factory()->create(['user_id' => $overflowUser->id]);
        $this->postJson('/api/v1/public/report', antiAbusePayload($overflowHandle))->assertStatus(429);
    } finally {
        cleanupReportAbuseUsers($users);
    }
});

it('rate-limits at the per-target throttle (3 per hour) from same IP', function () {
    $handle = 'joeplumber'.Str::lower(Str::random(6));
    $user = $this->seedAuthUser(['handle' => $handle]);

    try {
        Site::factory()->create(['user_id' => $user->id]);

        // First 3 succeed (different reporter emails = different dedup_hash)
        for ($i = 0; $i < 3; $i++) {
            $payload = antiAbusePayload($handle);
            $payload['reporter_email'] = "r{$i}@e.com";
            $this->postJson('/api/v1/public/report', $payload)->assertStatus(202);
        }

        // 4th from same IP to same target → per-target throttle fires
        $payload = antiAbusePayload($handle);
        $payload['reporter_email'] = 'r-overflow@e.com';
        $res = $this->postJson('/api/v1/public/report', $payload);
        $res->assertStatus(429);
        $res->assertJsonPath('error', 'TARGET_RATE_LIMITED');
    } finally {
        cleanupReportAbuseUsers([$user]);
    }
});

it('rejects a duplicate (same reporter, same target, same reason) with 409', function () {
    $handle = 'joeplumber'.Str::lower(Str::random(6));
    $user = $this->seedAuthUser(['handle' => $handle]);

    try {
        Site::factory()->create(['user_id' => $user->id]);

        $payload = antiAbusePayload($handle);
        $payload['reporter_email'] = 'reporter@example.com';
        $this->postJson('/api/v1/public/report', $payload)->assertStatus(202);

        $res = $this->postJson('/api/v1/public/report', $payload);
        $res->assertStatus(409);
        $res->assertJsonPath('error', 'DUPLICATE_REPORT');
    } finally {
        cleanupReportAbuseUsers([$user]);
    }
});
