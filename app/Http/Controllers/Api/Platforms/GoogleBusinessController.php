<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectGoogleBusinessRequest;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\PlacesClaim;
use App\Services\Platforms\DisplaySettingsFilter;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use App\Support\BusinessName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // WS-B2.2: honour the owner's display toggles on the dashboard card too —
        // a section switched off in Controls genuinely disappears here, not just
        // on the public sitepage.
        $payload = DisplaySettingsFilter::suppress($this->platform(), $payload, $row->display_settings);

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
            //
            // RV-6: a PlacesBudgetExhaustedException means the caller was DENIED
            // a spend claim, not that the fetch failed. UserCapReached is this
            // user's own doing (actionable) → 429. PlatformCapReached/Unavailable
            // is nothing this user can do about → degrade exactly like a missing
            // key or failed fetch (keep the picker card), but report() so the
            // platform-wide condition still pages Nightwatch.
            try {
                $details = $this->service->fetchPlaceDetails($data['placeId'], (string) $user->id);
            } catch (PlacesBudgetExhaustedException $e) {
                if ($e->reason === PlacesClaim::UserCapReached) {
                    return $this->error("You've looked up too many businesses today. Try again tomorrow.", 429);
                }

                report($e);
                $details = null;
            }
            $merged = [...$selection, ...($details ?? [])];

            // Apify enrichment (menu / reservation / order / booking / socials)
            // is too slow to block connect, so it runs in a background job while
            // we return the instant Place Details card. apify_status='pending'
            // (a real column now, NOT a payload key) drives the dashboard's poll;
            // the job flips it to ok/unavailable. Gated on the token so a missing
            // key is a clean no-op.
            $enrich = (bool) config('services.apify.token');

            // Business accounts adopt the Google Business name as their display name.
            // Suburb comes from the Place Details merge above — the picker form
            // itself never carries addressParts.
            $this->maybeAdoptGoogleName($user, $data['name'] ?? null, data_get($merged, 'addressParts.suburb'));

            // writeConnection owns the create/update authorization + payload upsert;
            // the promoted columns are a GB-specific follow-up. apify_status lives
            // ONLY in the column; place_id mirrors the payload value for the indexed
            // reconnect guard. saveQuietly — no public change beyond the payload
            // write writeConnection already purged.
            //
            // PWL-1: GoogleBusinessEnrichJob::persist() locks on this same
            // platform+user key (see that job's own docblock, which documents this
            // gap explicitly), so the read→mutate→write here must too, or a
            // background enrich run can clobber a just-saved edit. The job
            // dispatch below stays OUTSIDE the lock — it needs nothing from $row,
            // and dispatching it from inside would self-deadlock under a sync
            // queue connection (the job blocks on the identical lock key).
            $response = $this->withConnectionLock($user, function () use ($user, $merged, $data, $enrich): JsonResponse {
                // Google Business is single-account: writeConnection() below
                // UPSERTS the one connection row, so picking a DIFFERENT
                // business from search used to silently overwrite whichever
                // one was already connected (the auto-matched workplace from
                // signup, or an earlier pick), with nothing left behind to
                // show it was ever an option — live 2026-09-05: "Select your
                // workplace" replaced the suggested listing instead of
                // offering both with the new one selected. Preserve the
                // outgoing business as a `site.workplace_candidates` row
                // (state 'proposed', unselected) before the overwrite, the
                // same shape FreshaWorkplaceLinker::seed() already writes —
                // SetupPayload::listingCandidates() unions it back in
                // alongside the new connection.
                $current = $user->integrationConnections()
                    ->where('platform', 'google-business')
                    ->whereNull('deleted_at')
                    ->first();
                if ($current !== null && (string) $current->place_id !== (string) $data['placeId']) {
                    $outgoing = GoogleBusinessPayload::fromArray($current->payload);
                    $placeId = (string) ($current->place_id ?? '');
                    if ($placeId !== '' && ! DB::table('site.workplace_candidates')
                        ->where('user_id', $user->id)->where('place_id', $placeId)->exists()) {
                        $photo = null;
                        foreach ($outgoing->photos() as $p) {
                            if ($p['photoPicUrl'] !== null) {
                                $photo = $p['photoPicUrl'];
                                break;
                            }
                        }
                        DB::table('site.workplace_candidates')->insert([
                            'id' => (string) Str::uuid(),
                            'user_id' => $user->id,
                            'place_id' => $placeId,
                            'name' => $outgoing->name() ?? 'Your listing',
                            'address' => $outgoing->address(),
                            'lat' => $outgoing->lat(),
                            'lng' => $outgoing->lng(),
                            'photo_url' => $photo,
                            'rating' => $outgoing->rating(),
                            'review_count' => $outgoing->reviewCount(),
                            'source' => 'previously_connected',
                            'corroboration' => json_encode([]),
                            'state' => 'proposed',
                            'created_at' => now(),
                        ]);
                    }
                }

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
                // WS-B2.2: respect display toggles on the connect echo (a reconnect can
                // carry previously-saved display_settings).
                $echo = DisplaySettingsFilter::suppress($this->platform(), $echo, $row->display_settings);

                return $this->success((new $resource($echo))->resolve());
            });

            if ($enrich) {
                GoogleBusinessEnrichJob::dispatch((string) $user->id, $data['placeId']);
            }

            return $response;
        }

        $place = $this->service->resolve($data['url']);
        if ($place === null) {
            return $this->error('Paste your Google Maps link — open your business on Google Maps, hit Share, and copy the link.', 422);
        }

        $this->maybeAdoptGoogleName($user, $place['name'] ?? null, data_get($place, 'addressParts.suburb'));

        // PWL-1: same lock-boundary reasoning as the picker branch above — the
        // legacy link-parse path writes the same row the enrich job locks on.
        return $this->withConnectionLock($user, function () use ($user, $place): JsonResponse {
            $row = $this->writeConnection($user, $place);
            $resource = $this->resourceClass();
            // WS-B2.2: respect display toggles on the legacy link-parse connect echo.
            $shaped = DisplaySettingsFilter::suppress($this->platform(), $place, $row->display_settings);

            return $this->success((new $resource($shaped))->resolve());
        });
    }

    // DELETE /api/platforms/google-business — clear every connection.
    // PWL-1: wrapped so a concurrent GoogleBusinessEnrichJob write can't land
    // between forgetAllConnections()'s reads and deletes.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user): JsonResponse {
            $this->forgetAllConnections($user);

            return $this->success(['selection' => null]);
        });
    }

    // Business Partna accounts treat the Google Business name as their public
    // display name: connecting (or reconnecting) overwrites display_name with it
    // so the sitepage + dashboard read the official business name. Standard
    // accounts keep whatever name they set. Gated on the capability so the
    // account_type read stays inside AccountCapabilities; UserObserver fans the
    // change out to the sitepage cache (display_name is a public-profile field).
    private function maybeAdoptGoogleName(User $user, ?string $name, ?string $suburb = null): void
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || ! AccountCapabilities::for($user)->google_business_sets_display_name) {
            return;
        }

        // Item 1b: the listing's own suburb comes off the end first — the
        // sitepage wants the brand, Google's multi-location disambiguator
        // stays available to the handle ladder (HandleAllocator's untrimmed
        // fallback). Logged so every name's provenance is one line away.
        $trim = BusinessName::trimLocality($name, $suburb);
        if ($trim['rule'] !== null) {
            Log::info('name_trim', [
                'user_id' => $user->id,
                'from' => $name,
                'to' => $trim['name'],
                'rule' => $trim['rule'],
            ]);
        }

        // Business names carry an 80-char sanity bound. This value is
        // auto-adopted from Google, not typed by hand, so it can't be rejected
        // outright like UpsertWorkplaceRequest does for manual entry —
        // word-trimmed instead.
        $name = BusinessName::wordTrim($trim['name']);

        if ($user->display_name === $name) {
            return;
        }

        $user->display_name = $name;
        $user->save();
    }
}
