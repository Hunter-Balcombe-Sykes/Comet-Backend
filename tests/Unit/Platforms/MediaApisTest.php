<?php

use App\Services\Platforms\DeezerApi;
use App\Services\Platforms\MixcloudApi;
use App\Services\Platforms\TwitchScraper;
use App\Services\Platforms\VimeoApi;
use App\Services\SmartLinks\SafeUrlFetcher;

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

// ── Mixcloud ─────────────────────────────────────────────────────────────────

it('mixcloud parses profile URLs and bare handles, rejecting reserved routes', function () {
    $api = new MixcloudApi(mediaFetcherWith([]));

    expect($api->parseUsername('https://www.mixcloud.com/NTSRadio/'))->toBe('NTSRadio');
    expect($api->parseUsername('https://mixcloud.com/NTSRadio/shows/'))->toBe('NTSRadio');
    expect($api->parseUsername('@NTSRadio'))->toBe('NTSRadio');
    expect($api->parseUsername('https://www.mixcloud.com/discover/'))->toBeNull();
    expect($api->parseUsername('not a handle!'))->toBeNull();
});

it('mixcloud maps cloudcasts with widget embed URLs', function () {
    $api = new MixcloudApi(mediaFetcherWith([
        '/NTSRadio/cloudcasts/' => ['status' => 200, 'body' => json_encode(['data' => [
            ['key' => '/NTSRadio/show-1/', 'name' => 'Show 1', 'url' => 'https://www.mixcloud.com/NTSRadio/show-1/',
                'pictures' => ['large' => 'https://thumb.mixcloud.com/1.jpg'], 'created_time' => '2026-06-09T00:00:00Z'],
        ]]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]));

    $shows = $api->fetchCloudcasts('NTSRadio');
    expect($shows[0]['itemId'])->toBe('/NTSRadio/show-1/');
    expect($shows[0]['embedUrl'])->toBe('https://player-widget.mixcloud.com/?hide_cover=1&feed='.rawurlencode('/NTSRadio/show-1/'));
});

// ── Deezer ───────────────────────────────────────────────────────────────────

it('deezer parses artist links and surfaces API error objects as null', function () {
    $ok = new DeezerApi(mediaFetcherWith([
        'api.deezer.com/artist/134790' => ['status' => 200, 'body' => json_encode([
            'id' => 134790, 'name' => 'Tame Impala', 'link' => 'https://www.deezer.com/artist/134790',
            'picture_big' => 'https://cdn.dzcdn.net/big.jpg', 'nb_fan' => 1000,
        ]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]));

    expect($ok->parseArtistId('https://www.deezer.com/en/artist/134790?deferredFl=1'))->toBe('134790');
    expect($ok->parseArtistId('https://deezer.com/artist/134790'))->toBe('134790');
    expect($ok->parseArtistId('https://www.deezer.com/album/1'))->toBeNull();

    $artist = $ok->fetchArtist('134790');
    expect($artist['name'])->toBe('Tame Impala');
    expect(DeezerApi::embedUrlForArtist('134790'))->toBe('https://widget.deezer.com/widget/auto/artist/134790/top_tracks');

    // Deezer answers 200 with {"error": …} for unknown ids.
    $err = new DeezerApi(mediaFetcherWith([
        'api.deezer.com/artist/0' => ['status' => 200, 'body' => '{"error":{"type":"DataException"}}', 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]));
    expect($err->fetchArtist('0'))->toBeNull();
});

// ── Twitch ───────────────────────────────────────────────────────────────────

it('twitch parses channel inputs and og-scrapes the channel card', function () {
    $scraper = new TwitchScraper(mediaFetcherWith([
        'twitch.tv/loserfruit' => ['status' => 200, 'body' => '<meta property="og:title" content="Loserfruit - Twitch"/>'
            .'<meta property="og:description" content="Streams."/>'
            .'<meta property="og:image" content="https://static-cdn.jtvnw.net/avatar.png"/>', 'finalUrl' => 'x', 'contentType' => 'text/html'],
    ]));

    expect($scraper->parseLogin('https://www.twitch.tv/Loserfruit/videos'))->toBe('loserfruit');
    expect($scraper->parseLogin('@loserfruit'))->toBe('loserfruit');
    expect($scraper->parseLogin('https://www.twitch.tv/directory'))->toBeNull();

    $channel = $scraper->fetchChannel('loserfruit');
    expect($channel)->toMatchArray([
        'login' => 'loserfruit',
        'name' => 'Loserfruit',
        'image' => 'https://static-cdn.jtvnw.net/avatar.png',
        'description' => 'Streams.',
    ]);
});
