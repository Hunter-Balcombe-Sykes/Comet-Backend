<?php

// PWL-13: EventsCatalog wrote site.platform_connections rows (storeAccount/
// storeStandalone) with NO lock, even though EventsPlatformController::
// addAccount()/addStandaloneEvent() (via ManagesIntegrationConnection::
// withConnectionLock) and ScheduledRefresh already serialise writes to the
// SAME eventbrite/humanitix rows behind CacheKeyGenerator::
// platformConnectionLock($platform, $userId). EventsController::add() ->
// EventsCatalog::addByUrl() -> storeAccount()/storeStandalone() was therefore
// an unlocked duplicate writer — a lost-update window.
//
// This proves the fix: pre-acquire the exact key a real writer would need
// ('eventbrite', $user->id — the SAME formula EventbriteController::platform()
// feeds into withConnectionLock), then hit the catalogue's add endpoint. The
// scraper is mocked so no real network fetch happens; only the DB read->write
// cycle inside storeAccount()/storeStandalone() is under test. Against the
// pre-fix code this fails FAST (no ~5s block, HTTP 2xx, a row written) because
// the catalogue's own Cache::lock()->block() call never existed — nothing
// contended on the pre-held lock at all.
//
// CACHE_STORE=array in phpunit.xml — a real in-process ArrayLock, not mocked.
//
// 2026-08-16 (slice 7 Phase 4): only the storeAccount half still locks.
// storeStandalone() stopped writing a connection at all, so the lost-update
// window it was fixed for no longer exists — see its case below, now inverted.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\EventbriteScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Slice 7 Phase 4: the standalone-event arm writes an `events` POOL item,
    // so the content lane has to exist for the case that no longer locks.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function sessionA5User(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function sessionA5Event(string $link): array
{
    return [
        'name' => 'Cool Show', 'venue' => 'The Venue', 'location' => 'Melbourne',
        'startDate' => '2099-01-01T10:00:00+10:00', 'endDate' => null, 'price' => 'Free',
        'availability' => 'available', 'image' => 'https://img.example/e.jpg', 'link' => $link,
    ];
}

it('returns 423 and writes no row when storeAccount contends on the eventbrite platform lock', function () {
    $orgUrl = 'https://www.eventbrite.com/o/my-org-456';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn(null);
    $scraper->shouldReceive('normalizeOrgUrl')->andReturn($orgUrl);
    $scraper->shouldReceive('fetchEvents')->with($orgUrl)->andReturn([
        'organiser' => 'My Org',
        'events' => [sessionA5Event('https://www.eventbrite.com/e/a-1')],
    ]);
    app()->instance(EventbriteScraper::class, $scraper);

    $user = sessionA5User('a5acct');

    // Simulate a concurrent writer (e.g. EventbriteController::addAccount, or
    // ScheduledRefresh) already holding the SAME key a real writer would build.
    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $orgUrl])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'eventbrite')->count())->toBe(0);
});

// INVERTED 2026-08-16 (slice 7 Phase 4). storeStandalone() no longer writes a
// connection row, so it correctly no longer takes the platform lock: the write
// is an idempotent upsert on a URL-derived coord, which has no read-then-write
// span to serialise. Holding the eventbrite key must therefore NOT block it.
//
// Kept rather than deleted, and asserted as a success: silently dropping the
// case would leave the lock's disappearance unwitnessed, and a future
// re-introduction of a connection write here would then pass unnoticed.
it('does NOT contend on the eventbrite platform lock, because storeStandalone writes no connection', function () {
    $eventUrl = 'https://www.eventbrite.com/e/cool-show-123';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn($eventUrl);
    $scraper->shouldReceive('fetchSingleEvent')->with($eventUrl)->andReturn(sessionA5Event($eventUrl));
    app()->instance(EventbriteScraper::class, $scraper);

    $user = sessionA5User('a5event');
    // A pool item needs a section, which hangs off the site.
    $site = new Site(['subdomain' => 'a5event', 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $eventUrl])->assertOk();
    } finally {
        $lock->release();
    }

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'eventbrite')->count())->toBe(0);
    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $user->id)->where('kind', 'event')->count())->toBe(1);
});
