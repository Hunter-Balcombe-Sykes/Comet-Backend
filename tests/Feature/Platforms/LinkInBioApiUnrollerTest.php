<?php

use App\Services\Platforms\LinkInBioApiUnroller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// N2 (2026-08-19): linkin.bio delivers an empty Ember shell — zero <a> anchors —
// so the anchor harvest returns nothing and the whole page is lost. The links are
// there, behind the same public API the page's own JavaScript calls. Shape below
// is the real 2026-08-19 response for supernormal_180, trimmed to the keys we read.

function linkinBioPayload(array $buttons, array $extraBlocks = []): array
{
    return ['linkinbio_page' => [
        'id' => 595303,
        'social_profiles' => [['nickname' => 'supernormal_180', 'default_link' => null]],
        'linkinbio_blocks' => array_merge([
            ['block_type' => 'style', 'block_data' => ['enabled' => true, 'page_background' => '#EEE5D3']],
            ['block_type' => 'button_list', 'block_data' => ['enabled' => true, 'buttons' => $buttons, 'button_groups' => []]],
        ], $extraBlocks),
    ]];
}

function fakeLinkinBioApi(array $payload, int $status = 200): void
{
    Http::fake(['api-prod.linkin.bio/*' => Http::response($payload, $status)]);
}

it('returns the destination links behind a client-rendered linkin.bio page', function () {
    fakeLinkinBioApi(linkinBioPayload([
        ['url' => 'https://www.sevenrooms.com/explore/supernormalaustralia/reservations/create/search', 'title' => 'RESERVATIONS', 'enabled' => true],
        ['url' => 'https://supernormal.net.au/menu', 'title' => 'MENU', 'enabled' => true],
    ]));

    $links = app(LinkInBioApiUnroller::class)->unroll('https://linkin.bio/supernormal_180');

    expect($links)->toBe([
        'https://www.sevenrooms.com/explore/supernormalaustralia/reservations/create/search',
        'https://supernormal.net.au/menu',
    ]);
});

it('omits buttons the owner switched off', function () {
    // Measured on the live page: 8 buttons, 2 of them enabled=false. Those are
    // hidden from every human visitor, so publishing them would put links on the
    // user's site that they deliberately took down.
    fakeLinkinBioApi(linkinBioPayload([
        ['url' => 'https://supernormal.net.au/menu', 'title' => 'MENU', 'enabled' => true],
        ['url' => 'https://supernormal.net.au/contact', 'title' => 'CONTACT', 'enabled' => false],
    ]));

    $links = app(LinkInBioApiUnroller::class)->unroll('https://linkin.bio/supernormal_180');

    expect($links)->toBe(['https://supernormal.net.au/menu']);
});

it('skips a button_list block the owner disabled wholesale', function () {
    fakeLinkinBioApi(['linkinbio_page' => ['linkinbio_blocks' => [
        ['block_type' => 'button_list', 'block_data' => ['enabled' => false, 'buttons' => [
            ['url' => 'https://supernormal.net.au/menu', 'title' => 'MENU', 'enabled' => true],
        ]]],
    ]]]);

    expect(app(LinkInBioApiUnroller::class)->unroll('https://linkin.bio/supernormal_180'))->toBe([]);
});

it('reads the nickname from a page URL that carries a trailing slash', function () {
    // SafeUrlFetcher hands the importer the FINAL url, and linkin.bio 301s
    // /<nick> to /<nick>/ — so the trailing-slash form is the common case, not
    // the edge case.
    fakeLinkinBioApi(linkinBioPayload([
        ['url' => 'https://supernormal.net.au/menu', 'title' => 'MENU', 'enabled' => true],
    ]));

    app(LinkInBioApiUnroller::class)->unroll('https://linkin.bio/supernormal_180/');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'nickname=supernormal_180'));
});

it('declines a host it has no API for, so the anchor harvest still runs', function () {
    Http::fake();

    // Linktree server-renders its anchors; the harvest handles it and must not
    // be pre-empted. null means "not mine", which is NOT the same as "no links".
    expect(app(LinkInBioApiUnroller::class)->unroll('https://linktr.ee/someone'))->toBeNull();

    Http::assertNothingSent();
});

it('declines when the API does not answer, rather than claiming the page is empty', function () {
    fakeLinkinBioApi(['error' => 'nope'], 503);

    expect(app(LinkInBioApiUnroller::class)->unroll('https://linkin.bio/supernormal_180'))->toBeNull();
});

it('declines when the payload is not the shape it knows', function () {
    // Later can rev its API without telling us. A silent shape change must fall
    // back to the anchor harvest + zero-yield floor, not delete the user's links.
    fakeLinkinBioApi(['data' => ['blocks' => []]]);

    expect(app(LinkInBioApiUnroller::class)->unroll('https://linkin.bio/supernormal_180'))->toBeNull();
});

