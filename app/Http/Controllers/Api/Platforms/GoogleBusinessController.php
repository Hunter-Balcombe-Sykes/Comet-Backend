<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectGoogleBusinessRequest;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\GoogleBusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    // GET /api/platforms/google-business/synced
    // The connections this user's Google Business connect auto-created
    // (payload.source === 'google-business') — reservation, ordering links and
    // social profiles — for the connect modal's "Automatically Synced
    // Integrations" step, each with a remove target for one-click undo.
    public function synced(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $rows = $user->integrationConnections()
            ->whereIn('platform', ['opentable', 'reservations', 'online-ordering', 'instagram', 'facebook', 'tiktok', 'x', 'linkedin'])
            ->orderBy('platform')
            ->orderBy('created_at')
            ->get();

        $synced = $rows
            ->filter(fn (IntegrationConnection $row) => data_get($row->payload, 'source') === 'google-business')
            ->map(fn (IntegrationConnection $row) => $this->shapeSynced($row))
            ->values()
            ->all();

        return $this->success(['synced' => $synced]);
    }

    /**
     * One auto-synced connection in the modal's wire shape: which category it
     * landed in, a label, and the DELETE path the undo button hits.
     *
     * @return array<string,mixed>
     */
    private function shapeSynced(IntegrationConnection $row): array
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $platform = $row->platform;

        [$category, $label] = match ($platform) {
            'opentable' => ['reservations', $payload['name'] ?? 'OpenTable'],
            'reservations' => ['reservations', $payload['name'] ?? 'Reservations'],
            'online-ordering' => ['online-ordering', $payload['name'] ?? 'Order online'],
            'instagram' => ['social', 'Instagram'],
            'facebook' => ['social', 'Facebook'],
            'tiktok' => ['social', 'TikTok'],
            'x' => ['social', 'X'],
            'linkedin' => ['social', 'LinkedIn'],
            default => ['other', $platform],
        };

        // Ordering is multi-entry (delete the specific row); everything else is a
        // single-slot platform whose forget() clears it.
        $removePath = $platform === 'online-ordering'
            ? '/platforms/online-ordering/entries/'.$row->resource_id
            : '/platforms/'.$platform;

        return [
            'platform' => $platform,
            'category' => $category,
            'label' => $label,
            'url' => $payload['url'] ?? null,
            'removePath' => $removePath,
            'pending' => $row->last_refresh_status === 'pending',
        ];
    }
}
