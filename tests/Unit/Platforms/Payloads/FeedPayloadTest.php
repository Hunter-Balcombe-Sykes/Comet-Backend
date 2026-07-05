<?php

use App\Http\Resources\Platforms\AppleMusicConnectionResource;
use App\Http\Resources\Platforms\ApplePodcastConnectionResource;
use App\Http\Resources\Platforms\BandcampConnectionResource;
use App\Http\Resources\Platforms\PinterestConnectionResource;
use App\Http\Resources\Platforms\TwitchConnectionResource;
use App\Http\Resources\Platforms\VimeoConnectionResource;
use App\Http\Resources\Platforms\YoutubeConnectionResource;
use App\Http\Resources\Platforms\YoutubeMusicConnectionResource;
use App\Services\Platforms\Payloads\FeedPayload;
use Tests\TestCase;

// ->resolve() on JsonResource injects Request via the container — needs full app.
uses(TestCase::class)->in(__FILE__);

it('exposes typed properties incl. internal channelId/apiPath and array tiles', function () {
    $p = FeedPayload::fromArray([
        'channelId' => 'UC123', 'apiPath' => 'patagonia',
        'latest' => ['videoId' => 'v1'], 'items' => [['id' => 1]], 'highlights' => [],
        'followers' => 4200,
    ]);

    expect($p->channelId)->toBe('UC123');
    expect($p->apiPath)->toBe('patagonia');
    expect($p->latest)->toBe(['videoId' => 'v1']);
    expect($p->items)->toBe([['id' => 1]]);
    expect($p->highlights)->toBe([]);
    expect($p->followers)->toBe(4200);
});

it('hydrates leniently — missing keys null, unknown keys dropped, non-array tiles null', function () {
    $p = FeedPayload::fromArray(['handle' => 'mychannel', 'latest' => 'not-an-array', '_leak' => 'x']);

    expect($p->handle)->toBe('mychannel');
    expect($p->name)->toBeNull();
    expect($p->latest)->toBeNull();
    expect($p->toArray())->not->toHaveKey('_leak');
    expect(array_keys($p->toArray()))->toBe([
        'handle', 'url', 'channelId', 'apiPath', 'input', 'login', 'username', 'artist',
        'name', 'description', 'link', 'thumbnail', 'image', 'releaseDate', 'location',
        'followers', 'members', 'latest', 'items', 'highlights',
    ]);
});

// Strava's location/members keys (FOUND-24 Task 4) — round-trip through the DTO
// exactly like every other feed field: present values pass through typed,
// absent values null out.
it('round-trips strava location/members through fromArray/toArray', function () {
    $p = FeedPayload::fromArray(['location' => 'Melbourne, Australia', 'members' => 142]);

    expect($p->location)->toBe('Melbourne, Australia');
    expect($p->members)->toBe(142);
    expect($p->toArray())->toMatchArray(['location' => 'Melbourne, Australia', 'members' => 142]);
});

it('defaults location/members to null when absent', function () {
    $p = FeedPayload::fromArray(['name' => 'Fade Lab Running Club']);

    expect($p->location)->toBeNull();
    expect($p->members)->toBeNull();
});

// Resource-output equivalence: feeding the DTO-normalized array to each feed
// resource yields the SAME JSON as feeding the raw stored payload. This is the
// contract-freeze property (the golden master proves it again at the HTTP layer).
dataset('feed_payloads', [
    'youtube' => [YoutubeConnectionResource::class, [
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'], 'highlights' => [],
    ]],
    'youtube-music' => [YoutubeMusicConnectionResource::class, [
        'url' => 'https://music.youtube.com/channel/UC', 'channelId' => 'UC', 'name' => 'Artist',
        'thumbnail' => 't', 'link' => 'https://music.youtube.com/channel/UC',
        'latest' => ['itemId' => 'i1'], 'items' => [['itemId' => 'i1']], 'highlights' => [],
    ]],
    'vimeo' => [VimeoConnectionResource::class, [
        'url' => 'https://vimeo.com/x', 'apiPath' => 'x', 'name' => 'Pat', 'thumbnail' => 't',
        'link' => 'https://vimeo.com/x', 'latest' => ['id' => 1], 'items' => [['id' => 1]], 'highlights' => [],
    ]],
    'bandcamp' => [BandcampConnectionResource::class, [
        'url' => 'https://x.bandcamp.com', 'artist' => 'X', 'name' => 'Album', 'thumbnail' => 't',
        'link' => 'l', 'latest' => ['id' => 1], 'highlights' => [],
    ]],
    'twitch' => [TwitchConnectionResource::class, [
        'url' => 'https://www.twitch.tv/x', 'login' => 'x', 'name' => 'X', 'image' => 'i', 'description' => 'bio',
    ]],
    'pinterest' => [PinterestConnectionResource::class, [
        'url' => 'https://www.pinterest.com/x/', 'username' => 'x', 'name' => 'X', 'image' => 'i',
        'followers' => 4200, 'latest' => ['id' => 1], 'items' => [['id' => 1]],
    ]],
    'apple-music' => [AppleMusicConnectionResource::class, [
        'input' => 'in', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l',
        'latest' => ['id' => 1], 'highlights' => [],
    ]],
    // apple-podcast is the ONLY feed resource emitting description AND releaseDate in
    // its flat fields — it must be in the dataset so the union-completeness guard
    // exercises that key combination.
    'apple-podcast' => [ApplePodcastConnectionResource::class, [
        'input' => 'in', 'name' => 'Show', 'thumbnail' => 't', 'description' => 'desc', 'releaseDate' => '2026-01-01',
        'link' => 'l', 'latest' => ['id' => 1], 'highlights' => [],
    ]],
]);

it('is resource-output-equivalent to the raw payload', function (string $resourceClass, array $stored) {
    $viaDto = (new $resourceClass(FeedPayload::fromArray($stored)->toArray()))->resolve();
    $viaRaw = (new $resourceClass($stored))->resolve();

    expect($viaDto)->toEqual($viaRaw);
})->with('feed_payloads');
