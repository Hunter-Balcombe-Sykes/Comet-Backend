<?php

/** @phpstan-ignore-all */

// Covers the unified affiliate gallery grid: one pool of 6 slots that can
// hold a mix of images and videos. Before this change, POST /images/reorder
// silently defaulted `media_type` to 'image', which dropped video ids from
// the reorder payload and corrupted the displayed order.
//
// Pool flipped gallery→content 2026-09-01 (Item 5 retired the gallery write
// lane from partna.upload_pools) — the mixed-type reorder scoping under test
// is pool-agnostic.

use App\Http\Controllers\Api\User\Uploads\UserUploadController;
use App\Http\Requests\Api\User\Uploads\ReorderPoolImagesRequest;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\Media\VideoVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    shimPgAdvisoryLockForSqlite();

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull();
    app()->instance(SiteCacheService::class, $cache);
});

function seedGalleryMediaRow(string $siteId, string $mediaType, int $sortOrder): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('site_media')->insert([
        'id' => $id,
        'site_id' => $siteId,
        'pool' => 'content',
        'path' => "{$mediaType}s/{$siteId}/{$id}/original.bin",
        'sort_order' => $sortOrder,
        'is_active' => true,
        'media_type' => $mediaType,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

function callReorderController(User $professional, array $body): JsonResponse
{
    $request = Request::create('/api/images/reorder', 'POST', $body);
    $request->attributes->set('professional', $professional);
    app()->instance('request', $request);

    $formRequest = ReorderPoolImagesRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));
    $formRequest->validateResolved();

    $mediaService = Mockery::mock(ImageVariantService::class);
    $videoVariant = Mockery::mock(VideoVariantService::class);
    app()->instance(ImageVariantService::class, $mediaService);
    app()->instance(VideoVariantService::class, $videoVariant);
    $controller = app(UserUploadController::class);

    return $controller->reorder($formRequest);
}

function seedUserAndSite(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $handle = 'mixed-reorder-'.Str::random(8);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Test Pro',
        'first_name' => 'Test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $handle,
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $professional = User::query()->findOrFail($userId);
    $professional->load('site');
    $site = Site::query()->findOrFail($siteId);

    return [$professional, $site];
}

it('reorders a mixed image+video gallery when media_type is omitted', function () {
    [$professional, $site] = seedUserAndSite();

    // Seed: [image A (0), video B (1), image C (2), video D (3)].
    $a = seedGalleryMediaRow($site->id, SiteMedia::MEDIA_TYPE_IMAGE, 0);
    $b = seedGalleryMediaRow($site->id, SiteMedia::MEDIA_TYPE_VIDEO, 1);
    $c = seedGalleryMediaRow($site->id, SiteMedia::MEDIA_TYPE_IMAGE, 2);
    $d = seedGalleryMediaRow($site->id, SiteMedia::MEDIA_TYPE_VIDEO, 3);

    // Move video D to the front, keep the rest in order: [D, A, B, C].
    $response = callReorderController($professional, [
        'pool' => 'content',
        'ids' => [$d, $a, $b, $c],
    ]);

    expect($response->getStatusCode())->toBe(200);

    $ordered = SiteMedia::query()
        ->where('site_id', $site->id)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    expect($ordered)->toBe([$d, $a, $b, $c]);
});

it('still scopes the reorder when media_type is explicitly provided', function () {
    [$professional, $site] = seedUserAndSite();

    // Seed: image A (0), image B (1), video C (2), video D (3).
    $a = seedGalleryMediaRow($site->id, SiteMedia::MEDIA_TYPE_IMAGE, 0);
    $b = seedGalleryMediaRow($site->id, SiteMedia::MEDIA_TYPE_IMAGE, 1);
    $c = seedGalleryMediaRow($site->id, SiteMedia::MEDIA_TYPE_VIDEO, 2);
    $d = seedGalleryMediaRow($site->id, SiteMedia::MEDIA_TYPE_VIDEO, 3);

    // Swap the two images only; videos must keep their relative order.
    $response = callReorderController($professional, [
        'pool' => 'content',
        'media_type' => 'image',
        'ids' => [$b, $a],
    ]);

    expect($response->getStatusCode())->toBe(200);

    $ordered = SiteMedia::query()
        ->where('site_id', $site->id)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    // Images swapped; C and D stay after them in original relative order.
    expect($ordered)->toBe([$b, $a, $c, $d]);
});

it('rejects a mixed reorder that includes an id from another site', function () {
    [$professionalA, $siteA] = seedUserAndSite();
    [, $siteB] = seedUserAndSite();

    $ownImage = seedGalleryMediaRow($siteA->id, SiteMedia::MEDIA_TYPE_IMAGE, 0);
    $foreignVideo = seedGalleryMediaRow($siteB->id, SiteMedia::MEDIA_TYPE_VIDEO, 0);

    // abort(403, …) throws HttpException inside the transaction — assert on that.
    expect(fn () => callReorderController($professionalA, [
        'pool' => 'content',
        'ids' => [$ownImage, $foreignVideo],
    ]))->toThrow(HttpException::class);
});
