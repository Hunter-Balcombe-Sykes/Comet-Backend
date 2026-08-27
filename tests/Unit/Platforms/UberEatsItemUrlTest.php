<?php

use App\Services\Platforms\UberEatsMenuDriver;

/**
 * T10 (2026-08-27 unclaimed-signup quality plan, D4): the memo23 actor's
 * `href` is the quickView MODAL form — tapping it dumps the visitor into an
 * overlay festooned with rwg_token/utm tracking. The modctx blob carries the
 * exact section/subsection/item UUIDs of Uber Eats' own dedicated item PAGE
 * (`/store/{slug}/{storeB64}/{section}/{subsection}/{item}` — verified in a
 * real browser 2026-08-27: renders "LATTE | Uber Eats" with a back-link),
 * so the driver now constructs that page URL. A modctx that will not decode
 * falls back to the quickView URL with tracking stripped — still a working
 * link, never a guess.
 */

// The REAL href serving on st-ali-coffee-roasters' wire when this was written
// (rwg_token/utm redacted-shortened; shape byte-faithful).
const REAL_QUICKVIEW_HREF = '/au/store/st-ali/nK322-yMR8iIAJcSkfzELQ?mod=quickView&modctx=%257B%2522storeUuid%2522%253A%25229cadf6db-ec8c-47c8-8800-971291fcc42d%2522%252C%2522sectionUuid%2522%253A%2522f81f8200-767f-54ae-abee-57414d64a219%2522%252C%2522subsectionUuid%2522%253A%25220379a62a-1156-4ddb-a09a-7e1dc45d5dfb%2522%252C%2522itemUuid%2522%253A%25227682465a-4330-5dce-aa8a-3421c1394727%2522%252C%2522showSeeDetailsCTA%2522%253Atrue%257D&ps=1&rwg_token=AAiGzYZwTOKEN&utm_campaign=place-action-link&utm_medium=organic&utm_source=google';

function ueItemUrl(?string $href): ?string
{
    $driver = new UberEatsMenuDriver;

    return (new ReflectionMethod($driver, 'itemUrl'))->invoke($driver, $href);
}

it('builds the dedicated item-page URL from the real quickView href', function () {
    expect(ueItemUrl(REAL_QUICKVIEW_HREF))->toBe(
        'https://www.ubereats.com/au/store/st-ali/nK322-yMR8iIAJcSkfzELQ'
        .'/f81f8200-767f-54ae-abee-57414d64a219'
        .'/0379a62a-1156-4ddb-a09a-7e1dc45d5dfb'
        .'/7682465a-4330-5dce-aa8a-3421c1394727'
    );
});

it('builds the page URL from an absolute quickView href too', function () {
    expect(ueItemUrl('https://www.ubereats.com'.REAL_QUICKVIEW_HREF))->toBe(
        'https://www.ubereats.com/au/store/st-ali/nK322-yMR8iIAJcSkfzELQ'
        .'/f81f8200-767f-54ae-abee-57414d64a219'
        .'/0379a62a-1156-4ddb-a09a-7e1dc45d5dfb'
        .'/7682465a-4330-5dce-aa8a-3421c1394727'
    );
});

it('falls back to the quickView URL with tracking stripped when modctx will not decode', function () {
    $url = ueItemUrl('/au/store/st-ali/nK322-yMR8iIAJcSkfzELQ?mod=quickView&modctx=garbage&ps=1&rwg_token=SECRET&utm_source=google');

    expect($url)->toStartWith('https://www.ubereats.com/au/store/st-ali/nK322-yMR8iIAJcSkfzELQ?');
    expect($url)->toContain('mod=quickView');
    expect($url)->not->toContain('rwg_token');
    expect($url)->not->toContain('utm_');
});

it('passes a plain ubereats URL through with tracking stripped', function () {
    expect(ueItemUrl('https://www.ubereats.com/au/store/st-ali/nK322-yMR8iIAJcSkfzELQ?utm_source=google&rwg_token=SECRET'))
        ->toBe('https://www.ubereats.com/au/store/st-ali/nK322-yMR8iIAJcSkfzELQ');
});

it('still refuses foreign hosts and null', function () {
    expect(ueItemUrl('https://evil.example.com/store/x?mod=quickView'))->toBeNull();
    expect(ueItemUrl(null))->toBeNull();
});
