<?php

namespace App\Services\Platforms;

use App\Services\Site\ItemSlugAllocator;

/**
 * Mints/re-slugs event item_slugs from a platform_connections payload
 * (eventbrite / humanitix / events-custom). Handles both payload shapes
 * EventsPayload produces:
 *  - account rows: {..., upcoming: [ {id, name, ...}, ... ]}
 *  - standalone/custom rows: {kind: 'event', id, name, ...}
 *
 * Event slugs are pinned once and never re-derived from a later title edit
 * (design decision — the hex id is link-derived, not title-derived, so it
 * outlives an organiser renaming their listing upstream; re-slugging on every
 * sync would cause redirect sprawl from data we don't control). ensureCurrent
 * is naturally idempotent per hex id, so calling this on every sync is safe:
 * the FIRST sync to see a given hex mints its slug, every later sync for the
 * same hex is a no-op.
 */
class EventSlugSync
{
    public function __construct(private ItemSlugAllocator $slugs) {}

    /** @param  array<string,mixed>  $payload */
    public function sync(string $userId, array $payload): void
    {
        foreach ($this->events($payload) as $event) {
            $id = $event['id'] ?? null;
            $name = $event['name'] ?? null;
            if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
                continue;
            }
            $this->slugs->ensureCurrent($userId, ItemSlugAllocator::TYPE_EVENT, $id, $name);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function events(array $payload): array
    {
        if (($payload['kind'] ?? null) === 'event') {
            return [$payload];
        }

        $upcoming = $payload['upcoming'] ?? null;

        return is_array($upcoming) ? array_values(array_filter($upcoming, 'is_array')) : [];
    }
}
