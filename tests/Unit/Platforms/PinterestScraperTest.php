<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\PinterestScraper;

afterEach(function () {
    Mockery::close();
});

function pinterestScraperWith(array $routes): PinterestScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturnUsing(function (string $url) use ($routes) {
        foreach ($routes as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }

        return ['status' => 404, 'body' => '', 'finalUrl' => $url, 'contentType' => ''];
    });

    return new PinterestScraper($fetcher);
}

it('parses profile URLs across locale domains and bare handles', function () {
    $scraper = pinterestScraperWith([]);

    expect($scraper->parseUsername('https://au.pinterest.com/MockMaker/'))->toBe('mockmaker');
    expect($scraper->parseUsername('https://www.pinterest.com/mockmaker/boards/'))->toBe('mockmaker');
    expect($scraper->parseUsername('@mockmaker'))->toBe('mockmaker');
    expect($scraper->parseUsername('https://www.pinterest.com/pin/12345/'))->toBeNull(); // reserved
    expect($scraper->parseUsername('https://example.com/mockmaker'))->toBeNull();
});

// The page state embeds MANY user objects — the extractor must find the one
// whose username matches, not the first user it sees.
it('extracts the matching profile from the embedded page-state JSON', function () {
    $state = json_encode(['props' => ['initialReduxState' => ['users' => [
        // A decoy user first (another profile in the page state).
        ['username' => 'someoneelse', 'full_name' => 'Someone Else', 'follower_count' => 7, 'image_xlarge_url' => 'https://i.pinimg.com/other.jpg'],
        ['username' => 'mockmaker', 'full_name' => 'Mock Maker', 'follower_count' => 2361, 'image_xlarge_url' => 'https://i.pinimg.com/280x280_RS/me.jpg'],
    ]]]]);
    $html = '<html><head><meta property="og:title" content="Mock Maker (mockmaker) - Profile | Pinterest"></head>'
        .'<body><script type="application/json">'.$state.'</script></body></html>';

    $scraper = pinterestScraperWith([
        'pinterest.com/mockmaker/' => ['status' => 200, 'body' => $html, 'finalUrl' => 'x', 'contentType' => 'text/html'],
    ]);

    $profile = $scraper->fetchProfile('mockmaker');

    expect($profile)->toMatchArray([
        'username' => 'mockmaker',
        'name' => 'Mock Maker',
        'followers' => 2361,
        'image' => 'https://i.pinimg.com/280x280_RS/me.jpg',
    ]);
});

it('falls back to og:title when no page-state user object is found', function () {
    $html = '<html><head><meta property="og:title" content="Mock Maker (mockmaker) - Profile | Pinterest"></head><body></body></html>';

    $scraper = pinterestScraperWith([
        'pinterest.com/mockmaker/' => ['status' => 200, 'body' => $html, 'finalUrl' => 'x', 'contentType' => 'text/html'],
    ]);

    expect($scraper->fetchProfile('mockmaker')['name'])->toBe('Mock Maker');
});

it('maps the RSS pin feed and upgrades thumbnails to the 564px rendition', function () {
    $rss = <<<'XML'
<?xml version="1.0" encoding="utf-8"?><rss version="2.0">
  <channel>
    <title>Mock Maker</title>
    <item>
      <title> </title>
      <link>https://au.pinterest.com/pin/424605071145132476/</link>
      <description>&lt;a href="https://au.pinterest.com/pin/424605071145132476/"&gt;&lt;img src="https://i.pinimg.com/236x/a5/83/cb/pin.jpg"&gt;&lt;/a&gt;</description>
      <pubDate>Fri, 29 May 2026 15:57:39 GMT</pubDate>
    </item>
  </channel>
</rss>
XML;

    $scraper = pinterestScraperWith([
        '/mockmaker/feed.rss' => ['status' => 200, 'body' => $rss, 'finalUrl' => 'x', 'contentType' => 'application/rss+xml'],
    ]);

    $pins = $scraper->fetchPins('mockmaker');

    expect($pins)->toHaveCount(1);
    expect($pins[0]['itemId'])->toBe('424605071145132476');
    expect($pins[0]['thumbnail'])->toBe('https://i.pinimg.com/564x/a5/83/cb/pin.jpg');
    expect($pins[0]['link'])->toBe('https://au.pinterest.com/pin/424605071145132476/');
    expect($pins[0]['name'])->toBeNull(); // blank titles stay null
});
