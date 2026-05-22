<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\Professional\Uploads\ProfessionalUploadController;
use App\Http\Requests\Api\Professional\Uploads\UploadImageRequest;
use App\Jobs\DeleteMediaArtifactsJob;
use App\Models\Core\Professional\User;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\Media\VideoVariantService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    bootstrapMediaUploadFailureSchema();
    setupFeatureFlagsTable();

    // ProfessionalUploadController gates video uploads behind the video_uploads
    // feature flag (FeatureFlagService::enabled). With an empty registry it
    // resolves via the config fallback — enable it so these tests reach the
    // actual upload-failure paths under test rather than a 403.
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => storage_path('framework/testing/disks/media-upload-failure'),
        'partna.features.video_uploads' => true,
    ]);

    if (! is_dir(config('filesystems.disks.local.root'))) {
        mkdir(config('filesystems.disks.local.root'), 0777, true);
    }

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull();
    app()->instance(SiteCacheService::class, $cache);
});

it('dispatches video cleanup with directory base path when deleting media', function () {
    Queue::fake();

    [$professional] = createProfessionalAndSiteForMediaUploadTests();

    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site_media')->insert([
        'id' => $mediaId,
        'site_id' => $professional->site->id,
        'pool' => 'gallery',
        'path' => "videos/{$professional->id}/{$mediaId}/original_abc123.mp4",
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_VIDEO,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $request = Request::create("/api/images/{$mediaId}", 'DELETE');
    $request->attributes->set('professional', $professional);
    app()->instance('request', $request);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $videoVariant = Mockery::mock(VideoVariantService::class);
    $controller = new ProfessionalUploadController($mediaService, $videoVariant);
    $siteImage = SiteMedia::query()->findOrFail($mediaId);

    // Controller signature was changed to (Request, SiteMedia) — test was
    // calling it with the legacy single-arg form.
    $response = $controller->destroy($request, $siteImage);

    expect($response->getStatusCode())->toBe(200);

    Queue::assertPushed(DeleteMediaArtifactsJob::class, function (DeleteMediaArtifactsJob $job) use ($professional, $mediaId) {
        return $job->mediaId === $mediaId
            && $job->basePath === "videos/{$professional->id}/{$mediaId}"
            && $job->pool === 'gallery';
    });

    $deleted = SiteMedia::withTrashed()->findOrFail($mediaId);
    expect($deleted->trashed())->toBeTrue();
});

it('returns 503 and soft-deletes media when video dispatch fails', function () {
    [$professional] = createProfessionalAndSiteForMediaUploadTests();

    config([
        'queue.default' => 'database',
        'partna.video_queue.connection' => 'missing_video_connection',
        'partna.video_uploads_enabled' => true,
    ]);

    app()->instance('env', 'production');

    $video = UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4');
    $baseRequest = Request::create('/api/uploads', 'POST', [
        'pool' => 'gallery',
        'alt_text' => 'Clip',
    ], [], [
        'video' => $video,
    ]);

    /** @var UploadImageRequest $request */
    $request = UploadImageRequest::createFromBase($baseRequest);
    $request->attributes->set('professional', $professional);

    $validator = Mockery::mock(Validator::class);
    $validator->shouldReceive('validated')->andReturn([
        'pool' => 'gallery',
        'alt_text' => 'Clip',
    ]);
    $request->setValidator($validator);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $mediaService->shouldReceive('resolvedDiskName')->andReturn('local');
    $mediaService->shouldReceive('safeExtension')->andReturn('mp4');

    $videoVariant = Mockery::mock(VideoVariantService::class);
    // Probe passes so we reach the dispatch step (where the 503 originates).
    $videoVariant->shouldReceive('probeAndValidate')->once()->andReturn([]);

    $controller = new ProfessionalUploadController($mediaService, $videoVariant);
    $response = $controller->upload($request);

    expect($response->getStatusCode())->toBe(503);
    expect($response->getData(true)['message'] ?? null)
        ->toBe('Video processing is temporarily unavailable. Please try again.');

    $media = SiteMedia::withTrashed()->latest('created_at')->first();
    expect($media)->not->toBeNull();
    expect($media->trashed())->toBeTrue();
    expect(SiteMedia::query()->count())->toBe(0);
    expect(Storage::disk('local')->exists($media->path))->toBeFalse();
});

