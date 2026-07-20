<?php

use App\Services\Http\ParsedUrl;

// lastSegment() used end() on a readonly property, which fatals at runtime
// (readonly properties reject by-ref access) — see array_key_last() fix.
function makeParsedUrl(array $pathSegments): ParsedUrl
{
    return new ParsedUrl(
        original: 'https://example.com/',
        canonical: 'https://example.com/',
        trackingQuery: null,
        scheme: 'https',
        host: 'example.com',
        pathSegments: $pathSegments,
        essentialQuery: [],
    );
}

it('returns empty string for empty pathSegments', function () {
    expect(makeParsedUrl([])->lastSegment())->toBe('');
});

it('returns the last segment for a non-empty pathSegments array', function () {
    expect(makeParsedUrl(['products', 'handle'])->lastSegment())->toBe('handle');
});

it('returns the only segment for a single-element pathSegments array', function () {
    expect(makeParsedUrl(['solo'])->lastSegment())->toBe('solo');
});
