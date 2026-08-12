<?php

namespace App\Ingest\Runtime\Effects;

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\PlacesBudget;
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
final class PlacesDetailsDriver implements BilledEffectDriver, PrecheckableBilledEffect
{
    public function __construct(
        private readonly GoogleBusinessService $places,
        private readonly PlacesBudget $budget,
    ) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'api' && $name === 'places.details';
    }

    /**
     * #MONEY-2: refuse a capped day before EffectLedger writes a claim row.
     *
     * Budget only, deliberately. It is the case the finding was raised against
     * (a capped day repeats all day, whereas a missing key is rare and
     * permanent), and it is the one PlacesBudget can answer without a vendor
     * call. Everything this misses is still caught by run()'s BudgetDenied and
     * NotConfigured arms below, which stay the authority.
     *
     * remaining() documents itself as advisory and racy — "not a hard gate,
     * claim() is the sole authority" — which is exactly the right strength
     * here: this runs OUTSIDE the claim, so it could never be race-free, and
     * treating it as a gate would be a bug. It only ever skips work we were
     * going to refuse anyway.
     *
     * A null userId is NOT refused here: run() answers that with noAnswer(),
     * which settles the row visibly, and a precheck may only ever throw
     * EffectNotAttempted ("nothing was sent"). Converting one to the other
     * would hide our own bug behind a budget-shaped excuse.
     *
     * ALLOWS ON A CACHE OUTAGE, and that direction is deliberate. remaining()
     * is the one PlacesBudget method with no try/catch — claim() wraps the
     * identical Cache work and degrades to PlacesClaim::Unavailable, but
     * remaining() calls Cache::get() bare, and those reads go through
     * GuardedPhpRedisConnection, which throws once the request breaker opens.
     *
     * once() invokes this OUTSIDE every try it has, so an escaping exception
     * reaches RunExecutor's catch-all and marks the stream 'error' — paging
     * on-call for a Valkey blip that used to fold quietly to 'budget_skipped'
     * via claim() → BudgetDenied → EffectNotAttempted → EffectRefused. Exactly
     * the outcome run()'s own comment below refuses to accept for a spend
     * ceiling.
     *
     * Allowing (rather than failing closed) is safe ONLY because this is an
     * optimisation: control falls through to run(), whose claim() is the real
     * gate and still fails closed. Failing closed here would be the worse bug
     * — a cache blip would silently skip billed work the caller expects.
     */
    public function precheck(BilledEffectContext $context): void
    {
        if ($context->userId === null) {
            return;
        }

        try {
            $remaining = $this->budget->remaining('details', $context->userId);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if ($remaining <= 0) {
            throw new EffectNotAttempted(
                "Places details budget exhausted before claim for user {$context->userId}"
            );
        }
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
