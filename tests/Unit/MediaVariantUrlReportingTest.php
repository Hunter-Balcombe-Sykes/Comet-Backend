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
