<?php

use App\Services\Analytics\AnalyticsEventSanitizer;

it('strips the query string and fragment from a referrer, keeping origin + path (PRIV-5)', function () {
    expect(AnalyticsEventSanitizer::referrer('https://ads.example.com/landing?utm_content=leak%40example.com#frag'))
        ->toBe('https://ads.example.com/landing');
});

it('returns null for a missing, empty, or unparseable referrer', function () {
    expect(AnalyticsEventSanitizer::referrer(null))->toBeNull();
    expect(AnalyticsEventSanitizer::referrer(''))->toBeNull();
    expect(AnalyticsEventSanitizer::referrer('not-a-url'))->toBeNull();
});

it('caps the referrer at 512 characters', function () {
    $long = 'https://example.com/'.str_repeat('a', 600);
    expect(strlen(AnalyticsEventSanitizer::referrer($long)))->toBeLessThanOrEqual(512);
});

it('reduces a realistic Chrome UA to family + major version (PRIV-2)', function () {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/141.0.7390.54 Safari/537.36';

    expect(AnalyticsEventSanitizer::userAgent($ua))->toBe('Chrome/141');
});

it('reduces Edge, Firefox, and Safari UAs to family + major version, preferring the more specific token', function () {
    $edge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        .'Chrome/141.0.0.0 Safari/537.36 Edg/141.0.3537.85';
    $firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0';
    $safari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 '
        .'(KHTML, like Gecko) Version/17.4 Safari/605.1.15';

    // Edge/Chromium and Chrome's own UA both embed "Chrome/..." — Edge must win, not Chrome.
    expect(AnalyticsEventSanitizer::userAgent($edge))->toBe('Edge/141');
    expect(AnalyticsEventSanitizer::userAgent($firefox))->toBe('Firefox/133');
    // Safari's true version is the "Version/" token, not the "Safari/" WebKit build number.
    expect(AnalyticsEventSanitizer::userAgent($safari))->toBe('Safari/17');
});

it('falls back to Other for an unrecognised user agent and returns null for empty', function () {
    expect(AnalyticsEventSanitizer::userAgent('SomeWeirdClient/3.2'))->toBe('Other');
    expect(AnalyticsEventSanitizer::userAgent(null))->toBeNull();
    expect(AnalyticsEventSanitizer::userAgent(''))->toBeNull();
});

// PGR-18: a marketer's link can append a subscriber email to a UTM param.
it('neutralises a UTM value that carries an email-like substring', function () {
    expect(AnalyticsEventSanitizer::utmParam('newsletter-leak@example.com'))->toBeNull();
});

it('neutralises an email embedded inside a larger UTM value', function () {
    expect(AnalyticsEventSanitizer::utmParam('summer-sale-user@example.com-promo'))->toBeNull();
});

it('passes through a clean UTM value unchanged', function () {
    expect(AnalyticsEventSanitizer::utmParam('instagram_bio'))->toBe('instagram_bio');
});

it('returns null for a missing or empty UTM value', function () {
    expect(AnalyticsEventSanitizer::utmParam(null))->toBeNull();
    expect(AnalyticsEventSanitizer::utmParam(''))->toBeNull();
});

it('caps a clean UTM value at 128 characters', function () {
    $long = str_repeat('a', 200);
    expect(strlen(AnalyticsEventSanitizer::utmParam($long)))->toBe(128);
});

// JOB-1: the controller now sanitises referrer once at buildEvent() and the writer
// sanitises again at persist-time. That's only safe if a second pass is a no-op —
// otherwise sanitising earlier would change what lands in Postgres.
it('is idempotent — applying referrer() twice matches applying it once', function (?string $raw) {
    $once = AnalyticsEventSanitizer::referrer($raw);
    $twice = AnalyticsEventSanitizer::referrer($once);

    expect($twice)->toBe($once);
})->with([
    'utm-embedded PII' => 'https://mail.example.com/c?utm_content=user@example.com#frag',
    'protocol-relative with query' => '//example.com/path?a=b',
    'malformed non-URL' => 'not-a-url',
    'javascript scheme (no host)' => 'javascript:alert(1)',
    'URL with port' => 'https://a.com:8080/x?y=1',
    'already-clean URL' => 'https://a.com/x',
    'over-length URL' => 'https://example.com/'.str_repeat('a', 600),
    'null' => null,
    'empty string' => '',
]);

