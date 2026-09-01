<?php

namespace App\Ingest\Runtime\Effects;

use App\Ingest\Runtime\EffectNotAttempted;
use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\FacebookEventDetailsNormalizer;
use App\Services\Platforms\ScrapeCreators\FacebookEventsNormalizer;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use Illuminate\Support\Facades\Log;

/**
 * ('vendor', 'facebook_events') — an existing Facebook page connection's
 * upcoming events into schema.org-event docs (Item 11a, 2026-09-01). The
 * Pinterest pattern with the same two-endpoint shape: one discovery call
 * (/v1/facebook/profile/events — id-bearing stubs) then one details call per
 * upcoming stub (/v1/facebook/event/details — the doc the events pool lands).
 * ScrapeCreators-only: no Apify actor exists for FB events, so a vendor miss
 * is this driver's own noAnswer, never someone else's fall-through.
 *
 * ORDERING IS LOAD-BEARING, as in every billed driver: every check that can
 * refuse a run happens BEFORE the first budget claim. Refusals before a claim
 * throw EffectNotAttempted (ledger claim deleted, digest free to retry);
 * after the first claim only answered/noAnswer may leave.
 *
 * Budget rules are the Item 8 contract verbatim: claim before each call,
 * release on transport-null, keep the slot spent on billed husks (NotFound
 * bills with success:true — shape, not HTTP). A mid-walk failure keeps the
 * events already landed — paid detail docs are not discarded.
 *
 * `complete` in the answer is the connector's coverage input and errs closed:
 * true ONLY when the list page claims no further pages AND every enumerated
 * stub landed a details doc. A truncated walk (details_per_run, a cap-refused
 * claim, a transport miss, a husk) must never let the events stream claim
 * exhaustive coverage — Calendar + orderField means exhaustive TOMBSTONES,
 * and tombstoning a still-live gig because its details call missed once is
 * far worse than a stale one lingering (C5).
 *
 * Empty rows ⇒ noAnswer, never answered([]) — the profile-events husk
 * (success:true, events:[]) is indistinguishable from a vendor miss, and
 * settling it ok would serve "this venue has no events" for the whole
 * freshness window. A venue with genuinely nothing coming up looks exactly
 * the same, and that trade is deliberate: re-asking is one cheap list call.
 */
final class FacebookEventsVendorDriver implements BilledEffectDriver
{
    private const SOURCE = 'facebook_events';

    public function supports(string $kind, string $name): bool
    {
        return $kind === 'vendor' && $name === self::SOURCE;
    }

    public function run(BilledEffectContext $ctx): BilledEffectResult
    {
        $url = trim((string) ($ctx->input['url'] ?? ''));
        if (! preg_match('~^https?://~i', $url)) {
            return BilledEffectResult::noAnswer('facebook_events vendor effect carried no page url');
        }

        $client = app(ScrapeCreatorsClient::class);
        if (! $client->enabled()) {
            // No fallback lane exists for FB events — a missing key refuses
            // the run outright instead of failing it, so the digest may retry
            // once the key lands.
            throw new EffectNotAttempted('no ScrapeCreators key configured for the facebook_events vendor');
        }

        $budget = app(ScrapeCreatorsBudget::class);
        if (! $budget->tryClaim(self::SOURCE)) {
            throw new EffectNotAttempted("ScrapeCreators daily cap reached for source '".self::SOURCE."'");
        }

        $body = $client->get('/v1/facebook/profile/events', ['url' => $url], $ctx->userId);
        if ($body === null) {
            $budget->release(self::SOURCE);

            return BilledEffectResult::noAnswer('facebook profile-events call did not answer');
        }

        // From here the list call was billed upstream — the slot stays spent.
        $stubs = app(FacebookEventsNormalizer::class)->events($body);
        if ($stubs === null) {
            $this->log('facebook_events.vendor.unusable_shape', $ctx, ['endpoint' => 'profile/events']);

            return BilledEffectResult::noAnswer('facebook profile-events answer carried no upcoming id-bearing event');
        }

        [$rows, $walkComplete] = $this->detailRows($stubs, $client, $budget, $ctx);
        if ($rows === []) {
            return BilledEffectResult::noAnswer('facebook event details yielded no landable event doc');
        }

        $this->log('facebook_events.vendor.ok', $ctx, ['rows' => count($rows)]);

        return BilledEffectResult::answered([
            'complete' => $walkComplete && ($body['has_next_page'] ?? true) === false,
            'events' => $rows,
        ]);
    }

    /**
     * One details call per stub, each its own budget slot, vendor list order
     * (soonest-first on the recorded payload). Returns the landed rows plus
     * whether the walk covered EVERY stub — any break or per-stub miss makes
     * the walk incomplete, see the class docblock.
     *
     * @param  list<array<string, mixed>>  $stubs
     * @return array{0: list<array{key: string, doc: array<string, mixed>}>, 1: bool}
     */
    private function detailRows(array $stubs, ScrapeCreatorsClient $client, ScrapeCreatorsBudget $budget, BilledEffectContext $ctx): array
    {
        $cap = max(1, (int) config('partna.limits.scrapecreators.facebook_events.details_per_run', 8));

        $rows = [];
        $complete = true;

        foreach ($stubs as $i => $stub) {
            if ($i >= $cap) {
                $complete = false;
                break;
            }

            if (! $budget->tryClaim(self::SOURCE)) {
                $complete = false;
                break;
            }

            $body = $client->get('/v1/facebook/event/details', ['id' => (string) $stub['id']], $ctx->userId);
            if ($body === null) {
                $budget->release(self::SOURCE);
                $complete = false;
                break;
            }

            $doc = app(FacebookEventDetailsNormalizer::class)->doc($body);
            if ($doc === null) {
                // A billed husk, or an event cancelled between the list and
                // this call — the slot stays spent, the walk continues, and
                // completeness drops because we cannot tell those two apart
                // (a husk-missed live event must not tombstone).
                $this->log('facebook_events.vendor.unusable_shape', $ctx, ['endpoint' => 'event/details', 'event_id' => $stub['id']]);
                $complete = false;

                continue;
            }

            $rows[] = ['key' => (string) $stub['id'], 'doc' => $doc];
        }

        return [$rows, $complete];
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
