<?php

use App\Services\Platforms\WebsiteLinkHarvester;

// 2026-09-04 (lukemunn test signup): the Google listing's "Book online" was
// Timely's referral badge — www.gettimely.com?utm_campaign=Customer%20Referral,
// the "Powered by Timely" link off the merchant's minisite — and Partna made it
// that account's timely.book connection. Nothing could ever be fetched for it
// (no merchant slug in the URL, and no Timely connector), so Get Started drew
// an empty "Your services" step and the public page would have published a Book
// button pointing at Timely's marketing site.
//
// The classifier matched on the registrable domain alone. The routing lane has
// judged exactly that since PlacementPolicy (LinkValidity L1: a URL that
// matched only a bare-domain detector, on a surface whose real account links
// have a shape on file, is a link we can say is not an account page), and this
// class's own social arm has always enforced its version — the catalog arm
// never asked. It was not one brand: 75 of the catalog's brand homepages
// classified as a connectable booking/ordering/reservations/shop surface.

function vendorRootHarvester(): WebsiteLinkHarvester
{
    return app(WebsiteLinkHarvester::class);
}

it('never promotes a booking vendor front door to a connectable surface', function (string $url) {
    $classified = vendorRootHarvester()->classify($url);

    expect($classified)->not->toBeNull()
        ->and($classified['category'])->toBe('link')
        ->and(vendorRootHarvester()->isVendorRoot($url))->toBeTrue();
})->with([
    'the timely badge, verbatim from the live listing' => ['https://www.gettimely.com?utm_source=Minisite&utm_medium=Referral&utm_campaign=Customer Referral&utm_content=190299'],
    'timely bare' => ['https://www.gettimely.com'],
    'fresha' => ['https://www.fresha.com'],
    'booksy' => ['https://booksy.com'],
    'treatwell' => ['https://www.treatwell.com'],
    'vagaro' => ['https://www.vagaro.com'],
    'opentable' => ['https://www.opentable.com'],
    'doordash' => ['https://www.doordash.com'],
    'ubereats' => ['https://www.ubereats.com'],
]);

it('still connects a real merchant page on those same vendors', function (string $url, string $platform, string $category) {
    $classified = vendorRootHarvester()->classify($url);

    expect($classified)->not->toBeNull()
        ->and($classified['platform'])->toBe($platform)
        ->and($classified['category'])->toBe($category)
        ->and(vendorRootHarvester()->isVendorRoot($url))->toBeFalse();
})->with([
    'timely booking page' => ['https://book.gettimely.com/book/some-salon', 'timely', 'booking'],
    'fresha venue' => ['https://www.fresha.com/a/anseo-studio-v0v92jna', 'fresha', 'booking'],
    'doordash store' => ['https://www.doordash.com/store/some-cafe-123456', 'doordash.order', 'online-ordering'],
]);

it('says nothing about a domain the catalog does not know', function () {
    // The merchant's OWN booking domain must stay unrecognised, not vendor —
    // that is the case GoogleBusinessAutoSync answers with direct.book, and
    // this guard must never swallow it.
    expect(vendorRootHarvester()->isVendorRoot('https://booking.some-independent-salon.com.au/'))->toBeFalse();
});
