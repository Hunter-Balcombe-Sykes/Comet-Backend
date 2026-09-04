<?php

/** @phpstan-ignore-all */

/**
 * P3-28: verifies that MediaUploadService::storeOriginal() emits the structured
 * 'media.original_stored' breadcrumb (with media_id, disk, path) rather than the
 * old unstructured "Original stored successfully" message. This structured key lets
 * a GC tool query logs for orphan detection without PII leakage.
 */

use App\Models\Core\User\User;
use App\Services\Media\ImageVariantService;
use App\Services\Media\MediaUploadService;
use App\Services\Media\VideoVariantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupMediaTables();
    setupSitesTable();
    setupFeatureFlagsTable();

    $testRoot = storage_path('framework/testing/disks/media-breadcrumb');
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => $testRoot,
        'partna.upload_limits.gallery.max' => 50,
        'partna.features.video_uploads' => true,
    ]);

    if (! is_dir($testRoot)) {
        mkdir($testRoot, 0777, true);
    }
});

it('emits media.original_stored with media_id, disk, and path when an image is stored', function () {
    Log::spy();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'breadcrumb-test',
        'handle_lc' => 'breadcrumb-test',
        'display_name' => 'Breadcrumb Test',
        'first_name' => 'Breadcrumb',
        'primary_email' => 'breadcrumb@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'breadcrumb-test',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $pro = User::query()->with('site')->findOrFail($proId);
    $site = $pro->site;

    $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

    // The imageService is responsible for resolvedDiskName + storeOriginal.
    // Mock it to control the returned disk name and path without real I/O.
    $imageService = Mockery::mock(ImageVariantService::class);
    $imageService->shouldReceive('resolvedDiskName')->andReturn('local');
    $imageService->shouldReceive('storeOriginal')
        ->once()
        ->andReturn("images/{$proId}/test-media-id/original.jpg");
    // dispatchImageJob calls resolvedDiskName again on the service — allow it.
    $imageService->shouldReceive('deleteVariants')->byDefault()->andReturnNull();

    $videoVariant = Mockery::mock(VideoVariantService::class);

    $service = new MediaUploadService($imageService, $videoVariant);

    // dispatchImageJob dispatches synchronously in testing env — mock the job's
    // ImageVariantService dependency so processVariants doesn't actually run.
    app()->instance(ImageVariantService::class, $imageService);

    $service->upload(
        pro: $pro,
        site: $site,
        file: $file,
        usage: 'gallery',
        isVideo: false,
        altText: null,
        caption: null,
    );

    // The key assertion: the structured breadcrumb was logged with all three fields.
    Log::assertLogged('info', function ($message, $context) {
        return $message === 'media.original_stored'
            && isset($context['media_id'])
            && isset($context['disk'])
            && isset($context['path'])
            && $context['disk'] === 'local';
    });

    // Old unstructured key must NOT be logged.
    Log::assertNotLogged('info', fn ($msg) => $msg === 'Original stored successfully');
});
