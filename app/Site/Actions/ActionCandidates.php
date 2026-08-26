<?php

namespace App\Site\Actions;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Analytics\Concerns\EscalatesRepeatedFaults;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Site\Pools\PoolOrdering;
use App\Site\Pools\PoolWire;
use App\Support\UrlSafety;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The action candidate set for one site — everything that CAN occupy a
 * lander slot (spec §2). Four kinds:
 *
 *   page:<id>        the six destination-of-intent pages, when present
 *   platform:<key>   a connection whose platform is a public destination
 *                    (PlatformDescriptor::isDestination), or a SOURCE whose
 *                    granted page is absent (the fallback keeps Book reachable
 *                    while the services page is off)
 *   item:<uuid>      every item currently served on the sitepage (PoolWire),
 *                    except media — the gallery never produces an action (D1)
 *   category:<id>    a menu/services category block of served items
 *
 * Candidate = {id, kind, label, url, thumb, connectedAt, ref, meta}. Every url
 * has passed UrlSafety::safeHref; a candidate that can't is not a candidate.
 * Fail-open on the content lane: no items, never a 500.
 */
class ActionCandidates
{
    use EscalatesRepeatedFaults;

    public const PAGE_LABELS = [
        'services' => 'Book',
        'menu' => 'Menu',
        'shop' => 'Shop',
        'events' => 'Events',
        'contact' => 'Contact',
    ];

    /** Which page a SOURCE platform powers — by descriptor category, then by
     *  routing class. Reservations left both maps 2026-08-27: the page is
     *  gone, and reservation platforms are DESTINATIONS now (their bindings
     *  set the flag), so a visitor reserves at the platform's own URL. */
    private const CATEGORY_PAGE = [
        'booking' => 'services',
        'events' => 'events',
        'shop' => 'shop',
        'business' => 'contact',
    ];

    private const ROUTING_PAGE = [
        'booking' => 'services',
        'events' => 'events',
        'shop' => 'shop',
    ];

    /** Uncategorised (category-less) platforms that are destinations by routing class. */
    private const DESTINATION_ROUTING = ['ordering', 'social', 'content'];

    /**
     * Pool key => the sitepage the pool's items live on (item page anchors).
     * The media pool is NOT here (D1, 2026-08-23): media never produces an
     * action — no item: entries and no gallery category — it keeps its pool
     * smart order only.
     */
    public const POOL_PAGE = [
        'watch' => 'watch', 'listen' => 'listen', 'events' => 'events',
        'services' => 'services', 'shop' => 'shop', 'custom_links' => 'links', 'menus' => 'menu',
    ];

    public const CATEGORY_POOLS = ['menus', 'services'];

