<?php

use App\Models\Core\Site\Site;
use App\Services\Migration\StandaloneEventBackfiller;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 7 Phase 4 / parent §7 step 1: the live STANDALONE event connections
// (`resource_kind='event'` — one event added by URL from the Tickets & Events
// card) become `event`-kind content items through the slice-0b manual lane.
// Production code, idempotent, re-runnable (convergence invariant #4) — never
// a throwaway script.
//
// Slice 2 left these rows publishing their full payload on the integrations
// wire precisely because they had no pool representation. This is what gives
// them one, so Task 15 can empty that payload without it being a data-loss
// event.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

/**
 * One live standalone-event connection, shaped like the two dev rows: the
 * payload IS the event (EventsPayload::standalonePayload), stored under the
 * scraping platform with resource_id `event-<hex>`.
 *
 * @param  array<string, mixed>  $event
 * @param  array<string, mixed>  $overrides
 */
function sevConnection(string $userId, array $event = [], array $overrides = []): string
{
    $id = (string) Str::uuid();
    $payload = array_merge([
        'kind' => 'event',
        'id' => substr(sha1($id), 0, 16),
        'name' => 'Nerve Melbourne 2026',
        'link' => 'https://www.eventbrite.com/e/nerve-melbourne-2026-tickets-949590128637',
        'image' => 'https://img.evbuc.com/cover.jpg',
        'price' => 'AUD 16.97 – 398.03',
        'venue' => 'Brown Alley',
        'endsAt' => '2026-12-19T05:00:00+11:00',
        'endDate' => '2026-12-19T05:00:00+11:00',
        'soldOut' => false,
        'currency' => 'AUD',
        'location' => 'Melbourne',
        'priceMin' => 16.97,
        'startsAt' => '2026-11-26T22:00:00+11:00',
        'startDate' => '2026-11-26T22:00:00+11:00',
        'description' => 'Hard Techno & Hard Bounce.',
        'availability' => 'available',
    ], $event);

    DB::connection('pgsql')->table('site.platform_connections')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        // `platform` is a GENERATED alias of surface_key — never inserted.
        'surface_key' => 'eventbrite.organiser',
        'routing_class' => 'events',
        'resource_id' => 'event-'.$payload['id'],
        'resource_kind' => 'event',
        'payload' => json_encode($payload),
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

/** The coord the hand-add lane mints for the same URL (ManualEventWriter::coordFor). */
function sevCoord(string $url): string
{
    return 'manual:'.sha1(strtolower(trim($url)));
}

it('lands a live standalone event as an event-kind item on the manual source', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId);

    $result = app(StandaloneEventBackfiller::class)->run();

    expect($result['backfilled'])->toBe(1);

    $row = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->join('content.items as i', 'i.id', '=', 'si.item_id')
        ->where('si.coord', sevCoord('https://www.eventbrite.com/e/nerve-melbourne-2026-tickets-949590128637'))
        ->first(['cs.kind as source_kind', 'si.kind', 'i.headline_cache', 'i.removed_at', 'i.id as item_id']);

    expect($row->source_kind)->toBe('manual')
        ->and($row->kind)->toBe('event')
        ->and($row->headline_cache)->toBe('Nerve Melbourne 2026')
        ->and($row->removed_at)->toBeNull();
});

// The whole point of adopting the pool: the legacy payload's flat date/venue/
// price fields become the facets the events pool actually reads. Without
// f_occurrence the pool's `upcoming_occurrence` rule cannot see the event at
// all, and Task 15 would empty the wire in exchange for nothing.
it('carries the occurrence, place, description and price onto facets', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId);

    app(StandaloneEventBackfiller::class)->run();

    $itemId = (string) DB::table('content.items')->value('id');

    $occurrence = DB::table('content.f_occurrence')->where('item_id', $itemId)->first();
    expect($occurrence->starts_at_local)->toBe('2026-11-26T22:00:00+11:00')
        ->and($occurrence->ends_at_local)->toBe('2026-12-19T05:00:00+11:00')
        ->and($occurrence->starts_at_utc)->toBe('2026-11-26T11:00:00Z')
        ->and($occurrence->zone_confidence)->toBe('offset_only');

    $place = DB::table('content.f_place')->where('item_id', $itemId)->first();
    expect($place->venue_name)->toBe('Brown Alley')
        ->and($place->locality)->toBe('Melbourne');

    expect(DB::table('content.f_text')->where('item_id', $itemId)->value('body'))
        ->toBe('Hard Techno & Hard Bounce.');

    $offer = DB::table('content.offers')->where('item_id', $itemId)->first();
    expect((int) $offer->amount_minor)->toBe(1697)
        ->and($offer->currency)->toBe('AUD')
        ->and($offer->qualifier)->toBe('from');
});

