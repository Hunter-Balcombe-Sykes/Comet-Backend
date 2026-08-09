<?php

/**
 * DISC-6: UserBootstrapService::bootstrap() classifies a concurrent
 * core_users_handle_lc_unique collision as a RuntimeException('HANDLE_ALREADY_TAKEN')
 * (see tests/Feature/PublicSite/BootstrapEmailRaceTest.php for the service-level
 * classifier coverage). This drives the controller side: translateBootstrapException()
 * must turn that into a friendly 409, not fall through to the generic Log::error +
 * re-throw (which the framework turns into a raw 500).
 *
 * Reaching this over a genuine end-to-end request needs a real concurrent write —
 * BootstrapRequest's Rule::unique(handle_lc) already rejects a single-request
 * collision before the service ever runs. Mirrors
 * BootstrapEmailAlreadyRegisteredLogTest's approach: bind a mock UserBootstrapService
 * that throws the exact exception the real service throws on that race, so the
 * assertions here exercise BootstrapController's real catch branch.
 */

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\User\UserBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('translates a HANDLE_ALREADY_TAKEN service exception into a 409 with the HANDLE_ALREADY_TAKEN code, not a raw 500', function () {
    // hasExistingProfessional($uid) must be true or the controller 410s before
    // ever calling the service (create branch is retired — see BootstrapController
    // header comment).
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => 'target-uid',
        'handle' => 'targetuser',
        'handle_lc' => 'targetuser',
        'display_name' => 'Target User',
        'first_name' => 'Targetuser',
        'primary_email' => 'target@example.com',
        'account_type' => 'partna',
        'status' => 'active',
    ]);

    $mockService = Mockery::mock(UserBootstrapService::class);
    $mockService->shouldReceive('bootstrap')->once()->andThrow(new RuntimeException('HANDLE_ALREADY_TAKEN'));
    app()->instance(UserBootstrapService::class, $mockService);

    $email = 'target@example.com';
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => $email,
        'display_name' => 'Target User',
        'handle' => 'someoneelsestaken',
    ]);
    $request->attributes->set('supabase_uid', 'target-uid');
    $request->attributes->set('supabase_claims', ['email' => $email]);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    $response = app(BootstrapController::class)->bootstrap($request);

    expect($response->getStatusCode())->toBe(409);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('HANDLE_ALREADY_TAKEN');
});

/*
 * SIGNUP-1 service side. createSiteForHandle() provisions the handle VERBATIM and
 * refuses to suffix, so a handle that is unusable as a subdomain now fails where
 * createSiteWithRetry() used to quietly return 'support-1' / 'errol-s-1'.
 *
 * On the pre-account path that failure is deliberately loud (the handle is
 * machine-allocated; a refusal means the allocator is broken). Here the handle is
 * USER-supplied and BootstrapRequest validates it against neither the reserved
 * list nor subdomains held by legacy diverged rows — so it must come back as the
 * existing actionable HANDLE_ALREADY_TAKEN, which the controller test above turns
 * into a 409. Without the translation these two cases are raw 500s.
 */

/** An existing professional with no site row — the only branch that still provisions. */
function bootstrapUserWithoutSite(string $uid, string $handle): User
{
    return User::factory()->create([
        'auth_user_id' => $uid,
        'handle' => $handle,
        'handle_lc' => $handle,
        'status' => 'active',
        'primary_email' => $handle.'@example.com',
    ]);
}

function bootstrapPayload(string $handle, string $email): array
{
    return [
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Some Pro',
        'first_name' => 'Some',
        'primary_email' => $email,
    ];
}

it('surfaces a RESERVED handle as HANDLE_ALREADY_TAKEN, not a raw 500', function () {
    $uid = (string) Str::uuid();
    bootstrapUserWithoutSite($uid, 'oldhandle');

    expect(fn () => app(UserBootstrapService::class)->bootstrap(
        $uid, bootstrapPayload('support', 'oldhandle@example.com')
    ))->toThrow(RuntimeException::class, 'HANDLE_ALREADY_TAKEN');

    // And it did NOT quietly provision 'support-1' the way createSiteWithRetry did.
    expect(DB::connection('pgsql')->table('site.sites')->count())->toBe(0);
});

it('surfaces a handle whose subdomain is held by a legacy diverged site as HANDLE_ALREADY_TAKEN', function () {
    $uid = (string) Str::uuid();
    bootstrapUserWithoutSite($uid, 'oldhandle');

    // The exact dev shape: handle 'errols', subdomain 'errol-s'. 'errol-s' is free
    // as a handle (so BootstrapRequest's uniqueness rule passes) but taken as a
    // subdomain, which only the provisioning side can see.
    $legacy = User::factory()->create(['handle' => 'errols', 'handle_lc' => 'errols']);
    Site::factory()->create(['user_id' => $legacy->id, 'subdomain' => 'errol-s']);

    expect(fn () => app(UserBootstrapService::class)->bootstrap(
        $uid, bootstrapPayload('errol-s', 'oldhandle@example.com')
    ))->toThrow(RuntimeException::class, 'HANDLE_ALREADY_TAKEN');

    expect(DB::connection('pgsql')->table('site.sites')->count())->toBe(1);
});

it('still provisions normally for a usable handle', function () {
    $uid = (string) Str::uuid();
    bootstrapUserWithoutSite($uid, 'oldhandle');

    $result = app(UserBootstrapService::class)->bootstrap(
        $uid, bootstrapPayload('brandnewhandle', 'oldhandle@example.com')
    );

    expect($result['site']->subdomain)->toBe('brandnewhandle')
        ->and($result['professional']->handle_lc)->toBe('brandnewhandle');
});
