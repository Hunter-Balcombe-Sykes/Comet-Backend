<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectGoogleBusinessRequest;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Rules\PlatformInRegistry;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Google Business — connect via the Places picker (canonical) or a pasted
// Maps share link (legacy). Picker connects are enriched server-side with the
// Place Details snapshot (rating, reviews, hours, phone, website, …); the
// refresh cron keeps that snapshot current. Link connects stay the honest
// URL-parse subset: name + coordinates + keyless map embed.
class GoogleBusinessController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(private readonly GoogleBusinessService $service) {}

    protected function platform(): string
    {
        return Platform::GoogleBusiness->value;
    }

    protected function resourceClass(): string
    {
        return GoogleBusinessConnectionResource::class;
    }

    // GET /api/platforms/google-business/selection
    // Overrides the base (payload-only) selection so apifyStatus is sourced from
    // the promoted apify_status column, not the payload. The resource is built
    // from the payload ARRAY, so we splice the column value into that array
    // (the resource itself is unchanged — apifyStatus stays in ENRICHMENT_KEYS).
    public function selection(Request $request): JsonResponse
    {
        $row = $this->accountRows($this->currentUser($request))->first();
        if ($row === null) {
            return $this->success(['selection' => null]);
        }

        $payload = GoogleBusinessPayload::fromArray($row->payload)->toArray();
        // Column is the source of truth: drop any legacy payload copy, then add
        // the column value back as apifyStatus when set (null = never enriched).
        unset($payload['apifyStatus']);
        if ($row->apify_status !== null) {
            $payload['apifyStatus'] = $row->apify_status;
        }

        $resource = $this->resourceClass();

        return $this->success(['selection' => (new $resource($payload))->resolve()]);
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
                'placeId' => $data['placeId'],   // KEPT in payload — first-class identifier
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
            // we return the instant Place Details card. apify_status='pending'
            // (a real column now, NOT a payload key) drives the dashboard's poll;
            // the job flips it to ok/unavailable. Gated on the token so a missing
            // key is a clean no-op.
            $enrich = (bool) config('services.apify.token');

            // Business accounts adopt the Google Business name as their display name.
            $this->maybeAdoptGoogleName($user, $data['name'] ?? null);

            // writeConnection owns the create/update authorization + payload upsert;
            // the promoted columns are a GB-specific follow-up. apify_status lives
            // ONLY in the column; place_id mirrors the payload value for the indexed
            // reconnect guard. saveQuietly — no public change beyond the payload
            // write writeConnection already purged.
            $row = $this->writeConnection($user, $merged);
            $row->forceFill([
                'place_id' => $data['placeId'],
                'apify_status' => $enrich ? 'pending' : null,
            ])->saveQuietly();

            // Echo: re-inject apifyStatus from the column so the connect response
            // keeps the key the dashboard polls on (resource is built from the array).
            $resource = $this->resourceClass();
            $echo = $merged;
            if ($enrich) {
                $echo['apifyStatus'] = 'pending';
            }
            $response = $this->success((new $resource($echo))->resolve());

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

        $this->writeConnection($user, $place);
        $resource = $this->resourceClass();

        return $this->success((new $resource($place))->resolve());
    }

    // DELETE /api/platforms/google-business — clear every connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetAllConnections($this->currentUser($request));

        return $this->success(['selection' => null]);
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
        $gb = $user->integrationConnections()->where('platform', Platform::GoogleBusiness->value)->first();
        $findings = GoogleBusinessPayload::fromArray($gb?->payload)->syncFindings();

        // Pre-load all connections keyed by "platform|resource_id" so shapeFinding
        // can look up each seeded row in O(1) instead of issuing a DB query per finding.
        $connections = $user->integrationConnections()
            ->get()
            ->keyBy(fn ($r) => $r->platform.'|'.$r->resource_id);

        $synced = collect($findings)
            ->map(fn ($f) => is_array($f) ? $this->shapeFinding($user, $f, $connections) : null)
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
        $platform = $request->validate(['platform' => ['required', 'string', 'max:40', new PlatformInRegistry]])['platform'];

        $gb = $user->integrationConnections()->where('platform', Platform::GoogleBusiness->value)->first();
        $gbp = GoogleBusinessPayload::fromArray($gb?->payload);
        $payload = $gbp->toArray();
        $findings = $gbp->syncFindings();

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
     * @param  Collection<string, IntegrationConnection>  $connections  pre-loaded keyed by "platform|resource_id"
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>|null
     */
    private function shapeFinding(User $user, array $finding, Collection $connections): ?array
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
        // Use the pre-loaded collection keyed by "platform|resource_id" to avoid a
        // DB query per finding.
        $resourceId = (string) ($finding['resourceId'] ?? '');
        $row = $connections->get($platform.'|'.$resourceId);
        if ($row === null) {
            return null;
        }

        return [
            'platform' => $platform,
            'category' => $category,
            'label' => $label,
            'status' => $row->last_refresh_status === 'pending' ? 'syncing' : 'synced',
            'foundUrl' => $foundUrl,
            'removePath' => $platform === Platform::OnlineOrdering->value
                ? '/platforms/online-ordering/entries/'.$row->resource_id
                : '/platforms/'.$platform,
        ];
    }
}
