<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A partna account connects Fresha → we try to add the venue as their
 * WORKPLACE on Google Business (owner, 2026-08-19).
 *
 * The venue's name is what we ask Google for; the venue's OTHER details are
 * how we know the answer is the same place and not a namesake across town:
 * a candidate must carry the name AND corroborate on at least one of
 * distance (≤ 300 m of Fresha's pin), postcode, or phone number. One
 * confident candidate → a google-business connection is written exactly as
 * the pre-account generator writes one (details fetched, Apify enrichment
 * queued), and IntegrationConnectionObserver folds the identity onto the
 * workplace fill-if-empty as it does for any partna Google connect. No
 * confident candidate → nothing happens, logged.
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
    public function attempt(User $user, array $venue): array
    {
        $name = trim((string) ($venue['name'] ?? ''));
        if ($name === '') {
            return $this->outcome('skipped', null, 'no_name');
        }
        if (AccountCapabilities::for($user)->workplace_brand_is_site_identity) {
            return $this->outcome('skipped', null, 'business_account');
        }
        if ($user->isPendingDeletion()) {
            return $this->outcome('skipped', null, 'pending_deletion');
        }
        if ($user->integrationConnections()->where('platform', Platform::GoogleBusiness->value)->exists()) {
            return $this->outcome('skipped', null, 'google_already_connected');
        }

        $candidates = $this->search($user, $venue);
        if ($candidates === null) {
            return $this->outcome('failed', null, 'search_unavailable');
        }

        $match = $this->pick($venue, $candidates);
        if ($match === null) {
            Log::info('fresha.workplace_link.no_confident_match', [
                'user_id' => (string) $user->id,
                'venue' => $name,
                'candidates' => count($candidates),
            ]);

            return $this->outcome('no_match', null, 'no_confident_match');
        }

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
            'corroborated_by' => $match['corroboration'],
        ]);

        return $this->outcome('connected', $match['id'], null);
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
     * The one candidate that carries the venue's name and corroborates on
     * distance, postcode or phone — or null.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return array{id:string, name:string, address:?string, lat:?float, lng:?float, corroboration:string}|null
     */
    private function pick(array $venue, array $candidates): ?array
    {
        $venueName = $this->normaliseName((string) ($venue['name'] ?? ''));
        $venuePhone = $this->phoneDigits($venue['phone'] ?? null);
        $venuePostcode = trim((string) ($venue['postcode'] ?? ''));

        $best = null;
        foreach ($candidates as $place) {
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
            $corroboration = null;
            if (isset($venue['lat'], $venue['lng']) && is_numeric($lat) && is_numeric($lng)
                && $this->distanceMetres((float) $venue['lat'], (float) $venue['lng'], (float) $lat, (float) $lng) <= self::MAX_DISTANCE_M) {
                $corroboration = 'distance';
            } elseif ($venuePostcode !== '' && trim((string) data_get($place, 'postalAddress.postalCode', '')) === $venuePostcode) {
                $corroboration = 'postcode';
            } elseif ($venuePhone !== null) {
                foreach (['nationalPhoneNumber', 'internationalPhoneNumber'] as $field) {
                    if ($this->phoneDigits($place[$field] ?? null) === $venuePhone) {
                        $corroboration = 'phone';
                        break;
                    }
                }
            } elseif (! isset($venue['lat'], $venue['lng']) && $venuePostcode === '') {
                // ($venuePhone is necessarily null here — the branch above took it.)
                // T14/owner 2026-08-27: locality-corroborated hit for venues
                // that OFFER no corroborator at all (a bio-mention venue whose
                // IG bio carries only opening hours — measured on
                // @star_barber_darwin). A locality-looking token from the
                // venue NAME appearing in the candidate's own address is the
                // agreement ("Star Barber DARWIN" ↔ "…Darwin NT…"). Fires
                // only in this nothing-else-to-offer case; the single-
                // candidate ambiguity guard below still applies.
                foreach (preg_split('/\s+/', mb_strtolower((string) $venue['name'])) ?: [] as $token) {
                    if (mb_strlen($token) >= 4
                        && ! preg_match('/^(the|and|salon|studio|barbers?|barbershop|hair|beauty|nails?|spa|clinic|shop|store)$/u', $token)
                        && stripos((string) ($place['formattedAddress'] ?? ''), $token) !== false) {
                        $corroboration = 'name-locality';
                        break;
                    }
                }
            }
            if ($corroboration === null) {
                continue;
            }

            $candidate = [
                'id' => $id,
                'name' => $displayName,
                'address' => is_string($place['formattedAddress'] ?? null) ? $place['formattedAddress'] : null,
                'lat' => is_numeric($lat) ? (float) $lat : null,
                'lng' => is_numeric($lng) ? (float) $lng : null,
                'corroboration' => $corroboration,
            ];
            // Two confident candidates is ambiguity, not confidence.
            if ($best !== null) {
                return null;
            }
            $best = $candidate;
        }

        return $best;
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

    /** @return array{outcome:string, placeId:?string, reason:?string} */
    private function outcome(string $outcome, ?string $placeId, ?string $reason): array
    {
        return ['outcome' => $outcome, 'placeId' => $placeId, 'reason' => $reason];
    }
}
