<?php

namespace App\Services\Analytics;

use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Time-relevance boost for the event_item family (smart-scoring plan,
 * 2026-08-27) — the event analogue of ContentFreshness, with the shape an
 * event actually has: relevance PEAKS at the event date and collapses after,
 * instead of decaying from publishedAt.
 *
 *   upcoming:  boost = w · 2^(−days_until / half_life)
 *   past:      boost = w · PAST_WEIGHT · 2^(−days_since / half_life)
 *
 * aged from content.f_occurrence.starts_at_utc (falling back to
 * starts_at_local read as UTC when the utc column is null). w and the
 * half-life come from config `pools.smart.event_item`
 * (relevance / relevance_half_life_days); PAST_WEIGHT asymmetry is what
 * keeps a sold-out gig from outranking next week's show the morning after.
 *
 * Consumed by ComputeContentPopularityScores exactly like ContentFreshness:
 * merged into the additive-boost map, zero-signal keys seeded so an upcoming
 * event ranks before its first click.
 */
class EventTimeRelevance
{
    /** A finished event keeps a quarter of its would-be relevance. */
    private const PAST_WEIGHT = 0.25;

    // Below this the boost is noise; skipping keeps zero-signal seeding bounded.
    private const MIN_BOOST = 0.05;

    /**
     * event_item boosts for one site: item id => boost, for every event with
     * a live (>= MIN_BOOST) relevance.
     *
     * @return array<string, float>
     */
    public function boostsForSite(Site $site): array
    {
        $weights = (array) config('partna.pools.smart.event_item', []);
        $w = (float) ($weights['relevance'] ?? 3.0);
        $halfLife = (float) ($weights['relevance_half_life_days'] ?? 7.0);
        if ($w <= 0.0) {
            return [];
        }

        try {
            $rows = DB::connection('pgsql')->table('content.items as i')
                ->join('content.f_occurrence as fo', 'fo.item_id', '=', 'i.id')
                ->where('i.user_id', $site->user_id)
                ->where('i.kind', 'event')
                ->whereNull('i.removed_at')
                ->get(['i.id', 'fo.starts_at_utc', 'fo.starts_at_local']);
        } catch (QueryException) {
            // content.* lane absent (partial test envs) — no boosts.
            return [];
        }

        $now = now();
        $out = [];
        foreach ($rows as $row) {
            $at = $row->starts_at_utc ?? $row->starts_at_local;
            if ($at === null || $at === '') {
                continue;
            }
            $boost = $this->boost($w, $halfLife, Carbon::parse((string) $at), $now);
            if ($boost !== null) {
                $out[(string) $row->id] = $boost;
            }
        }

        return $out;
    }

    /** Peaked-at-the-date boost, or null once it has faded below MIN_BOOST. */
    private function boost(float $weight, float $halfLifeDays, Carbon $startsAt, Carbon $now): ?float
    {
        $deltaDays = ($startsAt->getTimestamp() - $now->getTimestamp()) / 86400.0;
        $boost = $deltaDays >= 0.0
            ? $weight * 2 ** (-$deltaDays / $halfLifeDays)
            : $weight * self::PAST_WEIGHT * 2 ** ($deltaDays / $halfLifeDays);

        return $boost >= self::MIN_BOOST ? $boost : null;
    }
}
