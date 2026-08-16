<?php

use App\Http\Controllers\Api\User\Uploads\UserDesignMediaController;
use App\Http\Requests\Api\User\Uploads\UploadDesignMediaRequest;
use App\Jobs\ProcessImageVariantsJob;
use App\Jobs\ProcessLogoVariantsJob;
use App\Services\Media\Exceptions\OriginalStoreFailedException;
use App\Services\Media\ImageVariantService;
use App\Services\Media\MediaUploadService;
use App\Services\Media\UnprocessableImageException;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // SiteActionsService reads the `custom_links` pool for the `custom:`
    // action family (convergence Phase 6), so site.sections must exist.
    setupSectionsTables();
    setupMediaTables();
    setupContentSelectionTable(); // payload's designMedia resolves the selection
    setupBlocksTable();
    setupServicesTable();
    setupDesignKitsTable();
    config(['filesystems.disks.test_disk.url' => 'https://cdn.example.com']);
});

// Bind a mocked ImageVariantService so uploadSingleton's storeOriginal succeeds
// without touching disk; the variant job itself is faked (Bus::fake) in routing tests.
function mockLogoImageService(): void
{
    $svc = Mockery::mock(ImageVariantService::class);
    $svc->shouldReceive('storeOriginal')->andReturn('images/new/original.png');
    $svc->shouldReceive('deleteVariants')->andReturnNull();
    $svc->shouldReceive('resolvedDiskName')->andReturn('test_disk');
    app()->instance(ImageVariantService::class, $svc);
}

function uploadDesignSingleton($pro, string $purpose, string $filename): void
{
    $base = Request::create('/api/design-media', 'POST', ['purpose' => $purpose], [], [
        'image' => UploadedFile::fake()->image($filename, 200, 200),
    ]);
    $request = UploadDesignMediaRequest::createFromBase($base);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->attributes->set('professional', $pro);
    $request->validateResolved();

    app(UserDesignMediaController::class)->upload($request);
}

