<?php

use App\Models\Core\MediaVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Verifies that when MediaVariant::getUrlAttribute fails to resolve a disk URL,
// the exception is reported to Nightwatch (report($e)) before returning ''.
//
// Before the fix: Throwable caught + Log::warning only — Nightwatch blind.
// After: report($e) alongside the warning.

beforeEach(function () {
    setupMediaTables();
});

it('reports the exception when Storage::disk throws for an unknown disk', function () {
    Exceptions::fake();

    // With no media-disk URL to borrow, an unknown variant disk must surface
    // (report) the resolution failure rather than silently 500. (When a media
    // URL IS configured — the production case — getUrlAttribute falls back to
    // it instead; see the fast-path fallback test below.)
    config(['filesystems.disks.media.url' => '']);

    // Create a variant row with a disk name that has no config entry
    $mediaId = (string) Str::uuid();
    $variantId = (string) Str::uuid();

    DB::connection('pgsql')->statement(
        "INSERT INTO site.media_variants (id, media_id, variant_key, artifact_type, disk, path, created_at, updated_at)
         VALUES (?, ?, 'optimized', 'webp', 'nonexistent_disk_xyz', 'images/test.webp', ?, ?)",
        [$variantId, $mediaId, now()->toDateTimeString(), now()->toDateTimeString()]
    );

    // No 'url' key in config for this disk, AND no disk registered in the filesystem config.
    // Storage::disk('nonexistent_disk_xyz') will throw InvalidArgumentException.
    $variant = MediaVariant::find($variantId);

    // Accessing the url attribute triggers the try/catch
    $url = $variant->url;

    expect($url)->toBe('');

    Exceptions::assertReported(InvalidArgumentException::class);
});

// Regression: variant disks like 'public_dev' are bucket-aliases of the canonical
// 'media' disk (same R2 bucket), but Laravel Cloud injects the public bucket URL
// onto 'media' only — leaving the alias disk's url null. getUrlAttribute must then
// borrow the media disk URL so we emit the public CDN host, NOT the auth-only S3
// API endpoint (which 400s/403s for anonymous <img> requests and broke every
// hosted sitepage image until this fallback was added).
it('falls back to the media disk url when the variant disk has no configured url', function () {
    config(['filesystems.disks.media.url' => 'https://cdn.media.test']);
    // An alias disk with NO 'url' configured (mirrors prod 'public_dev').
    config(['filesystems.disks.aliasdisk' => ['driver' => 's3']]);

    $variant = new MediaVariant([
        'variant_key' => 'vector',
        'artifact_type' => 'svg',
        'disk' => 'aliasdisk',
        'path' => 'images/site/media/vector_abc123.svg',
    ]);

    expect($variant->url)->toBe('https://cdn.media.test/images/site/media/vector_abc123.svg');
});

it('prefers the variant disk url over the media disk url when both are set', function () {
    config(['filesystems.disks.media.url' => 'https://cdn.media.test']);
    config(['filesystems.disks.ownurldisk.url' => 'https://own.cdn.test']);

    $variant = new MediaVariant([
        'variant_key' => 'optimized',
        'artifact_type' => 'webp',
        'disk' => 'ownurldisk',
        'path' => 'images/site/media/optimized_def456.webp',
    ]);

    expect($variant->url)->toBe('https://own.cdn.test/images/site/media/optimized_def456.webp');
});
