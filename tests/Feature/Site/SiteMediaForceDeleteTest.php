<?php

use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Tests that SiteMedia::forceDelete() cleans up variant files from storage
// before the DB CASCADE fires. The forceDeleting hook collects variant paths
// while the relation is still intact, then deletes them from the storage disk.

beforeEach(function () {
    setupSitesTable();
    setupMediaTables();

    Storage::fake('media');

    config(['partna.media_disk' => 'media']);
});

it('deletes all variant storage files when a SiteMedia row is force-deleted', function () {
    $siteId = (string) Str::uuid();
    $mediaId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'test-site',
        'is_published' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'path' => "images/{$mediaId}/original.jpg",
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $optimizedPath = "images/{$mediaId}/optimized.webp";
    $maximizedPath = "images/{$mediaId}/maximized.webp";

    Storage::disk('media')->put($optimizedPath, 'fake-optimized-bytes');
    Storage::disk('media')->put($maximizedPath, 'fake-maximized-bytes');

    DB::connection('pgsql')->table('site.media_variants')->insert([
        [
            'id' => (string) Str::uuid(),
            'media_id' => $mediaId,
            'variant_key' => 'optimized',
            'artifact_type' => 'webp',
            'disk' => 'media',
            'path' => $optimizedPath,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => (string) Str::uuid(),
            'media_id' => $mediaId,
            'variant_key' => 'maximized',
            'artifact_type' => 'webp',
            'disk' => 'media',
            'path' => $maximizedPath,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $media = SiteMedia::query()->findOrFail($mediaId);
    $media->forceDelete();

    Storage::disk('media')->assertMissing($optimizedPath);
    Storage::disk('media')->assertMissing($maximizedPath);
});

it('removes the SiteMedia DB row itself after force-delete', function () {
    $siteId = (string) Str::uuid();
    $mediaId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'test-site-2',
        'is_published' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $media = SiteMedia::query()->findOrFail($mediaId);
    $media->forceDelete();

    expect(SiteMedia::withTrashed()->find($mediaId))->toBeNull();
});

it('deletes the original upload file when a SiteMedia row is force-deleted', function () {
    $siteId = (string) Str::uuid();
    $mediaId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'test-site-original',
        'is_published' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $originalPath = "images/{$mediaId}/original.jpg";

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'path' => $originalPath,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Storage::disk('media')->put($originalPath, 'fake-original-bytes');

    $media = SiteMedia::query()->findOrFail($mediaId);
    $media->forceDelete();

    Storage::disk('media')->assertMissing($originalPath);
});

it('does not throw when a variant file is already missing from storage', function () {
    $siteId = (string) Str::uuid();
    $mediaId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'test-site-3',
        'is_published' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Variant row points to a path that was never written to storage.
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(),
        'media_id' => $mediaId,
        'variant_key' => 'optimized',
        'artifact_type' => 'webp',
        'disk' => 'media',
        'path' => 'images/ghost/already-gone.webp',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $media = SiteMedia::query()->findOrFail($mediaId);

    // Must not throw.
    expect(fn () => $media->forceDelete())->not->toThrow(\Throwable::class);
});