// Phase 3 declined to mint content.media_assets for third-party image URLs
// (LinkPoolWriter), and EventsCatalog::storeCustom inherited that ruling. A
// migration is not the place to reopen it: an evbuc.com URL is a hotlink with
// its own expiry, not an asset this platform owns.
it('mints a cover media asset for the scraped event image, like the connector lane does', function () {
    // Was "does not mint" under Phase 3's no-third-party-image ruling.
    // Reversed 2026-08-18: LinkPoolWriter already mints og:image for links,
    // SchemaOrgEventProjector always minted the event image, and a
    // hand-added / bio-found event was the one shape left without a
    // picture. Same source_url-only asset, role `cover`.
    [$userId] = seedUserWithSite();
    sevConnection($userId);

    app(StandaloneEventBackfiller::class)->run();

    expect(DB::table('content.item_media')->where('role', 'cover')->count())->toBe(1)
        ->and(DB::table('content.media_assets')->count())->toBe(1);
});

// The coord IS the fold-in seam. §1.7's one-coord-per-canonical-URL rule: an
// owner re-adding the same event URL by hand updates the item they already
// have. A uuid coord would fork the two lanes permanently, and two manual
// coords carrying ONE url poison it as a joining key for the whole resolution
// run (Resolver::poisonedKeys).
it('mints the same coord the hand-add lane would', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId, ['link' => '  HTTPS://Example.com/Event  ']);

    app(StandaloneEventBackfiller::class)->run();

    expect(DB::table('content.source_items')->value('coord'))
        ->toBe('manual:'.sha1('https://example.com/event'));
});

it('folds a duplicate URL onto one item rather than minting a second coord', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId, ['link' => 'https://example.com/dup', 'name' => 'First']);
    sevConnection($userId, ['link' => '  https://example.com/dup  ', 'name' => 'Second']);

    $result = app(StandaloneEventBackfiller::class)->run();

    expect($result['backfilled'])->toBe(1)
        ->and($result['duplicate_url'])->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(1);
});

// An active standalone event PUBLISHED on the legacy wire regardless of its
// dates — PublicIntegrationConnectionResource never filtered on them. A pin is
// what reproduces that faithfully: the pool's `upcoming_occurrence` rule alone
// would silently drop a long-running or already-started event (one of the two
// dev rows starts in 2024 and ends in December 2026), turning Task 15 into a
// partial blackout instead of a migration.
it('pins the migrated event so it publishes exactly as it did before', function () {
    [$userId, $siteId] = seedUserWithSite();
    sevConnection($userId, ['startDate' => '2024-07-26T22:00:00+10:00', 'startsAt' => '2024-07-26T22:00:00+10:00']);

    app(StandaloneEventBackfiller::class)->run();

    expect(DB::table('site.section_items')->value('state'))->toBe('pinned');

    $selection = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'events')['selection'];
    expect($selection)->toHaveCount(1);
});

// is_active=false is the owner hiding an event. It maps to a pool EXCLUDE, not
// a missing pin — an unpinned item would be re-selected by the section's rule
// and republished on the new lane, which is the pathology slice 6 hit from the
// other side.
it('excludes an inactive row rather than leaving it unpinned', function () {
    [$userId, $siteId] = seedUserWithSite();
    sevConnection($userId, [], ['is_active' => 0]);

    app(StandaloneEventBackfiller::class)->run();

    expect(DB::table('site.section_items')->value('state'))->toBe('excluded')
        ->and(app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'events')['selection'])
        ->toBe([]);
});

