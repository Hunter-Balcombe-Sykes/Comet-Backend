<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\DisplaySettingsFilter;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

// Re-pulls a Google Business Place Details snapshot by stored placeId. The
// dispatcher's per-platform cadence (registry refreshEvery, 2 days) governs
// how often this runs; a detailsFetchedAt < 40 hours short-circuit still
// guards against manual-refresh hammering + honours Google's ToS caching
// window without letting ratings/review counts drift for a week (the old
// 6-day gate meant a listing's rating could be 7 days stale on the sitepage).
// Mirrors PlatformRefresher::googleBusinessPayload EXACTLY — note the
// asymmetry: a MISSING placeId is 'unavailable' (legacy link-paste rows lack
// one), not a shape error.
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
                && Carbon::parse($payload['detailsFetchedAt'])->gt(now()->subHours(40));
        } catch (\Throwable) {
            $fresh = false;
        }
        if ($fresh) {
            return $payload;
        }

        try {
            $details = $this->googleBusiness->fetchPlaceDetails((string) $placeId, (string) $connection->user_id, (array) ($payload['photos'] ?? []));
        } catch (PlacesBudgetExhaustedException) {
            // RV-6: quiet either way here — the refresh cron isn't a synchronous
            // caller a user is waiting on, so both UserCapReached and
            // PlatformCapReached degrade the same way: 'unavailable', prior
            // payload preserved, retried next cycle.
            throw new FetchUnavailableException('places_budget_exhausted');
        }
        if ($details === null) {
            throw new FetchUnavailableException('google_details_fetch_failed');
        }

        // PRIV-1: google-business is refreshable (this method), so a generator-only
        // strip at build time would reinflate reviewer PII within one TTL cycle for
        // a still-unclaimed owner. Mirror the same strip here, gated on live status
        // (never the connection's stale cached state) so it self-heals the moment
        // the owner claims: claim flips status to 'active' and the very next
        // refresh restores full data with no ClaimSiteService change needed. A
        // cheap scalar column read — never a full User hydrate.
        $unclaimed = $connection->ownerIsUnclaimed();
        if ($unclaimed) {
            $details = GoogleBusinessPayload::stripThirdPartyPii($details);
        }

        // WS-B2.2: sections the owner switched off are not refreshed into storage —
        // drop them from the fresh snapshot so the stored payload never gains (or
        // updates) data we won't serve. Existing stored values are left untouched
        // (non-destructive); serve-gating hides them everywhere regardless.
        $details = Arr::except($details, DisplaySettingsFilter::disabledKeys('google-business', $connection->display_settings));

        $merged = [...$payload, ...$details];

        // PRIV-2: unlike the strip above, this one is DESTRUCTIVE of stored state and
        // therefore does NOT self-heal on claim — the strip above re-fetches $details
        // every cycle, whereas nothing regenerates syncFindings (see the enrich job).
        // syncFindings is never in $details, so it can only arrive from $payload.
        // GoogleBusinessEnrichJob stops writing it pre-claim, but rows enriched
        // before that guard existed still carry it, and an unclaimed owner never gets
        // a second enrich to self-clean. This cron does run for them (the refresh
        // candidate query filters on platform + freshness only, never user status),
        // so it is the seam that actually retires those rows — no backfill script.
        if ($unclaimed) {
            unset($merged['syncFindings']);
        }

        return $merged;
    }
}
