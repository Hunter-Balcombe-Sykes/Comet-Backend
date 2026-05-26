<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    setupUsersTable();

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
    Illuminate\Support\Facades\DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
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
