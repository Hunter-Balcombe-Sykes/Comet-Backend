<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuPlatformLink;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Content\ManualMenuItems;
use App\Services\Content\ManualMenuWriter;
use App\Services\Notifications\Dispatchers\PlatformHealthNotifier;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuPayloadComposer;
use App\Services\Platforms\MenuProjectionMapper;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\MenuSource;
use App\Services\Platforms\NormalizesMenuItemNames;
use App\Site\Pools\PoolRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Fetches (or refreshes) a user's menu from their connected online-ordering
// platforms. Slice 7 Task 7: the merged result lands in `content.*` through
// ManualMenuWriter (an upsert keyed on MenuProjectionMapper's name-derived
// coord); `site.menus` + `site.menu_platform_links` survive and keep the
// menu-level bookkeeping (fetch status, dining modes, scan_items,
// suppressed_items, per-platform store URL + sync status). The scrape itself,
// MenuMerger, MenuApifyScraper, the rate limiter and every cost control below
// are untouched — spec D1: the ingest connectors cover 0 of the 5 real menus
// and are all auto_sync=false, so retiring this job would stop automatic menu
// refresh entirely.
//
// Each connected platform (Uber Eats and/or DoorDash) is scraped
// once; MenuMerger UNIONs them — every dish from every platform appears. Uber
// Eats structure is canonical and wins display-field ties (gap-filling from
// DoorDash where UE lacks a value); DoorDash adds per-item ratings/badges. Each
// dish records the platforms it's available on (price + supported modes + order
// url per platform), and the aggregate pickup/delivery prices derive from those.
//
// Dispatched on every online-ordering change (add / remove / forget + the Google
// Business ordering seed) and by the manual "Refresh menu" button. Cost control:
// the scrape runs ONLY when a store URL changed (or $force) or the last fetch
// wasn't ok — re-deriving links on an unrelated change is free. When the user has
// no Uber Eats / DoorDash link at all, the menu row is soft-deleted (cleared).
class MenuFetchJob implements ShouldBeUnique, ShouldQueue, ThrottledByProvider
{
    use Dispatchable, InteractsWithQueue, NormalizesMenuItemNames, Queueable, SerializesModels;

    // Up to two real store scrapes (UE + DD), each retried on empty; allow
    // headroom for MAX_ATTEMPTS × ATTEMPT_TIMEOUT per platform in MenuApifyScraper.
    public int $timeout = 600;

    // Unlimited attempts, bounded by retryUntil() below — the 'platform-connect'
    // RateLimited middleware RELEASES this job when the menu actor is over-limit, and
    // every release counts as an attempt. A finite $tries would mass-fail scrapes
    // during a burst the gate exists to absorb. Genuine errors stay capped by
    // $maxExceptions, so a broken scrape still fails fast.
    public int $tries = 0;

    /** @var list<int> Backoff between exception-triggered retries (not rate-limit releases). */
    public array $backoff = [30, 120];

    public int $maxExceptions = 2;

