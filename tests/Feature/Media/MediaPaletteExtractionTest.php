<?php

/**
 * Coverage for ImageVariantService's palette extraction (#76 Part A):
 * processing a gallery image stores a dominant colour + {saturation, warm}
 * palette jsonb on the media row. The original consumer (ImageryPaletteFactor,
 * via IdentityEvidence::mediaPalette()) was retired with the integration
 * factor machine; SiteAccentResolver is the current reader (accent-colour
 * fallback chain).
 */

use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();

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
        'pool' => SiteMedia::POOL_CONTENT,
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
