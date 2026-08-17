<?php

namespace App\Jobs\Platforms;

use App\Services\Http\SafeUrlFetcher;
use App\Services\Media\Exceptions\LogoProcessorException;
use App\Services\Media\LogoProcessorClient;
use App\Services\Media\MediaDiskResolver;
use App\Services\Shop\ShopConnections;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Run a connected store's logo through the logo processor (background removal
 * + vectorization) and store the resulting marks on the brand row — the
 * dashboard's store cards and product badges render these instead of the raw
 * favicon.
 *
 * Best-effort by design: the raw favicon/logo URL columns always remain, so a
 * processor failure only means the dashboard keeps the unprocessed mark. Gated
 * on partna.logo_removal.store_enabled (plan §12's store switch), separate
 * from the user-logo switch.
 */
class ProcessShopBrandLogoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [60];

    public int $timeout = 280;

    /**
     * Re-home Task 9: the content.collections id, not the site.shop_brands uuid
     * PK. See ShopBrandConnectJob's constructor for why (spec §5).
     */
    public function __construct(public readonly string $collectionId) {}

    public function handle(LogoProcessorClient $client, SafeUrlFetcher $fetcher, ShopConnections $shop): void
    {
        if (! (bool) config('partna.logo_removal.store_enabled', false)) {
            return;
        }

        $store = $shop->storeByCollection($this->collectionId);
        if ($store === null || $store->isIndividual || $store->userId === null) {
            return;
        }

        // Prefer the store's real logo; the favicon is the fallback mark.
        $source = $store->logoUrl ?: $store->faviconUrl;
        if (! is_string($source) || ! str_starts_with($source, 'http')) {
            return;
        }

        // SSRF: $source is NOT trusted. PlatformScraper::favicon()/logo() lift the
        // href straight out of a <link rel="icon"> in scraped HTML, so a store the
        // visitor controls can point this at 169.254.169.254 or any internal host.
        // SafeUrlFetcher is the house guard for exactly this — public-IP-only, and
        // it re-validates every redirect hop. Never call Http::get() here.
        $response = $fetcher->tryFetch($source, [
            'User-Agent' => 'PartnaBot/1.0 (+https://partna.au)',
        ]);

        if ($response === null) {
            Log::info('shop.brand_logo.fetch_failed', [
                'collection_id' => $this->collectionId,
                'source' => $source,
            ]);

            return;
        }

        $bytes = (string) $response['body'];
        $status = (int) $response['status'];
        if ($status < 200 || $status >= 300 || $bytes === '' || strlen($bytes) > 10 * 1024 * 1024) {
            return;
        }

        $mime = (string) ($response['contentType'] ?: 'image/png');
        $mime = trim(explode(';', $mime)[0]) ?: 'image/png';

        try {
            $result = $client->process($bytes, basename(parse_url($source, PHP_URL_PATH) ?: 'logo'), $mime);
        } catch (LogoProcessorException $e) {
            Log::info('shop.brand_logo.process_failed', [
                'collection_id' => $this->collectionId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $diskName = MediaDiskResolver::resolve();
        $disk = Storage::disk($diskName);
        // Spec §5.1: the prefix moves from the legacy shop_brands uuid to the
        // collection id, so every NEW mark lands under a different path.
        //
        // EXISTING OBJECTS ARE DELIBERATELY NOT MIGRATED, and this is not loss:
        // the processed URLs are stored ABSOLUTE in logo_mark_url /
        // logo_mark_svg_url and keep resolving, and a re-process on the next
        // connect rewrites them under the new prefix for free. What changes is
        // that prefix-scan tooling keyed on collection ids will not find
        // pre-migration objects — recorded here so a later bucket audit reads
        // the stranded prefix as intentional rather than as orphaned data.
        $base = "shop-brands/{$this->collectionId}";

        $pngHash = substr(hash('sha256', $result->pngTransparent), 0, 16);
        $pngPath = "{$base}/mark_{$pngHash}.png";
        $disk->put($pngPath, $result->pngTransparent, 'public');

        $svgUrl = null;
        if ($result->vectorized()) {
            $svg = (string) $result->svg;
            $svgHash = substr(hash('sha256', $svg), 0, 16);
            $svgPath = "{$base}/mark_{$svgHash}.svg";
            $disk->put($svgPath, $svg, 'public');
            $svgUrl = $disk->url($svgPath);
        }

        // Re-home Task 9: ONE write, straight onto content.storefronts, where
        // the legacy row and a mirroring upsertStore() used to be two.
        //
        // A direct query-builder update rather than upsertStore() for the same
        // reason ShopBrandConnectJob::settle() uses one: this job must only ever
        // touch the two mark columns. upsertStore() rewrites the whole row from
        // a record, so it would re-assert a connect_status/currency/name
        // snapshot taken before the fetch — and this job runs AFTER the connect
        // settle, so that snapshot is the stale one.
        //
        // updated_at by hand, as everywhere on this raw seam. No edge purge is
        // owed: logoMark/logoMarkSvg are dashboard-only (SHOP_BRAND_ALLOWLIST
        // does not carry them), so the public wire is unchanged by this write.
        DB::table('content.storefronts')
            ->where('collection_id', $this->collectionId)
            ->update([
                'logo_mark_url' => $disk->url($pngPath),
                'logo_mark_svg_url' => $svgUrl,
                'updated_at' => now(),
            ]);
    }

    // R3-OBS-6: exists so Nightwatch sees a permanent failure at all — every
    // in-band failure above returns quietly, so without this a job that dies
    // after its retries is silent. Deliberately changes no state: the raw
    // logo/favicon columns are untouched, so the dashboard simply keeps
    // rendering the unprocessed mark, which is this job's designed fallback.
    public function failed(Throwable $e): void
    {
        report($e);
        Log::warning('shop.brand_logo.job_failed', [
            'collection_id' => $this->collectionId,
            'error' => $e->getMessage(),
        ]);
    }
}
