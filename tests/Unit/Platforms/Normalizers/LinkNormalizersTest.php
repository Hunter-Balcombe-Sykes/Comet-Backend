<?php

use App\Services\Platforms\Normalizers\LinkedinNormalizer;
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

it('LinkedIn normalizes an /in/ profile url', function () {
    expect((new LinkedinNormalizer)('https://www.linkedin.com/in/jane-doe/'))
        ->toBe(['username' => 'jane-doe', 'url' => 'https://www.linkedin.com/in/jane-doe/']);
});

it('LinkedIn maps a bare slug to an /in/ profile', function () {
    expect((new LinkedinNormalizer)('jane-doe'))
        ->toBe(['username' => 'jane-doe', 'url' => 'https://www.linkedin.com/in/jane-doe/']);
});

it('LinkedIn keeps a /company/ url under the company path', function () {
    expect((new LinkedinNormalizer)('https://www.linkedin.com/company/acme/'))
        ->toBe(['username' => 'acme', 'url' => 'https://www.linkedin.com/company/acme/']);
});

it('LinkedIn rejects a non-profile linkedin.com url', function () {
    expect((new LinkedinNormalizer)('https://www.linkedin.com/feed/'))->toBeNull();
});
