<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectQuandooRequest;
use App\Http\Resources\Platforms\QuandooConnectionResource;
use App\Services\Platforms\QuandooScraper;
use Illuminate\Http\JsonResponse;

// Quandoo — connect by restaurant page URL. The page's Restaurant JSON-LD
// provides name, photo, rating (out of 6), review count, cuisines, and
// address; the reserve action deep-links to the listing.
class QuandooController extends SingleSelectionPlatformController
{
    public function __construct(private readonly QuandooScraper $scraper) {}

    protected function platform(): string
    {
        return 'quandoo';
    }

    protected function resourceClass(): string
    {
        return QuandooConnectionResource::class;
    }

    // POST /api/platforms/quandoo/connect
    public function connect(ConnectQuandooRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $url = $this->scraper->normalizeUrl($request->validated()['url']);
        if (! $url) {
            return $this->error('Enter your Quandoo restaurant page URL (quandoo.com.au/place/...).', 422);
        }

        $restaurant = $this->scraper->fetchRestaurant($url);
        if ($restaurant === null) {
            return $this->error('Could not read that Quandoo page.', 404);
        }

        return $this->connected($user, ['url' => $url, ...$restaurant]);
    }
}
