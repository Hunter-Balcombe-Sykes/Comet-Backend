<?php

use App\Http\Controllers\Api\User\SiteManagement\UserGalleryController;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupMediaTables();
    setupSubdomainAliasesTable();
});

it('allows the owner to delete their gallery image', function () {
    $owner = createTenant('gallery-destroy-owner');
    $site = $owner->site;

    $mediaId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $image = SiteMedia::query()->findOrFail($mediaId);

    // Mock storage calls so the test doesn't hit real R2.
    $this->instance(ImageVariantService::class, Mockery::mock(ImageVariantService::class, function ($mock) {
        $mock->shouldReceive('deleteVariants')->once()->andReturnNull();
    }));

    $req = tenantRequestAs($owner, [], 'DELETE');

    $response = app(UserGalleryController::class)->destroy($req, $image);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a non-owner from deleting a gallery image with 404', function () {
    $owner = createTenant('gallery-destroy-owner-2');
    $intruder = createTenant('gallery-destroy-intruder');
    $site = $owner->site;

    $mediaId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $image = SiteMedia::query()->findOrFail($mediaId);
    $req = tenantRequestAs($intruder, [], 'DELETE');

    try {
        app(UserGalleryController::class)->destroy($req, $image);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

it('blocks a pending-deletion owner from updating a gallery image with 423', function () {
    $owner = createTenant('gallery-update-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $owner->id)->update([
        'status' => 'pending_deletion',
    ]);
    $owner->refresh();

    $site = $owner->site;

    $mediaId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'alt_text' => 'Before',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $image = SiteMedia::query()->findOrFail($mediaId);

    // UpdateGalleryImageRequest — use FormRequest::createFrom on a base request
    $req = tenantRequestAs($owner, ['alt_text' => 'Hacked'], 'PATCH');

    try {
        app(UserGalleryController::class)->update(
            \App\Http\Requests\Api\User\ImageGallery\UpdateGalleryImageRequest::createFrom($req),
            $image
        );
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});
