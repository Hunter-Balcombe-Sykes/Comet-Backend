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

it('caps the user agent at 256 characters (PRIV-6)', function () {
    expect(strlen(AnalyticsEventSanitizer::userAgent(str_repeat('U', 400))))->toBe(256);
});

it('leaves a short user agent unchanged and returns null for empty', function () {
    expect(AnalyticsEventSanitizer::userAgent('Mozilla/5.0 (Macintosh)'))->toBe('Mozilla/5.0 (Macintosh)');
    expect(AnalyticsEventSanitizer::userAgent(null))->toBeNull();
    expect(AnalyticsEventSanitizer::userAgent(''))->toBeNull();
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
