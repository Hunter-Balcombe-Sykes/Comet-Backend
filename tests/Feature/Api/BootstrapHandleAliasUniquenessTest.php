<?php

use App\Http\Requests\Api\BootstrapRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Mirror the SQLite redirect used by BrandBootstrapTest.
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        'partna.waitlist.enabled' => false,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    attachTestSchemas();

    $conn = DB::connection('pgsql');

    // Non-prefixed table used by Rule::unique('professionals', 'handle_lc').
    DB::statement('CREATE TABLE IF NOT EXISTS professionals (
        id TEXT PRIMARY KEY,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        primary_email TEXT NULL
    )');

    // Schema-prefixed table used by the Professional Eloquent model.
    $conn->statement('CREATE TABLE IF NOT EXISTS core.users (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT NULL,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        display_name TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        primary_email TEXT NULL,
        phone TEXT NULL,
        account_type TEXT NULL CHECK (account_type IN (\'partna\',\'business\')),
        status TEXT NULL,
        onboarding_step INTEGER NULL,
        country_code TEXT NULL,
        timezone TEXT NULL,
        stripe_connect_account_id TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // Alias table — the new uniqueness check queries this. Includes the
    // lifecycle columns (expires_at etc.) so the active-alias predicate resolves.
    $conn->statement('CREATE TABLE IF NOT EXISTS core.user_handle_aliases (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        reclaim_until TEXT NULL,
        expires_at TEXT NULL,
        notified_t3_at TEXT NULL,
        notified_t1_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
})->group('bootstrap-handle-alias-uniqueness');

/**
 * Resolve the BootstrapRequest and run full validation. Returns the errors
 * array on failure, or null if validation passes.
 *
 * @return array<string, mixed>|null
 */
function validateBootstrapRequest(array $data, string $uid = ''): ?array
{
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', $data);
    $request->attributes->set('supabase_uid', $uid);
    $request->setContainer(app())->setRedirector(app('redirect'));

    try {
        $request->validateResolved();

        return null; // validation passed
    } catch (ValidationException $e) {
        return $e->errors();
    } catch (HttpResponseException $e) {
        // BootstrapRequest::failedValidation() short-circuits the invite-only
        // branch into a structured HttpResponseException so the API returns a
        // 'error: invite_required' code. Unwrap the JSON for the test helper.
        $payload = json_decode((string) $e->getResponse()->getContent(), true) ?: [];

        return $payload['errors'] ?? [];
    }
}

/**
 * Minimal valid bootstrap payload with all required fields.
 *
 * @return array<string, mixed>
 */
function validBootstrapPayload(array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Test User',
        'primary_email' => 'testuser@example.com',
        'phone' => '0400000000',
        'first_name' => 'Test',
        // Post account-type merge, BootstrapRequest rejects 'individual'
        // (Rule::in([partna, business])). Use a currently-valid type so these
        // fixtures exercise handle/alias uniqueness, not account_type validation.
        'account_type' => 'partna',
    ], $overrides);
}

it('rejects a handle_lc that already exists in the core.users table', function () {
    // Rule::unique(User::class, 'handle_lc') queries core.users (the User
    // model's table) — not the legacy 'professionals' table that was renamed
    // during the standalone-user strip. See #114.
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-000000000001',
        'handle' => 'taken',
        'handle_lc' => 'taken',
        'primary_email' => 'other@example.com',
    ]);

    $errors = validateBootstrapRequest(validBootstrapPayload([
        'handle' => 'taken',
        'primary_email' => 'newemail@example.com',
    ]));

    expect($errors)->toHaveKey('handle_lc');
});

it('rejects a handle that exists in the core.user_handle_aliases table', function () {
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => '00000000-0000-0000-0000-000000000002',
        'user_id' => '00000000-0000-0000-0000-000000000099',
        'handle' => 'aliashandle',
        'handle_lc' => 'aliashandle',
        'created_at' => now()->toDateTimeString(),
    ]);

    $errors = validateBootstrapRequest(validBootstrapPayload(['handle' => 'aliashandle']));

    expect($errors)->toHaveKey('handle_lc');
});

it('accepts a handle whose alias has expired (expired aliases no longer reserve the handle)', function () {
    // Before Item 4 this would have been rejected — any alias row blocked the handle,
    // regardless of expiry. An expired alias has lapsed back to the pool.
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => '00000000-0000-0000-0000-000000000003',
        'user_id' => '00000000-0000-0000-0000-000000000098',
        'handle' => 'expiredalias',
        'handle_lc' => 'expiredalias',
        'reclaim_until' => now()->subDays(91)->toDateTimeString(),
        'expires_at' => now()->subDay()->toDateTimeString(),
        'created_at' => now()->subDays(91)->toDateTimeString(),
    ]);

    $errors = validateBootstrapRequest(validBootstrapPayload(['handle' => 'expiredalias']));

    expect($errors)->toBeNull();
});

it('accepts a handle that exists neither in professionals nor in aliases', function () {
    $errors = validateBootstrapRequest(validBootstrapPayload(['handle' => 'freshhandle']));

    // Validation should pass outright — no errors at all.
    expect($errors)->toBeNull();
});

// PROF-4 — a colon in the handle would collide the Redis cache-key namespace
// (handle_lc is interpolated into 'pro:handle:...' style keys). BootstrapRequest
// must reject it at signup, mirroring UpdateSiteRequest / ReclaimHandleRequest.
it('rejects a handle containing a colon', function () {
    $errors = validateBootstrapRequest(validBootstrapPayload(['handle' => 'alice:bob']));

    expect($errors)->not->toBeNull();
    expect($errors)->toHaveKey('handle');
});
