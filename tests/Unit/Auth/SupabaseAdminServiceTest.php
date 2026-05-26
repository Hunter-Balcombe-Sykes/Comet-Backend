<?php

use App\Services\Auth\SupabaseAdminService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config([
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-service-key',
    ]);
});

it('createUser returns id, email, and created=true on success', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response([
            'id' => 'supabase-uuid-123',
            'email' => 'new@example.com',
        ], 200),
    ]);

    $service = new SupabaseAdminService;
    $result = $service->createUser('new@example.com');

    expect($result)->toBe([
        'id' => 'supabase-uuid-123',
        'email' => 'new@example.com',
        'created' => true,
    ]);
});

it('createUser returns created=false when GoTrue v2 returns existing user id in 422 body', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response([
            'code' => 'email_exists',
            'user' => [
                'id' => 'existing-uuid-456',
                'email' => 'existing@example.com',
            ],
        ], 422),
    ]);

    $service = new SupabaseAdminService;
    $result = $service->createUser('existing@example.com');

    expect($result)->toBe([
        'id' => 'existing-uuid-456',
        'email' => 'existing@example.com',
        'created' => false,
    ]);
});

it('createUser throws when 422 arrives without user id in body', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response([
            'code' => 'email_exists',
            'msg' => 'User already registered',
            // no 'user' key — should throw, not paginate
        ], 422),
    ]);

    $service = new SupabaseAdminService;

    expect(fn () => $service->createUser('conflict@example.com'))
        ->toThrow(RuntimeException::class);
});

it('createUser throws on generic HTTP failure', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response(['msg' => 'server error'], 500),
    ]);

    $service = new SupabaseAdminService;

    expect(fn () => $service->createUser('fail@example.com'))
        ->toThrow(RuntimeException::class);
});

it('createUser trims and lowercases the email', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response([
            'id' => 'uuid-789',
            'email' => 'user@example.com',
        ], 200),
    ]);

    $service = new SupabaseAdminService;
    $result = $service->createUser('  USER@Example.COM  ');

    expect($result['email'])->toBe('user@example.com');

    Http::assertSent(function ($request) {
        return $request->data()['email'] === 'user@example.com';
    });
});

it('createUser throws on empty email', function () {
    $service = new SupabaseAdminService;

    expect(fn () => $service->createUser(''))
        ->toThrow(RuntimeException::class, 'Email is required');
});

it('findUserByEmail returns user data when Supabase has a matching record', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response([
            'users' => [
                [
                    'id' => 'supabase-uuid-abc',
                    'email' => 'found@example.com',
                    'email_confirmed_at' => '2026-05-23T08:06:26Z',
                ],
            ],
        ], 200),
    ]);

    $service = new SupabaseAdminService;
    $result = $service->findUserByEmail('found@example.com');

    expect($result)->toBe([
        'id' => 'supabase-uuid-abc',
        'email' => 'found@example.com',
        'email_confirmed_at' => '2026-05-23T08:06:26Z',
    ]);
});

it('findUserByEmail returns null when no user matches', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response(['users' => []], 200),
    ]);

    $service = new SupabaseAdminService;
    $result = $service->findUserByEmail('missing@example.com');

    expect($result)->toBeNull();
});

it('findUserByEmail ignores Supabase substring matches that are not exact', function () {
    // GoTrue email filter is LIKE in some versions — alice@ex.com substring-matches
    // alice@ex.com.au. The service must re-verify exact match before returning a hit.
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response([
            'users' => [
                [
                    'id' => 'wrong-uuid',
                    'email' => 'alice@ex.com.au',
                    'email_confirmed_at' => '2026-01-01T00:00:00Z',
                ],
            ],
        ], 200),
    ]);

    $service = new SupabaseAdminService;
    $result = $service->findUserByEmail('alice@ex.com');

    expect($result)->toBeNull();
});

it('findUserByEmail returns null for an empty email', function () {
    $service = new SupabaseAdminService;

    expect($service->findUserByEmail(''))->toBeNull();
    expect($service->findUserByEmail('   '))->toBeNull();
});

it('findUserByEmail trims and lowercases the email before querying', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response([
            'users' => [
                [
                    'id' => 'uuid-x',
                    'email' => 'normalised@example.com',
                    'email_confirmed_at' => null,
                ],
            ],
        ], 200),
    ]);

    $service = new SupabaseAdminService;
    $service->findUserByEmail('  NORMALISED@Example.COM  ');

    Http::assertSent(function ($request) {
        return ($request->data()['email'] ?? null) === 'normalised@example.com';
    });
});

it('findUserByEmail throws RuntimeException on HTTP failure', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response(['msg' => 'server error'], 500),
    ]);

    $service = new SupabaseAdminService;

    expect(fn () => $service->findUserByEmail('boom@example.com'))
        ->toThrow(RuntimeException::class);
});

it('findUserByEmail logs an email_fingerprint on failure (no raw email)', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users*' => Http::response(['msg' => 'server error'], 500),
    ]);

    Log::spy();

    $service = new SupabaseAdminService;

    expect(fn () => $service->findUserByEmail('  PII@Example.COM  '))
        ->toThrow(RuntimeException::class);

    $expectedFingerprint = hash('sha256', 'pii@example.com');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($expectedFingerprint) {
            return $message === 'Supabase admin: findUserByEmail failed'
                && ! array_key_exists('email', $context)
                && ($context['email_fingerprint'] ?? null) === $expectedFingerprint;
        });
});

it('logs an email_fingerprint instead of the raw email on createUser failure', function () {
    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users' => Http::response(['msg' => 'server error'], 500),
    ]);

    Log::spy();

    $service = new SupabaseAdminService;

    expect(fn () => $service->createUser('  USER@Example.COM  '))
        ->toThrow(RuntimeException::class);

    $expectedFingerprint = hash('sha256', 'user@example.com');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($expectedFingerprint) {
            // PII must be redacted: no raw email key, fingerprint present and matches normalised email.
            return $message === 'Supabase admin: failed to create user'
                && ! array_key_exists('email', $context)
                && ($context['email_fingerprint'] ?? null) === $expectedFingerprint;
        });
});

// B3 / P1-09: GoTrue 4xx responses include the full user object (email, metadata, phone).
// Embedding $response->body() in the exception message persists that PII into Horizon
// failed-job records and any `report($e)` upstream — retention windows the GDPR sweep
// can't reach. Status code is sufficient for diagnosis.
it('unenrollMfaFactor does not leak response body into exception message', function () {
    config(['supabase.admin.base_url' => 'https://test.supabase.co/auth/v1/admin']);

    Http::fake([
        'https://test.supabase.co/auth/v1/admin/users/*/factors/*' => Http::response([
            'user' => [
                'id' => 'uid-123',
                'email' => 'pii@example.com',
                'user_metadata' => ['phone' => '+44-7700-900000'],
            ],
            'code' => 'mfa_factor_not_found',
        ], 404),
    ]);

    $service = new SupabaseAdminService;

    try {
        $service->unenrollMfaFactor('uid-123', 'factor-abc');
        $this->fail('Expected RuntimeException to be thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('HTTP 404')
            ->and($e->getMessage())->not->toContain('pii@example.com')
            ->and($e->getMessage())->not->toContain('body=')
            ->and($e->getMessage())->not->toContain('user_metadata');
    }
});
