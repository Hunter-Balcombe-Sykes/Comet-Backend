<?php

// #SEC-3 (overnight-run) — NOT the claim-gate #SEC-3, which is a separate P1.
//
// config('partna.menu.platforms.square.host_pattern') used to carry a third arm,
// `^order\.(?!online$|toasttab\.com$|ubereats\.com$|doordash\.com$|menulog\.com\.au$)`.
// A negative lookahead is zero-width and that arm had no terminal anchor, so it
// matched EVERY host beginning `order.` except five named competitors — an
// allowlist-by-exclusion. `order.attacker.example` was therefore classified as a
// Square menu source, scraped, and rendered on a public sitepage under Square's
// brand identity.
//
// The arm cannot be repaired by anchoring: a Square Online custom domain is
// indistinguishable from an attacker's by hostname alone. app/Catalog/Definitions/
// Square.php reaches the same conclusion independently and gives square.order no
// detector at all ("detector intentionally absent"). This file pins the removal and
// runs the repo's differential convention over the change, so a future edit that
// silently drops a legitimate Square host fails here rather than in production.

use Illuminate\Support\Facades\Config;

/** The pattern as it stood before the fix, kept ONLY so the differential can diff against it. */
const SQUARE_HOST_PATTERN_BEFORE_SEC3 = '~(^|\.)square\.site$|(^|\.)square\.com$|^order\.(?!online$|toasttab\.com$|ubereats\.com$|doordash\.com$|menulog\.com\.au$)~';

function squarePattern(): string
{
    return (string) Config::get('partna.menu.platforms.square.host_pattern');
}

/** @return list<string> */
function squareHostCorpus(): array
{
    return [
        // Genuine Square hosts — must keep matching.
        'square.site', 'merchant.square.site', 'deep.sub.square.site',
        'square.com', 'www.square.com', 'checkout.square.com',
        // The attack the finding names, plus neighbours.
        'order.attacker.example', 'order.evil.co.uk', 'order.square-phish.com',
        // Hosts the old arm deliberately excluded.
        'order.online', 'order.toasttab.com', 'order.ubereats.com',
        'order.doordash.com', 'order.menulog.com.au',
        // Look-alikes that must never match on either pattern.
        'notsquare.com', 'square.com.evil.example', 'squaresite.com', 'mysquare.site.evil.com',
        // Other registry platforms' hosts — must not be claimed by Square.
        'ubereats.com', 'www.doordash.com', 'menulog.com.au',
    ];
}

it('no longer classifies an attacker-controlled order.* host as Square', function () {
    expect(preg_match(squarePattern(), 'order.attacker.example'))->toBe(0)
        ->and(preg_match(squarePattern(), 'order.evil.co.uk'))->toBe(0)
        ->and(preg_match(squarePattern(), 'order.square-phish.com'))->toBe(0);
});

it('still classifies every genuine Square host', function () {
    foreach (['square.site', 'merchant.square.site', 'deep.sub.square.site', 'square.com', 'www.square.com', 'checkout.square.com'] as $host) {
        expect(preg_match(squarePattern(), $host))->toBe(1, "expected {$host} to match");
    }
});

it('never claims a look-alike or another platform\'s host', function () {
    foreach (['notsquare.com', 'square.com.evil.example', 'squaresite.com', 'mysquare.site.evil.com', 'ubereats.com', 'www.doordash.com', 'menulog.com.au'] as $host) {
        expect(preg_match(squarePattern(), $host))->toBe(0, "expected {$host} NOT to match");
    }
});

// The differential (feedback_differential_test_for_keyword_map_changes): diff OLD vs
// NEW over the corpus. The ONLY acceptable change is order.* hosts losing their
// match. If a future edit drops a square.site/square.com host, it shows up here as a
// diff line instead of silently un-scraping a real merchant.
it('changes classification for order.* hosts only, and never loses a Square host', function () {
    $lost = [];
    $gained = [];

    foreach (squareHostCorpus() as $host) {
        $before = preg_match(SQUARE_HOST_PATTERN_BEFORE_SEC3, $host) === 1;
        $after = preg_match(squarePattern(), $host) === 1;

        if ($before && ! $after) {
            $lost[] = $host;
        }
        if (! $before && $after) {
            $gained[] = $host;
        }
    }

    sort($lost);

    expect($gained)->toBe([], 'the fix must not make the pattern accept anything new')
        ->and($lost)->toBe([
            'order.attacker.example',
            'order.evil.co.uk',
            'order.square-phish.com',
        ], 'the only hosts that may lose their match are unanchored order.* ones');
});
