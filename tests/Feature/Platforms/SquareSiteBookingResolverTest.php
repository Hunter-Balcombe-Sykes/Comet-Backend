<?php

use App\Services\Platforms\SquareSiteBookingResolver;
use Illuminate\Support\Facades\Http;

// A.12 proof catch (2026-09-03): Akro Studio's square.site EMBEDS Square
// Appointments (homepage type "appointments") instead of linking out, so the
// deep-link regex found nothing and the listing's booking link stayed a
// website. The resolver now reads the config JSON's merchant token — but only
// when the site declares the appointments feature.
//
// A.12 follow-up (2026-09-06, live Akro Studio proof): a bare merchant_id is
// not actually a resolvable booking page — book.squareup.com's own path
// validator reports it "invalid" with no /location/ suffix, even for this
// real single-location merchant, while the identical merchant_id paired with
// the site's own published location id validates. The resolver now also
// requires that location token (published under some *_location_ids array —
// whichever ecommerce feature happens to be on) before it will build a URL.

it('resolves an embedded-appointments square.site via its config merchant token and published location', function () {
    Http::fake([
        'ssbr-embed.square.site/*' => Http::response(
            '{"featuresets":["appointments"],"homepage":{"type":"template","typeID":"appointments"},"merchant_id":"MLSE36V5ANGCZ","shipping_location_ids":["ABCD1234EFGH"]}',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(app(SquareSiteBookingResolver::class)->resolve('https://ssbr-embed.square.site/'))
        ->toBe('https://book.squareup.com/appointments/mlse36v5angcz/location/ABCD1234EFGH');
});

it('leaves an embedded-appointments square.site unresolved when no location token is published anywhere', function () {
    // The exact A.12 (2026-09-03) fixture — merchant_id alone, no *_location_ids
    // array at all. Building book.squareup.com/appointments/{merchant_id} with
    // no location from this would be the pre-2026-09-06 behaviour, and that
    // URL is proven broken (see the file docblock) — null (stay a website) is
    // the honest answer until a real location token turns up.
    Http::fake([
        'ssbr-embed-no-location.square.site/*' => Http::response(
            '{"featuresets":["appointments"],"homepage":{"type":"template","typeID":"appointments"},"merchant_id":"MLSE36V5ANGCZ"}',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(app(SquareSiteBookingResolver::class)->resolve('https://ssbr-embed-no-location.square.site/'))
        ->toBeNull();
});

it('leaves a plain storefront with a stray merchant token as a website', function () {
    Http::fake([
        'ssbr-store.square.site/*' => Http::response(
            '{"featuresets":["onlineStore"],"merchant_id":"MLSE36V5ANGCZ"}',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(app(SquareSiteBookingResolver::class)->resolve('https://ssbr-store.square.site/'))->toBeNull();
});

it('still prefers an explicit appointments deep link in the HTML', function () {
    Http::fake([
        'ssbr-link.square.site/*' => Http::response(
            '<a href="https://squareup.com/appointments/book/abcd1234efgh">Book now</a>{"featuresets":["appointments"],"merchant_id":"MLZZZZZZZZZZ"}',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(app(SquareSiteBookingResolver::class)->resolve('https://ssbr-link.square.site/'))
        ->toBe('https://book.squareup.com/appointments/abcd1234efgh');
});
