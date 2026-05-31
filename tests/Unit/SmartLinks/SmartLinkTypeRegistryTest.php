<?php

use App\Services\SmartLinks\SmartLinkTypeRegistry;
use App\Services\SmartLinks\UrlNormalizer;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function resolveType(string $url, string $selection): ?string
{
    $parsed = (new UrlNormalizer)->parse($url);

    return app(SmartLinkTypeRegistry::class)->resolveType($parsed, $selection);
}

it('maps commerce selections to fixed types', function () {
    expect(resolveType('https://shop.com/products/x', 'product'))->toBe('commerce.product')
        ->and(resolveType('https://shop.com/collections/x', 'collection'))->toBe('commerce.collection')
        ->and(resolveType('https://shop.com', 'brand'))->toBe('commerce.brand')
        ->and(resolveType('https://eventbrite.com/e/x-123', 'event'))->toBe('commerce.event');
});

it('detects Spotify sub-types from the path', function () {
    expect(resolveType('https://open.spotify.com/track/abc', 'spotify'))->toBe('content.music.track')
        ->and(resolveType('https://open.spotify.com/album/abc', 'spotify'))->toBe('content.music.album')
        ->and(resolveType('https://open.spotify.com/playlist/abc', 'spotify'))->toBe('content.music.playlist');
});

it('detects Apple Music album vs track via ?i=', function () {
    expect(resolveType('https://music.apple.com/us/album/x/123', 'apple_music'))->toBe('content.music.album')
        ->and(resolveType('https://music.apple.com/us/album/x/123?i=456', 'apple_music'))->toBe('content.music.track');
});

it('rejects Apple Music playlists (cut for v1)', function () {
    expect(resolveType('https://music.apple.com/us/playlist/x/pl.123', 'apple_music'))->toBeNull();
});

it('detects YouTube video and Apple Podcasts episode', function () {
    expect(resolveType('https://www.youtube.com/watch?v=abc', 'youtube'))->toBe('content.video')
        ->and(resolveType('https://youtu.be/abc', 'youtube'))->toBe('content.video')
        ->and(resolveType('https://podcasts.apple.com/us/podcast/x/id123?i=456', 'apple_podcasts'))->toBe('content.podcast.episode');
});

it('rejects an Apple Podcasts show URL with no episode id', function () {
    expect(resolveType('https://podcasts.apple.com/us/podcast/x/id123', 'apple_podcasts'))->toBeNull();
});

it('returns null on platform/URL mismatch', function () {
    expect(resolveType('https://www.youtube.com/watch?v=abc', 'spotify'))->toBeNull()
        ->and(resolveType('https://open.spotify.com/track/abc', 'youtube'))->toBeNull();
});

it('reports family correctly', function () {
    $r = app(SmartLinkTypeRegistry::class);
    expect($r->familyOf('commerce.product'))->toBe('commerce')
        ->and($r->familyOf('content.video'))->toBe('content');
});
