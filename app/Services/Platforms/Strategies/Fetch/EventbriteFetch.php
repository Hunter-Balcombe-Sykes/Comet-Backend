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
