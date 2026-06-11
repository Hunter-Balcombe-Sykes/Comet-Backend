<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectEventbriteRequest;
use App\Http\Resources\Platforms\EventbriteConnectionResource;
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
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(private readonly EventbriteScraper $scraper) {}

    protected function platform(): string
    {
        return 'eventbrite';
    }

    // POST /api/platforms/eventbrite/connect — store the next + upcoming events for the user.
    public function connect(ConnectEventbriteRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        $orgUrl = $this->scraper->normalizeOrgUrl($validated['url']);
        if (! $orgUrl) {
            return $this->error('Enter your Eventbrite organiser URL (eventbrite.com/o/...).', 422);
        }

        $result = $this->scraper->fetchEvents($orgUrl);
        if ($result === null) {
            return $this->error('Could not load that Eventbrite organiser.', 422);
        }

        $events = $result['events'];
        $selection = [
            'url' => $orgUrl,
            'organiser' => $result['organiser'],
            'next' => $events[0] ?? null,
            'upcoming' => $events,
        ];
        $this->writeConnection($user, $selection);

        return $this->success((new EventbriteConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/eventbrite/selection — the authenticated user's saved
    // organiser, with elapsed events dropped at READ time so a stale-but-present
    // blob self-cleans without a re-scrape (endDate keeps in-progress events).
    // ISO-8601 string compare matches EventbriteScraper::fetchEvents.
    public function selection(Request $request): JsonResponse
    {
        $selection = $this->readConnection($this->currentUser($request));
        if (! is_array($selection)) {
            return $this->success(['selection' => null]);
        }

        return $this->success([
            'selection' => (new EventbriteConnectionResource($this->filterPastEvents($selection)))->resolve(),
        ]);
    }

    // DELETE /api/platforms/eventbrite — clear the authenticated user's connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
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
