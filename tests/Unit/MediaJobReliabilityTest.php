<?php

/** @phpstan-ignore-all */

use App\Jobs\DeleteMediaArtifactsJob;
use App\Jobs\ProcessImageVariantsJob;
use App\Jobs\ProcessVideoVariantsJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Media\VideoVariantService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    bootstrapMediaJobsSchema();

    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => storage_path('framework/testing/disks/media-jobs'),
    ]);

    if (! is_dir(config('filesystems.disks.local.root'))) {
        mkdir(config('filesystems.disks.local.root'), 0777, true);
    }
});

it('has correct reliability properties on ProcessVideoVariantsJob', function () {
    $job = new ProcessVideoVariantsJob('media-id', 'videos/test/original.mp4', 'videos/test/media-id');

    expect($job->tries)->toBe(2);
    expect($job->backoff)->toBe(60);
    expect($job->timeout)->toBe(720);
});

it('rethrows image processing exceptions and marks failed in failed handler', function () {
    $imageId = seedSiteImageRow(SiteMedia::MEDIA_TYPE_IMAGE);
    $originalPath = "images/test/{$imageId}/original.jpg";

    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andThrow(new RuntimeException('image boom'));

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");

    expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'image boom');

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PROCESSING);

    $job->failed(new RuntimeException('image boom'));
    $row->refresh();

    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);
    expect((string) $row->processing_error)->toContain('image boom');
});

it('fast-fails image processing when original file is missing', function () {
    $imageId = seedSiteImageRow(SiteMedia::MEDIA_TYPE_IMAGE);
    $originalPath = "images/test/{$imageId}/missing.jpg";

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->never();

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");
    $job->withFakeQueueInteractions();
    $job->handle($service);

    $job->assertFailed();

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);
    expect((string) $row->processing_error)->toContain('Original file not found');
});

it('rethrows video processing exceptions and marks failed in failed handler', function () {
    $mediaId = seedSiteImageRow(SiteMedia::MEDIA_TYPE_VIDEO);
    $originalPath = "videos/test/{$mediaId}/original.mp4";

    Storage::disk('local')->put($originalPath, 'video-bytes');

    $service = Mockery::mock(VideoVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andThrow(new RuntimeException('video boom'));

    $job = new ProcessVideoVariantsJob($mediaId, $originalPath, "videos/test/{$mediaId}");

    expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'video boom');

    $row = SiteMedia::query()->findOrFail($mediaId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PROCESSING);

    $job->failed(new RuntimeException('video boom'));
    $row->refresh();

    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);
    expect((string) $row->processing_error)->toContain('video boom');
});

it('fast-fails video processing when original file is missing', function () {
    $mediaId = seedSiteImageRow(SiteMedia::MEDIA_TYPE_VIDEO);
    $originalPath = "videos/test/{$mediaId}/missing.mp4";

    $service = Mockery::mock(VideoVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->never();

    $job = new ProcessVideoVariantsJob($mediaId, $originalPath, "videos/test/{$mediaId}");
    $job->withFakeQueueInteractions();
    $job->handle($service);

    $job->assertFailed();

    $row = SiteMedia::query()->findOrFail($mediaId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);
    expect((string) $row->processing_error)->toContain('Original video file not found');
});

it('keeps video media ready when the processing job completes successfully', function () {
    $mediaId = seedSiteImageRow(SiteMedia::MEDIA_TYPE_VIDEO);
    $originalPath = "videos/test/{$mediaId}/original.mp4";

    Storage::disk('local')->put($originalPath, 'video-bytes');

    $service = Mockery::mock(VideoVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andReturnUsing(function () use ($mediaId) {
        SiteMedia::query()
            ->where('id', $mediaId)
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                'processing_error' => null,
            ]);
    });

    $job = new ProcessVideoVariantsJob($mediaId, $originalPath, "videos/test/{$mediaId}");
    $job->handle($service);

    $row = SiteMedia::query()->findOrFail($mediaId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY);
});

it('rethrows delete cleanup failures so the queue can retry', function () {
    $service = Mockery::mock(VideoVariantService::class);
    $service->shouldReceive('deleteVariants')
        ->once()
        ->andThrow(new RuntimeException('cleanup boom'));

    $job = new DeleteMediaArtifactsJob((string) Str::uuid(), 'videos/test/media', 'gallery');
    $job->withFakeQueueInteractions();

    expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'cleanup boom');
    $job->assertNotFailed();
});

