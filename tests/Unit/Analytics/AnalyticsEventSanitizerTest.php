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
