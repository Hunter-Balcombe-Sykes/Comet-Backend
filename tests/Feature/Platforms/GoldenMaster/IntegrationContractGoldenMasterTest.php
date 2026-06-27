<?php

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// Link-only socials: selection wraps {username,url} and strips unknown keys.
// The _leak key is seeded into the payload to assert the resource strips it.
dataset('link_only', [
    'tiktok' => ['tiktok', ['username' => 'dancer', 'url' => 'https://www.tiktok.com/@dancer']],
    'facebook' => ['facebook', ['username' => 'jane.doe', 'url' => 'https://www.facebook.com/jane.doe']],
    'x' => ['x', ['username' => 'janed', 'url' => 'https://x.com/janed']],
    'linkedin' => ['linkedin', ['username' => 'jane-doe', 'url' => 'https://www.linkedin.com/in/jane-doe/']],
    'threads' => ['threads', ['username' => 'janed', 'url' => 'https://www.threads.net/@janed']],
    'reddit' => ['reddit', ['username' => 'janed', 'url' => 'https://www.reddit.com/user/janed/']],
    // Skool renders via SkoolConnectionResource (not LinkConnectionResource), which emits
    // {url, name, image, description}. image and description default to null when absent.
    'skool' => ['skool', ['url' => 'https://www.skool.com/community', 'name' => 'Community', 'image' => null, 'description' => null]],
]);

it('freezes link-only selection contract', function (string $platform, array $stored) {
    $user = gmUser("gm{$platform}");
    // Seed _leak alongside the stored payload — the resource must strip it.
    gmSeed($user, $platform, [...$stored, '_leak' => 'must-not-appear']);

    $selection = actingAsUser($user)->getJson("/api/platforms/{$platform}/selection")
        ->assertOk()
        ->json('selection');

    expect($selection)->toEqual($stored);
})->with('link_only');

// oEmbed music platforms (Spotify / SoundCloud / Deezer) render via
// MusicEmbedConnectionResource, which emits exactly {url, name, thumbnail, embedUrl, link}.
// The _leak key must not appear in the response.
dataset('oembed', [
    'spotify' => ['spotify', [
        'url' => 'https://open.spotify.com/artist/abc', 'name' => 'Artist',
        'thumbnail' => 'https://i.scdn.co/t.jpg', 'embedUrl' => 'https://open.spotify.com/embed/artist/abc',
        'link' => 'https://open.spotify.com/artist/abc',
    ]],
    'soundcloud' => ['soundcloud', [
        'url' => 'https://soundcloud.com/artist', 'name' => 'Artist',
        'thumbnail' => 'https://i1.sndcdn.com/t.jpg', 'embedUrl' => 'https://w.soundcloud.com/player/?url=x',
        'link' => 'https://soundcloud.com/artist',
    ]],
    'deezer' => ['deezer', [
        'url' => 'https://www.deezer.com/artist/123', 'name' => 'Artist',
        'thumbnail' => 'https://e-cdn.deezer.com/t.jpg', 'embedUrl' => 'https://widget.deezer.com/widget/dark/artist/123',
        'link' => 'https://www.deezer.com/artist/123',
    ]],
]);

it('freezes oembed selection contract', function (string $platform, array $stored) {
    $user = gmUser("gm{$platform}");
    // Seed _leak alongside stored payload — MusicEmbedConnectionResource must strip it.
    gmSeed($user, $platform, [...$stored, '_leak' => 'must-not-appear']);

    $selection = actingAsUser($user)->getJson("/api/platforms/{$platform}/selection")
        ->assertOk()
        ->json('selection');

    // Snapshot: _leak is gone and the five canonical keys round-trip exactly.
    expect($selection)->not->toHaveKey('_leak');
    expect($selection['url'])->toBe($stored['url']);
    expect($selection['embedUrl'])->toBe($stored['embedUrl']);
})->with('oembed');
