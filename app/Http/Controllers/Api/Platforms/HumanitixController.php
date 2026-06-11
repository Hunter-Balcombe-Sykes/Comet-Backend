<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectHumanitixRequest;
use App\Http\Resources\Platforms\HumanitixConnectionResource;
use App\Services\Platforms\HumanitixScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for the Humanitix "Tickets" integration — the Eventbrite
// twin. Takes a host page (or any event page — the host is resolved from it),
// scrapes the host's upcoming events via schema.org Event JSON-LD (no auth),
// and stores the next event + up to 5 upcoming. No selections. Scraping lives
// in App\Services\Platforms\HumanitixScraper.
class HumanitixController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(private readonly HumanitixScraper $scraper) {}

    protected function platform(): string
    {
        return 'humanitix';
    }

    // POST /api/platforms/humanitix/connect — store the next + upcoming events.
    public function connect(ConnectHumanitixRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        $hostUrl = $this->scraper->resolveHostUrl($validated['url']);
        if (! $hostUrl) {
            return $this->error('Enter your Humanitix host page or one of your event URLs (events.humanitix.com/...).', 422);
        }

        $result = $this->scraper->fetchEvents($hostUrl);
        if ($result === null) {
            return $this->error('Could not load that Humanitix host.', 422);
        }

        $events = $result['events'];
        $selection = [
            'url' => $hostUrl,
            'organiser' => $result['organiser'],
            'next' => $events[0] ?? null,
            'upcoming' => $events,
        ];
        $this->writeConnection($user, $selection);

        return $this->success((new HumanitixConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/humanitix/selection — saved host, with elapsed events
    // dropped at READ time so a stale blob self-cleans without a re-scrape.
    public function selection(Request $request): JsonResponse
    {
        $selection = $this->readConnection($this->currentUser($request));
        if (! is_array($selection)) {
            return $this->success(['selection' => null]);
        }

        return $this->success([
            'selection' => (new HumanitixConnectionResource($this->filterPastEvents($selection)))->resolve(),
        ]);
    }

    // DELETE /api/platforms/humanitix — clear the connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // Keep only events whose end (or start, when no end is known) is still in
    // the future, and recompute `next` from what remains — same READ-time
    // self-clean as EventbriteController. ISO-8601 string compare matches the
    // scrape-time filter.
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