    // One menu fetch per user at a time. The window matches retryUntil() so a job
    // parked in rate-limit purgatory can't have a duplicate slip in and bill a second
    // run. The lock also releases on completion/failure — this is the worst-case backstop.
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $userId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    /** Apify actor for the 'platform-connect' rate budget. */
    public function providerRateKey(): string
    {
        return 'menu';
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('platform-connect')];
    }

    // Wall-clock deadline for rate-limit releases. A menu scrape held behind the actor's
    // per-minute limit keeps retrying until it runs or 30 min elapses (headroom over the
    // 600s job timeout), then lapses to failed() (terminal) — never an infinite park.
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(MenuSource $source, MenuApifyScraper $scraper, MenuMerger $merger): void
    {
        $plan = $source->resolveAll($this->userId);

        // No connected menu-platform link (registry-driven, FOUND-23) → clear any
        // scraped content. Scan-sourced categories (menu-scan-apply endpoint, see
        // MenuScanApplier) have no scraper counterpart to lose — this is the SAME
        // wholesale-rebuild trigger as a real refresh, just via a different route
        // (removing the last online-ordering entry also dispatches this job), so it
        // needs the same guard as persist() below or a scan-only menu vanishes the
        // moment the user touches any unrelated online-ordering link.
        if ($plan === null) {
            $this->clearScrapedContent($this->userId);

            return;
        }

        // Registry platform slugs in content-priority order (Uber Eats first).
        $slugs = array_keys(config('partna.menu.platforms'));

        // previousCategoryOrder() reads content.collections directly (see that
        // method) — the menu row is loaded here only for its platform links and
        // the skip-gate below.
        $existing = Menu::query()->where('user_id', $this->userId)
            ->with(['platformLinks'])
            ->first();
        $existingLinks = $existing?->platformLinks->keyBy('platform') ?? collect();

        // Skip the scrape when EVERY registry platform's store URL is unchanged
        // and settled, the last fetch succeeded, and this isn't a forced refresh —
        // links recompute at read time, so there's nothing to do. A platform
        // that's connected but last came back 'unavailable' (a flaky / bot-blocked
        // scrape) is NOT settled, so we re-scrape to recover it rather than
        // leaving the menu permanently missing that platform.
        $allSettled = true;
        foreach ($slugs as $s) {
            $urlUnchanged = ($existingLinks->get($s)?->store_url) === $plan['storeUrls'][$s];
            if (! $urlUnchanged || ! $this->platformSettled($existingLinks, $s, $plan['storeUrls'][$s])) {
                $allSettled = false;
                break;
            }
        }
        if (! $this->force
            && $existing
            && $existing->fetch_status === 'ok'
            && $allSettled) {
            return;
        }

        // Flip to pending (preserving any existing items) so the dashboard shows
        // a syncing state while the scrape runs.
        $menu = Menu::updateOrCreate(
            ['user_id' => $this->userId],
            [
                'content_source' => $plan['contentSource'],
                'pickup_platform' => $plan['pickupPlatform'],
                'delivery_platform' => $plan['deliveryPlatform'],
                'fetch_status' => 'pending',
            ],
        );

        // Upsert the per-platform store URLs; a disconnected platform (null URL)
        // drops its link row so the skip-comparison sees "not connected".
        foreach ($plan['storeUrls'] as $platform => $url) {
            if ($url === null) {
                $menu->platformLinks()->where('platform', $platform)->delete();

                continue;
            }
            MenuPlatformLink::updateOrCreate(
                ['menu_id' => $menu->id, 'platform' => $platform],
                ['store_url' => $url],
            );
        }

        // Per-platform consolidated store links (one store's pickup + delivery rows
        // already collapsed) — the scrape targets AND each item's modes/url source.
        // Already slug-keyed to only the CONNECTED platforms; fetchStores() self-guards.
        $storeLinks = $source->storeLinks($this->userId);

        // Scrape every connected platform across BOTH modes CONCURRENTLY (one
        // Http::pool round inside fetchStores) and fuse per-mode prices per dish.
        $menus = $scraper->fetchStores($storeLinks, $this->userId, $plan['address']);

        $now = now();

        // Nothing usable from ANY connected platform — keep the last menu, mark
        // unavailable so the dashboard stops polling. A manual refresh retries.
        // No persist() risk on this branch (content is untouched), so the
        // per-platform sync status is safe to write right here.
        if (array_filter($menus) === []) {
            $this->writePlatformSyncStatus($storeLinks, $menus, $menu, $now);
            $menu->forceFill(['fetch_status' => 'unavailable', 'last_fetched_at' => $now])->save();

            return;
        }

        // Canonical structure prefers the highest-priority registry platform that
        // actually returned a menu (Uber Eats first). The union appends the other
        // platform's items either way; a connected platform that returned nothing
        // is still attached to every dish as a ghost (see MenuMerger).
        $contentSource = $slugs[0];
        foreach ($slugs as $s) {
            if (($menus[$s] ?? null) !== null) {
                $contentSource = $s;
                break;
            }
        }
        $merged = $merger->merge($menus, $contentSource, $storeLinks, $this->previousCategoryOrder($existing));

        $this->persist($menu, $contentSource, $merged, $now);

        // TXN-101: per-platform sync status is written ONLY after persist() has
        // committed successfully. It used to be written right after the scrape
        // (before persist()), which meant a persist() failure left
        // menu_platform_links claiming 'ok, synced just now' for content that
        // was never actually written — misleading the dashboard AND hiding the
        // platform from menu:retry-unavailable's self-heal query (which selects
        // on status = 'unavailable'), so a genuine failure could get stuck
        // forever without a manual "Refresh menu" click.
        $this->writePlatformSyncStatus($storeLinks, $menus, $menu, $now);

        // Re-apply the persisted Google-photos scan enrichment (menus.scan_items,
        // written by GoogleMenuPhotoScanJob) over the freshly written rows —
        // the scrape just upserted every dish WITHOUT the
        // scan's longer descriptions / dietary badges, and this restores them
        // from the stored extraction instead of re-billing OCR. Suppressed
        // dishes are filtered out first — persist() skipping a deleted scraped
        // dish is pointless if this AUTOMATIC reapply immediately recreates it
        // from scan_items via MenuScanApplier's no-match→create path. Never
        // lets an enrichment failure break the scrape itself.
        try {
            $freshMenu = $menu->fresh();
            $scanItems = $freshMenu?->scan_items['items'] ?? null;
            if ($freshMenu !== null && is_array($scanItems)) {
                $scanItems = $this->withoutSuppressedScanItems($scanItems, $freshMenu);
            }
            $scanUser = is_array($scanItems) && $scanItems !== [] ? User::query()->find($this->userId) : null;
            if ($scanUser) {
                app(MenuScanApplier::class)->apply($scanUser, $scanItems, enrichOnly: true);
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('menu_fetch.scan_reapply_failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);
        }

        // Menu content changed (wholesale rebuild via query builder bypasses the
        // model observers) — bust the public-page edge cache. purgeHandle already
        // covers the /menu sub-page; nothing had been DISPATCHING the purge on a
        // menu content change, so refreshed prices/items sat stale at the edge.
        $this->bustSiteCache($this->userId);
    }

    /**
     * The owner's previously persisted category labels, in position order — the
     * cross-run stability baseline MenuMerger sorts known categories against
     * (fix-round).
     *
     * Slice 7: `content.collections` kind `menu_category`, replacing
     * site.menu_categories. `is_user_created` is the source marker that survived
     * the move — content.collections carries no source column, and it is the one
     * bit available: ProjectionWriter::upsertCollections() writes `false` on
     * insert and NEVER updates it, so a category first created by an owner-lane
     * write (manual / photo scan / website scan) stays flagged even after a
     * scrape lists the same label. Excluding those here is the same exclusion
     * site.menu_categories.source_platform used to express: owner content was
     * never part of a prior merge() result, so it cannot anchor one.
     *
     * @return list<string>
     */
    private function previousCategoryOrder(?Menu $existing): array
    {
        if ($existing === null) {
            return [];
        }

        return DB::connection('pgsql')->table('content.collections')
            ->where('user_id', $existing->user_id)
            ->where('kind', 'menu_category')
            ->whereNull('removed_at')
            ->where('is_user_created', false)
            ->orderBy('position')
            ->orderBy('label')
            ->pluck('label')
            ->map(fn ($label) => (string) $label)
            ->all();
    }

    /**
     * Whether a platform is "settled" for skip purposes: an unconnected platform
     * (no store URL) always is; a connected one only when its last scrape was 'ok'.
     * A connected-but-'unavailable' platform forces a re-scrape (recovery).
     *
     * @param  Collection<string, MenuPlatformLink>  $links
     */
    private function platformSettled(Collection $links, string $platform, ?string $url): bool
    {
        if ($url === null) {
            return true;
        }

        return $links->get($platform)?->status === 'ok';
    }

    /**
     * Per-platform sync status (ok/unavailable) + timestamp, independent of
     * the merge outcome — only for connected platforms (those with a store
     * link). Callers must only invoke this once the associated content write
     * has actually landed (persist() returned, or the nothing-usable branch
     * that never touches content) — see the TXN-101 note at both call sites
     * in handle().
     *
     * @param  array<string, mixed>  $storeLinks
     * @param  array<string, mixed|null>  $menus
     */
    private function writePlatformSyncStatus(array $storeLinks, array $menus, Menu $menu, Carbon $now): void
    {
        foreach ($storeLinks as $platform => $link) {
            MenuPlatformLink::updateOrCreate(
                ['menu_id' => $menu->id, 'platform' => $platform],
                ['synced_at' => $now, 'status' => ($menus[$platform] ?? null) !== null ? 'ok' : 'unavailable'],
            );
        }
    }

    /**
     * Land the merged scrape in `content.*` and write the resolved store-level
     * fields onto the surviving `site.menus` row.
     *
     * Slice 7 Task 7. What this writes, in order:
     *
     *  1. the menu's store fields (name / logo / rating / currency / dining
     *     modes) — `site.menus` is NOT part of the teardown;
     *  2. one `content.items` upsert per merged dish, through
     *     ManualMenuWriter::write() on MenuProjectionMapper's coord;
     *  3. `items.removed_at` for every dish this scrape's platforms no longer
     *     carry (markRemoved, NEVER a hard delete and NEVER
     *     source_items.removed_at), then a slug re-assert over what was
     *     written — see the call site for why that order;
     *  4. one `order_platform` collection + `content.storefronts` sidecar per
     *     `site.menu_platform_links` row (see syncOrderPlatforms — dropping it
     *     costs every two-platform dish its order links);
     *  5. a `pool:menus` pin for any dish that has never had one;
     *  6. `fetch_status='ok'` + the two timestamps, LAST.
     *
     * The delete-and-reinsert rebuild is gone. The coord is derived from the
     * normalised dish name (MenuProjectionMapper's docblock says why), which is
     * exactly the identity key takeReusedId() used to reconstruct by hand — so
     * an upsert-by-coord is a straight simplification: no id reuse pool, no
     * orphan cleanup, and no window in which a dish does not exist.
     *
     * NO outer transaction, deliberately. write() is atomic per dish and
     * idempotent, so a mid-way failure leaves a partially-refreshed menu the
     * next scrape converges — where the old rebuild HAD to be atomic because
     * it had already deleted everything. The status stamp is last for the same
     * reason it used to sit inside the transaction: a failure must leave
     * fetch_status where it was, not claim success over half a menu.
     *
     * Protected (not private) so a test can subclass and override it to force
     * a deterministic failure — proving TXN-101's ordering guarantee (the
     * per-platform sync status must never be written before this succeeds).
     *
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}  $merged
     */
    protected function persist(Menu $menu, string $contentSource, array $merged, Carbon $now): void
    {
        $writer = app(ManualMenuWriter::class);
        $userId = (string) $menu->user_id;

        // Store fields first: MenuProjectionMapper falls back to menus.currency
        // for the 93 dishes that carry none, so the row has to be current
        // BEFORE the dish writes read it.
        $store = $merged['store'];
        $menu->forceFill([
            'content_source' => $contentSource,
            'store_name' => $store['name'] ?? null,
            'logo_url' => $store['logo'] ?? null,
            'rating' => $store['rating'] ?? null,
            'review_count' => $store['reviewCount'] ?? null,
            'currency' => $store['currency'] ?? 'AUD',
            'dining_modes' => $store['diningModes'] ?? null,
        ])->save();

        $ownerNames = $this->ownerAuthoredNames($menu);
        $dishes = $this->mergedDishes($merged, $ownerNames['skip_write']);

        $coords = [];
        foreach ($dishes as $dish) {
            $coords[$writer->coordFor((string) $menu->id, $dish['name'])] = true;
        }

        $absent = $this->absentDishIds($menu, $coords, $ownerNames['protected'], $ownerNames['protected_coords']);

        $itemIds = [];
        $namesById = [];
        foreach ($dishes as $dish) {
            $itemId = $writer->write($userId, $writer->coordFor((string) $menu->id, $dish['name']), $writer->projectionFor(
                $this->dishRow($dish['item']),
                $dish['categories'],
                $this->platformRows($dish['item']),
                $menu,
            ));
            $itemIds[] = $itemId;
            $namesById[$itemId] = $dish['name'];
        }

        // FREE THEN RE-ASSERT, and the order is load-bearing for exactly the
        // reason the pre-slice-7 reconcileItemSlugs() spelled out: a dropped
        // "Café Latte" and a new "Cafe Latte" are distinct dishes to
        // normalizeName() but share the slug base `cafe-latte`, and whoever
        // mints while the other still holds it is stuck on `cafe-latte-2`
        // permanently (ensureCurrent() treats a `-N` it would still allocate as
        // settled). markRemoved() frees the vanished dish's slug; the pass
        // after it hands the base to the newcomer.
        $this->retire($writer, $absent);
        $this->reassertSlugs($userId, $namesById);

        // After the dish writes: the projections are what create the
        // order_platform collections these sidecars hang off (same ordering
        // MenuBackfiller::run() uses for the same reason).
        $this->syncOrderPlatforms($menu);
        $this->seedPins($userId, $itemIds);

        $menu->forceFill([
            'fetch_status' => 'ok',
            'last_fetched_at' => $now,
            // LIFE-12: the ONLY writer of last_successful_fetch_at. It is the
            // failure-episode boundary for PlatformHealthNotifier::menuScrapeFailed(),
            // which needs "when did this menu last genuinely succeed" —
            // last_fetched_at cannot answer that, because manual dish edits
            // (MenuContentController::resolveMenu), photo-scan applies
            // (MenuScanApplier::resolveMenu) and this job's own
            // soft-unavailable branch all advance it without anything
            // having been fixed. Do not stamp this anywhere else.
            'last_successful_fetch_at' => $now,
        ])->save();
    }

    /**
     * One entry per merged dish, deduped MENU-WIDE by normalized name, each
     * carrying every category it appeared under.
     *
     * The dedupe is not an optimisation. writeManualItem() REPLACES an item's
     * collection memberships for its source, so writing the same coord twice
     * would leave the dish holding only the last category it was seen under —
     * the multi-category model's "one dish, several memberships" collapsing to
     * one. First occurrence wins the display fields and the platform rows,
     * matching the pre-slice-7 rebuild (the merger emits the same fused dish
     * either way).
     *
     * A nameless dish is skipped rather than written: its coord would be
     * sha1('') and every nameless dish on the menu would collapse onto one
     * item. MenuBackfiller skips them for the same reason.
     *
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}  $merged
     * @param  array<string, true>  $skip  normalized names the owner owns
     * @return list<array{name:string, item:array<string,mixed>, categories:list<array{id:string,name:string,position:int}>}>
     */
    private function mergedDishes(array $merged, array $skip): array
    {
        $dishes = [];
        $indexByName = [];

        foreach ($merged['categories'] as $position => $category) {
            $label = trim((string) $category['name']);

            foreach ($category['items'] as $item) {
                $key = $this->normalizeName((string) ($item['name'] ?? ''));
                if ($key === '' || isset($skip[$key])) {
                    continue;
                }

                if (! isset($indexByName[$key])) {
                    $indexByName[$key] = count($dishes);
                    $dishes[] = ['name' => (string) $item['name'], 'item' => $item, 'categories' => []];
                }

                $index = $indexByName[$key];
                $ref = MenuProjectionMapper::categoryRef($label);
                foreach ($dishes[$index]['categories'] as $seen) {
                    if ($seen['id'] === $ref) {
                        continue 2;
                    }
                }
                // `id` is the collection's natural key, not a uuid — the mapper
                // reads only name + position, and this is the value it derives
                // the ref from anyway, so it doubles as the dedupe key above.
                $dishes[$index]['categories'][] = ['id' => $ref, 'name' => $label, 'position' => (int) $position];
            }
        }

        return $dishes;
    }

    /**
     * The merged dish in the legacy `site.menu_items` column shape
     * MenuProjectionMapper::project() reads. Three merged keys have no
     * projection target and are dropped here rather than carried nowhere:
     * pickupSource / deliverySource (which platform backed the aggregate min)
     * and ddExternalId — ManualMenuItems documents the same three as
     * unrecoverable on the read side, so the two halves agree.
     *
     * @param  array<string, mixed>  $item
     */
    private function dishRow(array $item): object
    {
        return (object) [
            'name' => (string) $item['name'],
            'description' => $item['description'] ?? null,
            'image_url' => $item['imageUrl'] ?? null,
            'images' => $item['images'] ?? null,
            'rating' => $item['rating'] ?? null,
            'rating_count' => $item['ratingCount'] ?? null,
            'badges' => $item['badges'] ?? null,
            'base_price' => $item['basePrice'] ?? null,
            'pickup_price' => $item['pickupPrice'] ?? null,
            'delivery_price' => $item['deliveryPrice'] ?? null,
            'currency' => $item['currency'] ?? null,
        ];
    }

    /**
     * The dish's per-platform availability, shaped as the
     * `site.menu_item_platforms` rows MenuProjectionMapper::offers() and
     * ::platformCollections() expect. MenuMerger always emits at least one
     * entry (a connected-but-unscraped platform rides along as a ghost), which
     * is what makes "has an order_platform membership" a sound test for
     * scraper-owned in retireAbsentDishes().
     *
     * @param  array<string, mixed>  $item
     * @return list<object>
     */
    private function platformRows(array $item): array
    {
        $rows = [];

        foreach (($item['platforms'] ?? []) as $platform) {
            if (! is_array($platform) || ! isset($platform['platform'])) {
                continue;
            }
            $rows[] = (object) [
                'platform' => (string) $platform['platform'],
                'pickup_price' => $platform['pickupPrice'] ?? null,
                'pickup_url' => $platform['pickupUrl'] ?? null,
                'delivery_price' => $platform['deliveryPrice'] ?? null,
                'delivery_url' => $platform['deliveryUrl'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * One `order_platform` collection + `content.storefronts` sidecar per
     * `site.menu_platform_links` row — the store card, and the ONLY thing that
     * re-pairs a dish's deep link with the platform it belongs to.
     *
     * Load-bearing, not bookkeeping: MenuProjectionMapper::offer() carries the
     * order URL but drops the platform label, so ManualMenuItems::platforms()
     * recovers the attribution by matching the offer URL's HOST against each
     * platform's storefront URL. Stop writing these and every dish sold on two
     * platforms loses its order links (a dish on one platform survives on
     * ManualMenuItems' single-platform fallback, which is four of the five live
     * menus — so the failure would be invisible on most of them).
     *
     * The collection normally already exists: the dish projections above create
     * it. A platform whose scrape landed no dish (MenuMerger's "ghost") has
     * none, so it gets one here — the order link is real and connecting a
     * platform must not look like it did nothing. Same shape and same reasoning
     * as MenuBackfiller::migrateOrderPlatforms(), which seeded these from the
     * same table.
     */
    private function syncOrderPlatforms(Menu $menu): void
    {
        $userId = (string) $menu->user_id;

        foreach (MenuPlatformLink::query()->where('menu_id', $menu->id)->get() as $link) {
            $platform = trim((string) $link->platform);
            if ($platform === '') {
                continue;
            }

            $ref = MenuProjectionMapper::orderPlatformRef($platform);
            $collectionId = DB::connection('pgsql')->table('content.collections')
                ->where('user_id', $userId)->where('kind', 'order_platform')->where('external_ref', $ref)
                ->value('id');

            if ($collectionId === null) {
                $collectionId = (string) Str::uuid();
                DB::connection('pgsql')->table('content.collections')->insert([
                    'id' => $collectionId,
                    'user_id' => $userId,
                    'parent_id' => null,
                    'label' => $platform,
                    'kind' => 'order_platform',
                    'external_ref' => $ref,
                    'position' => 0,
                    'is_user_created' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // url IS vendor-owned and must be followed — a store that moves
            // would otherwise leave the order button pointing at a dead page.
            DB::connection('pgsql')->table('content.storefronts')->upsert([[
                'collection_id' => (string) $collectionId,
                'provider' => $platform,
                'url' => trim((string) $link->store_url) ?: null,
                'external_ref' => $ref,
                'currency' => $menu->currency,
                'referral_query' => '',
                'is_individual' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['collection_id'], ['url', 'currency', 'updated_at']);
        }
    }

    /**
     * The scraper-owned dishes this run did NOT re-emit — persist() retires them.
     *
     * `items.removed_at` only — never a hard delete (the old rebuild's delete
     * was safe only because it re-inserted in the same transaction) and never
     * `source_items.removed_at`, which is cleared the moment a dish reappears
     * and would therefore resurrect a dish its owner deleted.
     *
     * SCRAPER-OWNED means "holds at least one `order_platform` collection
     * membership". That is the content.* replacement for
     * rebuildableCategoryIds() and it preserves that method's whole point —
     * scanned and hand-authored content survives a scrape — through a signal
     * that actually exists on this side: a hand-added dish and a photo-scan
     * dish carry no platform rows, so neither is ever a candidate here. It is
     * also strictly more accurate than the old category proxy, which could only
     * infer ownership from where a dish happened to sit; `content.collections`
     * folds a scanned "Drinks" and a scraped "Drinks" into ONE row (they share
     * MenuProjectionMapper::categoryRef), so the category can no longer answer
     * the question at all.
     *
     * Two further exemptions, both name-matched with the same normalization the
     * coord hashes: `menus.suppressed_items` (the owner's delete / detach
     * intent) and `menus.scan_items` (a photo-scan dish that the scrape had
     * previously matched onto — the pre-slice-7 job deleted it and let the scan
     * reapply in handle() rebuild it, which a one-way removed_at cannot do).
     *
     * A third, coord-matched: a dish carrying a `content.manual_overrides` row
     * (see ownerLockedCoords) — the owner edited it, so the vendor dropping it
     * must not one-way retire the owner's work.
     *
     * @param  array<string, true>  $coords  coords this scrape wrote
     * @param  array<string, true>  $protected  normalized names the owner owns
     * @param  array<string, true>  $protectedCoords  coords the owner has edited
     * @return list<string> content item ids
     */
    private function absentDishIds(Menu $menu, array $coords, array $protected, array $protectedCoords = []): array
    {
        $userId = (string) $menu->user_id;

        $live = DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $userId)
            ->where('cs.kind', 'manual')
            ->where('i.user_id', $userId)
            ->where('i.kind', 'menu_item')
            ->whereNull('i.removed_at')
            ->where('si.coord', 'like', 'manual:menu:'.$menu->id.':%')
            ->get(['i.id', 'i.headline_cache', 'si.coord']);

        $candidates = [];
        foreach ($live as $row) {
            if (isset($coords[(string) $row->coord])) {
                continue;
            }
            if (isset($protected[$this->normalizeName((string) ($row->headline_cache ?? ''))])) {
                continue;
            }
            if (isset($protectedCoords[(string) $row->coord])) {
                continue;
            }
            $candidates[(string) $row->id] = true;
        }

        if ($candidates === []) {
            return [];
        }

        return $this->scraperOwnedItemIds($userId, array_keys($candidates));
    }

    /**
     * `items.removed_at` + a freed slug, for each id. Idempotent — see the
     * second call site in persist().
     *
     * @param  list<string>  $itemIds
     */
    private function retire(ManualMenuWriter $writer, array $itemIds): void
    {
        foreach ($itemIds as $itemId) {
            $writer->markRemoved($itemId);
        }
    }

    /**
     * Re-run the slug allocator over the dishes this scrape wrote, AFTER the
     * retirements have freed their bases — see the call site.
     *
     * A no-op for every dish whose slug already matches its name (one SELECT
     * each, the same cost ProjectionWriter::refreshItemCaches() already pays
     * per item), so this only ever moves a dish that was parked on a `-N`
     * suffix by a name the vendor has since dropped.
     *
     * Best-effort, matching every other slug call site: a permalink must never
     * fail a scrape, and this runs before writePlatformSyncStatus() in
     * handle(), where throwing would invert TXN-101's ordering guarantee.
     *
     * @param  array<string, string>  $namesById  item id => dish name
     */
    private function reassertSlugs(string $userId, array $namesById): void
    {
        if ($namesById === []) {
            return;
        }

        try {
            $slugs = app(ContentItemSlugAllocator::class);
            foreach ($namesById as $itemId => $name) {
                $slugs->ensureCurrent($userId, $itemId, $name);
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('menu_fetch.slug_reassert_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The subset of $itemIds holding an `order_platform` membership — see
     * retireAbsentDishes(). One query for the whole set, not one per dish.
     *
     * @param  list<string>  $itemIds
     * @return list<string>
     */
    private function scraperOwnedItemIds(string $userId, array $itemIds): array
    {
        return DB::connection('pgsql')->table('content.collection_items as ci')
            ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
            ->whereIn('ci.item_id', $itemIds)
            ->where('c.user_id', $userId)
            ->where('c.kind', 'order_platform')
            ->distinct()
            ->pluck('ci.item_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Give a dish the scrape has never seen pinned a place in the owner's
     * `pool:menus` arrangement, at the end of it.
     *
     * Dish ORDER lives in the pins (site.section_items.sort_key), NOT in
     * content.collection_items.position — that column is ProjectionWriter's
     * per-item counter over a dish's own collection list, which is why
     * ProvisionMenuPinsCommand had to seed the pins from the legacy order at
     * all. MenuPayloadComposer::pinOrder() is the reader, and an UNPINNED dish
     * trails every pinned one, so a scrape that pinned nothing would render a
     * brand-new menu alphabetically instead of in the vendor's order.
     *
     * SEED ONLY — an existing pin is never rewritten. That is the same
     * idempotency rule ProvisionMenuPinsCommand states and the same rule
     * content.collections.position follows: a scheduled run must never snap an
     * owner's reorder back (parent §19). So the first scrape of a fresh menu
     * lays the vendor's order down 1..N, and every later scrape appends new
     * dishes after whatever the owner has arranged.
     *
     * Best-effort: pool provisioning is not what a menu refresh is for, and an
     * owner who has never opened their Menu page has no section yet.
     *
     * @param  list<string>  $itemIds  in the scrape's own category/item order
     */
    private function seedPins(string $userId, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        try {
            $site = Site::query()->where('user_id', $userId)->first();
            if ($site === null) {
                return;
            }

            $writer = app(ManualMenuWriter::class);
            $sectionKey = PoolRegistry::sectionKey('menus');

            $existing = DB::connection('pgsql')->table('site.section_items as si')
                ->join('site.sections as s', 's.id', '=', 'si.section_id')
                ->where('s.site_id', $site->id)
                ->where('s.key', $sectionKey)
                ->pluck('si.sort_key', 'si.item_id');

            $next = 1.0 + (float) ($existing->filter(fn ($k) => $k !== null)->max() ?? 0.0);

            foreach ($itemIds as $itemId) {
                if ($existing->has($itemId)) {
                    continue;
                }
                $writer->pin($site, $itemId, $next);
                $next += 1.0;
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('menu_fetch.pin_seed_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The owner-authored sets a scrape must respect. The two name sets are
     * normalized with the same rule MenuProjectionMapper hashes into the coord;
     * the coord set is keyed on the coord itself (see ownerLockedCoords).
     *
     * `skip_write` — names the scrape must not write AT ALL. This is
     * `menus.suppressed_items`, the column MenuContentController writes when
     * the owner DELETES a scraped dish. That is the only signal it carries: an
     * owner EDIT does not land here. Editing a dish records
     * `content.manual_overrides` rows instead (MenuContentController's
     * `recordOwnerEdits()`, the content-lane `is_manual` that slice 7 Tasks 6
     * and 8 settled on), and this job does NOT yet honour those on the write
     * side — a scrape still re-projects the vendor's values over an
     * owner-edited dish. That gap is known, deliberately deferred to the drop
     * phase, and described in `docs/superpowers/plans/2026-08-12-slice-7-teardown.md`;
     * it needs a coord-keyed write skip that does not strand the coord and an
     * owner call on per-column vs whole-dish locking, which is more than a
     * predicate change.
     *
     * `protected` — names the scrape must not RETIRE. Everything in skip_write,
     * plus `menus.scan_items`: a photo-scan dish the scrape had matched onto
     * shares one item now, and marking it removed when the vendor drops it
     * would be one-way (items.removed_at is never cleared except by an explicit
     * owner restore), where the pre-slice-7 job deleted it and let the scan
     * reapply in handle() put it back.
     *
     * `protected_coords` — coords the scrape must not RETIRE, for the same
     * one-way reason, because the owner has edited them.
     *
     * @return array{skip_write: array<string, true>, protected: array<string, true>, protected_coords: array<string, true>}
     */
    private function ownerAuthoredNames(Menu $menu): array
    {
        $suppressed = $this->suppressedItemNames($menu);
        $protected = $suppressed;

        foreach (($menu->scan_items['items'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $this->normalizeName((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $protected[$name] = true;
            }
        }

        return [
            'skip_write' => $suppressed,
            'protected' => $protected,
            'protected_coords' => $this->ownerLockedCoords($menu),
        ];
    }

    /**
     * Coords of this menu's dishes carrying a `content.manual_overrides` row —
     * the owner has edited them by hand, so a vendor dropping the dish must not
     * retire it. Same "any override locks the dish" derivation
     * MenuScanApplier::lockedItemIds() uses, so the two lanes agree on what
     * owner-locked means.
     *
     * Keyed on COORD, not on the dish name: after a rename `headline_cache`
     * holds the owner's name while the coord still hashes the vendor's, so a
     * name-keyed lookup would miss exactly the case most worth protecting.
     *
     * Retirement ONLY. This never reaches the write skip, so a locked dish is
     * still re-projected and its coord still enters persist()'s $coords —
     * which is what keeps this from stranding a coord and retiring the dish it
     * was meant to save.
     *
     * @return array<string, true>
     */
    private function ownerLockedCoords(Menu $menu): array
    {
        $userId = (string) $menu->user_id;

        $coords = DB::connection('pgsql')->table('content.manual_overrides as mo')
            ->join('content.items as i', 'i.id', '=', 'mo.item_id')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $userId)
            ->where('cs.kind', 'manual')
            ->where('i.user_id', $userId)
            ->where('i.kind', 'menu_item')
            ->where('si.coord', 'like', 'manual:menu:'.$menu->id.':%')
            ->distinct()
            ->pluck('si.coord');

        $out = [];
        foreach ($coords as $coord) {
            $out[(string) $coord] = true;
        }

        return $out;
    }

    /**
     * Normalized-name set of dishes the owner deleted from scraped content
     * (menus.suppressed_items, written by MenuContentController). The rebuild
     * skips reinserting any scraped dish matching one. NAME-ONLY on purpose:
     * a dish spans several categories in the multi-category model, so the
     * owner's delete intent is "this dish, gone" — stored entries still carry
     * a {category, name} shape (the category is informational), and legacy
     * category-scoped entries keep matching by their name. Same normalization
     * as the identity matching, so "match" means the same thing everywhere.
     *
     * @return array<string, true>
     */
    private function suppressedItemNames(Menu $menu): array
    {
        $names = [];
        foreach (($menu->suppressed_items ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $this->normalizeName((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * $scanItems minus every dish the owner deleted (menus.suppressed_items),
     * matched by normalized name — the same rule persist() applies. Applies
     * ONLY to this automatic reapply — the MANUAL dashboard /scan/apply stays
     * unfiltered, because an explicit re-scan is the user asking for that
     * photo's content back.
     *
     * @param  list<mixed>  $scanItems
     * @return list<mixed>
     */
    private function withoutSuppressedScanItems(array $scanItems, Menu $menu): array
    {
        $suppressedNames = $this->suppressedItemNames($menu);
        if ($suppressedNames === []) {
            return $scanItems;
        }

        // Malformed (non-array / nameless) entries pass through untouched — the
        // applier's own handling of them is unchanged by this filter.
        return array_values(array_filter(
            $scanItems,
            fn ($item) => ! (is_array($item)
                && isset($suppressedNames[$this->normalizeName((string) ($item['name'] ?? ''))]))
        ));
    }

    /**
     * Retire every scraper-owned dish for a user, used when no ordering
     * platform is connected at all — the same scope persist() refreshes, run
     * with an empty scrape.
     *
     * `items.removed_at` only (see retireAbsentDishes): a photo-scan or
     * hand-added dish carries no `order_platform` membership and is never
     * touched, and neither is anything named in `menus.suppressed_items` /
     * `menus.scan_items`.
     *
     * Also clears the menu's platformLinks AND their `content.storefronts`
     * sidecars — by definition no platform is connected at this point, so
     * neither can be legitimately valid afterward. Leaving a link row behind
     * would let a later reconnect's urlUnchanged+settled skip-gate (handle(),
     * above) wrongly compare against stale data and no-op a scrape that should
     * run; leaving a storefront behind would keep a dead store card on the
     * public menu. The `order_platform` COLLECTION survives, empty: it is the
     * natural key a reconnect upserts back onto, and content.collections
     * .removed_at is one-way (upsertCollections never clears it), so removing
     * it would strand the platform permanently.
     *
     * When nothing owner-authored remains afterward, the menu row itself is
     * soft-deleted — IDENTICAL to the prior unconditional-delete behaviour for
     * every user who never used scan/manual. When owner content DOES remain,
     * the row survives and content_source flips to the accurate remaining
     * source instead of keeping a now-inaccurate scraped platform name.
     */
    private function clearScrapedContent(string $userId): void
    {
        $menu = Menu::query()->where('user_id', $userId)->first();
        if ($menu === null) {
            return;
        }

        $writer = app(ManualMenuWriter::class);

        // An empty coord set: every scraper-owned dish is "absent from this
        // scrape", which is exactly what having no ordering platform means.
        // One pass only — nothing is written afterwards, so nothing re-mints
        // the slugs this frees (see persist()'s second retire()).
        $ownerNames = $this->ownerAuthoredNames($menu);
        $this->retire($writer, $this->absentDishIds($menu, [], $ownerNames['protected'], $ownerNames['protected_coords']));

        $this->clearStorefronts($userId);
        $menu->platformLinks()->delete();

        if ($this->hasOwnerContent($menu)) {
            $menu->forceFill(['content_source' => $this->remainingContentSource($menu)])->save();
        } else {
            $menu->delete();
        }

        // Slug reconciliation is gone with Task 7's rewrite: persist() now
        // writes -> retires -> re-asserts via ContentItemSlugAllocator, so a
        // scraped dish's slug lives in content.item_slugs and the legacy
        // site.item_slugs menu lane is no longer this job's to sweep.

        // Scraped rows left the public menu — bust the edge cache (a menu
        // existed, so this is a real content change, not a no-op).
        $this->bustSiteCache($userId);
    }

    /** Drop the store-card sidecars; the parent collections stay (see clearScrapedContent). */
    private function clearStorefronts(string $userId): void
    {
        $collectionIds = DB::connection('pgsql')->table('content.collections')
            ->where('user_id', $userId)->where('kind', 'order_platform')
            ->pluck('id');

        if ($collectionIds->isNotEmpty()) {
            DB::connection('pgsql')->table('content.storefronts')
                ->whereIn('collection_id', $collectionIds)->delete();
        }
    }

    /**
     * Whether anything owner-authored survives on a menu whose scraped dishes
     * were just retired: a live dish (photo-scan, website-scan or hand-added —
     * all three land as `menu_item` content items on this menu's coord) or an
     * owner-created category with nothing in it yet.
     *
     * The transitional legacy arm was deleted in Phase 6 with site.menu_* —
     * all four write paths land in content.* now, so reading only content.*
     * can no longer soft-delete the menu row out from under a scan-only owner.
     */
    private function hasOwnerContent(Menu $menu): bool
    {
        $liveDish = DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $menu->user_id)
            ->where('cs.kind', 'manual')
            ->where('i.kind', 'menu_item')
            ->whereNull('i.removed_at')
            ->where('si.coord', 'like', 'manual:menu:'.$menu->id.':%')
            ->exists();

        if ($liveDish) {
            return true;
        }

        if (DB::connection('pgsql')->table('content.collections')
            ->where('user_id', $menu->user_id)
            ->where('kind', 'menu_category')
            ->whereNull('removed_at')
            ->where('is_user_created', true)
            ->exists()) {
            return true;
        }

        return $this->ownerSource($menu) !== null;
    }

    /**
     * The content_source to stamp on a menu whose scraped content was just
     * cleared but that still has owner-authored content.
     *
     * Answers 'scan' > 'website-scan' > 'manual' from the content lane's
     * category refs (ownerSource()). With site.menu_categories dropped the
     * answer DEGRADES where no owner category carries a source ref: 'scan' if a
     * photo scan ran (menus.scan_items is the only per-source marker surviving
     * the teardown), else 'manual'. A deliberate drop, named rather than
     * papered over — content.collections carries no source column, only the
     * insert-only `is_user_created` bit, which cannot tell three owner lanes
     * apart.
     */
    private function remainingContentSource(Menu $menu): string
    {
        return $this->ownerSource($menu)
            ?? (($menu->scan_items['items'] ?? []) !== [] ? 'scan' : 'manual');
    }

    /**
     * The owner-lane source for this menu, highest-precedence first. Null when
     * no owner category remains.
     *
     * Slice 7 Phase 6: the `site.menu_categories.source_platform` half is gone
     * with the table. An owner category is a `content.collections` row whose
     * `external_ref` names its source (MenuScanApplier::categoryRefFor), so the
     * answer is derived from the ref rather than a dropped column.
     */
    private function ownerSource(Menu $menu): ?string
    {
        $remaining = collect($this->ownerContentSources($menu));

        foreach (['scan', 'website-scan', 'manual'] as $source) {
            if ($remaining->contains($source)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The owner-category sources present in the content lane, as the same
     * 'scan' / 'website-scan' strings the legacy column held.
     *
     * @return list<string>
     */
    private function ownerContentSources(Menu $menu): array
    {
        $userId = DB::connection('pgsql')->table('site.menus')->where('id', $menu->id)->value('user_id');
        if ($userId === null) {
            return [];
        }

        $out = [];
        foreach (app(ManualMenuItems::class)->categories((string) $userId) as $category) {
            foreach (['scan', 'website-scan'] as $source) {
                if (str_starts_with((string) $category->external_ref, 'menu:'.$source.':')) {
                    $out[] = $source;
                }
            }
        }

        return $out;
    }

    /**
     * Bust the public sitepage cache for a menu content change. Menu writes are
     * wholesale query-builder rebuilds that intentionally bypass model observers,
     * so the cache invalidation has to be dispatched explicitly. Routes through
     * SiteCacheInvalidator::touchSite (→ SiteObserver → Redis invalidate + CF
     * purge + KV), never a direct CloudflareCachePurgeJob — that would double-fire.
     * Resolved from the container (not constructor-injected — jobs serialize their
     * constructor args). Never let a cache-bookkeeping failure fail the job.
     */
    private function bustSiteCache(string $userId): void
    {
        app(SiteCacheInvalidator::class)->touchSite(
            fn () => User::query()->with('site')->find($userId)?->site,
            'menu-fetch',
            ['user_id' => $userId],
        );
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('menu.fetch_job.failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);

        $menu = Menu::query()->where('user_id', $this->userId)->first();
        if ($menu) {
            $menu->forceFill(['fetch_status' => 'unavailable'])->save();
        }

        // OV-H: in-app heads-up (non-critical — the menu self-heals via the retry cron).
        // Resolved via the container since failed() gets no dependency injection.
        // LIFE-12: last_successful_fetch_at — NOT last_fetched_at — is the
        // failure-episode boundary. It advances only from handle()'s
        // fetch_status='ok' branch, so it stays fixed for the length of an
        // outage no matter how much the owner edits their menu meanwhile.
        // See PlatformHealthNotifier::menuScrapeFailed.
        app(PlatformHealthNotifier::class)->menuScrapeFailed((string) $this->userId, $menu?->last_successful_fetch_at);
    }
}
