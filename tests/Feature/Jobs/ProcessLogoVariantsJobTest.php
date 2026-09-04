<?php

/** @phpstan-ignore-all */

use App\Jobs\ProcessImageVariantsJob;
use App\Jobs\ProcessLogoVariantsJob;
use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\Exceptions\LogoProcessorException;
use App\Services\Media\ImageVariantService;
use App\Services\Media\LogoProcessorClient;
use App\Services\Media\LogoProcessorResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();

    $testRoot = storage_path('framework/testing/disks/process-logo-variants-job');
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => $testRoot,
    ]);

    if (! is_dir($testRoot)) {
        mkdir($testRoot, 0777, true);
    }
});

function seedLogoRow(?string $siteId = null): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => $siteId ?? (string) Str::uuid(),
        'usage' => 'design',
        'purpose' => 'logo_full',
        'path' => '',
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'original_mime' => 'image/png',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('removes the background, builds webp variants, stores the svg, and marks ready', function () {
    $imageId = seedLogoRow();
    $originalPath = "images/test/{$imageId}/original.png";
    Storage::disk('local')->put($originalPath, 'original-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andReturn(['optimized' => new stdClass]);

    $client = Mockery::mock(LogoProcessorClient::class);
    $client->shouldReceive('process')->once()->andReturn(
        new LogoProcessorResult('transparent-png-bytes', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z"/></svg>', ['width' => 120, 'height' => 80])
    );

    (new ProcessLogoVariantsJob($originalPath, $imageId, "images/test/{$imageId}"))->handle($service, $client);

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY);

    $svg = MediaVariant::query()->where('media_id', $imageId)->where('artifact_type', 'svg')->first();
    expect($svg)->not->toBeNull();
    expect($svg->variant_key)->toBe('vector');
    expect($svg->mime)->toBe('image/svg+xml');
    expect($svg->path)->toEndWith('.svg');
    expect(Storage::disk('local')->exists($svg->path))->toBeTrue();
});

it('does not store an svg variant when the processor returns none', function () {
    $imageId = seedLogoRow();
    $originalPath = "images/test/{$imageId}/original.png";
    Storage::disk('local')->put($originalPath, 'original-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andReturn(['optimized' => new stdClass]);

    $client = Mockery::mock(LogoProcessorClient::class);
    $client->shouldReceive('process')->once()->andReturn(
        new LogoProcessorResult('transparent-png-bytes', null, ['width' => 120, 'height' => 80])
    );

    (new ProcessLogoVariantsJob($originalPath, $imageId, "images/test/{$imageId}"))->handle($service, $client);

    expect(SiteMedia::query()->findOrFail($imageId)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY);
    expect(MediaVariant::query()->where('media_id', $imageId)->where('artifact_type', 'svg')->exists())->toBeFalse();
});

it('rethrows transient processor failures so Laravel retries them', function () {
    $imageId = seedLogoRow();
    $originalPath = "images/test/{$imageId}/original.png";
    Storage::disk('local')->put($originalPath, 'original-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->andReturn('local');
    $service->shouldNotReceive('processVariants');

    $client = Mockery::mock(LogoProcessorClient::class);
    $client->shouldReceive('process')->once()->andThrow(new LogoProcessorException('processor is down'));

    $job = new ProcessLogoVariantsJob($originalPath, $imageId, "images/test/{$imageId}");

    expect(fn () => $job->handle($service, $client))->toThrow(LogoProcessorException::class, 'processor is down');

    // Stays PROCESSING until retries are exhausted (failed() handles the terminal move).
    expect(SiteMedia::query()->findOrFail($imageId)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PROCESSING);
});

it('falls back to the standard image job on terminal failure so the logo still appears', function () {
    Queue::fake();
    $imageId = seedLogoRow();

    $job = new ProcessLogoVariantsJob("images/test/{$imageId}/original.png", $imageId, "images/test/{$imageId}");
    $job->failed(new RuntimeException('all retries exhausted'));

    // Reset to PENDING (not FAILED) so the fallback job can pick it up.
    expect(SiteMedia::query()->findOrFail($imageId)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PENDING);
    Queue::assertPushed(ProcessImageVariantsJob::class);
});

it('marks failed without a fallback when the original is missing', function () {
    Queue::fake();
    $imageId = seedLogoRow();
    // No original on disk.

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->andReturn('local');
    $service->shouldNotReceive('processVariants');

    $client = Mockery::mock(LogoProcessorClient::class);
    $client->shouldNotReceive('process');

    $job = new ProcessLogoVariantsJob("images/test/{$imageId}/original.png", $imageId, "images/test/{$imageId}");
    $job->withFakeQueueInteractions();
    $job->handle($service, $client);

    // JOB-3: also routed through fail() (Nightwatch visibility) — a fake
    // queue interaction records this without invoking the real failed().
    $job->assertFailed();

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);
    expect((string) $row->processing_error)->toContain('Original file not found');
    Queue::assertNotPushed(ProcessImageVariantsJob::class);
});

it('returns early when another worker holds the processing lock', function () {
    $imageId = seedLogoRow();
    // Simulate another worker holding the lock: acquire it here first (own
    // owner token) so the job's own Cache::lock()->get() fails to acquire.
    Cache::lock("logo:processing-lock:{$imageId}", 340)->get();

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldNotReceive('processVariants');
    $client = Mockery::mock(LogoProcessorClient::class);
    $client->shouldNotReceive('process');

    (new ProcessLogoVariantsJob("images/test/{$imageId}/original.png", $imageId, "images/test/{$imageId}"))
        ->handle($service, $client);

    expect(SiteMedia::query()->findOrFail($imageId)->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PENDING);
});
