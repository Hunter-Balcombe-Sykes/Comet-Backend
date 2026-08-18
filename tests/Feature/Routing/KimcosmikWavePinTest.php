<?php

// The 2026-08-18 build wave's kimcosmik ledger, replayed through the migrated
// unroll path. If a future change moves any of these buckets, this test names
// the link that moved. Fixture anchors mirror the live Linktree as fetched
// 2026-08-18 (15 off-host anchors).

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Content\LinkPoolReader;
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
    Http::fake(['linktr.ee/*' => Http::response($anchors, 200)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/kimcosmik', 'bio_harvest');

    // Buckets as OBSERVED on first run (2026-08-18) and pinned:
    //   connected 9 — bandcamp:kimcosmik, bandcamp:cybersoul, discord,
    //                 facebook:kimcosmik, facebook:hybridrave (distinct
    //                 identifiers both place — multi-account), instagram
    //                 (no pre-existing IG row in this fixture; in a real
    //                 build the source connection exists and this refreshes),
    //                 mixcloud:KimCosmik (connectable since 134f55853,
    //                 Task #17 — landed the same day, mid-plan), youtube:UCCY6…,
    //                 youtube:@cybersoul9038
    //   noted 4     — bandcamp deep album/track ×2 (below suggest after the
    //                 harvest penalty), ra.co (detect-only), facebook groups URL
    //   probed 2    — discogs, juno (unknown domains)
    // Legacy wave landed 3 connections from this page; the ruling lands 9.
    expect($result['connected'])->toBe(9)
        ->and($result['noted'])->toBe(4)
        ->and($result['probed'])->toBe(2)
        ->and($result['suggested'])->toBe(0);

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
        'facebook:hybridrave',
        'facebook:kimcosmik',
        'instagram:kimcosmik',
        'mixcloud:KimCosmik',
        'youtube:UCCY6-AIHHvrmZW5J8IAjk-A',
        'youtube:cybersoul9038',
    ]);

    // Unknown hosts (discogs, juno) were probed, not carded here.
    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'discogs.com'));
    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'juno.co.uk'));

    // Known-but-unconnectable landed as cards, not nothing.
    $urls = array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url');
    expect($urls)->toContain('https://ra.co/dj/kimcosmik')
        ->and($urls)->toContain('https://kimcosmik.bandcamp.com/album/star-glider');

    // Ledger balance — §3.6, executable: every anchor accounted for.
    expect($result['connected'] + $result['suggested'] + $result['noted'] + $result['probed'])
        ->toBe(15);
});
