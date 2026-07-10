<?php

namespace App\Services\PublicSite;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Analytics\RankedActionsComputer;
use Illuminate\Support\Collection;

/**
 * Unified site ACTIONS — the one ranked list the ONE lander renders its CTA
 * buttons from. An action is {kind: page|item|button} + a kind-local ref:
 *   - page   : ref = taxonomy page-id (home excluded — the lander IS home)
 *   - item   : ref = "<itemType>:<itemKey>" (keys match content_popularity_scores)
 *   - button : ref = platform slug ('booking' = the general booking link)
 *
 * This service owns the POOL (what actions exist right now) and the wire
 * resolution (ordering the pool by stored action ranks, or by the owner's
 * manual list when smart_actions is off). The SCORING lives in
 * RankedActionsComputer and runs inside analytics:compute-popularity —
 * existing page/item scores are consumed, never recomputed here.
 *
 * Consumed by IndividualProfilePayloadBuilder (public payload `rankedActions`
 * + `ordering`), UserSiteActionsController (dashboard picker data) and
 * ComputeContentPopularityScores (pool for the scoring run).
 */
class SiteActionsService
{
    /**
     * Scored item_type → the sitepage hosting it. Item actions deep-link to
     * this page; types absent here (e.g. legacy 'block') are not actionable.
     * Inverse-consistent with ComputeContentPopularityScores' click taxonomy.
     *
     * @var array<string, string>
     */
    public const ITEM_TYPE_TO_PAGE = [
        'shop_product' => 'shop',
        'service' => 'book',
        'engine_item' => 'events',
        'listen_item' => 'listen',
        'watch_item' => 'watch',
        'link_item' => 'links',
        'gallery_item' => 'gallery',
        'menu_item' => 'menu',
        'menu_category' => 'menu',
    ];

    // Top N items per item_type enter the pool (by stored popularity rank) —
    // keeps the ranked list CTA-sized instead of drowning it in catalog rows.
    private const ITEMS_PER_TYPE = 2;

    public function __construct(
        private readonly SitepageDataResolverService $resolver,
        private readonly ContentPopularityReader $popularity,
    ) {}

    /**
     * Enumerate the site's live action pool. Sections/booking/ranks are
     * injectable so callers that already loaded them (payload builder, the
     * scoring command) don't re-query; when omitted they're loaded here.
     *
     * Pool entry shape (internal — toWire() projects the public subset):
     *   {kind, ref, label: ?string, url: ?string, pageId: ?string,
     *    itemType: ?string, itemKey: ?string,
     *    clickPlatforms: list<string>,  // button-only: link_clicks.platform values that count as this button's clicks
     *    createdAt: ?string}            // button-only: recency anchor
     *
     * @param  Collection<string, Block>|null  $sections
     * @param  array{state: string, data: array|null}|null  $booking
     * @param  array<string, array<string, int>>|null  $ranks  content_type => content_key => rank
     * @return list<array<string, mixed>>
     */
    public function pool(User $pro, ?Site $site, ?Collection $sections = null, ?array $booking = null, ?array $ranks = null): array
    {
        if ($site === null) {
            return [];
        }

        $sections ??= $this->resolver->loadSections($site);
        $booking ??= $this->resolver->getBooking($site, $sections);
        $ranks ??= $this->popularity->forSite($site->id);

        $caps = AccountCapabilities::for($pro);
        $present = $this->resolver->presentPageIds($site, $caps, $sections);

        return array_merge(
            $this->pageActions($present),
            $this->buttonActions($site, $sections, $booking),
            $this->itemActions($site, $present, $ranks),
        );
    }

    /**
     * Every present page except home becomes a page action (the lander IS the
     * home page — a "go home" CTA is noise).
     *
     * @param  list<string>  $present
     * @return list<array<string, mixed>>
     */
    private function pageActions(array $present): array
    {
        $out = [];
        foreach ($present as $page) {
            if ($page === 'home') {
                continue;
            }
            $out[] = $this->entry('page', $page, label: ucfirst($page), pageId: $page);
        }

        return $out;
    }

