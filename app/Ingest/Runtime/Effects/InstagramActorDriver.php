<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ApifyBudget;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ProfileFetchFailure;

/**
 * ('actor', 'instagram') — the paid Apify profile scrape.
 *
 * The Apify daily caps are claimed HERE, not in InstagramScraper. That class
 * claims only for its own thin-profile retry and says so; the real cap sits in
 * InstagramController::guardApifyBudget(), which the ingest lane never passes
 * through. Without this claim every scheduled run would spend outside the cap.
 *
 * ORDERING IS LOAD-BEARING: isConfigured() covers BOTH the token and the actor
 * adapter, and runs before the claim. The adapter is resolved deep inside
 * InstagramScraper::attemptFetch(), so checking only the token would let a wrong
 * `partna.instagram.actor` drain the daily Apify cap doing nothing at all.
 *
 * A run costs ONE slot normally and TWO when the profile comes back thin —
 * fetchProfileResult() takes its own claim for the retry. That second slot is
 * correct (it is a second paid run) and is why this driver claims rather than
 * pre-checking remaining().
 */
final class InstagramActorDriver implements BilledEffectDriver
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly ApifyBudget $budget,
    ) {}

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'actor' && $name === 'instagram';
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        // Same normalisation InstagramConnector::pull() applies, so the digest and
        // the actor input agree on what was fetched.
        $username = strtolower(ltrim(trim((string) ($ctx->input['username'] ?? '')), '@'));

        if ($username === '') {
            return BilledEffectResult::noAnswer('instagram actor effect carried no username');
        }

        // Both of the next two send nothing, so both release the ledger claim: a
        // config fault must not lock every handle for the freshness window, and must
        // not stay locked once the config is fixed.
        if (! $this->scraper->isConfigured()) {
            throw new EffectNotAttempted('the Apify token or the configured Instagram actor adapter is missing');
        }

        if (! $this->budget->tryClaim('instagram')) {
            throw new EffectNotAttempted("Apify daily cap reached for actor 'instagram'");
        }

        $result = $this->scraper->fetchProfileResult($username, $ctx->userId);

        if ($result->profile !== null) {
            // Apify's dataset shape is a list; InstagramConnector::profileItem()
            // reads $data[0] ?? $data, so returning the vendor's own shape keeps
            // this driver honest about what came back.
            //
            // A THIN profile (2xx, identity present, post timeline missing) is
            // returned as an answer on purpose: the profile stream lands real
            // identity data, and the media stream's post-less branch emits a Note
            // with no Coverage, so nothing is tombstoned. Revisit in slice 1, where
            // a thin result cached for the freshness window would cost real media.
            return BilledEffectResult::answered([$result->profile]);
        }

        return match ($result->failure) {
            // The actor positively reported the handle does not exist. Settling
            // this ok stops us re-billing a dead handle on every run in the window.
            ProfileFetchFailure::ProfileNotFound => BilledEffectResult::answered(null),

            default => BilledEffectResult::noAnswer(
                "instagram actor did not answer for {$username} ({$result->failure?->value})"
            ),
        };
    }
}
