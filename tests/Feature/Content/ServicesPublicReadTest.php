<?php

use App\Models\Core\Site\Site;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 3a §3.4: the services section renders from content.*, and a
// Fresha-sourced service must never appear in it (the kickoff's two-surface
// rule — the trap this slice exists to avoid).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

it('renders backfilled owner services in the owner ordering', function () {
    [$userId, $siteId] = seedUserWithSite();
    ownerServiceItem($userId, ['title' => 'Second', 'sort_order' => 1, 'price_cents' => 9000]);
    ownerServiceItem($userId, ['title' => 'First', 'sort_order' => 0, 'price_cents' => 5000]);

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);

    expect(array_column($data['services'], 'title'))->toBe(['First', 'Second']);
});

it('never renders a fresha-sourced service in the services section', function () {
    [$userId, $siteId] = seedUserWithSite();
    ownerServiceItem($userId, ['title' => 'Mine']);

    // A Fresha service item, landed as a connection source would land it.
    freshaServiceItem($userId, 'Fresha Cut');

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);

    expect(array_column($data['services'], 'title'))->toBe(['Mine']);
});

it('hides the services section when the owner has no live services', function () {
    [$userId, $siteId] = seedUserWithSite();
    ownerServiceItem($userId, ['deleted_at' => now()]);

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);

    expect($data['services'])->toBe([]);
});

// A service item landed by a CONNECTION source — what 3b's connector will
// produce. Written directly because 3a has no connector to run.
function freshaServiceItem(string $userId, string $title): string
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}', 'eligible_cache' => '{}',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'fresha:acct:s:999', 'item_id' => $itemId, 'kind' => 'service',
        'projector_version' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    return $itemId;
}
