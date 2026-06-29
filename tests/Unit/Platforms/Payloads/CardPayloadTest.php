<?php

use App\Services\Platforms\Payloads\CardPayload;

it('exposes typed accessors over a branded-card payload, verbatim toArray', function () {
    $raw = [
        'id' => 'order-abc', 'provider' => 'custom', 'source' => 'google-business',
        'url' => 'https://ubereats.com/store/x', 'name' => 'Acme Eats',
        'description' => null, 'favicon' => 'https://f.ico', 'logo' => 'https://l.png',
        'data' => ['pickupUrl' => 'https://u/p', 'deliveryUrl' => 'https://u/d', 'type' => 'pickup'],
    ];
    $dto = CardPayload::fromArray($raw);

    expect($dto->id())->toBe('order-abc');
    expect($dto->provider())->toBe('custom');
    expect($dto->source())->toBe('google-business');
    expect($dto->url())->toBe('https://ubereats.com/store/x');
    expect($dto->name())->toBe('Acme Eats');
    expect($dto->favicon())->toBe('https://f.ico');
    expect($dto->logo())->toBe('https://l.png');
    expect($dto->data())->toBe(['pickupUrl' => 'https://u/p', 'deliveryUrl' => 'https://u/d', 'type' => 'pickup']);
    expect($dto->toArray())->toBe($raw);            // lossless
});

it('reads a custom-links card (kind:link) and is lenient about absent keys', function () {
    $dto = CardPayload::fromArray(['kind' => 'link', 'url' => 'https://acme.test', 'name' => 'Acme']);
    expect($dto->kind())->toBe('link');
    expect($dto->url())->toBe('https://acme.test');
    expect($dto->description())->toBeNull();
    expect($dto->data())->toBe([]);
    // garbage tolerance
    expect(CardPayload::fromArray(null)->url())->toBeNull();
    expect(CardPayload::fromArray('x')->data())->toBe([]);
});
