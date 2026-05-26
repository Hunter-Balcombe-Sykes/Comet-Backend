<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\User\Uploads\UserUploadController;
use App\Http\Requests\Api\User\Uploads\UploadImageRequest;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\Media\ImageVariantService;
use App\Services\Media\VideoVariantService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupMediaTables();

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull();
    app()->instance(SiteCacheService::class, $cache);
});

it('returns 403 when video_uploads flag is off for the professional', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'display_name' => 'Flag Test Pro',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'flag-test-pro',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $professional = \App\Models\Core\User\User::query()->findOrFail($userId);
    $professional->load('site');

    // Flag service returns false for video_uploads
    $flagService = Mockery::mock(FeatureFlagService::class);
    $flagService->shouldReceive('enabled')
        ->with('video_uploads', Mockery::any())
        ->andReturn(false);
    app()->instance(FeatureFlagService::class, $flagService);

    $video = UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4');
    $baseRequest = Request::create('/api/uploads', 'POST', ['pool' => 'gallery'], [], ['video' => $video]);

    /** @var UploadImageRequest $request */
    $request = UploadImageRequest::createFromBase($baseRequest);
    $request->attributes->set('professional', $professional);

    $validator = Mockery::mock(Validator::class);
    $validator->shouldReceive('validated')->andReturn(['pool' => 'gallery']);
    $request->setValidator($validator);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $videoVariant = Mockery::mock(VideoVariantService::class);
    app()->instance(ImageVariantService::class, $mediaService);
    app()->instance(VideoVariantService::class, $videoVariant);
    $controller = app(UserUploadController::class);

    $response = $controller->upload($request);

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['message'] ?? null)
        ->toBe('Video uploads are not enabled for your account.');

    // No media row should have been created
    expect(SiteMedia::query()->count())->toBe(0);
});

it('allows image uploads regardless of video_uploads flag state', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'display_name' => 'Flag Test Pro 2',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'flag-test-pro-2',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $professional = \App\Models\Core\User\User::query()->findOrFail($userId);
    $professional->load('site');

    // Flag service returns false, but image uploads should not be blocked
    $flagService = Mockery::mock(FeatureFlagService::class);
    $flagService->shouldReceive('enabled')->with('video_uploads', Mockery::any())->andReturn(false);
    app()->instance(FeatureFlagService::class, $flagService);

    $image = UploadedFile::fake()->image('photo.jpg', 100, 100);
    $baseRequest = Request::create('/api/uploads', 'POST', ['pool' => 'gallery'], [], ['image' => $image]);

    /** @var UploadImageRequest $request */
    $request = UploadImageRequest::createFromBase($baseRequest);
    $request->attributes->set('professional', $professional);

    $validator = Mockery::mock(Validator::class);
    $validator->shouldReceive('validated')->andReturn(['pool' => 'gallery', 'alt_text' => null, 'caption' => null]);
    $request->setValidator($validator);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $mediaService->shouldReceive('resolvedDiskName')->andReturn('local');
    $mediaService->shouldReceive('storeOriginal')->andReturn('images/test/original.jpg');
    $videoVariant = Mockery::mock(VideoVariantService::class);

    app()->instance(ImageVariantService::class, $mediaService);
    app()->instance(VideoVariantService::class, $videoVariant);
    $controller = app(UserUploadController::class);

    $response = $controller->upload($request);

    // Should not be 403 (flag gate should not have fired for an image)
    expect($response->getStatusCode())->not->toBe(403);
});
