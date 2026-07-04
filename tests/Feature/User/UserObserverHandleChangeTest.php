<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Observers\User\UserObserver;
use App\Services\Cache\UserCacheService;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

beforeEach(function () {
    // Prevent Redis connection attempts in the cache service
    mock(UserCacheService::class)->shouldIgnoreMissing();
});

it('dispatches SyncSubdomainToKvJob when handle changes', function () {
    Queue::fake();

    $id = (string) Str::uuid();
    $pro = new User;
    $pro->setRawAttributes(['id' => $id, 'handle' => 'old-handle']);
    $pro->syncOriginal();
    $pro->handle = 'new-handle';
    $pro->syncChanges();

    app(UserObserver::class)->updated($pro);

    // SyncSubdomainToKvJob writes KV for the current handle AND every historical
    // alias (UpdateSiteAction inserts the old handle into the alias table inside
    // the same transaction), so the old subdomain keeps resolving via its alias
    // entry on a rename — no separate retirement dispatch is needed.
    Queue::assertPushed(SyncSubdomainToKvJob::class, fn ($job) => $job->userId === $id);
});

it('does not dispatch KV sync when handle does not change', function () {
    Queue::fake();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid(), 'handle' => 'same-handle', 'display_name' => 'Old Name']);
    $pro->syncOriginal();
    $pro->display_name = 'New Name';
    $pro->syncChanges();

    app(UserObserver::class)->updated($pro);

    Queue::assertNotPushed(SyncSubdomainToKvJob::class);
});

it('dispatches KV sync even when the old handle was empty', function () {
    Queue::fake();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid(), 'handle' => '']);
    $pro->syncOriginal();
    $pro->handle = 'new-handle';
    $pro->syncChanges();

    app(UserObserver::class)->updated($pro);

    Queue::assertPushed(SyncSubdomainToKvJob::class);
});

// ── KV retirement on delete / restore (#P2-45) ────────────────────────────

it('dispatches a KV retire sync with the captured handle when a professional is deleted', function () {
    Queue::fake();

    $id = (string) Str::uuid();
    $pro = new User;
    $pro->setRawAttributes(['id' => $id, 'handle' => 'gone-handle']);
    $pro->syncOriginal();

    app(UserObserver::class)->deleted($pro);

    // The single KV writer must be told to reconcile, carrying the handle so it
    // can delete the entry even after a hard-delete removes the lookup row.
    Queue::assertPushed(
        SyncSubdomainToKvJob::class,
        fn ($job) => $job->userId === $id && $job->capturedHandle === 'gone-handle'
    );
});

it('does not dispatch a KV retire sync when the deleted professional has no handle', function () {
    Queue::fake();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid(), 'handle' => null]);
    $pro->syncOriginal();

    app(UserObserver::class)->deleted($pro);

    Queue::assertNotPushed(SyncSubdomainToKvJob::class);
});

it('re-dispatches a KV sync (upsert) when a professional is restored', function () {
    Queue::fake();

    $id = (string) Str::uuid();
    $pro = new User;
    $pro->setRawAttributes(['id' => $id, 'handle' => 'back-handle']);
    $pro->syncOriginal();

    app(UserObserver::class)->restored($pro);

    // No captured handle on restore — the job upserts from the live (now
    // untrashed) row.
    Queue::assertPushed(
        SyncSubdomainToKvJob::class,
        fn ($job) => $job->userId === $id && $job->capturedHandle === null
    );
});

// ── Site touch on public-visible User-field changes (PR #120) ─────────────

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

    app(UserObserver::class)->updated($pro);
})->with(['handle', 'display_name', 'first_name', 'last_name', 'bio']);

it('survives a null site relation when a public field changes', function () {
    Queue::fake();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid(), 'bio' => 'old']);
    $pro->syncOriginal();
    $pro->bio = 'new';
    $pro->syncChanges();
    $pro->setRelation('site', null);

    // Should complete without throwing — `?->touch()` short-circuits.
    app(UserObserver::class)->updated($pro);

    expect(true)->toBeTrue();
});

// ── public_contact section re-evaluation (PR #121) ────────────────────────

it('re-evaluates the public_contact section when a public contact field changes', function (string $field) {
    Queue::fake();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    $site = new Site;
    $site->setRawAttributes(['id' => $siteId]);

    $pro = new User;
    $pro->setRawAttributes(['id' => $proId, $field => null]);
    $pro->syncOriginal();
    $pro->{$field} = $field === 'public_contact_email' ? 'hi@example.com' : '+61400000000';
    $pro->syncChanges();
    $pro->setRelation('site', $site);

    $visibility = mock(SectionVisibilityService::class);
    $visibility->shouldReceive('reevaluateEnabled')
        ->once()
        ->with($proId, $siteId, 'public_contact');

    app(UserObserver::class)->updated($pro);
})->with(['public_contact_number', 'public_contact_email']);

it('does not throw when a public contact change happens with no site relation', function () {
    Queue::fake();

    $pro = new User;
    $pro->setRawAttributes(['id' => (string) Str::uuid(), 'public_contact_email' => null]);
    $pro->syncOriginal();
    $pro->public_contact_email = 'hi@example.com';
    $pro->syncChanges();
    $pro->setRelation('site', null);

    $visibility = mock(SectionVisibilityService::class);
    $visibility->shouldNotReceive('reevaluateEnabled');

    app(UserObserver::class)->updated($pro);

    expect(true)->toBeTrue();
});

// ── Joint negative — non-public field changes trigger neither path ────────

it('skips both site touch and public_contact reeval when only a non-public field changes', function () {
    Queue::fake();

    $site = Mockery::mock(Site::class);
    $site->shouldNotReceive('touch');

    $visibility = mock(SectionVisibilityService::class);
    $visibility->shouldNotReceive('reevaluateEnabled');

    $pro = new User;
    $pro->setRawAttributes([
        'id' => (string) Str::uuid(),
        'phone' => '+61400000000',
    ]);
    $pro->syncOriginal();
    $pro->phone = '+61400000001';
    $pro->syncChanges();
    $pro->setRelation('site', $site);

    app(UserObserver::class)->updated($pro);
});
