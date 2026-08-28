<?php

// The 2026-08-18 build wave's kimcosmik ledger, replayed through the migrated
// unroll path. If a future change moves any of these buckets, this test names
// the link that moved. Fixture anchors mirror the live Linktree as fetched
// 2026-08-18 (15 off-host anchors).

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Content\LinkPoolReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

it('replays the kimcosmik ledger: connections, cards, and probes land where the ruling says', function () {
    Queue::fake();
    $pro = createTenant('kimcosmik-pin');

    $anchors = implode('', array_map(fn ($u) => '<a href="'.$u.'">x</a>', [
        'https://obskurmusic.bandcamp.com/track/carissa-illy-i-look-good-kim-cosmik-remix',
        'https://kimcosmik.bandcamp.com/album/star-glider',
        'https://kimcosmik.bandcamp.com/',
        'https://www.mixcloud.com/KimCosmik/',
        'https://www.youtube.com/channel/UCCY6-AIHHvrmZW5J8IAjk-A',
        'https://ra.co/dj/kimcosmik',
        'https://www.instagram.com/kimcosmik/',
        'https://www.facebook.com/kimcosmik/',
        'https://www.discogs.com/search?q=kim+cosmik&type=all',
        'https://cybersoul.bandcamp.com/',
        'https://www.youtube.com/@cybersoul9038',
        'https://www.facebook.com/groups/3004349706304446/',
        'https://www.facebook.com/hybridrave',
        'https://www.juno.co.uk/products/kim-cosmik-arsonist-recorder-hybrid-collective-vol-1-vinyl/952291-01/',
        'https://discord.com/invite/q3FvffbQ',
    ]));
    Http::fake([
        'linktr.ee/*' => Http::response($anchors, 200),
        // T6: the two bandcamp DEEP links now read as media ITEMS — their
        // OG pages are faked so the seed is deterministic (before T6 they
        // never fetched; an unfaked pattern would hit the real network).
        '*.bandcamp.com/track/*' => Http::response('<html><head><meta property="og:title" content="I Look Good (Kim Cosmik Remix)"><meta property="og:image" content="https://f4.bcbits.com/img/a1.jpg"></head></html>', 200),
        '*.bandcamp.com/album/*' => Http::response('<html><head><meta property="og:title" content="Star Glider"><meta property="og:image" content="https://f4.bcbits.com/img/a2.jpg"></head></html>', 200),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/kimcosmik', 'bio_harvest');

    // Buckets as OBSERVED on first run (2026-08-18), re-pinned 2026-08-20
    // for T6 (scan-lane media items):
    //   connected 8 — bandcamp:kimcosmik, bandcamp:cybersoul, discord,
    //                 facebook:kimcosmik, instagram (no pre-existing IG row
    //                 in this fixture; in a real build the source connection
    //                 exists and this refreshes), mixcloud:KimCosmik
    //                 (connectable since 134f55853, Task #17 — landed the
    //                 same day, mid-plan), youtube:UCCY6…,
    //                 youtube:@cybersoul9038
    //   suggested 1 — facebook:hybridrave. Both facebooks placed while
    //                 socials were multiAccount(5); FI-1 (2026-08-20) made
    //                 every social single-account, so the second distinct
    //                 facebook now cap-holds as a Swap suggestion.
    //   items 2     — the bandcamp deep track + album are REAL listen items
    //                 now (T6), library-only, not link cards
    //   noted 1     — the facebook groups URL. ra.co LEFT this line on
    //                 2026-08-28: the artist page ra.co/dj/<slug> gained a
    //                 captured ProfileLink detector, so kimcosmik's RA page
    //                 now CONNECTS and the T27b connector fetches their tour
    //                 dates onto the site — the whole point of building that
    //                 connector. Listings (/events, /clubs) still note.
    //   probed 2    — discogs, juno (unknown domains)
    // Legacy wave landed 3 connections from this page; the ruling lands 8+1.
    expect($result['connected'])->toBe(9)
        ->and($result['items'])->toBe(2)
        ->and($result['noted'])->toBe(1)
        ->and($result['probed'])->toBe(2)
        ->and($result['suggested'])->toBe(1);

    $connections = IntegrationConnection::where('user_id', $pro->id)
        ->get()
        ->map(fn ($c) => $c->platform.':'.$c->resource_id)
        ->sort()
        ->values()
        ->all();
    expect($connections)->toBe([
        'bandcamp:cybersoul',
        'bandcamp:kimcosmik',
        'discord:q3FvffbQ',
        // facebook:hybridrave is the cap-held Swap suggestion (FI-1), not a row.
        'facebook:kimcosmik',
        'instagram:kimcosmik',
        'mixcloud:KimCosmik',
        'resident-advisor:kimcosmik',
        'youtube:UCCY6-AIHHvrmZW5J8IAjk-A',
        'youtube:cybersoul9038',
    ]);

    // Unknown hosts (discogs, juno) were probed, not carded here.
    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'discogs.com'));
    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'juno.co.uk'));

    // Known-but-unconnectable landed as cards, not nothing.
    $urls = array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url');
    expect($urls)->not->toContain('https://ra.co/dj/kimcosmik') // connects now, never a card
        // T6: the bandcamp album is a LISTEN ITEM now, never a link card.
        ->and($urls)->not->toContain('https://kimcosmik.bandcamp.com/album/star-glider');

    // The two media items: real kinds, canonical URLs, in the LIBRARY only —
    // a scan never pins (owner, 2026-08-20).
    $items = DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->whereIn('kind', ['track', 'release'])->get();
    expect($items)->toHaveCount(2)
        ->and($items->pluck('headline_cache')->sort()->values()->all())
        ->toBe(['I Look Good (Kim Cosmik Remix)', 'Star Glider']);
    expect(DB::connection('pgsql')->table('site.section_items')
        ->whereIn('item_id', $items->pluck('id'))->count())->toBe(0);

    // Ledger balance — §3.6, executable: every anchor accounted for. 'dropped'
    // is in the sum because it is a real bucket now (#R2): before, a rejected
    // link was counted 'noted' and the equation balanced while a link was
    // gone. A non-zero drop here should be read, not absorbed. 'items' joined
    // the equation with T6.
    expect($result['connected'] + $result['suggested'] + $result['noted'] + $result['items'] + $result['probed'] + $result['dropped'] + $result['folded'])
        ->toBe(15)
        ->and($result['dropped'])->toBe(0);
});
