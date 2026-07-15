<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\MenuPlatformLink;
use App\Services\Notifications\Dispatchers\PlatformHealthNotifier;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
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

        $existing = Menu::query()->where('user_id', $this->userId)->with('platformLinks')->first();
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

        // Per-platform sync status, independent of the merge outcome — only for
        // connected platforms (those with a store link).
        foreach ($storeLinks as $platform => $link) {
            MenuPlatformLink::updateOrCreate(
                ['menu_id' => $menu->id, 'platform' => $platform],
                ['synced_at' => $now, 'status' => ($menus[$platform] ?? null) !== null ? 'ok' : 'unavailable'],
            );
        }

        // Nothing usable from ANY connected platform — keep the last menu, mark
        // unavailable so the dashboard stops polling. A manual refresh retries.
        if (array_filter($menus) === []) {
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
        $merged = $merger->merge($menus, $contentSource, $storeLinks);

        $this->persist($menu, $contentSource, $merged, $now);
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
     * Replace the menu's categories + items + per-platform availability wholesale
     * within a transaction, and write the resolved store-level fields.
     *
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}  $merged
     */
    private function persist(Menu $menu, string $contentSource, array $merged, Carbon $now): void
    {
        DB::connection('pgsql')->transaction(function () use ($menu, $contentSource, $merged, $now) {
            // Wholesale rebuild (delete children → reinsert) is intentional, not a perf gap:
            // persist() only runs when handle()'s unchanged-skip gate misses (genuine content
            // change / forced refresh / recovery), so it is not a hot path. There is also no
            // stable per-item identity to diff against — ue_external_id was dropped and
            // menu_items.id is a fresh UUID each scrape; the menu is read fresh on both the
            // dashboard and the public sitepage, so UUID churn is invisible to consumers.
            // Keep it atomic in the txn.
            //
            // Scoped to rebuildableCategoryIds() (NOT every category) — scan-sourced
            // categories (source_platform='scan', written by MenuScanApplier) are never
            // scraper output and must survive every rebuild; see that method's docblock.
            //
            // Also clears children explicitly (FK cascade covers this on Postgres, but being
            // explicit prevents orphaned item-platform rows in SQLite tests).
            $categoryIds = $this->rebuildableCategoryIds($menu->id);
            $itemIds = MenuItem::query()->whereIn('category_id', $categoryIds)->pluck('id');
            MenuItemPlatform::query()->whereIn('menu_item_id', $itemIds)->delete();
            MenuItem::query()->whereIn('category_id', $categoryIds)->delete();
            MenuCategory::query()->whereIn('id', $categoryIds)->delete();

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
                $cat = MenuCategory::create([
                    'menu_id' => $menu->id,
                    'name' => $category['name'],
                    'position' => $ci,
                    'source_platform' => $category['sourcePlatform'],
                ]);

                $rows = [];
                foreach ($category['items'] as $ii => $item) {
                    $itemId = (string) Str::uuid();
                    $rows[] = [
                        'id' => $itemId,
                        'menu_id' => $menu->id,
                        'category_id' => $cat->id,
                        'position' => $ii,
                        'name' => $item['name'],
                        'description' => $item['description'] ?? null,
                        'image_url' => $item['imageUrl'] ?? null,
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
     * delete/replace — every category except source_platform='scan' ones
     * (menu-scan-apply endpoint, MenuScanApplier). Shared by persist()'s
     * rebuild and clearScrapedContent() below so both delete points treat
     * scan content identically. NULL source_platform (shouldn't occur in
     * practice — every scraper-written category always sets one) is treated
     * as rebuildable, matching the pre-scan behaviour of deleting everything.
     *
     * @return Collection<int, string>
     */
    private function rebuildableCategoryIds(string $menuId): Collection
    {
        return MenuCategory::query()
            ->where('menu_id', $menuId)
            ->where(fn ($q) => $q->whereNull('source_platform')->orWhere('source_platform', '!=', 'scan'))
            ->pluck('id');
    }

    /**
     * Clear every NON-scan-sourced category/item/item-platform row for a user
     * (the same scope persist() rebuilds), used when no ordering platform is
     * connected at all. Also clears the menu's platformLinks — by definition
     * no platform is connected at this point, so no menu_platform_links row
     * can be legitimately valid afterward; leaving one behind would let a
     * later reconnect's urlUnchanged+settled skip-gate (handle(), above)
     * wrongly compare against stale data and no-op a scrape that should run.
     * When nothing scan-sourced remains afterward, the menu row itself is
     * soft-deleted — IDENTICAL to the prior unconditional-delete behaviour
     * for every user who has never used menu scan. When scan content DOES
     * remain, the row survives and content_source flips to 'scan' (the only
     * real content left) instead of keeping a now-inaccurate scraped
     * platform name.
     */
    private function clearScrapedContent(string $userId): void
    {
        $menu = Menu::query()->where('user_id', $userId)->first();
        if ($menu === null) {
            return;
        }

        DB::connection('pgsql')->transaction(function () use ($menu) {
            $categoryIds = $this->rebuildableCategoryIds($menu->id);
            $itemIds = MenuItem::query()->whereIn('category_id', $categoryIds)->pluck('id');
            MenuItemPlatform::query()->whereIn('menu_item_id', $itemIds)->delete();
            MenuItem::query()->whereIn('category_id', $categoryIds)->delete();
            MenuCategory::query()->whereIn('id', $categoryIds)->delete();
            $menu->platformLinks()->delete();

            if (! $menu->categories()->exists()) {
                $menu->delete();
            } else {
                $menu->forceFill(['content_source' => 'scan'])->save();
            }
        });
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
