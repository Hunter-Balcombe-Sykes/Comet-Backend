<?php

use App\Services\Platforms\InstagramScraper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Http::fake needs the Laravel test framework bootstrapped (mirrors
// tests/Unit/Http/SafeUrlFetcherTest.php).
uses(TestCase::class)->in(__FILE__);

// ── bioLinks() — defensive extraction from the raw Apify profile item ────────
// BE2: the actor's field names vary by version, so bioLinks() reads
// externalUrl + externalUrls[].url + URLs regexed out of biography, defensively.

it('collects externalUrl when present', function () {
    $links = (new InstagramScraper)->bioLinks(['externalUrl' => 'https://example.com']);

    expect($links)->toBe(['https://example.com']);
});

it('collects externalUrls[].url entries', function () {
    $links = (new InstagramScraper)->bioLinks([
        'externalUrls' => [
            ['url' => 'https://a.example.com'],
            ['url' => 'https://b.example.com'],
        ],
    ]);

    expect($links)->toBe(['https://a.example.com', 'https://b.example.com']);
});

it('tolerates externalUrls as a plain list of URL strings (actor version variance)', function () {
    $links = (new InstagramScraper)->bioLinks([
        'externalUrls' => ['https://a.example.com', 'https://b.example.com'],
    ]);

    expect($links)->toBe(['https://a.example.com', 'https://b.example.com']);
});

it('regex-extracts URLs out of the biography text', function () {
    $links = (new InstagramScraper)->bioLinks([
        'biography' => 'Book now: https://fresha.com/a/x and DM me for collabs!',
    ]);

    expect($links)->toBe(['https://fresha.com/a/x']);
});

it('strips trailing sentence punctuation off a biography URL', function () {
    $links = (new InstagramScraper)->bioLinks(['biography' => 'Site: https://example.com.']);

    expect($links)->toBe(['https://example.com']);
});

it('extracts multiple biography URLs in order', function () {
    $links = (new InstagramScraper)->bioLinks([
        'biography' => 'Shop https://shop.example.com or book https://fresha.com/a/x',
    ]);

    expect($links)->toBe(['https://shop.example.com', 'https://fresha.com/a/x']);
});

it('collects and dedupes across all three sources, externalUrl first then externalUrls then biography', function () {
    $links = (new InstagramScraper)->bioLinks([
        'externalUrl' => 'https://example.com',
        'externalUrls' => [['url' => 'https://example.com'], ['url' => 'https://other.example.com']],
        'biography' => 'Also see https://example.com and https://third.example.com',
    ]);

    expect($links)->toBe([
        'https://example.com',
        'https://other.example.com',
        'https://third.example.com',
    ]);
});

it('caps bio links at 10', function () {
    $externalUrls = [];
    for ($i = 0; $i < 15; $i++) {
        $externalUrls[] = ['url' => "https://example{$i}.com"];
    }

    $links = (new InstagramScraper)->bioLinks(['externalUrls' => $externalUrls]);

    expect($links)->toHaveCount(10);
});

it('returns an empty array when the profile has none of the bio fields (todays real Apify shape)', function () {
    expect((new InstagramScraper)->bioLinks([]))->toBe([]);
    expect((new InstagramScraper)->bioLinks(['fullName' => 'Acme', 'followersCount' => 10]))->toBe([]);
});

it('returns an empty array when biography has no URLs', function () {
    expect((new InstagramScraper)->bioLinks(['biography' => 'Just a normal bio, no links here.']))->toBe([]);
});

it('tolerates malformed/non-string field types without throwing', function () {
    $links = (new InstagramScraper)->bioLinks([
        'externalUrl' => 123,
        'externalUrls' => 'not-an-array',
        'biography' => null,
    ]);

    expect($links)->toBe([]);
});

it('drops a non-http externalUrl and biography-embedded non-http scheme', function () {
    $links = (new InstagramScraper)->bioLinks([
        'externalUrl' => 'javascript:alert(1)',
        'biography' => 'ftp://not-web.example.com is not a real link',
    ]);

    expect($links)->toBe([]);
});

// ── bio_links[] — the figue~instagram-profile-scraper actor shape ────────────

it('extracts bio links from the figue actor shape (bio_links array)', function () {
    $links = (new InstagramScraper)->bioLinks([
        'bio_links' => [['url' => 'https://linktr.ee/venue'], ['url' => 'https://instagram.com/venue']],
    ]);

    expect($links)->toBe(['https://linktr.ee/venue', 'https://instagram.com/venue']);
});

it('collects bio_links ahead of externalUrl/externalUrls/biography, deduped across all four sources', function () {
    $links = (new InstagramScraper)->bioLinks([
        'bio_links' => [['url' => 'https://linktr.ee/venue']],
        'externalUrl' => 'https://linktr.ee/venue', // duplicate of a bio_links entry
        'externalUrls' => [['url' => 'https://other.example.com']],
        'biography' => 'Also see https://third.example.com',
    ]);

    expect($links)->toBe([
        'https://linktr.ee/venue',
        'https://other.example.com',
        'https://third.example.com',
    ]);
});

it('tolerates a bio_links entry that is a bare string, and ignores non-string entries', function () {
    $links = (new InstagramScraper)->bioLinks([
        'bio_links' => ['https://linktr.ee/venue', 123, null],
    ]);

    expect($links)->toBe(['https://linktr.ee/venue']);
});

// ── fetchProfile() end-to-end: bio fields flow through from a realistic Apify item ──

function igScraperItem(array $overrides = []): array
{
    return array_merge([
        'fullName' => 'Doc Pizza',
        'profilePicUrlHD' => 'https://scontent.cdninstagram.com/pic.jpg',
        'businessCategoryName' => 'Restaurant',
        'followersCount' => 500,
        'postsCount' => 42,
        'biography' => 'Wood-fired pizza. Book a table: https://www.opentable.com.au/r/doc-pizza',
        'externalUrl' => 'https://docpizza.example.com',
        'externalUrls' => [
            ['url' => 'https://www.facebook.com/docpizzabar'],
        ],
        'latestPosts' => [],
    ], $overrides);
}

it('fetchProfile returns the raw item with bio fields intact for bioLinks() to read', function () {
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => Http::response([igScraperItem()], 201)]);

    $scraper = new InstagramScraper;
    $profile = $scraper->fetchProfile('docpizza');

    expect($profile)->not->toBeNull();
    expect($profile['biography'])->toContain('opentable.com.au');
    expect($profile['externalUrl'])->toBe('https://docpizza.example.com');

    $links = $scraper->bioLinks($profile);
    expect($links)->toBe([
        'https://docpizza.example.com',
        'https://www.facebook.com/docpizzabar',
        'https://www.opentable.com.au/r/doc-pizza',
    ]);
});