    /**
     * Platform-level CTAs derived from the payload's existing links source
     * (link blocks + the synthesised booking row). Rules:
     *   - category 'booking' → ref 'booking' (the general booking link).
     *   - platform-tagged rows → ref = platform slug (instagram, youtube, ...).
     *   - category 'custom' rows are EXCLUDED — they're scored as link_item items.
     *   - dedupe by ref, first row (sort_order) wins.
     *
     * clickPlatforms lists the link_clicks.platform values whose clicks count
     * as this button's native signal (booking also matches its underlying
     * platform, e.g. fresha). createdAt anchors the recency term.
     *
     * @param  Collection<string, Block>  $sections
     * @param  array{state: string, data: array|null}  $booking
     * @return list<array<string, mixed>>
     */
    private function buttonActions(Site $site, Collection $sections, array $booking): array
    {
        $links = $this->resolver->getLinks($site, $booking);
        if ($links === []) {
            return [];
        }

        // created_at per link block (getLinks projects it away) — one pluck.
        $createdAt = Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'links')
            ->whereNull('deleted_at')
            ->pluck('created_at', 'id');

        $out = [];
        $seen = [];
        foreach ($links as $link) {
            $category = (string) ($link['category'] ?? 'custom');
            $platform = is_string($link['platform'] ?? null) ? $link['platform'] : null;
            $isBooking = $category === 'booking';

            if (! $isBooking && ($platform === null || $category === 'custom')) {
                continue;
            }

            $ref = $isBooking ? 'booking' : $platform;
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;

            // Booking is synthesised (no block row) — its recency anchor is the
            // booking section block's created_at.
            $anchor = $isBooking
                ? $sections->get('booking')?->created_at?->toISOString()
                : (($id = (string) ($link['id'] ?? '')) !== '' ? ($createdAt[$id] ?? null) : null);

            $out[] = $this->entry(
                'button',
                (string) $ref,
                label: (string) ($link['title'] ?? ''),
                url: (string) ($link['url'] ?? ''),
                clickPlatforms: array_values(array_unique(array_filter([
                    $isBooking ? 'booking' : null,
                    $platform,
                ]))),
                createdAt: is_string($anchor) || $anchor === null ? $anchor : (string) $anchor,
            );
        }

