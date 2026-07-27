<?php

use App\Routing\ConnectionPayload;

// These assertions exist because the showcase accounts once served
// `"payload":[]` for all 20 of their connections and every backend test still
// passed. The rows were valid; the sitepage just had nothing to render from.
// What the public wire needs is therefore pinned here, not left implicit.

it('always carries the url the sitepage renders from', function () {
    $payload = ConnectionPayload::forWrite('https://instagram.com/nasa', 'nasa', 'handle', 'showcase');

    expect($payload['url'])->toBe('https://instagram.com/nasa');
});

it('keeps provenance so a row can say where it came from', function () {
    $payload = ConnectionPayload::forWrite('https://x.com/nasa', 'nasa', 'handle', 'link_in_bio');

    expect($payload['source'])->toBe('link_in_bio');
});

it('exposes a handle as username, which is the only thing Instagram renders from', function () {
    // social-links.ts reads instagram.username and ignores url entirely, so a
    // handle surface that omits it renders an empty Instagram section.
    $payload = ConnectionPayload::forWrite('https://instagram.com/nasa', 'nasa', 'handle', 'direct');

    expect($payload['username'])->toBe('nasa');
});

it('never presents a composite identifier as a username', function () {
    // spotify.player's identifier is `artist/4gzp…` — a path, not a handle.
    // Publishing it as a username would render a broken profile label.
    $payload = ConnectionPayload::forWrite(
        'https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU',
        'artist/4gzpq5DPGxSnKTe4SA8HAU',
        'composite',
        'direct',
    );

    expect($payload)->not->toHaveKey('username');
});

it('never presents an opaque id as a username', function () {
    $payload = ConnectionPayload::forWrite('https://music.apple.com/artist/1419227', '1419227', 'id', 'direct');

    expect($payload)->not->toHaveKey('username');
});
