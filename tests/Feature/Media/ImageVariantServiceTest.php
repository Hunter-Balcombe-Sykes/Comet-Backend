<?php

/** @phpstan-ignore-all */

use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();

    // Large GD fixtures (e.g. 6000x4000 = ~96 MB raw) exceed the default 128 MB
    // test limit. Unlock memory for this suite — image processing is memory-heavy
    // by nature and must be tested at realistic resolutions.
    ini_set('memory_limit', '-1');

    // Use a real local disk rooted in a unique test directory, mirroring the
    // pattern in tests/Unit/MediaJobReliabilityTest.php. Keeps filesystem state
    // inspectable and avoids Storage::fake() quirks around the media disk.
    $testRoot = storage_path('framework/testing/disks/image-variant-service');
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => $testRoot,
    ]);

    if (! is_dir($testRoot)) {
        mkdir($testRoot, 0777, true);
    }
    // No cross-test cleanup needed — each test seeds a fresh UUID, so
    // content-hashed output paths never collide across runs.
});

/**
 * Create a solid-color JPEG/PNG/WebP fixture at the requested dimensions.
 * Returns the absolute path to the temp file.
 */
function makeVariantFixture(int $width, int $height, string $format = 'jpeg'): string
{
    $img = imagecreatetruecolor($width, $height);
    $bg = imagecolorallocate($img, 200, 150, 100);
    imagefilledrectangle($img, 0, 0, $width, $height, $bg);

    $path = tempnam(sys_get_temp_dir(), 'sidest_variant_test_');
    match ($format) {
        'jpeg' => imagejpeg($img, $path, 90),
        'png' => imagepng($img, $path),
        'webp' => imagewebp($img, $path, 90),
    };
    imagedestroy($img);

    return $path;
}

