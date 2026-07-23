<?php

/** @phpstan-ignore-all */

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Media\UnprocessableImageException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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

it('reports (but does not fail the job on) a cache-purge dispatch failure (R3-OBS-2)', function () {
    // Queue::fake() never throws, so the dispatch-failure catch can only be
    // exercised by making the underlying Bus dispatcher itself throw —
    // CloudflareCachePurgeJob::dispatch() is the ONLY dispatch this job makes.
    Exceptions::fake();
    $this->mock(Dispatcher::class, function ($mock) {
        $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unreachable'));
    });

    $pro = createTenant('purgefailhost');
    $pro->site->forceFill(['subdomain' => 'purgefailhost'])->saveQuietly();
    $imageId = seedJobTestMediaRow('design', (string) $pro->site->id);
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andReturn([
        'optimized' => new stdClass,
        'maximized' => new stdClass,
    ]);

    (new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}"))->handle($service);

    // The purge failure must not fail the already-successful variant job.
    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'queue unreachable');
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

it('streams the original to a temp file (readStream) rather than loading it all into memory (get)', function () {
    // SCALE-5: the job must not call $disk->get() — that buffers the entire file
    // in PHP memory. It should instead open a read stream and pipe it to a local tmp.
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    // Spy on the Storage facade so we can assert get() is never called on this disk.
    $diskSpy = Mockery::spy(FilesystemAdapter::class);

    // The spy must delegate real readStream behaviour so the job can actually pipe.
    $realDisk = Storage::disk('local');
    $diskSpy->shouldReceive('readStream')
        ->once()
        ->with($originalPath)
        ->andReturnUsing(fn ($p) => $realDisk->readStream($p));

    $diskSpy->shouldReceive('exists')
        ->with($originalPath)
        ->andReturn(true);

    // Intercept Storage::disk('local') to return the spy.
    Storage::shouldReceive('disk')->with('local')->andReturn($diskSpy);

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andReturn([]);

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");
    $job->handle($service);

    // If get() were called it would appear in the spy's call list; asserting
    // readStream was called once is the positive confirmation of streaming.
    $diskSpy->shouldNotHaveReceived('get');
});

it('returns early without processing when the processing lock is already held by another worker', function () {
    // TEST-4: GuardsMediaProcessing::acquireProcessingLock calls
    //   Cache::lock($key, $timeout + 60)->get()
    // Simulate another worker holding it by acquiring the same key here first
    // (own owner token) so the job's own get() fails to acquire.
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";

    Cache::lock("image:processing-lock:{$imageId}", 180)->get();

    // The processing service must NOT be invoked when the lock cannot be acquired.
    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldNotReceive('processVariants');
    $service->shouldNotReceive('resolvedDiskName');

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");
    $job->handle($service);

    // The media row must remain in its original PENDING state — no mutation occurred.
    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PENDING);
});
