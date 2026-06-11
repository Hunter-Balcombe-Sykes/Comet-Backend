<?php

/** @phpstan-ignore-all */

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Media\UnprocessableImageException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();

    $testRoot = storage_path('framework/testing/disks/process-image-variants-job');
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => $testRoot,
    ]);

    if (! is_dir($testRoot)) {
        mkdir($testRoot, 0777, true);
    }
});

function seedJobTestMediaRow(string $pool = 'gallery', ?string $siteId = null): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => $siteId ?? (string) Str::uuid(),
        'pool' => $pool,
        'path' => '',
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('marks the SiteMedia row as ready on successful processing', function () {
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andReturn([
        'optimized' => new stdClass,
        'maximized' => new stdClass,
    ]);

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");
    $job->handle($service);

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY);
    expect($row->processing_error)->toBeNull();
});

it('purges the sitepage edge cache when an image becomes ready', function () {
    // The ready transition is a query-builder update (bypasses SiteMediaObserver),
    // so the job must purge directly — otherwise a just-processed image / cover
    // doesn't appear until the s-maxage window lapses.
    $pro = createTenant('purgehost');
    $pro->site->forceFill(['subdomain' => 'purgehost'])->saveQuietly();
    $imageId = seedJobTestMediaRow('design', (string) $pro->site->id);
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andReturn([
        'optimized' => new stdClass,
        'maximized' => new stdClass,
    ]);

    Queue::fake();
    (new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}"))->handle($service);

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('fails immediately without retrying when processVariants throws UnprocessableImageException', function () {
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andThrow(
        new UnprocessableImageException('Image dimensions exceed safe processing limit (6000 x 5000 = 30000000 pixels, max 24000000).')
    );

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");
    $job->withFakeQueueInteractions();
    $job->handle($service);

    // The job must call $this->fail() internally — assertFailed() verifies that.
    $job->assertFailed();

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);
    expect((string) $row->processing_error)->toContain('exceed safe processing limit');
    expect((string) $row->processing_error)->toContain('6000');
});

it('rethrows transient failures so Laravel retries them normally', function () {
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andThrow(
        new RuntimeException('transient boom')
    );

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");

    // Generic Throwable is rethrown, which Laravel's queue worker would catch
    // and reschedule. We verify the rethrow and the in-progress processing state.
    expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'transient boom');

    $row = SiteMedia::query()->findOrFail($imageId);
    // State stays PROCESSING because markFailed only runs in the terminal failed() handler
    // for transient errors — the row moves to FAILED only after $tries is exhausted.
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PROCESSING);
});

it('records the guard error message in processing_error so the frontend can surface it', function () {
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andThrow(
        new UnprocessableImageException('Image dimensions exceed safe processing limit (8000 x 8000 = 64000000 pixels, max 24000000).')
    );

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");
    $job->withFakeQueueInteractions();
    $job->handle($service);

    $job->assertFailed();

    $row = SiteMedia::query()->findOrFail($imageId);
    $error = (string) $row->processing_error;
    expect($error)->not->toBeEmpty();
    expect($error)->toContain('8000');
    expect($error)->toContain('64000000');
    expect($error)->toContain('24000000');
});
