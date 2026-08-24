<?php

namespace App\Jobs\Platforms;

use App\Services\Platforms\ShopCatalog;
use App\Services\Shop\ShopAutoSelector;
use App\Services\Shop\ShopConnections;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lane (b)'s twin of ShopBrandConnectJob's initial-fill tail (L-4, owner run
 * 2026-08-20): a scan-suggested store accepted through StoreBrandSeeder never
 * passes through ShopBrandConnectJob — its settle() is compare-and-set on
 * connect_status='pending', and seeder stores are minted settled — so the
 * catalogue stayed empty until the 6-hourly ShopFetch, and the first-connect
 * auto-select would have nothing to pick. This job carries exactly the two
 * post-settle steps that lane needs: the one-shot catalogue fill, then
 * ShopAutoSelector::selectInitial(). Both are idempotent, so a duplicate
 * dispatch is harmless.
 */
class ShopInitialFillJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var list<int> Seconds. */
    public array $backoff = [10];

    // NOT ShopBrandConnectJob's 75s: that ceiling covers ONE profile fetch,
    // while this job does the whole catalogue fill (N product upserts) plus
    // the auto-select. Measured live (T5/T7, 2026-08-20): a 7-product fill
    // against a remote DB ran ~70-80s and the 75s ceiling killed the worker
    // between fill and select — the once-only select then waited for the 6h
    // ShopFetch late hook instead of firing at connect time.
    public int $timeout = 240;

    // Must exceed $timeout (HorizonQueueCoverageTest): a unique job can
    // otherwise outrun its own dedupe lock mid-run.
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $collectionId,
    ) {
        $this->onQueue(config('partna.queues.platform_connect'));
    }

    public function uniqueId(): string
    {
        return "shop-store-fill:{$this->collectionId}";
    }

    public function handle(ShopConnections $shop, ShopCatalog $catalog, ShopAutoSelector $selector): void
    {
        $store = $shop->storeByCollection($this->collectionId);
        if ($store === null || $store->userId === null) {
            return;
        }

        // Best-effort, mirroring ShopBrandConnectJob's fill tail: a failed
        // fetch is not a failed connect, and the fetch gate/circuit machinery
        // owns retries. The auto-select still runs — selectInitial() treats an
        // empty catalogue as "no stamp", so a later reconnect gets its chance.
        try {
            $catalog->syncLatest($store, (string) $store->userId);
        } catch (Throwable $e) {
            Log::warning('shop.initial_fill_job.fill_failed', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $selector->selectInitial($this->collectionId);
        } catch (Throwable $e) {
            Log::warning('shop.initial_fill_job.auto_select_failed', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('shop.initial_fill_job.failed', [
            'collection_id' => $this->collectionId,
            'error' => $e->getMessage(),
        ]);
    }
}
