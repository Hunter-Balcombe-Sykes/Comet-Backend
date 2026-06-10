<?php

use App\Services\Platforms\PodcastFeedService;
use App\Services\SmartLinks\SafeUrlFetcher;

afterEach(function () {
    Mockery::close();
});

function podcastServiceWith(array $routes): PodcastFeedService
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

    return new PodcastFeedService($fetcher);
}

function podcastFeedXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
  <channel>
    <title>Syntax — Tasty Treats</title>
    <link>https://syntax.fm</link>
    <description><![CDATA[A <b>tasty</b> web development podcast.]]></description>
    <itunes:image href="https://feeds.example/art.jpg"/>
    <item>
      <title>Episode 900: The Big One</title>
      <link>https://syntax.fm/show/900</link>
      <guid>syntax-900</guid>
      <pubDate>Mon, 08 Jun 2026 10:00:00 +0000</pubDate>
      <itunes:duration>01:02:30</itunes:duration>
      <enclosure url="https://traffic.example/900.mp3" type="audio/mpeg" length="1"/>
    </item>
    <item>
      <title>Episode 899</title>
      <link>https://syntax.fm/show/899</link>
      <guid>syntax-899</guid>
      <pubDate>Mon, 01 Jun 2026 10:00:00 +0000</pubDate>
      <itunes:duration>1830</itunes:duration>
      <enclosure url="https://traffic.example/899.mp3" type="audio/mpeg" length="1"/>
    </item>
  </channel>
</rss>
XML;
}

it('parses an RSS podcast feed with itunes fields and audio enclosures', function () {
    $service = podcastServiceWith([]);

    $result = $service->parseFeed(podcastFeedXml());

    expect($result['show']['name'])->toBe('Syntax — Tasty Treats');
    expect($result['show']['thumbnail'])->toBe('https://feeds.example/art.jpg');
    expect($result['show']['description'])->toBe('A tasty web development podcast.');

    expect($result['episodes'])->toHaveCount(2);
    expect($result['episodes'][0])->toMatchArray([
        'itemId' => 'syntax-900',
        'title' => 'Episode 900: The Big One',
        'duration' => '1 hr 2 min',
        'audioUrl' => 'https://traffic.example/900.mp3',
        'link' => 'https://syntax.fm/show/900',
    ]);
    // Bare-seconds duration normalises too (floored, like podcast apps show).
    expect($result['episodes'][1]['duration'])->toBe('30 min');
});

it('accepts a direct feed URL and autodiscovers from a host page', function () {
    $service = podcastServiceWith([
        'feeds.example/show.rss' => ['status' => 200, 'body' => podcastFeedXml(), 'finalUrl' => 'x', 'contentType' => 'application/rss+xml'],
        'buzzsprout.com/231452' => ['status' => 200, 'body' => '<html><head><link type="application/rss+xml" rel="alternate" href="https://feeds.example/show.rss"></head></html>', 'finalUrl' => 'x', 'contentType' => 'text/html'],
    ]);

    $direct = $service->fetchFromInput('https://feeds.example/show.rss');
    expect($direct['feedUrl'])->toBe('https://feeds.example/show.rss');
    expect($direct['show']['name'])->toBe('Syntax — Tasty Treats');

    $discovered = $service->fetchFromInput('https://www.buzzsprout.com/231452');
    expect($discovered['feedUrl'])->toBe('https://feeds.example/show.rss');
    expect($discovered['episodes'])->toHaveCount(2);
});

it('rejects DOCTYPE-bearing XML and pages with no feed', function () {
    $service = podcastServiceWith([
        'evil.example' => ['status' => 200, 'body' => '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss/>', 'finalUrl' => 'x', 'contentType' => 'application/rss+xml'],
        'plain.example' => ['status' => 200, 'body' => '<html><head></head><body>No feed here</body></html>', 'finalUrl' => 'x', 'contentType' => 'text/html'],
    ]);

    expect($service->fetchFromInput('https://evil.example/feed'))->toBeNull();
    expect($service->fetchFromInput('https://plain.example/'))->toBeNull();
});
