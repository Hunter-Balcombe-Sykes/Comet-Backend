<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectGoogleBusinessRequest;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\GoogleBusinessAutoSync;
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

            // Business accounts adopt the Google Business name as their display name.
            $this->maybeAdoptGoogleName($user, $data['name'] ?? null);

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

        $this->maybeAdoptGoogleName($user, $place['name'] ?? null);

        return $this->connected($user, $place);
    }

    // Business Partna accounts treat the Google Business name as their public
    // display name: connecting (or reconnecting) overwrites display_name with it
    // so the sitepage + dashboard read the official business name. Standard
    // accounts keep whatever name they set. Gated on the capability so the
    // account_type read stays inside AccountCapabilities; UserObserver fans the
    // change out to the sitepage cache (display_name is a public-profile field).
    private function maybeAdoptGoogleName(User $user, ?string $name): void
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || ! AccountCapabilities::for($user)->google_business_sets_display_name) {
            return;
        }
        if ($user->display_name === $name) {
            return;
        }

        $user->display_name = $name;
        $user->save();
    }

    // GET /api/platforms/google-business/synced
    // The platforms THIS Google Business connect found — read from the connection's
    // recorded syncFindings (scoped to the latest scrape), each re-shaped with a
    // live status (synced / syncing / conflict). Only this run's findings appear,
    // never platforms a previous connect already synced.
    public function synced(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $gb = $user->integrationConnections()->where('platform', 'google-business')->first();
        $findings = is_array(data_get($gb?->payload, 'syncFindings')) ? $gb->payload['syncFindings'] : [];

        $synced = collect($findings)
            ->map(fn ($f) => is_array($f) ? $this->shapeFinding($user, $f) : null)
            ->filter()
            ->values()
            ->all();

        return $this->success(['synced' => $synced]);
    }

    // POST /api/platforms/google-business/synced/apply
    // "Change to" — swap the user's existing connection for the one Google found
    // (a conflict finding): remove the existing, install Google's (or re-run the
    // Instagram scrape), and flip the finding to seeded so it shows as synced/syncing.
    public function applySync(Request $request, GoogleBusinessAutoSync $autoSync): JsonResponse
    {
        $user = $this->currentUser($request);
        $platform = $request->validate(['platform' => ['required', 'string', 'max:40']])['platform'];

        $gb = $user->integrationConnections()->where('platform', 'google-business')->first();
        $payload = is_array($gb?->payload) ? $gb->payload : [];
        $findings = is_array($payload['syncFindings'] ?? null) ? array_values($payload['syncFindings']) : [];

        $idx = null;
        foreach ($findings as $i => $f) {
            if (is_array($f) && ($f['platform'] ?? null) === $platform && ($f['outcome'] ?? null) === 'conflict') {
                $idx = $i;
                break;
            }
        }
        if ($idx === null || $gb === null) {
            return $this->error('Nothing to change for that platform.', 404);
        }

        $autoSync->applyFinding((string) $user->id, $findings[$idx]);

        $findings[$idx]['outcome'] = 'seeded';
        $findings[$idx]['apply'] = null;
        $gb->forceFill(['payload' => [...$payload, 'syncFindings' => $findings]])->saveQuietly();

        return $this->synced($request);
    }

    /**
     * Shape one recorded finding for the modal, re-deriving live status. Returns
     * null when a seeded row was since removed (so it drops off the list).
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>|null
     */
    private function shapeFinding(User $user, array $finding): ?array
    {
        $platform = (string) ($finding['platform'] ?? '');
        $category = (string) ($finding['category'] ?? 'other');
        $label = (string) ($finding['label'] ?? $platform);
        $foundUrl = is_string($finding['foundUrl'] ?? null) ? $finding['foundUrl'] : null;

        if (($finding['outcome'] ?? 'seeded') === 'conflict') {
            return [
                'platform' => $platform,
                'category' => $category,
                'label' => $label,
                'status' => 'conflict',
                'foundUrl' => $foundUrl,
                'removePath' => null,
            ];
        }

        // Seeded — drop if the user already removed it; else derive synced/syncing.
        $row = $user->integrationConnections()
            ->where('platform', $platform)
            ->where('resource_id', (string) ($finding['resourceId'] ?? ''))
            ->first();
        if ($row === null) {
            return null;
        }

        return [
            'platform' => $platform,
            'category' => $category,
            'label' => $label,
            'status' => $row->last_refresh_status === 'pending' ? 'syncing' : 'synced',
            'foundUrl' => $foundUrl,
            'removePath' => $platform === 'online-ordering'
                ? '/platforms/online-ordering/entries/'.$row->resource_id
                : '/platforms/'.$platform,
        ];
    }
}
