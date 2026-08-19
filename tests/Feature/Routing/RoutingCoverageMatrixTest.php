<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
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

    $res = actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'item')
        ->assertJsonPath('pool', 'watch');

    expect($res->json('canonicalUrl'))->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

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

it('routing paste: an unknown-host PRODUCT page cards the link and dispatches the store probe as a SUGGESTION', function () {
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
