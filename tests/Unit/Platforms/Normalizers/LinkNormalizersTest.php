<?php

use App\Services\Platforms\Normalizers\FacebookNormalizer;
use App\Services\Platforms\Normalizers\LinkedinNormalizer;
use App\Services\Platforms\Normalizers\MediumNormalizer;
use App\Services\Platforms\Normalizers\RedditNormalizer;
use App\Services\Platforms\Normalizers\SkoolNormalizer;
use App\Services\Platforms\Normalizers\StravaNormalizer;
use App\Services\Platforms\Normalizers\ThreadsNormalizer;
use App\Services\Platforms\Normalizers\TiktokNormalizer;
use App\Services\Platforms\Normalizers\TwitchNormalizer;
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

it('Medium accepts a 2-char handle — its own founder is @ev', function () {
    // Regression: the {3,40} grammar rejected real 2-char Medium handles.
    expect((new MediumNormalizer)('ev'))
        ->toBe(['username' => 'ev', 'url' => 'https://medium.com/@ev']);
    expect((new MediumNormalizer)('https://medium.com/@ev'))
        ->toBe(['username' => 'ev', 'url' => 'https://medium.com/@ev']);
});

it('Medium still rejects a 1-char handle', function () {
    expect((new MediumNormalizer)('e'))->toBeNull();
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

// ── fix-round P3 sweep (critic-backend): gaps this PR's own FacebookNormalizer
// rewrite left uncovered ───────────────────────────────────────────────────

it('Facebook recognizes the short fb.com domain the same way as facebook.com (fix-round P3)', function () {
    expect((new FacebookNormalizer)('https://fb.com/MyPage'))
        ->toBe(['username' => 'MyPage', 'url' => 'https://www.facebook.com/MyPage']);
});

it('Facebook does not mistake an unrelated domain merely ending in "fb.com" for the short domain (fix-round P3)', function () {
    // \bfb\.com requires a word boundary before "fb" — "myfb.com" must NOT
    // be parsed as the fb.com short domain; it falls through to the generic
    // bare-input branch like any other unrecognized string.
    expect((new FacebookNormalizer)('myfb.com/MyPage'))
        ->toBe(['username' => 'myfb.com/MyPage', 'url' => 'https://www.facebook.com/myfb.com/MyPage']);
});

it('Facebook unwraps an l.facebook.com click-through redirect to the real target (fix-round P3)', function () {
    expect((new FacebookNormalizer)('https://l.facebook.com/l.php?u=https%3A%2F%2Fwww.facebook.com%2FMyPage%3Ffbclid%3Dabc123&h=xyz'))
        ->toBe(['username' => 'MyPage', 'url' => 'https://www.facebook.com/MyPage']);
});

it('Facebook unwraps the lm.facebook.com mobile variant the same way (fix-round P3)', function () {
    expect((new FacebookNormalizer)('https://lm.facebook.com/l.php?u=https%3A%2F%2Fwww.facebook.com%2FMyPage'))
        ->toBe(['username' => 'MyPage', 'url' => 'https://www.facebook.com/MyPage']);
});

it('Facebook treats an l.facebook.com redirect with no usable u param as reserved/unrecognized, not the literal "l.php" as a username (fix-round P3)', function () {
    expect((new FacebookNormalizer)('https://l.facebook.com/l.php'))
        ->toBe(['username' => '', 'url' => 'https://l.facebook.com/l.php']);
});

it('Facebook decodes a percent-encoded unicode Page name for the username but keeps the url percent-encoded (fix-round P3)', function () {
    expect((new FacebookNormalizer)('https://www.facebook.com/Caf%C3%A9-Ren%C3%A9'))
        ->toBe(['username' => 'Café-René', 'url' => 'https://www.facebook.com/Caf%C3%A9-Ren%C3%A9']);
});

it('Facebook decodes a percent-encoded unicode name past a reserved /pages/ segment too (fix-round P3)', function () {
    expect((new FacebookNormalizer)('https://www.facebook.com/pages/Caf%C3%A9-Ren%C3%A9/123456'))
        ->toBe(['username' => 'Café-René', 'url' => 'https://www.facebook.com/pages/Caf%C3%A9-Ren%C3%A9/123456']);
});

it('Facebook returns an empty reserved username for a bare "/pages/" link with no name/id segment at all (P4 regression coverage)', function () {
    // Already handled correctly by the existing reserved-segment logic
    // (critic P4) — this pins the exact degenerate-input case with a test,
    // since none previously covered it.
    expect((new FacebookNormalizer)('https://www.facebook.com/pages/'))
        ->toBe(['username' => '', 'url' => 'https://www.facebook.com/pages']);
});

// ── Phase 1 follow-through: twitch / skool / strava became link-only ────────
// Each rule below is carried over from the SourceProvisioner::identifierFor()
// helper that Phase 1 deleted with the connector, so the validation knowledge
// moved rather than being lost.

it('Twitch normalizes a bare login, lowercased', function () {
    expect((new TwitchNormalizer)('SomeStreamer'))
        ->toBe(['username' => 'somestreamer', 'url' => 'https://twitch.tv/somestreamer']);
});

it('Twitch normalizes a channel url and tolerates trailing junk', function () {
    expect((new TwitchNormalizer)('https://www.twitch.tv/Monstercat?tt_medium=share'))
        ->toBe(['username' => 'monstercat', 'url' => 'https://twitch.tv/monstercat']);
});

it('Twitch rejects site chrome and an out-of-range login', function () {
    expect((new TwitchNormalizer)('https://twitch.tv/directory'))->toBeNull()
        ->and((new TwitchNormalizer)('ab'))->toBeNull()
        ->and((new TwitchNormalizer)('https://twitch.tv/'))->toBeNull();
});

it('Skool normalizes a community url to the canonical www form', function () {
    expect((new SkoolNormalizer)('https://skool.com/Max-Business-School/about?ref=x'))
        ->toBe(['username' => 'max-business-school', 'url' => 'https://www.skool.com/max-business-school']);
});

it('Skool refuses product chrome that looks like a valid slug', function () {
    // The load-bearing case: /signup parses fine as a slug, and without the
    // chrome list a pasted sign-up page would be saved as a "community".
    expect((new SkoolNormalizer)('https://www.skool.com/signup'))->toBeNull()
        ->and((new SkoolNormalizer)('https://www.skool.com/login'))->toBeNull();
});

it('Strava normalizes a club url and a bare club id', function () {
    expect((new StravaNormalizer)('https://strava.com/clubs/Midday-Milers'))
        ->toBe(['username' => 'Midday-Milers', 'url' => 'https://www.strava.com/clubs/Midday-Milers'])
        ->and((new StravaNormalizer)('289149'))
        ->toBe(['username' => '289149', 'url' => 'https://www.strava.com/clubs/289149']);
});

it('Strava refuses an athlete url rather than coercing it into a club link', function () {
    expect((new StravaNormalizer)('https://www.strava.com/athletes/12345'))->toBeNull();
});
