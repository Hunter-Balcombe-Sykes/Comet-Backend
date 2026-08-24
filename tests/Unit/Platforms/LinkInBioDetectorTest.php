<?php

use App\Services\Platforms\LinkInBioDetector;

it('matches each of the curated link-in-bio hosts', function (string $url) {
    expect(app(LinkInBioDetector::class)->matches($url))->toBeTrue();
})->with([
    'https://linktr.ee/venue',
    'https://www.linktr.ee/venue',
    'https://msha.ke/venue',
    'https://beacons.ai/venue',
    'https://stan.store/venue',
    // 2026-07-23 expansion (signup-v2 A2) — linkin.bio first: the live retest's
    // own bio link, missed by the old 4-host list.
    'https://linkin.bio/supernormal_180',
    'https://www.linkin.bio/venue',
    'https://lnk.bio/venue',
    // 2026-08-24 (themetapunter live): clk.bio is Lnk.Bio's mirror and is NOT
    // behind the Cloudflare block that makes lnk.bio itself unfetchable —
    // 200 + 32 server-rendered anchors where lnk.bio answers 403.
    'https://clk.bio/venue',
    // sprout.link joined HOSTS on 2026-08-21 without joining this dataset.
    'https://sprout.link/venue',
    'https://bio.link/venue',
    'https://campsite.bio/venue',
    'https://snipfeed.co/venue',
    'https://komi.io/venue',
    'https://hoo.be/venue',
    'https://taplink.cc/venue',
    'https://solo.to/venue',
    'https://liinks.co/venue',
    'https://heylink.me/venue',
    'https://allmylinks.com/venue',
    'https://direct.me/venue',
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
