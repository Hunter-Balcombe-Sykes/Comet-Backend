<?php

namespace App\Services\Analytics;

use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Freshness boost — an ADDITIVE, decaying score term for newly-added pool
 * links, so a new link surfaces before it has earned engagement (cold
 * start), then fades back to pure engagement ranking over ~a month.
 *
 *   boost = W_ITEM · 2^(-age_days / HALF_LIFE_DAYS)
 *
 * The page family this used to carry (W_PAGE, PLATFORM_TO_PAGE) was retired
 * 2026-08-23: pages are actions now and get their freshness from
 * ActionScorer (connectedAt → 2^(-age/14), same half-life), not from here.
 *
 *   - link_item : each pool link keys on content.items.id — every item
 *                 family is keyed by item id (2026-08-23).
 * NOT freshness-eligible: shop_product / listen_item / watch_item (no stable
 * per-item created_at — documented, not half-built).
 *
 * Consumed by ComputeContentPopularityScores (zero-signal keys are seeded so a
 * brand-new link ranks at all) and surfaced by DevInsightsController.
 */
class ContentFreshness
{
    public const HALF_LIFE_DAYS = 14.0;

    // A brand-new ITEM starts at +3 — exactly one click's head start.
    public const W_ITEM = 3.0;

    // Below this the boost is noise; skipping keeps zero-signal seeding bounded.
    private const MIN_BOOST = 0.05;

    /**
     * Freshness boosts for one site, keyed like the scoring job's content grains.
     *
     * @return array{link_item: array<string, float>}
     */
    public function boostsForSite(Site $site): array
    {
        $now = now();
        $linkItems = [];

        try {
            DB::connection('pgsql')->table('content.items as i')
                ->join('content.f_link as fl', 'fl.item_id', '=', 'i.id')
                ->where('i.user_id', $site->user_id)
                ->where('i.kind', 'link')
                ->whereNull('i.removed_at')
                ->get(['i.id', 'i.created_at'])
                ->each(function ($row) use (&$linkItems): void {
                    $linkItems[(string) $row->id] = Carbon::parse($row->created_at);
                });
        } catch (QueryException) {
            // content.* lane absent (partial test envs) — no link boosts.
        }

        $links = [];
        foreach ($linkItems as $itemId => $createdAt) {
            $boost = $this->boost(self::W_ITEM, $createdAt, $now);
            if ($boost !== null) {
                $links[$itemId] = $boost;
            }
        }

        return ['link_item' => $links];
    }

    /** Decayed boost, or null once it has faded below MIN_BOOST. */
    private function boost(float $weight, Carbon $createdAt, Carbon $now): ?float
    {
        $ageDays = max(0.0, $now->getTimestamp() - $createdAt->getTimestamp()) / 86400.0;
        $boost = $weight * 2 ** (-$ageDays / self::HALF_LIFE_DAYS);

        return $boost >= self::MIN_BOOST ? $boost : null;
    }
}
