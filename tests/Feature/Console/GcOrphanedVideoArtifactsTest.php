<?php

use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();
    config([
        'partna.media_disk' => 'media',
        'partna.media_orphan_sweep.gc_min_age_hours' => 0,
    ]);
    Storage::fake('media');
});

function putVideoObject(string $proId, string $mediaId, string $file = 'optimized.mp4'): string
{
    $path = "videos/{$proId}/{$mediaId}/{$file}";
    Storage::disk('media')->put($path, 'x');

    return $path;
}

function insertMediaRow(string $mediaId, bool $trashed = false): void
{
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => (string) Str::uuid(),
        'media_type' => SiteMedia::MEDIA_TYPE_VIDEO,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
        'deleted_at' => $trashed ? now()->toDateTimeString() : null,
    ]);
}

it('deletes video R2 objects that have no matching site_media row', function () {
    $mediaId = (string) Str::uuid();
    $path = putVideoObject('pro-1', $mediaId);
    // No DB row at all → genuine orphan (account hard-deleted, job failed).

    $this->artisan('media:gc-orphaned-video-artifacts')->assertSuccessful();

    Storage::disk('media')->assertMissing($path);
});

it('keeps R2 objects whose site_media row still exists, even when soft-deleted', function () {
    $mediaId = (string) Str::uuid();
    $path = putVideoObject('pro-1', $mediaId);
    insertMediaRow($mediaId, trashed: true);

    $this->artisan('media:gc-orphaned-video-artifacts')->assertSuccessful();

    Storage::disk('media')->assertExists($path);
});

it('keeps orphan objects newer than the age guard (possible in-flight upload)', function () {
    config(['partna.media_orphan_sweep.gc_min_age_hours' => 24]);
    $mediaId = (string) Str::uuid();
    $path = putVideoObject('pro-1', $mediaId);

    $this->artisan('media:gc-orphaned-video-artifacts')->assertSuccessful();

    Storage::disk('media')->assertExists($path);
});
