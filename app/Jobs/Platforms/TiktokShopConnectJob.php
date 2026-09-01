<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\TiktokShopScraper;
use App\Services\Shop\ShopAutoSelector;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Item 10b (2026-09-01): connect a TikTok Shop storefront (seller id or
 * tiktok.com/shop/store/… URL) as a shop-pool store — AmazonShopConnectJob's
 * twin, structure preserved line for line where the lanes match; see that
 * class for the shared reasoning (no-op-on-miss contract, lock discipline,
 * capability posture, timeout arithmetic). What differs here:
 *
 *  - ONE vendor call answers BOTH halves: storefront() returns the shop
 *    identity (name/logo/url) and the products blob already in syncStore's
 *    catalogue shape (TiktokShopProductsNormalizer's contract), so there is
 *    no separate products() normalizer step.
 *  - The anchor's surface is 'tiktok_shop.store' (a dedicated
 *    PROVIDER_SURFACE row, not the generic fallback), and minting it is
 *    what arms the REVIEWS lane: IntegrationConnectionObserver provisions
 *    the tiktok_shop ingest source off the connection (identifier =
 *    resource_id = seller id, SourceProvisioner's own arm) and the
 *    connector's eagerOnConnect pulls review pages under the same budget.
 *  - external_ref is the SELLER ID (the vendor-proven identity; slugs in
 *    pasted URLs are decorative) and currency is pinned USD with the
 *    region (TiktokShopScraper::CURRENCY).
 */
class TiktokShopConnectJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /** @var list<int> Seconds. */
    public array $backoff = [10];

    public int $timeout = 240;

    public int $uniqueFor = 300;

    /** The store cap, from `partna.shop_brands_max` — the ONE definition (#CFG-3). */
    private static function maxBrands(): int
    {
        return (int) config('partna.shop_brands_max');
    }

    public function __construct(
        public readonly string $userId,
        public readonly string $storefrontUrl,
    ) {
        $this->onQueue(config('partna.queues.platform_connect'));
    }

    public function uniqueId(): string
    {
        return 'tiktok-shop-connect:'.$this->userId.':'.sha1(trim($this->storefrontUrl));
    }

    public function handle(
        TiktokShopScraper $scraper,
        ShopConnections $shop,
        ShopContentWriter $content,
        ShopAutoSelector $selector,
        IntegrationConnectionCacheRefresher $refresher,
    ): void {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }
        if (! FeatureAvailability::for($user)->allows('integration.shop')) {
            return;
        }

        $sellerId = TiktokShopScraper::sellerIdFrom($this->storefrontUrl);
        if ($sellerId === null) {
            Log::info('shop.tiktok_shop_connect.unrecognized_url', [
                'user_id' => $user->id, 'url' => $this->storefrontUrl,
            ]);

            return;
        }

        // PRE-FLIGHT, before the billed call — see AmazonShopConnectJob.
        if ($this->existingStore($shop, $user, $sellerId) === null && $this->capReached($shop, $user)) {
            Log::info('shop.tiktok_shop_connect.cap_reached', ['user_id' => $user->id, 'seller_id' => $sellerId]);

            return;
        }

        // FETCH OUTSIDE THE LOCK. Budget claim/release/husk mechanics live
        // in the scraper; every miss folds to null (the no-op contract).
        $page = $scraper->storefront($sellerId);
        if ($page === null) {
            Log::info('shop.tiktok_shop_connect.no_answer', ['user_id' => $user->id, 'seller_id' => $sellerId]);

            return;
        }

        $key = CacheKeyGenerator::platformConnectionLock('shop', $user->id);

        try {
            /** @var array{0: string, 1: StoreRecord}|null $minted */
            $minted = Cache::lock($key, 10)->block(5, function () use ($shop, $content, $user, $sellerId, $page): ?array {
                $existing = $this->existingStore($shop, $user, $sellerId);
                if ($existing === null && $this->capReached($shop, $user)) {
                    Log::info('shop.tiktok_shop_connect.cap_reached', ['user_id' => $user->id, 'seller_id' => $sellerId]);

                    return null;
                }

                $shop->anchor($user, TiktokShopScraper::PROVIDER, $sellerId);
                $record = $this->record($shop, $user, $existing, $sellerId, $page);

                return [$content->upsertStore($record, (string) $user->id), $record];
            });
        } catch (LockTimeoutException $e) {
            // Never $this->release() — AmazonShopConnectJob's documented trap.
            report($e);
            Log::warning('shop.tiktok_shop_connect.lock_timeout', ['user_id' => $user->id, 'seller_id' => $sellerId]);

            return;
        }

        if ($minted === null) {
            return;
        }
        [$collectionId, $record] = $minted;

        $written = $content->syncStore(
            (string) $user->id,
            $collectionId,
            $page['products'],
            $record->currency,
        );

        try {
            $selector->selectInitial($collectionId);
        } catch (Throwable $e) {
            report($e);
            Log::warning('shop.tiktok_shop_connect.auto_select_failed', [
                'collection_id' => $collectionId, 'error' => $e->getMessage(),
            ]);
        }

        $connection = $shop->anchorFor($user, $sellerId);
        if ($connection !== null) {
            $refresher->refresh($connection);
        }
        $this->touchSite((string) $user->id);

        Log::info('shop.tiktok_shop_connect.synced', [
            'user_id' => $user->id, 'seller_id' => $sellerId,
            'collection_id' => $collectionId, 'written' => $written,
        ]);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('shop.tiktok_shop_connect.failed', [
            'user_id' => $this->userId,
            'url' => $this->storefrontUrl,
            'error' => $e->getMessage(),
        ]);
    }

    /** This lane's store for $sellerId, or null — provider-checked (see AmazonShopConnectJob). */
    private function existingStore(ShopConnections $shop, User $user, string $sellerId): ?StoreRecord
    {
        $store = $shop->stores($user)->get($sellerId);

        return $store !== null && $store->provider === TiktokShopScraper::PROVIDER ? $store : null;
    }

    private function capReached(ShopConnections $shop, User $user): bool
    {
        $count = $shop->stores($user)->filter(fn (StoreRecord $s): bool => $s->isIndividual === false)->count();

        return $count >= self::maxBrands();
    }

    /**
     * The record upsertStore() writes — minted settled (catalogue in hand),
     * folding onto the existing row on refresh so user edits survive; see
     * AmazonShopConnectJob::record() for the full reasoning.
     *
     * @param  array{shop: array{seller_id: string, name?: string, url?: string, logo?: string}, products: non-empty-list<array<string, mixed>>}  $page
     */
    private function record(ShopConnections $shop, User $user, ?StoreRecord $existing, string $sellerId, array $page): StoreRecord
    {
        // Canonical id URL, not the vendor's shop_link echo (it repeats
        // whatever slug the request carried — the scraper's own finding).
        $url = TiktokShopScraper::storeUrlFor($sellerId);

        if ($existing !== null) {
            return $existing->with([
                'name' => $existing->name ?? ($page['shop']['name'] ?? null),
                'url' => $url,
                'sourceUrl' => $url,
                'currency' => $existing->currency ?? TiktokShopScraper::CURRENCY,
                'logoUrl' => $existing->logoUrl ?? ($page['shop']['logo'] ?? null),
                'connectStatus' => null,
                'connectError' => null,
            ]);
        }

        $maxPosition = $shop->stores($user)->max('position');

        return new StoreRecord(
            externalRef: $sellerId,
            provider: TiktokShopScraper::PROVIDER,
            name: $page['shop']['name'] ?? null,
            position: ($maxPosition === null ? -1 : $maxPosition) + 1,
            url: $url,
            sourceUrl: $url,
            currency: TiktokShopScraper::CURRENCY,
            discountCode: '',
            referralQuery: '',
            isIndividual: false,
            fetchMode: null,
            connectStatus: null,
            connectError: null,
            logoUrl: $page['shop']['logo'] ?? null,
        );
    }

    /** Raw invalidation write — mirrors AmazonShopConnectJob::touchSite verbatim. */
    private function touchSite(string $userId): void
    {
        DB::connection('pgsql')->table('site.sites')
            ->where('user_id', $userId)
            ->update(['updated_at' => now()]);
    }
}