it('is idempotent across two runs', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId);

    app(StandaloneEventBackfiller::class)->run();
    app(StandaloneEventBackfiller::class)->run();

    expect(DB::table('content.source_items')->count())->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(1)
        ->and(DB::table('site.section_items')->count())->toBe(1);
});

// The command is MEANT to be re-run — until Phase 6 retires the per-platform
// add verb it is how an event added there lands — so a second run must not
// restate its opinion over the owner's.
it('leaves the owner\'s later exclusion alone on a re-run', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId);

    app(StandaloneEventBackfiller::class)->run();
    DB::table('site.section_items')->update(['state' => 'excluded', 'sort_key' => null]);

    $result = app(StandaloneEventBackfiller::class)->run();

    expect($result['already_curated'])->toBe(1)
        ->and(DB::table('site.section_items')->value('state'))->toBe('excluded');
});

it('writes nothing under --dry-run', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId);

    $result = app(StandaloneEventBackfiller::class)->run(dryRun: true);

    expect($result['backfilled'])->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(0)
        ->and(DB::table('content.source_items')->count())->toBe(0)
        ->and(DB::table('site.section_items')->count())->toBe(0);
});

it('ignores a soft-deleted connection', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId, [], ['deleted_at' => now()->toDateTimeString()]);

    $result = app(StandaloneEventBackfiller::class)->run();

    expect($result['backfilled'])->toBe(0)
        ->and(DB::table('content.items')->count())->toBe(0);
});

// An ACCOUNT row is an organiser feed the ingest connectors already land into
// content.items. Carrying it here too would mint a second, thinner copy of
// every event the connector owns.
it('ignores account rows', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId, [], ['resource_kind' => null, 'resource_id' => 'acct-abc']);

    $result = app(StandaloneEventBackfiller::class)->run();

    expect($result['backfilled'])->toBe(0)
        ->and(DB::table('content.items')->count())->toBe(0);
});

it('counts a payload with no link rather than writing an item for it', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId, ['link' => '  ']);

    $result = app(StandaloneEventBackfiller::class)->run();

    expect($result['skipped_no_url'])->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(0);
});

it('falls back to the URL host when the payload carries no name', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId, ['name' => null, 'link' => 'https://events.humanitix.com/gala']);

    app(StandaloneEventBackfiller::class)->run();

    expect(DB::table('content.items')->value('headline_cache'))->toBe('events.humanitix.com');
});

it('keeps two owners of the same event URL on separate items', function () {
    [$first] = seedUserWithSite();
    [$second] = seedUserWithSite();
    sevConnection($first, ['link' => 'https://example.com/shared']);
    sevConnection($second, ['link' => 'https://example.com/shared']);

    $result = app(StandaloneEventBackfiller::class)->run();

    expect($result['backfilled'])->toBe(2)
        ->and(DB::table('content.items')->count())->toBe(2);
});

// The permalink decision (parent §7 step 4), pinned rather than left implicit.
// content.item_slugs is minted from headline_cache by
// ProjectionWriter::refreshItemCaches() — `event` is already in
// ContentItemSlugAllocator::SLUGGED_KINDS — and BOTH allocators slug through
// the identical Str::slug + 80-char word-boundary base(). So the migrated item
// RE-MINTS the byte-identical slug the legacy site.item_slugs row held, and no
// row needs copying across before Phase 6 deletes the legacy table.
it('re-mints the legacy permalink in content.item_slugs from the same headline', function () {
    [$userId] = seedUserWithSite();
    sevConnection($userId, ['name' => 'Nerve Melbourne 2026']);

    app(StandaloneEventBackfiller::class)->run();

    $slug = DB::table('content.item_slugs')
        ->where('user_id', $userId)->where('is_current', true)->first();

    expect($slug->slug)->toBe('nerve-melbourne-2026')
        ->and(Str::slug('Nerve Melbourne 2026'))->toBe($slug->slug);
});
