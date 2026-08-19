<?php

// The behaviours LinkInBioScanJob provided caller-side that LinkInBioImporter
// must own before the job can become a shell over it (P8 consumer 1 of 9):
// note→card, unknown→probe within a budget, zero-yield→bio-URL floor, and
// conflict→notification. Each was live behaviour on 2026-08-18; losing any of
// them in the swap is a silent regression on the signup build path.

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

it('cards a matched-but-unconnectable link instead of dropping it', function () {
    Queue::fake();
    $pro = createTenant('imp-noted');
    Http::fake(['linktr.ee/*' => Http::response(
        '<a href="https://ra.co/dj/kimcosmik">RA</a>', 200,
    )]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/kimcosmik');

    $cards = app(LinkPoolReader::class)->cards($pro->refresh());
    expect($result['noted'])->toBe(1)
        ->and($cards)->toHaveCount(1)
        ->and($cards[0]['url'])->toBe('https://ra.co/dj/kimcosmik');
    Queue::assertNotPushed(CommerceProbeJob::class); // recognised host: no probe
});

it('probes an unknown host instead of carding it, within the probe budget', function () {
    Queue::fake();
    $pro = createTenant('imp-probe');
    $anchors = '';
    // Two more distinct hosts than the run's probe budget (18, T9 2026-08-20).
    foreach (range(1, 20) as $i) {
        $anchors .= '<a href="https://unknown-shop-'.$i.'.example/">S'.$i.'</a>';
    }
    Http::fake(['linktr.ee/*' => Http::response($anchors, 200)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/shops');

    Queue::assertPushed(CommerceProbeJob::class, 18);
    expect($result['probed'])->toBe(18);
    // The two past-budget links must be CARDED — silent truncation is the
    // failure mode 3.9 hunts; a probe-starved link still lands somewhere.
    $urls = array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url');
    expect($urls)->toContain('https://unknown-shop-19.example/')
        ->and($urls)->toContain('https://unknown-shop-20.example/');
});

it('seeds the bio url itself when the page yields nothing routable', function () {
    Queue::fake();
    $pro = createTenant('imp-floor');
    // The linkin.bio shape: 200, chrome only, zero anchors.
    Http::fake(['linkin.bio/*' => Http::response('<html><body><div id="app"></div></body></html>', 200)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linkin.bio/supernormal_180', 'bio_harvest');

    expect($result['bio_url_seeded'])->toBeTrue();
    $cards = app(LinkPoolReader::class)->cards($pro->refresh());
    expect($cards)->toHaveCount(1)
        ->and($cards[0]['url'])->toBe('https://linkin.bio/supernormal_180');
});

it('does not seed the bio url when something routed', function () {
    Queue::fake();
    $pro = createTenant('imp-no-floor');
    Http::fake(['linktr.ee/*' => Http::response(
        '<a href="https://ra.co/dj/kimcosmik">RA</a>', 200,
    )]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/kimcosmik');

    expect($result['bio_url_seeded'])->toBeFalse();
    $urls = array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url');
    expect($urls)->not->toContain('https://linktr.ee/kimcosmik');
});

it('raises one notification when the unroll finds a conflict', function () {
    Queue::fake();
    $pro = createTenant('imp-conflict');
    // An incumbent booking connection…
    IntegrationConnection::create([
        'user_id' => $pro->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/incumbent-venue'], 'is_active' => true,
    ]);
    // …and a bio page carrying a DIFFERENT venue: XOR holds it as a conflict.
    Http::fake(['linktr.ee/*' => Http::response(
        '<a href="https://www.fresha.com/a/other-venue-x1y2z3">Book</a>', 200,
    )]);

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/venue', 'bio_harvest');

    expect(DB::connection('pgsql')
        ->table('notifications.notifications')->where('user_id', $pro->id)->count())->toBe(1);
});
