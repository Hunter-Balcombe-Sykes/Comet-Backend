<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopBrandIdentity;
use App\Services\Platforms\ShopBrandProfiler;
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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connect a store the owner never asked to connect — because they pasted one
 * of its product pages (owner, 2026-08-17: "adding a product for a store we
 * can add as a store makes it just add the store"). The DEFERRED branch of
 * ShopController::addBrand, as a job: mint the anchor, write the pending
 * store row, hand off to ShopBrandConnectJob for the catalogue.
 *
 * Off-thread on purpose: the product itself is already added and pinned by
 * the time this runs; this only fills the LIBRARY. Auto-latest is written
 * OFF on the anchor (ShopConnections::anchor), so nothing else publishes.
 * Because product coords are URL-keyed, the synced record folds onto the
 * pasted item and upgrades it with the store's variants and gallery.
 *
 * Skips quietly when the store is already there, the cap is reached, or the
 * integration is unavailable to this owner — none of those is a failure the
 * owner can act on from a product paste.
 */
class ConnectStoreFromProductJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public array $backoff = [10];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** Mirrors ShopController::MAX_BRANDS. */
    private const MAX_BRANDS = 10;

    /**
     * @param  array<string, mixed>  $detected  ShopProviderDetector's detected block
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $detected,
    ) {
        $this->onQueue(config('partna.queues.platform_connect'));
    }

    public function uniqueId(): string
    {
        return 'connect-store-from-product:'.$this->userId.':'.sha1((string) ($this->detected['origin'] ?? ''));
    }

    public function handle(
        ShopBrandIdentity $identity,
        ShopBrandProfiler $profiler,
        ShopConnections $shop,
        ShopContentWriter $content,
        IntegrationConnectionCacheRefresher $refresher,
    ): void {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }
        if (! FeatureAvailability::for($user)->allows('integration.shop')) {
            return;
        }

        $detected = $this->detected;
        $id = $identity->for($detected);

        try {
            $collectionId = Cache::lock(CacheKeyGenerator::platformConnectionLock('shop', $user->id), 10)
                ->block(5, function () use ($user, $detected, $id, $profiler, $shop, $content, $refresher): ?string {
                    $stores = $shop->stores($user);
                    if ($stores->has($id)) {
                        return null; // already connected — nothing to do
                    }
                    $storeCount = $stores->filter(fn (StoreRecord $s): bool => $s->isIndividual === false)->count();
                    if ($storeCount >= self::MAX_BRANDS) {
                        Log::info('shop.connect_from_product.cap_reached', ['user_id' => $user->id, 'origin' => $detected['origin'] ?? null]);

                        return null;
                    }

                    $connection = $shop->anchor($user, $detected['provider'], $id);
                    $maxPosition = $stores->max('position');
                    $store = new StoreRecord(
                        externalRef: $id,
                        provider: $detected['provider'],
                        name: null,
                        position: ($maxPosition === null ? -1 : $maxPosition) + 1,
                        url: $detected['origin'],
                        sourceUrl: $detected['sourceUrl'],
                        currency: $profiler->syncCurrencyFor($detected),
                        discountCode: '',
                        referralQuery: '',
                        isIndividual: false,
                        fetchMode: $detected['fetchMode'] ?? null,
                        connectStatus: 'pending',
                        connectError: null,
                    );
                    $collectionId = $content->upsertStore($store, (string) $user->id);
                    $refresher->refresh($connection);

                    return $collectionId;
                });
        } catch (LockTimeoutException) {
            $this->release(15);

            return;
        }

        if ($collectionId !== null) {
            ShopBrandConnectJob::dispatch($collectionId);
        }
    }

    // R3-OBS-6: terminal visibility for Nightwatch.
    //
    // No terminal store write is owed here, unlike ShopBrandConnectJob::failed().
    // Either upsertStore() never ran — there is no row to settle — or it did and
    // the store is left 'pending', which connectStatus()'s StrandedPendingWindow
    // backstop already answers as 'failed' on the next poll (StoreRecord::$updatedAt).
    // The pasted product itself is added and pinned before this job runs; only the
    // library is missing, so there is nothing the owner can act on either way.
    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('shop.connect_store_from_product.failed', [
            'user_id' => $this->userId,
            'origin' => $this->detected['origin'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
