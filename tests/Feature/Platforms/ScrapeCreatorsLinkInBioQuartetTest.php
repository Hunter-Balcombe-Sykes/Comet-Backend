<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Platforms\ScrapeCreators\KomiLinksNormalizer;
use App\Services\Platforms\ScrapeCreators\LinkbioLinksNormalizer;
use App\Services\Platforms\ScrapeCreators\LinkmeLinksNormalizer;
use App\Services\Platforms\ScrapeCreators\PillarLinksNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Item 10b (2026-09-01): the link-in-bio quartet — Komi, Pillar, Lnk.Bio,
// Linkme — riding the Linktree vendor lane, each pinned against a RECORDED
// live payload (tests/fixtures/recorded/scrapecreators-{komi,pillar,linkbio,
// linkme}.json, captured 2026-09-01 from the vendor's own documented example
// pages). The two Linktree-lane properties carry over per service:
//
//  1. When the vendor answers usably, the unroll consumes its links exactly
//     as it consumes an HTML parse's — page order, one observation per
//     distinct URL, contact PII never emitted.
//  2. When the vendor answers any other way — 5xx, success-shaped husk, no
//     key — the run behaves exactly as it did before the lane existed.
//
// Plus the one property Linktree never needed: lnk.bio refuses our fetcher at
// the edge (Cloudflare 403), so the vendor lane must fire even when the page
// fetch fails — the rescue path.

function scQuartetFixture(string $service): array
{
    return json_decode(
        file_get_contents(base_path("tests/fixtures/recorded/scrapecreators-{$service}.json")),
        true
    );
}