it('returns 422 and creates no DB row when probe finds no video stream', function () {
    [$professional] = createProfessionalAndSiteForMediaUploadTests();

    config([
        'queue.default' => 'sync',
        'partna.video_uploads_enabled' => true,
    ]);

    $video = UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4');
    $baseRequest = Request::create('/api/uploads', 'POST', [
        'pool' => 'gallery',
        'alt_text' => 'Clip',
    ], [], ['video' => $video]);

    /** @var UploadImageRequest $request */
    $request = UploadImageRequest::createFromBase($baseRequest);
    $request->attributes->set('professional', $professional);

    $validator = Mockery::mock(Validator::class);
    $validator->shouldReceive('validated')->andReturn(['pool' => 'gallery', 'alt_text' => 'Clip']);
    $request->setValidator($validator);

    $mediaService = Mockery::mock(ImageVariantService::class);

    $videoVariant = Mockery::mock(VideoVariantService::class);
    $videoVariant->shouldReceive('probeAndValidate')
        ->once()
        ->andThrow(new \RuntimeException('File does not contain a recognisable video stream.'));

    $controller = new ProfessionalUploadController($mediaService, $videoVariant);
    $response = $controller->upload($request);

    expect($response->getStatusCode())->toBe(422);
    expect($response->getData(true)['message'] ?? null)
        ->toContain('Invalid video file');
    expect(SiteMedia::query()->count())->toBe(0);
});

it('returns 422 and creates no DB row when video exceeds maximum duration', function () {
    [$professional] = createProfessionalAndSiteForMediaUploadTests();

    config([
        'queue.default' => 'sync',
        'partna.video_uploads_enabled' => true,
    ]);

    $video = UploadedFile::fake()->create('long.mp4', 512, 'video/mp4');
    $baseRequest = Request::create('/api/uploads', 'POST', [
        'pool' => 'gallery',
        'alt_text' => 'Long clip',
    ], [], ['video' => $video]);

    /** @var UploadImageRequest $request */
    $request = UploadImageRequest::createFromBase($baseRequest);
    $request->attributes->set('professional', $professional);

    $validator = Mockery::mock(Validator::class);
    $validator->shouldReceive('validated')->andReturn(['pool' => 'gallery', 'alt_text' => 'Long clip']);
    $request->setValidator($validator);

    $mediaService = Mockery::mock(ImageVariantService::class);

    $videoVariant = Mockery::mock(VideoVariantService::class);
    $videoVariant->shouldReceive('probeAndValidate')
        ->once()
        ->andThrow(new \RuntimeException('Video is too long (400s). Maximum allowed duration is 300s.'));

    $controller = new ProfessionalUploadController($mediaService, $videoVariant);
    $response = $controller->upload($request);

    expect($response->getStatusCode())->toBe(422);
    expect($response->getData(true)['message'] ?? null)
        ->toContain('Invalid video file');
    expect(SiteMedia::query()->count())->toBe(0);
});

it('returns 422 and creates no DB row when ffprobe cannot parse the container', function () {
    [$professional] = createProfessionalAndSiteForMediaUploadTests();

    config([
        'queue.default' => 'sync',
        'partna.video_uploads_enabled' => true,
    ]);

    $video = UploadedFile::fake()->create('corrupt.mp4', 512, 'video/mp4');
    $baseRequest = Request::create('/api/uploads', 'POST', [
        'pool' => 'gallery',
        'alt_text' => 'Bad file',
    ], [], ['video' => $video]);

    /** @var UploadImageRequest $request */
    $request = UploadImageRequest::createFromBase($baseRequest);
    $request->attributes->set('professional', $professional);

    $validator = Mockery::mock(Validator::class);
    $validator->shouldReceive('validated')->andReturn(['pool' => 'gallery', 'alt_text' => 'Bad file']);
    $request->setValidator($validator);

    $mediaService = Mockery::mock(ImageVariantService::class);

    $videoVariant = Mockery::mock(VideoVariantService::class);
    $videoVariant->shouldReceive('probeAndValidate')
        ->once()
        ->andThrow(new \RuntimeException('ffprobe failed (exit 1): Invalid data found when processing input'));

    $controller = new ProfessionalUploadController($mediaService, $videoVariant);
    $response = $controller->upload($request);

    expect($response->getStatusCode())->toBe(422);
    expect($response->getData(true)['message'] ?? null)
        ->toContain('Invalid video file');
    expect(SiteMedia::query()->count())->toBe(0);
});

function bootstrapMediaUploadFailureSchema(): void
{
    // Models reference schema-qualified tables (core.users, site.sites,
    // site.site_media). The shared helpers in tests/Pest.php attach the right
    // schemas and create tables under them.
    setupProfessionalsTable();
    setupSitesTable();
    setupMediaTables();
}

/**
 *  array{0: User, 1: Site}
 */
function createProfessionalAndSiteForMediaUploadTests(): array
{
    $professionalId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $professionalId,
        'display_name' => 'Test Professional',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $professionalId,
        'subdomain' => 'test-pro',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $professional = User::query()->findOrFail($professionalId);
    $professional->load('site');
    $site = Site::query()->findOrFail($siteId);

    return [$professional, $site];
}
