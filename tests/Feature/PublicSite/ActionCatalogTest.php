<?php

use App\Catalog\LegacyPlatformMap;
use App\Services\PublicSite\SiteActionsService;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SiteActionsService::pool() — the fixed-vocabulary catalog. Each test proves
// one eligibility rule from the plan's table (docs/superpowers/plans/
// 2026-07-23-actions-rebuild-demand-rate.md). Pool entry shape:
// {id, kind, label, pageId, url, platform, sourceCreatedAt}.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();
    // SiteActionsService reads the `custom_links` pool for the `custom:`
    // action family (convergence Phase 6), so site.sections must exist —
    // and the D2 cases WRITE pool links now (LinkPoolWriter), so the
    // content/ingest lanes must too.
    setupSectionsTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    setupServicesTable();
});

function actionsPool(object $tenant): array
{
    $pro = $tenant->fresh(['site']);

    return app(SiteActionsService::class)->pool($pro, $pro->site);
}

function poolIds(object $tenant): array
{
    return collect(actionsPool($tenant))->pluck('id')->all();
}

function insertConnection(object $tenant, string $platform, array $payload = [], array $attrs = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.platform_connections')->insert(array_merge([
        'id' => $id,
        'user_id' => $tenant->id,
        // Retired legacy slugs map to null now — fall back to the slug so a
        // caller overriding surface_key/routing_class via $attrs still works.
        'surface_key' => $surface = (LegacyPlatformMap::surfaceFor($platform) ?? $platform),
        'routing_class' => LegacyPlatformMap::routingClassFor($surface),
        'resource_id' => 'r-'.Str::random(8),
        // Account rows carry a NULL resource_kind in prod — the discriminator is
        // only stamped for 'event' / 'link' rows (platform_connections_resource_kind_check).
        'resource_kind' => null,
        'payload' => json_encode($payload),
        'is_active' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ], $attrs));

    return $id;
}

function insertLinkBlockRow(object $tenant, array $attrs = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.blocks')->insert(array_merge([
        'id' => $id,
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'Link',
        'url' => 'https://example.com',
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'category' => 'social',
        'platform' => null,
        'settings' => json_encode([]),
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ], $attrs));

    return $id;
}

function insertBookingSectionBlock(object $tenant, string $url = 'https://fresha.com/book/x'): void
{
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'block_group' => 'sections',
        'block_type' => 'booking',
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'settings' => json_encode(['booking_url' => $url, 'platform' => 'fresha', 'title' => 'Book now']),
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);
}

function makeFoodBusiness(string $handle): object
{
    $tenant = createTenant($handle);
    DB::connection('pgsql')->table('core.users')->where('id', $tenant->id)->update([
        'account_type' => 'business',
        'sector' => 'restaurant',
    ]);

    return $tenant->fresh(['site']);
}

it('a bare site has an empty pool', function () {
    $tenant = createTenant('cat-bare');

    expect(actionsPool($tenant))->toBe([]);
});

it('menu and contact present → those two page entries with correct hrefs, nothing else', function () {
    $tenant = makeFoodBusiness('cat-menupage');
    DB::connection('pgsql')->table('core.users')->where('id', $tenant->id)->update(['sector' => 'restaurant']);
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'last_fetched_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'block_group' => 'sections',
        'block_type' => 'contact',
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);

    $pool = collect(actionsPool($tenant))->keyBy('id');

    expect($pool->keys()->all())->toEqualCanonicalizing(['menu', 'contact'])
        ->and($pool['menu'])->toMatchArray(['kind' => 'page', 'label' => 'Menu', 'pageId' => 'menu', 'url' => null])
        ->and($pool['contact'])->toMatchArray(['kind' => 'page', 'label' => 'Contact', 'pageId' => 'contact', 'url' => null]);
});

it('reservations: an opentable connection yields an external action linking to its own url', function () {
    $tenant = makeFoodBusiness('cat-resv');
    insertConnection($tenant, 'opentable', ['url' => 'https://opentable.com/r/maha', 'rid' => '123']);

    $pool = collect(actionsPool($tenant))->keyBy('id');

    expect($pool->has('reservations'))->toBeTrue()
        ->and($pool['reservations'])->toMatchArray([
            'kind' => 'external', 'label' => 'Reservations',
            'pageId' => null, 'url' => 'https://opentable.com/r/maha',
        ]);
});

