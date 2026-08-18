<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Content\LinkPoolReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

// Uses https://example.com as the bio page: SafeUrlFetcher::assertSafe() does
// a real DNS lookup before the fetch even under Http::fake(), so a
// non-resolving domain fails the SSRF check before the fake is consulted.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
    // The importer now writes note-cards / the zero-yield floor into the
    // custom_links POOL (parity work, 2026-08-18), so the content lane must
    // exist even for tests that only assert routing rows.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function bioPage(string $html): void
{
    Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
}

it('skips the bio platform\'s own chrome and keeps only the owner\'s links', function () {
    // Measured on a real Linktree page: 58 anchors, 55 of them the platform's
    // own navigation. Without this rule an unroll turns a user's page into a
    // directory of someone else's marketing.
    bioPage('<html><body>
        <a href="https://example.com/pricing">Pricing</a>
        <a href="https://example.com/blog">Blog</a>
        <a href="https://example.com/help">Help centre</a>
        <a href="https://www.instagram.com/theartist">Instagram</a>
        <a href="https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4">Spotify</a>
    </body></html>');

    $result = app(LinkInBioImporter::class)->import(createTenant('bio-chrome'), 'https://example.com/theartist');

    expect($result['skipped_chrome'])->toBe(3)
        ->and($result['observations'])->toBe(2);
});

it('routes the owner\'s links through the same pipeline everything else uses', function () {
    $pro = createTenant('bio-routes');
    bioPage('<html><body>
        <a href="https://www.instagram.com/theartist">IG</a>
        <a href="https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4">Spotify</a>
    </body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    $observations = DB::table('routing.link_observations')->where('source', 'link_in_bio')->get();
    expect($observations)->toHaveCount(2)
        ->and($observations->pluck('surface_key')->all())
        ->toContain('instagram.profile', 'spotify.player');
});

it('treats an unreachable bio page as unavailable, not as a page with no links', function () {
    Http::fake(['*' => Http::response('', 404)]);

    $result = app(LinkInBioImporter::class)->import(createTenant('bio-down'), 'https://example.com/gone');

    expect($result['outcome'])->toBe('unavailable')
        ->and($result['observations'])->toBe(0);
});

it('counts one decision per distinct link', function () {
    $pro = createTenant('bio-dupes');
    bioPage('<html><body>
        <a href="https://www.instagram.com/theartist">Top</a>
        <a href="https://WWW.INSTAGRAM.COM/theartist">Bottom</a>
    </body></html>');

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    expect($result['observations'])->toBe(1);
});

it('records the run under its own kind so imports stay accountable', function () {
    $pro = createTenant('bio-run');
    bioPage('<html><body><a href="https://x.com/theartist">X</a></body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    $run = DB::table('routing.import_runs')->where('user_id', $pro->id)->first();
    expect($run->kind)->toBe('link_in_bio')
        ->and($run->outcome)->toBe('ok');
});

it('never leaves a bio page with only chrome looking like a successful haul', function () {
    // A page where everything was chrome is a real outcome worth seeing: it
    // means the unroll found the user nothing.
    $pro = createTenant('bio-allchrome');
    bioPage('<html><body>
        <a href="https://example.com/pricing">Pricing</a>
        <a href="https://example.com/blog">Blog</a>
    </body></html>');

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(0)
        ->and($result['skipped_chrome'])->toBe(2)
        // The zero-yield floor (2026-08-18): a page that gave the user
        // nothing still leaves the bio URL itself as a card, so the unroll
        // is never a silent total loss. No intents — a card is not an intent.
        ->and($result['bio_url_seeded'])->toBeTrue()
        ->and(DB::table('routing.source_intents')->where('user_id', $pro->id)->count())->toBe(0);
});

// ── Batch (P8 blocker 3) ────────────────────────────────────────────────────
// An Instagram bio harvest hands over every URL it found on one profile. That
// is ONE acquisition of one account, so it must be one run — N runs would burn
// N of the user's 3 daily slots and misreport what happened.

it('unrolls a list of bio pages as ONE run, not one run per page', function () {
    $pro = createTenant('bio-batch');
    Http::fake([
        'example.com/one*' => Http::response('<html><body><a href="https://www.instagram.com/theartist">IG</a></body></html>', 200),
        'example.com/two*' => Http::response('<html><body><a href="https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4">Spotify</a></body></html>', 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($pro, [
        'https://example.com/one',
        'https://example.com/two',
    ]);

    expect($result['outcome'])->toBe('ok')
        ->and($result['pages'])->toBe(2)
        ->and($result['observations'])->toBe(2)
        ->and(DB::table('routing.import_runs')->where('user_id', $pro->id)->count())->toBe(1);
});

it('counts a link found on two bio pages once', function () {
    // Cross-page dedupe is the point of one shared run: the same Instagram on
    // a Linktree AND a Beacons page is one intent, not two.
    $pro = createTenant('bio-batch-dupes');
    Http::fake([
        '*' => Http::response('<html><body><a href="https://www.instagram.com/theartist">IG</a></body></html>', 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($pro, [
        'https://example.com/one',
        'https://example.com/two',
    ]);

    expect($result['observations'])->toBe(1)
        ->and(DB::table('routing.link_observations')->where('user_id', $pro->id)->count())->toBe(1);
});

it('reports a partly unreachable batch as partial, not as a clean haul', function () {
    // "Found nothing" and "could not look" are different answers, and a caller
    // that cannot tell them apart will retry the wrong one.
    $pro = createTenant('bio-batch-partial');
    Http::fake([
        'example.com/up*' => Http::response('<html><body><a href="https://www.instagram.com/theartist">IG</a></body></html>', 200),
        'example.com/down*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($pro, [
        'https://example.com/up',
        'https://example.com/down',
    ]);

    expect($result['outcome'])->toBe('partial')
        ->and($result['pages'])->toBe(1)
        ->and($result['pages_unavailable'])->toBe(1)
        ->and($result['observations'])->toBe(1);
});

it('treats a wholly unreachable batch the same as a single dead page', function () {
    Http::fake(['*' => Http::response('', 500)]);

    $result = app(LinkInBioImporter::class)->import(createTenant('bio-batch-dead'), [
        'https://example.com/a',
        'https://example.com/b',
    ]);

    expect($result['outcome'])->toBe('unavailable')
        ->and($result['observations'])->toBe(0);
});

it('records a bio harvest under its own run kind', function () {
    // routing.import_runs has accepted kind='bio_harvest' since the schema
    // landed; nothing could write it until the importer took a list.
    $pro = createTenant('bio-harvest-kind');
    bioPage('<html><body><a href="https://www.instagram.com/theartist">IG</a></body></html>');

    app(LinkInBioImporter::class)->import($pro, ['https://example.com/profile'], 'bio_harvest');

    $run = DB::table('routing.import_runs')->where('user_id', $pro->id)->first();
    expect($run->kind)->toBe('bio_harvest')
        ->and(DB::table('routing.link_observations')->where('user_id', $pro->id)->value('source'))->toBe('bio_harvest');
});

it('refuses an unknown run kind rather than writing one the schema rejects', function () {
    // The CHECK on import_runs.kind is the real authority; silently coercing
    // beats a 500 from a typo'd caller, and a wrong-but-valid kind is visible.
    $pro = createTenant('bio-bad-kind');
    bioPage('<html><body><a href="https://www.instagram.com/theartist">IG</a></body></html>');

    app(LinkInBioImporter::class)->import($pro, ['https://example.com/profile'], 'not_a_kind');

    expect(DB::table('routing.import_runs')->where('user_id', $pro->id)->value('kind'))->toBe('link_in_bio');
});

it('redacts a secret-shaped param from source_url and from detail.pages (#SEC-1)', function () {
    $pro = createTenant('bio-secret-url');
    Http::fake([
        '*' => Http::response('<html><body><a href="https://www.instagram.com/theartist">IG</a></body></html>', 200),
    ]);

    app(LinkInBioImporter::class)->import($pro, [
        'https://example.com/one?token=eyJhbGciOiJIUzI1NiJ9.aaa.bbb',
        'https://example.com/two?page=2',
    ]);

    $run = DB::table('routing.import_runs')->where('user_id', $pro->id)->first();
    expect($run->source_url)->toContain('token=[redacted]')
        ->and($run->source_url)->not->toContain('eyJhbGci');

    $detail = json_decode($run->detail, true);
    expect($detail['pages'][0])->toContain('token=[redacted]')
        ->and($detail['pages'][0])->not->toContain('eyJhbGci')
        ->and($detail['pages'][1])->toContain('page=2');
});

it('spends one link budget across the whole batch', function () {
    // The cap is the RUN's, not the page's: 20 pages must not buy 20x the
    // routing work a single page gets.
    $pro = createTenant('bio-batch-budget');
    $page = function (string $prefix): string {
        $links = '';
        for ($i = 0; $i < 80; $i++) {
            $links .= '<a href="https://'.$prefix.$i.'.test/x">L</a>';
        }

        return '<html><body>'.$links.'</body></html>';
    };
    Http::fake([
        'example.com/one*' => Http::response($page('first'), 200),
        'example.com/two*' => Http::response($page('second'), 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($pro, [
        'https://example.com/one',
        'https://example.com/two',
    ]);

    // 80 distinct links per page, two pages, cap 100 — the second page is cut
    // off rather than doubling the spend to 160.
    expect($result['observations'])->toBe(100);
});

// ── #R2: nothing the owner published leaves without a trace ──────────────────
// themilleraffect's Linktree carried https://canva.link/hxwh4ybxzn38wkg. It
// produced an observation with verdict=reject, block_reason=public-suffix-host
// and then nothing at all: no content item, absent from the public wire, no
// log line. canva.link is a PSL PRIVATE-section entry, so the eTLD+1 check
// found no registrable domain and the canonicaliser refused the URL — a
// structural failure to derive a key, read downstream as "this link is bad".

it('keeps a link whose host is itself a privately-registered suffix', function () {
    $pro = createTenant('bio-private-suffix');
    bioPage('<html><body>
        <a href="https://canva.link/hxwh4ybxzn38wkg?utm_source=linktree">My portfolio</a>
    </body></html>');

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/themilleraffect');

    // An unknown registrable key, so it takes the probe arm like any other
    // unrecognised domain — and CommerceProbeJob cards every miss. What
    // matters for #R2 is that it is no longer in the bucket that cards nothing.
    expect($result['dropped'])->toBe(0);
});

it('probes a private-suffix host instead of discarding it', function () {
    Queue::fake();
    $pro = createTenant('bio-private-suffix-probe');
    bioPage('<html><body><a href="https://canva.link/hxwh4ybxzn38wkg">Portfolio</a></body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/themilleraffect');

    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'canva.link'));
});

it('cards a private-suffix link directly once the probe budget is spent', function () {
    // Past the budget an unknown link must still land somewhere visible.
    $pro = createTenant('bio-private-suffix-starved');
    $links = '<a href="https://canva.link/hxwh4ybxzn38wkg">Portfolio</a>';
    for ($i = 0; $i < 8; $i++) {
        $links = '<a href="https://unknown'.$i.'.test/x">L</a>'.$links;
    }
    bioPage('<html><body>'.$links.'</body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/themilleraffect');

    $urls = array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url');
    expect($urls)->toContain('https://canva.link/hxwh4ybxzn38wkg');
});

it('keeps a bare platform host as a link rather than connecting it', function () {
    // Admitting private suffixes also admits the bare platform hosts that sit
    // in the suffix-override table (square.site, myshopify.com). Those match
    // their own brand's detectors with no tenant in the URL, so the thing to
    // hold is that low confidence keeps them a plain card — a CTA pointing at
    // Square's own homepage is exactly the visible error the thresholds exist
    // to prevent.
    $pro = createTenant('bio-bare-platform');
    bioPage('<html><body><a href="https://square.site/">Order</a></body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/bare');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

it('does not record a private-suffix link as rejected', function () {
    $pro = createTenant('bio-private-suffix-verdict');
    bioPage('<html><body><a href="https://canva.link/hxwh4ybxzn38wkg">Portfolio</a></body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/themilleraffect');

    $observation = DB::table('routing.link_observations')->where('user_id', $pro->id)->first();
    expect($observation->verdict)->not->toBe('reject');
});

it('counts a dropped link as dropped, not as noted', function () {
    // own-infra is a reject that SHOULD stay uncarded — the point is that the
    // ledger stops claiming a card it never made.
    $pro = createTenant('bio-drop-ledger');
    bioPage('<html><body><a href="https://someone.partna.au/">Me</a></body></html>');

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/dropper');

    expect($result['dropped'])->toBe(1)
        ->and($result['noted'])->toBe(0);
});

it('logs every dropped link with the reason it was dropped', function () {
    $pro = createTenant('bio-drop-log');
    bioPage('<html><body><a href="https://someone.partna.au/">Me</a></body></html>');

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $event, array $ctx) => $event === 'routing.link_dropped'
            && $ctx['reason'] === 'own-infra'
            && $ctx['url'] === 'https://someone.partna.au/');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/dropper');
});

it('records why each link was dropped in the import run detail', function () {
    $pro = createTenant('bio-drop-detail');
    bioPage('<html><body>
        <a href="https://someone.partna.au/">Me</a>
        <a href="https://bit.ly/xyz">Short</a>
    </body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/dropper');

    $detail = json_decode(DB::table('routing.import_runs')->where('user_id', $pro->id)->value('detail'), true);
    expect($detail['dropped_reasons'])->toBe(['own-infra' => 1, 'shortener' => 1]);
});