// --- clickUrl(): the click destination contract -----------------------------
//
// A tel: tap and a mailto: tap ARE conversions, so both are accepted
// destinations. What they are not is free-form text: a phone number has as
// many spellings as a visitor's keyboard allows, and a mailto: carries a
// prefilled subject/body that is template copy (and a PII surface), not a
// destination. clickUrl() reduces each contact point to exactly one string so
// the url column stays a countable dimension instead of a bag of variants.

it('passes an http/https destination through unchanged', function () {
    expect(AnalyticsEventSanitizer::clickUrl('https://shop.example.com/products/tee?variant=1'))
        ->toBe('https://shop.example.com/products/tee?variant=1');
});

it('strips visual formatting from a tel: number so one line is one destination', function (string $raw, string $expected) {
    expect(AnalyticsEventSanitizer::clickUrl($raw))->toBe($expected);
})->with([
    'spaces' => ['tel:+61 400 000 000', 'tel:+61400000000'],
    'parens and dashes' => ['tel:+61 (400) 000-000', 'tel:+61400000000'],
    'dots' => ['tel:0400.000.000', 'tel:0400000000'],
    'uppercase scheme' => ['TEL:+61400000000', 'tel:+61400000000'],
    'RFC 3966 extension' => ['tel:+61 3 9000 0000;ext=12', 'tel:+61390000000;ext=12'],
]);

it('lowercases a mailto: address and drops its prefilled subject/body', function (string $raw, string $expected) {
    expect(AnalyticsEventSanitizer::clickUrl($raw))->toBe($expected);
})->with([
    'subject + body' => ['mailto:Hello@Example.COM?subject=Hi&body=There', 'mailto:hello@example.com'],
    'uppercase scheme' => ['MAILTO:Hello@Example.com', 'mailto:hello@example.com'],
    'already clean' => ['mailto:hello@example.com', 'mailto:hello@example.com'],
]);

it('returns null for anything that is not a destination a visitor navigated to', function (?string $raw) {
    expect(AnalyticsEventSanitizer::clickUrl($raw))->toBeNull();
})->with([
    'javascript scheme' => 'javascript:alert(1)',
    'data scheme' => 'data:text/html,<script>x</script>',
    'schemeless' => 'not-a-url',
    'empty mailto' => 'mailto:',
    'mailto without a domain' => 'mailto:nobody',
    'empty tel' => 'tel:',
    'tel that is only punctuation' => 'tel:---',
    'tel too short to dial' => 'tel:12',
    'null' => null,
    'empty string' => '',
    // Every case above is refused by the SCHEME arm of the match. Until these
    // four, nothing exercised the guard INSIDE the http/https arm: replacing that
    // whole guard with an unconditional `$scheme.':'.$rest` passed the suite, so
    // 'http://' — a scheme and nothing else — was a destination as far as the
    // tests were concerned, and the url column is a dimension the dashboard
    // groups by and a value something downstream renders as a link.
    'http scheme and nothing else' => 'http://',
    'https with no authority at all' => 'https:not-a-url',
    'http with a space inside the host' => 'http://exa mple.com',
    'https with an empty host before the path' => 'https:///path',
]);

it('is idempotent — re-normalising a normalised destination changes nothing', function (string $raw) {
    $once = AnalyticsEventSanitizer::clickUrl($raw);
    expect(AnalyticsEventSanitizer::clickUrl($once))->toBe($once);
})->with([
    'tel' => 'tel:+61 (400) 000-000',
    'mailto' => 'mailto:Hello@Example.COM?subject=Hi',
    'https' => 'https://a.com/x?y=1',
]);
