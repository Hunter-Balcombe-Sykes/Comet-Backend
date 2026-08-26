<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Controllers\Api\Platforms\GoogleBusinessController;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch;

/**
 * PD-retirement P5 (2026-08-27): Google Business's full behavioural
 * contract, moved VERBATIM from the retired hand-written registration.
 * Connect is irreducible (multi-field ConnectGoogleBusinessRequest) — no
 * connectInput; routes are the SingleSelection archetype on the bespoke
 * GoogleBusinessController (connect/selection/forget).
 *
 * GoogleBusinessPayload is verbatim-preserving (variable key set via
 * array_intersect_key) — read paths migrated in Plan 5 of the uniformity
 * work; NOT FeedPayload.
 *
 * NOTE: the google_business catalog surface is notConnectable() (its
 * connect is never a pasted URL), so this slug derives through the
 * BEHAVIOUR_BINDINGS relaxation in candidates() — the binding's existence
 * IS the declaration that the platform must exist in the registry.
 */
final class GoogleBusinessBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Business)
            ->resource(GoogleBusinessConnectionResource::class)
            ->payload(GoogleBusinessPayload::class)
            ->refreshable()
            // Fetch keeps a 40h internal freshness gate so ratings stay ≤2 days
            // stale while respecting Google's caching guidance.
            ->fetch(fn () => new GoogleBusinessFetch(app(GoogleBusinessService::class)))
            ->displayToggles([
                ['key' => 'reviews', 'label' => 'Reviews', 'description' => 'Your Google rating and recent reviews.'],
                ['key' => 'hours', 'label' => 'Opening hours', 'description' => 'Your weekly opening hours.'],
                ['key' => 'photos', 'label' => 'Photos', 'description' => 'Photos from your Google Business profile.'],
                ['key' => 'location', 'label' => 'Location & map', 'description' => 'Your address, map and directions.'],
                ['key' => 'menu', 'label' => 'Menu', 'description' => 'Your food and drink menu.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.google-business', 2 * 86400))
            ->routes(PlatformRouteShape::SingleSelection, GoogleBusinessController::class);
    }
}
