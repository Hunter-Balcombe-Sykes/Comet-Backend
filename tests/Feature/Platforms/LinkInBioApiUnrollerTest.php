<?php

use App\Services\Platforms\LinkInBioApiUnroller;
use Illuminate\Support\Facades\Http;

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
