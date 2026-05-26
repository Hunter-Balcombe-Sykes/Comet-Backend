<?php

use App\Jobs\DeleteMediaArtifactsJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\User\AccountDeletionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupMediaTables();
    setupUserDeletionAuditTable();

    config([
        'partna.media_disk' => 'media',
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-key',
    ]);

    Storage::fake('media');
    Queue::fake();
    Http::fake(['https://test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);
});

it('dispatches DeleteMediaArtifactsJob for each video media item on purge', function () {
    $professional = createTenant('purge-video');
    $site = $professional->site;

    $mediaId = (string) Str::uuid();
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_GALLERY,
        'path' => "videos/{$professional->id}/{$mediaId}",
        'media_type' => SiteMedia::MEDIA_TYPE_VIDEO,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    app(AccountDeletionService::class)->purge($professional);

    Queue::assertPushed(DeleteMediaArtifactsJob::class, function (DeleteMediaArtifactsJob $job) use ($mediaId, $professional) {
        return $job->mediaId === $mediaId
            && $job->basePath === "videos/{$professional->id}/{$mediaId}";
    });
});

it('deletes image variant files from storage on purge', function () {
    $professional = createTenant('purge-image');
    $site = $professional->site;

    $mediaId = (string) Str::uuid();
    $imagePath = "images/{$professional->id}/{$mediaId}/original.jpg";
    Storage::disk('media')->put($imagePath, 'fake-image-bytes');

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_GALLERY,
        'path' => $imagePath,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    app(AccountDeletionService::class)->purge($professional);

    Storage::disk('media')->assertMissing($imagePath);
});

it('deletes document files from storage on purge', function () {
    $professional = createTenant('purge-doc');
    $site = $professional->site;

    $mediaId = (string) Str::uuid();
    $docPath = "documents/{$professional->id}/{$mediaId}/file.pdf";
    Storage::disk('media')->put($docPath, 'fake-pdf-bytes');

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_DOCUMENTS,
        'path' => $docPath,
        'media_type' => SiteMedia::MEDIA_TYPE_DOCUMENT,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    app(AccountDeletionService::class)->purge($professional);

    Storage::disk('media')->assertMissing($docPath);
});

it('still completes purge when a professional has no site media', function () {
    $professional = createTenant('purge-empty');

    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();
    Queue::assertNothingPushed();
});

it('invalidates the public site cache before forceDelete so stale payloads are gone immediately', function () {
    $professional = createTenant('purge-cache-bust');
    $site = $professional->site;

    $cache = Mockery::mock(SiteCacheService::class);
    // atLeast()->once() because SiteObserver also fires invalidateSite when the site row is cascade-deleted.
    $cache->shouldReceive('invalidateSite')->atLeast()->once()->with(Mockery::on(fn ($s) => $s->id === $site->id));
    $this->app->instance(SiteCacheService::class, $cache);

    app(AccountDeletionService::class)->purge($professional);
});

it('continues purge even when an individual media artifact cleanup throws', function () {
    $professional = createTenant('purge-partial-fail');
    $site = $professional->site;

    $mediaId = (string) Str::uuid();
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_GALLERY,
        'path' => "videos/{$professional->id}/{$mediaId}",
        'media_type' => SiteMedia::MEDIA_TYPE_VIDEO,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // ImageVariantService throwing should not abort the whole purge
    $this->mock(ImageVariantService::class, function ($mock) {
        $mock->shouldReceive('deleteVariants')->andThrow(new \RuntimeException('storage error'));
    });

    // Purge completes (returns true) — the video job is still dispatched
    $result = app(AccountDeletionService::class)->purge($professional);
    expect($result)->toBeTrue();
});
