<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\User\UserBootstrapService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['partna.waitlist.enabled' => true]);
    config(['partna.individual_waitlist_enabled' => false]);

    // TestCase::setUp redirects 'pgsql' to in-memory SQLite. Use the shared
    // helper to attach 'core' and create core.users.
    setupUsersTable();
    // The 200 path serialises the professional via UserDashboardResource, which
    // reads $this->site?->custom_domain (custom-domain merge) — so site.sites must
    // exist or the resource throws "no such table". Empty table → $this->site is
    // null → the null-safe reads return null, which is all this gate test needs.
    setupSitesTable();
})->group('bootstrap-waitlist-gate');

// Task 14: WAITLIST_ONLY for a brand-new signup attempt moved off bootstrap —
// it now lives on the build endpoint (PublicBuildEndpointsTest, Task 11). The
// old WAITLIST_ONLY block here only ever fired for
// `! hasExistingProfessional($uid)` callers, and such callers now 410 first
// regardless of the waitlist config, which is exactly what this test proves:
// the config toggle no longer changes the outcome for a new user.
it('410s a new user even when waitlist mode is enabled (WAITLIST_ONLY block is dead — moved to POST /api/public/signup/build)', function () {
    // partna.waitlist.enabled is already true from beforeEach.
    $controller = app(BootstrapController::class);
    $request = BootstrapRequest::create('/api/bootstrap', 'POST');
    $request->attributes->set('supabase_uid', 'new-user-uid');
    // Verified email claim present so this isolates the retirement gate from the
    // fail-closed EMAIL_VERIFICATION_REQUIRED guard.
    $request->attributes->set('supabase_claims', ['email' => 'newuser@example.com']);

    $response = $controller->bootstrap($request);

    expect($response->getStatusCode())->toBe(410);
    expect($response->getData(true)['code'] ?? null)->toBe('SIGNUP_MOVED');
});

it('does not gate existing professionals when waitlist mode is enabled', function () {
    $existing = new User([
        'handle' => 'existing',
        'handle_lc' => 'existing',
        'display_name' => 'Existing User',
        'primary_email' => 'existing@example.com',
        'status' => 'active',
        'account_type' => 'partna',
    ]);
    $existing->id = '00000000-0000-0000-0000-000000000001';

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $existing->id,
        'auth_user_id' => 'existing-user-uid',
        'primary_email' => 'existing@example.com',
        'handle' => 'existing',
        'handle_lc' => 'existing',
        'display_name' => 'Existing User',
        'first_name' => 'Existing',
        'status' => 'active',
        'account_type' => 'partna',
    ]);

    // Stub the service — this test only verifies the gate predicate, not the
    // full bootstrap path. If the gate fires, the service is never called; if
    // the gate lets the request through, the stub returns a known-good shape.
    $site = new Site(['id' => '00000000-0000-0000-0000-000000000002', 'subdomain' => 'existing']);
    $this->instance(UserBootstrapService::class, Mockery::mock(UserBootstrapService::class, function ($mock) use ($existing, $site) {
        $mock->shouldReceive('bootstrap')->once()->andReturn([
            'professional' => $existing,
            'site' => $site,
            'created' => false,
        ]);
    }));

    $controller = app(BootstrapController::class);
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', [
        'primary_email' => 'existing@example.com',
        'display_name' => 'Existing User',
        'handle' => 'existing',
    ]);
    $request->attributes->set('supabase_uid', 'existing-user-uid');
    $request->attributes->set('supabase_claims', ['email' => 'existing@example.com']);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    $response = $controller->bootstrap($request);

    // Gate did not fire → service was called → 200 success path.
    expect($response->getStatusCode())->toBe(200);
});
