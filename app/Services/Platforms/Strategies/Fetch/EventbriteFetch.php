<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\EventsPayload;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls an Eventbrite organiser account's events, or re-scrapes a single
// standalone event when payload.kind === 'event'. Mirrors
// PlatformRefresher::eventbritePayload + standaloneEventPayload EXACTLY — same
// kind branch, same accountPayload re-application of the user's hiddenEventIds.
final readonly class EventbriteFetch implements FetchStrategy
{
    public function __construct(private EventbriteScraper $eventbrite) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        // Standalone-event row — re-scrape the single event page; the id is
        // re-derived from the link so it stays stable across refreshes.
        if (($payload['kind'] ?? null) === 'event') {
            $link = $payload['link'] ?? null;
            if (! is_string($link) || $link === '') {
                throw new FetchShapeException('missing_key: link');
            }
            $event = $this->eventbrite->fetchSingleEvent($link);
            if ($event === null) {
                throw new FetchUnavailableException('eventbrite_event_fetch_failed');
            }

            return EventsPayload::standalonePayload($event);
        }

        // Owner switched "Auto sync latest from each organiser" off (sparse
        // display_settings; absent = ON) → freeze this account's stored events.
        // 304 semantics (not a skip in the dispatcher) so last_refreshed_at
        // still advances and the hourly cron doesn't re-select the row forever.
        // Standalone event rows (kind branch above) deliberately keep syncing —
        // sold-out/price freshness is a separate concern from pulling NEW events.
        // The manual dashboard Refresh shares this path and honours the switch too.
        if ((data_get($connection->display_settings, 'auto_sync_latest') ?? true) === false) {
            throw new FetchNotModifiedException('eventbrite');
        }

        $url = $payload['url'] ?? null;
        if (! $url) {
            throw new FetchShapeException('missing_key: url');
        }
        $result = $this->eventbrite->fetchEvents($url);
        if ($result === null) {
            throw new FetchUnavailableException('eventbrite_fetch_failed');
        }

        // accountPayload re-applies the user's per-event hides to the fresh list.
        return EventsPayload::accountPayload(
            $url,
            $result['organiser'],
            $result['events'],
            is_array($payload['hiddenEventIds'] ?? null) ? $payload['hiddenEventIds'] : [],
        );
    }
}
