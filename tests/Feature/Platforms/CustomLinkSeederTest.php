<?php

use App\Jobs\Content\EnrichPoolLinkJob;
use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Phase 6: seedCustom() writes a custom_links POOL item, not a connection.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    // A routed booking link is a proposed source intent since 2026-09-05.
    setupRoutingTables();
});

/**
 * Phase 6: a seeded link is a custom_links POOL item, and a pool item needs a
 * section, which hangs off the site. The connection lane could store a link for
 * a siteless user; the pool cannot, so every case here needs a real site.
 */
function seederUser(array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    $site = new Site(['subdomain' => 'seed'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

// These exercise seedCustom() — the RAW custom-link write. They used to call
// seed(), which since the 2026-07-25 link classification consolidation is the
// routing GATEWAY: it hands the URL to LinkRouter first, and an unclassified URL
// there becomes a commerce probe returning 'pending' with no row written (the
// probe's own seedCustom() fallback writes it later, on a miss). Under
// Queue::fake() that meant no row at all. The gateway behaviour has its own
// tests at the bottom of this file; everything above them is about the write.

it('seeds a custom link card idempotently, enriches it, and enforces the same 50-link cap the manual UI uses', function () {
    Queue::fake();
    $user = seederUser(['account_type' => 'business']);

    app(CustomLinkSeeder::class)->seedCustom($user, 'https://someblog.example/post');
    app(CustomLinkSeeder::class)->seedCustom($user, 'https://someblog.example/post'); // again

    // Idempotent by construction: the coord is derived from the url, so the
    // re-seed upserts the SAME item rather than adding a second card.
    $cards = app(LinkPoolReader::class)->cards($user->refresh());
    expect($cards)->toHaveCount(1)
        ->and($cards[0]['url'])->toBe('https://someblog.example/post');
    // Dispatched once, not on the idempotent re-seed.
    Queue::assertPushed(EnrichPoolLinkJob::class, 1);
});

it('refuses to seed a link for a pending-deletion account', function () {
    $user = seederUser(['status' => 'pending_deletion']);
    expect(app(CustomLinkSeeder::class)->seedCustom($user, 'https://example.com'))->toBeNull();
    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
});

it('stops at the 50-link cap (T9/F10 raise)', function () {
    Queue::fake();
    $user = seederUser(['account_type' => 'business']);
    for ($i = 0; $i < 50; $i++) {
        $result = app(CustomLinkSeeder::class)->seedCustom($user, "https://example{$i}.com");
    }
    $result = app(CustomLinkSeeder::class)->seedCustom($user, 'https://one-too-many.example');
    expect($result)->toBeNull();
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(50);
});

it('returns null for a URL that fails to normalize', function () {
    $user = seederUser();
    expect(app(CustomLinkSeeder::class)->seedCustom($user, 'not a url'))->toBeNull();
});

it('respects an existing link already at the cap — re-seeding it is still idempotent, not blocked', function () {
    Queue::fake();
    $user = seederUser(['account_type' => 'business']);
    for ($i = 0; $i < 50; $i++) {
        app(CustomLinkSeeder::class)->seedCustom($user, "https://example{$i}.com");
    }
    // Re-seeding the FIRST link (already stored) must still succeed — the cap
    // only blocks genuinely NEW items, not idempotent re-seeds of an existing one.
    app(CustomLinkSeeder::class)->seedCustom($user, 'https://example0.com');

    // Still 50, and still the SAME 50: the re-seed resolved to the existing
    // coord rather than being refused by the cap or minting a 51st. Stored in
    // CANONICAL form (F11): the root URL folds to its trailing-slash shape.
    $cards = app(LinkPoolReader::class)->cards($user->refresh());
    expect($cards)->toHaveCount(50)
        ->and(collect($cards)->pluck('url'))->toContain('https://example0.com/');
});

// ── seed() as the routing GATEWAY (link classification consolidation, Phase 5) ──
//
// seed() routes first and only writes a custom link when LinkRouter says
// 'custom'. These pin the four outcomes, because nothing else did: the original
// change shipped 999 insertions of routing with no LinkRouter test at all, and a
// missing `use App\Jobs\Platforms\CommerceProbeJob` import in the router — which
// fataled every one of these paths — reached deployed development undetected.

it('seed() dispatches a commerce probe and writes NO custom link for an unclassified URL', function () {
    Queue::fake();
    $user = seederUser(['account_type' => 'business']);

    $result = app(CustomLinkSeeder::class)->seed($user, 'https://someblog.example/post');

    expect($result)->toBeNull();
    Queue::assertPushed(CommerceProbeJob::class, 1);
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);
});

it('seed() proposes a Booksy link as a pre-ticked suggestion — no connection, no custom link (2026-09-05)', function () {
    Queue::fake();
    $user = seederUser(['account_type' => 'partna']);

    $result = app(CustomLinkSeeder::class)->seed($user, 'https://booksy.com/en-us/12345_the-salon');

    // Routed, so a null return (Issue F: every caller already discarded this
    // return value, which is why null is safe) and NO custom-link card: the
    // router answered custom(handled) — carried by the intent below — and
    // seed() honours `handled` the same way InstagramAutoSync's pass 1 does.
    expect($result)->toBeNull();
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);

    // Owner policy (2026-09-05): a harvest never connects a booking platform,
    // it proposes one. Booksy keeps its legacy surface slug (Convergence
    // Phase 6 retired the shared 'booking' pseudo-key).
    expect(IntegrationConnection::where(['user_id' => $user->id, 'routing_class' => 'booking'])->count())->toBe(0);
    $intent = DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'booksy.book')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed')
        ->and($intent->band)->toBe('auto')
        ->and($intent->canonical_url)->toBe('https://booksy.com/en-us/12345_the-salon');
});

