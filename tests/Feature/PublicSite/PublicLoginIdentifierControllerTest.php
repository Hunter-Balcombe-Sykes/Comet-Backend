<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    setupUsersTable();
    Cache::flush();
});

it('echoes a valid email back lowercased', function () {
    $response = $this->postJson('/api/public/auth/resolve-identifier', [
        'identifier' => 'Tobias@Example.com',
    ]);

    $response->assertOk()->assertJson(['email' => 'tobias@example.com']);
});

it('resolves a handle to the matching primary email', function () {
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-000000000010',
        'auth_user_id' => 'auth-user-handle-resolve',
        'handle' => 'tobias-balcombe-ehrlich',
        'handle_lc' => 'tobias-balcombe-ehrlich',
        'display_name' => 'Tobias',
        'primary_email' => 'tobias@example.com',
        'account_type' => 'partna',
        'status' => 'active',
        'onboarding_step' => 0,
    ]);

    $response = $this->postJson('/api/public/auth/resolve-identifier', [
        'identifier' => 'Tobias-Balcombe-Ehrlich',
    ]);

    $response->assertOk()->assertJson(['email' => 'tobias@example.com']);
});

it('returns null for an unknown handle', function () {
    $response = $this->postJson('/api/public/auth/resolve-identifier', [
        'identifier' => 'nobody-here',
    ]);

    $response->assertOk()->assertJson(['email' => null]);
});

it('rejects requests without an identifier', function () {
    $response = $this->postJson('/api/public/auth/resolve-identifier', []);

    $response->assertStatus(422);
});

// --- Rate limiter tests (P2-44) ---

it('429s on the 21st request within a minute (per-minute gate, P2-44)', function () {
    // Re-register with a 20/min limit, always-on (ignores PARTNA_THROTTLE_ENABLED).
    RateLimiter::for('login-identifier', function (Request $request) {
        $key = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());

        return Limit::perMinute(20)
            ->by($key)
            ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429));
    });

    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/api/public/auth/resolve-identifier', ['identifier' => "nobody-{$i}@example.com"])
            ->assertStatus(200);
    }

    $this->postJson('/api/public/auth/resolve-identifier', ['identifier' => 'overflow@example.com'])
        ->assertStatus(429)
        ->assertJson(['message' => 'Too many requests. Please try again later.']);
});

it('uses the dedicated login-identifier throttle (not public-site)', function () {
    $route = app('router')->getRoutes()->match(
        Request::create('/api/public/auth/resolve-identifier', 'POST')
    );

    expect($route->gatherMiddleware())->toContain('throttle:login-identifier');
    expect($route->gatherMiddleware())->not->toContain('throttle:public-site');
});

it('exhausting signup-availability does not 429 login-identifier (bucket independence)', function () {
    // Supabase config required by the availability controller's orphan-check path.
    config([
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-service-key',
        'partna.waitlist.enabled' => false,
    ]);

    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response(['users' => []], 200),
    ]);

    // Exhaust the signup-availability bucket (tight 1/min limit for test isolation).
    RateLimiter::for('signup-availability', function (Request $request) {
        $key = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());

        return [
            Limit::perMinute(1)->by($key)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
            Limit::perHour(60)->by($key)
                ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429)),
        ];
    });

    // Register login-identifier with a real limit so it's a valid check.
    RateLimiter::for('login-identifier', function (Request $request) {
        $key = (string) ($request->header('CF-Connecting-IP') ?? $request->ip());

        return Limit::perMinute(20)
            ->by($key)
            ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429));
    });

    $this->postJson('/api/public/signup/availability', ['email' => 'bucket@example.com'])->assertStatus(200);
    $this->postJson('/api/public/signup/availability', ['email' => 'bucket2@example.com'])->assertStatus(429);

    // login-identifier should still succeed — its bucket is independent.
    $this->postJson('/api/public/auth/resolve-identifier', ['identifier' => 'nobody@example.com'])
        ->assertStatus(200);
});
