<?php

namespace App\Site\Pools;

use App\Models\Core\Site\Site;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * The public pools map — ONE hydration shared by the profile payload, the
 * action candidate set (App\Site\Actions\ActionCandidates) and the scoring
 * job, so "what the visitor sees", "what can be an action" and "what gets
 * scored" are the same list by construction. Extracted verbatim from
 * IndividualProfilePayloadBuilder::buildPools (2026-08-23).
 */
class PoolWire
{
    public function __construct(private readonly PoolResolver $pools) {}

    /**
     * Is this failure "the content lane does not exist here" rather than "a
     * query failed"? The two need opposite handling — see buildPools().
     *
     * 42P01 is Postgres's undefined_table. SQLite reports everything as HY000,
     * so the message is the only signal on the test lane; that arm is test-only
     * because production is always Postgres.
     */
    private function poolLaneAbsent(QueryException $e): bool
    {
        return $e->getCode() === '42P01'
            || str_contains($e->getMessage(), 'no such table');
    }

    /**
     * The content pools (platforms-as-sources, 2026-08-05): each pool's
     * public SELECTION — pins + every auto-source's rolling latest, minus
     * removals — resolved LIVE by the same PoolResolver the dashboard reads,
     * so what the owner curates and what a visitor sees cannot diverge. The
     * library never ships publicly; a pool with nothing selected is simply
     * absent. Wire: {watch|listen|media: {items, latestItemId}}; shop adds a
     * sibling `collections` map of the store cards its items belong to.
     *
     * @return array<string, array{items: list<array<string, mixed>>, latestItemId: string|null, collections?: array<string, array<string, mixed>>}>
     */
    /**
     * @param  SitepageDataResolverService  $resolver  the CALLER's resolver instance —
     *                                                 the degraded flag (markDegraded/hasDegraded) is per-instance and the
     *                                                 service is not a singleton, so the payload builder must hand in its
     *                                                 own so the controller sees the flag it set.
     */
    public function forSite(?Site $site, SitepageDataResolverService $resolver): array
    {
        if (! $site) {
            return [];
        }

        // plan → ONE shared hydrate → assemble (2026-08-24): resolving each
        // pool independently ran itemPayloads' ~20 facet queries nine times
        // over. The ids are planned per pool (cheap), hydrated once as a
        // union, and each pool assembles from the shared payload map — same
        // rows, same shapes, a fraction of the round trips. Measured on a
        // real site: 244 queries → ~60, and GET /site/actions' pools phase
        // from 58.8s to single-digit seconds on a high-latency link.
        // The shared pre-reads: one sections ensure, one curation query.
        // Failing here fails every pool at once, so it takes the same two
        // arms the shared hydrate below does.
        try {
            $sections = $this->pools->preloadSections($site, array_keys(PoolRegistry::POOLS));
            $curationBySection = $this->pools->preloadCuration($sections);
        } catch (QueryException $e) {
            if ($this->poolLaneAbsent($e)) {
                return [];
            }
            Log::warning('sitepage.pool_preload_failed', [
                'site_id' => $site->id,
                'user_id' => $site->user_id,
                'error' => $e->getMessage(),
            ]);
            $resolver->markDegraded();

            return [];
        }

        $plans = [];
        foreach (array_keys(PoolRegistry::POOLS) as $pool) {
            try {
                $section = $sections[$pool];
                $plans[$pool] = $this->pools->plan(
                    $site,
                    $pool,
                    $section,
                    $curationBySection[(string) $section->id] ?? collect(),
                );
            } catch (QueryException $e) {
                if ($this->poolLaneAbsent($e)) {
                    return [];
                }
                Log::warning('sitepage.pool_query_failed', [
                    'pool' => $pool,
                    'site_id' => $site->id,
                    'user_id' => $site->user_id,
                    'error' => $e->getMessage(),
                ]);
                $resolver->markDegraded();

                continue;
            }
        }

        // SCALE-2: selection ids only — this class's output never carries
        // `library` (see forSite()'s docblock: watch|listen|media items +
        // latestItemId, shop adds collections). Nine pools x LIBRARY_LIMIT
        // (500) each meant a public cache miss could hydrate up to 4,500 item
        // payloads through itemPayloads() only to throw every library one
        // away below. The dashboard/swap-picker library comes from a
        // DIFFERENT entry point (PoolController::show -> PoolResolver::resolve()),
        // which is untouched.
        $allIds = [];
        foreach ($plans as $plan) {
            array_push($allIds, ...$plan['selectionIds']);
        }

        try {
            [$payloads, $stores] = $this->pools->hydrateItems($site, array_values(array_unique($allIds)));
        } catch (QueryException $e) {
            if ($this->poolLaneAbsent($e)) {
                return [];
            }
            // ONE batch, one fate: the shared hydration failing takes the
            // whole lane down for this build rather than pool-by-pool — the
            // degraded flag puts both cache keys on the 10s TTL, so the page
            // heals seconds after the database does (#LIFE-6's contract,
            // coarser grain).
            Log::warning('sitepage.pool_hydration_failed', [
                'site_id' => $site->id,
                'user_id' => $site->user_id,
                'error' => $e->getMessage(),
            ]);
            $resolver->markDegraded();

            return [];
        }

        $out = [];
        foreach ($plans as $pool => $plan) {
            try {
                $resolved = $this->pools->assemble($site, $pool, $plan, $payloads, $stores, withLibrary: false);
            } catch (QueryException $e) {
                // TWO different failures used to share one `return []`, and
                // #LIFE-6 is only about the second of them.
                //
                // 1. THE LANE IS ABSENT. Partial test envs may not provision the
                //    content/sections tables (the getContentMedia precedent); in
                //    production they always exist. Still bail on the whole lane
                //    here, and deliberately NOT pool-by-pool: resolve()
                //    provisions a section row as a SIDE EFFECT, so continuing
                //    would mint a section for every pool while content.* does
                //    not exist — and other readers (the actions layer ->
                //    LinkPoolReader) are not guarded against that pairing and
                //    500 on it. "A missing lane yields no pools, never a 500" is
                //    the contract this branch has always kept. It is also not a
                //    degradation — the schema is missing, not unwell — so the
                //    flag stays off and the payload caches normally.
                if ($this->poolLaneAbsent($e)) {
                    return [];
                }

                // 2. A POOL QUERY FAILED (#LIFE-6, also #CCH-3 and #API-3 — one
                //    defect, three ids). This used to take the same `return []`,
                //    throwing away EVERY pool because one threw — including the
                //    ones already built above — and then letting that empty
                //    result cache for the full 60s payload TTL. Most likely to
                //    fire under database load, i.e. exactly when a page is
                //    popular. Now: drop THIS pool, keep the ones that resolved,
                //    and mark the build degraded so the controller rewrites both
                //    cache keys at the 10s degraded TTL and the page heals
                //    seconds after the database does.
                Log::warning('sitepage.pool_query_failed', [
                    'pool' => $pool,
                    'site_id' => $site->id,
                    'user_id' => $site->user_id,
                    'error' => $e->getMessage(),
                ]);
                $resolver->markDegraded();

                continue;
            }
            if ($resolved['selection'] === []) {
                continue;
            }
            $out[$pool] = [
                'items' => array_map(
                    // The dashboard-only flags stay off the public wire.
                    static function (array $item): array {
                        foreach (PoolResolver::DASHBOARD_ONLY_ITEM_KEYS as $key) {
                            unset($item[$key]);
                        }

                        return $item;
                    },
                    $resolved['selection'],
                ),
                'latestItemId' => $resolved['latestItemId'],
                // Shop groups its items into store cards; every other pool
                // returns [] and the key is simply absent from its payload.
                ...($resolved['collections'] === [] ? [] : ['collections' => $resolved['collections']]),
                // Slice 6 §5.4: reviews carries its source's aggregates — the
                // star average, review count and Google's review summary. This
                // is where `rating`, `reviewCount` and `reviewSummary` went
                // when they left PublicIntegrationConnectionResource; without
                // it that retirement drops three published fields on the floor.
                // Absent when null, the same contract `collections` keeps.
                ...($resolved['stats'] === null ? [] : ['stats' => $resolved['stats']]),
                // Slice 4 §7: the menus pool carries its vendor's service modes
                // (DELIVERY / PICKUP) — store-level metadata, so it sits beside
                // the dishes rather than on one. Absent when null, the same
                // additive contract `collections` and `stats` keep.
                ...($resolved['diningModes'] === null ? [] : ['diningModes' => $resolved['diningModes']]),
            ];
        }

        return $out;
    }
}
