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
