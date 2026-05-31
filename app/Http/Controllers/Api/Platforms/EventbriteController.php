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

    // selection() + forget() come from ManagesPlatformSelection.
}
