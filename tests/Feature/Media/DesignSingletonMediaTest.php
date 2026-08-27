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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // ActionCandidates reads the pools for item actions (was: the `custom:`
    // action family (convergence Phase 6), so site.sections must exist.
    setupSectionsTables();
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

it('rejects every retired cover purpose (feature removed 2026-08-05)', function () {
    foreach (['cover_youtube', 'cover_apple_music', 'cover_apple_podcast', 'cover_eventbrite', 'cover_shopify'] as $purpose) {
        $result = validateDesignMediaRequest(
            ['purpose' => $purpose],
            ['image' => UploadedFile::fake()->image('cover.png', 400, 200)],
        );

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toHaveKey('purpose');
    }
});

it('holds the design singleton purposes at 2 logos + placeholder + headshot (covers retired 2026-08-05; headshot added T17 2026-08-27)', function () {
    $purposes = SiteMedia::designSingletonPurposes();
    sort($purposes);

    $expected = ['headshot', 'logo_full', 'logo_square', 'placeholder'];
    sort($expected);

    expect($purposes)->toBe($expected);
});

it('accepts the headshot purpose (T17, 2026-08-27)', function () {
    $result = validateDesignMediaRequest(
        ['purpose' => 'headshot'],
        ['image' => UploadedFile::fake()->image('headshot.jpg', 400, 400)],
    );

    expect($result['errors'] ?? [])->not->toHaveKey('purpose');
});

