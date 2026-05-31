<?php

use App\Models\Core\Site\SmartLink;
use App\Services\SmartLinks\SmartLinkVisitorUrl;

function makeLink(array $attrs): SmartLink
{
    return new SmartLink(array_merge([
        'canonical_url' => 'https://shop.com/products/widget',
        'platform' => 'shopify',
        'tracking_query' => null,
        'discount_code' => null,
    ], $attrs));
}

it('returns the canonical URL when there is no tracking or discount', function () {
    $url = (new SmartLinkVisitorUrl)->build(makeLink([]));

    expect($url)->toBe('https://shop.com/products/widget');
});

it('appends preserved tracking params', function () {
    $url = (new SmartLinkVisitorUrl)->build(makeLink(['tracking_query' => 'sca_ref=tobias']));

    expect($url)->toBe('https://shop.com/products/widget?sca_ref=tobias');
});

it('wraps Shopify product + discount in the /discount/ redirect URL', function () {
    $url = (new SmartLinkVisitorUrl)->build(makeLink([
        'discount_code' => 'CREATOR20',
        'tracking_query' => 'sca_ref=tobias',
    ]));

    expect($url)->toStartWith('https://shop.com/discount/CREATOR20?redirect=')
        ->and($url)->toContain(rawurlencode('/products/widget?sca_ref=tobias'));
});

it('uses the ?discount= param for Eventbrite', function () {
    $url = (new SmartLinkVisitorUrl)->build(makeLink([
        'canonical_url' => 'https://www.eventbrite.com/e/my-event-tickets-123',
        'platform' => 'eventbrite',
        'discount_code' => 'EARLYBIRD',
    ]));

    expect($url)->toContain('discount=EARLYBIRD')
        ->and($url)->toStartWith('https://www.eventbrite.com/e/my-event-tickets-123?');
});

it('ignores a discount code on a non-discountable platform (no wrapper)', function () {
    $url = (new SmartLinkVisitorUrl)->build(makeLink([
        'canonical_url' => 'https://open.spotify.com/track/abc',
        'platform' => 'spotify',
        'discount_code' => 'X',
    ]));

    expect($url)->toBe('https://open.spotify.com/track/abc');
});
