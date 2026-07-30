<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\ShopBrand;
use App\Services\Media\Exceptions\LogoProcessorException;
use App\Services\Media\LogoProcessorClient;
use App\Services\Media\MediaDiskResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

    public function __construct(public readonly string $brandRowId) {}

    public function handle(LogoProcessorClient $client): void
    {
        if (! (bool) config('partna.logo_removal.store_enabled', false)) {
            return;
        }

        $brand = ShopBrand::query()->find($this->brandRowId);
        if ($brand === null || $brand->is_individual) {
            return;
        }

        // Prefer the store's real logo; the favicon is the fallback mark.
        $source = $brand->logo ?: $brand->favicon;
        if (! is_string($source) || ! str_starts_with($source, 'http')) {
            return;
        }

        try {
            $response = Http::timeout(30)->withHeaders([
                'User-Agent' => 'PartnaBot/1.0 (+https://partna.au)',
            ])->get($source);
        } catch (\Throwable $e) {
            Log::info('shop.brand_logo.fetch_failed', [
                'brand_row_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $bytes = (string) $response->body();
        if (! $response->successful() || $bytes === '' || strlen($bytes) > 10 * 1024 * 1024) {
            return;
        }

        $mime = (string) ($response->header('Content-Type') ?: 'image/png');
        $mime = trim(explode(';', $mime)[0]) ?: 'image/png';

        try {
            $result = $client->process($bytes, basename(parse_url($source, PHP_URL_PATH) ?: 'logo'), $mime);
        } catch (LogoProcessorException $e) {
            Log::info('shop.brand_logo.process_failed', [
                'brand_row_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $diskName = MediaDiskResolver::resolve();
        $disk = Storage::disk($diskName);
        $base = "shop-brands/{$brand->id}";

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

        ShopBrand::whereKey($brand->id)->update([
            'logo_mark_url' => $disk->url($pngPath),
            'logo_mark_svg_url' => $svgUrl,
        ]);
    }
}
