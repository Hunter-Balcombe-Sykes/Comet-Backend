<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
class MenuFetchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Up to two real store scrapes (UE + DD), each retried on empty; allow
    // headroom for MAX_ATTEMPTS × ATTEMPT_TIMEOUT per platform in MenuApifyScraper.
    public int $timeout = 600;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $maxExceptions = 2;

    // One menu fetch per user at a time; the window exceeds $timeout so a
    // duplicate dispatch can't slip in and bill a second run mid-flight.
    public int $uniqueFor = 660;

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

    public function handle(MenuSource $source, MenuApifyScraper $scraper, MenuMerger $merger): void
    {
        $plan = $source->resolveAll($this->userId);

        // No Uber Eats / DoorDash link → clear any existing menu.
        if ($plan === null) {
            Menu::query()->where('user_id', $this->userId)->delete();

            return;
        }

        $existing = Menu::query()->where('user_id', $this->userId)->first();

        // Skip the scrape when both store URLs are unchanged, the last fetch
        // succeeded, EVERY connected platform last scraped 'ok', and this isn't a
        // forced refresh — links recompute at read time, so there's nothing to do.
        // A platform that's connected but last came back 'unavailable' (a flaky /
        // bot-blocked scrape) is NOT settled, so we re-scrape to recover it rather
        // than leaving the menu permanently single-platform.
        if (! $this->force
            && $existing
            && $existing->fetch_status === 'ok'
            && $existing->uber_eats_store_url === $plan['ueUrl']
            && $existing->doordash_store_url === $plan['ddUrl']
            && $this->platformSettled($existing, 'uber-eats', $plan['ueUrl'])
            && $this->platformSettled($existing, 'doordash', $plan['ddUrl'])) {
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
                'uber_eats_store_url' => $plan['ueUrl'],
                'doordash_store_url' => $plan['ddUrl'],
                'fetch_status' => 'pending',
            ],
        );

        // Per-platform consolidated store links (one store's pickup + delivery rows
        // already collapsed) — the scrape targets AND each item's modes/url source.
        $storeLinks = $source->storeLinks($this->userId);
        $ueLink = $storeLinks['uber-eats'] ?? null;
        $ddLink = $storeLinks['doordash'] ?? null;

        // Scrape each connected platform across BOTH modes (fetchStore hits the
        // pickup + delivery pages and fuses per-mode prices per dish).
        $ueMenu = $ueLink ? $scraper->fetchStore($ueLink, 'uber-eats', $this->userId) : null;
        $ddMenu = $ddLink ? $scraper->fetchStore($ddLink, 'doordash', $this->userId, $plan['address']) : null;

        $now = now();

        // Per-platform sync status, independent of the merge outcome.
        $menu->forceFill(array_filter([
            'uber_eats_synced_at' => $ueLink ? $now : null,
            'uber_eats_status' => $ueLink ? ($ueMenu ? 'ok' : 'unavailable') : null,
            'doordash_synced_at' => $ddLink ? $now : null,
            'doordash_status' => $ddLink ? ($ddMenu ? 'ok' : 'unavailable') : null,
        ], fn ($v) => $v !== null))->save();

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
        $merged = $merger->merge($ueMenu, $ddMenu, $contentSource, $storeLinks);

        $this->persist($menu, $contentSource, $merged, $now);
    }

    /**
     * Whether a platform is "settled" for skip purposes: an unconnected platform
     * (no store URL) always is; a connected one only when its last scrape was 'ok'.
     * A connected-but-'unavailable' platform forces a re-scrape (recovery).
     */
    private function platformSettled(Menu $menu, string $platform, ?string $url): bool
    {
        if ($url === null) {
            return true;
        }
        $status = $platform === 'uber-eats' ? $menu->uber_eats_status : $menu->doordash_status;

        return $status === 'ok';
    }

    /**
     * Replace the menu's categories + items wholesale within a transaction, and
     * write the resolved store-level fields.
     *
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}  $merged
     */
    private function persist(Menu $menu, string $contentSource, array $merged, Carbon $now): void
    {
        DB::connection('pgsql')->transaction(function () use ($menu, $contentSource, $merged, $now) {
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

            foreach ($merged['categories'] as $ci => $category) {
                $cat = MenuCategory::create([
                    'menu_id' => $menu->id,
                    'name' => $category['name'],
                    'position' => $ci,
                    'source_platform' => $category['sourcePlatform'],
                ]);

                $rows = [];
                foreach ($category['items'] as $ii => $item) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'menu_id' => $menu->id,
                        'category_id' => $cat->id,
                        'position' => $ii,
                        'name' => $item['name'],
                        'description' => $item['description'] ?? null,
                        'image_url' => $item['imageUrl'] ?? null,
                        'rating' => $item['rating'] ?? null,
                        'rating_count' => $item['ratingCount'] ?? null,
                        'badges' => isset($item['badges']) ? json_encode($item['badges']) : null,
                        'base_price' => $item['basePrice'] ?? null,
                        'pickup_price' => $item['pickupPrice'] ?? null,
                        'pickup_source' => $item['pickupSource'] ?? null,
                        'delivery_price' => $item['deliveryPrice'] ?? null,
                        'delivery_source' => $item['deliverySource'] ?? null,
                        'platforms' => isset($item['platforms']) ? json_encode($item['platforms']) : null,
                        'dd_external_id' => $item['ddExternalId'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    // Bulk insert (bypasses casts — badges/platforms already JSON).
                    MenuItem::query()->insert($rows);
                }
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
