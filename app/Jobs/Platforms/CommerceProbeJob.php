<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Brand\StoreBrandSeeder;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\EventsSeeder;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopProductSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves ONE scanned link into the richest thing it can be.
 *
 * Replaces ProbeCommerceLinksJob (2026-07-25, Phase 10). The key difference:
 * this job calls CustomLinkSeeder::seedCustom() on failure, not seed() —
 * avoiding the mutual recursion between seed() → LinkRouter → seed().
 *
 * Resolution chain:
 *   category 'event'           → EventsSeeder::seedStandalone()
 *   category 'event-organiser' → EventsSeeder::seedAccount()
 *   category 'shop'            → StoreBrandSeeder (probe subsumes detection)
 *   category null (unknown)    → GenericShopScraper probe → product/store/neither
 *
 * Every miss falls through to seedCustom() — "nothing vanishes."
 *
 * WAVE-2C (2026-08-06): seedStore() used to run ShopProviderDetector::detect()
 * then hand the result to the legacy ShopBrandSeeder. StoreBrandSeeder's own
 * probe (LinkProbeWorker) subsumes that detection step entirely, so this job
 * no longer needs ShopProviderDetector at all.
 */
class CommerceProbeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30];

    public int $timeout = 90;

    public int $uniqueFor = 300;

    /**
     * This job is reached from LinkRouter for BOTH a manually-added link
     * (CustomLinksController) and a harvested one (Instagram bio scrape,
     * link-in-bio unroll) — LinkRouter::route() carries no origin parameter,
     * so which of those it was is genuinely not known here. Deliberately NOT
     * 'paste': that origin bypasses the tombstone check (RoutingContext::
     * isDirectRequest()), and overclaiming directness for a link that may
     * have been harvested would risk resurrecting something a user removed.
     * A distinct origin, like every other importer's own string
     * (website_import, the LinkInBioImporter kind), keeps this honest and
     * tombstone-safe.
     */
    private const ORIGIN = 'commerce_probe';

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
        StoreBrandSeeder $brands,
        ShopProductSeeder $products,
        EventsSeeder $events,
        CustomLinkSeeder $links,
    ): void {
        $user = User::find($this->userId);
        if ($user === null || $user->isPendingDeletion()) {
            return;
        }

        try {
            $resolved = match ($this->category) {
                'event' => $events->seedStandalone($user, (string) $this->platform, $this->url),
                'event-organiser' => $events->seedAccount($user, (string) $this->platform, $this->url),
                'shop' => $this->seedStore($brands, $user, $this->url),
                default => $this->probe($generic, $brands, $products, $user),
            };
        } catch (Throwable $e) {
            report($e);
            $resolved = false;
        }

        if (! $resolved) {
            $links->seedCustom($user, $this->url);
        }

        Log::info('commerce_probe.resolved', [
            'user_id' => $this->userId,
            'category' => $this->category ?? 'probe',
            'resolved' => $resolved,
        ]);
    }

    private function probe(
        GenericShopScraper $generic,
        StoreBrandSeeder $brands,
        ShopProductSeeder $products,
        User $user,
    ): bool {
        $read = $generic->readProductPage($this->url);

        if ($read['outcome'] === GenericShopScraper::OUTCOME_PRODUCT && is_array($read['product'])) {
            return $products->seed($user, $read['product']);
        }

        if ($read['outcome'] === GenericShopScraper::OUTCOME_STORE_PAGE && is_string($read['storeUrl'])) {
            return $this->seedStore($brands, $user, $read['storeUrl']);
        }

        if ($read['outcome'] === GenericShopScraper::OUTCOME_NO_PRODUCT) {
            return $this->seedStore($brands, $user, $this->url);
        }

        return false;
    }

    private function seedStore(StoreBrandSeeder $brands, User $user, string $url): bool
    {
        return $brands->seed($user, $url, self::ORIGIN)['outcome'] === 'placed';
    }

    public function failed(Throwable $e): void
    {
        report($e);

        Log::error('platforms.commerce_probe.failed', [
            'user_id' => $this->userId,
            'url' => $this->url,
            'category' => $this->category,
            'platform' => $this->platform,
            'error' => $e->getMessage(),
        ]);
    }
}