it('seed() falls through to a custom link when the routing gate denies the category', function () {
    Queue::fake();
    // Reservations route for business FOOD accounts only; a partna account is
    // denied and the link must still land, as a custom link — never dropped.
    $user = seederUser(['account_type' => 'partna']);

    app(CustomLinkSeeder::class)->seed($user, 'https://www.opentable.com/r/some-restaurant');

    // Gate denied the reservations route, so the link lands in the pool instead
    // — never dropped. seed() returns null on every path since Phase 6.
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(1);
    expect(IntegrationConnection::where(['user_id' => $user->id, 'routing_class' => 'reservations'])->count())->toBe(0);
});

it('seed() still routes a Fresha link when the user has custom links DISABLED (Issue I/B2)', function () {
    Queue::fake();
    $user = seederUser(['account_type' => 'partna']);

    // integration.custom is a CUSTOM-LINK policy and lives in seedCustom(). If it
    // sat in seed() — where it used to — a user with custom links switched off
    // would silently lose booking, social, event and shop routing too.
    config(['partna.feature_availability.disabled' => ['integration.custom']]);

    app(CustomLinkSeeder::class)->seed($user, 'https://www.fresha.com/a/the-salon');

    // Routed (proposed, since booking stopped auto-connecting on 2026-09-05)
    // rather than swallowed by the custom-link switch.
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'fresha.book')->where('state', 'proposed')->exists())->toBeTrue();
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);
});

it('seed() shares ONE probe budget across a loop, so a page of unclassified links cannot fan out unbounded', function () {
    Queue::fake();
    $user = seederUser(['account_type' => 'business']);
    $seeder = app(CustomLinkSeeder::class);

    // The regression this pins: LinkInBioScanJob and autoSaveUnmatchedLinks both
    // deleted their own MAX_COMMERCE_PROBES counters and called seed() per URL.
    // Because seed() built a fresh RouteContext each time, every link got its own
    // budget of RouteContext::DEFAULT_MAX_PROBES — i.e. no cap. One shared
    // context is the whole fix. Link count kept a few past the budget so the
    // test still exercises the "past the cap" branch after the cap was raised.
    $ctx = new RouteContext;
    $linkCount = RouteContext::DEFAULT_MAX_PROBES + 4;
    for ($i = 0; $i < $linkCount; $i++) {
        $seeder->seed($user, "https://unclassified{$i}.example/page", $ctx);
    }

    Queue::assertPushed(CommerceProbeJob::class, RouteContext::DEFAULT_MAX_PROBES);
    // The links past the budget are not lost — they become pool links.
    expect(app(LinkPoolReader::class)->cards($user->refresh()))
        ->toHaveCount($linkCount - RouteContext::DEFAULT_MAX_PROBES);
});
