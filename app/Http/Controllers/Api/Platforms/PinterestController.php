<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Resources\Platforms\PinterestConnectionResource;
use App\Services\Platforms\PinterestScraper;
use Illuminate\Http\JsonResponse;

// Pinterest — connect by profile URL or handle. The profile page's state
// JSON provides name / avatar / follower count, and the public RSS feed
// provides the latest pins for the sitepage grid.
class PinterestController extends SingleSelectionPlatformController
{
    public function __construct(private readonly PinterestScraper $scraper) {}

    protected function platform(): string
    {
        return 'pinterest';
    }

    protected function resourceClass(): string
    {
        return PinterestConnectionResource::class;
    }

    // POST /api/platforms/pinterest/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $username = $this->scraper->parseUsername($request->validated()['url']);
        if (! $username) {
            return $this->error('Enter your Pinterest profile (pinterest.com/yourname).', 422);
        }

        $profile = $this->scraper->fetchProfile($username);
        if ($profile === null) {
            return $this->error('Could not find that Pinterest profile.', 404);
        }
        $pins = $this->scraper->fetchPins($username);

        return $this->connected($user, [
            'url' => "https://www.pinterest.com/{$username}/",
            'username' => $username,
            'name' => $profile['name'],
            'image' => $profile['image'],
            'followers' => $profile['followers'],
            'latest' => $pins[0] ?? null,
            'items' => $pins,
        ]);
    }
}
