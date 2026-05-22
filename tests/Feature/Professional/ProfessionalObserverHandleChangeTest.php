<?php

use App\Jobs\Cloudflare\RetireSubdomainFromKvJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\User;
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
