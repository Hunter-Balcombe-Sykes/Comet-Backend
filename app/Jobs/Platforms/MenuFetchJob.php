<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\MenuPlatformLink;
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

        // No Uber Eats / DoorDash link → clear any existing menu.
        if ($plan === null) {
            Menu::query()->where('user_id', $this->userId)->delete();

            return;
        }

        $existing = Menu::query()->where('user_id', $this->userId)->with('platformLinks')->first();
        $existingLinks = $existing?->platformLinks->keyBy('platform') ?? collect();

        // Skip the scrape when both store URLs are unchanged, the last fetch
        // succeeded, EVERY connected platform last scraped 'ok', and this isn't a
        // forced refresh — links recompute at read time, so there's nothing to do.
        // A platform that's connected but last came back 'unavailable' (a flaky /
        // bot-blocked scrape) is NOT settled, so we re-scrape to recover it rather
        // than leaving the menu permanently single-platform.
        if (! $this->force
            && $existing
            && $existing->fetch_status === 'ok'
            && ($existingLinks->get('uber-eats')?->store_url) === $plan['ueUrl']
            && ($existingLinks->get('doordash')?->store_url) === $plan['ddUrl']
            && $this->platformSettled($existingLinks, 'uber-eats', $plan['ueUrl'])
            && $this->platformSettled($existingLinks, 'doordash', $plan['ddUrl'])) {
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
        foreach (['uber-eats' => $plan['ueUrl'], 'doordash' => $plan['ddUrl']] as $platform => $url) {
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
        $storeLinks = $source->storeLinks($this->userId);
        $ueLink = $storeLinks['uber-eats'] ?? null;
        $ddLink = $storeLinks['doordash'] ?? null;

        // Scrape every connected platform across BOTH modes CONCURRENTLY (one
        // Http::pool round inside fetchStores) and fuse per-mode prices per dish.
        $links = array_filter(['uber-eats' => $ueLink, 'doordash' => $ddLink]);
        $menus = $scraper->fetchStores($links, $this->userId, $plan['address']);
        $ueMenu = $menus['uber-eats'] ?? null;
        $ddMenu = $menus['doordash'] ?? null;

        $now = now();

        // Per-platform sync status, independent of the merge outcome — only for
        // connected platforms (those with a store link).
        $statuses = [
            'uber-eats' => ['link' => $ueLink, 'menu' => $ueMenu],
            'doordash' => ['link' => $ddLink, 'menu' => $ddMenu],
        ];
        foreach ($statuses as $platform => $r) {
            if ($r['link'] === null) {
                continue;
            }
            MenuPlatformLink::updateOrCreate(
                ['menu_id' => $menu->id, 'platform' => $platform],
                ['synced_at' => $now, 'status' => $r['menu'] ? 'ok' : 'unavailable'],
            );
        }

        // Nothing usable from EITHER platform — keep the last menu, mark
        // unavailable so the dashboard stops polling. A manual refresh retries.
        if ($ueMenu === null && $ddMenu === null) {
            $menu->forceFill(['fetch_status' => 'unavailable', 'last_fetched_at' => $now])->save();

            return;
        }

        // Canonical structure prefers Uber Eats, but falls back to whichever
        // platform actually returned a menu. The union appends the other
        // platform's items either way; a connected platform that returned nothing
        // is still attached to every dish as a ghost (see MenuMerger).
        $contentSource = $ueMenu !== null ? 'uber-eats' : 'doordash';
        $merged = $merger->merge(
            ['uber-eats' => $ueMenu, 'doordash' => $ddMenu],
            $contentSource,
            $storeLinks,
        );

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
            // menu_items.id is a fresh UUID each scrape; the menu is dashboard-only and read
            // fresh, so UUID churn is invisible to consumers. Keep it atomic in the txn.
            //
            // Also clears children explicitly (FK cascade covers this on Postgres, but being
            // explicit prevents orphaned item-platform rows in SQLite tests).
            $itemIds = MenuItem::query()->where('menu_id', $menu->id)->pluck('id');
            MenuItemPlatform::query()->whereIn('menu_item_id', $itemIds)->delete();
            MenuItem::query()->where('menu_id', $menu->id)->delete();
            MenuCategory::query()->where('menu_id', $menu->id)->delete();

            $store = $merged['store'];
            $menu->forceFill([
                'content_source' => $contentSource,
                'store_name' => $store['name'] ?? null,
                'logo_url' => $store['logo'] ?? null,
                'rating' => $store['rating'] ?? null,
                'review_count' => $store['reviewCount'] ?? null,
                'currency' => $store['currency'] ?? 'AUD',
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

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('menu.fetch_job.failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);

        $menu = Menu::query()->where('user_id', $this->userId)->first();
        if ($menu) {
            $menu->forceFill(['fetch_status' => 'unavailable'])->save();
        }
    }
}
