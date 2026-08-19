<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// GET /api/site/actions — the dashboard picker data source for the design
// page's "Smart actionable lander" toggle + manual reorder dialog: live pool
// + currently-served rankedActions + ordering settings (2026-07-23 rebuild:
// fixed-vocabulary pool, entries keyed by ActionVocabulary id).

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);
    setupBlocksTable();
    setupServicesTable();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupItemViewsTable();
    setupActionEventsTable();
    setupContentPopularityScoresTable();
    // SiteActionsService reads the `custom_links` pool for the `custom:`
    // action family (convergence Phase 6), so site.sections must exist.
    setupSectionsTables();
});

it('returns pool, rankedActions and ordering for the authed professional', function () {
    $pro = createTenant('actions-endpoint');
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $pro->site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'Instagram',
        'url' => 'https://instagram.com/x',
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'category' => 'social',
        'platform' => 'instagram',
        'settings' => json_encode([]),
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $pro->site->id,
        'content_type' => 'action',
        'content_key' => 'instagram',
        'score' => 0.77,
        'rank' => 1,
        'computed_at' => now()->toISOString(),
    ]);

    $response = actingAsUser($pro)->getJson('/api/site/actions')->assertOk();

    // success() responds with the body directly — no data envelope.
    $data = $response->json();
    $poolIds = collect($data['pool'])->pluck('id')->all();

    expect($poolIds)->toBe(['instagram'])
        // Pool entries carry their stored score when ranked.
        ->and(collect($data['pool'])->firstWhere('id', 'instagram')['score'])->toBe(0.77)
        ->and($data['rankedActions'][0]['id'])->toBe('instagram')
        ->and($data['ordering'])->toMatchArray(['smartPageOrder' => true, 'smartActions' => true]);
});

it('requires authentication', function () {
    createTenant('actions-noauth');

    $this->getJson('/api/site/actions')->assertStatus(401);
});

it('serves the feed preview beside the actions data', function () {
    $pro = createTenant('actions-feed-preview');

    $response = actingAsUser($pro)->getJson('/api/site/actions')->assertOk()->json();

    expect($response)->toHaveKey('feed');
    expect($response['feed'])->toHaveKeys(['mode', 'entries', 'manual']);
    expect($response['feed']['mode'])->toBe('newest');
    expect($response['feed']['manual'])->toBe([]);
});

// Pins spec §6's no-drift guarantee itself: the dashboard's feed.entries must
// come from the SAME publicPools() resolution the public payload serves, not
// merely have the right shape. poolTenant() + shopStore() + shopProduct() +
// poolPin() is the established fixture family (see
// tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php's feed
// tests) for landing one real, pinned content.* item that a pool actually
// serves — setupContentTables() (idempotent) brings in the source/collection/
// storefront tables this file's beforeEach doesn't otherwise need.
it('resolves feed.entries identically to the public payload for the same site', function () {
    setupContentTables();
    // The public profile endpoint also loads the design kit row + site media
    // (unrelated to feed resolution) — this file's beforeEach never needed
    // those tables until this test hits the public payload builder directly.
    setupDesignKitsTable();
    setupMediaTables();

    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

    $dashboardFeed = actingAsUser($pro)->getJson('/api/site/actions')->assertOk()->json('feed');
    $publicFeed = $this->getJson("/api/public/profiles/{$pro->handle}")->assertOk()->json('data.profile.feed');

    // A stub returning a hardcoded ['mode' => 'newest', 'entries' => [], 'manual' => []]
    // would pass the shape test above but fail here twice over: entries would
    // be empty (the real resolution over a pinned product is not), and even a
    // stub that happened to return a non-empty literal would still diverge
    // from whatever the public payload actually serves for this site.
    expect($dashboardFeed['entries'])->not->toBeEmpty();
    expect($dashboardFeed['mode'])->toBe($publicFeed['mode']);
    expect($dashboardFeed['entries'])->toBe($publicFeed['entries']);
});
