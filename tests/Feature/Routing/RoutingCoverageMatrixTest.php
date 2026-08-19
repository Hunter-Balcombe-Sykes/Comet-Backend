<?php

use App\Catalog\CompiledCatalog;
use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// T8 (owner, 2026-08-20): the COVERAGE MATRIX — one routing brain on every
// surface. This file pins the ROUTING PASTE lane cell-by-cell (its item arms
// are new); the other surfaces' cells are pinned in their own suites and
// indexed here so the whole matrix stays findable:
//
//   pool add endpoints    → MediaItemPoolAddTest (watch/listen/media + refusals
//                           + classify endpoint), EventPagePoolAddTest (events)
//   bio/link-in-bio scans → MediaScanSeedTest (media items + tombstones + F2),
//                           KimcosmikWavePinTest (the full 15-anchor ledger),
//                           LinkInBioImporterTest/-ParityTest (budgets, cards)
//   commerce probe        → CommerceProbeObservationTest (product + store +
//                           origin fallback + T7 store connect + tombstone)
//   website scan          → WebsiteImporter's own suite + the item arms added
//                           2026-08-20 (T8)
//
// Every row here: what a pasted URL BECOMES through POST /routing/links.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

it('routing paste: a media ITEM url becomes a REAL pinned pool item, never a card', function () {
    Queue::fake();
    $pro = createTenant('matrix-media');
    Http::fake([
        'youtube.com/oembed*' => Http::response(json_encode(['title' => 'Matrix Video', 'thumbnail_url' => 'https://i.ytimg.com/m.jpg']), 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);

    $siteId = (string) $pro->site->id;
    $revisionBefore = (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);

    $res = actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'item')
        ->assertJsonPath('pool', 'watch');

    expect($res->json('canonicalUrl'))->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    // The same +2 hand-add cache contract PoolCacheLanesTest pins for the
    // pool endpoint: writeManualItem bumps lane 1, the pin busts all three.
    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 2);

    $item = DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'video')->first();
    expect($item)->not->toBeNull()
        ->and($item->headline_cache)->toBe('Matrix Video')
        // A person pasted it asking for it on their site — PINNED (unlike a
        // scan, which lands library-only).
        ->and(DB::connection('pgsql')->table('site.section_items')
            ->where('item_id', $item->id)->where('state', 'pinned')->count())->toBe(1)
        // And NO link card beside it.
        ->and(DB::connection('pgsql')->table('content.items')
            ->where('user_id', $pro->id)->where('kind', 'link')->count())->toBe(0);
});

