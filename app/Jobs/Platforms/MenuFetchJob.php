<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\MenuPlatformLink;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\Notifications\Dispatchers\PlatformHealthNotifier;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\MenuSource;
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

// Fetches (or refreshes) a user's menu into the relational site.menus +
// menu_categories + menu_items tables from their connected online-ordering
// platforms. Each connected platform (Uber Eats and/or DoorDash) is scraped
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        // Categories eager-loaded here (position order) feed stableSortCategories()'s
        // cross-run persisted-order match below — loaded BEFORE persist() wholesale
        // deletes/rebuilds them, and nothing between here and the merge() call
        // touches menu_categories, so this stays fresh for that read.
        $existing = Menu::query()->where('user_id', $this->userId)
            ->with(['platformLinks', 'categories' => fn ($q) => $q->orderBy('position')])
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
        // written by GoogleMenuPhotoScanJob) over the freshly rebuilt rows —
        // the wholesale rebuild just recreated every scraped item WITHOUT the
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
            Log::warning('menu_fetch.scan_reapply_failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);
        }

        // Menu content changed (wholesale rebuild via query builder bypasses the
        // model observers) — bust the public-page edge cache. purgeHandle already
        // covers the /menu sub-page; nothing had been DISPATCHING the purge on a
        // menu content change, so refreshed prices/items sat stale at the edge.
        $this->bustSiteCache($this->userId);
    }

    /**
     * The site's previously persisted category names, in position order — the
     * cross-run stability baseline MenuMerger sorts known categories against
     * (fix-round). Scoped to the same set persist() rebuilds (excludes
     * source_platform='scan' — those are never scraper output and were never
     * part of a prior merge() result, so they can't meaningfully anchor one).
     *
     * @return list<string>
     */
    private function previousCategoryOrder(?Menu $existing): array
    {
        if ($existing === null) {
            return [];
        }

        return $existing->categories
            ->filter(fn (MenuCategory $c) => $c->source_platform !== 'scan')
            ->pluck('name')
            ->values()
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
     * Replace the menu's categories + items + per-platform availability wholesale
     * within a transaction, and write the resolved store-level fields.
     *
     * Protected (not private) so a test can subclass and override it to force
     * a deterministic failure — proving TXN-101's ordering guarantee (the
     * per-platform sync status must never be written before this succeeds)
     * without having to engineer a real DB-level exception through the
     * collision-safe identity-reuse logic below.
     *
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}  $merged
     */
    protected function persist(Menu $menu, string $contentSource, array $merged, Carbon $now): void
    {
        DB::connection('pgsql')->transaction(function () use ($menu, $contentSource, $merged, $now) {
            // Wholesale rebuild (delete children → reinsert) is intentional, not a perf gap:
            // persist() only runs when handle()'s unchanged-skip gate misses (genuine content
            // change / forced refresh / recovery), so it is not a hot path. Keep it atomic
            // in the txn.
            //
            // STABLE IDENTITY across rebuilds: category + item UUIDs are REUSED when a
            // rebuilt row's normalized name matches a pre-rebuild row (FIFO per name, so
            // duplicate names can't double-assign one id). UUID churn used to be harmless
            // ("read fresh on every render"), but two consumers now key on the id across
            // refreshes: item-seen popularity scores (analytics.item_views item_id +
            // content_popularity_scores content_key) and the sitepage's per-item detail
            // URLs — a fresh UUID every scrape silently reset both. Name-matching is the
            // same identity heuristic MenuMerger uses cross-platform; a renamed dish gets
            // a new id (and starts its score over), which is the honest interpretation.
            //
            // Scoped to rebuildableCategoryIds() (NOT every category) — owner-authored
            // categories (source_platform 'scan'/'manual') are never scraper output and
            // must survive every rebuild; see that method's docblock. Within a rebuildable
            // (scraper-owned) category, is_manual dishes are ALSO preserved: only the
            // scraped (is_manual=false) items are deleted, and a rebuildable category that
            // still holds a manual dish is kept (folded back into on reinsert) rather than
            // dropped. The identity-reuse pool is built from DELETED rows only — a surviving
            // row keeps its live id, so reusing it would collide.
            //
            // Also clears children explicitly (FK cascade covers this on Postgres, but being
            // explicit prevents orphaned item-platform rows in SQLite tests).
            $rebuildableCategoryIds = $this->rebuildableCategoryIds($menu->id);

            // Manual dishes living inside a rebuildable (scraper-owned) category — the
            // owner's own edits/additions. They survive the rebuild, keep their category
            // alive, and outrank a same-named scraped dish (collision skip below).
            $survivingManualItems = MenuItem::query()
                ->whereIn('category_id', $rebuildableCategoryIds)
                ->where('is_manual', true)
                ->get(['id', 'category_id', 'name']);
            $survivingCategoryIds = $survivingManualItems->pluck('category_id')->unique();

            // Categories to actually delete: rebuildable ones NOT kept alive by a manual
            // dish. Every dish in them is scraped, so they're empty once the scrape rows go.
            $deletableCategoryIds = $rebuildableCategoryIds
                ->reject(fn ($id) => $survivingCategoryIds->contains($id))->values();
            // Items to delete: scraped (is_manual=false) rows in rebuildable categories.
            $deletableItemIds = MenuItem::query()
                ->whereIn('category_id', $rebuildableCategoryIds)
                ->where('is_manual', false)
                ->pluck('id');

            // Identity snapshot BEFORE the delete: normalized name → FIFO queue of ids,
            // in stable (category, position) order so re-assignment is deterministic.
            // Scoped to DELETED categories only (survivors keep their live ids).
            $previousCategoryIds = $this->idsByNormalizedName(
                MenuCategory::query()->whereIn('id', $deletableCategoryIds)->orderBy('position')->get(['id', 'name'])
            );
            // Items key by (category id, name) — NOT name alone. A global
            // name pool deterministically SWAPPED ids between same-named
            // dishes in different categories on every rebuild (critic
            // 2026-07-17: the FIFO order rode category_id's lexicographic
            // sort, unrelated to processing order), cross-contaminating
            // popularity scores and item URLs. Scoping to the matched
            // category makes reuse exact; the trade-off — a RENAMED category
            // restarts its items' identities — is the same honest policy as
            // a renamed dish. Scoped to DELETED (scraped) items only.
            $previousItemIds = $this->itemIdsByCategoryAndName(
                MenuItem::query()->whereIn('id', $deletableItemIds)
                    ->orderBy('category_id')->orderBy('position')->get(['id', 'name', 'category_id'])
            );

            MenuItemPlatform::query()->whereIn('menu_item_id', $deletableItemIds)->delete();
            MenuItem::query()->whereIn('id', $deletableItemIds)->delete();
            MenuCategory::query()->whereIn('id', $deletableCategoryIds)->delete();

            // Rebuildable categories kept alive by a manual dish, keyed by normalized
            // name — a scraped category of the same name folds its items back into the
            // existing row instead of duplicating it.
            $survivingCategoriesByName = MenuCategory::query()
                ->whereIn('id', $survivingCategoryIds)
                ->get()
                ->keyBy(fn (MenuCategory $c) => $this->normalizeName((string) $c->name));

            // "<categoryId>|<normalized name>" of every surviving manual dish — a scraped
            // dish that collides with one is skipped (the manual version wins).
            $manualNamesByCategory = [];
            foreach ($survivingManualItems as $mi) {
                $manualNamesByCategory[(string) $mi->category_id.'|'.$this->normalizeName((string) $mi->name)] = true;
            }

            // "<normalized category>|<normalized name>" of dishes the owner deleted from
            // scraped content — the rebuild must not resurrect them.
            $suppressedKeys = $this->suppressedItemKeys($menu);

            $store = $merged['store'];
            $menu->forceFill([
                'content_source' => $contentSource,
                'store_name' => $store['name'] ?? null,
                'logo_url' => $store['logo'] ?? null,
                'rating' => $store['rating'] ?? null,
                'review_count' => $store['reviewCount'] ?? null,
                'currency' => $store['currency'] ?? 'AUD',
                'dining_modes' => $store['diningModes'] ?? null,
                'fetch_status' => 'ok',
                'last_fetched_at' => $now,
            ])->save();

            // Accumulate item-platform child rows across all categories; insert
            // them once, after every menu_items row exists (FK menu_item_id).
            $platformRows = [];

            foreach ($merged['categories'] as $ci => $category) {
                $categoryKey = $this->normalizeName((string) $category['name']);
                $survivor = $survivingCategoriesByName->get($categoryKey);

                if ($survivor !== null) {
                    // Fold scraped items back into the preserved category, and re-slot it
                    // to the scrape's position so the overall order still follows the scrape.
                    $cat = $survivor;
                    $cat->forceFill(['position' => $ci])->save();
                    // Append scraped items AFTER the manual dishes already occupying it.
                    $position = 1 + (int) MenuItem::query()->where('category_id', $cat->id)->max('position');
                } else {
                    // new MenuCategory + explicit id (NOT ::create) — 'id' isn't mass-
                    // assignable, and HasUuids only fills the key when it's still empty,
                    // so a reused id set here is respected.
                    $cat = new MenuCategory([
                        'menu_id' => $menu->id,
                        'name' => $category['name'],
                        'position' => $ci,
                        'source_platform' => $category['sourcePlatform'],
                    ]);
                    $cat->id = $this->takeReusedId($previousCategoryIds, (string) $category['name']) ?? (string) Str::uuid();
                    $cat->save();
                    $position = 0;
                }

                $rows = [];
                foreach ($category['items'] as $item) {
                    $itemKey = $this->normalizeName((string) $item['name']);
                    // A manual dish in this category outranks the scraped one — skip it.
                    if (isset($manualNamesByCategory[(string) $cat->id.'|'.$itemKey])) {
                        continue;
                    }
                    // The owner deleted this scraped dish — don't resurrect it.
                    if (isset($suppressedKeys[$categoryKey.'|'.$itemKey])) {
                        continue;
                    }
                    // $cat->id IS the old category id whenever the category
                    // matched by name above — so this lookup only ever finds
                    // ids that lived in this same category. A fresh-uuid
                    // (new/renamed) category finds nothing and mints new ids.
                    $itemId = $this->takeReusedItemId($previousItemIds, (string) $cat->id, (string) $item['name']) ?? (string) Str::uuid();
                    $rows[] = [
                        'id' => $itemId,
                        'menu_id' => $menu->id,
                        'category_id' => $cat->id,
                        'position' => $position++,
                        'name' => $item['name'],
                        'description' => $item['description'] ?? null,
                        'image_url' => $item['imageUrl'] ?? null,
                        // Hero-first image set (JSON like badges — bulk insert bypasses casts).
                        'images' => isset($item['images']) ? json_encode($item['images']) : null,
                        'rating' => $item['rating'] ?? null,
                        'rating_count' => $item['ratingCount'] ?? null,
                        // Kept as JSONB (reviewed 2026-07-04, #FOUND-13) — display-only, no query pattern exists.
                        'badges' => isset($item['badges']) ? json_encode($item['badges']) : null,
                        'base_price' => $item['basePrice'] ?? null,
                        'pickup_price' => $item['pickupPrice'] ?? null,
                        'pickup_source' => $item['pickupSource'] ?? null,
                        'delivery_price' => $item['deliveryPrice'] ?? null,
                        'delivery_source' => $item['deliverySource'] ?? null,
                        'dd_external_id' => $item['ddExternalId'] ?? null,
                        'currency' => $item['currency'] ?? null,
                        // Scraper output is never manual (an edited scraped dish flips
                        // is_manual and is preserved above, not re-inserted here).
                        'is_manual' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    foreach (($item['platforms'] ?? []) as $p) {
                        if (! is_array($p) || ! isset($p['platform'])) {
                            continue;
                        }
                        $platformRows[] = [
                            'id' => (string) Str::uuid(),
                            'menu_item_id' => $itemId,
                            'platform' => $p['platform'],
                            'pickup_price' => $p['pickupPrice'] ?? null,
                            'pickup_url' => $p['pickupUrl'] ?? null,
                            'delivery_price' => $p['deliveryPrice'] ?? null,
                            'delivery_url' => $p['deliveryUrl'] ?? null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($rows !== []) {
                    // Bulk insert (bypasses casts — badges already JSON).
                    MenuItem::query()->insert($rows);
                }
            }

            if ($platformRows !== []) {
                MenuItemPlatform::query()->insert($platformRows);
            }
        });
    }

    /**
     * Category ids under $menuId that the SCRAPER is allowed to wholesale
     * delete/replace — every category except owner-authored ones:
     * source_platform='scan' (menu-scan-apply, MenuScanApplier) and
     * source_platform='manual' (MenuContentController). Shared by persist()'s
     * rebuild and clearScrapedContent() below so both delete points treat
     * owner content identically. NULL source_platform (shouldn't occur in
     * practice — every scraper-written category always sets one) is treated
     * as rebuildable, matching the pre-scan behaviour of deleting everything.
     *
     * NOTE: a category IS rebuildable while still protecting any is_manual dish
     * inside it — persist()/clearScrapedContent() delete only the scraped
     * (is_manual=false) items and keep the category alive if a manual dish
     * remains. This method gates the CATEGORY delete; item-level preservation
     * is enforced at the delete sites.
     *
     * @return Collection<int, string>
     */
    private function rebuildableCategoryIds(string $menuId): Collection
    {
        return MenuCategory::query()
            ->where('menu_id', $menuId)
            ->where(fn ($q) => $q->whereNull('source_platform')->orWhereNotIn('source_platform', ['scan', 'manual']))
            ->pluck('id');
    }

    /**
     * normalized name → FIFO list of row ids, from a (id, name) collection in
     * its already-sorted stable order. Feeds the persist() identity reuse:
     * each rebuilt row shifts the oldest unclaimed id sharing its name.
     *
     * @param  Collection<int, MenuCategory>  $rows
     * @return array<string, list<string>>
     */
    private function idsByNormalizedName($rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $key = $this->normalizeName((string) $row->name);
            if ($key !== '') {
                $map[$key][] = (string) $row->id;
            }
        }

        return $map;
    }

    /** Shift (consume) the oldest previous id recorded under this name, or null when none is left. */
    private function takeReusedId(array &$idsByName, string $name): ?string
    {
        $key = $this->normalizeName($name);
        if ($key === '' || empty($idsByName[$key])) {
            return null;
        }

        return array_shift($idsByName[$key]);
    }

    /**
     * Item flavour of idsByNormalizedName: "<categoryId>|<normalized name>" →
     * FIFO list of item ids. Category-scoped so same-named dishes in two
     * categories can never trade identities across rebuilds (see persist()).
     *
     * @param  Collection<int, MenuItem>  $rows
     * @return array<string, list<string>>
     */
    private function itemIdsByCategoryAndName($rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $key = $this->normalizeName((string) $row->name);
            if ($key !== '') {
                $map[((string) $row->category_id).'|'.$key][] = (string) $row->id;
            }
        }

        return $map;
    }

    /** Shift the oldest previous item id recorded under (category, name), or null. */
    private function takeReusedItemId(array &$idsByCatAndName, string $categoryId, string $name): ?string
    {
        $key = $this->normalizeName($name);
        if ($key === '') {
            return null;
        }
        $mapKey = $categoryId.'|'.$key;
        if (empty($idsByCatAndName[$mapKey])) {
            return null;
        }

        return array_shift($idsByCatAndName[$mapKey]);
    }

    /**
     * lowercase, non-alphanumerics → single spaces, trimmed. Deliberately the
     * same normalization MenuMerger::norm() applies when matching one dish
     * across platforms, so "identity" means the same thing at merge time and
     * at persist time. (Kept local — duplication over a shared dependency,
     * matching the menu controllers' own convention.)
     */
    private function normalizeName(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('~[^a-z0-9]+~', ' ', $s) ?? '';

        return trim((string) preg_replace('~\s+~', ' ', $s));
    }

    /**
     * "<normalized category>|<normalized name>" set of dishes the owner deleted
     * from scraped content (menus.suppressed_items, written by
     * MenuContentController). The rebuild skips reinserting any scraped dish
     * matching one — same normalization as the identity matching, so "match"
     * means the same thing here as everywhere else.
     *
     * @return array<string, true>
     */
    private function suppressedItemKeys(Menu $menu): array
    {
        $keys = [];
        foreach (($menu->suppressed_items ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $this->normalizeName((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $keys[$this->normalizeName((string) ($entry['category'] ?? '')).'|'.$name] = true;
        }

        return $keys;
    }

    /**
     * $scanItems minus every dish the owner deleted (menus.suppressed_items),
     * matched by normalized NAME ONLY — deliberately looser than persist()'s
     * category+name suppression key. Scan category names rarely equal scrape
     * category names ("Beverages" vs "Drinks"), and the owner's delete intent
     * is "this dish, gone", not category-scoped; a category-scoped match here
     * would let the AUTOMATIC scan reapply recreate the deleted dish under a
     * scan category every rebuild. Applies ONLY to this automatic reapply —
     * the MANUAL dashboard /scan/apply stays unfiltered, because an explicit
     * re-scan is the user asking for that photo's content back.
     *
     * @param  list<mixed>  $scanItems
     * @return list<mixed>
     */
    private function withoutSuppressedScanItems(array $scanItems, Menu $menu): array
    {
        $suppressedNames = [];
        foreach (($menu->suppressed_items ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $this->normalizeName((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $suppressedNames[$name] = true;
            }
        }
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
     * Clear every NON-scan-sourced category/item/item-platform row for a user
     * (the same scope persist() rebuilds), used when no ordering platform is
     * connected at all. Also clears the menu's platformLinks — by definition
     * no platform is connected at this point, so no menu_platform_links row
     * can be legitimately valid afterward; leaving one behind would let a
     * later reconnect's urlUnchanged+settled skip-gate (handle(), above)
     * wrongly compare against stale data and no-op a scrape that should run.
     * Manual dishes (is_manual) are preserved the same way persist() preserves
     * them — only the scraped items are deleted, and a rebuildable category that
     * still holds a manual dish survives. When nothing owner-authored remains
     * afterward, the menu row itself is soft-deleted — IDENTICAL to the prior
     * unconditional-delete behaviour for every user who never used scan/manual.
     * When owner content DOES remain, the row survives and content_source flips
     * to the accurate remaining source ('scan' when scan content exists, else
     * 'manual') instead of keeping a now-inaccurate scraped platform name.
     */
    private function clearScrapedContent(string $userId): void
    {
        $menu = Menu::query()->where('user_id', $userId)->first();
        if ($menu === null) {
            return;
        }

        DB::connection('pgsql')->transaction(function () use ($menu) {
            $rebuildableCategoryIds = $this->rebuildableCategoryIds($menu->id);
            // Delete only scraped items — manual dishes survive even when no
            // ordering platform is connected at all.
            $deletableItemIds = MenuItem::query()
                ->whereIn('category_id', $rebuildableCategoryIds)
                ->where('is_manual', false)
                ->pluck('id');
            MenuItemPlatform::query()->whereIn('menu_item_id', $deletableItemIds)->delete();
            MenuItem::query()->whereIn('id', $deletableItemIds)->delete();

            // Drop rebuildable categories that are now empty; keep any that still
            // hold a manual dish (its name/position intact).
            $nonEmptyCategoryIds = MenuItem::query()
                ->whereIn('category_id', $rebuildableCategoryIds)
                ->distinct()->pluck('category_id');
            MenuCategory::query()
                ->whereIn('id', $rebuildableCategoryIds)
                ->whereNotIn('id', $nonEmptyCategoryIds)
                ->delete();

            $menu->platformLinks()->delete();

            if (! $menu->categories()->exists()) {
                $menu->delete();
            } else {
                $menu->forceFill(['content_source' => $this->remainingContentSource($menu)])->save();
            }
        });

        // Scraped rows were removed from the public menu — bust the edge cache
        // (a menu existed, so this is a real content change, not a no-op).
        $this->bustSiteCache($userId);
    }

    /**
     * The content_source to stamp on a menu whose scraped content was just
     * cleared but that still has owner-authored content: 'scan' when any
     * scan-sourced category remains (preserves the prior scan-only behaviour),
     * otherwise 'manual' (a scraped category kept alive purely by a manual dish
     * has an inaccurate scraped source_platform, so 'manual' is the honest label).
     */
    private function remainingContentSource(Menu $menu): string
    {
        $hasScan = MenuCategory::query()
            ->where('menu_id', $menu->id)
            ->where('source_platform', 'scan')
            ->exists();

        return $hasScan ? 'scan' : 'manual';
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
        app(PlatformHealthNotifier::class)->menuScrapeFailed((string) $this->userId);
    }
}
