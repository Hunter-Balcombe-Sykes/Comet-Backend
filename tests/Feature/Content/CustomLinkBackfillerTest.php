<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Migration\CustomLinkBackfiller;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Convergence Phase 3 (W5): the live `partna.custom_link` connections become
// link-kind content items through the slice-0b manual lane. Production code,
// idempotent, re-runnable (convergence invariant #4) — never a throwaway
// script.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

/**
 * One live custom-link connection, shaped like the dev rows: the payload is
 * the LinkCardScraper's snapshot (url/name/description/favicon/logo).
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $overrides
 */
function customLink(string $userId, array $payload = [], array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('site.platform_connections')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => 'partna.custom_link',
        'routing_class' => 'link',
        'resource_id' => 'link-'.substr(sha1($id), 0, 16),
        'resource_kind' => 'link',
        'payload' => json_encode(array_merge([
            'kind' => 'link',
            'url' => 'https://example.com/one',
            'name' => 'Example One',
            'description' => null,
            'favicon' => null,
            'logo' => null,
        ], $payload)),
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

/** The coord the hand-add lane would mint for the same URL. */
function linkCoord(string $url): string
{
    return 'manual:'.sha1(strtolower(trim($url)));
}

it('lands a live custom link as a link-kind item on the manual source', function () {
    [$userId] = seedUserWithSite();
    customLink($userId, ['url' => 'https://letaj.com.au/', 'name' => 'LeTaj']);

    $result = app(CustomLinkBackfiller::class)->run();

    expect($result['backfilled'])->toBe(1);

    $row = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->join('content.items as i', 'i.id', '=', 'si.item_id')
        ->where('si.coord', linkCoord('https://letaj.com.au/'))
        ->first(['cs.kind as source_kind', 'si.kind', 'i.headline_cache', 'i.removed_at', 'i.id as item_id']);

    expect($row->source_kind)->toBe('manual')
        ->and($row->kind)->toBe('link')
        ->and($row->headline_cache)->toBe('LeTaj')
        ->and($row->removed_at)->toBeNull();

    expect(DB::table('content.f_link')->where('item_id', $row->item_id)->value('url'))
        ->toBe('https://letaj.com.au/');
});

// The coord IS the fold-in seam: PoolItemCreateController derives the same
// sha1 from the URL, so an owner re-typing a migrated link updates the item
// they already have instead of minting a second one. A uuid coord would have
// made the two lanes permanently distinct — which is why the plan names
// `manual:{sha1(url)}` and not `manual:{connection_uuid}`.
it('mints the same coord the hand-add lane would', function () {
    [$userId] = seedUserWithSite();
    customLink($userId, ['url' => '  HTTPS://Example.com/One  ']);

    app(CustomLinkBackfiller::class)->run();

    expect(DB::table('content.source_items')->value('coord'))
        ->toBe('manual:'.sha1('https://example.com/one'));
});

// Two links, one URL, one user: the second write resolves to the item the
// first landed. Not merely tolerated — REQUIRED. Two manual coords carrying
// one URL poison it as a joining key for the entire resolution run
// (Resolver::poisonedKeys()), taking any connector item on the same URL down
// with them.
it('folds a duplicate URL onto one item rather than minting a second coord', function () {
    [$userId] = seedUserWithSite();
    customLink($userId, ['url' => 'https://example.com/dup', 'name' => 'First']);
    customLink($userId, ['url' => 'https://example.com/dup', 'name' => 'Second']);

    $result = app(CustomLinkBackfiller::class)->run();

    expect($result['backfilled'])->toBe(1)
        ->and($result['duplicate_url'])->toBe(1)
        ->and(DB::table('content.source_items')->count())->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(1);
});

// Same URL, two owners: a coord is scoped to the user's own manual source, so
// these are two items. 4 of the 23 dev rows are one shared URL.
it('keeps two owners of the same URL on separate items', function () {
    [$first] = seedUserWithSite();
    [$second] = seedUserWithSite();
    customLink($first, ['url' => 'https://abovetheground.co']);
    customLink($second, ['url' => 'https://abovetheground.co']);

    $result = app(CustomLinkBackfiller::class)->run();

    expect($result['backfilled'])->toBe(2)
        ->and(DB::table('content.items')->count())->toBe(2);
});

// Matches PoolItemCreateController's own fallback: a hand-add with no title
// shows the host. 2 of the 23 dev rows are showcase seeds with no name.
it('falls back to the URL host when the payload carries no name', function () {
    [$userId] = seedUserWithSite();
    customLink($userId, ['url' => 'https://www.nasa.gov/', 'name' => null]);

    app(CustomLinkBackfiller::class)->run();

    expect(DB::table('content.items')->value('headline_cache'))->toBe('www.nasa.gov');
});

it('carries the scraped description to f_text.body, and omits the row when there is none', function () {
    [$userId] = seedUserWithSite();
    customLink($userId, ['url' => 'https://a.example', 'description' => 'Melbourne pool hall']);
    customLink($userId, ['url' => 'https://b.example', 'description' => null]);

    app(CustomLinkBackfiller::class)->run();

    // Two f_text rows (both carry a headline); exactly one carries a body.
    $bodies = DB::table('content.f_text')->pluck('body')->all();
    expect($bodies)->toHaveCount(2)
        ->and(array_values(array_filter($bodies)))->toBe(['Melbourne pool hall']);
});

// The pool is fed by LIVE connections. A removed link must not reappear on the
// public wire under a new lane — that is the WS-B2 pathology slice 6 hit from
// the other side.
it('ignores a soft-deleted connection', function () {
    [$userId] = seedUserWithSite();
    customLink($userId, [], ['deleted_at' => now()->toDateTimeString()]);

    $result = app(CustomLinkBackfiller::class)->run();

    expect($result['backfilled'])->toBe(0)
        ->and(DB::table('content.items')->count())->toBe(0);
});

it('ignores every other pseudo-platform surface', function () {
    // Owner ruling 2026-08-14: order links, storefronts and reservations keep
    // their own lanes until Phase 6. Only custom links feed this pool.
    [$userId] = seedUserWithSite();
    customLink($userId, [], ['surface_key' => 'partna.order_link', 'routing_class' => 'ordering']);
    customLink($userId, [], ['surface_key' => 'partna.storefront', 'routing_class' => 'shop']);

    $result = app(CustomLinkBackfiller::class)->run();

    expect($result['backfilled'])->toBe(0)
        ->and(DB::table('content.items')->count())->toBe(0);
});

it('counts a payload with no URL rather than writing an item for it', function () {
    [$userId] = seedUserWithSite();
    customLink($userId, ['url' => '  ']);

    $result = app(CustomLinkBackfiller::class)->run();

    expect($result['skipped_no_url'])->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(0);
});

it('is idempotent across two runs', function () {
    [$userId] = seedUserWithSite();
    customLink($userId);

    app(CustomLinkBackfiller::class)->run();
    app(CustomLinkBackfiller::class)->run();

    expect(DB::table('content.source_items')->count())->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(1)
        ->and(DB::table('site.section_items')->count())->toBe(1);
});

it('writes nothing under dry run but still counts', function () {
    [$userId] = seedUserWithSite();
    customLink($userId);

    $result = app(CustomLinkBackfiller::class)->run(dryRun: true);

    expect($result['backfilled'])->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(0)
        ->and(DB::table('site.section_items')->count())->toBe(0);
});

it('limits a run to one user when asked', function () {
    [$mine] = seedUserWithSite();
    [$theirs] = seedUserWithSite();
    customLink($mine);
    customLink($theirs, ['url' => 'https://example.com/two']);

    $result = app(CustomLinkBackfiller::class)->run(userId: $mine);

    expect($result['backfilled'])->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(1);
});

// A links page is an ARRANGEMENT, not a feed: without pins the section's
// recency ordering would publish them in whatever order the migration wrote
// them. sort_order is the owner's arrangement and 17 of the 23 dev rows share
// the value 0, so created_at breaks the tie deterministically rather than
// leaving it to scan order.
it('pins in sort_order then created_at so the owner arrangement survives', function () {
    [$userId, $siteId] = seedUserWithSite();
    $third = customLink($userId, ['url' => 'https://c.example'], ['sort_order' => 5]);
    $second = customLink($userId, ['url' => 'https://b.example'], ['sort_order' => 0, 'created_at' => now()->toDateTimeString()]);
    $first = customLink($userId, ['url' => 'https://a.example'], ['sort_order' => 0, 'created_at' => now()->subDay()->toDateTimeString()]);

    app(CustomLinkBackfiller::class)->run();

    $ordered = DB::table('site.section_items as si')
        ->join('content.f_link as fl', 'fl.item_id', '=', 'si.item_id')
        ->where('si.state', 'pinned')
        ->orderBy('si.sort_key')
        ->pluck('fl.url')
        ->all();

    expect($ordered)->toBe(['https://a.example', 'https://b.example', 'https://c.example'])
        ->and([$first, $second, $third])->each->toBeString();
});

it('excludes an inactive link instead of pinning it', function () {
    [$userId] = seedUserWithSite();
    customLink($userId, [], ['is_active' => 0]);

    app(CustomLinkBackfiller::class)->run();

    $pin = DB::table('site.section_items')->first(['state', 'sort_key']);

    expect($pin->state)->toBe('excluded')
        ->and($pin->sort_key)->toBeNull();
});

it('invalidates all three cache lanes for each touched site', function () {
    // No CI check enforces this despite BuildState's docblock claiming one
    // (parent §9.1), so it is asserted directly. Backdated for the same
    // second-precision reason ServiceBackfillerTest documents.
    [$userId, $siteId] = seedUserWithSite();
    customLink($userId);
    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $beforeRevision = BuildState::read($siteId)['content_revision'];

    app(CustomLinkBackfiller::class)->run();

    // writeManualItem() bumps once on its own; invalidate() must bump AGAIN on
    // top of that, so ">0" would pass with the invalidate call deleted.
    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($beforeRevision + 2);
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

// Invariant #2 — a kind is not adopted until something READS it. Writer, pool
// entry and read path in one assertion: this is the exit criterion the plan
// names, exercised in-suite so a regression is red before it is live.
it('returns the migrated links through the pool read path', function () {
    [$userId, $siteId] = seedUserWithSite();
    customLink($userId, ['url' => 'https://letaj.com.au/', 'name' => 'LeTaj', 'description' => 'Indian restaurant']);

    app(CustomLinkBackfiller::class)->run();

    $pool = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'custom_links');

    expect($pool['selection'])->toHaveCount(1);
    $item = $pool['selection'][0];

    expect($item['kind'])->toBe('link')
        ->and($item['headline'])->toBe('LeTaj')
        ->and($item['url'])->toBe('https://letaj.com.au/')
        ->and($item['description'])->toBe('Indian restaurant')
        ->and($item['origin'])->toBe('manual')
        // Not a Latest-tag pool.
        ->and($pool['latestItemId'])->toBeNull()
        // Aggregates belong to reviews alone.
        ->and($pool['stats'])->toBeNull();
});

// The public payload builder loops PoolRegistry::POOLS, so the pool reaches
// the wire with no builder change — asserted rather than assumed, because
// "the loop covers it" is exactly the claim that would go stale.
it('publishes the pool on the public profile payload', function () {
    // The payload builder reads far more than the pool lane (PoolLaneTest's
    // own public-wire test carries the same five).
    setupMediaTables();
    setupContentSelectionTable();
    setupBlocksTable();
    setupServicesTable();
    setupDesignKitsTable();

    [$userId, $siteId] = seedUserWithSite();
    customLink($userId, ['url' => 'https://letaj.com.au/', 'name' => 'LeTaj']);

    app(CustomLinkBackfiller::class)->run();

    $site = Site::query()->findOrFail($siteId);
    $payload = app(IndividualProfilePayloadBuilder::class)->build(User::query()->findOrFail($userId), $site);

    // The resource casts pools to an object so an empty map serializes {}.
    $links = $payload['profile']['pools']->custom_links ?? null;

    expect($links)->not->toBeNull();
    expect(array_column($links['items'], 'headline'))->toBe(['LeTaj']);
    // The dashboard-only flag never ships publicly.
    expect($links['items'][0])->not->toHaveKey('selected');
});
