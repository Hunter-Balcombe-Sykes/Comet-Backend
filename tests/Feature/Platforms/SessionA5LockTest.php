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

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\EventbriteScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function sessionA5User(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
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

it('returns 423 and writes no row when storeStandalone contends on the eventbrite platform lock', function () {
    $eventUrl = 'https://www.eventbrite.com/e/cool-show-123';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn($eventUrl);
    $scraper->shouldReceive('fetchSingleEvent')->with($eventUrl)->andReturn(sessionA5Event($eventUrl));
    app()->instance(EventbriteScraper::class, $scraper);

    $user = sessionA5User('a5event');

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $eventUrl])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'eventbrite')->count())->toBe(0);
});
