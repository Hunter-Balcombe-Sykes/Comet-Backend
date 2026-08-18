<?php

// Owner ruling 2026-08-18: "connect as many as possible auto, for pre-account
// and always." On a HARVEST origin (anything but 'paste'), a suggestion is
// useless-to-harmful: pre-claim there is no inbox to see it, post-claim it is
// one more tap for a link the user demonstrably published themselves. So the
// suggest band auto-applies — but ONLY when the margin is clean: a projection
// where two rules matched too closely (margin < minMargin) stays Choose, or
// we would be guessing WHICH surface to connect, not whether.

use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
});

// Bare Bandcamp measured 60 pre-penalty on 2026-08-18: suggest band for
// 'content' (post-penalty 50, against auto 70 / suggest 45). Was 'choose';
// now places.
it('auto-places a suggest-band content link from a harvest origin', function () {
    $pro = createTenant('harvest-band');

    $out = app(LinkRoutingService::class)->route(
        'https://kimcosmik.bandcamp.com/',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('place')
        ->and($out['connectionId'])->not->toBeNull();
});

// YouTube measured 75 pre-penalty: 65 post-penalty, below auto (70), inside
// suggest (45). The signup build connected YouTube on the legacy path; the
// new path must not regress that.
it('auto-places a suggest-band social link from a harvest origin', function () {
    $pro = createTenant('harvest-social');

    $out = app(LinkRoutingService::class)->route(
        'https://www.youtube.com/channel/UCCY6-AIHHvrmZW5J8IAjk-A',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('place')
        ->and($out['connectionId'])->not->toBeNull();
});

// Direct paste keeps the interactive flow — the dashboard's confirm UI is a
// wire contract (RoutingController), and paste already auto-applies at 70+.
it('keeps the suggest band as a suggestion on a direct paste', function () {
    $pro = createTenant('paste-band');

    $out = app(LinkRoutingService::class)->route(
        'https://kimcosmik.bandcamp.com/',
        RoutingContext::forUser($pro, 'paste'),
    );

    expect($out['verdict'])->toBe('choose')
        ->and($out['connectionId'])->toBeNull();
});

// Below the suggest floor nothing changes: still a Note, never a connection.
it('keeps a below-suggest harvest link as a note', function () {
    $pro = createTenant('harvest-note');

    $out = app(LinkRoutingService::class)->route(
        'https://www.mixcloud.com/KimCosmik/', // 32 pre-penalty, and unservable anyway
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('note')
        ->and($out['connectionId'])->toBeNull();
});

// Tombstones still beat maximisation: a harvest must never resurrect a
// refusal (C8). Same shape as TombstoneResurrectionTest, pinned here so the
// new Place path is the one proven, not the old Choose path.
it('does not auto-place a tombstoned surface from a harvest origin', function () {
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
