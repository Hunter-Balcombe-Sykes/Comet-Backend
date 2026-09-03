<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Platforms\ScrapeCreators\LinktreeLinksNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Item 8 G3 (2026-09-01): the Linktree vendor lane's contract, pinned against
// a RECORDED live payload (tests/fixtures/recorded/scrapecreators-linktree.json
// — the real /v1/linktree answer for linktr.ee/ryanfitzsimons from the
// 2026-09-01 trial). Ground truth from that trial: the HTML parser yielded 3
// observations off this page, and the vendor returns the SAME 3. Two
// properties matter and every test serves one:
//
//  1. When the vendor answers usably, the unroll consumes its links exactly
//     as it consumes the __NEXT_DATA__ parse's — same URLs, same page order,
//     same observation count.
//  2. When the vendor answers any other way — 5xx, success-shaped husk, no
//     key, budget denied — the existing HTML parse runs completely unchanged.

function scLtFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-linktree.json')),
        true
    );
}

/** The recorded page's 3 links, as linktr.ee's own __NEXT_DATA__ ships them. */
function scLtNextDataHtml(array $socialUrls = []): string
{
    $doc = json_encode([
        'props' => ['pageProps' => [
            'links' => [
                ['type' => 'CLASSIC', 'url' => 'https://akrostudio.com.au/'],
                ['type' => 'CLASSIC', 'url' => 'https://jrlaus.com.au/jrl-launch-party-17th-march-workshop-18th-march/'],
                ['type' => 'TIKTOK_PROFILE', 'url' => 'https://www.tiktok.com/@ryanfitzbarber?_t=8k1neh3vbpi&_r=1'],
            ],
            'socialLinks' => array_map(fn (string $u) => ['url' => $u], $socialUrls),
        ]],
    ]);

    return '<!DOCTYPE html><html><body><div id="__next"></div>'
        .'<script id="__NEXT_DATA__" type="application/json">'.$doc.'</script></body></html>';
}

/** An anchor-free, payload-free shell: anything observed can only be the vendor's. */
function scLtShellHtml(): string
{
    return '<!DOCTYPE html><html><head><title>Linktree</title></head>'
        .'<body><div id="__next"></div><script src="/assets/app.js"></script></body></html>';
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();

    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.linkinbio', 100);
});

it('normalizes the recorded payload into the exact list the unroll consumes', function () {
    $normalizer = app(LinktreeLinksNormalizer::class);
    $page = $normalizer->normalize(scLtFixture());

    expect($page)->not->toBeNull()
        ->and($page['username'])->toBe('ryanfitzsimons')
        ->and($page['profilePictureUrl'])->toStartWith('https://ugc.production.linktr.ee/')
        ->and($page['links'])->toHaveCount(3);

    // The trailing whitespace is real in the recorded payload ("Book with me ").
    expect($page['links'][0])->toBe([
        'url' => 'https://akrostudio.com.au/',
        'title' => 'Book with me',
        'id' => 365910628,
        'type' => 'CLASSIC',
    ]);

    // Ground truth: the same 3 URLs the HTML parser observed, in page order.
    expect($normalizer->urls($page))->toBe([
        'https://akrostudio.com.au/',
        'https://jrlaus.com.au/jrl-launch-party-17th-march-workshop-18th-march/',
        'https://www.tiktok.com/@ryanfitzbarber?_t=8k1neh3vbpi&_r=1',
    ]);

    // Nothing billing-shaped survives normalization — credits_* must never
    // travel toward persistence.
    expect(json_encode($page))->not->toContain('credits');
});

it('omits optional keys rather than emitting null, mirroring the vendor', function () {
    $page = app(LinktreeLinksNormalizer::class)->normalize([
        'username' => 'someone',
        'links' => [['title' => '   ', 'url' => 'https://a.example/']],
    ]);

    expect($page['links'][0])->toBe(['url' => 'https://a.example/'])
        ->and($page)->not->toHaveKey('profilePictureUrl');
});

it('reads every husk shape as a vendor miss, never as an empty page', function () {
    $normalizer = app(LinktreeLinksNormalizer::class);

    // The NotFound quirk: billed, success-shaped, no page inside.
    expect($normalizer->normalize(['success' => true, 'credits_charged' => 1]))->toBeNull()
        // A page with no usable links must fall through to the HTML parse —
        // the vendor lane may never be the reason a page reads as empty.
        ->and($normalizer->normalize(['username' => 'someone', 'links' => []]))->toBeNull()
        ->and($normalizer->normalize(['username' => 'someone', 'links' => [['url' => 'notaurl']]]))->toBeNull()
        ->and($normalizer->normalize(['username' => '', 'links' => [['url' => 'https://a.example/']]]))->toBeNull();
});

