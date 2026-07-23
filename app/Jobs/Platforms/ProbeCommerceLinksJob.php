<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\EventsSeeder;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopBrandSeeder;
use App\Services\Platforms\ShopProductSeeder;
use App\Services\Platforms\ShopProviderDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Resolves ONE scanned link into the richest thing it can be (signup-v2 C4):
//
//   category 'event'           → standalone event row      (EventsSeeder)
//   category 'event-organiser' → organiser account row     (EventsSeeder)
//   category 'shop'            → ShopBrand                 (detector → ShopBrandSeeder)
//   category null (unknown)    → probe: product page?      (ShopProductSeeder)
//                                       storefront?        (detector → ShopBrandSeeder)
//                                       neither            → custom link
//
// Every miss/failure falls through to CustomLinkSeeder — "nothing vanishes,"
// upgraded. Dispatched (not inline) from InstagramConnectionSeeder and
// LinkInBioScanJob because the probe chain is up to ~5 HTTP round-trips and
// the event/brand fetches are full page loads — InstagramConnectJob's 150s
// budget is mostly consumed by Apify's 110s scrape, so this work must never
// ride inside it. Dispatch sites budget how many links get a probe per scan
// (excess goes straight to CustomLinkSeeder there).
//
// Gating (all fail-closed, resolved here because this job runs detached from
// the scan that queued it): missing user, pending deletion, and DISC-7
// can_autosync_scraped_connections (an unclaimed provisional subject has not
// consented to auto-created connections) all downgrade to a custom link — and
// the custom-link seeder itself re-checks deletion + feature availability.
class ProbeCommerceLinksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30];

    // Worst case: detector probe chain + a brand-profile fetch, all on 8s
    // fetch timeouts — 90s covers it with room.
    public int $timeout = 90;

    /** Coalesce duplicate probes of the same link queued within the window. */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $userId,
        public readonly string $url,
        public readonly ?string $category = null,
        public readonly ?string $platform = null,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.sha1($this->url);
    }

    public function handle(
        GenericShopScraper $generic,
        ShopProviderDetector $detector,
        ShopBrandSeeder $brands,
        ShopProductSeeder $products,
        EventsSeeder $events,
        CustomLinkSeeder $links,
    ): void {
        $user = User::find($this->userId);
        if ($user === null || $user->isPendingDeletion()) {
            return;
        }

        // DISC-7 fail-closed: no auto-created connections for a not-yet-
        // consenting provisional subject. The custom-link fallback applies its
        // own feature gate, so routing there is safe either way.
        if (! AccountCapabilities::for($user)->can_autosync_scraped_connections) {
            $links->seed($user, $this->url);

            return;
        }

        try {
            $resolved = match ($this->category) {
                'event' => $events->seedStandalone($user, (string) $this->platform, $this->url),
                'event-organiser' => $events->seedAccount($user, (string) $this->platform, $this->url),
                'shop' => $this->seedStore($detector, $brands, $user, $this->url),
                default => $this->probe($generic, $detector, $brands, $products, $user),
            };
        } catch (Throwable $e) {
            report($e);
            $resolved = false;
        }

        if (! $resolved) {
            $links->seed($user, $this->url);
        }

        Log::info('commerce_probe.resolved', [
            'user_id' => $this->userId,
            'category' => $this->category ?? 'probe',
            'resolved' => $resolved,
        ]);
    }

    /** Unknown link: one read discriminates product page / storefront / neither. */
    private function probe(
        GenericShopScraper $generic,
        ShopProviderDetector $detector,
        ShopBrandSeeder $brands,
        ShopProductSeeder $products,
        User $user,
    ): bool {
        $read = $generic->readProductPage($this->url);

        if ($read['outcome'] === GenericShopScraper::OUTCOME_PRODUCT && is_array($read['product'])) {
            return $products->seed($user, $read['product']);
        }

        if ($read['outcome'] === GenericShopScraper::OUTCOME_STORE_PAGE && is_string($read['storeUrl'])) {
            return $this->seedStore($detector, $brands, $user, $read['storeUrl']);
        }

        return false;
    }

    private function seedStore(ShopProviderDetector $detector, ShopBrandSeeder $brands, User $user, string $url): bool
    {
        $detected = $detector->detect($url);
        if ($detected === null) {
            return false;
        }

        return $brands->seed($user, $detected) !== null;
    }
}
