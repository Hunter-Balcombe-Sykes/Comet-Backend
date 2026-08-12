<?php

namespace App\Site\Pools;

use App\Models\Core\Site\Site;
use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Media\MediaUrlResolver;
use App\Site\Sections\SectionCandidates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONE pool read — live, no document cache (owner chose Option B:
 * "always as live as is possible"). The dashboard's pool page and the
 * public payload both call this, so what the owner curates and what a
 * visitor sees cannot be two different resolutions.
 *
 * Selection semantics (the pool contract, 2026-08-05):
 *   pins (hand-picks, drag order)  +  rule candidates (each auto-source's
 *   newest item)  −  excludes (removals). An excluded current-latest yields
 *   NOTHING from that source until something newer lands.
 *
 * Item payloads are render-ready: headline (manual override wins), primary
 * link + platform, creator, published, duration, cover thumbnail, the dated /
 * located / priced facets (null off events), the public URL slug + its 301
 * aliases, and the full per-platform link set (synced source links +
 * hand-saved item_links).
 * popularityRank is null until watch_item/listen_item beacons compute —
 * the wire carries the field so the shape doesn't change under the FE.
 */
class PoolResolver
{
    private const LIBRARY_LIMIT = 500;

    public function __construct(
        private readonly PoolSectionProvisioner $provisioner,
        private readonly SectionCandidates $candidates,
        private readonly ContentItemSlugAllocator $slugs,
        private readonly MediaUrlResolver $mediaUrls,
    ) {}

