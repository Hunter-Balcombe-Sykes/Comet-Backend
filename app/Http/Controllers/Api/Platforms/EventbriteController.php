<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesPlatformSelection;
use App\Services\Platforms\EventbriteScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for the Eventbrite "Tickets" integration. Takes an
// organiser URL, scrapes the organiser's upcoming events (no auth), and stores
// the next event + the next up-to-5 upcoming events. No selections. Scraping
// lives in App\Services\Platforms\EventbriteScraper.
// Spec: ~/Developer/platform link capabilites/eventbrite.md
class EventbriteController extends ApiController
{
    use ManagesPlatformSelection;

    private const SELECTION_KEY = 'platforms.eventbrite.selection';

    public function __construct(private readonly EventbriteScraper $scraper) {}

    protected function selectionKey(): string
    {
        return self::SELECTION_KEY;
    }

    // POST /api/platforms/eventbrite/connect — store the next + upcoming events.
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate(['url' => ['required', 'string', 'max:500']]);

        $orgUrl = $this->scraper->normalizeOrgUrl($validated['url']);
        if (! $orgUrl) {
            return $this->error('Enter your Eventbrite organiser URL (eventbrite.com/o/...).', 422);
        }

        $result = $this->scraper->fetchEvents($orgUrl);
        if ($result === null) {
            return $this->error('Could not load that Eventbrite organiser.', 502);
        }

        $events = $result['events'];
        $selection = [
            'url' => $orgUrl,
            'organiser' => $result['organiser'],
            'next' => $events[0] ?? null,
            'upcoming' => $events,
        ];
        $this->writeSelection($selection);

        return $this->success($selection);
    }

    // GET /api/platforms/eventbrite/selection — overrides the trait default to
    // drop events that have since elapsed, at READ time. The organiser blob is
    // scraped once and lives for the cache TTL; without this, events that were
    // upcoming at scrape time keep showing after they're over. Filtering on read
    // (rather than re-scraping) means a stale-but-present blob self-cleans on
    // every read. forget() still comes from ManagesPlatformSelection.
    public function selection(): JsonResponse
    {
        $selection = $this->readSelection();
        if (! is_array($selection)) {
            return $this->success(['selection' => null]);
        }

        return $this->success(['selection' => $this->filterPastEvents($selection)]);
    }

    // Keep only events whose end (or start, when no end is known) is still in
    // the future, and recompute `next` from what remains. Using endDate means an
    // in-progress event (started, not yet ended) still shows. ISO-8601 string
    // compare matches the scrape-time filter in EventbriteScraper::fetchEvents.
    private function filterPastEvents(array $selection): array
    {
        $now = now()->toIso8601String();

        $upcoming = array_values(array_filter(
            $selection['upcoming'] ?? [],
            function (array $e) use ($now) {
                $end = $e['endDate'] ?? $e['startDate'] ?? null;

                return $end === null || $end >= $now;
            },
        ));

        $selection['upcoming'] = $upcoming;
        $selection['next'] = $upcoming[0] ?? null;

        return $selection;
    }
}
