<?php

use App\Services\Platforms\LinkInBioDetector;

it('matches each of the 4 curated link-in-bio hosts', function (string $url) {
    expect(app(LinkInBioDetector::class)->matches($url))->toBeTrue();
})->with([
    'https://linktr.ee/venue',
    'https://www.linktr.ee/venue',
    'https://msha.ke/venue',
    'https://beacons.ai/venue',
    'https://stan.store/venue',
]);

it('does not match an unrelated host', function () {
    expect(app(LinkInBioDetector::class)->matches('https://example.com'))->toBeFalse();
});

it('matches a genuine subdomain but rejects a lookalike host with no dot boundary (host-confusion guard)', function () {
    // A real subdomain of the curated host — must match (dot-boundary suffix).
    expect(app(LinkInBioDetector::class)->matches('https://sub.linktr.ee/venue'))->toBeTrue();
    // Contains "linktr.ee" as a raw substring but has no "." right before it —
    // a naive str_contains() check would wrongly match this; the real
    // implementation must not.
    expect(app(LinkInBioDetector::class)->matches('https://evillinktr.ee/venue'))->toBeFalse();
});

it('does not match a malformed url', function () {
    expect(app(LinkInBioDetector::class)->matches('not a url'))->toBeFalse();
});
