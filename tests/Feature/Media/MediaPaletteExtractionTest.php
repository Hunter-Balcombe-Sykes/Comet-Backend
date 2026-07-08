<?php

/**
 * End-to-end coverage for #76 Part A: ImageVariantService extracts + stores a
 * colour palette during variant processing, IdentityEvidence::mediaPalette()
 * aggregates the stored rows, and ImageryPaletteFactor turns that into an
 * image-treatment nudge. Proves the previously-dormant factor is now LIVE.
 */

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\ImageryPaletteFactor;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Media\ImageVariantService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();
    ini_set('memory_limit', '-1');

    $testRoot = storage_path('framework/testing/disks/media-palette-extraction');
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => $testRoot,
    ]);
    if (! is_dir($testRoot)) {
        mkdir($testRoot, 0777, true);
    }
});

/** A solid-colour JPEG fixture on disk. Returns the temp path. */
function paletteImageFixture(int $r, int $g, int $b, int $w = 400, int $h = 400): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, $r, $g, $b));
    $path = tempnam(sys_get_temp_dir(), 'palette_e2e_');
    imagejpeg($img, $path, 95);

    return $path;
}

/** Seed a gallery image row and return [siteId, mediaId]. */
function seedPaletteMediaRow(string $siteId): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'path' => '',
        'sort_order' => 0,
        'is_active' => true,
        'processing_state' => SiteMedia::PROCESSING_STATE_PROCESSING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('stores a palette on the media row during variant processing', function () {
    $siteId = (string) Str::uuid();
    $imageId = seedPaletteMediaRow($siteId);
    $fixture = paletteImageFixture(230, 120, 60); // warm

    (new ImageVariantService)->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
        siteId: $siteId,
    );
    @unlink($fixture);

    $row = SiteMedia::query()->find($imageId);
    expect($row->dominant_color)->toMatch('/^#[0-9a-f]{6}$/')
        ->and($row->palette)->toBeArray()
        ->and($row->palette['warm'])->toBeTrue()
        ->and($row->palette['saturation'])->toBeGreaterThan(0.5);
});

it('a warm mid-saturation gallery lights up ImageryPaletteFactor with a warm treatment + bg tint', function () {
    $siteId = (string) Str::uuid();
    $imageId = seedPaletteMediaRow($siteId);
    // Warm hue (~30°) with MID saturation (~0.30): between the LOW (0.20) and
    // HIGH (0.65) thresholds, so the factor falls through to the warm branch
    // rather than the muted / "vibrant speaks for itself" branches.
    $fixture = paletteImageFixture(200, 170, 140);

    (new ImageVariantService)->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
        siteId: $siteId,
    );
    @unlink($fixture);

    $evidence = new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => $siteId]),
        new Collection,
        new Collection,
    );

    // mediaPalette() aggregates the stored row → the factor emits.
    $palette = $evidence->mediaPalette();
    expect($palette)->toMatchArray(['warm' => true])
        ->and($palette['saturation'])->toBeGreaterThan(0.20)
        ->and($palette['saturation'])->toBeLessThan(0.65);

    $out = (new ImageryPaletteFactor)->detect($evidence);
    expect($out['effect_image_treatment'])->toBe('warm')
        ->and($out['color_bg'])->toBe('#f7f4ee'); // warm light tint
});

it('a vivid gallery yields a high-saturation read → treatment none', function () {
    $siteId = (string) Str::uuid();
    $imageId = seedPaletteMediaRow($siteId);
    $fixture = paletteImageFixture(255, 20, 20); // vivid red, saturation >= 0.65

    (new ImageVariantService)->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
        siteId: $siteId,
    );
    @unlink($fixture);

    $evidence = new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => $siteId]),
        new Collection,
        new Collection,
    );

    // High saturation → "let the vibrant imagery speak" → treatment none.
    $out = (new ImageryPaletteFactor)->detect($evidence);
    expect($out)->toBe(['effect_image_treatment' => 'none']);
});

it('a low-saturation (grey) gallery yields a muted/mono treatment', function () {
    $siteId = (string) Str::uuid();
    $imageId = seedPaletteMediaRow($siteId);
    $fixture = paletteImageFixture(128, 128, 128); // neutral grey

    (new ImageVariantService)->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
        siteId: $siteId,
    );
    @unlink($fixture);

    $evidence = new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => $siteId]),
        new Collection,
        new Collection,
    );

    $out = (new ImageryPaletteFactor)->detect($evidence);
    // Very low saturation → mono; low → muted. Grey is ~0 → mono.
    expect($out['effect_image_treatment'])->toBeIn(['muted', 'mono']);
});

it('abstains cleanly when no gallery image has a palette', function () {
    $siteId = (string) Str::uuid();
    // A row with no palette (processing never ran / extraction produced nothing).
    seedPaletteMediaRow($siteId);

    $evidence = new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => $siteId]),
        new Collection,
        new Collection,
    );

    expect($evidence->mediaPalette())->toBe([])
        ->and((new ImageryPaletteFactor)->detect($evidence))->toBe([]);
});