it('refuses a nickname that could carry URL syntax, without spending a request', function () {
    Http::fake();

    expect(app(LinkInBioApiUnroller::class)->unroll('https://linkin.bio/evil%26nickname=x'))->toBeNull();

    Http::assertNothingSent();
});

it('ignores non-http destinations and Later\'s own asset URLs', function () {
    fakeLinkinBioApi(linkinBioPayload([
        ['url' => 'mailto:hello@supernormal.net.au', 'title' => 'EMAIL', 'enabled' => true],
        ['url' => 'https://supernormal.net.au/menu', 'title' => 'MENU', 'enabled' => true],
    ], [
        ['block_type' => 'header', 'block_data' => ['enabled' => true, 'display_name' => 'Supernormal'], 'linkinbio_attachments' => [
            ['name' => 'avatar', 'variants' => ['thumb' => ['url' => 'https://image-cdn.later.com/linkinbio_attachments/avatar/x/thumb.jpg']]],
        ]],
    ]));

    expect(app(LinkInBioApiUnroller::class)->unroll('https://linkin.bio/supernormal_180'))
        ->toBe(['https://supernormal.net.au/menu']);
});

// ── taplink.cc ───────────────────────────────────────────────────────────────
// A Vue shell whose window.account blob carries the theme and nickname but not
// the links. Shape below is the real 2026-08-19 response for taplink.cc/demo.

function fakeTaplink(array $payload, int $status = 200): void
{
    Http::fake(['taplink.cc/*' => Http::response($payload, $status)]);
}

function taplinkPage(array $items): array
{
    return ['result' => 'success', 'response' => [
        'page_id' => 10408374,
        'fields' => [['section' => null, 'items' => $items]],
    ]];
}

it('returns the destination links behind a client-rendered taplink.cc page', function () {
    fakeTaplink(taplinkPage([
        ['block_type_name' => 'avatar', 'options' => []],
        ['block_type_name' => 'link', 'options' => ['title' => 'Whatsapp', 'value' => 'http://wa.me/6285654008642']],
        ['block_type_name' => 'link', 'options' => ['title' => 'Shop', 'value' => 'https://shop.example.com']],
    ]));

    expect(app(LinkInBioApiUnroller::class)->unroll('https://taplink.cc/demo'))
        ->toBe(['http://wa.me/6285654008642', 'https://shop.example.com']);
});

it('skips a taplink block that points at an internal sub-page, not outward', function () {
    // Measured live: `options.type === 'page'` blocks carry `value: null` and
    // navigate within Taplink. Treating one as a link would write a null URL.
    fakeTaplink(taplinkPage([
        ['block_type_name' => 'link', 'options' => ['title' => 'TESTIMONI', 'type' => 'page', 'value' => null]],
        ['block_type_name' => 'link', 'options' => ['title' => 'Shop', 'value' => 'https://shop.example.com']],
    ]));

    expect(app(LinkInBioApiUnroller::class)->unroll('https://taplink.cc/demo'))
        ->toBe(['https://shop.example.com']);
});

it('asks taplink for the page BARE — a page_id param makes it answer redirect', function () {
    // The live endpoint answers {"result":"redirect"} to ?page_id= / ?id=, so a
    // "helpful" extra param silently costs every link on the page.
    fakeTaplink(taplinkPage([]));

    app(LinkInBioApiUnroller::class)->unroll('https://taplink.cc/demo');

    Http::assertSent(fn ($request) => $request->url() === 'https://taplink.cc/demo/api/page/get.json');
});

// ── stan.store ───────────────────────────────────────────────────────────────
// A Nuxt shell. A Stan store is a STOREFRONT: most pages are products hosted on
// stan.store itself. Only type=link points outward. Shape confirmed against
// api.stanwith.me 2026-08-19.

function fakeStan(array $payload, int $status = 200): void
{
    Http::fake(['api.stanwith.me/*' => Http::response($payload, $status)]);
}

function stanStore(array $pages): array
{
    return ['store' => ['store_id' => 1114, 'type' => 'linksite', 'slug' => 'a_store', 'pages' => $pages]];
}

it('returns only the outward-pointing pages of a stan.store storefront', function () {
    fakeStan(stanStore([
        ['type' => 'meeting', 'status' => 2, 'slug' => 'book-me', 'data' => ['product' => ['title' => 'Call']]],
        ['type' => 'link', 'status' => 2, 'slug' => 'my-shop', 'data' => ['product' => ['link' => ['url' => 'https://shop.example.com']]]],
    ]));

    // The meeting page is hosted on stan.store itself — same host as the bio
    // page, so the importer's chrome rule would drop it anyway.
    expect(app(LinkInBioApiUnroller::class)->unroll('https://stan.store/creator'))
        ->toBe(['https://shop.example.com']);
});

