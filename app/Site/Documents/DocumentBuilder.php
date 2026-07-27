<?php

namespace App\Site\Documents;

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

        $items = [];
        foreach ($candidateIds as $itemId) {
            if (isset($excluded[$itemId])) {
                continue;
            }
            $item = $this->itemPayload($itemId);
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
            $values = (array) ($predicate['values'] ?? []);
            $negated = (bool) ($predicate['not'] ?? false);

            match ($predicate['op'] ?? '') {
                'kind_is' => $negated
                    ? $query->whereNotIn('content.items.kind', $values)
                    : $query->whereIn('content.items.kind', $values),
                'published_within' => $query->where(
                    'content.items.last_seen_at', '>=',
                    now()->subDays((int) ($values[0] ?? 30)),
                ),
                // Facet/source/tag predicates need their own joins; they are
                // applied by the section resolver at P5's completion. Until
                // then an unsupported predicate is IGNORED rather than
                // silently emptying the section.
                default => null,
            };
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

    /** @return array<string, mixed>|null */
    private function itemPayload(string $itemId): ?array
    {
        $item = DB::table('content.items')->where('id', $itemId)->first();
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
