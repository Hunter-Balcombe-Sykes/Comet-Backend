<?php

/**
 * A2/C4: logo uploads (logo_full/logo_square) are now palette-scanned like
 * gallery images — ProcessLogoVariantsJob used to pass extractPalette:false
 * (stale rationale: "the palette factor never reads them" — that factor,
 * ImageryPaletteFactor, was retired with the whole contribution-ledger
 * machine). SiteAccentResolver (Task 13) is the new reader.
 */

use App\Jobs\ProcessLogoVariantsJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Media\LogoProcessorClient;
use App\Services\Media\LogoProcessorResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();
    ini_set('memory_limit', '-1');

    $testRoot = storage_path('framework/testing/disks/logo-palette-scan');
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => $testRoot,
    ]);
    if (! is_dir($testRoot)) {
        mkdir($testRoot, 0777, true);
    }
});

/** Real PNG bytes (solid warm colour) — mirrors MediaPaletteExtractionTest's fixture, as bytes not a path. */
function warmLogoPngBytes(int $w = 200, int $h = 200): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 224, 73, 31)); // #e0491f-ish, warm + saturated
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function seedLogoMediaRow(string $siteId, string $purpose): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id, 'site_id' => $siteId, 'pool' => 'design', 'purpose' => $purpose,
        'path' => '', 'sort_order' => 0, 'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE, 'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'original_mime' => 'image/png', 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('stores a dominant colour + palette for a scanned logo_full (real ImageVariantService, real PNG)', function () {
    $siteId = (string) Str::uuid();
    $imageId = seedLogoMediaRow($siteId, SiteMedia::PURPOSE_LOGO_FULL);
    $originalPath = "images/test/{$imageId}/original.png";
    Storage::disk('local')->put($originalPath, 'irrelevant-original-bytes');

    $client = Mockery::mock(LogoProcessorClient::class);
    $client->shouldReceive('process')->once()->andReturn(
        new LogoProcessorResult(warmLogoPngBytes(), null, ['width' => 200, 'height' => 200])
    );

    (new ProcessLogoVariantsJob($originalPath, $imageId, "images/test/{$imageId}", $siteId))
        ->handle(new ImageVariantService, $client);

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY)
        ->and($row->dominant_color)->toMatch('/^#[0-9a-f]{6}$/')
        ->and($row->palette)->toBeArray()
        ->and($row->palette['warm'])->toBeTrue();
});

it('stores a palette for logo_square too, not just logo_full', function () {
    $siteId = (string) Str::uuid();
    $imageId = seedLogoMediaRow($siteId, SiteMedia::PURPOSE_LOGO_SQUARE);
    $originalPath = "images/test/{$imageId}/original.png";
    Storage::disk('local')->put($originalPath, 'irrelevant-original-bytes');

    $client = Mockery::mock(LogoProcessorClient::class);
    $client->shouldReceive('process')->once()->andReturn(
        new LogoProcessorResult(warmLogoPngBytes(), null, ['width' => 200, 'height' => 200])
    );

    (new ProcessLogoVariantsJob($originalPath, $imageId, "images/test/{$imageId}", $siteId))
        ->handle(new ImageVariantService, $client);

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->dominant_color)->not->toBeNull();
});

it('requests palette extraction explicitly (extractPalette: true) from ImageVariantService', function () {
    $siteId = (string) Str::uuid();
    $imageId = seedLogoMediaRow($siteId, SiteMedia::PURPOSE_LOGO_FULL);
    $originalPath = "images/test/{$imageId}/original.png";
    Storage::disk('local')->put($originalPath, 'irrelevant-original-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->andReturn('local');
    $service->shouldReceive('processVariants')
        ->once()
        ->withArgs(fn ($originalTmpPath, $imageId, $basePath, $passedSiteId, $extractPalette) => $extractPalette === true)
        ->andReturn(['optimized' => new stdClass]);

    $client = Mockery::mock(LogoProcessorClient::class);
    $client->shouldReceive('process')->once()->andReturn(
        new LogoProcessorResult('transparent-png-bytes', null, ['width' => 120, 'height' => 80])
    );

    (new ProcessLogoVariantsJob($originalPath, $imageId, "images/test/{$imageId}", $siteId))
        ->handle($service, $client);
});