it('answers empty — not null — for a stan store that sells only hosted products', function () {
    // The COMMON case for this host. Empty means "read it, nothing points out",
    // which lets the zero-yield floor card the store URL; null would instead
    // re-run the anchor harvest against a shell that has no anchors.
    fakeStan(stanStore([
        ['type' => 'digital-download', 'status' => 2, 'slug' => 'ebook', 'data' => []],
    ]));

    expect(app(LinkInBioApiUnroller::class)->unroll('https://stan.store/creator'))->toBe([]);
});

it('skips a stan page the owner has not published', function () {
    // Fail-closed: every live page observed carries status 2 and the enum is not
    // published anywhere readable, so anything else is treated as not-live.
    fakeStan(stanStore([
        ['type' => 'link', 'status' => 1, 'slug' => 'draft', 'data' => ['product' => ['link' => ['url' => 'https://draft.example.com']]]],
        ['type' => 'link', 'status' => 2, 'slug' => 'live', 'data' => ['product' => ['link' => ['url' => 'https://live.example.com']]]],
    ]));

    expect(app(LinkInBioApiUnroller::class)->unroll('https://stan.store/creator'))
        ->toBe(['https://live.example.com']);
});

it('surfaces the owner\'s socials from the stan API payload (F12)', function () {
    // The socials are neither tiles nor anchors — the raw shell carries them
    // only inside its __NUXT__ blob (natalieannehair, 2026-08-20: her TikTok
    // and Facebook were invisible to BOTH arms). The API carries them at
    // user.data.socials in MIXED shapes, confirmed live: full URLs pass
    // through, named handle keys expand, mail_to is an email and must not
    // become a "link".
    fakeStan(stanStore([
        ['type' => 'link', 'status' => 2, 'slug' => 'my-shop', 'data' => ['product' => ['link' => ['url' => 'https://shop.example.com']]]],
    ]) + ['user' => ['data' => ['socials' => [
        'link' => 'https://example.com',
        'tiktok' => 'natalieannehair',
        'instagram' => '@Natalieannehair',
        'facebook' => 'https://www.facebook.com/natalieannehairstylist',
        'mail_to' => 'sales@example.com',
    ]]]]);

    expect(app(LinkInBioApiUnroller::class)->unroll('https://stan.store/creator'))
        ->toBe([
            'https://shop.example.com',
            'https://example.com',
            'https://www.tiktok.com/@natalieannehair',
            'https://www.instagram.com/Natalieannehair',
            'https://www.facebook.com/natalieannehairstylist',
        ]);
});

it('never MINTS a social URL from a shape it cannot name (F12)', function () {
    // A handle key not confirmed against a live store is skipped, and a
    // slash-carrying value is refused even for a named key — expanding either
    // would fabricate a URL the owner never published, which is worse than
    // missing one (the class's own linkinBio() rule).
    fakeStan(stanStore([]) + ['user' => ['data' => ['socials' => [
        'snapchat' => 'somehandle',
        'tiktok' => 'weird/shape',
        'twitter' => 'someone',
    ]]]]);

    expect(app(LinkInBioApiUnroller::class)->unroll('https://stan.store/creator'))->toBe([]);
});

it('answers the socials even when the stan store has zero outward tiles (F12)', function () {
    // The hosted-products-only store is stan's COMMON case; before F12 it
    // answered [] and the owner's socials were lost entirely.
    fakeStan(stanStore([
        ['type' => 'digital-download', 'status' => 2, 'slug' => 'ebook', 'data' => []],
    ]) + ['user' => ['data' => ['socials' => ['tiktok' => 'natalieannehair']]]]);

    expect(app(LinkInBioApiUnroller::class)->unroll('https://stan.store/creator'))
        ->toBe(['https://www.tiktok.com/@natalieannehair']);
});

it('refuses to mint a profile URL from a handle that is only @-signs (F12)', function () {
    // ltrim('@', '@') is '' — expanding that would emit a bare
    // "https://www.tiktok.com/@", a URL the owner never published.
    fakeStan(stanStore([]) + ['user' => ['data' => ['socials' => ['tiktok' => '@']]]]);

    expect(app(LinkInBioApiUnroller::class)->unroll('https://stan.store/creator'))->toBe([]);
});

it('logs the unrecognised-payload warning when a 200 body is not JSON at all', function () {
    // An HTML challenge page served with a 200 must not become a silent null
    // forever — the warning is the class's whole reason for logging at all.
    Log::spy();
    fakeStan([], 200);
    Http::fake(['api.stanwith.me/*' => Http::response('<html>not json</html>', 200)]);

    expect(app(LinkInBioApiUnroller::class)->unroll('https://stan.store/creator'))->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => $msg === 'platforms.link_in_bio_api.unrecognised_payload')
        ->once();
});

it('declines every new host when its API is down, so the floor still backstops', function () {
    Http::fake(['*' => Http::response('', 503)]);

    expect(app(LinkInBioApiUnroller::class)->unroll('https://taplink.cc/demo'))->toBeNull()
        ->and(app(LinkInBioApiUnroller::class)->unroll('https://stan.store/creator'))->toBeNull();
});
