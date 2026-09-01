<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\PinterestBoardsNormalizer;
use App\Services\Platforms\ScrapeCreators\PinterestPinsNormalizer;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use Illuminate\Support\Facades\Log;

/**
 * ('vendor', 'pinterest') — boards + pins into media-pool candidate rows
 * (Item 10a, 2026-09-01). The first ScrapeCreators-ONLY billed effect: unlike
 * the Item 8 lanes there is no Apify actor behind it, so the vendor kind is
 * 'vendor', not 'actor', and a vendor miss is this driver's own noAnswer
 * rather than someone else's fall-through.
 *
 * ORDERING IS LOAD-BEARING, as in every other billed driver: every check that
 * can refuse a run happens BEFORE the first budget claim. Refusals before a
 * claim throw EffectNotAttempted (ledger claim deleted, digest free to
 * retry); after the first claim only answered/noAnswer may leave.
 *
 * Two endpoints per run, each call one budget slot: the board list first
 * (discovery), then pins per public board in the vendor's board order (board
 * recency) up to boards_per_run. Budget rules are the Item 8 contract
 * verbatim: claim before the call, release on transport-null, keep the slot
 * spent on billed husks (NotFound bills with success:true — shape, not HTTP).
 * A mid-walk failure keeps the boards already landed — paid pins are not
 * discarded.
 *
 * No re-sort and no synthesized dates: board pins carry created_at:null
 * (recorded 2026-09-01), so the answered rows keep curation order and the
 * pins stream can never delete (orderField null, Sample profile).
 *
 * Empty rows ⇒ noAnswer, never answered([]) — an account with no public
 * image-bearing boards is indistinguishable from a vendor miss, and settling
 * it ok would serve "this account has nothing" for the whole freshness
 * window.
 */
final class PinterestVendorDriver implements BilledEffectDriver
{
    private const SOURCE = 'pinterest';

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'vendor' && $name === self::SOURCE;
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $handle = strtolower(ltrim(trim((string) ($ctx->input['username'] ?? '')), '@'));
        if ($handle === '') {
            return BilledEffectResult::noAnswer('pinterest vendor effect carried no identifier');
        }

        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            // No fallback lane exists for pinterest — a missing key refuses
            // the run outright instead of failing it, so the digest may retry
            // once the key lands.
            throw new EffectNotAttempted('no ScrapeCreators key configured for the pinterest vendor');
        }

        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim(self::SOURCE)) {
            throw new EffectNotAttempted("ScrapeCreators daily cap reached for source '".self::SOURCE."'");
        }

        $body = $client->get('/v1/pinterest/user/boards', ['handle' => $handle], $ctx->userId);
        if ($body === null) {
            $budget->release(self::SOURCE);

            return BilledEffectResult::noAnswer('pinterest boards call did not answer');
        }

        // From here the boards call was billed upstream — the slot stays spent.
        $boards = app(PinterestBoardsNormalizer::class)->rows($body);
        if ($boards === null) {
            $this->log('pinterest.vendor.unusable_shape', $ctx, ['endpoint' => 'boards']);

            return BilledEffectResult::noAnswer('pinterest boards answer carried no usable public board');
        }

        $rows = $this->pinRows($boards, $client, $budget, $ctx);
        if ($rows === []) {
            return BilledEffectResult::noAnswer('pinterest boards yielded no image-bearing pins');
        }

        $this->log('pinterest.vendor.ok', $ctx, ['rows' => count($rows)]);

        return BilledEffectResult::answered($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $boards
     * @return list<array<string, mixed>>
     */
    private function pinRows(array $boards, ScrapeCreatorsClient $client, ScrapeCreatorsBudget $budget, BilledEffectContext $ctx): array
    {
        $boardCap = max(1, (int) config('partna.limits.scrapecreators.pinterest.boards_per_run', 3));
        $limit = max(1, (int) config('partna.limits.scrapecreators.pinterest.results_limit', 30));

        /** @var array<string, array<string, mixed>> $rows keyed by pin id — a repin can sit on several boards */
        $rows = [];
        $fetched = 0;

        foreach ($boards as $board) {
            if ($fetched >= $boardCap || count($rows) >= $limit) {
                break;
            }
            if ((int) $board['pin_count'] < 1) {
                continue;
            }

            if (! $budget->tryClaim(self::SOURCE)) {
                break;
            }
            $fetched++;

            $body = $client->get('/v1/pinterest/board', ['url' => $board['url']], $ctx->userId);
            if ($body === null) {
                $budget->release(self::SOURCE);
                break;
            }

            $pageRows = app(PinterestPinsNormalizer::class)->rows($body);
            if ($pageRows === null) {
                $this->log('pinterest.vendor.unusable_shape', $ctx, ['endpoint' => 'board', 'board_id' => $board['id']]);
                break;
            }

            foreach ($pageRows as $row) {
                // The board list's name wins over the pin's embedded board
                // stub — same value in practice, but the list row is the one
                // the privacy gate already vetted.
                $rows[(string) $row['id']] ??= array_replace($row, [
                    'board_id' => $board['id'],
                    'board_name' => $board['name'],
                ]);
            }
        }

        return array_slice(array_values($rows), 0, $limit);
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
