<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\Platforms\ShopCatalog;
use App\Services\PreAccount\BuildProgress;
use App\Services\Shop\ShopAutoSelector;
use App\Services\Shop\ShopConnections;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
        if ($store !== null && $store->userId !== null) {
            // Setup progress (2026-09-02): the store stage has STARTED.
            BuildProgress::noteForUser((string) $store->userId, PreAccountBuildEvent::STAGE_SHOP, PreAccountBuildEvent::STATUS_STARTED, 'Syncing your store');
        }
        if ($store === null || $store->userId === null) {
            return;
        }

        // Best-effort, mirroring ShopBrandConnectJob's fill tail: a failed
        // fetch is not a failed connect, and the fetch gate/circuit machinery
        // owns retries. The auto-select still runs — selectInitial() treats an
        // empty catalogue as "no stamp", so a later reconnect gets its chance.
        // Best-effort means no retry, not no alert — report() still fires so
        // Nightwatch sees it; a prior version of this exact gap (Log::warning
        // only, no report()) already reached production once.
        try {
            $catalog->syncLatest($store, (string) $store->userId);
        } catch (Throwable $e) {
            report($e);
            Log::warning('shop.initial_fill_job.fill_failed', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
                'error' => $e->getMessage(),
            ]);
        }

        // A.7: a sign-up build's store fills its catalogue but PINS nothing —
        // the setup dialog's shop pass offers the products instead. Staff
        // demo builds keep the auto-select (nobody is there to pick).
        $signupUnclaimed = ($storeUser = User::query()->find($store->userId)) !== null
            && $storeUser->isUnclaimed()
            && PreAccountBuild::latestIsSignup((string) $store->userId);
        if ($signupUnclaimed) {
            Log::info('shop.initial_fill_job.auto_select_skipped_signup', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
            ]);
        } else {
            try {
                $selector->selectInitial($this->collectionId);
            } catch (Throwable $e) {
                report($e);
                Log::warning('shop.initial_fill_job.auto_select_failed', [
                    'collection_id' => $this->collectionId,
                    'user_id' => $store->userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Setup progress (2026-09-02): the store row the signup card shows —
        // its name/logo and the first few products' artwork.
        try {
            $products = DB::connection('pgsql')->table('content.items as i')
                ->leftJoin('content.item_media as im', fn ($j) => $j->on('im.item_id', '=', 'i.id')->where('im.role', 'cover'))
                ->leftJoin('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
                ->where('i.user_id', (string) $store->userId)
                ->where('i.kind', 'product')
                ->whereNull('i.removed_at')
                ->orderByDesc('i.created_at')
                ->limit(4)
                ->get(['i.headline_cache', 'ma.source_url'])
                ->map(fn ($r) => ['name' => (string) $r->headline_cache, 'image' => is_string($r->source_url) ? $r->source_url : null])
                ->all();
            $storeName = $store->name ?: (string) (parse_url((string) $store->url, PHP_URL_HOST) ?: 'your store');
            BuildProgress::noteForUser(
                (string) $store->userId,
                PreAccountBuildEvent::STAGE_SHOP,
                PreAccountBuildEvent::STATUS_LANDED,
                'Synced your store: '.$storeName,
                ['store' => $storeName, 'url' => $store->url, 'logo' => $store->logoUrl, 'discountCode' => $store->discountCode ?: null, 'products' => $products],
            );
        } catch (Throwable $e) {
            // Feed decoration only — never an exception report for it.
            Log::debug('shop.initial_fill_job.progress_note_skipped', ['collection_id' => $this->collectionId, 'error' => $e->getMessage()]);
        }
    }

    public function failed(Throwable $e): void
    {
        // Setup progress (2026-09-02): an owed stage gets its answer.
        try {
            $store = app(ShopConnections::class)->storeByCollection($this->collectionId);
            if ($store !== null && $store->userId !== null) {
                BuildProgress::noteForUser((string) $store->userId, PreAccountBuildEvent::STAGE_SHOP, PreAccountBuildEvent::STATUS_FAILED, "Couldn't sync your store just now");
            }
        } catch (Throwable) {
        }
        report($e);
        Log::error('shop.initial_fill_job.failed', [
            'collection_id' => $this->collectionId,
            'error' => $e->getMessage(),
        ]);
    }
}
