<?php

// Slice 1b D6. Google's Places policies require crediting the photographer and
// linking back to the photo on Maps; mapPhoto() collects the names today and
// everything downstream drops them. These pin the shape that carries them.
//
// mapPhoto is private, so it is driven through reflection the way pull() drives
// it. The helper below is a global in the test namespace: a second definition
// of the same name anywhere in the suite is a FATAL error, not a test failure
// (1a hit this in f4edafb6b), so the name is deliberately unique and was
// grepped for before being added.

use App\Ingest\Connectors\GoogleBusinessConnector;

/** @return array<string, mixed>|null */
function mapPhotoFixture(array $photo): ?array
{
    return (new ReflectionMethod(GoogleBusinessConnector::class, 'mapPhoto'))
        ->invoke(new GoogleBusinessConnector, $photo);
}

it('carries author name, author uri, maps uri and flag uri', function () {
    $result = mapPhotoFixture([
        'name' => 'places/ChIJtest/photos/AWCwydtoken',
        'widthPx' => 4032,
        'heightPx' => 3024,
        'authorAttributions' => [
            ['displayName' => 'Jo Rivera', 'uri' => 'https://maps.google.com/maps/contrib/1234'],
        ],
        'googleMapsUri' => 'https://maps.google.com/photo/abc',
        'flagContentUri' => 'https://maps.google.com/flag/abc',
    ]);

    expect($result['attribution'])->toBe([
        'authors' => [['name' => 'Jo Rivera', 'uri' => 'https://maps.google.com/maps/contrib/1234']],
        'maps_uri' => 'https://maps.google.com/photo/abc',
        'flag_uri' => 'https://maps.google.com/flag/abc',
    ]);
});

it('omits attribution entirely when Google supplied none', function () {
    // D6's known gap: only ~60 of 110 live photos carry authors. Absent must
    // stay absent — an empty object renders as a credit with no name in it.
    $result = mapPhotoFixture([
        'name' => 'places/ChIJtest/photos/AWCwydtoken',
        'widthPx' => 800,
        'heightPx' => 600,
    ]);

    expect($result)->not->toHaveKey('attribution');
});

it('keeps an author whose uri is missing', function () {
    $result = mapPhotoFixture([
        'name' => 'places/ChIJtest/photos/AWCwydtoken',
        'authorAttributions' => [['displayName' => 'Sam Okafor']],
    ]);

    expect($result['attribution']['authors'])->toBe([['name' => 'Sam Okafor', 'uri' => null]]);
});

it('carries the resolved url when the driver supplied one', function () {
    // Task 5 populates `url` on the raw photo entry inside the same billed
    // Details fetch. Reading it here now keeps Task 5 a driver-only change.
    $result = mapPhotoFixture([
        'name' => 'places/ChIJtest/photos/AWCwydtoken',
        'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest',
    ]);

    expect($result['url'])->toBe('https://lh3.googleusercontent.com/place-photos/AG9NLjtest');
});

it('omits url when the photo could not be resolved', function () {
    $result = mapPhotoFixture([
        'name' => 'places/ChIJtest/photos/AWCwydtoken',
    ]);

    expect($result)->not->toHaveKey('url')
        ->and($result['ref'])->toBe('places/ChIJtest/photos/AWCwydtoken');
});
