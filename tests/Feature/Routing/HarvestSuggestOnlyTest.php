<?php

// Owner ruling 2026-09-03: "nothing a harvester found ever auto-connects."
// This supersedes the 2026-08-18 "harvest maximisation" rule pinned here
// until today — that rule auto-applied the SUGGEST band on any indirect
// origin, on the theory that a suggestion was useless-to-harmful for a link
// the user had "demonstrably published themselves". The owner's later read:
// that reasoning quietly turned `suggest` into the auto-connect threshold for
// every post-claim harvest lane, with margin as the only guard — the exact
// opposite of what "suggest" should mean. Now PlacementPolicy::decide() mints
// Verdict::Place ONLY when RoutingContext::isConfirmedByUser() is true (a
// paste, or an inbox accept) AND confidence/margin clear the auto band.
// Every harvest-origin find, however confident, lands as a Choose — a
// proposed intent, zero live connections — and the person confirms it
// through the suggestions inbox.

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    // Same latent network dependence ConnectionIdentityAliasTest carried:
    // F9's reconciler-dispatched ConnectFetchJob runs inline under the sync
    // driver with no Http::fake, so applied content connections were being
    // enriched against the REAL youtube/bandcamp — slow always, and
    // outcome-flipping the day a vendor endpoint flakes (F26 removes a
    // never-fetched row). These tests pin verdict/placement semantics only.
    Bus::fake([ConnectFetchJob::class]);
});

// Bare Bandcamp measured 60 pre-penalty on 2026-08-18: suggest band for
// 'content' (post-penalty 50, against auto 70 / suggest 45). Used to
// auto-place under the 2026-08-18 rule; now it is filed as a suggestion —
// same as every other harvest-origin find, connection count stays 0.
it('suggests a suggest-band content link from a harvest origin instead of auto-placing it', function () {
    $pro = createTenant('harvest-band');

    $out = app(LinkRoutingService::class)->route(
        'https://kimcosmik.bandcamp.com/',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('choose')
        ->and($out['connectionId'])->toBeNull()
        ->and($out['intentId'])->not->toBeNull();

    $intent = DB::table('routing.source_intents')->where('id', $out['intentId'])->first();
    expect($intent->state)->toBe('proposed')
        ->and($intent->surface_key)->toBe('bandcamp.artist');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'bandcamp.artist')->count())->toBe(0);
});

// YouTube measured 75 pre-penalty: 65 post-penalty, below auto (70), inside
// suggest (45). Same treatment — a suggestion, not a connection, no matter
// how the sign-up path used to auto-connect it.
it('suggests a suggest-band social link from a harvest origin instead of auto-placing it', function () {
    $pro = createTenant('harvest-social');

    $out = app(LinkRoutingService::class)->route(
        'https://www.youtube.com/channel/UCCY6-AIHHvrmZW5J8IAjk-A',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('choose')
        ->and($out['connectionId'])->toBeNull()
        ->and($out['intentId'])->not->toBeNull();

    $intent = DB::table('routing.source_intents')->where('id', $out['intentId'])->first();
    expect($intent->state)->toBe('proposed')
        ->and($intent->surface_key)->toBe('youtube.channel');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'youtube.channel')->count())->toBe(0);
});

// Direct paste keeps the interactive flow, and it is the ONLY lane that still
// reaches Place — isConfirmedByUser() is what separates "the person typed this
// link and pressed add" from anything a harvester turned up.
//
// Rewritten 2026-09-03 with the confidence system. This used to assert that a
// paste in the SUGGEST band (70+ auto-applied, below that asked) stayed a
// question. There is no band to sit below now, and the reason is the point: 70
// was never measurable. What decides it instead is whether the rule named an
// account — kimcosmik.bandcamp.com names `kimcosmik` in the subdomain, so a
// person pasting their own Bandcamp gets it connected rather than being asked
// to confirm a fact we already read off the URL.
it('connects a pasted link that names an account', function () {
    $pro = createTenant('paste-band');

    $out = app(LinkRoutingService::class)->route(
        'https://kimcosmik.bandcamp.com/',
        RoutingContext::forUser($pro, 'paste'),
    );

    expect($out['verdict'])->toBe('place')
        ->and($out['connectionId'])->not->toBeNull();
});

it('still asks about a pasted link that matched a shape but named nobody', function () {
    // The review path a paste can still take, pinned against a REAL URL rather
    // than a hand-picked score. Square's appointments detectors constrain a
    // path — so Gate 3 passes and this is a genuine booking page — but declare
    // no capture group, so nothing here can say WHOSE. Choose, not Place.
    $pro = createTenant('paste-nameless');

    $out = app(LinkRoutingService::class)->route(
        'https://book.squareup.com/appointments/7rn54rnv21ng7n',
        RoutingContext::forUser($pro, 'paste'),
    );

    expect($out['verdict'])->toBe('choose')
        ->and($out['connectionId'])->toBeNull();
});

// Below the suggest floor nothing changes: still a Note, never a connection.
it('keeps a below-suggest harvest link as a note', function () {
    $pro = createTenant('harvest-note');

    $out = app(LinkRoutingService::class)->route(
        // An RA LISTING (28 pre-penalty, MarketplaceListing). The artist page
        // ra.co/dj/<slug> stopped being a valid example here on 2026-08-28: it
        // gained a captured ProfileLink detector so the RA connector can fetch
        // that DJ's tour, so it now scores 75 and reaches the choose band. A
        // club/event page is still someone else's night, and still a note.
        'https://ra.co/events/1234567',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('note')
        ->and($out['connectionId'])->toBeNull();
});

// Tombstones still beat every band: a harvest must never resurrect a
// refusal (C8), regardless of whether the projection would otherwise choose
// or place.
it('does not suggest a tombstoned surface from a harvest origin', function () {
    $pro = createTenant('harvest-tombstoned');

    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'source_ref' => 'bandcamp.artist:kimcosmik',
        'scope' => 'this_source',
        'reason' => 'test refusal',
        'created_at' => now(),
    ]);

    $out = app(LinkRoutingService::class)->route(
        'https://kimcosmik.bandcamp.com/',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('reject')
        ->and($out['connectionId'])->toBeNull();
});