function seedVariantTestMediaRow(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => '',
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_PROCESSING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

it('generates optimized and maximized variants for a normal-sized image', function () {
    $service = new ImageVariantService;
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(1000, 1000, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    expect($variants)->toHaveKeys(['optimized', 'maximized']);
    expect($variants['optimized']->path)->toStartWith("images/test/{$imageId}/optimized_");
    expect($variants['maximized']->path)->toStartWith("images/test/{$imageId}/maximized_");
    expect(Storage::disk('local')->exists($variants['optimized']->path))->toBeTrue();
    expect(Storage::disk('local')->exists($variants['maximized']->path))->toBeTrue();

    @unlink($fixture);
});

it('downscales variants when source exceeds the cap', function () {
    $service = new ImageVariantService;
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(6000, 4000, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    // optimized cap = 2400 long edge. 6000x4000 → ratio 0.4 → 2400x1600.
    expect($variants['optimized']->width)->toBe(2400);
    expect($variants['optimized']->height)->toBe(1600);

    // maximized cap = 4000 long edge. 6000x4000 → ratio 0.667 → 4000x2666 (rounded).
    expect($variants['maximized']->width)->toBe(4000);
    expect($variants['maximized']->height)->toBeGreaterThanOrEqual(2666);
    expect($variants['maximized']->height)->toBeLessThanOrEqual(2667);

    @unlink($fixture);
});

it('preserves aspect ratio for portrait images', function () {
    $service = new ImageVariantService;
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(3000, 4000, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    // optimized: 3000x4000 → ratio min(2400/3000, 2400/4000, 1) = 0.6 → 1800x2400
    expect($variants['optimized']->width)->toBe(1800);
    expect($variants['optimized']->height)->toBe(2400);

    // maximized: 3000x4000 → ratio min(4000/3000, 4000/4000, 1) = 1 → 3000x4000 (unchanged)
    expect($variants['maximized']->width)->toBe(3000);
    expect($variants['maximized']->height)->toBe(4000);

    @unlink($fixture);
});

it('leaves small images untouched when under both caps', function () {
    $service = new ImageVariantService;
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(800, 600, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    // min(2400/800, 2400/600, 1) = 1 → no upscale
    expect($variants['optimized']->width)->toBe(800);
    expect($variants['optimized']->height)->toBe(600);
    expect($variants['maximized']->width)->toBe(800);
    expect($variants['maximized']->height)->toBe(600);

    @unlink($fixture);
});

it('generates an optimized variant file under the target_kb ceiling', function () {
    $service = new ImageVariantService;
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(2000, 2000, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    $targetBytes = 500 * 1024;
    // Small solid-colour fixtures encode well under 500KB — this asserts the
    // binary-search quality path stayed wired up, not that targeting is tight.
    expect($variants['optimized']->file_size_bytes)->toBeLessThanOrEqual($targetBytes);

    @unlink($fixture);
});

it('throws UnprocessableImageException when pixel count exceeds the guard', function () {
    $service = new ImageVariantService;
    $imageId = seedVariantTestMediaRow();
    // 6000 × 5000 = 30 MP, above the 24 MP default ceiling.
    $fixture = makeVariantFixture(6000, 5000, 'jpeg');

    try {
        $service->processVariants(
            originalTmpPath: $fixture,
            imageId: $imageId,
            basePath: "images/test/{$imageId}",
        );
        throw new \RuntimeException('Expected UnprocessableImageException was not thrown');
    } catch (\App\Services\Media\UnprocessableImageException $e) {
        expect($e->getMessage())->toContain('exceed safe processing limit');
        expect($e->getMessage())->toContain('6000');
        expect($e->getMessage())->toContain('5000');
    } finally {
        @unlink($fixture);
    }

    // No MediaVariant rows should have been created.
    $variantCount = DB::connection('pgsql')
        ->table('site.media_variants')
        ->where('media_id', $imageId)
        ->count();
    expect($variantCount)->toBe(0);
});

it('respects the PARTNA_IMAGE_MAX_PIXELS env override', function () {
    config(['partna.image_max_pixels' => 1_000_000]); // 1 MP

    $service = new ImageVariantService;
    $imageId = seedVariantTestMediaRow();
    // 1200 × 1200 = 1.44 MP, above the configured 1 MP ceiling.
    $fixture = makeVariantFixture(1200, 1200, 'jpeg');

    try {
        $service->processVariants(
            originalTmpPath: $fixture,
            imageId: $imageId,
            basePath: "images/test/{$imageId}",
        );
        throw new \RuntimeException('Expected UnprocessableImageException was not thrown');
    } catch (\App\Services\Media\UnprocessableImageException $e) {
        expect($e->getMessage())->toContain('1000000');
    } finally {
        @unlink($fixture);
    }
});

it('storeOriginal stores a valid image via stream and returns the path', function () {
    $service = new ImageVariantService;
    $basePath = 'images/test/'.Str::uuid();
    $fixture = makeVariantFixture(400, 300, 'jpeg');

    $upload = new \Illuminate\Http\UploadedFile(
        $fixture,
        'photo.jpg',
        'image/jpeg',
        null,
        true, // test mode — bypasses moveUploadedFile()
    );

    $path = $service->storeOriginal($upload, $basePath);

    expect($path)->toStartWith("{$basePath}/original_");
    expect($path)->toEndWith('.jpg');
    expect(Storage::disk('local')->exists($path))->toBeTrue();
    // Stream-based upload must preserve the file byte-for-byte.
    expect(Storage::disk('local')->size($path))->toBe(filesize($fixture));

    @unlink($fixture);
});

it('storeOriginal rejects files whose bytes are not an allowed image MIME', function () {
    $service = new ImageVariantService;
    $basePath = 'images/test/'.Str::uuid();

    // Write a PHP script and disguise it as a JPEG via the client-supplied filename + MIME.
    $maliciousPath = tempnam(sys_get_temp_dir(), 'sidest_malicious_');
    file_put_contents($maliciousPath, "<?php echo 'pwn'; ?>\n");
    $upload = new \Illuminate\Http\UploadedFile(
        $maliciousPath,
        'evil.jpg',
        'image/jpeg', // CLIENT-supplied MIME — must NOT be trusted
        null,
        true,
    );

    try {
        $service->storeOriginal($upload, $basePath);
        throw new \RuntimeException('Expected UnprocessableImageException was not thrown');
    } catch (\App\Services\Media\UnprocessableImageException $e) {
        expect($e->getMessage())->toContain('not an accepted image format');
    } finally {
        @unlink($maliciousPath);
    }

    // Sniff rejection must occur BEFORE any disk write.
    expect(Storage::disk('local')->allFiles($basePath))->toBeEmpty();
});