it('D1: booking-services links straight to the booking url when services page is absent', function () {
    // getBooking() and presentPageIds()'s services-trigger both key off the
    // SAME 'booking' section block (SECTION_BLOCK_TO_PAGE: 'booking' =>
    // 'services'), so "booking live + services absent" can't be constructed
    // through real site data — it's a documented defensive fallback for if
    // that coupling ever changes. Exercise the branch directly via pool()'s
    // injectable $booking param, decoupled from the fixture's real presence.
    $tenant = createTenant('cat-d1-nopage');
    $pro = $tenant->fresh(['site']);
    $sections = app(SitepageDataResolverService::class)->loadSections($pro->site);
    $booking = ['state' => 'live', 'data' => ['resolved_url' => 'https://fresha.com/book/maha', 'title' => 'Book now', 'platform' => 'fresha']];

    $pool = collect(app(SiteActionsService::class)->pool($pro, $pro->site, $sections, $booking))->keyBy('id');

    expect($pool->has('booking-services'))->toBeTrue()
        ->and($pool['booking-services'])->toMatchArray([
            'kind' => 'external', 'label' => 'Booking and services',
            'pageId' => null, 'url' => 'https://fresha.com/book/maha',
        ]);
});

it('D1: booking-services is a page action to /services when the services page is present', function () {
    // Slice 3a §3.4: the services engine reads content.* now — seed the
    // legacy row and run the backfiller, the same route production's
    // owner-authored services took, rather than writing site.services and
    // expecting presence to see it directly (it no longer does).
    setupIngestTables();
    setupContentTables();

    $tenant = createTenant('cat-d1-page');
    ownerServiceItem($tenant->id, ['title' => 'Haircut']);

    $pool = collect(actionsPool($tenant))->keyBy('id');

    expect($pool['booking-services'])->toMatchArray(['kind' => 'page', 'pageId' => 'services']);
});

it('spotify connection yields an external action linking to its own profile url', function () {
    $tenant = createTenant('cat-spotify');
    insertConnection($tenant, 'spotify', ['url' => 'https://open.spotify.com/user/maha', 'link' => 'https://open.spotify.com/user/maha']);

    $pool = collect(actionsPool($tenant))->keyBy('id');

    expect($pool['spotify'])->toMatchArray([
        'kind' => 'external', 'label' => 'Spotify', 'platform' => 'spotify',
        'url' => 'https://open.spotify.com/user/maha',
    ]);
});

it('apple-music connection resolves its action from the connection link field', function () {
    $tenant = createTenant('cat-applemusic');
    insertConnection($tenant, 'apple-music', ['link' => 'https://music.apple.com/artist/maha']);

    $pool = collect(actionsPool($tenant))->keyBy('id');

    expect($pool['apple-music']['url'])->toBe('https://music.apple.com/artist/maha');
});

it('D4: a social connection wins over a platform-tagged link block for the same platform', function () {
    $tenant = createTenant('cat-d4-conn-wins');
    insertConnection($tenant, 'instagram', ['username' => 'maha_official']);
    insertLinkBlockRow($tenant, ['platform' => 'instagram', 'title' => 'IG', 'url' => 'https://instagram.com/stale-link']);

    $pool = collect(actionsPool($tenant))->keyBy('id');

    expect($pool['instagram']['url'])->toBe('https://www.instagram.com/maha_official');
});

it('D4: a platform-tagged link block alone still yields the social action', function () {
    $tenant = createTenant('cat-d4-block-only');
    insertLinkBlockRow($tenant, ['platform' => 'telegram', 'title' => 'Telegram', 'url' => 'https://t.me/maha']);

    $pool = collect(actionsPool($tenant))->keyBy('id');

    expect($pool->has('telegram'))->toBeTrue()
        ->and($pool['telegram']['url'])->toBe('https://t.me/maha');
});

it('D3: online-ordering actions come from connection entries only, gated by capability', function () {
    $tenant = makeFoodBusiness('cat-d3-ordering');
    // A router-seeded ordering row on its REAL brand surface — the
    // 'online-ordering' pseudo surface was retired 2026-08-19.
    $connId = insertConnection($tenant, 'uber_eats', [], ['resource_id' => 'order-uber', 'surface_key' => 'uber_eats.order', 'routing_class' => 'ordering']);
    DB::connection('pgsql')->table('site.platform_connections')->where('id', $connId)->update([
        'payload' => json_encode(['url' => 'https://ubereats.com/store/maha', 'name' => 'Uber Eats']),
    ]);
    insertLinkBlockRow($tenant, ['platform' => 'doordash', 'title' => 'DoorDash', 'url' => 'https://doordash.com/store/maha']);

    $pool = collect(actionsPool($tenant));
    $ordering = $pool->filter(fn ($a) => str_starts_with($a['id'], 'ordering:'));

    // Exactly one ordering action (from the connection entry) — the
    // doordash-tagged LINK BLOCK must NOT produce an ordering:* action (D3:
    // connection entries only, no link-block fallback for this family).
    expect($ordering)->toHaveCount(1)
        ->and($ordering->first()['id'])->toBe('ordering:order-uber')
        ->and($ordering->first())->toMatchArray(['kind' => 'external', 'label' => 'Uber Eats', 'url' => 'https://ubereats.com/store/maha']);
});

