<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectGoogleBusinessRequest;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Services\Platforms\GoogleBusinessService;
use Illuminate\Http\JsonResponse;

// Google Business — connect via the Places picker (canonical) or a pasted
// Maps share link (legacy). Picker connects are enriched server-side with the
// Place Details snapshot (rating, reviews, hours, phone, website, …); the
// refresh cron keeps that snapshot current. Link connects stay the honest
// URL-parse subset: name + coordinates + keyless map embed.
class GoogleBusinessController extends SingleSelectionPlatformController
{
    public function __construct(private readonly GoogleBusinessService $service) {}

    protected function platform(): string
    {
        return 'google-business';
    }

    protected function resourceClass(): string
    {
        return GoogleBusinessConnectionResource::class;
    }

    // POST /api/platforms/google-business/connect
    public function connect(ConnectGoogleBusinessRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $data = $request->validated();

        // Places-picker payload (canonical): the user searched + picked their
        // own business in the dashboard. Store the canonical place deep link
        // for the "open in Maps" / directions actions.
        if (isset($data['placeId'])) {
            $selection = [
                'url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($data['name']).'&query_place_id='.rawurlencode($data['placeId']),
                'placeId' => $data['placeId'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'lat' => (float) $data['lat'],
                'lng' => (float) $data['lng'],
            ];

            // Place Details enrichment (rating, reviews, hours, phone, …) —
            // best-effort: a missing server key or failed fetch keeps the
            // picker fields and the refresh cron retries within a week.
            // mapDetails() drops absent keys, so the spread never overwrites
            // a picker value with null.
            $details = $this->service->fetchPlaceDetails($data['placeId']);
            $merged = [...$selection, ...($details ?? [])];

            // Apify enrichment (menu / reservation / order / booking / socials)
            // is too slow to block connect, so it runs in a background job while
            // we return the instant Place Details card. apifyStatus = 'pending'
            // drives the dashboard's poll; the job flips it to ok/unavailable.
            // Gated on the token so a missing key is a clean no-op.
            $enrich = (bool) config('services.apify.token');
            if ($enrich) {
                $merged['apifyStatus'] = 'pending';
            }

            $response = $this->connected($user, $merged);

            if ($enrich) {
                GoogleBusinessEnrichJob::dispatch((string) $user->id, $data['placeId']);
            }

            return $response;
        }

        $place = $this->service->resolve($data['url']);
        if ($place === null) {
            return $this->error('Paste your Google Maps link — open your business on Google Maps, hit Share, and copy the link.', 422);
        }

        return $this->connected($user, $place);
    }
}
