<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    Cache::flush();

    config([
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-service-key',
        'partna.waitlist.enabled' => false,
    ]);
});

it('returns available when neither Laravel nor Supabase knows the email', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response(['users' => []], 200),
    ]);

    $response = $this->postJson('/api/public/signup/availability', [
        'email' => 'fresh@example.com',
    ]);

    $response->assertOk()->assertJson([
        'email' => ['available' => true, 'exists' => false],
    ]);
});

it('returns NOT available when Supabase has a CONFIRMED user with no Laravel mirror', function () {
    // This is the bug we are fixing: a prior signup got to OTP-verify but
    // never reached bootstrap. The Laravel users table is empty for this
    // address but Supabase has a confirmed record. signUp() would silently
    // do nothing — surface "exists" so the frontend points the visitor at
    // sign-in instead.
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response([
            'users' => [
                [
                    'id' => 'supabase-uuid-confirmed',
                    'email' => 'orphan@example.com',
                    'email_confirmed_at' => '2026-05-23T08:06:26Z',
                ],
            ],
        ], 200),
    ]);

    $response = $this->postJson('/api/public/signup/availability', [
        'email' => 'orphan@example.com',
    ]);

    $response->assertOk()->assertJson([
        'email' => ['available' => false, 'exists' => true],
    ]);
});

it('returns AVAILABLE when Supabase has an UNCONFIRMED user (resumable signup)', function () {
    // Unconfirmed Supabase users can still complete signup — supabase.auth.signUp()
    // resends the confirmation email for them. We must NOT block availability here.
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response([
            'users' => [
                [
                    'id' => 'supabase-uuid-pending',
                    'email' => 'pending@example.com',
                    'email_confirmed_at' => null,
                ],
            ],
        ], 200),
    ]);

    $response = $this->postJson('/api/public/signup/availability', [
        'email' => 'pending@example.com',
    ]);

    $response->assertOk()->assertJson([
        'email' => ['available' => true, 'exists' => false],
    ]);
});

it('fails open and stays available when the Supabase admin API errors', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response(['msg' => 'boom'], 500),
    ]);

    Log::spy();

    $response = $this->postJson('/api/public/signup/availability', [
        'email' => 'transient@example.com',
    ]);

    $response->assertOk()->assertJson([
        'email' => ['available' => true, 'exists' => false],
    ]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg) => str_contains($msg, 'Supabase orphan check failed'));
});

it('skips the Supabase check when the email is already taken in Laravel', function () {
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'handle' => 'existing',
        'handle_lc' => 'existing',
        'status' => 'active',
        'primary_email' => 'taken@example.com',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Http::fake();

    $response = $this->postJson('/api/public/signup/availability', [
        'email' => 'taken@example.com',
    ]);

    $response->assertOk()->assertJson([
        'email' => ['available' => false, 'exists' => true],
    ]);

    // Supabase must not be contacted — the Laravel hit is authoritative.
    Http::assertNothingSent();
});

// --- Rate limiter tests (P2-44 + P3-10) ---
//
// Re-register the limiter with hard-coded tight limits so these tests are
// not affected by PARTNA_THROTTLE_ENABLED=false in the test env. This mirrors
// the pattern used in ApiExceptionHandlingTest and PublicReportAntiAbuseTest.

it('429s on the 11th request within a minute (per-minute gate, P2-44)', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response(['users' => []], 200),
    ]);

    // Re-register with a 10/min limit, always-on (ignores PARTNA_THROTTLE_ENABLED).
    RateLimiter::for('signup-availability', function (Request $request) {
        $key = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());

        return [
            Limit::perMinute(10)->by($key)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
            Limit::perHour(60)->by($key)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
        ];
    });

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/public/signup/availability', ['email' => "u{$i}@example.com"])
            ->assertStatus(200);
    }

    $this->postJson('/api/public/signup/availability', ['email' => 'overflow@example.com'])
        ->assertStatus(429)
        ->assertJson(['message' => 'Too many requests. Please try again later.']);
});

it('429s on the 61st request within an hour (per-hour gate, P3-10)', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response(['users' => []], 200),
    ]);

    // Re-register with a high per-minute limit so the hour gate fires first.
    RateLimiter::for('signup-availability', function (Request $request) {
        $key = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());

        return [
            Limit::perMinute(200)->by($key)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
            Limit::perHour(60)->by($key)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
        ];
    });

    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/public/signup/availability', ['email' => "h{$i}@example.com"])
            ->assertStatus(200);
    }

    $this->postJson('/api/public/signup/availability', ['email' => 'hour-overflow@example.com'])
        ->assertStatus(429);
});

it('uses the dedicated signup-availability throttle (not public-site)', function () {
    $route = app('router')->getRoutes()->match(
        Request::create('/api/public/signup/availability', 'POST')
    );

    expect($route->gatherMiddleware())->toContain('throttle:signup-availability');
    expect($route->gatherMiddleware())->not->toContain('throttle:public-site');
});
