<?php

namespace App\Site\Documents;

use App\Services\Content\SectionTracer;
use App\Site\Sections\SectionCandidates;
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
            : array_merge($pinned, app(SectionCandidates::class)->ruleCandidates($section, $pinned));

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
     * Re-export for historical readers ({@see SectionTracer},
     * SectionTraceTest). The executor — arms, facet map and this list —
     * moved whole to {@see SectionCandidates} on 2026-08-05 so the builder,
     * the tracer and the pools lane cannot drift.
     *
     * @var list<string>
     */
    public const EXECUTED_OPERATORS = SectionCandidates::EXECUTED_OPERATORS;

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
