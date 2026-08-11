<?php

namespace App\Ingest\Runtime\Effects;

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\PlaceDetailsFailure;

/**
 * ('api', 'places.details') — the one path permitted to issue a keyed Places
 * request from the ingest lane, mirroring the rule GoogleBusinessConnector's
 * empty `hosts` list encodes.
 *
 * Returns the RAW Places response, not the mapped card payload: the connector
 * reads displayName.text, photos[].name and reviews[].authorAttribution, and its
 * when_unclaimed reviewer-PII redaction is declared over those exact keys.
 *
 * Deliberately does NOT resolve photo refs to servable URLs. That is up to 15
 * further billed media calls per run and it belongs to slice 1, where something
 * actually renders them.
 */
final class PlacesDetailsDriver implements BilledEffectDriver
{
    public function __construct(private readonly GoogleBusinessService $places) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'api' && $name === 'places.details';
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $placeId = trim((string) ($ctx->input['place_id'] ?? ''));

        // No place id and no owner are both our own bugs, not vendor conditions —
        // NoAnswer keeps the ledger row settled and visible instead of throwing
        // into RunExecutor's catch-all, and neither spends anything.
        if ($placeId === '') {
            return BilledEffectResult::noAnswer('places.details effect carried no place_id');
        }
        if ($ctx->userId === null) {
            return BilledEffectResult::noAnswer("places.details effect for {$placeId} has no owning user to budget against");
        }

        try {
            $result = $this->places->fetchPlaceDetailsRaw($placeId, $ctx->userId);
        } catch (PlacesBudgetExhaustedException $e) {
            // Only reachable from a MID-LOOP denial: fetchPlaceDetailsRaw() reports a
            // first-attempt denial rather than throwing, and a later attempt only
            // follows a transport failure — so a request already reached Google and
            // may have been billed. NoAnswer (not EffectNotAttempted) keeps the
            // settled row and its claim, which is the only safe reading of "we might
            // have been charged".
            //
            // Caught rather than left to propagate: an escaping RuntimeException hits
            // RunExecutor's catch-all, marking the stream 'error' and paging on-call.
            // A spend ceiling is not our bug.
            return BilledEffectResult::noAnswer(
                "places.details budget denied mid-retry for {$placeId} ({$e->reason->value})"
            );
        }

        if ($result->place !== null) {
            return BilledEffectResult::answered($result->place);
        }

        return match ($result->failure) {
            // Nothing was sent. once() releases the claim, so the digest is retryable
            // the moment the cap resets or the key is set, instead of being locked
            // out for the rest of the freshness window.
            PlaceDetailsFailure::BudgetDenied => throw new EffectNotAttempted(
                "Places details budget denied for {$placeId} ({$result->deniedBy?->value})"
            ),
            // Names the ENV VAR, not the config path: PlacesBudgetGuardTest's
            // sole-origin check is a substring match on the config key, and that
            // guard should stay a bright line rather than collect an allowlist
            // entry for a file that only mentions the key in an error string.
            // GOOGLE_MAPS_SERVER_API_KEY is what an operator sets anyway.
            PlaceDetailsFailure::NotConfigured => throw new EffectNotAttempted(
                'GOOGLE_MAPS_SERVER_API_KEY is not configured'
            ),

            // Google answered: there is no such place. Settling this ok stops us
            // re-billing a dead place id on every run inside the window.
            PlaceDetailsFailure::NotFound => BilledEffectResult::answered(null),

            default => BilledEffectResult::noAnswer(
                "places.details did not answer for {$placeId} ({$result->failure?->value})"
            ),
        };
    }
}
