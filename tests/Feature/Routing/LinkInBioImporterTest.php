<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Content\LinkPoolReader;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Fixtures\Recorded;

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
// N of the user's daily slots and misreport what happened.

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
        for ($i = 0; $i < 170; $i++) {
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

    // 170 distinct links per page, two pages, cap 300 (T9) — the second page
    // is cut off rather than doubling the spend to 340.
    expect($result['observations'])->toBe(300);
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

// ── #SEC-7: a secret-bearing query param must not reach the probe dispatch ──

it('redacts a secret-shaped query param before dispatching the commerce probe job', function () {
    Queue::fake();
    $pro = createTenant('bio-secret-probe');
    bioPage('<html><body><a href="https://canva.link/hxwh4ybxzn38wkg?access_token=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig">Portfolio</a></body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/secretprobe');

    Queue::assertPushed(CommerceProbeJob::class, function ($job) {
        expect($job->url)->not->toContain('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig');
        expect($job->url)->toContain('access_token=[redacted]');

        return true;
    });
});

it('leaves a non-secret query param on the probe dispatch unchanged', function () {
    Queue::fake();
    $pro = createTenant('bio-benign-probe');
    bioPage('<html><body><a href="https://canva.link/hxwh4ybxzn38wkg?ref=portfolio">Portfolio</a></body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/benignprobe');

    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => $job->url === 'https://canva.link/hxwh4ybxzn38wkg?ref=portfolio');
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

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/dropper');

    // FI-3 (2026-08-20): the shortener is no longer a drop. Expansion is
    // attempted (here the fake answers 200 with no redirect, so it fails)
    // and the unexpandable short link becomes a CARD — zero-loss — leaving
    // own-infra as the one genuine drop.
    $detail = json_decode(DB::table('routing.import_runs')->where('user_id', $pro->id)->value('detail'), true);
    expect($detail['dropped_reasons'])->toBe(['own-infra' => 1])
        ->and($result['noted'])->toBe(1)
        ->and($result['dropped'])->toBe(1);
});

// ── N2: pages that ship no anchors ──────────────────────────────────────────
// linkin.bio delivers an empty Ember shell, so the anchor harvest returned zero
// for every one of these accounts and the floor was all that stood between the
// user and an empty site. LinkInBioApiUnroller reads the same public API the
// page's own JavaScript calls. Shell below is the real 2026-08-19 response body,
// trimmed — what matters is that it has no <a> at all.

it('unrolls a client-rendered linkin.bio page through its API, not its empty shell', function () {
    $pro = createTenant('bio-linkinbio');
    Http::fake([
        'api-prod.linkin.bio/*' => Http::response(['linkinbio_page' => ['linkinbio_blocks' => [
            ['block_type' => 'button_list', 'block_data' => ['enabled' => true, 'buttons' => [
                ['url' => 'https://www.sevenrooms.com/explore/supernormalaustralia/reservations/create/search', 'title' => 'RESERVATIONS', 'enabled' => true],
                ['url' => 'https://supernormal.net.au/menu', 'title' => 'MENU', 'enabled' => true],
            ]]],
        ]]], 200),
        'linkin.bio/*' => Http::response('<html><head><title>Linkin.bio</title></head><body><script src="/assets/linkinbio.js"></script></body></html>', 200),
    ]);
    Queue::fake();

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linkin.bio/supernormal_180');

    expect($result['observations'])->toBe(2)
        // The floor is the tell. It fires only when the unroll yielded nothing,
        // so a false here IS the regression pin: the page unrolled for real.
        ->and($result['bio_url_seeded'])->toBeFalse();
});

