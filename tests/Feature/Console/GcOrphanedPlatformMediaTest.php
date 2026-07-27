<?php

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupIntegrationConnectionsTable();
    config([
        'partna.media_orphan_sweep.platform_gc_min_age_hours' => 0,
        'partna.media_orphan_sweep.platform_gc_deleted_grace_days' => 7,
        'partna.media_orphan_sweep.platform_gc_max_per_run' => 200,
        'partna.media_orphan_sweep.platform_gc_max_orphan_ratio' => 0.25,
        'partna.media_orphan_sweep.platform_gc_anomaly_floor' => 20,
    ]);
    Storage::fake('media');
    Queue::fake();
});

function putPlatformMirrorFiles(string $folder, array $files = ['photo.jpg', 'profile.jpg']): void
{
    foreach ($files as $file) {
        Storage::disk('media')->put("{$folder}/{$file}", 'x');
    }
}

function insertInstagramConnection(Carbon $createdAt, ?Carbon $deletedAt = null, ?string $folder = null): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'resource_id' => 'ig-'.Str::random(6),
        'payload' => json_encode($folder !== null ? ['_folder' => $folder] : []),
        'is_active' => 1,
        'created_at' => $createdAt->toDateTimeString(),
        'updated_at' => $createdAt->toDateTimeString(),
        'deleted_at' => $deletedAt?->toDateTimeString(),
    ]);

    return $id;
}

// Every test but the empty-live-set pair needs at least one genuinely live
// connection so the empty-live-set refusal (§6) doesn't mask the behaviour
// under test. This connection has no matching folder on disk — it only keeps
// the live set non-empty.
function seedDecoyLiveConnection(): void
{
    insertInstagramConnection(now()->subDay());
}

it('dispatches reclamation for a folder with no connection row at all', function () {
    seedDecoyLiveConnection();
    $token = (string) now()->subDays(2)->timestamp;
    putPlatformMirrorFiles("platforms/instagram/{$token}");

    $this->artisan('media:gc-orphaned-platform-media')->assertSuccessful();

    Queue::assertPushed(DeleteMirroredMediaJob::class, fn ($job) => $job->folder === "platforms/instagram/{$token}");
});

