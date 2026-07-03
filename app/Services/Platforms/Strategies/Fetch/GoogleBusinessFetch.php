<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use Illuminate\Support\Carbon;

// Re-pulls a Google Business Place Details snapshot by stored placeId. The cron is
// daily but the snapshot only needs ~weekly re-pulls (Google billing + ToS caching),
// so a detailsFetchedAt < 6 days short-circuits with no API call. Mirrors
// PlatformRefresher::googleBusinessPayload EXACTLY — note the asymmetry: a MISSING
// placeId is 'unavailable' (legacy link-paste rows lack one), not a shape error.
final readonly class GoogleBusinessFetch implements FetchStrategy
{
    public function __construct(private GoogleBusinessService $googleBusiness) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $placeId = $payload['placeId'] ?? null;
        if (! $placeId) {
            // Legacy link-paste connections legitimately lack a placeId — transient,
            // not a data-integrity error (refresher status='unavailable').
            throw new FetchUnavailableException('missing_place_id');
        }

        try {
            $fresh = isset($payload['detailsFetchedAt'])
                && Carbon::parse($payload['detailsFetchedAt'])->gt(now()->subDays(6));
        } catch (\Throwable) {
            $fresh = false;
        }
        if ($fresh) {
            return $payload;
        }

        $details = $this->googleBusiness->fetchPlaceDetails((string) $placeId, (array) ($payload['photos'] ?? []));
        if ($details === null) {
            throw new FetchUnavailableException('google_details_fetch_failed');
        }

        return [...$payload, ...$details];
    }
}
