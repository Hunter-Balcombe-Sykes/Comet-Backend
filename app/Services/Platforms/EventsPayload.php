<?php

namespace App\Services\Platforms;

/**
 * Shared shaping for events-platform payloads (Eventbrite + Humanitix).
 *
 * Two row kinds live under each events platform:
 *  - account rows: {url, organiser, next, upcoming, hiddenEventIds} — an
 *    organiser/host whose upcoming events auto-refresh daily;
 *  - standalone event rows (resource_id 'event-<id>'): {kind:'event', id,
 *    ...event} — a single event the user added by URL, possibly from an
 *    organiser they never connected.
 *
 * Events carry a stable `id` derived from their canonical link so the
 * dashboard can hide/remove a single event and the hide survives refreshes
 * (the re-scrape re-derives the same id). `hiddenEventIds` is dashboard-only
 * state: hidden events are pruned from next/upcoming at every write, so the
 * public wire never sees them and never needs to know about hiding.
 */
class EventsPayload
{
    /** Stable per-event id from the canonical event link. */
    public static function id(string $link): string
    {
        return substr(sha1(strtolower(trim($link))), 0, 16);
    }

    /**
     * Ensure every event in the list carries its derived id.
     *
     * @param  list<array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    public static function withIds(array $events): array
    {
        return array_values(array_map(function (array $event): array {
            $link = is_string($event['link'] ?? null) ? $event['link'] : '';
            $event['id'] ??= $link !== '' ? self::id($link) : substr(sha1(json_encode($event)), 0, 16);

            return $event;
        }, $events));
    }

    /**
     * Build an account-row payload: ids stamped, hidden events pruned,
     * next recomputed from what survives. hiddenEventIds is persisted so a
     * daily refresh can re-apply the same prune.
     *
     * @param  list<array<string,mixed>>  $events
     * @param  list<string>  $hiddenEventIds
     * @return array<string,mixed>
     */
    public static function accountPayload(string $url, ?string $organiser, array $events, array $hiddenEventIds = []): array
    {
        $hidden = array_values(array_unique(array_filter($hiddenEventIds, 'is_string')));
        $upcoming = array_values(array_filter(
            self::withIds($events),
            fn (array $e) => ! in_array($e['id'], $hidden, true),
        ));

        return [
            'url' => $url,
            'organiser' => $organiser,
            'next' => $upcoming[0] ?? null,
            'upcoming' => $upcoming,
            'hiddenEventIds' => $hidden,
        ];
    }

    /**
     * Build a standalone-event row payload (flat event + kind marker).
     *
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public static function standalonePayload(array $event): array
    {
        [$withId] = self::withIds([$event]);

        return ['kind' => 'event', ...$withId];
    }
}
