<?php

use App\Services\Platforms\Normalizers\LinkedinNormalizer;
use App\Services\Platforms\Normalizers\RedditNormalizer;
use App\Services\Platforms\Normalizers\ThreadsNormalizer;
use App\Services\Platforms\Normalizers\TiktokNormalizer;
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

it('Threads normalizes a bare @handle', function () {
    expect((new ThreadsNormalizer)('@janed'))
        ->toBe(['username' => 'janed', 'url' => 'https://www.threads.net/@janed']);
});

it('Threads normalizes a threads.com profile url', function () {
    expect((new ThreadsNormalizer)('https://www.threads.com/@janed'))
        ->toBe(['username' => 'janed', 'url' => 'https://www.threads.net/@janed']);
});

it('Threads rejects an invalid handle', function () {
    expect((new ThreadsNormalizer)('has spaces!'))->toBeNull();
});

it('Reddit normalizes a u/ username to the user profile url', function () {
    expect((new RedditNormalizer)('u/janed'))
        ->toBe(['username' => 'janed', 'url' => 'https://www.reddit.com/user/janed/']);
});

it('Reddit normalizes an r/ community', function () {
    expect((new RedditNormalizer)('r/community'))
        ->toBe(['username' => 'community', 'url' => 'https://www.reddit.com/r/community/']);
});

it('Reddit maps a bare username to a user profile', function () {
    expect((new RedditNormalizer)('janed'))
        ->toBe(['username' => 'janed', 'url' => 'https://www.reddit.com/user/janed/']);
});

it('Reddit rejects a reddit.com url without a profile/community path', function () {
    expect((new RedditNormalizer)('https://www.reddit.com/about'))->toBeNull();
});

it('TikTok normalizes a bare @handle', function () {
    expect((new TiktokNormalizer)('@dancer'))
        ->toBe(['username' => 'dancer', 'url' => 'https://www.tiktok.com/@dancer']);
});

it('TikTok normalizes a tiktok.com/@handle url', function () {
    expect((new TiktokNormalizer)('https://www.tiktok.com/@dancer'))
        ->toBe(['username' => 'dancer', 'url' => 'https://www.tiktok.com/@dancer']);
});

it('TikTok rejects an @-only input (empty handle)', function () {
    expect((new TiktokNormalizer)('@'))->toBeNull();
});