// Seed a ready design-pool logo + its optimized webp variant, and optionally a
// vector (svg) variant; return the SiteMedia id.
function seedReadyLogo(string $siteId, string $purpose, string $webpPath, ?string $svgPath = null): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id, 'site_id' => $siteId, 'pool' => 'design', 'purpose' => $purpose,
        'path' => "images/{$purpose}.png", 'sort_order' => 0, 'is_active' => 1,
        'media_type' => 'image', 'processing_state' => 'ready',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $id, 'variant_key' => 'optimized',
        'artifact_type' => 'webp', 'disk' => 'test_disk', 'path' => $webpPath,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    if ($svgPath !== null) {
        DB::connection('pgsql')->table('site.media_variants')->insert([
            'id' => (string) Str::uuid(), 'media_id' => $id, 'variant_key' => 'vector',
            'artifact_type' => 'svg', 'disk' => 'test_disk', 'path' => $svgPath,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    return $id;
}

// ── SVG logo originals (signup-v2 B1: auto-grabbed inline-SVG header logos) ──
// The HTTP request layer (UploadDesignMediaRequest, mimes:jpeg,png,webp) never
// admits SVG — LogoAutoGrabber's direct uploadSingleton() call is the only SVG
// entry point, and it pre-sanitizes via svgIsSafe(). These tests exercise the
// service directly for exactly that reason.

function svgLogoUploadedFile(): UploadedFile
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80"><rect width="240" height="80" fill="#f50"/></svg>';

    return UploadedFile::fake()->createWithContent('logo.svg', $svg);
}

it('stores an svg logo original directly and routes it to the logo pipeline', function () {
    Bus::fake([ProcessLogoVariantsJob::class, ProcessImageVariantsJob::class]);
    Storage::fake('test_disk');
    config(['partna.logo_removal.enabled' => true]);

    // storeOriginal must NEVER run for the SVG branch — it's the raster-only
    // gate this branch exists to bypass.
    $svc = Mockery::mock(ImageVariantService::class);
    $svc->shouldReceive('storeOriginal')->never();
    $svc->shouldReceive('deleteVariants')->andReturnNull();
    $svc->shouldReceive('resolvedDiskName')->andReturn('test_disk');
    app()->instance(ImageVariantService::class, $svc);

    $pro = createTenant('svglogo');
    $pro->loadMissing('site');

    $media = app(MediaUploadService::class)->uploadSingleton(
        pro: $pro, site: $pro->site, file: svgLogoUploadedFile(), purpose: 'logo_full',
    );

    expect($media->path)->toEndWith('.svg');
    expect($media->original_mime)->toBe('image/svg+xml');
    Storage::disk('test_disk')->assertExists($media->path);
    Bus::assertDispatchedSync(ProcessLogoVariantsJob::class);
    Bus::assertNotDispatchedSync(ProcessImageVariantsJob::class);
});

it('sends an svg logo to the raster gate (which rejects) when the removal pipeline is off', function () {
    config(['partna.logo_removal.enabled' => false]);

    // Pipeline off → no rasterization path → the SVG must hit storeOriginal's
    // raster-only MIME gate exactly as before this feature existed.
    $svc = Mockery::mock(ImageVariantService::class);
    $svc->shouldReceive('storeOriginal')->once()->andThrow(
        new UnprocessableImageException("Rejected: MIME type 'image/svg+xml' is not an accepted image format.")
    );
    $svc->shouldReceive('deleteVariants')->andReturnNull();
    $svc->shouldReceive('resolvedDiskName')->andReturn('test_disk');
    app()->instance(ImageVariantService::class, $svc);

    $pro = createTenant('svgoff');
    $pro->loadMissing('site');

    expect(fn () => app(MediaUploadService::class)->uploadSingleton(
        pro: $pro, site: $pro->site, file: svgLogoUploadedFile(), purpose: 'logo_full',
    ))->toThrow(OriginalStoreFailedException::class);
});

it('sends an svg to the raster gate for non-logo singletons even with the pipeline on', function () {
    config(['partna.logo_removal.enabled' => true]);

    $svc = Mockery::mock(ImageVariantService::class);
    $svc->shouldReceive('storeOriginal')->once()->andThrow(
        new UnprocessableImageException("Rejected: MIME type 'image/svg+xml' is not an accepted image format.")
    );
    $svc->shouldReceive('deleteVariants')->andReturnNull();
    $svc->shouldReceive('resolvedDiskName')->andReturn('test_disk');
    app()->instance(ImageVariantService::class, $svc);

    $pro = createTenant('svgcover');
    $pro->loadMissing('site');

    expect(fn () => app(MediaUploadService::class)->uploadSingleton(
        pro: $pro, site: $pro->site, file: svgLogoUploadedFile(), purpose: 'placeholder',
    ))->toThrow(OriginalStoreFailedException::class);
});

// ── Dispatch routing (the feature flag + purpose decide the pipeline) ─────────

it('routes a logo through the removal pipeline when the flag is on', function () {
    Bus::fake([ProcessLogoVariantsJob::class, ProcessImageVariantsJob::class]);
    config(['partna.logo_removal.enabled' => true]);
    mockLogoImageService();

    uploadDesignSingleton(createTenant('routelogo'), 'logo_full', 'logo.png');

    Bus::assertDispatchedSync(ProcessLogoVariantsJob::class);
    Bus::assertNotDispatchedSync(ProcessImageVariantsJob::class);
});

it('keeps non-logo singletons on the standard pipeline even when the flag is on', function () {
    Bus::fake([ProcessLogoVariantsJob::class, ProcessImageVariantsJob::class]);
    config(['partna.logo_removal.enabled' => true]);
    mockLogoImageService();

    uploadDesignSingleton(createTenant('routecover'), 'placeholder', 'placeholder.png');

    Bus::assertDispatchedSync(ProcessImageVariantsJob::class);
    Bus::assertNotDispatchedSync(ProcessLogoVariantsJob::class);
});

it('keeps logos on the standard pipeline when the flag is off', function () {
    Bus::fake([ProcessLogoVariantsJob::class, ProcessImageVariantsJob::class]);
    config(['partna.logo_removal.enabled' => false]);
    mockLogoImageService();

    uploadDesignSingleton(createTenant('routeoff'), 'logo_full', 'logo.png');

    Bus::assertDispatchedSync(ProcessImageVariantsJob::class);
    Bus::assertNotDispatchedSync(ProcessLogoVariantsJob::class);
});

// ── SVG exposure (dashboard resource + public payload) ───────────────────────

it('exposes svg_url on the dashboard resource when a vector variant exists', function () {
    $pro = createTenant('svghost');
    seedReadyLogo($pro->site->id, 'logo_full', 'img/logo.webp', 'img/logo.svg');

    $request = Request::create('/api/design-media', 'GET');
    $request->attributes->set('professional', $pro);
    $data = app(UserDesignMediaController::class)->index($request)->getData(true);

    expect($data['images']['logo_full']['svg_url'])->toBe('https://cdn.example.com/img/logo.svg');
});

it('returns a null svg_url for a logo without a vector variant', function () {
    $pro = createTenant('nosvghost');
    seedReadyLogo($pro->site->id, 'logo_full', 'img/logo.webp', null);

    $request = Request::create('/api/design-media', 'GET');
    $request->attributes->set('professional', $pro);
    $data = app(UserDesignMediaController::class)->index($request)->getData(true);

    expect($data['images']['logo_full'])->toHaveKey('svg_url');
    expect($data['images']['logo_full']['svg_url'])->toBeNull();
});

it('exposes url_svg in the resolver and urlSvg in the public profile payload', function () {
    $pro = createTenant('pubsvghost');
    $site = $pro->site;
    seedReadyLogo($site->id, 'logo_full', 'img/logo.webp', 'img/logo.svg');

    $resolved = app(SitepageDataResolverService::class)->getDesignSingletons($site);
    expect($resolved['logo_full']['url_svg'])->toBe('https://cdn.example.com/img/logo.svg');

    $payload = app(IndividualProfilePayloadBuilder::class)->build($pro->fresh('site'), $site);
    expect($payload['siteImages']['logoFull']['urlSvg'])->toBe('https://cdn.example.com/img/logo.svg');
});
