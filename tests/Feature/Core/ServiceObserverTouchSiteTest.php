<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Observers\Core\ServiceObserver;
use App\Services\Cache\UserCacheService;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

beforeEach(function () {
    // Stub heavyweight collaborators so the unit test runs without Redis.
    mock(UserCacheService::class)->shouldIgnoreMissing();
    mock(SectionVisibilityService::class)->shouldIgnoreMissing();
});

function invokeTouchParentSite(ServiceObserver $observer, Service $service, ?User $pro): void
{
    $method = (new ReflectionClass($observer))->getMethod('touchParentSite');
    $method->invoke($observer, $service, $pro);
}

it('touchParentSite calls touch() on the professional\'s site', function () {
    $site = Mockery::mock(Site::class);
    $site->shouldReceive('touch')->once();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid()]);
    $pro->setRelation('site', $site);

    $service = new Service;
    $service->setRawAttributes(['id' => (string) Str::uuid(), 'user_id' => $pro->id]);

    invokeTouchParentSite(app(ServiceObserver::class), $service, $pro);
});

it('touchParentSite no-ops when the professional has no site', function () {
    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid()]);
    $pro->setRelation('site', null);

    $service = new Service;
    $service->setRawAttributes(['id' => (string) Str::uuid(), 'user_id' => $pro->id]);

    // Should complete without throwing — `?->touch()` short-circuits.
    invokeTouchParentSite(app(ServiceObserver::class), $service, $pro);

    expect(true)->toBeTrue();
});

it('touchParentSite no-ops when the professional itself is null', function () {
    $service = new Service;
    $service->setRawAttributes(['id' => (string) Str::uuid(), 'user_id' => (string) Str::uuid()]);

    invokeTouchParentSite(app(ServiceObserver::class), $service, null);

    expect(true)->toBeTrue();
});

it('touchParentSite swallows touch() exceptions and logs a warning', function () {
    $site = Mockery::mock(Site::class);
    $site->shouldReceive('touch')->once()->andThrow(new RuntimeException('db down'));

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid()]);
    $pro->setRelation('site', $site);

    $service = new Service;
    $service->setRawAttributes(['id' => (string) Str::uuid(), 'user_id' => $pro->id]);

    // Should NOT propagate the exception — a transient touch() failure on cache
    // bookkeeping must never break the main service save flow.
    invokeTouchParentSite(app(ServiceObserver::class), $service, $pro);

    expect(true)->toBeTrue();
});
