<?php

use App\Services\Platforms\Actors\SpotifyReleasesAdapter;

// The nifty.codes discography actor's dataset shape, as observed on the wire
// (W10 probe + session 3 re-probe with a second artist, Men I Trust).

it('splits the joined "All Cover Art URLs" string and keeps the 640px cover', function () {
    // The dataset export joins the three sizes with " || "; taken verbatim it
    // became the item's cover URL and rendered as a broken image.
    $rows = (new SpotifyReleasesAdapter)->tracks([[
        'Release ID' => '4E6XYXyzk6bHcRmmP87ruD',
        'Release Name' => 'I Hope to Be Around',
        'Release Type' => 'Single',
        'Release Date' => '2017-11-10',
        'Share URL' => 'https://open.spotify.com/album/4E6XYXyzk6bHcRmmP87ruD',
        'All Cover Art URLs' => 'https://i.scdn.co/image/ab67616d00001e02aaaa || https://i.scdn.co/image/ab67616d0000b273aaaa || https://i.scdn.co/image/ab67616d00004851aaaa',
    ]]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['artwork'])->toBe('https://i.scdn.co/image/ab67616d0000b273aaaa')
        ->and($rows[0]['format'])->toBe('single')
        ->and($rows[0]['published'])->toBe('2017-11-10');
});

it('still accepts a list of cover objects, and rewrites a lone 300px url to the 640px variant', function () {
    $rows = (new SpotifyReleasesAdapter)->tracks([
        ['Release ID' => 'a', 'Release Name' => 'Listed', 'Release Type' => 'Album', 'All Cover Art URLs' => [
            ['url' => 'https://i.scdn.co/image/ab67616d00004851bbbb', 'width' => 64],
            ['url' => 'https://i.scdn.co/image/ab67616d0000b273bbbb', 'width' => 640],
        ]],
        ['Release ID' => 'b', 'Release Name' => 'Small', 'Release Type' => 'EP', 'All Cover Art URLs' => 'https://i.scdn.co/image/ab67616d00001e02cccc'],
        ['Release ID' => 'c', 'Release Name' => 'Bare', 'Release Type' => 'Compilation'],
    ]);

    $byId = collect($rows)->keyBy('external_id');
    expect($byId['a']['artwork'])->toBe('https://i.scdn.co/image/ab67616d0000b273bbbb')
        ->and($byId['b']['artwork'])->toBe('https://i.scdn.co/image/ab67616d0000b273cccc')
        ->and($byId['b']['format'])->toBe('ep')
        ->and($byId['c']['artwork'])->toBeNull()
        ->and($byId['c']['format'])->toBe('compilation');
});