    /**
     * Whether this pool's resolved selection has at least one item — the
     * page-presence probe (presence-via-pools, 2026-08-06). Same pins →
     * rule-candidates → excludes arithmetic as resolve(), without hydrating
     * a single payload, so the payload builder's presence gate can afford
     * to ask per pool.
     */
    public function hasSelection(Site $site, string $pool): bool
    {
        $section = $this->provisioner->ensure($site, $pool);

        $curation = DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->get();

        $excluded = $curation->where('state', 'excluded')
            ->pluck('item_id')->flip()->all();
        $pinned = $curation->where('state', 'pinned')
            ->sortBy('sort_key')->pluck('item_id')->values()->all();

        foreach ($pinned as $itemId) {
            if (! isset($excluded[$itemId])) {
                return true;
            }
        }
        foreach ($this->candidates->ruleCandidates($section, $pinned) as $itemId) {
            if (! isset($excluded[$itemId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   selection: list<array<string, mixed>>,
     *   library: list<array<string, mixed>>,
     *   latestItemId: string|null,
     * }
     */
    public function resolve(Site $site, string $pool): array
    {
        $section = $this->provisioner->ensure($site, $pool);

        $curation = DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->get();

        $pinned = $curation->where('state', 'pinned')
            ->sortBy('sort_key')->pluck('item_id')->values()->all();
        $excluded = $curation->where('state', 'excluded')
            ->pluck('item_id')->flip()->all();

        $ruleIds = $this->candidates->ruleCandidates($section, $pinned);
        $autoSet = array_flip($ruleIds);

        $selectionIds = [];
        foreach ([...$pinned, ...$ruleIds] as $itemId) {
            if (! isset($excluded[$itemId])) {
                $selectionIds[] = $itemId;
            }
        }

        $libraryIds = DB::connection('pgsql')->table('content.items')
            ->where('user_id', $site->user_id)
            ->whereIn('kind', PoolRegistry::kinds($pool))
            ->whereNull('removed_at')
            ->orderByDesc('last_seen_at')
            ->limit(self::LIBRARY_LIMIT)
            ->pluck('id')
            ->all();

        $payloads = $this->itemPayloads(
            $site,
            array_values(array_unique([...$selectionIds, ...$libraryIds])),
        );

        $selectedSet = array_flip($selectionIds);

        $selection = [];
        foreach ($selectionIds as $itemId) {
            if (! isset($payloads[$itemId])) {
                continue;
            }
            $selection[] = [
                ...$payloads[$itemId],
                'selected' => true,
                'origin' => isset($autoSet[$itemId]) && ! in_array($itemId, $pinned, true)
                    ? 'auto'
                    : 'manual',
            ];
        }

        $library = [];
        foreach ($libraryIds as $itemId) {
            if (! isset($payloads[$itemId])) {
                continue;
            }
            $library[] = [
                ...$payloads[$itemId],
                'selected' => isset($selectedSet[$itemId]),
                'origin' => isset($autoSet[$itemId]) && isset($selectedSet[$itemId]) && ! in_array($itemId, $pinned, true)
                    ? 'auto'
                    : 'manual',
            ];
        }

        return [
            'selection' => $selection,
            'library' => $library,
            'latestItemId' => PoolRegistry::carriesLatestTag($pool)
                ? $this->latestItemId($selection)
                : null,
        ];
    }

    /**
     * The single Latest tag (owner): whichever SELECTED item was most
     * recently released — published date, first-seen when nothing dated it.
     *
     * @param  list<array<string, mixed>>  $selection
     */
    private function latestItemId(array $selection): ?string
    {
        $latest = null;
        $latestAt = null;
        foreach ($selection as $item) {
            $at = $item['publishedAt'] ?? $item['firstSeenAt'] ?? null;
            if ($at !== null && ($latestAt === null || $at > $latestAt)) {
                $latestAt = $at;
                $latest = $item['id'];
            }
        }

        return $latest;
    }

    /**
     * Render-ready payloads for a set of items, owner-scoped, one query per
     * facet table — never one per item.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function itemPayloads(Site $site, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $items = DB::connection('pgsql')->table('content.items')
            ->whereIn('id', $ids)
            ->where('user_id', $site->user_id)
            ->whereNull('removed_at')
            ->get()
            ->keyBy('id');

        $ids = $items->keys()->all();
        if ($ids === []) {
            return [];
        }

        // Headline overrides: the user's edit beats every cache.
        $overrides = DB::connection('pgsql')->table('content.manual_overrides')
            ->whereIn('item_id', $ids)
            ->where('facet', 'f_text')
            ->where('column_name', 'headline')
            ->pluck('value', 'item_id')
            ->map(fn ($v) => is_string($v) ? json_decode($v, true) : $v);

        // Source links, each carrying its connection's platform key. Ordered
        // by source priority so ->first() per item IS the primary source.
        $sourceLinks = DB::connection('pgsql')->table('content.f_link')
            ->join('content.sources', 'content.sources.id', '=', 'content.f_link.source_id')
            ->leftJoin('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->whereIn('content.f_link.item_id', $ids)
            ->orderByDesc('content.sources.priority')
            ->get([
                'content.f_link.item_id',
                'content.f_link.url',
                'content.sources.kind as source_kind',
                'site.platform_connections.platform as platform',
            ])
            ->groupBy('item_id');

        $manualLinks = DB::connection('pgsql')->table('content.item_links')
            ->whereIn('item_id', $ids)
            ->get(['item_id', 'platform', 'url'])
            ->groupBy('item_id');

        $published = DB::connection('pgsql')->table('content.f_published')
            ->whereIn('item_id', $ids)
            ->whereNotNull('published_from')
            ->selectRaw('item_id, MAX(published_from) as published_from')
            ->groupBy('item_id')
            ->pluck('published_from', 'item_id');

        $durations = DB::connection('pgsql')->table('content.f_duration')
            ->whereIn('item_id', $ids)
            ->whereNotNull('seconds')
            ->selectRaw('item_id, MAX(seconds) as seconds')
            ->groupBy('item_id')
            ->pluck('seconds', 'item_id');

        // Soonest occurrence per item, aggregated in SQL. A collection keyed
        // by item_id would be LAST-row-wins, which is the opposite of what
        // the section's MIN(starts_at_utc) ordering does.
        $occursAt = DB::connection('pgsql')->table('content.f_occurrence')
            ->whereIn('item_id', $ids)
            ->whereNotNull('starts_at_utc')
            ->selectRaw('item_id, MIN(starts_at_utc) as starts_at_utc')
            ->groupBy('item_id')
            ->pluck('starts_at_utc', 'item_id');

        // The local/venue detail belongs to whichever source supplied the
        // soonest time; ordering DESC and letting keyBy overwrite leaves the
        // EARLIEST row in the map.
        $occurrenceDetail = DB::connection('pgsql')->table('content.f_occurrence')
            ->whereIn('item_id', $ids)
            ->whereNotNull('starts_at_utc')
            ->orderByDesc('starts_at_utc')
            ->get(['item_id', 'starts_at_local', 'ends_at_local', 'timezone'])
            ->keyBy('item_id');

        // Ordered for the same reason the occurrence detail is: keyBy keeps
        // the LAST row, and an unordered fetch would let two sources describing
        // one venue flip the published address between reads. Freshest wins.
        $places = DB::connection('pgsql')->table('content.f_place')
            ->whereIn('item_id', $ids)
            ->orderBy('updated_at')
            ->get(['item_id', 'venue_name', 'locality'])
            ->keyBy('item_id');

        // Cheapest offer per item — the scrape sees the lowest tier and the
        // projector stamps qualifier='from' to say so. Ordered DESC so the
        // cheapest row is written LAST and survives keyBy's overwrite.
        $offers = DB::connection('pgsql')->table('content.offers')
            ->whereIn('item_id', $ids)
            ->orderByRaw('amount_minor IS NULL DESC, amount_minor DESC')
            ->get(['item_id', 'amount_minor', 'amount_max_minor', 'currency', 'qualifier', 'availability'])
            ->keyBy('item_id');

        // Public URL slugs. The legacy events lane served these off
        // site.item_slugs onto the integrations wire; retiring it moves the
        // duty here, so a slug-less item must degrade exactly as that lane did
        // — null slug, raw id as the sole alias — rather than 404 a permalink.
        $slugMap = $this->slugs->lookupCurrent((string) $site->user_id, $ids);

        $creators = DB::connection('pgsql')->table('content.f_authored')
            ->whereIn('item_id', $ids)
            ->whereNotNull('creator')
            ->get(['item_id', 'creator'])
            ->keyBy('item_id');

        $channels = DB::connection('pgsql')->table('content.f_channel')
            ->whereIn('item_id', $ids)
            ->whereNotNull('handle')
            ->get(['item_id', 'handle'])
            ->keyBy('item_id');

        $coverRows = DB::connection('pgsql')->table('content.item_media')
            ->join('content.media_assets', 'content.media_assets.id', '=', 'content.item_media.asset_id')
            ->whereIn('content.item_media.item_id', $ids)
            ->whereIn('content.item_media.role', ['cover', 'poster', 'gallery'])
            ->orderBy('content.item_media.position')
            ->get([
                'content.item_media.item_id',
                'content.item_media.role',
                'content.item_media.alt_text',
                'content.media_assets.id as asset_id',
                'content.media_assets.source_url',
                'content.media_assets.storage_path',
                'content.media_assets.site_media_id',
                'content.media_assets.width',
                'content.media_assets.height',
            ]);

        // ONE resolver call for the page — MediaUrlResolver batches its
        // variant lookup, and this sits on the public hot path.
        $resolvedUrls = $this->mediaUrls->resolve(
            $coverRows->map(fn (object $row): object => (object) [
                'id' => $row->asset_id,
                'source_url' => $row->source_url,
                'storage_path' => $row->storage_path,
                'site_media_id' => $row->site_media_id,
                'width' => $row->width,
                'height' => $row->height,
            ])
        );

        $covers = $coverRows->groupBy('item_id');

        $out = [];
        foreach ($items as $itemId => $item) {
            $links = $this->linkSet(
                $sourceLinks->get($itemId, collect()),
                $manualLinks->get($itemId, collect()),
            );
            $primary = $links[0] ?? null;

            $overrideHeadline = $overrides[$itemId] ?? null;

            $out[$itemId] = [
                'id' => (string) $itemId,
                'kind' => $item->kind,
                'slug' => $slugMap[$itemId]['slug'] ?? null,
                'aliases' => $slugMap[$itemId]['aliases'] ?? [(string) $itemId],
                'headline' => is_string($overrideHeadline) && $overrideHeadline !== ''
                    ? $overrideHeadline
                    : $item->headline_cache,
                'headlineEdited' => is_string($overrideHeadline) && $overrideHeadline !== '',
                'url' => $primary['url'] ?? null,
                'platform' => $primary['platform'] ?? null,
                'creator' => $creators[$itemId]->creator ?? $channels[$itemId]->handle ?? null,
                'publishedAt' => $published[$itemId] ?? null,
                'firstSeenAt' => $item->first_seen_at,
                'durationSeconds' => isset($durations[$itemId]) ? (int) $durations[$itemId] : null,
                'thumbnail' => $this->cover($covers->get($itemId, collect()), $resolvedUrls),
                // Slice 1a §3.5: media items ship every frame (positional);
                // every other kind ships [] — the wire shape does not change
                // with kind, same contract startsAt/venue/price follow.
                'frames' => $item->kind === 'media'
                    ? $this->frames($covers->get($itemId, collect()), $resolvedUrls)
                    : [],
                // Dated / located / priced facets. Present on every pool item
                // and null off events, so the wire shape does not change with
                // kind — same contract durationSeconds already has.
                'startsAt' => $occursAt[$itemId] ?? null,
                'startsAtLocal' => $occurrenceDetail[$itemId]->starts_at_local ?? null,
                'endsAtLocal' => $occurrenceDetail[$itemId]->ends_at_local ?? null,
                'timezone' => $occurrenceDetail[$itemId]->timezone ?? null,
                'venue' => $places[$itemId]->venue_name ?? null,
                'locality' => $places[$itemId]->locality ?? null,
                'price' => isset($offers[$itemId]) ? [
                    'amountMinor' => $offers[$itemId]->amount_minor === null ? null : (int) $offers[$itemId]->amount_minor,
                    'amountMaxMinor' => $offers[$itemId]->amount_max_minor === null ? null : (int) $offers[$itemId]->amount_max_minor,
                    'currency' => $offers[$itemId]->currency,
                    'qualifier' => $offers[$itemId]->qualifier,
                ] : null,
                // Deliberately the CHEAPEST offer's availability, not the
                // event's: it qualifies the price beside it, so "from $6.61 /
                // sold_out" reads as "that tier is gone". An event-level
                // rollup would need a rank over availability values and would
                // then disagree with the price it sits next to.
                'availability' => $offers[$itemId]->availability ?? null,
                'links' => $links,
                'popularityRank' => null,
            ];
        }

        return $out;
    }

    /**
     * The per-platform link set: synced source links first (priority order),
     * then the hand-saved item_links for platforms no source covers. One
     * entry per platform; a synced link always beats a manual one for the
     * same platform — the sync cannot drift, the hand-typed URL can.
     *
     * @return list<array{platform: string|null, url: string, source: string}>
     */
    private function linkSet(Collection $sourceRows, Collection $manualRows): array
    {
        $links = [];
        $seen = [];

        foreach ($sourceRows as $row) {
            $platform = $row->platform !== null ? (string) $row->platform : null;
            if ($platform !== null && isset($seen[$platform])) {
                continue;
            }
            if ($platform !== null) {
                $seen[$platform] = true;
            }
            $links[] = ['platform' => $platform, 'url' => (string) $row->url, 'source' => 'synced'];
        }

        foreach ($manualRows as $row) {
            if (isset($seen[$row->platform])) {
                continue;
            }
            $seen[$row->platform] = true;
            $links[] = ['platform' => (string) $row->platform, 'url' => (string) $row->url, 'source' => 'manual'];
        }

        return $links;
    }

    /**
     * Prefer the cover, then poster, then any gallery frame — ROLE priority,
     * not positional order (frames() is the positional view). Same firstWhere
     * semantics as before slice 1a; only the URL source moved from raw
     * source_url to the resolver seam.
     *
     * @param  array<string, array{url: string, width: int|null, height: int|null}>  $resolved
     */
    private function cover(Collection $rows, array $resolved): ?string
    {
        foreach (['cover', 'poster', 'gallery'] as $role) {
            $row = $rows->firstWhere('role', $role);
            $url = $row !== null ? ($resolved[(string) $row->asset_id]['url'] ?? null) : null;
            if ($url !== null && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * Every servable frame, in item_media.position order. An asset that
     * resolves to no URL is OMITTED, never emitted as null — the unrenderable
     * ref-only Google assets degrade to an empty gallery (spec §3.5).
     *
     * @param  array<string, array{url: string, width: int|null, height: int|null}>  $resolved
     * @return list<array{url: string, width: int|null, height: int|null, role: string, alt: string|null}>
     */
    private function frames(Collection $rows, array $resolved): array
    {
        $frames = [];
        foreach ($rows as $row) {
            $hit = $resolved[(string) $row->asset_id] ?? null;
            if ($hit === null) {
                continue;
            }
            $frames[] = [
                'url' => $hit['url'],
                'width' => $hit['width'],
                'height' => $hit['height'],
                'role' => (string) $row->role,
                'alt' => $row->alt_text === null ? null : (string) $row->alt_text,
            ];
        }

        return $frames;
    }
}
