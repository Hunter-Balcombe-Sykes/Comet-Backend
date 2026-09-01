<?php

// 9e (2026-09-01): timers out, events in. The Google-photo menu scan chains
// off MenuFetchJob's completion instead of a blind 5-minute head start; the
// accent's async tiers chain off a media row reaching READY instead of a
// blind +120s re-dispatch; and a transiently-failed fetch gets ONE in-band
// recovery shot instead of waiting up to 15 minutes for the cron.

use App\Jobs\Platforms\GoogleMenuPhotoScanJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Jobs\Platforms\ResolveSiteAccentJob;
use App\Jobs\Platforms\RetryMenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSiteMediaTable();
    Queue::fake();
});

function mecUser(string $handle): User
{
    return User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'business', 'sector' => 'restaurant', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function mecOrdering(User $user): IntegrationConnection
{
    $url = 'https://www.ubereats.com/store/x';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    return IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'uber_eats.order', 'resource_id' => $rid,
        'payload' => ['id' => $rid, 'provider' => 'custom', 'url' => $url, 'name' => 'Order', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
}

function mecMenu(User $user, string $fetchStatus): void
{
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id,
        'content_source' => 'uber-eats', 'fetch_status' => $fetchStatus,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
}

// ── GoogleMenuPhotoScanJob::dispatchAfterEnrich ──────────────────────────────

it('dispatches the photo scan immediately when no ordering platform is connected', function () {
    $user = mecUser('mec1');

    GoogleMenuPhotoScanJob::dispatchAfterEnrich($user->id, 'ChIJmec1');

    Queue::assertPushed(GoogleMenuPhotoScanJob::class, fn ($job) => $job->placeId === 'ChIJmec1');
});

it('defers the photo scan while an ordering fetch has not settled', function () {
    $user = mecUser('mec2');
    mecOrdering($user);
    mecMenu($user, 'pending');

    GoogleMenuPhotoScanJob::dispatchAfterEnrich($user->id, 'ChIJmec2');

    Queue::assertNotPushed(GoogleMenuPhotoScanJob::class);
});

it('dispatches the photo scan immediately once the ordering fetch already settled', function () {
    $user = mecUser('mec3');
    mecOrdering($user);
    mecMenu($user, 'ok');

    GoogleMenuPhotoScanJob::dispatchAfterEnrich($user->id, 'ChIJmec3');

    Queue::assertPushed(GoogleMenuPhotoScanJob::class, fn ($job) => $job->placeId === 'ChIJmec3');
});

// ── GoogleMenuPhotoScanJob::chainAfterMenuSettled ────────────────────────────

it('chains the photo scan with the place id read off the GBP connection', function () {
    $user = mecUser('mec4');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['placeId' => 'ChIJmec4', 'name' => 'Mec Four'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    GoogleMenuPhotoScanJob::chainAfterMenuSettled($user->id);

    Queue::assertPushed(GoogleMenuPhotoScanJob::class, fn ($job) => $job->placeId === 'ChIJmec4'
        && $job->userId === $user->id);
});

it('chain is a no-op without a GBP connection to read a place id from', function () {
    $user = mecUser('mec5');

    GoogleMenuPhotoScanJob::chainAfterMenuSettled($user->id);

    Queue::assertNotPushed(GoogleMenuPhotoScanJob::class);
});

// ── SiteMediaObserver accent chain ───────────────────────────────────────────

it('chains accent resolution when a gallery asset reaches READY with a dominant colour', function () {
    $user = mecUser('mec6');
    $site = Site::factory()->for($user, 'user')->create();

    (new SiteMedia([
        'pool' => 'content', 'path' => 'images/g.webp', 'media_type' => 'image',
        'processing_state' => 'ready', 'sort_order' => 0, 'is_active' => true,
        'dominant_color' => '#aa3311',
    ]))->site()->associate($site)->save();

    Queue::assertPushed(ResolveSiteAccentJob::class, fn ($job) => $job->siteId === (string) $site->id
        && $job->themeColor === null);
});

it('does not chain accent resolution for a still-processing or colourless asset', function () {
    $user = mecUser('mec7');
    $site = Site::factory()->for($user, 'user')->create();

    (new SiteMedia([
        'pool' => 'content', 'path' => 'images/p.webp', 'media_type' => 'image',
        'processing_state' => 'processing', 'sort_order' => 0, 'is_active' => true,
        'dominant_color' => '#aa3311',
    ]))->site()->associate($site)->save();

    (new SiteMedia([
        'pool' => 'gallery', 'path' => 'images/n.webp', 'media_type' => 'image',
        'processing_state' => 'ready', 'sort_order' => 1, 'is_active' => true,
    ]))->site()->associate($site)->save();

    Queue::assertNotPushed(ResolveSiteAccentJob::class);
});

// ── RetryMenuFetchJob (the one in-band recovery shot) ────────────────────────

it('re-dispatches a forced fetch when the menu is still unavailable', function () {
    $user = mecUser('mec8');
    mecMenu($user, 'unavailable');

    (new RetryMenuFetchJob($user->id))->handle();

    Queue::assertPushed(MenuFetchJob::class, fn (MenuFetchJob $job) => $job->userId === $user->id
        && $job->force === true
        && $job->inBandRetry === true);
});

it('spends nothing when the menu recovered during the relay delay', function () {
    $user = mecUser('mec9');
    mecMenu($user, 'ok');

    (new RetryMenuFetchJob($user->id))->handle();

    Queue::assertNotPushed(MenuFetchJob::class);
});