it('keeps a folder whose token matches a live row created_at (derived match)', function () {
    seedDecoyLiveConnection();
    $createdAt = now()->subDays(3);
    $token = (string) $createdAt->timestamp;
    putPlatformMirrorFiles("platforms/instagram/{$token}");
    insertInstagramConnection($createdAt);

    $this->artisan('media:gc-orphaned-platform-media')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('keeps a folder recorded only in payload._folder, proving the derived+stored union', function () {
    seedDecoyLiveConnection();
    $createdAt = now()->subDays(5);
    $storedFolder = 'platforms/instagram/'.now()->subDays(6)->timestamp;
    putPlatformMirrorFiles($storedFolder);
    insertInstagramConnection($createdAt, folder: $storedFolder);

    $this->artisan('media:gc-orphaned-platform-media')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('keeps a folder whose connection is soft-deleted within the grace window', function () {
    seedDecoyLiveConnection();
    $createdAt = now()->subDays(10);
    $token = (string) $createdAt->timestamp;
    putPlatformMirrorFiles("platforms/instagram/{$token}");
    insertInstagramConnection($createdAt, deletedAt: now()->subDays(3));

    $this->artisan('media:gc-orphaned-platform-media')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('reclaims a folder whose connection is soft-deleted past the grace window', function () {
    seedDecoyLiveConnection();
    $createdAt = now()->subDays(20);
    $token = (string) $createdAt->timestamp;
    putPlatformMirrorFiles("platforms/instagram/{$token}");
    insertInstagramConnection($createdAt, deletedAt: now()->subDays(10));

    $this->artisan('media:gc-orphaned-platform-media')->assertSuccessful();

    Queue::assertPushed(DeleteMirroredMediaJob::class, fn ($job) => $job->folder === "platforms/instagram/{$token}");
});

it('keeps an orphan folder newer than the age guard (possible in-flight mirror write)', function () {
    config(['partna.media_orphan_sweep.platform_gc_min_age_hours' => 24]);
    seedDecoyLiveConnection();
    $token = (string) now()->timestamp;
    putPlatformMirrorFiles("platforms/instagram/{$token}");

    $this->artisan('media:gc-orphaned-platform-media')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('dry-run reports the candidate without dispatching anything', function () {
    seedDecoyLiveConnection();
    $token = (string) now()->subDays(2)->timestamp;
    putPlatformMirrorFiles("platforms/instagram/{$token}");

    $this->artisan('media:gc-orphaned-platform-media', ['--dry-run' => true])
        ->expectsOutputToContain("platforms/instagram/{$token}")
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('refuses to run when zero live rows exist but folders are on disk', function () {
    $token = (string) now()->subDays(2)->timestamp;
    putPlatformMirrorFiles("platforms/instagram/{$token}");

    $this->artisan('media:gc-orphaned-platform-media')->assertFailed();

    Queue::assertNothingPushed();
});

it('proceeds with --allow-empty-db despite zero live rows', function () {
    $token = (string) now()->subDays(2)->timestamp;
    putPlatformMirrorFiles("platforms/instagram/{$token}");

    $this->artisan('media:gc-orphaned-platform-media', ['--allow-empty-db' => true])->assertSuccessful();

    Queue::assertPushed(DeleteMirroredMediaJob::class, fn ($job) => $job->folder === "platforms/instagram/{$token}");
});

it('aborts and dispatches nothing when the orphan ratio exceeds the guard', function () {
    config(['partna.media_orphan_sweep.platform_gc_anomaly_floor' => 5]);
    seedDecoyLiveConnection();

    // 2 live folders (matching connections) + 10 orphan folders = 12 total.
    // 10/12 ≈ 0.83 exceeds the default 0.25 ratio, and 10 >= the lowered floor of 5.
    for ($i = 0; $i < 2; $i++) {
        $createdAt = now()->subDays(30 + $i);
        $token = (string) $createdAt->timestamp;
        putPlatformMirrorFiles("platforms/instagram/{$token}");
        insertInstagramConnection($createdAt);
    }
    for ($i = 0; $i < 10; $i++) {
        $token = (string) now()->subDays(40 + $i)->timestamp;
        putPlatformMirrorFiles("platforms/instagram/{$token}");
    }

    $this->artisan('media:gc-orphaned-platform-media')->assertFailed();

    Queue::assertNothingPushed();
});

it('caps dispatches at --limit, leaving the remainder for the next run', function () {
    seedDecoyLiveConnection();

    // 5 orphans, well under the default anomaly floor (20), so the ratio guard
    // does not fire even though the ratio itself (5/5) would exceed 0.25.
    for ($i = 0; $i < 5; $i++) {
        $token = (string) now()->subDays(50 + $i)->timestamp;
        putPlatformMirrorFiles("platforms/instagram/{$token}");
    }

    $this->artisan('media:gc-orphaned-platform-media', ['--limit' => 2])->assertSuccessful();

    Queue::assertPushed(DeleteMirroredMediaJob::class, 2);
});

it('never reclaims a non-instagram platforms/ namespace', function () {
    Storage::disk('media')->put('platforms/tiktok/123/clip.mp4', 'x');

    $this->artisan('media:gc-orphaned-platform-media')->assertSuccessful();

    Queue::assertNothingPushed();
    Storage::disk('media')->assertExists('platforms/tiktok/123/clip.mp4');
});

it('never lists or touches the videos/ prefix', function () {
    Storage::disk('media')->put('videos/pro-1/media-1/optimized.mp4', 'x');
    seedDecoyLiveConnection();
    $token = (string) now()->subDays(2)->timestamp;
    putPlatformMirrorFiles("platforms/instagram/{$token}");

    $this->artisan('media:gc-orphaned-platform-media')->assertSuccessful();

    Storage::disk('media')->assertExists('videos/pro-1/media-1/optimized.mp4');
    Queue::assertPushed(DeleteMirroredMediaJob::class, fn ($job) => $job->folder === "platforms/instagram/{$token}");
});

it('never reclaims a path deeper than the fixed 4-segment layout', function () {
    Storage::disk('media')->put('platforms/instagram/999999/nested/extra.jpg', 'x');

    $this->artisan('media:gc-orphaned-platform-media')->assertSuccessful();

    Queue::assertNothingPushed();
    Storage::disk('media')->assertExists('platforms/instagram/999999/nested/extra.jpg');
});
