<?php

namespace App\Site\Sections;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE rule executor — the one place a section rule becomes SQL.
 *
 * Extracted 2026-08-05 (platforms-as-sources): DocumentBuilder owned this
 * privately, SectionTracer kept a "mirror" that executed two of the seven
 * operators (and filtered published_within on the wrong column), and the
 * pools lane was about to become a third copy. The tracer's own header
 * warns what happens next — the copies drift and the diagnostic lies about
 * the live page. One executor, three consumers: DocumentBuilder (the public
 * document), SectionTracer (the explanation), PoolResolver (the dashboard
 * and the live public payload).
 */
class SectionCandidates
{
    /** Mirrors DocumentBuilder::ruleCandidates()' historical bound. */
    public const CANDIDATE_SCAN_LIMIT = 200;

    /**
     * The rule operators {@see applyPredicate()} actually executes — the
     * single source of truth for "does this predicate narrow the list?".
     * DocumentBuilder re-exports it for its historical readers.
     *
     * A new RuleOperator case MUST be added here at the same time as its
     * match arm, or the trace will report it as applied while the builder
     * ignores it.
     *
     * @var list<string>
     */
    public const EXECUTED_OPERATORS = [
        'kind_is',
        'published_within',
        'has_facet',
        'from_source',
        'in_collection',
        'tagged_with',
        'has_action',
        'latest_per_auto_source',
        'upcoming_occurrence',
    ];

    /**
     * Facet names a has_facet rule may reference, each with the content table
     * that carries it. A closed map on purpose: rule JSON reaches this query
     * builder, and an open mapping would let a stored rule name arbitrary
     * tables. An off-map value matches NOTHING (an impossible ask must yield
     * an empty section, not a silently widened one).
     *
     * @var array<string, string>
     */
    private const FACET_TABLES = [
        'f_text' => 'content.f_text',
        'f_link' => 'content.f_link',
        'f_duration' => 'content.f_duration',
        'f_published' => 'content.f_published',
        'f_occurrence' => 'content.f_occurrence',
        'f_embed' => 'content.f_embed',
        'f_playable' => 'content.f_playable',
        'f_authored' => 'content.f_authored',
        'f_catalog' => 'content.f_catalog',
        'f_place' => 'content.f_place',
        'f_rated' => 'content.f_rated',
        'f_review' => 'content.f_review',
        'f_channel' => 'content.f_channel',
        'f_file' => 'content.f_file',
        'f_action' => 'content.f_action',
        'item_media' => 'content.item_media',
        'offers' => 'content.offers',
        'item_tags' => 'content.item_tags',
        'item_variants' => 'content.item_variants',
        'item_refs' => 'content.item_refs',
    ];

    /**
     * Resolve a section's rule to candidate item ids, newest-first (or
     * alphabetical), excluding what is already pinned. `$section` is either
     * the raw site.sections row (DocumentBuilder reads with DB::table) or the
     * Section model — both carry rule / site_id / order_by.
     *
     * @param  list<string>  $alreadyPinned
     * @return list<string>
     */
    public function ruleCandidates(object $section, array $alreadyPinned, int $limit = self::CANDIDATE_SCAN_LIMIT): array
    {
        $rawRule = $section->rule ?? '{}';
        $rule = is_array($rawRule) ? $rawRule : (json_decode((string) $rawRule, true) ?: []);
        $predicates = $rule['all'] ?? [];

        $query = DB::table('content.items')
            ->join('site.sites', 'site.sites.user_id', '=', 'content.items.user_id')
            ->where('site.sites.id', $section->site_id)
            ->whereNull('content.items.removed_at');

        foreach ($predicates as $predicate) {
            $this->applyPredicate($query, (array) $predicate);
        }

        $ordered = match ($section->order_by ?? 'recency') {
            'alphabetical' => $query->orderBy('content.items.headline_cache'),
            // Dated pools (events): soonest first, undated last. A correlated
            // MIN scalar — not a join — because f_occurrence is keyed
            // (item_id, source_id), so an item carried by two sources would
            // emit two candidate rows through a join.
            'occurrence' => $query->orderByRaw(
                '(SELECT MIN(fo.starts_at_utc) FROM content.f_occurrence fo'
                .' WHERE fo.item_id = content.items.id) ASC NULLS LAST'
            ),
            default => $query->orderByDesc('content.items.last_seen_at'),
        };

        return array_values($ordered
            ->whereNotIn('content.items.id', $alreadyPinned ?: ['00000000-0000-0000-0000-000000000000'])
            ->limit($limit)
            ->pluck('content.items.id')
            ->all());
    }

