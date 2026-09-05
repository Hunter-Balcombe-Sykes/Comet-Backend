<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\PreAccount\BuildProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A partna account connects Fresha → we try to add the venue as their
 * WORKPLACE on Google Business (owner, 2026-08-19).
 *
 * The venue's name is what we ask Google for; the venue's OTHER details are
 * how we know the answer is the same place and not a namesake across town:
 * a candidate carries the name AND corroborates on at least one of distance
 * (≤ 300 m of Fresha's pin), postcode, or phone number. Every name-agreeing
 * candidate is persisted as a listing CANDIDATE (proposeCandidates()) for
 * the setup dialog / suggestions inbox to ask about — nothing here ever
 * connects a Google Business listing without that accept step. Adopting one
 * (WorkplaceCandidates::adopt() → connect()) writes it exactly as the
 * pre-account generator writes one (details fetched, Apify enrichment
 * queued), and IntegrationConnectionObserver folds the identity onto the
 * workplace fill-if-empty as it does for any partna Google connect.
 *
 * Retired 2026-09-06: attempt(), the old single-confident-match AUTO-CONNECT
 * for a claimed owner (LinkFreshaVenueToGoogleJob used to keep it for
 * claimed users only, "they can disconnect it themselves" — an address
 * match connecting a stranger's Google listing with no accept step is the
 * same bug class as the GlossGenius/Fresha booking auto-connect closed the
 * same day). Every venue now proposes candidates regardless of claim status.
 *
 * Only for accounts whose workplace is NOT their own brand
 * (workplace_brand_is_site_identity=false — the partna shape) and only when
 * no Google Business connection exists yet: a business connects its own
 * listing on purpose, and an existing listing is never replaced.
 */
// Not final: BioMentionChainsJob's tests substitute it via the container (T14).
class FreshaWorkplaceLinker
{
    private const MAX_DISTANCE_M = 300;

    public function __construct(
        private readonly GoogleBusinessService $google,
    ) {}

    /**
     * @param  array{name:?string, street:?string, city:?string, postcode:?string, region:?string, country:?string, lat:?float, lng:?float, phone:?string}  $venue
     * @return array{outcome:string, placeId:?string, reason:?string}
     */
    /** The shared refusals; a reason string, or null when the lane may run. */
    private function guardReason(User $user, string $name): ?string
    {
        if ($name === '') {
            return 'no_name';
        }
        if (AccountCapabilities::for($user)->workplace_brand_is_site_identity) {
            return 'business_account';
        }
        if ($user->isPendingDeletion()) {
            return 'pending_deletion';
        }
        if ($user->integrationConnections()->where('platform', Platform::GoogleBusiness->value)->exists()) {
            return 'google_already_connected';
        }

        return null;
    }

    /**
     * Persist every name-agreeing candidate for the setup dialog's listing
     * pass (A.5, decision 6) instead of connecting one. Details fetched once
     * per candidate for photo/rating; a row the person already answered
     * (adopted/dismissed/superseded) is never reopened. Returns how many
     * proposed rows were written or refreshed.
     */
    public function proposeCandidates(User $user, array $venue, string $source): int
    {
        $name = trim((string) ($venue['name'] ?? ''));
        if ($this->guardReason($user, $name) !== null) {
            return 0;
        }

        $candidates = $this->candidates($user, $venue) ?? [];
        if ($candidates === []) {
            return 0;
        }

        $siteId = Site::query()->where('user_id', $user->id)->value('id');
        $written = 0;
        foreach ($candidates as $candidate) {
            $existing = DB::table('site.workplace_candidates')
                ->where('user_id', $user->id)
                ->where('place_id', $candidate['id'])
                ->first();
            if ($existing !== null && (string) $existing->state !== 'proposed') {
                continue;
            }

            $details = [];
            try {
                $fetched = $this->google->fetchPlaceDetails($candidate['id'], (string) $user->id);
                $details = is_array($fetched) ? GoogleBusinessPayload::stripThirdPartyPii($fetched) : [];
            } catch (Throwable $e) {
                report($e);
            }

            $photo = null;
            foreach ((array) ($details['photos'] ?? []) as $p) {
                if (is_array($p) && is_string($p['url'] ?? null) && $p['url'] !== '') {
                    $photo = $p['url'];
                    break;
                }
            }

            $fields = [
                'site_id' => $siteId,
                'name' => (string) ($details['name'] ?? $candidate['name']),
                'address' => $details['address'] ?? $candidate['address'],
                'lat' => $candidate['lat'],
                'lng' => $candidate['lng'],
                'photo_url' => $photo,
                'rating' => is_numeric($details['rating'] ?? null) ? (float) $details['rating'] : null,
                'review_count' => is_numeric($details['reviewCount'] ?? null) ? (int) $details['reviewCount'] : null,
                'source' => $source,
                'corroboration' => json_encode($candidate['corroboration']),
                'state' => 'proposed',
            ];
            if ($existing === null) {
                DB::table('site.workplace_candidates')->insert($fields + [
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'place_id' => $candidate['id'],
                    'created_at' => now(),
                ]);
            } else {
                DB::table('site.workplace_candidates')
                    ->where('id', $existing->id)->update($fields);
            }
            $written++;
        }

        if ($written > 0) {
            BuildProgress::noteForUser(
                (string) $user->id,
                PreAccountBuildEvent::STAGE_LISTING,
                PreAccountBuildEvent::STATUS_LANDED,
                BuildProgress::count($written, 'possible listing found', 'possible listings found'),
                ['source' => $source, 'venue' => $name],
            );
        }

        return $written;
    }

    /**
     * Connect ONE candidate as the google-business connection — the accept
     * step (WorkplaceCandidates::adopt()) calls this once the user has
     * picked a proposed candidate: details fetched, Apify enrichment
     * queued, and the observer folds the identity.
     *
     * @param  array{id:string, name:string, address:?string, lat:?float, lng:?float, corroboration?:mixed}  $candidate
     * @return array{outcome:string, placeId:?string, reason:?string, connectionId?:string}
     */
    public function connect(User $user, array $candidate): array
    {
        $match = $candidate;
        $name = (string) $candidate['name'];

        try {
            $details = $this->google->fetchPlaceDetails($match['id'], (string) $user->id);
        } catch (Throwable $e) {
            report($e);
            $details = null;
        }
        // The details call is what makes the card real; without it we still
        // connect on the search result (name/address/location) so the
        // workplace fold has something, and the enrich job fills the rest.
        $details = is_array($details) ? GoogleBusinessPayload::stripThirdPartyPii($details) : [];

        $displayName = trim((string) ($details['name'] ?? $match['name'])) ?: $name;
        $payload = [
            'url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($displayName).'&query_place_id='.rawurlencode($match['id']),
            'placeId' => $match['id'],
            'name' => $displayName,
            'address' => $match['address'],
            'lat' => $match['lat'],
            'lng' => $match['lng'],
            ...$details,
        ];

        $enrich = (bool) config('services.apify.token');
        $connection = IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => Platform::GoogleBusiness->value,
                'resource_id' => Platform::GoogleBusiness->value,
            ],
            [
                'payload' => $payload,
                'is_active' => true,
                'last_refreshed_at' => now(),
                'last_refresh_status' => 'ok',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );
        $connection->forceFill([
            'place_id' => $match['id'],
            'apify_status' => $enrich ? 'pending' : null,
        ])->saveQuietly();

        if ($enrich) {
            GoogleBusinessEnrichJob::dispatch((string) $user->id, $match['id']);
        }

        Log::info('fresha.workplace_link.connected', [
            'user_id' => (string) $user->id,
            'venue' => $name,
            'place_id' => $match['id'],
            'corroborated_by' => $match['corroboration'] ?? null,
        ]);
        // Setup progress (2026-09-02): the workplace row the feed shows.
        BuildProgress::noteForUser(
            (string) $user->id,
            PreAccountBuildEvent::STAGE_WORKPLACE,
            PreAccountBuildEvent::STATUS_LANDED,
            'Workplace: '.$name,
            ['name' => $name, 'address' => $match['address'] ?? null, 'platform' => 'google-business'],
        );

        return ['outcome' => 'connected', 'placeId' => (string) $match['id'], 'reason' => null, 'connectionId' => (string) $connection->id];
    }

    /**
     * Places Text Search around Fresha's pin, through GoogleBusinessService
     * (the only class allowed to hold the Places key or spend the budget).
     *
     * @return list<array<string,mixed>>|null
     */
    private function search(User $user, array $venue): ?array
    {
        $query = implode(', ', array_filter([
            $venue['name'] ?? null,
            $venue['street'] ?? null,
            $venue['city'] ?? null,
        ]));
        $bias = isset($venue['lat'], $venue['lng'])
            ? ['lat' => (float) $venue['lat'], 'lng' => (float) $venue['lng'], 'radiusMetres' => 2000.0]
            : null;
        $region = isset($venue['country']) && strlen((string) $venue['country']) === 2 ? (string) $venue['country'] : null;

        return $this->google->searchText($query, (string) $user->id, $bias, $region, 5);
    }

    /**
     * Every operational candidate that carries the venue's name, each with
     * the corroborators it earned — 'name' always, plus any of distance,
     * postcode, phone, or (when the venue offers no corroborator at all)
     * the name-locality token match. No refusal on ambiguity: the caller
     * decides — proposeCandidates() writes them all for the person to pick
     * (A.5; the old single-confident-match attempt() was retired 2026-09-06).
     *
     * @return list<array{id:string, name:string, address:?string, lat:?float, lng:?float, corroboration:list<string>}>|null
     *                                                                                                                       null = search unavailable
     */
    public function candidates(User $user, array $venue): ?array
    {
        $places = $this->search($user, $venue);
        if ($places === null) {
            return null;
        }

        $venueName = $this->normaliseName((string) ($venue['name'] ?? ''));
        $venuePhone = $this->phoneDigits($venue['phone'] ?? null);
        $venuePostcode = trim((string) ($venue['postcode'] ?? ''));

        $out = [];
        foreach ($places as $place) {
            $id = $place['id'] ?? null;
            $displayName = data_get($place, 'displayName.text');
            if (! is_string($id) || ! is_string($displayName)) {
                continue;
            }
            if (($place['businessStatus'] ?? 'OPERATIONAL') === 'CLOSED_PERMANENTLY') {
                continue;
            }
            if (! $this->namesAgree($venueName, $this->normaliseName($displayName))) {
                continue;
            }

            $lat = data_get($place, 'location.latitude');
            $lng = data_get($place, 'location.longitude');
            $corroboration = ['name'];
            if (isset($venue['lat'], $venue['lng']) && is_numeric($lat) && is_numeric($lng)
                && $this->distanceMetres((float) $venue['lat'], (float) $venue['lng'], (float) $lat, (float) $lng) <= self::MAX_DISTANCE_M) {
                $corroboration[] = 'distance';
            }
            if ($venuePostcode !== '' && trim((string) data_get($place, 'postalAddress.postalCode', '')) === $venuePostcode) {
                $corroboration[] = 'postcode';
            }
            if ($venuePhone !== null) {
                foreach (['nationalPhoneNumber', 'internationalPhoneNumber'] as $field) {
                    if ($this->phoneDigits($place[$field] ?? null) === $venuePhone) {
                        $corroboration[] = 'phone';
                        break;
                    }
                }
            }
            if (! isset($venue['lat'], $venue['lng']) && $venuePostcode === '' && $venuePhone === null) {
                // T14/owner 2026-08-27: locality-corroborated hit for venues
                // that OFFER no corroborator at all (a bio-mention venue whose
                // IG bio carries only opening hours — measured on
                // @star_barber_darwin). A locality-looking token from the
                // venue NAME appearing in the candidate's own address is the
                // agreement ("Star Barber DARWIN" ↔ "…Darwin NT…").
                foreach (preg_split('/\s+/', mb_strtolower((string) $venue['name'])) ?: [] as $token) {
                    if (mb_strlen($token) >= 4
                        && ! preg_match('/^(the|and|salon|studio|barbers?|barbershop|hair|beauty|nails?|spa|clinic|shop|store)$/u', $token)
                        && stripos((string) ($place['formattedAddress'] ?? ''), $token) !== false) {
                        $corroboration[] = 'name-locality';
                        break;
                    }
                }
            }

            $out[] = [
                'id' => $id,
                'name' => $displayName,
                'address' => is_string($place['formattedAddress'] ?? null) ? $place['formattedAddress'] : null,
                'lat' => is_numeric($lat) ? (float) $lat : null,
                'lng' => is_numeric($lng) ? (float) $lng : null,
                'corroboration' => $corroboration,
            ];
        }

        return $out;
    }

    /** Lower-cased, punctuation stripped, generic trade words dropped. */
    private function normaliseName(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $n) ?? $n;
        $n = preg_replace('/\b(the|and|&|salon|studio|barbers?|barbershop|hair|beauty|nails?|spa|clinic|co|pty|ltd)\b/u', ' ', $n) ?? $n;

        return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
    }

    private function namesAgree(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        // A bio-mention venue name can be nothing but its Instagram handle
        // (no separators to split on — "membersonlychopshop"), which never
        // shares a token with Google's real, spaced-out listing name
        // ("members only chop shop") under the word-set check below. Squashed
        // (space-free) equality catches that extremely common handle
        // convention — handle = the business's real name with the spaces
        // removed (found live 2026-09-05, membersonlychopshop/Orlando).
        if (str_replace(' ', '', $a) === str_replace(' ', '', $b)) {
            return true;
        }
        $ta = array_unique(explode(' ', $a));
        $tb = array_unique(explode(' ', $b));
        $shared = count(array_intersect($ta, $tb));

        return $shared > 0 && $shared / max(1, min(count($ta), count($tb))) >= 0.6;
    }

    private function phoneDigits(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // The last eight digits: enough to identify a line, immune to the
        // +61 / 0 / (03) prefixes the two sides format differently.
        return strlen($digits) >= 8 ? substr($digits, -8) : null;
    }

    private function distanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
    }
}