it('D3: online-ordering entries are excluded for a non-food-business account (capability gate)', function () {
    $tenant = createTenant('cat-d3-gated'); // plain partna — no online-ordering capability
    $connId = insertConnection($tenant, 'uber_eats', [], ['resource_id' => 'order-uber', 'surface_key' => 'uber_eats.order', 'routing_class' => 'ordering']);
    DB::connection('pgsql')->table('site.platform_connections')->where('id', $connId)->update([
        'payload' => json_encode(['url' => 'https://ubereats.com/store/x', 'name' => 'Uber Eats']),
    ]);

    $pool = collect(actionsPool($tenant));

    expect($pool->filter(fn ($a) => str_starts_with($a['id'], 'ordering:')))->toHaveCount(0);
});

it('D2: a custom link block and a same-URL POOL link dedupe to ONE action, block wins', function () {
    // The 'custom' pseudo connection was retired 2026-08-19 — the pool card
    // is the other custom-link lane now, same ??= dedupe, block still wins.
    $tenant = createTenant('cat-d2-dedupe');
    $blockId = insertLinkBlockRow($tenant, ['category' => 'custom', 'platform' => null, 'title' => 'My Site', 'url' => 'https://mysite.example']);
    app(\App\Services\Content\LinkPoolWriter::class)->add($tenant->refresh(), 'https://mysite.example', 'My Site (dup)', enrich: false);

    $pool = collect(actionsPool($tenant));
    $customs = $pool->filter(fn ($a) => str_starts_with($a['id'], 'custom:'));

    expect($customs)->toHaveCount(1)
        ->and($customs->first()['id'])->toBe('custom:'.$blockId)
        ->and($customs->first())->toMatchArray(['label' => 'My Site', 'url' => 'https://mysite.example']);
});

it('D2: a POOL link with a distinct URL yields its own separate action', function () {
    $tenant = createTenant('cat-d2-distinct');
    insertLinkBlockRow($tenant, ['category' => 'custom', 'platform' => null, 'title' => 'Site A', 'url' => 'https://a.example']);
    app(\App\Services\Content\LinkPoolWriter::class)->add($tenant->refresh(), 'https://b.example', 'Site B', enrich: false);

    $pool = collect(actionsPool($tenant));
    $customs = $pool->filter(fn ($a) => str_starts_with($a['id'], 'custom:'));

    expect($customs)->toHaveCount(2)
        ->and($customs->pluck('url')->sort()->values()->all())->toBe(['https://a.example', 'https://b.example']);
});

it('shop-tracks: present only via an active bandcamp connection', function () {
    $tenant = createTenant('cat-bandcamp');
    insertConnection($tenant, 'bandcamp', ['url' => 'https://maha.bandcamp.com']);

    $pool = collect(actionsPool($tenant))->keyBy('id');

    expect($pool->has('shop-tracks'))->toBeTrue()
        ->and($pool['shop-tracks'])->toMatchArray(['kind' => 'page', 'pageId' => 'shop-tracks']);
});

// Slice 2 Task 9 flipped Events presence from connection-derived to
// pool-derived: PLATFORM_TO_PAGE no longer maps eventbrite/humanitix, so the
// connection alone grants nothing. An UPCOMING event in the pool is now the
// whole qualification — which is the point, an organiser with nothing on
// should not advertise an empty Events page.
it('events: a ticketing connection alone no longer grants the action', function () {
    $tenant = createTenant('cat-events-empty');
    insertConnection($tenant, 'eventbrite', ['url' => 'https://eventbrite.com/o/maha']);

    expect(poolIds($tenant))->not->toContain('events');
});

it('events: present via an upcoming event in the pool, page action to /events', function () {
    setupContentTables();

    $tenant = createTenant('cat-events');
    $connectionId = insertConnection($tenant, 'eventbrite', ['url' => 'https://eventbrite.com/o/maha']);

    $sourceId = (string) Str::uuid();
    DB::connection('pgsql')->table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $tenant->id, 'kind' => 'connection',
        'connection_id' => $connectionId, 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $itemId = (string) Str::uuid();
    DB::connection('pgsql')->table('content.items')->insert([
        'id' => $itemId, 'user_id' => $tenant->id, 'kind' => 'event',
        'headline_cache' => 'Clothes swap', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('pgsql')->table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct:swap', 'item_id' => $itemId, 'kind' => 'event',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::connection('pgsql')->table('content.f_occurrence')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'starts_at_local' => now()->addDays(5)->toDateTimeString(),
        'starts_at_utc' => now()->addDays(5)->toDateTimeString(),
        'zone_confidence' => 'offset_only', 'is_all_day' => 0, 'updated_at' => now(),
    ]);

    $pool = collect(actionsPool($tenant))->keyBy('id');

    expect($pool['events'])->toMatchArray(['kind' => 'page', 'pageId' => 'events', 'url' => null]);
});

it('shop: present only when a chosen product exists on an active shop connection (FOUND-25 parity)', function () {
    $tenant = createTenant('cat-shop-empty');
    insertConnection($tenant, 'shop', []);

    // Connection alone, zero products — Shop must NOT be eligible.
    expect(collect(actionsPool($tenant))->pluck('id'))->not->toContain('shop');
});