/** An anchor-free shell: anything observed can only be the vendor's. */
function scQuartetShellHtml(): string
{
    return '<!DOCTYPE html><html><head><title>bio</title></head>'
        .'<body><div id="app"></div><script src="/assets/app.js"></script></body></html>';
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

it('normalizes the recorded Komi payload: visible tiles lead, icon-row socials follow, hidden modules vanish', function () {
    $normalizer = app(KomiLinksNormalizer::class);
    $page = $normalizer->normalize(scQuartetFixture('komi'));

    expect($page)->not->toBeNull()
        ->and($page['username'])->toBe('kimkardashian')
        ->and($page['displayName'])->toBe('Kim Kardashian');

    $urls = $normalizer->urls($page);

    // 21 shown tiles (10 archived visible:false rows and the url-less module
    // rows dropped) + 6 icon-row socials (website is null on the recording).
    expect($urls)->toHaveCount(27)
        ->and($urls[0])->toBe('https://skims.social/shop-skims')
        ->and($urls)->toContain('https://www.snapchat.com/add/kimkardashian?locale=en-GB')
        ->and(implode(' ', $urls))->not->toContain('skims.com/products')
        ->and(implode(' ', $urls))->not->toContain('komi.io');

    // Row vocabulary matches the Linktree lane's; trailing whitespace is real
    // in the recorded payload ("UPDATE ENERGY DRINK ") — trimmed.
    expect($page['links'][1])->toBe([
        'url' => 'https://drinkupdate.com/?srsltid=AfmBOooIad8vPZueD_CvcKcyVCG1KbYiz1fU-or88He0HmecDhK35kKL',
        'title' => 'UPDATE ENERGY DRINK',
        'id' => '4cb6a1c6-3486-4655-abf0-9ade378d8cef',
        'type' => 'LINK',
    ]);

    expect(json_encode($page))->not->toContain('credits');
});

it('normalizes the recorded Pillar payload: tiles, products, then socials — and never the contact PII', function () {
    $normalizer = app(PillarLinksNormalizer::class);
    $page = $normalizer->normalize(scQuartetFixture('pillar'));

    expect($page)->not->toBeNull()
        ->and($page['name'])->toBe('Angel Blanco');

    $urls = $normalizer->urls($page);

    // 5 tiles + 4 products + 8 non-empty socials, minus the twitter URL the
    // tiles already carry (exact-URL fold).
    expect($urls)->toHaveCount(16)
        ->and($urls[0])->toBe('https://www.instagram.com/contiendarecords')
        ->and($urls)->toContain('https://angel-strife.ueniweb.com/products/merchandise/sweater-negro-edicion-angel-strife-52416306')
        ->and($urls)->toContain('https://mx.linkedin.com/in/angelcovablanco');

    expect(array_values(array_filter($urls, fn (string $u): bool => $u === 'https://twitter.com/SoyAngelStrife')))->toHaveCount(1);

    // Pillar's links[].type repeats the title — not a discriminator, so the
    // row carries none; and the payload's email/location keys must be gone.
    expect($page['links'][0])->toBe([
        'url' => 'https://www.instagram.com/contiendarecords',
        'title' => 'sincronicidad',
        'id' => '657440b0-1ba7-11ee-b33b-e5396daf72e9',
    ]);
    expect(json_encode($page))->not->toContain('example.invalid')
        ->and(json_encode($page))->not->toContain('credits');
});

it('normalizes the recorded Lnk.Bio payload: text maps to title, null socials contribute nothing', function () {
    $normalizer = app(LinkbioLinksNormalizer::class);
    $page = $normalizer->normalize(scQuartetFixture('linkbio'));

    expect($page)->not->toBeNull()
        ->and($page['username'])->toBe('msjennafischer')
        ->and($page['links'])->toHaveCount(11);

    expect($page['links'][0])->toBe([
        'url' => 'https://www.instagram.com/msjennafischer',
        'title' => '@msjennafischer',
    ]);

    expect($normalizer->urls($page)[10])->toBe('https://miryslist.org/lists')
        ->and(json_encode($page))->not->toContain('credits');
});

it('normalizes the recorded Linkme payload: grouped webLinks flatten in order, dupes fold, PII and vendor funnel links never emitted', function () {
    $normalizer = app(LinkmeLinksNormalizer::class);
    $page = $normalizer->normalize(scQuartetFixture('linkme'));

    expect($page)->not->toBeNull()
        ->and($page['username'])->toBe('danucd');

    $urls = $normalizer->urls($page);

    // 13 rows across 11 groups; the recorded YouTube group repeats one
    // channel — folded, leaving 12.
    expect($urls)->toHaveCount(12)
        ->and($urls[0])->toBe('https://music.apple.com/ng/artist/danucd/1562315189')
        ->and(array_values(array_filter($urls, fn (string $u): bool => $u === 'https://www.youtube.com/@DanucD2')))->toHaveCount(1);

    expect($page['links'][0]['title'])->toBe('Apple-music');

    // infoLinks (email) and the vendor's referral/deeplink funnel are data
    // the page carries but the owner never published as links.
    expect(json_encode($page))->not->toContain('example.invalid')
        ->and(json_encode($page))->not->toContain('page.link')
        ->and(json_encode($page))->not->toContain('credits');
});

it('reads every husk shape as a vendor miss across all four normalizers', function () {
    $husk = ['success' => true, 'credits_charged' => 1];

    expect(app(KomiLinksNormalizer::class)->normalize($husk))->toBeNull()
        ->and(app(PillarLinksNormalizer::class)->normalize($husk))->toBeNull()
        ->and(app(LinkbioLinksNormalizer::class)->normalize($husk))->toBeNull()
        ->and(app(LinkmeLinksNormalizer::class)->normalize($husk))->toBeNull();

    // An all-hidden Komi page reads as empty — the lane may never be the
    // reason a page yields nothing.
    expect(app(KomiLinksNormalizer::class)->normalize([
        'username' => 'x',
        'links' => [['url' => 'https://a.example/', 'visible' => false]],
    ]))->toBeNull();

    expect(app(PillarLinksNormalizer::class)->normalize(['links' => [['url' => 'https://a.example/']]]))->toBeNull()
        ->and(app(LinkbioLinksNormalizer::class)->normalize(['handle' => '', 'links' => [['url' => 'https://a.example/']]]))->toBeNull()
        ->and(app(LinkmeLinksNormalizer::class)->normalize(['profile' => ['username' => 'x', 'webLinks' => []]]))->toBeNull();
});

it('scans a Komi page like a Linktree one — vendor links off an anchor-free shell', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scQuartetFixture('komi')),
        'kimkardashian.komi.io/*' => Http::response(scQuartetShellHtml(), 200),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://kimkardashian.komi.io/');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(27)
        ->and($result['bio_url_seeded'])->toBeFalse();
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'tiktok'])->exists())->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/komi')
        && $request['url'] === 'https://kimkardashian.komi.io/');
});