it('normalizes legacy file paths and deletes media artifacts plus db rows', function () {
    $mediaId = (string) Str::uuid();
    $basePath = "videos/test/{$mediaId}";
    $originalPath = "{$basePath}/original_abc.mp4";
    $segmentPath = "{$basePath}/hls/optimized/seg_000.ts";
    $playlistPath = "{$basePath}/hls/optimized/playlist.m3u8";

    Storage::disk('local')->put($originalPath, 'orig');
    Storage::disk('local')->put($segmentPath, 'segment');
    Storage::disk('local')->put($playlistPath, 'playlist');

    DB::connection('pgsql')->table('media_variants')->insert([
        'id' => (string) Str::uuid(),
        'media_id' => $mediaId,
        'variant_key' => 'optimized',
        'artifact_type' => 'hls_playlist',
        'disk' => 'local',
        'path' => $playlistPath,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $service = new VideoVariantService;
    $service->deleteVariants($mediaId, $originalPath);

    expect(Storage::disk('local')->exists($originalPath))->toBeFalse();
    expect(Storage::disk('local')->exists($segmentPath))->toBeFalse();
    expect(Storage::disk('local')->exists($playlistPath))->toBeFalse();
    expect(DB::connection('pgsql')->table('media_variants')->where('media_id', $mediaId)->count())->toBe(0);
});

it('still clears DB rows when storage listing fails (best-effort cleanup)', function () {
    // Contract: deleteVariants is best-effort on storage and unconditional on DB.
    // If R2 listing flakes, we still scrub the DB row so the user sees the media as
    // deleted; any storage orphans become a separate ops concern. Previously this
    // threw, which caused DeleteMediaArtifactsJob to retry 3× and amplify transients.
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('media_variants')->insert([
        'id' => (string) Str::uuid(),
        'media_id' => $mediaId,
        'variant_key' => 'optimized',
        'artifact_type' => 'mp4',
        'disk' => 'local',
        'path' => 'videos/test/media/optimized.mp4',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')
        ->once()
        ->with('videos/test/media')
        ->andThrow(new RuntimeException('disk exploded'));

    Storage::shouldReceive('disk')
        ->once()
        ->andReturn($disk);

    $service = new VideoVariantService;

    // Must not throw — graceful degradation on transient storage failure.
    $service->deleteVariants($mediaId, 'videos/test/media/original.mp4');

    expect(DB::connection('pgsql')->table('media_variants')->where('media_id', $mediaId)->count())
        ->toBe(0);
});

it('continues past per-file delete failures and still clears DB rows', function () {
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('media_variants')->insert([
        'id' => (string) Str::uuid(),
        'media_id' => $mediaId,
        'variant_key' => 'optimized',
        'artifact_type' => 'mp4',
        'disk' => 'local',
        'path' => 'videos/test/media/optimized.mp4',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')
        ->once()
        ->with('videos/test/media')
        ->andReturn([
            'videos/test/media/optimized.mp4',
            'videos/test/media/poster.jpg',
            'videos/test/media/hls/optimized/playlist.m3u8',
        ]);

    // First delete blows up; remaining two succeed. The loop must NOT bail.
    $disk->shouldReceive('delete')
        ->with('videos/test/media/optimized.mp4')
        ->once()
        ->andThrow(new RuntimeException('per-file flake'));
    $disk->shouldReceive('delete')
        ->with('videos/test/media/poster.jpg')
        ->once()
        ->andReturn(true);
    $disk->shouldReceive('delete')
        ->with('videos/test/media/hls/optimized/playlist.m3u8')
        ->once()
        ->andReturn(true);

    Storage::shouldReceive('disk')
        ->once()
        ->andReturn($disk);

    $service = new VideoVariantService;
    $service->deleteVariants($mediaId, 'videos/test/media/original.mp4');

    expect(DB::connection('pgsql')->table('media_variants')->where('media_id', $mediaId)->count())
        ->toBe(0);
});

function bootstrapMediaJobsSchema(): void
{
    // Models reference 'site.site_media' / 'site.media_variants' (with schema
    // prefix). The shared helper in tests/Pest.php attaches the 'site' schema
    // and creates both tables with the production column set.
    setupMediaTables();
}

function seedSiteImageRow(string $mediaType): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => '',
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => $mediaType,
        'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}
