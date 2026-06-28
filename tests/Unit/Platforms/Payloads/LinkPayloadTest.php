<?php

use App\Services\Platforms\Payloads\LinkPayload;

it('round-trips a full link payload', function () {
    $stored = ['username' => 'jane.doe', 'url' => 'https://www.facebook.com/jane.doe'];

    expect(LinkPayload::fromArray($stored)->toArray())->toBe($stored);
});

it('exposes typed properties', function () {
    $payload = LinkPayload::fromArray(['username' => 'janed', 'url' => 'https://x.com/janed']);

    expect($payload->username)->toBe('janed');
    expect($payload->url)->toBe('https://x.com/janed');
});

it('hydrates leniently — missing keys become null and unknown keys are dropped', function () {
    $payload = LinkPayload::fromArray(['url' => 'https://x.com/janed', '_leak' => 'must-not-survive']);

    expect($payload->username)->toBeNull();
    expect($payload->url)->toBe('https://x.com/janed');
    expect($payload->toArray())->toBe(['username' => null, 'url' => 'https://x.com/janed']);
});

it('preserves an empty-string username (Facebook /pages/ links)', function () {
    // Facebook page links store username:'' deliberately — it must NOT collapse to null.
    $payload = LinkPayload::fromArray(['username' => '', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123']);

    expect($payload->username)->toBe('');
    expect($payload->toArray())->toBe(['username' => '', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123']);
});

it('coerces non-string scalars to null', function () {
    $payload = LinkPayload::fromArray(['username' => 123, 'url' => ['nested']]);

    expect($payload->username)->toBeNull();
    expect($payload->url)->toBeNull();
});
