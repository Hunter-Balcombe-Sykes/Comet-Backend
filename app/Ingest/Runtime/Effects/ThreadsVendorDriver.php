<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\ThreadsPostsNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * ('vendor', 'threads') — the account's recent public posts into media-feed
 * rows (Item 10a, 2026-09-01). The Pinterest shape without the walk: threads
 * has no Apify actor at all, so the vendor kind is 'vendor', not 'actor', a
 * vendor miss is this driver's own noAnswer rather than someone else's
 * fall-through, and one run is exactly ONE billed call
 * (/v1/threads/user/posts).
 *
 * ORDERING IS LOAD-BEARING, as in every other billed driver: every check that
 * can refuse a run happens BEFORE the budget claim. Refusals before the claim
 * throw EffectNotAttempted (ledger claim deleted, digest free to retry);
 * after the claim only answered/noAnswer may leave. Budget rules are the
 * Item 8 contract verbatim: claim before the call, release on transport-null,
 * keep the slot spent on billed husks (NotFound bills with success:true —
 * shape, not HTTP).
 *
 * The rows leave here in ThreadsPostsNormalizer's synthesized contract —
 * owned `threads:` refs on every asset (the never-hot-link half of Item 10a),
 * replies dropped, a carousel folded to one row. Empty rows ⇒ noAnswer,
 * never answered([]) — a page of nothing-but-replies is indistinguishable
 * from a vendor miss, and settling it ok would serve "this account posts
 * nothing" for the whole freshness window.
 */
final class ThreadsVendorDriver implements BilledEffectDriver
{
    private const SOURCE = 'threads';

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'vendor' && $name === self::SOURCE;
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $handle = strtolower(ltrim(trim((string) ($ctx->input['username'] ?? '')), '@'));
        if ($handle === '') {
            return BilledEffectResult::noAnswer('threads vendor effect carried no identifier');
        }

        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            // No fallback lane exists for threads — a missing key refuses
            // the run outright instead of failing it, so the digest may retry
            // once the key lands.
            throw new EffectNotAttempted('no ScrapeCreators key configured for the threads vendor');
        }

        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim(self::SOURCE)) {
            throw new EffectNotAttempted("ScrapeCreators daily cap reached for source '".self::SOURCE."'");
        }

        $body = $client->get('/v1/threads/user/posts', ['handle' => $handle], $ctx->userId);
        if ($body === null) {
            $budget->release(self::SOURCE);

            return BilledEffectResult::noAnswer('threads posts call did not answer');
        }

        // From here the call was billed upstream — the slot stays spent.
        $rows = app(ThreadsPostsNormalizer::class)->rows($body);
        if ($rows === null) {
            $this->log('threads.vendor.unusable_shape', $ctx, ['endpoint' => 'user/posts']);

            return BilledEffectResult::noAnswer('threads posts answer carried no usable post');
        }

        $this->log('threads.vendor.ok', $ctx, ['rows' => count($rows)]);

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
