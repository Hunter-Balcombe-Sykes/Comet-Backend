<?php

use App\Services\Platforms\Normalizers\XNormalizer;

it('X normalizes a bare @handle to the canonical url', function () {
    expect((new XNormalizer)('@janed'))->toBe(['username' => 'janed', 'url' => 'https://x.com/janed']);
});

it('X normalizes a twitter.com profile url', function () {
    expect((new XNormalizer)('https://twitter.com/janed'))->toBe(['username' => 'janed', 'url' => 'https://x.com/janed']);
});

it('X rejects reserved first-segment paths', function () {
    expect((new XNormalizer)('https://x.com/home'))->toBeNull();
});

it('X rejects an over-long handle', function () {
    expect((new XNormalizer)('thishandleiswaytoolongforx'))->toBeNull();
});
