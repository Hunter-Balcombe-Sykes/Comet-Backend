<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\VideoVariantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
});

it('touches the parent Site updated_at when video variant processing completes', function () {
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId, 'handle' => 'touch-test', 'handle_lc' => 'touch-test',
        'display_name' => 'Touch Test', 'account_type' => 'individual',
        'status' => 'active',
        'created_at' => now()->subHour()->toDateTimeString(),
        'updated_at' => now()->subHour()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $proId, 'subdomain' => 'touch-test',
        'settings' => json_encode([]), 'is_published' => 1,
        'created_at' => now()->subHour()->toDateTimeString(),
        'updated_at' => now()->subHour()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId, 'site_id' => $siteId, 'user_id' => $proId,
        'pool' => 'content', 'media_type' => 'video',
        'processing_state' => 'processing', 'sort_order' => 0, 'is_active' => 1,
        'path' => 'videos/touch-test/orig.mp4',
        'created_at' => now()->subHour()->toDateTimeString(),
        'updated_at' => now()->subHour()->toDateTimeString(),
    ]);

    $siteUpdatedAtBefore = Site::query()->findOrFail($siteId)->updated_at;

    $service = app(VideoVariantService::class);
    $service->markReady(mediaId: $mediaId, durationMs: 5000, posterPath: 'videos/touch-test/poster.jpg');

    $siteUpdatedAtAfter = Site::query()->findOrFail($siteId)->updated_at;

    expect($siteUpdatedAtAfter)->not->toEqual($siteUpdatedAtBefore);
});
