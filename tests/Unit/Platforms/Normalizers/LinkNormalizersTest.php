<?php

use App\Services\Platforms\Normalizers\FacebookNormalizer;
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

it('Facebook normalizes a vanity handle', function () {
    expect((new FacebookNormalizer)('@nike'))
        ->toBe(['username' => 'nike', 'url' => 'https://www.facebook.com/nike']);
});

it('Facebook normalizes a bare facebook.com/<Page> vanity url', function () {
    // G4-4 required shape: facebook.com/MyPage.
    expect((new FacebookNormalizer)('facebook.com/MyPage'))
        ->toBe(['username' => 'MyPage', 'url' => 'https://www.facebook.com/MyPage']);
});

it('Facebook extracts the Page name from a legacy /pages/Name/ID link (G4-4)', function () {
    // G4-4: this used to store the literal reserved segment "pages" as the
    // username; it must now skip past it to the actual page name.
    expect((new FacebookNormalizer)('https://www.facebook.com/pages/Some-Cafe/123456789'))
        ->toBe(['username' => 'Some-Cafe', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123456789']);
});

it('Facebook required shape: facebook.com/pages/DOC-Pizza-Carlton/12345', function () {
    expect((new FacebookNormalizer)('facebook.com/pages/DOC-Pizza-Carlton/12345'))
        ->toBe(['username' => 'DOC-Pizza-Carlton', 'url' => 'https://www.facebook.com/pages/DOC-Pizza-Carlton/12345']);
});

it('Facebook strips a query string from a /pages/ link but keeps the extracted name', function () {
    expect((new FacebookNormalizer)('https://www.facebook.com/pages/Some-Cafe/123456789?ref=bookmarks'))
        ->toBe(['username' => 'Some-Cafe', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123456789']);
});

it('Facebook falls back to the numeric id when a /pages/ link has no name segment', function () {
    expect((new FacebookNormalizer)('https://www.facebook.com/pages/123456789'))
        ->toBe(['username' => '123456789', 'url' => 'https://www.facebook.com/pages/123456789']);
});

it('Facebook required shape: m.facebook.com/pages/X/9 (mobile subdomain, single-char name)', function () {
    expect((new FacebookNormalizer)('m.facebook.com/pages/X/9'))
        ->toBe(['username' => 'X', 'url' => 'https://www.facebook.com/pages/X/9']);
});

it('Facebook extracts a /people/Name/ID link the same way as /pages/', function () {
    expect((new FacebookNormalizer)('https://www.facebook.com/people/Jane-Doe/100012345'))
        ->toBe(['username' => 'Jane-Doe', 'url' => 'https://www.facebook.com/people/Jane-Doe/100012345']);
});

it('Facebook extracts a /groups/Name link the same way as /pages/', function () {
    expect((new FacebookNormalizer)('https://www.facebook.com/groups/SomeCommunity'))
        ->toBe(['username' => 'SomeCommunity', 'url' => 'https://www.facebook.com/groups/SomeCommunity']);
});

it('Facebook keeps the numeric id from a profile.php link as the username (G4-4)', function () {
    // G4-4: previously stored an empty username here too; the numeric id is a
    // better display fallback than nothing since there's no vanity handle at all.
    expect((new FacebookNormalizer)('https://www.facebook.com/profile.php?id=12345'))
        ->toBe(['username' => '12345', 'url' => 'https://www.facebook.com/profile.php?id=12345']);
});

it('Facebook required shape: facebook.com/profile.php?id=123', function () {
    expect((new FacebookNormalizer)('facebook.com/profile.php?id=123'))
        ->toBe(['username' => '123', 'url' => 'https://www.facebook.com/profile.php?id=123']);
});

it('Facebook keeps an empty username for a profile.php link with no id', function () {
    expect((new FacebookNormalizer)('https://www.facebook.com/profile.php'))
        ->toBe(['username' => '', 'url' => 'https://www.facebook.com/profile.php']);
});

it('Facebook rejects a bare facebook.com/ link with no handle', function () {
    expect((new FacebookNormalizer)('https://www.facebook.com/'))->toBeNull();
});
