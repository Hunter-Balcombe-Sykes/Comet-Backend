<?php

use App\Http\Controllers\Api\User\Uploads\UserDesignMediaController;
use App\Http\Requests\Api\User\Uploads\UploadDesignMediaRequest;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    setupBlocksTable();
    setupServicesTable();
    setupDesignKitsTable();
    // Variant URL resolution reads the disk's configured base URL.
    config(['filesystems.disks.test_disk.url' => 'https://cdn.example.com']);
});

// Run a payload+files pair through the form request; return validity + errors.
function validateDesignMediaRequest(array $payload, array $files = []): array
{
    $request = Request::create('/test', 'POST', $payload, [], $files);
    $formRequest = UploadDesignMediaRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['valid' => true, 'errors' => []];
    } catch (ValidationException $e) {
        return ['valid' => false, 'errors' => $e->errors()];
    }
}

// Seed a ready design-pool singleton + its optimized webp variant; return id.
function seedReadyDesignSingleton(string $siteId, string $purpose, string $webpPath): string
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

    return $id;
}

// ── Request validation (the purpose allowlist is the boundary) ──────────────

it('accepts a logo upload with a valid purpose + image', function () {
    $result = validateDesignMediaRequest(
        ['purpose' => 'logo_full'],
        ['image' => UploadedFile::fake()->image('logo.png', 200, 200)],
    );

    expect($result['errors'] ?? [])->not->toHaveKeys(['purpose', 'image']);
});

it('accepts every integration cover purpose', function () {
    foreach (['cover_youtube', 'cover_apple_music', 'cover_apple_podcast', 'cover_eventbrite'] as $purpose) {
        $result = validateDesignMediaRequest(
            ['purpose' => $purpose],
            ['image' => UploadedFile::fake()->image('cover.png', 400, 200)],
        );

        expect($result['errors'] ?? [])->not->toHaveKey('purpose');
    }
});

it('rejects the retired cover_shopify slot', function () {
    $result = validateDesignMediaRequest(
        ['purpose' => 'cover_shopify'],
        ['image' => UploadedFile::fake()->image('x.png')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('purpose');
});

it('derives the design singleton purposes from the registry (2 logos + 4 covers, no dead shopify)', function () {
    $purposes = SiteMedia::designSingletonPurposes();
    sort($purposes);

    $expected = [
        'logo_full', 'logo_square',
        'cover_youtube', 'cover_apple_music', 'cover_apple_podcast', 'cover_eventbrite',
    ];
    sort($expected);

    expect($purposes)->toBe($expected);
    expect($purposes)->not->toContain('cover_shopify'); // retired dead slot — no shopify platform exists
});

it('rejects an unknown purpose', function () {
    $result = validateDesignMediaRequest(
        ['purpose' => 'cover_bogus'],
        ['image' => UploadedFile::fake()->image('x.png')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('purpose');
});

it('rejects a missing image', function () {
    $result = validateDesignMediaRequest(['purpose' => 'logo_full'], []);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('image');
});

it('rejects a non-image file', function () {
    $result = validateDesignMediaRequest(
        ['purpose' => 'logo_full'],
        ['image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('image');
});

// ── Singleton replace ───────────────────────────────────────────────────────

it('replaces the existing singleton of the same purpose on re-upload', function () {
    Queue::fake();

    $pro = createTenant('logohost');
    $site = $pro->site;

    // An existing logo_full that the re-upload must supersede.
    $oldId = seedReadyDesignSingleton($site->id, 'logo_full', 'img/old.webp');

    // Mock the image service: store returns a path, variant cleanup is a no-op.
    // The async variant job is faked (Queue::fake) so processing never runs.
    $imageService = Mockery::mock(ImageVariantService::class);
    $imageService->shouldReceive('storeOriginal')->andReturn('images/new/original.png');
    $imageService->shouldReceive('deleteVariants')->andReturnNull();
    $imageService->shouldReceive('resolvedDiskName')->andReturn('test_disk');
    app()->instance(ImageVariantService::class, $imageService);

    $base = Request::create('/api/design-media', 'POST', ['purpose' => 'logo_full'], [], [
        'image' => UploadedFile::fake()->image('logo.png', 200, 200),
    ]);
    $request = UploadDesignMediaRequest::createFromBase($base);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->attributes->set('professional', $pro);
    $request->validateResolved();

    $response = app(UserDesignMediaController::class)->upload($request);

    expect($response->getStatusCode())->toBe(201);

    // Old soft-deleted; exactly one active logo_full; it's a brand-new row.
    expect(SiteMedia::withTrashed()->find($oldId)?->trashed())->toBeTrue();

    $active = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', 'design')
        ->where('purpose', 'logo_full')
        ->get();

    expect($active)->toHaveCount(1);
    expect($active->first()->id)->not->toBe($oldId);
});

// ── Read endpoint ───────────────────────────────────────────────────────────

it('reads back current design singletons by purpose (null for empty slots)', function () {
    $pro = createTenant('readhost');
    $site = $pro->site;

    seedReadyDesignSingleton($site->id, 'logo_full', 'img/logo.webp');
    seedReadyDesignSingleton($site->id, 'cover_youtube', 'img/yt.webp');

    $request = Request::create('/api/design-media', 'GET');
    $request->attributes->set('professional', $pro);

    $data = app(UserDesignMediaController::class)->index($request)->getData(true);

    expect($data['images']['logo_full']['url'])->toBe('https://cdn.example.com/img/logo.webp');
    expect($data['images']['cover_youtube']['url'])->toBe('https://cdn.example.com/img/yt.webp');
    expect($data['images']['logo_square'])->toBeNull();
    expect($data['images']['cover_eventbrite'])->toBeNull(); // a still-live cover slot, empty
    expect($data['images'])->not->toHaveKey('cover_shopify'); // retired — not enumerated anymore
});

// ── Public payload exposure ─────────────────────────────────────────────────

it('resolves ready design singletons keyed by purpose with webp urls', function () {
    $pro = createTenant('reshost');
    $site = $pro->site;

    seedReadyDesignSingleton($site->id, 'logo_square', 'img/sq.webp');

    $out = app(SitepageDataResolverService::class)->getDesignSingletons($site);

    expect($out)->toHaveKey('logo_square');
    expect($out['logo_square']['url'])->toBe('https://cdn.example.com/img/sq.webp');
});

it('exposes design singletons as camelCase siteImages in the public profile payload', function () {
    $pro = createTenant('payloadhost');
    $site = $pro->site;

    seedReadyDesignSingleton($site->id, 'logo_full', 'img/logo.webp');
    seedReadyDesignSingleton($site->id, 'cover_apple_music', 'img/am.webp');

    $payload = app(IndividualProfilePayloadBuilder::class)->build($pro->fresh('site'), $site);

    expect($payload['siteImages']['logoFull']['url'])->toBe('https://cdn.example.com/img/logo.webp');
    expect($payload['siteImages']['coverAppleMusic']['url'])->toBe('https://cdn.example.com/img/am.webp');
});

// ── Routes ──────────────────────────────────────────────────────────────────

it('registers the design-media routes', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    $get = $routes->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/design-media');
    $post = $routes->first(fn ($r) => in_array('POST', $r->methods()) && $r->uri() === 'api/design-media');

    expect($get?->getActionName())->toContain('UserDesignMediaController@index');
    expect($post?->getActionName())->toContain('UserDesignMediaController@upload');
});
