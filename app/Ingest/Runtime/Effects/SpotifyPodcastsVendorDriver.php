<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\SpotifyEpisodesNormalizer;
use App\Services\Platforms\SpotifyPodcastsScraper;
use Illuminate\Support\Facades\Log;

/**
 * ('vendor', 'spotify_podcasts') — a show's newest episodes page into
 * listen-pool rows (Item 11f, 2026-09-01). The PinterestVendorDriver frame,
 * one endpoint: ScrapeCreators is the only fetch path (no Apify actor
 * exists), so the kind is 'vendor' and a vendor miss is this driver's own
 * noAnswer rather than someone else's fall-through. The SHOW card call lives
 * in SpotifyPodcastsScraper on the connect side — this driver spends only
 * for episodes, so each lane's claims stay in one home.
 *
 * ORDERING IS LOAD-BEARING, as in every billed driver: every check that can
 * refuse a run happens BEFORE the budget claim. Refusals before the claim
 * throw EffectNotAttempted (ledger claim deleted, digest free to retry);
 * after the claim only answered/noAnswer may leave.
 *
 * One call per run — the endpoint answers up to ~50 newest-first episodes,
 * more than the listen surface keeps, so paging the cursor would only buy
 * rows the pool throws away. Budget rules are the Item 8 contract verbatim:
 * claim before the call, release on transport-null, keep the slot spent on
 * billed husks. BOTH recorded husk shapes bill: the NotFound answer
 * (success:true, __typename "NotFound") and the all-RestrictedContent list a
 * Spotify exclusive can degrade to — the normalizer folds both to null, and
 * the slot stays spent either way.
 *
 * No usable rows ⇒ noAnswer, never answered([]) — a show whose whole page is
 * restricted is indistinguishable from a vendor miss, and settling it ok
 * would serve "this show has no episodes" for the freshness window.
 */
final class SpotifyPodcastsVendorDriver implements BilledEffectDriver
{
    private const SOURCE = 'spotify_podcasts';

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'vendor' && $name === self::SOURCE;
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        // Same grammar the connector and connect strategy parse through, so
        // the digest and the vendor input agree on which show was fetched.
        $id = SpotifyPodcastsScraper::showId((string) ($ctx->input['show_id'] ?? ''));
        if ($id === null) {
            return BilledEffectResult::noAnswer('spotify_podcasts vendor effect carried no show id');
        }

        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            // No fallback lane exists for spotify_podcasts — a missing key
            // refuses the run outright instead of failing it, so the digest
            // may retry once the key lands.
            throw new EffectNotAttempted('no ScrapeCreators key configured for the spotify_podcasts vendor');
        }

        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim(self::SOURCE)) {
            throw new EffectNotAttempted("ScrapeCreators daily cap reached for source '".self::SOURCE."'");
        }

        $body = $client->get('/v1/spotify/podcast/episodes', ['id' => $id], $ctx->userId);
        if ($body === null) {
            $budget->release(self::SOURCE);

            return BilledEffectResult::noAnswer('spotify podcast episodes call did not answer');
        }

        // From here the call was billed upstream — the slot stays spent.
        $rows = app(SpotifyEpisodesNormalizer::class)->episodes($body);
        if ($rows === null) {
            $this->log('spotify_podcasts.vendor.unusable_shape', $ctx, ['show_id' => $id]);

            return BilledEffectResult::noAnswer('spotify podcast episodes answer carried no usable episode');
        }

        $this->log('spotify_podcasts.vendor.ok', $ctx, ['rows' => count($rows)]);

        return BilledEffectResult::answered($rows);
    }

    /** @param array<string, mixed> $extra */
    private function log(string $event, BilledEffectContext $ctx, array $extra): void
    {
        // info level: cloud env:logs surfaces info, and a failed scrape must
        // be diagnosable from the stream.
        Log::info($event, $extra + [
            'source_id' => $ctx->sourceId,
            'run_id' => $ctx->runId,
            'user_id' => $ctx->userId,
        ]);
    }
}
