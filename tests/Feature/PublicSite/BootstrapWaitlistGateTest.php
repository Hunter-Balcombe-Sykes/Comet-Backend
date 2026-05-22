<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use App\Services\Professional\SiteProvisioningService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['partna.waitlist.enabled' => true]);

    // TestCase::setUp redirects 'pgsql' to in-memory SQLite. Use the shared
    // helper to attach 'core' and create core.users.
    setupProfessionalsTable();
})->group('bootstrap-waitlist-gate');

it('blocks bootstrap for new users when waitlist mode is enabled', function () {
    $controller = new BootstrapController(new SiteProvisioningService);
    $request = BootstrapRequest::create('/api/bootstrap', 'POST');
    $request->attributes->set('supabase_uid', 'new-user-uid');

    $response = $controller->bootstrap(
        $request,
        \Mockery::mock(BrandAffiliateInviteService::class),
        \Mockery::mock(BrandPartnerLinkService::class),
        \Mockery::mock(AccountTypeDefaultsService::class),
    );

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors']['code'] ?? null)->toBe('WAITLIST_ONLY');
});

it('detects existing professionals by supabase auth user id', function () {
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => '00000000-0000-0000-0000-000000000001',
        'auth_user_id' => 'existing-user-uid',
    ]);

    $controller = new BootstrapController(new SiteProvisioningService);
    $method = new ReflectionMethod(BootstrapController::class, 'hasExistingProfessional');
    $method->setAccessible(true);

    expect($method->invoke($controller, 'existing-user-uid'))->toBeTrue();
    expect($method->invoke($controller, 'missing-user-uid'))->toBeFalse();
});