it('posts to the configured apify actor id', function () {
    config(['services.apify.token' => 'test-token', 'partna.instagram.actor' => 'figue~instagram-profile-scraper']);
    Http::fake(['api.apify.com/*' => Http::response([igScraperItem()], 201)]);

    (new InstagramScraper)->fetchProfile('docpizza');

    Http::assertSent(fn ($request) => str_contains(
        $request->url(),
        'acts/figue~instagram-profile-scraper/run-sync-get-dataset-items'
    ));
});

// Regression for the 2026-07-23 zero-media incident: the actor's posts are OFF
// by default ("profile data only"), so the request MUST opt in — and the old
// resultsLimit key isn't in the actor's input schema at all (was silently
// ignored for its whole life), so it must stay gone.
it('sends includeRecentPosts and never the schemaless resultsLimit key', function () {
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => Http::response([igScraperItem()], 201)]);

    (new InstagramScraper)->fetchProfile('docpizza');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['includeRecentPosts'] ?? null) === true
            && ! array_key_exists('resultsLimit', $body)
            && $body['profiles'] === ['docpizza'];
    });
});

it('fetchProfile + bioLinks tolerate a profile with none of the bio fields (older Apify actor shape)', function () {
    config(['services.apify.token' => 'test-token']);
    // Today's real actor output: no biography / externalUrl / externalUrls at all.
    Http::fake(['api.apify.com/*' => Http::response([[
        'fullName' => 'Legacy User',
        'followersCount' => 10,
        'postsCount' => 2,
        'latestPosts' => [],
    ]], 201)]);

    $scraper = new InstagramScraper;
    $profile = $scraper->fetchProfile('legacyuser');

    expect($profile)->not->toBeNull();
    expect($scraper->bioLinks($profile))->toBe([]);
});

// ── snake_case actor shape (figue actor, raw Instagram GraphQL — live 2026-07-23) ──
// The live actor returns profile_pic_url_hd / display_url / video_url, not the
// legacy camelCase names. Both shapes must read.

