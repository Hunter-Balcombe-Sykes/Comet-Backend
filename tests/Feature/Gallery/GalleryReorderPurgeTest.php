<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\User\SiteManagement\UserGalleryController;
use App\Http\Requests\Api\User\ImageGallery\ReorderGalleryImageRequest;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable(); // invalidateSite() queries site.site_subdomain_aliases
    setupMediaTables();
});

function seedGalleryReorderFixture(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'gallery-purge',
        'handle_lc' => 'gallery-purge',
        'display_name' => 'Gallery Purge',
        'account_type' => 'individual',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'gallery-purge',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $pro = User::query()->findOrFail($userId);
    $pro->load('site');

    $ids = [];
    foreach ([0, 1] as $sort) {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site_media')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'user_id' => $userId,
            'pool' => 'gallery',
            'path' => "images/{$siteId}/{$id}/original.webp",
            'sort_order' => $sort,
            'is_active' => true,
            'media_type' => 'image',
            'processing_state' => SiteMedia::PROCESSING_STATE_READY,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        $ids[] = $id;
    }

    return [$pro, $ids];
}

it('gallery reorder dispatches CloudflareCachePurgeJob via $site->touch()', function () {
    [$pro, $ids] = seedGalleryReorderFixture();
    Queue::fake();

    $request = Request::create('/api/gallery/reorder', 'POST', ['ids' => array_reverse($ids)]);
    $request->attributes->set('professional', $pro);
    app()->instance('request', $request);

    $formRequest = ReorderGalleryImageRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));
    $formRequest->validateResolved();

    app()->instance(ImageVariantService::class, Mockery::mock(ImageVariantService::class));
    $response = app(UserGalleryController::class)->reorder($formRequest);

    expect($response->getStatusCode())->toBe(200);
    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'gallery-purge';
    });
});