it('accepts the brand placeholder purpose (singleton since 2026-07-10)', function () {
    $result = validateDesignMediaRequest(
        ['purpose' => 'placeholder'],
        ['image' => UploadedFile::fake()->image('placeholder.png', 400, 300)],
    );

    expect($result['errors'] ?? [])->not->toHaveKey('purpose');
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

// #TEST-1 / #LOG-1: the mid-store soft-delete race guarded by
// MediaUploadService::uploadSingleton()'s CONDITIONAL claim (~:215-230),
// distinct from DesignSingletonMediaConcurrencyTest's INSERT-time unique-
// violation race (createSingletonRowOrConflict, from 9534da25). This one
// fires AFTER the row exists, while storeOriginal() is still doing its R2
// round-trip: a concurrent replace/delete soft-deletes the row mid-store, and
// the conditional `whereKey(...)->whereNull('deleted_at')->update(...)`
// claims 0 rows instead of silently succeeding on a row no read can see.
it('409s and logs when the row is soft-deleted during storeOriginal (mid-store lost race)', function () {
    Queue::fake();
    Log::spy();

    $pro = createTenant('raceloser');
    $site = $pro->site;

    // storeOriginal's return simulates the concurrent winner: while THIS
    // request's original is (notionally) still being written to R2, another
    // request's purge/delete soft-deletes the row createSingletonRowOrConflict
    // just inserted for this (site, purpose). No pre-existing singleton is
    // seeded — this race needs only one in-flight upload plus one concurrent
    // deleter, not two competing uploads.
    $imageService = Mockery::mock(ImageVariantService::class);
    $imageService->shouldReceive('storeOriginal')->once()->andReturnUsing(function () use ($site) {
        SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', 'design')
            ->where('purpose', 'logo_full')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        return 'images/new/original.png';
    });
    $imageService->shouldReceive('deleteVariants')->once()->andReturnNull();
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

    expect($response->getStatusCode())->toBe(409);
    expect($response->getData(true)['code'] ?? null)->toBe('SINGLETON_UPLOAD_CONFLICT');

    // No live row for this purpose — the loser's row stayed soft-deleted, no
    // second row was created, and nothing 201'd for a row no read can see.
    expect(
        SiteMedia::query()->where('site_id', $site->id)->where('purpose', 'logo_full')->exists()
    )->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $m === 'Singleton upload lost a concurrent-replace race (conditional claim)'
            && $c['site_id'] === $site->id
            && $c['purpose'] === 'logo_full');
});

// ── Delete endpoint (2026-08-04: clear a slot without replacing it) ─────────

it('deletes a design singleton by purpose and purges its variants', function () {
    $pro = createTenant('deletehost');
    $site = $pro->site;

    $id = seedReadyDesignSingleton($site->id, 'placeholder', 'img/ph.webp');

    $imageService = Mockery::mock(ImageVariantService::class);
    $imageService->shouldReceive('deleteVariants')->once()->andReturnNull();
    app()->instance(ImageVariantService::class, $imageService);

    $request = Request::create('/api/design-media/placeholder', 'DELETE');
    $request->attributes->set('professional', $pro);

    $response = app(UserDesignMediaController::class)->destroy($request, 'placeholder');

    expect($response->getStatusCode())->toBe(200);
    expect(SiteMedia::withTrashed()->find($id)?->trashed())->toBeTrue();
    expect(
        SiteMedia::query()->where('site_id', $site->id)->where('purpose', 'placeholder')->exists()
    )->toBeFalse();
});

it('404s a delete for an empty slot and an unknown purpose', function () {
    $pro = createTenant('deleteempty');

    $request = Request::create('/api/design-media/placeholder', 'DELETE');
    $request->attributes->set('professional', $pro);

    // Empty slot: a real purpose with nothing uploaded.
    expect(
        app(UserDesignMediaController::class)->destroy($request, 'placeholder')->getStatusCode()
    )->toBe(404);

    // Unknown purposes: never a design singleton, plus the retired cover slots.
    expect(
        app(UserDesignMediaController::class)->destroy($request, 'gallery_image')->getStatusCode()
    )->toBe(404);
    expect(
        app(UserDesignMediaController::class)->destroy($request, 'cover_youtube')->getStatusCode()
    )->toBe(404);
});

// ── Read endpoint ───────────────────────────────────────────────────────────

it('reads back current design singletons by purpose (null for empty slots)', function () {
    $pro = createTenant('readhost');
    $site = $pro->site;

    seedReadyDesignSingleton($site->id, 'logo_full', 'img/logo.webp');
    // A leftover cover row from before the 2026-08-05 retirement: the read
    // must skip it, not resurrect the slot.
    seedReadyDesignSingleton($site->id, 'cover_youtube', 'img/yt.webp');

    $request = Request::create('/api/design-media', 'GET');
    $request->attributes->set('professional', $pro);

    $data = app(UserDesignMediaController::class)->index($request)->getData(true);

    expect($data['images']['logo_full']['url'])->toBe('https://cdn.example.com/img/logo.webp');
    expect($data['images']['logo_square'])->toBeNull();
    expect($data['images']['placeholder'])->toBeNull();
    expect($data['images'])->not->toHaveKey('cover_youtube'); // retired — not enumerated anymore
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

// Slice 7 unit E deleted the payload's `siteImages` map outright (owner ruling
// 2026-08-14 — apps/pages is rebuilt, not repaired). The resolver projection it
// was built from stays, and is what these two now assert against.
it('resolves design singletons excluding leftover pre-retirement cover rows', function () {
    $pro = createTenant('payloadhost');
    $site = $pro->site;

    seedReadyDesignSingleton($site->id, 'logo_full', 'img/logo.webp');
    // A leftover pre-retirement cover row must not survive the allowlist.
    seedReadyDesignSingleton($site->id, 'cover_apple_music', 'img/am.webp');

    $out = app(SitepageDataResolverService::class)->getDesignSingletons($site);

    expect($out['logo_full']['url'])->toBe('https://cdn.example.com/img/logo.webp');
    expect($out)->not->toHaveKey('cover_apple_music');

    // …and the public payload carries no siteImages key at all any more.
    $payload = app(IndividualProfilePayloadBuilder::class)->build($pro->fresh('site'), $site);
    expect(array_key_exists('siteImages', $payload))->toBeFalse();
});

it('resolves a placeholder-purpose row as a design singleton (gap fix 2026-07-10)', function () {
    // Pre-fix, purpose='placeholder' was outside designSingletonPurposes(), so a
    // stored placeholder image never reached the projection at all.
    $pro = createTenant('placeholderhost');
    $site = $pro->site;

    seedReadyDesignSingleton($site->id, 'placeholder', 'img/ph.webp');

    $out = app(SitepageDataResolverService::class)->getDesignSingletons($site);

    expect($out['placeholder']['url'])->toBe('https://cdn.example.com/img/ph.webp');
});

// ── Routes ──────────────────────────────────────────────────────────────────

it('registers the design-media routes', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    $get = $routes->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/design-media');
    $post = $routes->first(fn ($r) => in_array('POST', $r->methods()) && $r->uri() === 'api/design-media');
    $delete = $routes->first(fn ($r) => in_array('DELETE', $r->methods()) && $r->uri() === 'api/design-media/{purpose}');

    expect($get?->getActionName())->toContain('UserDesignMediaController@index');
    expect($post?->getActionName())->toContain('UserDesignMediaController@upload');
    expect($delete?->getActionName())->toContain('UserDesignMediaController@destroy');
});
