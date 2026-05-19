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
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT NULL,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        display_name TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        primary_email TEXT NULL,
        phone TEXT NULL,
        professional_type TEXT NULL,
        account_type TEXT NULL,
        status TEXT NULL,
        onboarding_step INTEGER NULL,
        country_code TEXT NULL,
        timezone TEXT NULL,
        stripe_connect_account_id TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // Alias table — the new uniqueness check queries this.
    $conn->statement('CREATE TABLE IF NOT EXISTS site.professional_handle_aliases (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        created_at TEXT NULL
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
        'professional_type' => 'professional',
        // Satisfies the invite-only signup rule. Non-existent handle is harmless
        // — BootstrapController silently skips when no matching brand exists.
        'join_brand_handle' => 'no-such-brand-for-test',
    ], $overrides);
}

it('rejects a handle_lc that already exists in the professionals table', function () {
    DB::table('professionals')->insert([
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

it('rejects a handle that exists in the site.professional_handle_aliases table', function () {
    DB::connection('pgsql')->table('site.professional_handle_aliases')->insert([
        'id' => '00000000-0000-0000-0000-000000000002',
        'professional_id' => '00000000-0000-0000-0000-000000000099',
        'handle' => 'aliashandle',
        'handle_lc' => 'aliashandle',
        'created_at' => now()->toDateTimeString(),
    ]);

    $errors = validateBootstrapRequest(validBootstrapPayload(['handle' => 'aliashandle']));

    expect($errors)->toHaveKey('handle_lc');
});

it('accepts a handle that exists neither in professionals nor in aliases', function () {
    $errors = validateBootstrapRequest(validBootstrapPayload(['handle' => 'freshhandle']));

    // Validation should pass outright — no errors at all.
    expect($errors)->toBeNull();
});

// ── Invite-only signup enforcement ────────────────────────────────────────────
// A new non-brand professional must arrive with one of invite_token /
// brand_partner_professional_id / join_brand_handle. Otherwise the bootstrap
// would create an orphaned affiliate with no brand link, no KV entry, and
// no site.settings.brand_partner (this is exactly how the 'hjjh' bad state
// happened in dev — see PR #66/67 and the inline comment in BootstrapRequest).

it('rejects new affiliate signup with no invite/brand context', function () {
    $errors = validateBootstrapRequest(array_merge(validBootstrapPayload([
        'handle' => 'inviteless',
    ]), ['join_brand_handle' => null]));

    expect($errors)->not->toBeNull();
    expect($errors)->toHaveKey('join_brand_handle');
    expect($errors['join_brand_handle'][0] ?? '')->toContain('invitation');
});

it('rejects new influencer signup with no invite/brand context', function () {
    $errors = validateBootstrapRequest(array_merge(validBootstrapPayload([
        'handle' => 'inviteless2',
        'professional_type' => 'influencer',
    ]), ['join_brand_handle' => null]));

    expect($errors)->not->toBeNull();
    expect($errors)->toHaveKey('join_brand_handle');
});

it('accepts new affiliate signup with invite_token', function () {
    $errors = validateBootstrapRequest(array_merge(
        validBootstrapPayload(['handle' => 'withinvite']),
        ['join_brand_handle' => null, 'invite_token' => 'some-test-invite-token']
    ));

    expect($errors)->toBeNull();
});

it('accepts new BRAND signup with no invite context', function () {
    // Brands self-onboard; the invite-only rule must not apply to them.
    $errors = validateBootstrapRequest(array_merge(
        validBootstrapPayload([
            'handle' => 'somebrand',
            'professional_type' => 'brand',
        ]),
        ['join_brand_handle' => null]
    ));

    expect($errors)->toBeNull();
});

it('accepts re-bootstrap of existing professional without invite context', function () {
    // An existing professional re-saving their profile must not be subject to
    // the invite-only rule — they already passed it at signup.
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => '00000000-0000-0000-0000-00000000aaaa',
        'auth_user_id' => 'existing-uid-rebootstrap',
        'handle' => 'existingpro',
        'handle_lc' => 'existingpro',
        'professional_type' => 'professional',
    ]);

    $errors = validateBootstrapRequest(
        array_merge(
            validBootstrapPayload([
                'handle' => 'existingpro',
                'primary_email' => 'existingpro@example.com',
            ]),
            ['join_brand_handle' => null]
        ),
        uid: 'existing-uid-rebootstrap'
    );

    expect($errors)->toBeNull();
});
