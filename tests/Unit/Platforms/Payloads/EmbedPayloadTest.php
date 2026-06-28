<?php

use App\Services\Platforms\Payloads\EmbedPayload;

it('round-trips a full deezer-shaped payload (including the internal artistId)', function () {
    $stored = [
        'url' => 'https://www.deezer.com/artist/123',
        'name' => 'Artist',
        'thumbnail' => 'https://e-cdn.deezer.com/t.jpg',
        'embedUrl' => 'https://widget.deezer.com/widget/dark/artist/123',
        'link' => 'https://www.deezer.com/artist/123',
        'artistId' => '123',
    ];

    expect(EmbedPayload::fromArray($stored)->toArray())->toBe($stored);
});

it('exposes typed properties', function () {
    $p = EmbedPayload::fromArray(['url' => 'https://open.spotify.com/artist/abc', 'embedUrl' => 'https://open.spotify.com/embed/artist/abc']);

    expect($p->url)->toBe('https://open.spotify.com/artist/abc');
    expect($p->embedUrl)->toBe('https://open.spotify.com/embed/artist/abc');
    expect($p->artistId)->toBeNull();
});

it('hydrates leniently — missing keys become null, unknown keys are dropped', function () {
    $p = EmbedPayload::fromArray(['url' => 'https://soundcloud.com/x', '_leak' => 'must-not-survive']);

    expect($p->name)->toBeNull();
    expect($p->toArray())->toBe([
        'url' => 'https://soundcloud.com/x', 'name' => null, 'thumbnail' => null,
        'embedUrl' => null, 'link' => null, 'artistId' => null,
    ]);
    expect($p->toArray())->not->toHaveKey('_leak');
});

it('coerces non-string scalars to null', function () {
    $p = EmbedPayload::fromArray(['artistId' => 123, 'name' => ['nested']]);

    expect($p->artistId)->toBeNull();
    expect($p->name)->toBeNull();
});
