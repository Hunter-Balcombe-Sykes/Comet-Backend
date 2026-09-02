<?php

use App\Services\Platforms\SquareSiteBookingResolver;
use Illuminate\Support\Facades\Http;

// A.12 proof catch (2026-09-03): Akro Studio's square.site EMBEDS Square
// Appointments (homepage type "appointments") instead of linking out, so the
// deep-link regex found nothing and the listing's booking link stayed a
// website. The resolver now reads the config JSON's merchant token — but only
// when the site declares the appointments feature.

it('resolves an embedded-appointments square.site via its config merchant token', function () {
    Http::fake([
        'ssbr-embed.square.site/*' => Http::response(
            '{"featuresets":["appointments"],"homepage":{"type":"template","typeID":"appointments"},"merchant_id":"MLSE36V5ANGCZ"}',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(app(SquareSiteBookingResolver::class)->resolve('https://ssbr-embed.square.site/'))
        ->toBe('https://book.squareup.com/appointments/mlse36v5angcz');
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
