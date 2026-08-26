<?php

// A refresh that comes back thin must change NOTHING.
//
// InstagramConnectionSeeder::seed() rebuilds $images from scratch and then
// deletes every mirrored file it did NOT write this run (the stale-reclaim
// complement). Fed a thin profile that set is empty, so the complement is
// everything: seeding a thin profile blanks the payload AND unlinks the user's
// photo and reel from R2 — on the ~12h refresh sweep, for a live claimed user,
// with no alarm.
//
// The guard is that seed() never runs. Preservation by omission: not executing
// the destructive code cannot be broken by a later edit to it.

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // #LIFE-13: a QueryException in the observer's ingest-source seam is now
    // reported + warned instead of silently Log::debug'd, so a missing ingest
    // mirror turns every Eloquent-created connection into a spurious report.
    // Provision it; Bus::fake() because provisioning switches the eager-run
    // dispatch on, and these tests drive jobs via ->handle() directly.
    setupIngestTables();
    Bus::fake();
    config([
        'services.apify.token' => 'test-token',
        'partna.instagram.actor' => 'apify~instagram-profile-scraper',
        'partna.media_disk' => 'media',
    ]);
});

function thinRefreshUser(string $h): User
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

/** The 2026-08-10 fault: header fields present, post timeline absent. */
function thinRefreshItem(): array
{
    return [
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30042,
        'businessCategoryName' => 'None',
        'private' => false,
    ];
}

it('preserves the mirrored media and the payload when a refresh comes back thin', function () {
    Storage::fake('media');

    $user = thinRefreshUser('thinrefresh1');
    $folder = 'platforms/instagram/1700000000';
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [
            'username' => 'crucibletattooco',
            'postsCount' => 4164,
            'images' => ['https://cdn.example/photo.jpg'],
            'videoUrl' => 'https://cdn.example/reel.mp4',
            '_folder' => $folder,
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    Storage::disk('media')->put("{$folder}/photo.jpg", 'photo-bytes');
    Storage::disk('media')->put("{$folder}/reel.mp4", 'reel-bytes');
    Storage::disk('media')->put("{$folder}/reel-cover.jpg", 'cover-bytes');

    // Thin on the first call AND on the retry.
    Http::fake(['api.apify.com/*' => Http::response([thinRefreshItem()], 201)]);

    try {
        // Constructor order is (userId, username, connectionId).
        (new InstagramConnectJob($user->id, 'crucibletattooco', $connection->id))
            ->handle(
                app(InstagramScraper::class),
                app(InstagramConnectionSeeder::class),
                app(InstagramAutoSync::class),
            );
    } catch (Throwable) {
        // $this->fail() surfaces here outside a queue worker. The assertions below
        // are the point, not the exception.
    }

    // THE assertion: the bug deletes these. Asserting only on the payload would
    // pass while the media was being destroyed.
    Storage::disk('media')->assertExists("{$folder}/photo.jpg");
    Storage::disk('media')->assertExists("{$folder}/reel.mp4");
    Storage::disk('media')->assertExists("{$folder}/reel-cover.jpg");

    $connection->refresh();
    expect($connection->payload['images'])->toBe(['https://cdn.example/photo.jpg'])
        ->and($connection->payload['postsCount'])->toBe(4164)
        ->and($connection->payload['videoUrl'])->toBe('https://cdn.example/reel.mp4');
});

// DEVIATION from spec §5.2: the refresh error stays the generic 'job_failed'
// rather than a distinct 'thin_scrape'. Distinguishing it would require the job
// to call fetchProfileResult(), and 36 mock sites across 14 test files stub
// fetchProfile() by name — the entry point the job calls is effectively part of
// its contract. The occurrence is still recorded: InstagramScraper logs
// instagram.thin_profile with counts, retried and recovered. It is simply not
// queryable off the connection row.
//
// The terminal-state write itself (unavailable + consecutive_failures) is
// already covered by InstagramAsyncConnectTest and InstagramJobSeederLockTest,
// so it is not re-asserted here.