it('routing paste: a SCHEME-LESS media url still becomes the item (the share-sheet shape)', function () {
    // RouteLinkRequest deliberately accepts scheme-less input; the item arm
    // must normalize before the grammar asks (critic: a bare youtu.be paste
    // skipped the arm AND spent a storefront probe on a video link).
    Queue::fake();
    $pro = createTenant('matrix-bare');
    Http::fake([
        'youtube.com/oembed*' => Http::response(json_encode(['title' => 'Bare Paste', 'thumbnail_url' => null]), 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'youtu.be/dQw4w9WgXcQ'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'item')
        ->assertJsonPath('pool', 'watch');

    Queue::assertNotPushed(CommerceProbeJob::class);
});

it('routing paste: a spotify EPISODE becomes a Listen item — never a platform connection (T6b end-to-end)', function () {
    Queue::fake();
    $pro = createTenant('matrix-episode');
    Http::fake([
        'open.spotify.com/oembed*' => Http::response(json_encode(['title' => 'The Episode', 'thumbnail_url' => null]), 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://open.spotify.com/episode/512ojhOuo1ktJprKbVcKyQ'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'item')
        ->assertJsonPath('pool', 'listen');

    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'episode')->count())->toBe(1)
        ->and(IntegrationConnection::query()
            ->where('user_id', $pro->id)->count())->toBe(0);
});

it('routing paste: an EVENT page becomes a real event item', function () {
    Queue::fake();
    $pro = createTenant('matrix-event');
    $eventHtml = '<html><head><script type="application/ld+json">'.json_encode([
        '@context' => 'https://schema.org', '@type' => 'MusicEvent', 'name' => 'Matrix Rave',
        'url' => 'https://lu.ma/matrix-rave', 'startDate' => '2099-03-01T20:00:00+11:00',
    ]).'</script></head><body></body></html>';
    Http::fake(['lu.ma/*' => Http::response($eventHtml, 200), '*' => Http::response('', 404)]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://lu.ma/matrix-rave'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'item')
        ->assertJsonPath('pool', 'events');

    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'event')->count())->toBe(1);
});

it('routing paste: an unknown-host deep URL stays a link card (a card is the RIGHT answer here)', function () {
    Queue::fake();
    $pro = createTenant('matrix-unknown');
    Http::fake(['*' => Http::response('', 404)]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://some-blog.example.com/my-post'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'link');

    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'link')->count())->toBe(1);
});

it('routing paste: a social ITEM url (instagram reel) is a card — never a profile connection', function () {
    Queue::fake();
    $pro = createTenant('matrix-reel');
    Http::fake(['*' => Http::response('', 404)]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://www.instagram.com/reel/Cxxxxxxxxxx/'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'link');

    expect(IntegrationConnection::query()
        ->where('user_id', $pro->id)->count())->toBe(0);
});

it('routing paste: a failed item READ falls through to the card path — nothing vanishes', function () {
    Queue::fake();
    $pro = createTenant('matrix-deaditem');
    // A claimed video whose oEmbed AND page are dead: the item arm answers
    // null and the ordinary route cards it.
    Http::fake(['*' => Http::response('', 404)]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://vimeo.com/123456789'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'link');

    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'link')->count())->toBe(1);
});

it('routing paste: a removed item is RESURRECTED by a fresh paste (direct request wins the tombstone)', function () {
    Queue::fake();
    $pro = createTenant('matrix-resurrect');
    Http::fake([
        'youtube.com/oembed*' => Http::response(json_encode(['title' => 'Back Again', 'thumbnail_url' => null]), 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertStatus(202);
    $itemId = DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'video')->value('id');
    DB::connection('pgsql')->table('content.items')->where('id', $itemId)->update(['removed_at' => now()]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])
        ->assertStatus(202)->assertJsonPath('outcome', 'item');

    expect(DB::connection('pgsql')->table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull();
});

it('legacy scan lane: a catalog-only brand CONNECTS instead of carding (F6 — the_046_official trace)', function () {
    // An Apple Music artist link in an Instagram bio was carded ("The 046 on
    // Apple Music") while the same URL through the P8 importer or a paste
    // became a connection. The 'link' arm asks Engine 1 first now.
    Queue::fake();
    $pro = createTenant('f6-apple');
    Http::fake(['*' => Http::response('', 404)]);

    $result = app(LinkRouter::class)
        ->route($pro, 'https://music.apple.com/au/artist/the-046/1492426191', new RouteContext);

    expect($result->handled)->toBeTrue();
    expect(IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('surface_key', 'apple_music.artist')->count()
        + DB::table('routing.source_intents')->where('user_id', $pro->id)
            ->where('surface_key', 'apple_music.artist')->whereIn('state', ['proposed', 'applied'])->count())
        ->toBeGreaterThan(0);
});

it('legacy scan lane: marketplaces stay CARDS — the LINK_ONLY flavour is byte-identical (F6)', function () {
    Queue::fake();
    $pro = createTenant('f6-amazon');
    Http::fake(['*' => Http::response('', 404)]);

    $result = app(LinkRouter::class)
        ->route($pro, 'https://www.amazon.com.au/dp/B0EXAMPLE1', new RouteContext);

    expect($result->outcome)->toBe('custom')
        ->and($result->handled)->toBeFalse();
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

it('catalog store caps ride in lockstep with the shop family cap (F7 — the046.com trace)', function () {
    // shopify.store defaulted to ONE account in the catalog while every
    // other door allowed ten — the046.com's store blocked on cap_reached
    // beside a single existing store.
    foreach (['shopify.store', 'woocommerce.store', 'squarespace.store', 'bigcartel.store', 'generic.store', 'bandcamp.store', 'gumroad.store', 'stan.store'] as $key) {
        expect((int) CompiledCatalog::surface($key)['max_accounts'])->toBe(10, "surface {$key}");
    }
});

it('routing paste: an unknown-host deep URL of the product-page SHAPE cards the link and dispatches the store probe as a SUGGESTION', function () {
    // Honest scope note: the probe is queued (Queue::fake), so this row pins
    // the paste lane's DISPATCH contract (suggestOnly). What the probe does
    // with a real product page — item + store suggestion — is pinned in
    // CommerceProbeObservationTest.
    Queue::fake();
    $pro = createTenant('matrix-product');
    Http::fake(['*' => Http::response('', 404)]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://merchant-store.example.com/products/hat'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'link');

    // suggestOnly: the user pasted a link, not a store — a hit becomes an
    // inbox suggestion, never an auto-connect.
    Queue::assertPushed(CommerceProbeJob::class, fn (CommerceProbeJob $job) => $job->suggestOnly === true);
});