    public function __construct(
        private readonly SitepageDataResolverService $resolver,
        private readonly PlatformRegistry $registry,
        private readonly PoolWire $poolWire,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>|null  $pools  the PoolWire map when the caller already built it
     * @return list<array<string, mixed>>
     */
    public function forSite(User $pro, Site $site, ?Collection $sections = null, ?array $pools = null): array
    {
        $sections ??= $this->resolver->loadSections($site);
        $caps = AccountCapabilities::for($pro);
        $present = array_flip($this->resolver->presentPageIds($site, $caps, $sections));

        $connections = IntegrationConnection::query()
            ->where('user_id', $pro->id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'platform', 'surface_key', 'routing_class', 'resource_id', 'payload', 'created_at']);

        $pageNewest = [];
        $platforms = [];
        foreach ($connections as $conn) {
            $key = strtolower((string) $conn->platform);
            if ($key === '') {
                continue;
            }
            $descriptor = $this->registry->forConnection($conn);
            $category = $descriptor?->getCategory();
            $page = ($category !== null ? (self::CATEGORY_PAGE[$category->value] ?? null) : null)
                ?? self::ROUTING_PAGE[(string) $conn->routing_class] ?? null;
            if ($page !== null && (! isset($pageNewest[$page]) || $conn->created_at->gt($pageNewest[$page]))) {
                $pageNewest[$page] = $conn->created_at;
            }

            $destination = $descriptor !== null && $category !== null
                ? $descriptor->isDestination()
                : in_array((string) $conn->routing_class, self::DESTINATION_ROUTING, true);
            // Source fallback: the page it powers is absent, so its own URL is
            // the right place to send people until the page comes back.
            $fallback = ! $destination && $page !== null && ! isset($present[$page]);
            if ((! $destination && ! $fallback) || isset($platforms[$key])) {
                continue;
            }
            $url = ConnectionProfileUrl::for($conn);
            if ($url === null) {
                continue;
            }
            $platforms[$key] = [
                'id' => 'platform:'.$key,
                'kind' => 'platform',
                'label' => $descriptor?->getLabel() ?: ucfirst($key),
                'url' => $url,
                'thumb' => null,
                'connectedAt' => $conn->created_at->toIso8601String(),
                'ref' => null,
                'meta' => ['platformKey' => $key, 'page' => $page, 'fallback' => $fallback],
            ];
        }

        $out = [];
        foreach (self::PAGE_LABELS as $pageId => $label) {
            if (! isset($present[$pageId])) {
                continue;
            }
            $out[] = [
                'id' => 'page:'.$pageId,
                'kind' => 'page',
                'label' => $label,
                'url' => '/'.$pageId,
                'thumb' => null,
                'connectedAt' => ($pageNewest[$pageId] ?? $site->created_at)->toIso8601String(),
                'ref' => null,
                'meta' => ['pageId' => $pageId],
            ];
        }
        foreach ($platforms as $platform) {
            $out[] = $platform;
        }

        if ($pools === null) {
            try {
                $pools = $this->poolWire->forSite($site, $this->resolver);
            } catch (QueryException $e) {
                // #TEST-10: this used to swallow the fault with no log and no
                // report — a content-lane fault silently blanked every
                // pool-derived candidate (200 OK, content gone, nothing in
                // Nightwatch). Fail-open stays: the page renders with
                // whatever page:/platform: candidates it already built. A
                // SUSTAINED run escalates via the shared trait (CCH-11
                // precedent) instead of a single blip paging anyone.
                Log::warning('sitepage.action_candidates_pools_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
                self::escalateIfSustained($e, 'action_candidates_pools');
                $pools = [];
            }
        }
        foreach (self::fromPools($pools) as $candidate) {
            $out[] = $candidate;
        }

        return $out;
    }

    /**
     * Item + category candidates from the PoolWire map — pure, so "an entry
     * may only reference an item the payload serves" is structural. Reviews
     * never rank. Category homing delegates to PoolOrdering::homeCollection()
     * (#SEM-16, kept in sync with the item-feed branch by construction): a
     * dish belongs to the first served collection with a null provider (a
     * real menu/service category); provider-bearing collections (order-
     * platform sidecars) are only a fallback, used when no real category
     * matched, and a dish with NO matching collection at all floats as an
     * item.
     *
     * @param  array<string, array<string, mixed>>  $pools
     * @return list<array<string, mixed>>
     */
    public static function fromPools(array $pools): array
    {
        $out = [];
        foreach (self::POOL_PAGE as $pool => $page) {
            $items = $pools[$pool]['items'] ?? [];
            if (! is_array($items) || $items === []) {
                continue;
            }
            $collections = is_array($pools[$pool]['collections'] ?? null) ? $pools[$pool]['collections'] : [];
            if (! in_array($pool, self::CATEGORY_POOLS, true)) {
                foreach ($items as $item) {
                    if (($c = self::itemCandidate($pool, $page, $item)) !== null) {
                        $out[] = $c;
                    }
                }

                continue;
            }
            $grouped = [];
            foreach ($items as $item) {
                // #SEM-16: was a hand-copied duplicate of PoolOrdering::homeCollection()
                // that dropped the `$fallback ??= $cid` result on the floor — the two
                // paths homed the same sidecar-only item differently. Call the shared
                // helper instead of re-deriving it, so this can't drift again.
                $home = PoolOrdering::homeCollection($item, $collections);
                if ($home === null) {
                    if (($c = self::itemCandidate($pool, $page, $item)) !== null) {
                        $out[] = $c;
                    }
                } else {
                    $grouped[$home][] = $item;
                }
            }
            foreach ($grouped as $cid => $members) {
                $memberIds = [];
                $newest = null;
                $dated = false;
                $thumb = null;
                foreach ($members as $member) {
                    $memberIds[] = (string) ($member['id'] ?? '');
                    $dated = $dated || self::isDated($member);
                    $at = self::connectedAt($member);
                    if ($at !== null && ($newest === null || strcmp($at, $newest) > 0)) {
                        $newest = $at;
                    }
                    $thumb ??= is_string($member['thumbnail'] ?? null) && $member['thumbnail'] !== '' ? $member['thumbnail'] : null;
                }
                $out[] = [
                    'id' => 'category:'.$cid,
                    'kind' => 'category',
                    'label' => (string) ($collections[$cid]['name'] ?? 'Category'),
                    'url' => '/'.$page.'#'.$cid,
                    'thumb' => $thumb,
                    'connectedAt' => $newest,
                    'ref' => null,
                    'meta' => ['pool' => $pool, 'collectionId' => (string) $cid, 'itemIds' => $memberIds, 'undated' => ! $dated],
                ];
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $item */
    private static function itemCandidate(string $pool, string $page, array $item): ?array
    {
        $id = (string) ($item['id'] ?? '');
        if ($id === '' || ! ActionId::isValid('item:'.$id)) {
            return null;
        }
        $outbound = is_string($item['url'] ?? null) && $item['url'] !== '' ? UrlSafety::safeHref($item['url']) : null;
        if (is_string($item['url'] ?? null) && $item['url'] !== '' && $outbound === null) {
            return null; // an unsafe stored url is not a candidate
        }
        $label = trim((string) ($item['headline'] ?? ''));

        $meta = ['pool' => $pool, 'undated' => ! self::isDated($item)];
        if (is_string($item['startsAt'] ?? null) && $item['startsAt'] !== '') {
            // The occurrence date rides along so SectorActionRecipes can
            // resolve the next-event role against the calendar, not the
            // sync order.
            $meta['startsAt'] = $item['startsAt'];
        }

        return [
            'id' => 'item:'.$id,
            'kind' => 'item',
            'label' => $label !== '' ? $label : 'Untitled',
            'url' => $outbound ?? '/'.$page.'#'.$id,
            'thumb' => is_string($item['thumbnail'] ?? null) && $item['thumbnail'] !== '' ? $item['thumbnail'] : null,
            'connectedAt' => self::connectedAt($item),
            'ref' => ['pool' => $pool, 'itemId' => $id],
            // undated: the timestamp is when WE first saw it, not a release
            // date (X5) — newest order puts every dated candidate first.
            'meta' => $meta,
        ];
    }

    /**
     * Dated = carries a real publishedAt, OR an occurrence date (an event's
     * own start date IS its date — real ingested events carry f_occurrence
     * and never f_published, so publishedAt-only dating left every real
     * event undated and the latest-event recipe role dead; critic find,
     * 2026-08-27), OR is a link-pool item — those are hand-added by
     * definition, so "first seen" IS the add date. Every other item without
     * one of those is undated (X5: synced-but-undated content never
     * outranks dated content on the strength of when we saw it).
     *
     * @param  array<string, mixed>  $item
     */
    private static function isDated(array $item): bool
    {
        return (is_string($item['publishedAt'] ?? null) && $item['publishedAt'] !== '')
            || (is_string($item['startsAt'] ?? null) && $item['startsAt'] !== '')
            || ($item['kind'] ?? null) === 'link';
    }

    /** @param  array<string, mixed>  $item */
    private static function connectedAt(array $item): ?string
    {
        // An event's date is its occurrence, then the generic publish/seen
        // ladder — same precedence isDated() implies.
        $at = $item['publishedAt'] ?? $item['startsAt'] ?? $item['firstSeenAt'] ?? null;

        return is_string($at) && $at !== '' ? $at : null;
    }
}