it('reads the snake_case profile pic fields the figue actor returns', function () {
    $scraper = new InstagramScraper;

    expect($scraper->profilePicUrl([
        'profile_pic_url_hd' => 'https://scontent.cdninstagram.com/hd.jpg',
        'profile_pic_url' => 'https://scontent.cdninstagram.com/sd.jpg',
    ]))->toBe('https://scontent.cdninstagram.com/hd.jpg');

    expect($scraper->profilePicUrl([
        'profile_pic_url' => 'https://scontent.cdninstagram.com/sd.jpg',
    ]))->toBe('https://scontent.cdninstagram.com/sd.jpg');

    // Legacy camelCase still wins when present (older stored shapes).
    expect($scraper->profilePicUrl([
        'profilePicUrlHD' => 'https://scontent.cdninstagram.com/legacy-hd.jpg',
        'profile_pic_url_hd' => 'https://scontent.cdninstagram.com/hd.jpg',
    ]))->toBe('https://scontent.cdninstagram.com/legacy-hd.jpg');
});

it('picks photo cover and video url from the snake_case post shape', function () {
    $media = (new InstagramScraper)->latestMedia([
        'latestPosts' => [
            [
                'type' => 'Video',
                'display_url' => 'https://scontent.cdninstagram.com/reel-cover.jpg',
                'video_url' => 'https://scontent.cdninstagram.com/reel.mp4',
                'timestamp' => '2026-07-20T00:00:00.000Z',
                'shortCode' => 'reel1',
            ],
            [
                'type' => 'Image',
                'display_url' => 'https://scontent.cdninstagram.com/photo.jpg',
                'timestamp' => '2026-07-19T00:00:00.000Z',
                'shortCode' => 'img1',
            ],
        ],
    ]);

    expect($media['photo']['thumbnailUrl'])->toBe('https://scontent.cdninstagram.com/photo.jpg');
    expect($media['video']['videoUrl'])->toBe('https://scontent.cdninstagram.com/reel.mp4');
    expect($media['video']['thumbnailUrl'])->toBe('https://scontent.cdninstagram.com/reel-cover.jpg');
});

// ── R1: broadened reel detection + diagnostics ────────────────────────────
// Live-tested 2026-07-24 against the real account: today's figue-actor grid
// node reliably carries type==='Video' + videoUrl/video_url — that path
// already works and must keep working unchanged (test above). This section
// adds defence-in-depth for actor-field variance seen on other Meta scrapers
// (a clips/igtv product_type, a GraphQL __typename, an mp4 nested under
// video_versions[0].url) plus a diagnostics trail so a future "reel didn't
// mirror" report is diagnosable from stored data instead of a guess.

it('detects a reel via product_type/video_versions when type/videoUrl are absent (actor field variance)', function () {
    $media = (new InstagramScraper)->latestMedia([
        'latestPosts' => [
            [
                // No 'type' key, no 'videoUrl'/'video_url' — only the fields a
                // different actor-version grid node might carry.
                'product_type' => 'clips',
                'display_url' => 'https://scontent.cdninstagram.com/reel-cover.jpg',
                'video_versions' => [['url' => 'https://scontent.cdninstagram.com/reel.mp4']],
                'timestamp' => '2026-07-20T00:00:00.000Z',
                'shortCode' => 'reel1',
            ],
        ],
    ]);

    expect($media['video']['videoUrl'])->toBe('https://scontent.cdninstagram.com/reel.mp4');
});

it('detects a reel via is_video=true', function () {
    $media = (new InstagramScraper)->latestMedia([
        'latestPosts' => [
            [
                'is_video' => true,
                'display_url' => 'https://scontent.cdninstagram.com/reel-cover.jpg',
                'video_url' => 'https://scontent.cdninstagram.com/reel.mp4',
                'timestamp' => '2026-07-20T00:00:00.000Z',
                'shortCode' => 'reel1',
            ],
        ],
    ]);

    expect($media['video']['videoUrl'])->toBe('https://scontent.cdninstagram.com/reel.mp4');
});

it('detects a reel via a GraphVideo __typename', function () {
    $media = (new InstagramScraper)->latestMedia([
        'latestPosts' => [
            [
                '__typename' => 'GraphVideo',
                'display_url' => 'https://scontent.cdninstagram.com/reel-cover.jpg',
                'video_url' => 'https://scontent.cdninstagram.com/reel.mp4',
                'timestamp' => '2026-07-20T00:00:00.000Z',
                'shortCode' => 'reel1',
            ],
        ],
    ]);

    expect($media['video']['videoUrl'])->toBe('https://scontent.cdninstagram.com/reel.mp4');
});