it('merges the shell\'s footer social anchors into an API unroll (F12)', function () {
    // F12 (2026-08-20, the natalieannehair stan.store trace): the API unroll
    // returns the platform's TILE links only, and her TikTok and Facebook sat
    // as plain anchors in the delivered shell, unseen. The social-classified
    // anchors merge back in; the shell's own asset/legal links must NOT ride
    // along (they don't classify as social).
    $pro = createTenant('bio-stan-socials');
    Http::fake([
        'api.stanwith.me/*' => Http::response(['store' => ['pages' => [
            ['type' => 'link', 'status' => 2, 'data' => ['product' => ['link' => [
                'url' => 'https://supernormal.net.au/menu',
            ]]]],
        ]]], 200),
        'stan.store/*' => Http::response('<html><body>
            <a href="https://www.tiktok.com/@natalieannehair">TikTok</a>
            <a href="https://www.facebook.com/natalieannehairstylist">Facebook</a>
            <a href="https://assets.stanwith.me/legal/terms-of-service.pdf">Terms</a>
        </body></html>', 200),
        '*' => Http::response('<html><body>ok</body></html>', 200),
    ]);
    Queue::fake();

    app(LinkInBioImporter::class)->import($pro, 'https://stan.store/Natalieanne');

    $observations = DB::table('routing.link_observations')->where('source', 'link_in_bio')->get();
    expect($observations->pluck('surface_key')->all())
        ->toContain('tiktok.profile', 'facebook.profile')
        ->and($observations->filter(fn ($o) => str_contains((string) $o->raw_url, 'assets.stanwith.me')))
        ->toHaveCount(0)
        // The merge is ADDITIVE: the API tile must still route. An
        // implementation that replaced tiles with the anchor socials would
        // satisfy every assertion above — this one is the additive pin.
        ->and($observations->filter(fn ($o) => str_contains((string) $o->raw_url, 'supernormal.net.au/menu')))
        ->toHaveCount(1);
});

it('finds footer socials even when the stan API answers zero outward tiles (F12)', function () {
    // The empty-array answer is the COMMON one for stan (hosted products
    // only). Before F12 it also silenced the shell's social anchors entirely —
    // the ?? chain treats [] as a real answer, so the anchor pass never ran
    // and the zero-yield floor fired on a page with links in plain sight.
    $pro = createTenant('bio-stan-empty');
    Http::fake([
        'api.stanwith.me/*' => Http::response(['store' => ['pages' => []]], 200),
        'stan.store/*' => Http::response('<html><body>
            <a href="https://www.tiktok.com/@natalieannehair">TikTok</a>
        </body></html>', 200),
        '*' => Http::response('<html><body>ok</body></html>', 200),
    ]);
    Queue::fake();

    $result = app(LinkInBioImporter::class)->import($pro, 'https://stan.store/Natalieanne');

    expect(DB::table('routing.link_observations')->where('source', 'link_in_bio')->pluck('surface_key')->all())
        ->toContain('tiktok.profile')
        ->and($result['bio_url_seeded'])->toBeFalse();
});

it('falls back to the anchor harvest when the linkin.bio API cannot be read', function () {
    // Later going down, or revving its API, must cost the user nothing they had
    // before: the anchor pass still runs and the zero-yield floor still fires.
    $pro = createTenant('bio-linkinbio-down');
    Http::fake([
        'api-prod.linkin.bio/*' => Http::response('', 503),
        'linkin.bio/*' => Http::response('<html><body><script src="/assets/linkinbio.js"></script></body></html>', 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linkin.bio/supernormal_180');

    expect($result['observations'])->toBe(0)
        ->and($result['bio_url_seeded'])->toBeTrue();
});

// ── Cloudflare-blocked hosts (2026-08-19) ────────────────────────────────────
// Five detector hosts — bio.link, heylink.me, direct.me, lnk.bio, beacons.ai —
// answer 403 to BOTH of SafeUrlFetcher's User-Agent attempts: three behind a
// managed JS challenge, two behind a hard WAF rule. Nothing we can send passes
// them, so the only question is what the user is left with.

it('cards the bio URL when the host refuses us outright, instead of dropping it', function () {
    // The regression this pins: the floor used to require $fetched > 0, so a
    // wholly unreachable page skipped it. Combined with InstagramAutoSync
    // dispatching this job and continuing ("Nothing about the bio-link URL
    // itself is persisted"), the user's bio link vanished entirely — no links
    // AND no card, strictly worse than the inert card linkin.bio produced.
    $pro = createTenant('bio-cf-blocked');
    Http::fake(['*' => Http::response('<html><head><title>Just a moment...</title></head></html>', 403)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/blocked');

    expect($result['outcome'])->toBe('unavailable')
        ->and($result['pages'])->toBe(0)
        ->and($result['pages_unavailable'])->toBe(1)
        ->and($result['observations'])->toBe(0)
        ->and($result['bio_url_seeded'])->toBeTrue();

    expect(array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url'))
        ->toContain('https://example.com/blocked');
});

it('cards the first page when an entire batch is refused', function () {
    $pro = createTenant('bio-cf-batch');
    Http::fake(['*' => Http::response('', 403)]);

    $result = app(LinkInBioImporter::class)->import($pro, [
        'https://example.com/a',
        'https://example.com/b',
    ]);

    // One card for the acquisition, not one per dead page — the batch is the unit.
    expect($result['bio_url_seeded'])->toBeTrue()
        ->and(array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url'))
        ->toBe(['https://example.com/a']);
});

// ── The other three client-rendered hosts (2026-08-19) ───────────────────────
// taplink.cc and stan.store join linkin.bio on the API seam; liinks.co needs no
// request at all — its links are inlined in the body we already fetched.

it('unrolls a client-rendered taplink.cc page through its API', function () {
    $pro = createTenant('bio-taplink');
    Http::fake([
        'taplink.cc/*/api/page/get.json' => Http::response(['result' => 'success', 'response' => [
            'fields' => [['items' => [
                ['block_type_name' => 'link', 'options' => ['title' => 'IG', 'value' => 'https://www.instagram.com/theartist']],
            ]]],
        ]], 200),
        'taplink.cc/*' => Http::response('<html><body><div id="app"></div></body></html>', 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://taplink.cc/theartist');

    expect($result['observations'])->toBe(1)
        ->and($result['bio_url_seeded'])->toBeFalse();
});

it('unrolls a client-rendered liinks.co page from the body it already has', function () {
    $pro = createTenant('bio-liinks');
    $context = json_encode(['USER_DATA' => ['links' => [
        ['linkType' => 'LINK', 'target' => 'https://www.instagram.com/theartist', 'isHidden' => false, 'isDeleted' => false],
    ]]]);
    // Zero anchors, links inlined — exactly the shape that read as an empty page.
    Http::fake(['*' => Http::response(
        '<html><body><div id="root"></div><script>window.CONTEXT = '.$context.';</script></body></html>',
        200,
        ['Content-Type' => 'text/html']
    )]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://liinks.co/theartist');

    expect($result['observations'])->toBe(1)
        ->and($result['bio_url_seeded'])->toBeFalse();
});

// ── Telling a bot-block apart from a dead page (2026-08-19) ──────────────────
// `pages_unavailable: 1` was the ONLY signal, and it reads identically whether
// the host refused us, the page 404'd, or the domain is dead. Four detector
// hosts serve a Cloudflare refusal at the edge, so that difference decides
// whether a fix is even possible — and nothing surfaced it.

it('names a Cloudflare challenge as a bot block, not a generic failure', function () {
    $pro = createTenant('bio-reason-challenge');
    Http::fake(['*' => Http::response('<html><head><title>Just a moment...</title></head></html>', 403)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/blocked');

    expect($result['unavailable_reasons'])->toBe(['bot_challenge' => 1]);
});

it('names a hard Cloudflare WAF block as a bot block too', function () {
    // beacons.ai's shape — a firewall rule, not a solvable challenge, but the
    // same conclusion for us: the host is refusing this caller.
    $pro = createTenant('bio-reason-waf');
    Http::fake(['*' => Http::response('<html><title>Attention Required! | Cloudflare</title></html>', 403)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/blocked');

    expect($result['unavailable_reasons'])->toBe(['bot_challenge' => 1]);
});

it('does NOT call a missing page a bot block', function () {
    // The whole point of the split. A 404 is the page's problem, not ours, and
    // must not raise a signal that says "a host is refusing us".
    $pro = createTenant('bio-reason-404');
    Http::fake(['*' => Http::response('<html><body>Not found</body></html>', 404)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/gone');

    expect($result['unavailable_reasons'])->toBe(['not_found' => 1]);
});

it('does not mistake a plain 403 with no challenge markers for a bot block', function () {
    // A genuinely private page is forbidden, not fighting us.
    $pro = createTenant('bio-reason-403');
    Http::fake(['*' => Http::response('<html><body>This page is private.</body></html>', 403)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/private');

    expect($result['unavailable_reasons'])->toBe(['refused' => 1]);
});

it('logs a distinct, greppable line when a host refuses us', function () {
    // The "would I ever notice?" test. This line is the trigger to revisit a
    // residential-proxy vendor; without it the block stays invisible.
    Log::spy();
    $pro = createTenant('bio-reason-log');
    Http::fake(['*' => Http::response('<html><title>Just a moment...</title></html>', 403)]);

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/blocked');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => $message === 'platforms.link_in_bio.host_blocked_us'
            && $context['host'] === 'example.com'
            && $context['status'] === 403)
        ->once();
});

it('stays quiet for an ordinary dead page', function () {
    // The counter-check: if this warning fired on every 404 it would be noise,
    // and noise is how a real signal gets ignored.
    Log::spy();
    $pro = createTenant('bio-reason-quiet');
    Http::fake(['*' => Http::response('', 404)]);

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/gone');

    // Bare, on purpose. `shouldNotHaveReceived('warning', [$message])` matches
    // a call whose FULL arg list is that one string — which never happens, so
    // it passes vacuously; and `shouldHaveReceived(...)->never()` asserts >=1
    // call before never() is reached. A 404 import warns about nothing at all,
    // so "no warnings" is both true and the thing worth pinning.
    // Pin the MESSAGE with a wildcard context. A bare shouldNotHaveReceived
    // ('warning') is too strict — the test env emits an unrelated
    // `feature_availability.resolve_overrides_failed` — while passing only the
    // message string matches a one-arg call that never happens, so it passes
    // vacuously. Two args, message fixed, context any.
    Log::shouldNotHaveReceived('warning', ['platforms.link_in_bio.host_blocked_us', Mockery::any()]);
});

it('records the reasons on the run itself, so they can be counted in SQL', function () {
    // A log line answers "did it happen"; the run detail answers "how often,
    // and to whom" without shipping logs anywhere.
    $pro = createTenant('bio-reason-detail');
    Http::fake([
        'example.com/up*' => Http::response('<html><body><a href="https://www.instagram.com/a">IG</a></body></html>', 200),
        'example.com/blocked*' => Http::response('<html><title>Just a moment...</title></html>', 403),
        'example.com/gone*' => Http::response('', 404),
    ]);

    app(LinkInBioImporter::class)->import($pro, [
        'https://example.com/up',
        'https://example.com/blocked',
        'https://example.com/gone',
    ]);

    $detail = json_decode((string) DB::table('routing.import_runs')
        ->where('user_id', $pro->id)->value('detail'), true);

    expect($detail['unavailable_reasons'])->toBe(['bot_challenge' => 1, 'not_found' => 1]);
});

it('reads Linktree music-embed links from __NEXT_DATA__ — the full sammy.pdf replay (FI-5)', function () {
    // The real linktr.ee/samakhurst shape: 6 published links, 3 of them
    // embed types that render as players with NO anchors. Pre-FI-5 the
    // anchor harvest saw only 3; the __NEXT_DATA__ arm reads all 6.
    Queue::fake();
    Cache::flush();
    $pro = createTenant('fi5-linktree');

    $nextData = json_encode(['props' => ['pageProps' => ['links' => [
        ['title' => 'Open Your Eyes (And Dance)', 'url' => 'https://open.spotify.com/track/5WOkoJzd6nDzKJXlVgVU5q', 'type' => 'SPOTIFY_SONG'],
        ['title' => 'Are You The One (Sam Akhurst Remix)', 'url' => 'https://soundcloud.com/sam-akhurst/are-you-the-one-remix', 'type' => 'SOUNDCLOUD_SONG'],
        ['title' => 'Spotify', 'url' => 'https://open.spotify.com/artist/4WoNQlu21ftnkouDsSUtmS', 'type' => 'SPOTIFY_ARTIST'],
        ['title' => 'Apple Music', 'url' => 'https://music.apple.com/au/artist/sam-akhurst/1810969283', 'type' => 'CLASSIC'],
        ['title' => 'SoundCloud', 'url' => 'https://on.soundcloud.com/fh433tMk6lU9xgP3TM', 'type' => 'CLASSIC'],
        ['title' => 'Instagram', 'url' => 'https://www.instagram.com/ssml.wav', 'type' => 'INSTAGRAM_PROFILE'],
    ]]]]);

    Http::fake([
        'linktr.ee/*' => Http::response('<html><body><script id="__NEXT_DATA__" type="application/json">'.$nextData.'</script></body></html>', 200, ['Content-Type' => 'text/html']),
        'open.spotify.com/oembed*' => Http::response(json_encode(['title' => 'Open Your Eyes (And Dance)', 'thumbnail_url' => null]), 200, ['Content-Type' => 'application/json']),
        'open.spotify.com/embed/track/*' => Http::response('{"artists":[{"uri":"artist/4WoNQlu21ftnkouDsSUtmS"}]}', 200),
        'soundcloud.com/oembed*' => Http::response(json_encode(['title' => 'Are You The One (Sam Akhurst Remix)', 'thumbnail_url' => null, 'author_url' => 'https://soundcloud.com/sam-akhurst']), 200, ['Content-Type' => 'application/json']),
        'on.soundcloud.com/*' => Http::response('', 302, ['Location' => 'https://soundcloud.com/sam-akhurst?ref=clipboard']),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/samakhurst', 'bio_harvest');

    // The expected best-case outcome from the run plan, verbatim: four
    // connections (Spotify artist, Apple Music artist, SoundCloud artist via
    // short-link expansion, Instagram), two Listen items, nothing carded.
    expect($result['items'])->toBe(2)
        ->and($result['connected'])->toBe(4)
        ->and($result['noted'])->toBe(0)
        ->and($result['dropped'])->toBe(0);

    $connections = IntegrationConnection::where('user_id', $pro->id)->get()
        ->map(fn ($c) => $c->surface_key.':'.$c->resource_id)->sort()->values()->all();
    expect($connections)->toBe([
        'apple_music.artist:1810969283',
        'instagram.profile:ssml.wav',
        'soundcloud.player:sam-akhurst',
        'spotify.player:4WoNQlu21ftnkouDsSUtmS',
    ]);

    $items = DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->whereIn('kind', ['track'])->get();
    expect($items)->toHaveCount(2)
        ->and($items->pluck('headline_cache')->sort()->values()->all())
        ->toBe(['Are You The One (Sam Akhurst Remix)', 'Open Your Eyes (And Dance)']);
});

it('folds a second URL that canonicalizes identically instead of carding it (FI-8)', function () {
    // Round 3 live shape: the same Spotify artist arrived as a __NEXT_DATA__
    // tile AND as the shell's own anchor, differing only in tracking params.
    // Same canonical → silent fold; a card here duplicated the connection.
    Queue::fake();
    $pro = createTenant('fi8-canonical-fold');
    bioPage('<html><body>
        <a href="https://www.instagram.com/theartist?utm_source=a">One</a>
        <a href="https://instagram.com/theartist?utm_source=b">Two</a>
    </body></html>');

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    // 'folded', its own bucket (critic pass 2): 'noted' claims a card
    // exists, and a same-canonical fold deliberately writes none.
    expect($result['connected'])->toBe(1)
        ->and($result['folded'])->toBe(1)
        ->and($result['noted'])->toBe(0);
    expect(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->where('kind', 'link')->count())->toBe(0)
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1);
});

// The whole chain against the page that exposed it (themetapunter, 2026-08-24):
// clk.bio is recognised, its four owner links are PLACED, its seven share
// widgets and five hidden SEO backlinks are not.
it('unrolls a real Lnk.Bio page to the owner\'s links and nothing else', function () {
    $pro = createTenant('bio-clkbio');
    bioPage(Recorded::html('linkinbio/lnkbio.clkbio.html'));

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/TheMetaPunter');

    $placed = DB::table('routing.link_observations')
        ->where('source', 'link_in_bio')->where('verdict', 'place')
        ->pluck('surface_key')->sort()->values()->all();

    expect($result['outcome'])->toBe('ok')
        ->and($placed)->toBe(['instagram.profile', 'kick.channel', 'tiktok.profile', 'youtube.channel']);

    // "Nothing else" has to mean CARDS too, not just placed surfaces. Before
    // the vendor-chrome and share-widget rules this page seeded EIGHT junk
    // cards — Lnk.Bio's own "Get Lnk.Bio" referral link plus all seven share
    // buttons — while the placed-surface assertion above stayed green.
    expect(DB::table('content.items')->where('kind', 'link')->count())->toBe(0);
    expect(DB::table('routing.link_observations')->count())->toBe(4);
});

// Split from the routing assertion above: a chained expect() aborts on its
// first failure, so bundling these would prove only whichever ran first.
// Lnk.Bio's five display:none backlinks must never reach the harvest at all —
// unlike the share widgets they are not "observed and declined", they are gone.
it('never even observes the bio platform\'s hidden SEO backlinks', function (string $domain) {
    $pro = createTenant('bio-clkbio-hidden-'.substr(md5($domain), 0, 6));
    bioPage(Recorded::html('linkinbio/lnkbio.clkbio.html'));

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/TheMetaPunter');

    expect(DB::table('routing.link_observations')->pluck('registrable_key')->all())
        ->not->toContain($domain);
})->with(['cruciverba.io', 'petrolprice.sg', 'mediakit.bio', 'menoo.me', 'calcio.dev']);

// Independent of the routing assertions above, because a negative assertion
// that passes for the wrong reason is invisible. Asserted on the RAW harvest
// count, which is the number the hidden-anchor rule actually moves: 28 anchors
// on the page, 23 after its five display:none SEO backlinks are dropped.
// (An earlier draft counted classify()-able links instead and read 4 either
// way — those backlinks classify as nothing, so the rule was invisible to it.)
it('keeps the bio platform\'s hidden anchors out of the harvest entirely', function () {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks(
        Recorded::html('linkinbio/lnkbio.clkbio.html'),
        'https://clk.bio/TheMetaPunter'
    );

    expect($links)->toHaveCount(23);
});

// A floor card is only correct while the page yields nothing. A user can
// re-paste the same aggregator through POST /routing/links (RoutingController
// dispatches LinkInBioScanJob), so a host that was rate-limited on the first
// try — or one we have only just learned to read, as clk.bio was on
// 2026-08-24 — leaves the owner holding BOTH the inert card and the links it
// contains.
//
// The fake is a CLOSURE over a flag, not two Http::fake() calls: repeated
// fake(['*' => …]) stacks stubs and the first match wins, so the 403 would
// answer both runs. A fakeSequence() is wrong too — SafeUrlFetcher retries a
// 403 with a second User-Agent, so one logical fetch eats two entries.
it('retires the floor card once the same page finally unrolls', function () {
    $pro = createTenant('bio-refloor');
    $readable = false;
    $html = '<html><body>
        <a href="https://www.instagram.com/refloor">IG</a>
        <a href="https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4">Spotify</a>
    </body></html>';
    // NB `use (&$readable)`: an arrow fn captures by VALUE, so fn() would
    // freeze the flag at false and answer 403 to both runs.
    Http::fake(function () use (&$readable, $html) {
        return $readable
            ? Http::response($html, 200, ['Content-Type' => 'text/html'])
            : Http::response('', 403);
    });

    $first = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/refloor');

    expect($first['outcome'])->toBe('unavailable')
        ->and($first['bio_url_seeded'])->toBeTrue()
        ->and(DB::table('content.items')->where('kind', 'link')->whereNull('removed_at')->count())->toBe(1);

    $readable = true;
    $second = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/refloor');

    expect($second['outcome'])->toBe('ok');
    expect(DB::table('content.items')->where('kind', 'link')->whereNull('removed_at')->count())->toBe(0);
});

// The retire is keyed on the page we just unrolled, not "tidy up the pool".
it('leaves a card the owner holds for some other page alone', function () {
    $pro = createTenant('bio-keepother');
    $unrollable = false;
    Http::fake(function () use (&$unrollable) {
        return $unrollable
            ? Http::response('<html><body><a href="https://www.instagram.com/keepother">IG</a></body></html>', 200, ['Content-Type' => 'text/html'])
            : Http::response('', 403);
    });

    app(LinkInBioImporter::class)->import($pro, 'https://beacons.ai/keepother');
    expect(DB::table('content.items')->where('kind', 'link')->whereNull('removed_at')->count())->toBe(1);

    $unrollable = true;
    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/different');

    // The beacons card survives — nothing unrolled THAT url.
    expect(DB::table('content.items')->where('kind', 'link')->whereNull('removed_at')->count())->toBe(1);
});

// A bio_harvest run carries N pages and $observations counts the whole RUN, so
// "something unrolled" must not be read as "every page unrolled". One page
// coming back while another is still blocked would otherwise retire the
// blocked page's card — deleting a link the owner can still see nothing else
// for.
it('keeps the card of a page that is still down when a sibling page unrolls', function () {
    $pro = createTenant('bio-batch');
    $siblingUp = false;
    Http::fake(function (Request $request) use (&$siblingUp) {
        if (str_contains($request->url(), 'linktr.ee/up')) {
            return $siblingUp
                ? Http::response('<html><body><a href="https://www.instagram.com/batchup">IG</a></body></html>', 200, ['Content-Type' => 'text/html'])
                : Http::response('', 403);
        }

        return Http::response('', 403);
    });

    // The blocked page gets its floor card on its own run.
    app(LinkInBioImporter::class)->import($pro, ['https://beacons.ai/stilldown']);
    expect(DB::table('content.items')->where('kind', 'link')->whereNull('removed_at')->count())->toBe(1);

    // Now a batch: the sibling reads, the blocked one still 403s.
    $siblingUp = true;
    app(LinkInBioImporter::class)->import($pro, ['https://linktr.ee/up', 'https://beacons.ai/stilldown'], 'bio_harvest');

    // beacons.ai never unrolled, so its card is still the only thing standing
    // for that link.
    expect(DB::table('content.items')->where('kind', 'link')->whereNull('removed_at')->count())->toBe(1);
});

// ── SCALE-5: pacing between successive page fetches ─────────────────────────
// 50 rapid sequential requests at one bio-link host is exactly the shape a
// WAF is built to catch, and a block degrades every future import from that
// host, not just this one. Sleep::fake() records durations without a real
// wait, and phpunit.xml pins PARTNA_LINK_IN_BIO_PAGE_DELAY_MS=0 so every test
// that does NOT override it stays instant.

// Fixture bodies are irrelevant to pacing itself; using the four real
// recorded pages (rather than hand-typed HTML) keeps the fetch loop exercised
// against genuine markup while four distinct page URLs give the loop
// something to actually iterate over.
function bioPacingPages(): array
{
    return [
        'https://example.com/pace1' => Recorded::html('linkinbio/linktree.mixed.html'),
        'https://example.com/pace2' => Recorded::html('linkinbio/linktree.hair.html'),
        'https://example.com/pace3' => Recorded::html('linkinbio/linkinbio.spa.html'),
        'https://example.com/pace4' => Recorded::html('linkinbio/lnkbio.clkbio.html'),
    ];
}

it('paces between pages on a multi-page import, never before the first or after the last', function () {
    Queue::fake(); // no CommerceProbeJob dispatch (QUEUE_CONNECTION=sync would run it inline)
    Sleep::fake();
    config(['partna.routing.link_in_bio.page_delay_ms' => 300]);

    $pages = bioPacingPages();
    Http::fake(fn (Request $request) => Http::response($pages[$request->url()] ?? '', 200, ['Content-Type' => 'text/html']));

    $pro = createTenant('bio-pace-multi');
    app(LinkInBioImporter::class)->import($pro, array_keys($pages), 'bio_harvest');

    // 4 pages, 3 gaps — a sleep after every fetch but the last one, at the
    // configured duration. If this ever reads 4 the gate before the first
    // fetch has gone missing; if it reads 0 the loop stopped pacing at all.
    Sleep::assertSequence(array_fill(0, 3, Sleep::for(300)->milliseconds()));
});

it('sleeps zero times on a single-page import, the overwhelmingly common case', function () {
    Queue::fake();
    Sleep::fake();
    config(['partna.routing.link_in_bio.page_delay_ms' => 300]);
    bioPage(Recorded::html('linkinbio/linktree.mixed.html'));

    $pro = createTenant('bio-pace-single');
    app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    // A single fetch has no "next page" to pace before — if this ever slept
    // once, every ordinary one-URL import would pay the delay for nothing.
    Sleep::assertNeverSlept();
});

it('never sleeps when the configured delay is zero', function () {
    Queue::fake();
    Sleep::fake();
    config(['partna.routing.link_in_bio.page_delay_ms' => 0]);

    $pages = bioPacingPages();
    Http::fake(fn (Request $request) => Http::response($pages[$request->url()] ?? '', 200, ['Content-Type' => 'text/html']));

    $pro = createTenant('bio-pace-zero');
    app(LinkInBioImporter::class)->import($pro, array_keys($pages), 'bio_harvest');

    // Operators must be able to switch pacing off entirely, not just turn it down.
    Sleep::assertNeverSlept();
});

it('paces after a failed fetch too, not only after a successful one', function () {
    Queue::fake();
    Sleep::fake();
    config(['partna.routing.link_in_bio.page_delay_ms' => 50]);

    Http::fake([
        'example.com/pace-fail' => Http::response('', 403),
        'example.com/pace-ok' => Http::response(
            Recorded::html('linkinbio/linktree.mixed.html'), 200, ['Content-Type' => 'text/html']
        ),
        'example.com/pace-gone' => Http::response('', 404),
    ]);

    $pro = createTenant('bio-pace-failure');
    app(LinkInBioImporter::class)->import($pro, [
        'https://example.com/pace-fail',
        'https://example.com/pace-ok',
        'https://example.com/pace-gone',
    ], 'bio_harvest');

    // 3 pages, 2 gaps, regardless of which pages actually 200'd — a refused
    // request still hit the host, and skipping the pace after a failure
    // would let exactly the retry burst that trips a WAF go unpaced.
    Sleep::assertSequence(array_fill(0, 2, Sleep::for(50)->milliseconds()));
});

it('returns the same outcome and counts whether or not pacing runs', function () {
    Queue::fake();
    $pages = bioPacingPages();
    Http::fake(fn (Request $request) => Http::response($pages[$request->url()] ?? '', 200, ['Content-Type' => 'text/html']));

    $unpaced = app(LinkInBioImporter::class)->import(createTenant('bio-pace-identity-off'), array_keys($pages), 'bio_harvest');

    Sleep::fake();
    config(['partna.routing.link_in_bio.page_delay_ms' => 300]);
    Http::fake(fn (Request $request) => Http::response($pages[$request->url()] ?? '', 200, ['Content-Type' => 'text/html']));

    $paced = app(LinkInBioImporter::class)->import(createTenant('bio-pace-identity-on'), array_keys($pages), 'bio_harvest');

    // Pacing must be invisible to the result contract — a caller (the queued
    // job's log line, the run detail row) should never be able to tell pacing
    // ran just by reading what import() returned.
    expect($paced)->toBe($unpaced);
});

it('connects a harvested Square Appointments deep link as the booking provider, not a card', function () {
    Queue::fake();
    $pro = createTenant('bio-square');
    bioPage('<html><body>
        <a href="https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?buttonTextColor=ffffff&color=000000&team_member_id=TM-qREuvGrHGnJ5Z">Book</a>
    </body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/bio-square', 'link_in_bio');

    $row = IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('surface_key', 'square.book')->whereNull('deleted_at')->first();
    expect($row)->not->toBeNull()
        ->and($row->routing_class)->toBe('booking')
        ->and($row->is_active)->toBeTrue()
        ->and($row->payload['url'])->toContain('book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW')
        ->and($row->payload['url'])->toContain('team_member_id=TM-qREuvGrHGnJ5Z');
    expect(DB::table('routing.link_observations')->where('surface_key', 'square.book')->value('verdict'))->toBe('place');
});

it('folds a link that differs from a placed one only by query string, instead of carding it', function () {
    Queue::fake();
    $pro = createTenant('bio-ytq');
    bioPage('<html><body>
        <a href="https://www.youtube.com/@acmebarbers">YouTube</a>
        <a href="https://www.youtube.com/@acmebarbers?sub_confirmation=1">Subscribe</a>
    </body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/bio-ytq', 'link_in_bio');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', 'youtube')->whereNull('deleted_at')->count())->toBe(1);
    expect(DB::table('content.items')->where('user_id', $pro->id)->where('kind', 'link')->whereNull('removed_at')->count())->toBe(0);
});

it('hands an unknown store tile\'s discount code to the commerce probe that will mint the storefront', function () {
    Queue::fake();
    $pro = createTenant('bio-code');
    bioPage('<html><body>
        <a href="https://gammaplus.example.au">Gamma+ - CODE: TEEGAN10</a>
    </body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/bio-code', 'link_in_bio');

    Queue::assertPushed(CommerceProbeJob::class, fn (CommerceProbeJob $job): bool => str_contains($job->url, 'gammaplus.example.au') && $job->discountCode === 'TEEGAN10');
});
