<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\EventsPayload;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Humanitix twin of EventbriteFetch — organiser account events or a single
// standalone event (payload.kind === 'event'). Mirrors
// PlatformRefresher::humanitixPayload + standaloneEventPayload EXACTLY.
final readonly class HumanitixFetch implements FetchStrategy
{
    public function __construct(private HumanitixScraper $humanitix) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        if (($payload['kind'] ?? null) === 'event') {
            $link = $payload['link'] ?? null;
            if (! is_string($link) || $link === '') {
                throw new FetchShapeException('missing_key: link');
            }
            $event = $this->humanitix->fetchSingleEvent($link);
            if ($event === null) {
                throw new FetchUnavailableException('humanitix_event_fetch_failed');
            }

            return EventsPayload::standalonePayload($event);
        }

        // "Auto sync latest from each organiser" off → freeze the account's
        // stored events via 304 semantics. Mirrors EventbriteFetch exactly —
        // see the rationale comment there (standalone rows keep syncing).
        if ((data_get($connection->display_settings, 'auto_sync_latest') ?? true) === false) {
            throw new FetchNotModifiedException('humanitix');
        }

        $url = $payload['url'] ?? null;
        if (! $url) {
            throw new FetchShapeException('missing_key: url');
        }
        $result = $this->humanitix->fetchEvents($url);
        if ($result === null) {
            throw new FetchUnavailableException('humanitix_fetch_failed');
        }

        return EventsPayload::accountPayload(
            $url,
            $result['organiser'],
            $result['events'],
            is_array($payload['hiddenEventIds'] ?? null) ? $payload['hiddenEventIds'] : [],
        );
    }
}
