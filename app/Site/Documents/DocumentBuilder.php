<?php

namespace App\Site\Documents;

use App\Services\Content\SectionTracer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the ONE artefact the public site reads (plan §9).
 *
 * Protocol, in order, because each step protects the next:
 *   1. read the revision we are building FROM
 *   2. build the document
 *   3. hash it — byte-identical output inserts nothing and purges nothing,
 *      which is what makes a rebuild storm harmless
 *   4. commit under CAS — if content moved while we built, throw the result
 *      away rather than publish something already stale
 *
 * Display strings are resolved HERE, server-side. Nothing on the wire needs a
 * client to know what a platform is, and no relative timestamp is ever
 * emitted — a document cached for seven days must not silently start lying
 * about how long ago something happened.
 */
class DocumentBuilder
{
    /** Bump when the document SHAPE changes; triggers a fleet rebuild. */
    public const BUILDER_REVISION = 1;

    /**
     * @return array{status: string, version: ?int, hash: ?string}
     */
    public function build(string $siteId, string $channel = 'live'): array
    {
        $state = BuildState::read($siteId);
        $buildingFrom = $state['content_revision'];

        $document = $this->compose($siteId);
        $hash = hash('sha256', json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $current = DB::table('site.site_documents')
            ->where('site_id', $siteId)
            ->where('channel', $channel)
            ->orderByDesc('version')
            ->first();

        if ($current !== null && $current->content_hash === $hash && (int) $current->builder_revision === self::BUILDER_REVISION) {
            // Nothing actually changed. Mark ourselves caught up so the site
            // stops looking stale, but write no new version and purge no CDN.
            BuildState::commit($siteId, $buildingFrom);

            return ['status' => 'unchanged', 'version' => (int) $current->version, 'hash' => $hash];
        }

        $version = (int) ($current->version ?? 0) + 1;

        DB::table('site.site_documents')->insert([
            'id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'version' => $version,
            'channel' => $channel,
            'document' => json_encode($document),
            'content_hash' => $hash,
            'builder_revision' => self::BUILDER_REVISION,
            'warnings' => json_encode($document['warnings'] ?? []),
            'built_at' => now(),
        ]);

        if (! BuildState::commit($siteId, $buildingFrom)) {
            // Content moved underneath us. The version we just wrote is not
            // wrong, it is merely superseded — the caller rebuilds.
            return ['status' => 'superseded', 'version' => $version, 'hash' => $hash];
        }

        return ['status' => 'built', 'version' => $version, 'hash' => $hash];
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(string $siteId): array
    {
        $pages = DB::table('site.pages')
            ->where('site_id', $siteId)
            ->orderBy('sort_order')
            ->get();

        $sections = DB::table('site.sections')
            ->where('site_id', $siteId)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('page_id');

        $warnings = [];
        $composedPages = [];

        foreach ($pages as $page) {
            $pageSections = [];

            foreach ($sections->get($page->id, collect()) as $section) {
                $items = $this->resolveSection($section);

                // A section that resolves to nothing is not automatically a
                // problem — on_empty says what the user wanted to happen.
                if ($items === [] && $section->on_empty === 'hide') {
                    continue;
                }
                if (count($items) < (int) $section->min_items && $section->on_empty === 'hide') {
                    continue;
                }

                $pageSections[] = [
                    'id' => $section->id,
                    'key' => $section->key,
                    'label' => $section->label,
                    'slot' => $section->slot,
                    'kind' => $section->kind,
                    'render' => $section->render,
                    'density' => $section->density,
                    'groupBy' => $section->group_by,
                    'items' => $items,
                ];
            }

            if ($pageSections === [] && ! $page->is_hidden) {
                $warnings[] = ['code' => 'empty_page', 'page' => $page->key];
            }

            $composedPages[] = [
                'key' => $page->key,
                'label' => $page->label,
                'hidden' => (bool) $page->is_hidden,
                'sections' => $pageSections,
            ];
        }

        return [
            // Navigation is PAGES — one row each, never one per section.
            'navigation' => array_values(array_map(
                fn (array $p) => ['key' => $p['key'], 'label' => $p['label']],
                array_filter($composedPages, fn (array $p) => ! $p['hidden'] && $p['sections'] !== []),
            )),
            'pages' => $composedPages,
            'warnings' => $warnings,
            'builderRevision' => self::BUILDER_REVISION,
        ];
    }

    /**
     * Resolve one section's membership: rule candidates, minus excludes, with
     * pins forced to the front in their own order.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveSection(object $section): array
    {
        $curation = DB::table('site.section_items')
            ->where('section_id', $section->id)
            ->get();

        $pinned = $curation->where('state', 'pinned')->sortBy('sort_key')->pluck('item_id')->all();
        $excluded = $curation->where('state', 'excluded')->pluck('item_id')->flip();

        // Hand-picked sections are exactly their pins — no rule is consulted,
        // so a user who curated by hand never gets surprise additions.
        $candidateIds = $section->mode === 'hand_picked'
            ? $pinned
            : array_merge($pinned, $this->ruleCandidates($section, $pinned));

        $itemsById = $this->itemsById($candidateIds, (string) $section->site_id);

        $items = [];
        foreach ($candidateIds as $itemId) {
            if (isset($excluded[$itemId])) {
                continue;
            }
            $item = $this->itemPayload($itemsById[$itemId] ?? null);
            if ($item !== null) {
                $items[] = $item;
            }
            if ($section->limit_n !== null && count($items) >= (int) $section->limit_n) {
                break;
            }
        }

        return $items;
    }

    /**
     * One keyed fetch for every candidate a section might render, mirroring
     * {@see SectionTracer::itemsById()} on the same
     * table — the two drifted; bring this one back in line rather than
     * inventing a second pattern. Callers MUST keep iterating $candidateIds
     * and looking up in this map: iterating the map itself would silently
     * reorder pins-first ordering and drop duplicate candidate ids.
     *
     * SCOPED TO THE SITE'S OWNER, by the same join ruleCandidates() uses. Rule
     * candidates were already scoped; PINS WERE NOT, and a hand-picked section
     * consults no rule at all, so its membership was pinned ids and nothing
     * else. A pin naming a foreign item therefore rendered that item on this
     * public page. SectionItemController::findItem() is why the API cannot
     * write one today, but it is not the only writer: ItemMerger repoints pins
     * by item id, and any DB::table() fix or manual SQL carries no ownership
     * check (CLAUDE.md: a write path that bypasses Eloquent must invalidate
     * and validate for itself). This builder renders a public document, so it
     * scopes for itself rather than trusting every past and future writer.
     *
     * An out-of-scope id simply falls out of the map, and resolveSection()
     * already skips a null lookup WITHOUT spending a limit_n slot.
     *
     * Scoped by SUBQUERY rather than by ruleCandidates()' join. A join would
     * pull a second `id` into the row, and under `select *` the joined table's
     * id wins — keyBy('id') would key this map by SITE id and every lookup
     * would miss. Selecting content.items.* to avoid that is not portable:
     * Laravel quotes it "content"."items".*, and SQLite (the unit lane) cannot
     * parse a qualified star on an ATTACHed database. The subquery keeps one
     * round trip, one unambiguous column set, and reads as what it means.
     *
     * @param  list<string>  $ids
     * @return array<string, object>
     */
    private function itemsById(array $ids, string $siteId): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('content.items')
            ->whereIn('id', array_values(array_unique($ids)))
            ->whereIn('user_id', fn ($q) => $q
                ->from('site.sites')->select('user_id')->where('id', $siteId))
            ->get()
            ->keyBy('id')
            ->all();
    }

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
     * @param  list<string>  $alreadyPinned
     * @return list<string>
     */
    private function ruleCandidates(object $section, array $alreadyPinned): array
    {
        $rule = json_decode((string) $section->rule, true) ?: [];
        $predicates = $rule['all'] ?? [];

        $query = DB::table('content.items')
            ->join('site.sites', 'site.sites.user_id', '=', 'content.items.user_id')
            ->where('site.sites.id', $section->site_id)
            ->whereNull('content.items.removed_at');

        foreach ($predicates as $predicate) {
            $this->applyPredicate($query, (array) $predicate);
        }

        $ordered = match ($section->order_by) {
            'alphabetical' => $query->orderBy('content.items.headline_cache'),
            default => $query->orderByDesc('content.items.last_seen_at'),
        };

        return $ordered
            ->whereNotIn('content.items.id', $alreadyPinned ?: ['00000000-0000-0000-0000-000000000000'])
            ->limit(200)
            ->pluck('content.items.id')
            ->all();
    }

    /**
     * One clause of the bounded DSL — all SEVEN operators (plan §7/§8), each
     * negatable. Facet/source/collection/tag/action clauses are EXISTS
     * subqueries against the typed tables, so the rule reads live content
     * rather than a cache. An unknown operator is IGNORED (forward
     * compatibility: an old builder must not blank a section over a rule a
     * newer validator accepted).
     *
     * @param  Builder  $query
     * @param  array<string, mixed>  $predicate
     */
    private function applyPredicate($query, array $predicate): void
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
            'in_collection' => $this->applyExists($query, $negated, function ($q) use ($values) {
                $q->orWhereExists(function ($e) use ($values) {
                    $e->from('content.collection_items')
                        ->join('content.collections', 'content.collections.id', '=', 'content.collection_items.collection_id')
                        ->whereColumn('content.collection_items.item_id', 'content.items.id')
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

    /** @return array<string, mixed>|null */
    private function itemPayload(?object $item): ?array
    {
        if ($item === null || $item->removed_at !== null) {
            return null;
        }

        return [
            'id' => $item->id,
            'kind' => $item->kind,
            'headline' => $item->headline_cache,
        ];
    }
}
