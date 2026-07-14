<?php

use App\Services\Platforms\InstagramScraper;
use Illuminate\Support\Facades\Http;
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
        'biography' => "Book now: https://fresha.com/a/x and DM me for collabs!",
    ]);

    expect($links)->toBe(['https://fresha.com/a/x']);
});

it('strips trailing sentence punctuation off a biography URL', function () {
    $links = (new InstagramScraper)->bioLinks(['biography' => 'Site: https://example.com.']);

    expect($links)->toBe(['https://example.com']);
});

it('extracts multiple biography URLs in order', function () {
    $links = (new InstagramScraper)->bioLinks([
        'biography' => "Shop https://shop.example.com or book https://fresha.com/a/x",
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

// ── fetchProfile() end-to-end: bio fields flow through from a realistic Apify item ──

function igScraperItem(array $overrides = []): array
{
    return array_merge([
        'fullName' => 'Doc Pizza',
        'profilePicUrlHD' => 'https://scontent.cdninstagram.com/pic.jpg',
        'businessCategoryName' => 'Restaurant',
        'followersCount' => 500,
        'postsCount' => 42,
        'biography' => "Wood-fired pizza. Book a table: https://www.opentable.com.au/r/doc-pizza",
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
