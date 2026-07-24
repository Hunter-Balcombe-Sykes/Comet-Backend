<?php

namespace App\Services\Platforms;

use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
use App\Services\Site\ItemSlugAllocator;

/**
 * Mints/reuses event item_slugs from an already-extracted list of event
 * arrays (each {id, name, ...}). The caller pulls that list out of a
 * connection's typed payload DTO first (EventsAccountPayload::upcoming() or
 * StandaloneEventPayload::event()) — this class stays Eloquent- and
 * payload-shape-free so it's trivial to unit test and never touches a raw
 * ->payload access itself.
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
    // The events-platform set. Platform enum has no cases for these (by
    // design — see Platform.php); mirrors CloudflarePurgeService's own
    // string list. Single source of truth for both the sync hook
    // (IntegrationConnectionObserver) and the backfill command.
    public const PLATFORMS = ['eventbrite', 'humanitix', 'events-custom'];

    public function __construct(private ItemSlugAllocator $slugs) {}

    /**
     * Extract the list of event arrays ({id, name, ...}) from a stored
     * connection payload, given its resource_kind — 'event' for a
     * standalone/custom row (the payload itself, minus `kind`), anything
     * else for an account row (its `upcoming` list). The one place this
     * branch is decided; mirrors EventsPlatformController's own
     * resource_kind check (EventsPlatformController.php:318). DTO-mediated
     * (never raw ->payload access) so every caller — the sync hook, the
     * backfill command, the public API controller — stays off
     * NoUntypedPayloadAccessTest's radar automatically.
     *
     * @return list<mixed>
     */
    public static function extractEvents(?string $resourceKind, mixed $payload): array
    {
        return $resourceKind === 'event'
            ? [StandaloneEventPayload::fromArray($payload)->event()]
            : EventsAccountPayload::fromArray($payload)->upcoming();
    }

    /**
     * The event ids a stored payload carries, deduped and in payload order —
     * the retirement side's counterpart to extractEvents(). Static + pure so it
     * unit-tests without a container and works on ANY payload (the pre-write
     * original as well as the live one).
     *
     * Ids are kept as strings deliberately: an all-digit hex id would come back
     * as an int through an array-key dedupe, which then leaks an int into a
     * `list<string>` and into forgetMany()'s binds.
     *
     * @return list<string>
     */
    public static function eventIds(?string $resourceKind, mixed $payload): array
    {
        $ids = [];
        foreach (self::extractEvents($resourceKind, $payload) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $id = $event['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Free the slugs of events that have dropped out of a profile — a refresh
     * that no longer lists them, or a disconnected connection. Hard-deletes
     * (forgetMany), because item_slugs_unique_slug is NON-partial: a merely
     * retired row would keep squatting the slug so a same-named future event
     * would land on `-2` forever.
     *
     * The CALLER owns the "is this id really gone?" decision — event ids are
     * link-derived, so the same event can legitimately appear under several of
     * a user's connections and item_slugs has no connection column. See
     * IntegrationConnectionObserver::retireVanishedEventSlugs().
     *
     * Best-effort: a slug-bookkeeping failure must never break the connection
     * write that triggered it.
     *
     * @param  list<string>  $eventIds
     */
    public function retireEvents(string $userId, array $eventIds): void
    {
        $ids = array_values(array_filter($eventIds, fn ($id) => $id !== ''));
        if ($ids === []) {
            return;
        }

        try {
            $this->slugs->forgetMany($userId, ItemSlugAllocator::TYPE_EVENT, $ids);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param list<mixed> $events */
    public function syncEvents(string $userId, array $events): void
    {
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $id = $event['id'] ?? null;
            $name = $event['name'] ?? null;
            if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
                continue;
            }
            $this->slugs->ensureCurrent($userId, ItemSlugAllocator::TYPE_EVENT, $id, $name);
        }
    }
}
