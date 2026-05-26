<?php

use App\Jobs\Cloudflare\RetireSubdomainFromKvJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\User;
use App\Models\Core\Site\Site;
use App\Observers\Professional\ProfessionalObserver;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

beforeEach(function () {
    // Prevent Redis connection attempts in the cache service
    mock(ProfessionalCacheService::class)->shouldIgnoreMissing();
});

it('dispatches SyncSubdomainToKvJob when handle changes', function () {
    Queue::fake();

    $id = (string) Str::uuid();
    $pro = new User;
    $pro->setRawAttributes(['id' => $id, 'handle' => 'old-handle']);
    $pro->syncOriginal();
    $pro->handle = 'new-handle';
    $pro->syncChanges();

    app(ProfessionalObserver::class)->updated($pro);

    // SyncSubdomainToKvJob now writes KV for the current handle AND every
    // historical alias (UpdateSiteAction inserts the old handle into the
    // alias table inside the same transaction), so a separate retirement
    // dispatch is no longer needed — the old subdomain keeps resolving via
    // its alias entry.
    Queue::assertPushed(SyncSubdomainToKvJob::class, fn ($job) => $job->professionalId === $id);
    Queue::assertNotPushed(RetireSubdomainFromKvJob::class);
});

it('does not dispatch retirement job when handle does not change', function () {
    Queue::fake();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid(), 'handle' => 'same-handle', 'display_name' => 'Old Name']);
    $pro->syncOriginal();
    $pro->display_name = 'New Name';
    $pro->syncChanges();

    app(ProfessionalObserver::class)->updated($pro);

    Queue::assertNotPushed(SyncSubdomainToKvJob::class);
    Queue::assertNotPushed(RetireSubdomainFromKvJob::class);
});

it('does not dispatch retirement job when old handle is empty', function () {
    Queue::fake();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid(), 'handle' => '']);
    $pro->syncOriginal();
    $pro->handle = 'new-handle';
    $pro->syncChanges();

    app(ProfessionalObserver::class)->updated($pro);

    Queue::assertPushed(SyncSubdomainToKvJob::class);
    Queue::assertNotPushed(RetireSubdomainFromKvJob::class);
});

it('touches parent site when a public-visible field changes', function (string $field) {
    Queue::fake();

    $site = Mockery::mock(Site::class);
    $site->shouldReceive('touch')->once();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid(), $field => 'old']);
    $pro->syncOriginal();
    $pro->{$field} = 'new';
    $pro->syncChanges();
    $pro->setRelation('site', $site);

    app(ProfessionalObserver::class)->updated($pro);
})->with(['handle', 'display_name', 'first_name', 'last_name', 'bio']);

it('touches parent site when about JSONB changes', function () {
    Queue::fake();

    $site = Mockery::mock(Site::class);
    $site->shouldReceive('touch')->once();

    // `about` is cast to array, so raw attributes hold the JSON string (matches
    // what Eloquent reads from Postgres). The assignment below sets the typed
    // value and `syncChanges` records the diff.
    $pro = new User;
    $pro->setRawAttributes([
        'id' => (string) Str::uuid(),
        'about' => json_encode(['credentials' => []]),
    ]);
    $pro->syncOriginal();
    $pro->about = ['credentials' => [['title' => 'New cert']]];
    $pro->syncChanges();
    $pro->setRelation('site', $site);

    app(ProfessionalObserver::class)->updated($pro);
});

it('does not touch parent site when only non-public fields change', function () {
    Queue::fake();

    $site = Mockery::mock(Site::class);
    $site->shouldNotReceive('touch');

    $pro = new User;
    $pro->setRawAttributes([
        'id' => (string) Str::uuid(),
        'handle' => 'jane',
        'phone' => '+61400000000',
    ]);
    $pro->syncOriginal();
    $pro->phone = '+61400000001';
    $pro->syncChanges();
    $pro->setRelation('site', $site);

    app(ProfessionalObserver::class)->updated($pro);
});

it('survives a null site relation when a public field changes', function () {
    Queue::fake();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid(), 'bio' => 'old']);
    $pro->syncOriginal();
    $pro->bio = 'new';
    $pro->syncChanges();
    $pro->setRelation('site', null);

    // Should complete without throwing — `?->touch()` short-circuits.
    app(ProfessionalObserver::class)->updated($pro);

    expect(true)->toBeTrue();
});