        return $out;
    }

    /**
     * Top-ranked scored items (per type) whose hosting page is present. Labels
     * are best-effort: services + link/gallery items resolve here; platform-
     * sourced items (shop/listen/watch/events) emit label null — the sitepage
     * app resolves those from its own resolved content by (itemType, itemKey)
     * and MUST skip entries it cannot resolve.
     *
     * @param  list<string>  $present
     * @param  array<string, array<string, int>>  $ranks
     * @return list<array<string, mixed>>
     */
    private function itemActions(Site $site, array $present, array $ranks): array
    {
        $presentSet = array_flip($present);

        $picked = [];
        foreach (self::ITEM_TYPE_TO_PAGE as $type => $page) {
            if (! isset($presentSet[$page]) || ! isset($ranks[$type]) || $ranks[$type] === []) {
                continue;
            }
            $typeRanks = $ranks[$type];
            asort($typeRanks);
            foreach (array_slice(array_keys($typeRanks), 0, self::ITEMS_PER_TYPE) as $key) {
                $picked[] = ['type' => $type, 'key' => (string) $key, 'page' => $page];
            }
        }

        if ($picked === []) {
            return [];
        }

        $labels = $this->itemLabels($site, $picked);

        $out = [];
        foreach ($picked as $item) {
            $out[] = $this->entry(
                'item',
                $item['type'].':'.$item['key'],
                label: $labels[$item['type']][$item['key']] ?? null,
                pageId: $item['page'],
                itemType: $item['type'],
                itemKey: $item['key'],
            );
        }

        return $out;
    }

    /**
     * Backend-resolvable item labels, keyed [itemType][itemKey]. Services by
     * id; gallery items by media caption/alt; link items by matching the live
     * links list on block id or destination url.
     *
     * @param  list<array{type: string, key: string, page: string}>  $picked
     * @return array<string, array<string, string>>
     */
    private function itemLabels(Site $site, array $picked): array
    {
        $byType = [];
        foreach ($picked as $item) {
            $byType[$item['type']][] = $item['key'];
        }

        $labels = [];

        if (! empty($byType['service'])) {
            $titles = Service::query()
                ->whereIn('id', $byType['service'])
                ->pluck('title', 'id');
            foreach ($titles as $id => $title) {
                if (is_string($title) && trim($title) !== '') {
                    $labels['service'][(string) $id] = trim($title);
                }
            }
        }

        if (! empty($byType['gallery_item'])) {
            $media = SiteMedia::query()
                ->where('site_id', $site->id)
                ->whereIn('id', $byType['gallery_item'])
                ->get(['id', 'caption', 'alt_text']);
            foreach ($media as $row) {
                $label = trim((string) ($row->caption ?? '')) !== ''
                    ? trim((string) $row->caption)
                    : trim((string) ($row->alt_text ?? ''));
                if ($label !== '') {
                    $labels['gallery_item'][(string) $row->id] = $label;
                }
            }
        }

        if (! empty($byType['link_item'])) {
            // Theme beacons key link items by block id or destination url —
            // match either against the live link blocks.
            $rows = Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->whereNull('deleted_at')
                ->get(['id', 'title', 'url']);
            foreach ($byType['link_item'] as $key) {
                foreach ($rows as $row) {
                    $title = trim((string) ($row->title ?? ''));
                    if ($title !== '' && ((string) $row->id === $key || (string) $row->url === $key)) {
                        $labels['link_item'][$key] = $title;
                        break;
                    }
                }
            }
        }

        return $labels;
    }

    // ── Wire resolution ─────────────────────────────────────────────────

    /**
     * The final ordered action list the lander renders (top 6 of). Smart mode:
     * stored action ranks resolved against the live pool (stale keys dropped),
     * then not-yet-scored pool entries appended by prior (a just-connected
     * platform shows up before the next 15-min compute tick, score null).
     * Manual mode: the owner's list resolved in order — unknown refs dropped
     * (curation semantics, nothing appended), customs passed through.
     *
     * @param  list<array<string, mixed>>  $pool
     * @param  list<array{key: string, score: float, rank: int}>  $stored
     * @param  list<array<string, mixed>>  $manualActions
     * @return list<array<string, mixed>>
     */
    public function resolveRankedActions(array $pool, array $stored, bool $smartActions, array $manualActions): array
    {
        $byKey = [];
        foreach ($pool as $entry) {
            $byKey[$entry['kind'].':'.$entry['ref']] = $entry;
        }

        if (! $smartActions) {
            $out = [];
            foreach ($manualActions as $manual) {
                if (! is_array($manual)) {
                    continue;
                }
                if (($manual['kind'] ?? null) === 'custom') {
                    $label = trim((string) ($manual['label'] ?? ''));
                    $url = trim((string) ($manual['url'] ?? ''));
                    if ($label === '' || $url === '') {
                        continue;
                    }
                    $out[] = [
                        'kind' => 'custom', 'ref' => null, 'label' => $label, 'url' => $url,
                        'pageId' => null, 'itemType' => null, 'itemKey' => null, 'score' => null,
                    ];

                    continue;
                }
                $entry = $byKey[($manual['kind'] ?? '').':'.($manual['ref'] ?? '')] ?? null;
                if ($entry !== null) {
                    $out[] = $this->toWire($entry);
                }
            }

            return $out;
        }

        $out = [];
        $used = [];
        foreach ($stored as $row) {
            $entry = $byKey[$row['key']] ?? null;
            if ($entry === null) {
                continue; // stale stored key — no longer in the pool
            }
            $used[$row['key']] = true;
            $out[] = $this->toWire($entry, $row['score']);
        }

        // Cold-path append: pool entries the scoring job hasn't stored yet,
        // prior-ordered (deterministic ref tiebreak).
        $rest = array_values(array_filter(
            $pool,
            static fn (array $entry): bool => ! isset($used[$entry['kind'].':'.$entry['ref']]),
        ));
        usort($rest, static function (array $a, array $b): int {
            $cmp = RankedActionsComputer::priorFor($b) <=> RankedActionsComputer::priorFor($a);

            return $cmp !== 0 ? $cmp : strcmp((string) $a['ref'], (string) $b['ref']);
        });
        foreach ($rest as $entry) {
            $out[] = $this->toWire($entry);
        }

        return $out;
    }

    /**
     * Public wire projection of a pool entry (drops internal clickPlatforms /
     * createdAt). score: blended comparable score, null = not yet scored.
     *
     * @param  array<string, mixed>  $entry
     * @return array{kind: string, ref: string, label: string|null, url: string|null, pageId: string|null, itemType: string|null, itemKey: string|null, score: float|null}
     */
    public function toWire(array $entry, ?float $score = null): array
    {
        $label = is_string($entry['label'] ?? null) ? trim((string) $entry['label']) : '';
        $url = is_string($entry['url'] ?? null) ? trim((string) $entry['url']) : '';

        return [
            'kind' => (string) $entry['kind'],
            'ref' => (string) $entry['ref'],
            'label' => $label !== '' ? $label : null,
            'url' => $url !== '' ? $url : null,
            'pageId' => $entry['pageId'] ?? null,
            'itemType' => $entry['itemType'] ?? null,
            'itemKey' => $entry['itemKey'] ?? null,
            'score' => $score !== null ? round($score, 4) : null,
        ];
    }

    // ── Ordering settings ───────────────────────────────────────────────

    /**
     * The four ordering preferences from site.sites.settings with defaults
     * applied (absent = smart). Shape mirrors the stored snake_case keys.
     *
     * @return array{smart_page_order: bool, manual_page_order: list<string>, smart_actions: bool, manual_actions: list<array<string, mixed>>}
     */
    public function orderingSettings(?Site $site): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];

        return [
            'smart_page_order' => filter_var($settings['smart_page_order'] ?? true, FILTER_VALIDATE_BOOL),
            'manual_page_order' => array_values(array_filter(
                is_array($settings['manual_page_order'] ?? null) ? $settings['manual_page_order'] : [],
                'is_string',
            )),
            'smart_actions' => filter_var($settings['smart_actions'] ?? true, FILTER_VALIDATE_BOOL),
            'manual_actions' => array_values(array_filter(
                is_array($settings['manual_actions'] ?? null) ? $settings['manual_actions'] : [],
                'is_array',
            )),
        ];
    }

    /**
     * camelCase wire projection of orderingSettings() for the public payload
     * + the dashboard picker endpoint.
     *
     * @param  array{smart_page_order: bool, manual_page_order: list<string>, smart_actions: bool, manual_actions: list<array<string, mixed>>}  $settings
     * @return array{smartPageOrder: bool, manualPageOrder: list<string>, smartActions: bool, manualActions: list<array<string, mixed>>}
     */
    public function orderingWire(array $settings): array
    {
        return [
            'smartPageOrder' => $settings['smart_page_order'],
            'manualPageOrder' => $settings['manual_page_order'],
            'smartActions' => $settings['smart_actions'],
            'manualActions' => $settings['manual_actions'],
        ];
    }

    /**
     * Manual page order applied against the LIVE present pages: manual ∩
     * present in manual order (unknown/absent ids dropped), then present pages
     * missing from the manual list appended in canonical order — every present
     * page stays reachable.
     *
     * @param  list<string>  $present  presence-gated canonical page-ids
     * @param  list<string>  $manual  the stored manual_page_order
     * @return list<string>
     */
    public function applyManualPageOrder(array $present, array $manual): array
    {
        $presentSet = array_flip($present);

        $ordered = [];
        foreach ($manual as $page) {
            if (isset($presentSet[$page]) && ! in_array($page, $ordered, true)) {
                $ordered[] = $page;
            }
        }
        foreach ($present as $page) {
            if (! in_array($page, $ordered, true)) {
                $ordered[] = $page;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $clickPlatforms
     * @return array<string, mixed>
     */
    private function entry(
        string $kind,
        string $ref,
        ?string $label = null,
        ?string $url = null,
        ?string $pageId = null,
        ?string $itemType = null,
        ?string $itemKey = null,
        array $clickPlatforms = [],
        ?string $createdAt = null,
    ): array {
        return [
            'kind' => $kind,
            'ref' => $ref,
            'label' => $label,
            'url' => $url,
            'pageId' => $pageId,
            'itemType' => $itemType,
            'itemKey' => $itemKey,
            'clickPlatforms' => $clickPlatforms,
            'createdAt' => $createdAt,
        ];
    }
}
