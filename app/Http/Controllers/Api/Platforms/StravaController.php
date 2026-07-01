<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Resources\Platforms\StravaConnectionResource;
use App\Services\Platforms\StravaClubScraper;
use Illuminate\Http\JsonResponse;

// Strava — connect by club URL (athlete profiles are login-walled; clubs are
// the public surface). The club page provides name, location, photo, and
// live member count; the join action deep-links to the club.
class StravaController extends SingleSelectionPlatformController
{
    public function __construct(private readonly StravaClubScraper $scraper) {}

    protected function platform(): string
    {
        return 'strava';
    }

    protected function resourceClass(): string
    {
        return StravaConnectionResource::class;
    }

    // POST /api/platforms/strava/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $url = $this->scraper->normalizeUrl($request->validated()['url']);
        if (! $url) {
            return $this->error('Enter your Strava club URL (strava.com/clubs/yourclub).', 422);
        }

        $club = $this->scraper->fetchClub($url);
        if ($club === null) {
            return $this->error('Could not read that Strava club page.', 404);
        }

        return $this->connected($user, ['url' => $url, ...$club]);
    }
}
