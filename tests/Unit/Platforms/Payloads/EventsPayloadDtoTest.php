<?php

use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Payloads\StandaloneEventPayload;

it('EventsAccountPayload exposes typed accessors over the account blob, verbatim toArray', function () {
    $raw = [
        'url' => 'https://eventbrite.com/o/acme',
        'organiser' => 'Acme Events',
        'next' => ['id' => 'e1'],
        'upcoming' => [['id' => 'e1', 'name' => 'Show'], ['id' => 'e2', 'name' => 'Gig']],
        'hiddenEventIds' => ['e3'],
    ];
    $dto = EventsAccountPayload::fromArray($raw);

    expect($dto->url())->toBe('https://eventbrite.com/o/acme');
    expect($dto->organiser())->toBe('Acme Events');
    expect($dto->upcoming())->toBe([['id' => 'e1', 'name' => 'Show'], ['id' => 'e2', 'name' => 'Gig']]);
    expect($dto->hiddenEventIds())->toBe(['e3']);
    expect($dto->toArray())->toBe($raw);            // lossless
});

it('EventsAccountPayload is lenient — missing keys become null / []', function () {
    $dto = EventsAccountPayload::fromArray(['url' => 'https://eventbrite.com/o/acme']);
    expect($dto->organiser())->toBeNull();
    expect($dto->upcoming())->toBe([]);
    expect($dto->hiddenEventIds())->toBe([]);
    // tolerant of garbage
    expect(EventsAccountPayload::fromArray(null)->upcoming())->toBe([]);
});

it('StandaloneEventPayload exposes id + the event minus the internal kind key', function () {
    $raw = ['kind' => 'event', 'id' => 'abc123', 'name' => 'Gig', 'startDate' => '2026-07-01T19:00:00+10:00'];
    $dto = StandaloneEventPayload::fromArray($raw);

    expect($dto->id())->toBe('abc123');
    expect($dto->event())->toBe(['id' => 'abc123', 'name' => 'Gig', 'startDate' => '2026-07-01T19:00:00+10:00']);
    expect($dto->event())->not->toHaveKey('kind');
    expect($dto->toArray())->toBe($raw);            // lossless (kind retained in storage)
});
