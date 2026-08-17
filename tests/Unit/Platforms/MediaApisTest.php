<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\VimeoApi;

afterEach(function () {
    Mockery::close();
});

function mediaFetcherWith(array $routes): SafeUrlFetcher
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

    return $fetcher;
}

// ── Vimeo ────────────────────────────────────────────────────────────────────

it('vimeo parses profile and channel URLs, rejecting videos and reserved paths', function () {
    $api = new VimeoApi(mediaFetcherWith([]));

    expect($api->parseSource('https://vimeo.com/patagonia'))->toBe(['apiPath' => 'patagonia', 'link' => 'https://vimeo.com/patagonia']);
    expect($api->parseSource('https://www.vimeo.com/Patagonia/'))->toBe(['apiPath' => 'patagonia', 'link' => 'https://vimeo.com/patagonia']);
    expect($api->parseSource('https://vimeo.com/channels/staffpicks'))->toBe(['apiPath' => 'channel/staffpicks', 'link' => 'https://vimeo.com/channels/staffpicks']);
    expect($api->parseSource('https://vimeo.com/76979871'))->toBeNull();   // a video id
    expect($api->parseSource('https://vimeo.com/watch'))->toBeNull();      // reserved
    expect($api->parseSource('https://example.com/patagonia'))->toBeNull();
});

it('vimeo maps Simple-API videos with player embed URLs', function () {
    $api = new VimeoApi(mediaFetcherWith([
        '/api/v2/patagonia/videos.json' => ['status' => 200, 'body' => json_encode([
            ['id' => 1030868189, 'title' => 'The Last Observers', 'url' => 'https://vimeo.com/1030868189',
                'thumbnail_large' => 'https://i.vimeocdn.com/video/195.jpg', 'upload_date' => '2024-11-18 14:03:12'],
        ]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
        '/api/v2/patagonia/info.json' => ['status' => 200, 'body' => json_encode([
            'display_name' => 'Patagonia', 'portrait_huge' => 'https://i.vimeocdn.com/portrait/p.jpg', 'profile_url' => 'https://vimeo.com/patagonia',
        ]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]));

    $videos = $api->fetchVideos('patagonia');
    expect($videos[0])->toMatchArray([
        'itemId' => '1030868189',
        'name' => 'The Last Observers',
        'thumbnail' => 'https://i.vimeocdn.com/video/195.jpg',
        'embedUrl' => 'https://player.vimeo.com/video/1030868189',
    ]);

    $profile = $api->fetchProfile('patagonia');
    expect($profile['name'])->toBe('Patagonia');
    expect($profile['thumbnail'])->toBe('https://i.vimeocdn.com/portrait/p.jpg');
});

// ── Twitch ───────────────────────────────────────────────────────────────────
//
// REMOVED: 'twitch parses channel inputs and og-scrapes the channel card'.
// Twitch was demoted to link-only, so TwitchScraper is deleted — there is no
// channel card to og-scrape any more. The surviving half of that test (login
// parsing / reserved-path rejection) now lives on TwitchNormalizer and is
// covered by tests/Feature/Platforms/IntegrationsV4AdditionsTest.php.