it('scans a Pillar page like a Linktree one', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scQuartetFixture('pillar')),
        'pillar.io/*' => Http::response(scQuartetShellHtml(), 200),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://pillar.io/angelstrife');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(16)
        ->and($result['bio_url_seeded'])->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/pillar')
        && $request['url'] === 'https://pillar.io/angelstrife');
});

it('scans a Linkme page like a Linktree one', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scQuartetFixture('linkme')),
        'link.me/*' => Http::response(scQuartetShellHtml(), 200),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://link.me/danucd');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(12)
        ->and($result['bio_url_seeded'])->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/linkme')
        && $request['url'] === 'https://link.me/danucd');
});

it('rescues a Lnk.Bio page the host refuses at the edge — the vendor lane outlives the fetch', function () {
    // The production shape: lnk.bio serves Cloudflare's challenge to both of
    // SafeUrlFetcher's UAs. Before Item 10b this page was a guaranteed
    // zero-yield floor card; the vendor never even saw it.
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scQuartetFixture('linkbio')),
        'lnk.bio/*' => Http::response('<html><title>Just a moment...</title></html>', 403),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://lnk.bio/msjennafischer');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(11)
        ->and($result['pages'])->toBe(1)
        ->and($result['pages_unavailable'])->toBe(0)
        ->and($result['bio_url_seeded'])->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/linkbio')
        && $request['url'] === 'https://lnk.bio/msjennafischer');
});

it('keeps the unavailable accounting untouched when both the fetch and the vendor miss', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response('upstream sad', 502),
        'lnk.bio/*' => Http::response('<html><title>Just a moment...</title></html>', 403),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://lnk.bio/msjennafischer');

    expect($result['outcome'])->toBe('unavailable')
        ->and($result['observations'])->toBe(0)
        ->and($result['unavailable_reasons'])->toBe(['bot_challenge' => 1])
        // The zero-yield floor still fires: the URL the owner published
        // survives as a card, exactly as before the lane existed.
        ->and($result['bio_url_seeded'])->toBeTrue();
});

it('rewrites the clk.bio mirror onto the lnk.bio host the vendor documents', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scQuartetFixture('linkbio')),
        'clk.bio/*' => Http::response(scQuartetShellHtml(), 200),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://clk.bio/themetapunter');

    expect($result['observations'])->toBe(11);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/linkbio')
        && $request['url'] === 'https://lnk.bio/themetapunter');
});

it('leaves the quartet lane dormant when no key is configured', function () {
    Queue::fake();
    config()->set('services.scrapecreators.key', null);
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'kimkardashian.komi.io/*' => Http::response('<a href="https://someblog.example/post">Blog</a>', 200),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://kimkardashian.komi.io/');

    expect($result['observations'])->toBe(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
});

it('falls through to the anchor parse when the vendor transport fails on a quartet host', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    Http::fake([
        'api.scrapecreators.com/*' => Http::response('upstream sad', 502),
        'pillar.io/*' => Http::response('<a href="https://someblog.example/post">Blog</a>', 200),
        '*' => Http::response('', 404),
    ]);

    $result = app(LinkInBioImporter::class)->import($user, 'https://pillar.io/angelstrife');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/pillar'));
});