it('serves the vendor links off an anchor-free shell — the recorded 3 observations', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scLtFixture()),
        'linktr.ee/*' => Http::response(scLtShellHtml(), 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://linktr.ee/ryanfitzsimons');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(3)
        ->and($result['bio_url_seeded'])->toBeFalse();
    // Nothing a harvester found ever auto-connects (owner, 2026-09-03): the
    // TikTok link off the vendor-normalized shell lands as a proposed
    // suggestion, not a live connection.
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'tiktok'])->exists())->toBeFalse();
    $intent = DB::table('routing.source_intents')
        ->where(['user_id' => $user->id, 'surface_key' => 'tiktok.profile'])->first();
    expect($intent)->not->toBeNull()
        ->and($intent->identifier)->toBe('ryanfitzbarber')
        ->and((string) $intent->state)->toBe('proposed');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/linktree')
        && $request['url'] === 'https://linktr.ee/ryanfitzsimons');
});

it('yields the same observations vendor-first as the HTML parser did — the parity ground truth', function () {
    Queue::fake();
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scLtFixture()),
        'linktr.ee/parser-lane' => Http::response(scLtNextDataHtml(), 200),
        'linktr.ee/vendor-lane' => Http::response(scLtShellHtml(), 200),
    ]);

    config()->set('services.scrapecreators.key', null);
    $parserResult = app(LinkInBioImporter::class)->import(
        User::factory()->create(['account_type' => 'partna']),
        'https://linktr.ee/parser-lane',
    );

    config()->set('services.scrapecreators.key', 'test-key');
    $vendorResult = app(LinkInBioImporter::class)->import(
        User::factory()->create(['account_type' => 'partna']),
        'https://linktr.ee/vendor-lane',
    );

    expect($parserResult['observations'])->toBe(3)
        ->and($vendorResult['observations'])->toBe(3)
        ->and($vendorResult['connected'])->toBe($parserResult['connected']);
});

it('keeps the icon-row socials the vendor payload never carries — union, not replacement', function () {
    // T24/issue 19 (benbohmer/memphislk): socialLinks-only URLs are
    // unrecoverable from anchors, and /v1/linktree returns only the tile
    // list. A vendor hit must not make them absent (lane contract), so the
    // inline parse's answer rides behind the vendor's, deduped.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scLtFixture()),
        'linktr.ee/*' => Http::response(scLtNextDataHtml(['https://ryanfitz.bandcamp.com/']), 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://linktr.ee/ryanfitzsimons');

    expect($result['observations'])->toBe(4);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/linktree'));
});

it('falls through to the HTML parser when the vendor transport fails', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response('upstream sad', 502),
        'linktr.ee/*' => Http::response(scLtNextDataHtml(), 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://linktr.ee/ryanfitzsimons');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/linktree'));
});

it('falls through to the HTML parser on a success-shaped husk (the NotFound quirk)', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1, 'credits_remaining' => 7000]),
        'linktr.ee/*' => Http::response(scLtNextDataHtml(), 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://linktr.ee/ryanfitzsimons');

    expect($result['observations'])->toBe(3);
});

it('skips the vendor lane entirely when no key is configured', function () {
    Queue::fake();
    config()->set('services.scrapecreators.key', null);
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'linktr.ee/*' => Http::response(scLtNextDataHtml(), 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://linktr.ee/ryanfitzsimons');

    expect($result['observations'])->toBe(3);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
});

it('skips the vendor lane when its budget is exhausted', function () {
    Queue::fake();
    config()->set('partna.limits.scrapecreators.sources.linkinbio', 0);
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'linktr.ee/*' => Http::response(scLtNextDataHtml(), 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://linktr.ee/ryanfitzsimons');

    expect($result['observations'])->toBe(3);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
});

it('never consults the vendor for a non-Linktree link-in-bio host', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'beacons.ai/*' => Http::response('<a href="https://someblog.example/post">Blog</a>', 200),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://beacons.ai/someone');

    expect($result['observations'])->toBe(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
});