    /**
     * One clause of the bounded DSL — all NINE operators, each negatable.
     * Facet/source/collection/tag/action clauses are EXISTS subqueries against
     * the typed tables, so the rule reads live content rather than a cache. An
     * unknown operator is IGNORED (forward compatibility: an old builder must
     * not blank a section over a rule a newer validator accepted).
     *
     * @param  Builder  $query
     * @param  array<string, mixed>  $predicate
     */
    public function applyPredicate($query, array $predicate): void
    {
        $values = array_values(array_map('strval', (array) ($predicate['values'] ?? [])));
        $negated = (bool) ($predicate['not'] ?? false);

        match ($predicate['op'] ?? '') {
            'kind_is' => $negated
                ? $query->whereNotIn('content.items.kind', $values)
                : $query->whereIn('content.items.kind', $values),

            // Published within N days: the item's own published facet when
            // any source supplied one, first-seen otherwise — an item nothing
            // ever dated still counts as "new" while it is actually new.
            'published_within' => $query->where(function ($q) use ($values, $negated) {
                $cutoff = now()->subDays(max(1, (int) ($values[0] ?? 30)));
                $clause = function ($inner) use ($cutoff) {
                    $inner->where(fn ($w) => $w
                        ->whereExists(fn ($e) => $e->from('content.f_published')
                            ->whereColumn('content.f_published.item_id', 'content.items.id')
                            ->where('content.f_published.published_from', '>=', $cutoff))
                        ->orWhere(fn ($w2) => $w2
                            ->whereNotExists(fn ($e) => $e->from('content.f_published')
                                ->whereColumn('content.f_published.item_id', 'content.items.id')
                                ->whereNotNull('content.f_published.published_from'))
                            ->where('content.items.first_seen_at', '>=', $cutoff)));
                };
                $negated ? $q->whereNot($clause) : $q->where($clause);
            }),

            'has_facet' => $this->applyExists($query, $negated, function ($q) use ($values) {
                $tables = array_values(array_filter(array_map(
                    fn (string $facet) => self::FACET_TABLES[$facet] ?? null,
                    $values,
                )));
                if ($tables === []) {
                    // Every named facet is off-map: an impossible ask matches
                    // nothing — an empty OR-group would have matched everything.
                    $q->whereRaw('1 = 0');

                    return;
                }
                foreach ($tables as $table) {
                    $q->orWhereExists(fn ($e) => $e->from($table)
                        ->whereColumn("{$table}.item_id", 'content.items.id'));
                }
            }),

            // Values may be content.sources ids OR channel kinds
            // ('connection' | 'manual' | 'import') — presets speak in kinds,
            // the inspector pins exact sources.
            'from_source' => $this->applyExists($query, $negated, function ($q) use ($values) {
                $ids = array_values(array_filter($values, fn (string $v) => Str::isUuid($v)));
                $kinds = array_values(array_intersect($values, ['connection', 'manual', 'import']));

                $q->orWhereExists(function ($e) use ($ids, $kinds) {
                    $e->from('content.source_items')
                        ->join('content.sources', 'content.sources.id', '=', 'content.source_items.source_id')
                        ->whereColumn('content.source_items.item_id', 'content.items.id')
                        ->whereNull('content.source_items.removed_at')
                        ->where(function ($w) use ($ids, $kinds) {
                            if ($ids !== []) {
                                $w->orWhereIn('content.source_items.source_id', $ids);
                            }
                            if ($kinds !== []) {
                                $w->orWhereIn('content.sources.kind', $kinds);
                            }
                            if ($ids === [] && $kinds === []) {
                                $w->whereRaw('1 = 0');
                            }
                        });
                });
            }),

            // A collection id or its label — presets author by label before
            // the collection row exists to have an id.
            //
            // Slice 3b Task 9: removed_at is excluded here, on the JOIN's own
            // table rather than inside the id/label OR block, so both arms
            // are covered. An owner deleting a service category sets
            // collections.removed_at (ServiceCollections::remove — the only
            // writer); the memberships deliberately survive so a restore
            // brings the group back intact, which means this filter is the
            // only thing stopping items from still rendering through a group
            // that no longer exists. Inert until Task 9 shipped the first
            // writer of that column.
            'in_collection' => $this->applyExists($query, $negated, function ($q) use ($values) {
                $q->orWhereExists(function ($e) use ($values) {
                    $e->from('content.collection_items')
                        ->join('content.collections', 'content.collections.id', '=', 'content.collection_items.collection_id')
                        ->whereColumn('content.collection_items.item_id', 'content.items.id')
                        ->whereNull('content.collections.removed_at')
                        ->where(function ($w) use ($values) {
                            $ids = array_values(array_filter($values, fn (string $v) => Str::isUuid($v)));
                            if ($ids !== []) {
                                $w->orWhereIn('content.collection_items.collection_id', $ids);
                            }
                            $w->orWhereIn('content.collections.label', $values);
                        });
                });
            }),

            'tagged_with' => $this->applyExists($query, $negated, function ($q) use ($values) {
                $q->orWhereExists(fn ($e) => $e->from('content.item_tags')
                    ->whereColumn('content.item_tags.item_id', 'content.items.id')
                    ->whereIn('content.item_tags.tag', $values));
            }),

            'has_action' => $this->applyExists($query, $negated, function ($q) use ($values) {
                $q->orWhereExists(fn ($e) => $e->from('content.f_action')
                    ->whereColumn('content.f_action.item_id', 'content.items.id')
                    ->whereIn('content.f_action.intent', $values));
            }),

            // The auto half of a pool section (2026-08-05): the item is its
            // connection-source's NEWEST non-removed item among the given
            // kinds (its own kind when none given), and that connection has
            // auto_sync_latest on (sparse display_settings — absent = ON).
            // Read-time on purpose: no engine writes pins (C4), a newer item
            // wins the next resolve, and mixed-mode excludes apply AFTER
            // candidates — so excluding today's latest leaves nothing from
            // that source until something newer lands (owner semantics).
            'latest_per_auto_source' => $this->applyExists($query, $negated, function ($q) use ($values) {
                $q->orWhereExists(function ($e) use ($values) {
                    $e->from('content.source_items')
                        ->join('content.sources', 'content.sources.id', '=', 'content.source_items.source_id')
                        ->join('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
                        ->whereColumn('content.source_items.item_id', 'content.items.id')
                        ->whereNull('content.source_items.removed_at')
                        ->where('content.sources.kind', 'connection')
                        ->whereNull('site.platform_connections.deleted_at')
                        ->where('site.platform_connections.is_active', true)
                        // Sparse toggle: absent = ON, so only an explicit
                        // false turns a source off. The JSON grammar compiles
                        // per driver (->> on pg, json_extract on SQLite).
                        ->where(fn ($w) => $w
                            ->whereNull('site.platform_connections.display_settings->auto_sync_latest')
                            ->orWhere('site.platform_connections.display_settings->auto_sync_latest', '!=', false))
                        ->whereNotExists(function ($newer) use ($values) {
                            $newer->from('content.source_items as si2')
                                ->join('content.items as i2', 'i2.id', '=', 'si2.item_id')
                                ->leftJoin('content.f_published as p2', fn ($j) => $j
                                    ->on('p2.item_id', '=', 'i2.id')
                                    ->on('p2.source_id', '=', 'si2.source_id'))
                                ->leftJoin('content.f_published as p1', fn ($j) => $j
                                    ->on('p1.item_id', '=', 'content.items.id')
                                    ->on('p1.source_id', '=', 'content.source_items.source_id'))
                                ->whereColumn('si2.source_id', 'content.source_items.source_id')
                                ->whereNull('si2.removed_at')
                                ->whereNull('i2.removed_at')
                                ->whereColumn('i2.id', '!=', 'content.items.id');
                            $values === []
                                ? $newer->whereColumn('i2.kind', 'content.items.kind')
                                : $newer->whereIn('i2.kind', $values);
                            // "Newer": published when a source dated it,
                            // first-seen otherwise — same fallback the
                            // published_within arm uses. Ties break on id:
                            // a bulk first-ingest stamps one first_seen_at
                            // across a whole catalogue, and without a total
                            // order NOTHING is "newer", so EVERY item won
                            // (live smoke, ollies: 15 undated releases all
                            // wearing auto). Exactly one item must win a tie.
                            $newer->whereRaw(
                                'COALESCE(p2.published_from, i2.first_seen_at) > COALESCE(p1.published_from, content.items.first_seen_at)'
                                .' OR (COALESCE(p2.published_from, i2.first_seen_at) = COALESCE(p1.published_from, content.items.first_seen_at)'
                                .' AND i2.id > content.items.id)'
                            );
                        });
                });

                // THE STOREFRONT ARM (2026-08-17, shop opt-in). Shop products
                // hang off the user's MANUAL source (the identity spine), so
                // the connection-source arm above can never see them. A store
                // is a content.storefronts collection whose ANCHOR connection
                // (resource_id = external_ref, ShopConnections::anchor) holds
                // the same sparse auto_sync_latest toggle — this arm publishes
                // each live store's NEWEST catalogue row (collection_items
                // position 0, ShopFetch writes newest-first) while the toggle
                // is on. The individual-products bucket is excluded: those
                // are hand-adds the owner pins one by one, and "the newest
                // thing you pasted" is not a store's newest release.
                //
                // PRODUCT POOLS ONLY: the arm exists for shop, and adding
                // storefront joins to every latest_per_auto_source pool put
                // storefront SQL in the WATCH pool's query plan
                // (ShopPoolPayloadTest's query-isolation guard).
                if (in_array('product', $values, true)) {
                    $q->orWhereExists(function ($e) use ($values) {
                        $e->from('content.collection_items as sci')
                            ->join('content.collections as scol', 'scol.id', '=', 'sci.collection_id')
                            ->join('content.storefronts as sf', 'sf.collection_id', '=', 'scol.id')
                            ->join('site.platform_connections as spc', function ($j) {
                                $j->on('spc.resource_id', '=', 'sf.external_ref')
                                    ->whereColumn('spc.user_id', 'scol.user_id');
                            })
                            ->whereColumn('sci.item_id', 'content.items.id')
                            ->whereColumn('scol.user_id', 'content.items.user_id')
                            ->where('scol.kind', 'storefront')
                            ->where('sf.is_individual', false)
                            ->whereNull('spc.deleted_at')
                            ->where('spc.is_active', true)
                            ->where(fn ($w) => $w
                                ->whereNull('spc.display_settings->auto_sync_latest')
                                ->orWhere('spc.display_settings->auto_sync_latest', '!=', false))
                            // Newest = the smallest position in its own store:
                            // ShopFetch/syncStore write the catalogue newest-first.
                            ->whereNotExists(function ($newer) {
                                $newer->from('content.collection_items as sci2')
                                    ->join('content.items as si2', 'si2.id', '=', 'sci2.item_id')
                                    ->whereColumn('sci2.collection_id', 'sci.collection_id')
                                    ->whereNull('si2.removed_at')
                                    ->whereRaw('sci2.position < sci.position');
                            });
                        // Non-empty by construction — the in_array above only
                        // enters this arm when 'product' is present.
                        $e->whereIn('content.items.kind', $values);
                    });
                }
            }),

            // See RuleOperator::UpcomingOccurrence. `values` is ignored: kind
            // narrowing is kind_is' job and the pool rule always pairs the two.
            'upcoming_occurrence' => $this->applyExists($query, $negated, function ($q) {
                $q->orWhereExists(fn ($e) => $e->from('content.f_occurrence')
                    ->whereColumn('content.f_occurrence.item_id', 'content.items.id')
                    ->whereNotNull('content.f_occurrence.starts_at_utc')
                    ->where('content.f_occurrence.starts_at_utc', '>=', now()->subDay()));
            }),

            default => null,
        };
    }

    /**
     * EXISTS-style clauses share one negation shape: "matches any of the
     * values" or, negated, "matches none of them".
     *
     * @param  Builder  $query
     */
    private function applyExists($query, bool $negated, \Closure $orClauses): void
    {
        $negated
            ? $query->whereNot(fn ($q) => $q->where($orClauses))
            : $query->where($orClauses);
    }
}