it('does not misdetect a plain image post as a video', function () {
    $media = (new InstagramScraper)->latestMedia([
        'latestPosts' => [
            [
                'type' => 'Image',
                'product_type' => 'feed', // 'feed' alone (no video signal) must not trip detection
                'display_url' => 'https://scontent.cdninstagram.com/photo.jpg',
                'timestamp' => '2026-07-20T00:00:00.000Z',
                'shortCode' => 'img1',
            ],
        ],
    ]);

    expect($media['video'])->toBeNull();
    expect($media['photo']['thumbnailUrl'])->toBe('https://scontent.cdninstagram.com/photo.jpg');
});

it('returns diagnostics: total posts, video count, and per-video mp4 presence', function () {
    $media = (new InstagramScraper)->latestMedia([
        'latestPosts' => [
            // A video post the actor gave NO mp4 for — must still count as a
            // video candidate (has_mp4 false), not vanish silently.
            ['type' => 'Video', 'display_url' => 'https://x/cover.jpg', 'timestamp' => '2026-07-20T00:00:00.000Z', 'shortCode' => 'reel1'],
            ['type' => 'Image', 'display_url' => 'https://x/photo.jpg', 'timestamp' => '2026-07-19T00:00:00.000Z', 'shortCode' => 'img1'],
        ],
    ]);

    expect($media['diagnostics'])->toBe([
        'posts' => 2,
        'videos' => 1,
        'pickedPhoto' => true,
        'pickedVideo' => false,
        'videoCandidates' => [
            ['shortCode' => 'reel1', 'hasMp4' => false, 'type' => 'Video'],
        ],
    ]);
});

it('caps videoCandidates in diagnostics at 5, even with more video posts', function () {
    $posts = [];
    for ($i = 0; $i < 8; $i++) {
        $posts[] = ['type' => 'Video', 'display_url' => "https://x/cover{$i}.jpg", 'video_url' => "https://x/reel{$i}.mp4", 'timestamp' => "2026-07-{$i}T00:00:00.000Z", 'shortCode' => "reel{$i}"];
    }

    $media = (new InstagramScraper)->latestMedia(['latestPosts' => $posts]);

    expect($media['diagnostics']['videos'])->toBe(8);
    expect($media['diagnostics']['videoCandidates'])->toHaveCount(5);
});

// ── fetchProfile() failure logging: hashed + case-normalised username, never raw ──
// SEC-102: the log context must never carry the raw Instagram handle, and the
// hash must be computed from the LOWERCASED username so "DocPizza" and "docpizza"
// (the same real account) correlate to the same hash in the logs.

it('treats a 2xx Apify response carrying an "error" key as a failed scrape, not a valid empty profile', function () {
    // Reproduced live 2026-07-20 against the real Apify actor: a profile it
    // can't retrieve still comes back 2xx with a dataset item shaped like a
    // profile but carrying an "error" string instead of real fields.
    Log::spy();
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'somehandle',
        'full_name' => null,
        'url' => 'https://www.instagram.com/somehandle/',
        'inputUrl' => 'https://www.instagram.com/somehandle/',
        'scrapedAt' => '2026-07-20T06:29:14.988Z',
        'error' => 'Could not retrieve profile data — please try again later',
    ]], 201)]);

    $profile = (new InstagramScraper)->fetchProfile('somehandle', 'user-123');

    expect($profile)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'instagram.apify.error_item'
            && ($context['user_id'] ?? null) === 'user-123'
            && ($context['username_hash'] ?? null) === hash('sha256', 'somehandle'));
});

it('logs a lowercase-normalised username hash — never the raw username — when the Apify call throws', function () {
    Exceptions::fake(); // the catch block also calls report($e); don't let that hit the real handler
    Log::spy();
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => fn () => throw new ConnectionException('timeout')]);

    // Mixed case is the point: if the hash were computed from the raw (unlowered)
    // username, this would not equal hash('sha256', mb_strtolower($username)) below
    // — that's what makes this test fail on a revert of the Defect 1 fix.
    $username = 'DocPizza';
    $result = (new InstagramScraper)->fetchProfile($username, 'user-123');

    expect($result)->toBeNull();

    $expectedHash = hash('sha256', mb_strtolower($username));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($expectedHash, $username) {
            return $message === 'instagram.apify.threw'
                && ($context['username_hash'] ?? null) === $expectedHash
                && ($context['user_id'] ?? null) === 'user-123'
                && ! array_key_exists('username', $context)
                // Belt-and-braces: the raw handle must not appear anywhere in the
                // logged context, under any key.
                && ! in_array($username, $context, true);
        });
});
