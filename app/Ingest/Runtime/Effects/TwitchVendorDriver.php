<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\TwitchVideosNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * ('vendor', 'twitch') — the channel's recent VODs into watch-pool candidate
 * rows (Item 10a, 2026-09-01). ScrapeCreators-ONLY, like pinterest: the old
 * TwitchScraper died with the link-only demotion (f4d7c3b0f), so there is no
 * Apify actor to fall through to and a vendor miss is this driver's own
 * noAnswer.
 *
 * ONE billed call per run, deliberately: /v1/twitch/user/videos. The profile
 * endpoint is NOT called here — identity lands on the connection payload at
 * connect time (TwitchConnect strategy, the VimeoConnect pattern), and paying
 * a second credit per pull to re-read a name that just got fetched seconds
 * earlier would double the lane's cost for nothing. Consequence stated
 * honestly: a channel whose every VOD has expired answers a husk-shaped page
 * here, indistinguishable from NotFound, and reads as a vendor miss — the
 * connection degrades to Unavailable, never to "this channel is empty", which
 * is the only safe reading of a page that cannot prove the channel exists.
 *
 * ORDERING IS LOAD-BEARING, as in every billed driver: every check that can
 * refuse a run happens BEFORE the budget claim. Refusals before the claim
 * throw EffectNotAttempted (ledger claim deleted, digest free to retry);
 * after the claim only answered/noAnswer may leave. Budget rules are the
 * Item 8 contract verbatim: claim before the call, release on transport-null,
 * keep the slot spent on billed husks (NotFound bills with success:true —
 * shape, not HTTP).
 *
 * All VOD types ride (no filter_by): ARCHIVE past broadcasts — which Twitch
 * itself expires after 7-60 days — plus HIGHLIGHT and UPLOAD. The channel's
 * own videos page shows all three, and that page is what the watch pool
 * mirrors. Rows come back full-page (up to 100); the connector owns the
 * re-sort and the window cut, because ordering and pool size are its
 * contract, not a vendor observation.
 */
final class TwitchVendorDriver implements BilledEffectDriver
{
    private const SOURCE = 'twitch';

    /** Twitch's own login format — TwitchNormalizer's rule, same source. */
    private const LOGIN_PATTERN = '~^[a-z0-9_]{3,25}$~';

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'vendor' && $name === self::SOURCE;
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $login = strtolower(ltrim(trim((string) ($ctx->input['login'] ?? '')), '@'));
        if (preg_match(self::LOGIN_PATTERN, $login) !== 1) {
            // A malformed identifier can never resolve — refusing it as
            // noAnswer (not a throw) lets the digest settle it instead of
            // retrying a login that will be malformed tomorrow too.
            return BilledEffectResult::noAnswer('twitch vendor effect carried no usable login');
        }

        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            // No fallback lane exists for twitch — a missing key refuses the
            // run outright instead of failing it, so the digest may retry
            // once the key lands.
            throw new EffectNotAttempted('no ScrapeCreators key configured for the twitch vendor');
        }

        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim(self::SOURCE)) {
            throw new EffectNotAttempted("ScrapeCreators daily cap reached for source '".self::SOURCE."'");
        }

        $body = $client->get('/v1/twitch/user/videos', ['handle' => $login, 'sort_by' => 'TIME'], $ctx->userId);
        if ($body === null) {
            $budget->release(self::SOURCE);

            return BilledEffectResult::noAnswer('twitch videos call did not answer');
        }

        // From here the call was billed upstream — the slot stays spent.
        $rows = app(TwitchVideosNormalizer::class)->rows($body);
        if ($rows === null) {
            $this->log('twitch.vendor.unusable_shape', $ctx, ['endpoint' => 'user/videos']);

            return BilledEffectResult::noAnswer('twitch videos answer carried no usable VOD');
        }

        $this->log('twitch.vendor.ok', $ctx, ['rows' => count($rows)]);

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
