<?php

namespace App\Services\Brand;

use App\Jobs\Brand\IngestBrandAssetJob;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Routing\SecretParams;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The brand-asset plane (plan §12): one pipeline that turns a URL pointing at
 * someone's logo into bytes we own, sanitised, addressed by content, and
 * attached to the connection that earned them.
 *
 * Why own the bytes at all. A hotlinked store logo is a dependency on someone
 * else's CDN path staying alive and staying the same image — it rots, and
 * until it rots it is a live channel from a third party onto a user's page.
 * Owning them also makes the deletion promise real: hard-delete on disconnect
 * is only meaningful for bytes we hold.
 *
 * Owned bytes come with obligations, and the plan states them: attribution,
 * a DMCA/takedown SLA, and hard-delete on disconnect. `brand_asset_refs`
 * carries the source URL and attribution per ref precisely so a takedown
 * request naming a URL can be answered without a full-table hunt.
 *
 * SANITISER FAILURE PATH (plan §17). An auto-grabbed logo is untrusted input.
 * SVG is refused outright unless the sanitising container is configured and
 * enabled — the one thing that must never happen is serving an unsanitised
 * vector we scraped off a stranger's site. When the container is off, this
 * pipeline handles raster only and says so, rather than falling back to
 * passing the original through.
 */
class BrandAssetPipeline
{
    /** Raster types we will decode. SVG is handled only by the container. */
    private const ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** A logo larger than this is not a logo. */
    private const MAX_BYTES = 4 * 1024 * 1024;

    /** Longest edge of the stored variant. */
    private const VARIANT_EDGE = 512;

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Queue the store-logo ingest for a freshly seeded shop brand.
     *
     * Gated on `logo_removal.store_enabled` and NOT on `logo_removal.enabled`:
     * the config split exists so flipping the store path can never change what
     * happens to a workplace logo a user uploaded themselves (plan §12).
     */
    public function queueStoreLogo(User $user, string $connectionId, ShopBrand $brand): void
    {
        if (! config('partna.logo_removal.store_enabled')) {
            return;
        }

        $source = $brand->logo ?: $brand->favicon;
        if (! is_string($source) || trim($source) === '') {
            return;
        }

        IngestBrandAssetJob::dispatch(
            (string) $user->id,
            $connectionId,
            $brand->logo ? 'logo_full' : 'favicon',
            $source,
            (string) ($brand->name ?? $brand->url ?? ''),
        );
    }

    /**
     * Download → sanitise → variant → media asset → ref.
     *
     * Returns the asset id, or null when the source could not be turned into
     * something safe to serve. Every null here is a deliberate refusal, logged
     * with its reason — a silent one would leave a store card with a blank
     * logo and no way to find out why.
     */
    public function ingest(string $userId, string $connectionId, string $role, string $sourceUrl, string $attribution = ''): ?string
    {
        $response = $this->fetcher->tryFetch($sourceUrl);

        if ($response === null || $response['status'] !== 200 || $response['body'] === '') {
            return $this->refuse($connectionId, $role, 'unreachable');
        }

        $mime = strtolower(trim(explode(';', $response['contentType'])[0]));

        if ($mime === 'image/svg+xml') {
            // §17: never serve an unsanitised auto-grabbed vector. The
            // container is the only thing that may accept one, and it is not
            // wired into this path yet.
            return $this->refuse($connectionId, $role, 'svg_requires_sanitiser');
        }

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            return $this->refuse($connectionId, $role, 'unsupported_type:'.$mime);
        }

        if (strlen($response['body']) > self::MAX_BYTES) {
            return $this->refuse($connectionId, $role, 'too_large');
        }

        $variant = $this->toWebp($response['body']);
        if ($variant === null) {
            // Undecodable bytes with an image content-type is the shape a
            // disguised payload takes; refusing is the point.
            return $this->refuse($connectionId, $role, 'undecodable');
        }

        // Content address, not URL address: the same logo reached by two
        // different CDN URLs is one asset, and re-running the ingest after a
        // cache purge must not duplicate it.
        $fingerprint = hash('sha256', $variant['bytes']);
        $assetId = $this->existingAsset($userId, $fingerprint)
            ?? $this->storeAsset($userId, $fingerprint, $sourceUrl, $variant);

        if ($assetId === null) {
            return $this->refuse($connectionId, $role, 'store_failed');
        }

        $this->linkRef($connectionId, $role, $assetId, $sourceUrl, $attribution);

        return $assetId;
    }

    /**
     * Re-encode to WebP through GD. This is the sanitising step as much as the
     * variant step: decoding to a raster and re-encoding drops every chunk
     * that was not pixels, so an EXIF payload or an appended archive cannot
     * survive it.
     *
     * @return array{bytes: string, width: int, height: int}|null
     */
    private function toWebp(string $body): ?array
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            return null;
        }

        $source = @imagecreatefromstring($body);
        if ($source === false) {
            return null;
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1.0, self::VARIANT_EDGE / max(1, max($width, $height)));
            $targetW = max(1, (int) round($width * $scale));
            $targetH = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor($targetW, $targetH);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

            ob_start();
            imagewebp($canvas, null, 90);
            $bytes = (string) ob_get_clean();
            imagedestroy($canvas);

            return $bytes === '' ? null : ['bytes' => $bytes, 'width' => $targetW, 'height' => $targetH];
        } finally {
            imagedestroy($source);
        }
    }

    private function existingAsset(string $userId, string $fingerprint): ?string
    {
        $id = DB::table('content.media_assets')
            ->where('user_id', $userId)
            ->where('fingerprint', $fingerprint)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    /** @param array{bytes: string, width: int, height: int} $variant */
    private function storeAsset(string $userId, string $fingerprint, string $sourceUrl, array $variant): ?string
    {
        $path = 'brand-assets/'.$userId.'/'.substr($fingerprint, 0, 32).'.webp';

        try {
            Storage::disk(config('partna.media_disk'))->put($path, $variant['bytes']);
        } catch (\Throwable $e) {
            Log::warning('brand_asset.store_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        $id = (string) Str::uuid();

        DB::table('content.media_assets')->insert([
            'id' => $id,
            'user_id' => $userId,
            'fingerprint' => $fingerprint,
            // #PRIV-5: fingerprint above is a content hash of the decoded
            // image bytes, NOT derived from $sourceUrl — unlike
            // ProjectionWriter::mediaFingerprint(), minimising the stored
            // URL here cannot re-mint this row or collide the UNIQUE
            // (user_id, fingerprint) key.
            'source_url' => SecretParams::minimiseUrl($sourceUrl),
            'storage_path' => $path,
            'mime_type' => 'image/webp',
            'width' => $variant['width'],
            'height' => $variant['height'],
            // We decoded the image ourselves, so the dimensions are measured
            // rather than taken from a header someone else wrote.
            'dims_confidence' => 'measured',
            'variant_family' => 'native',
            'created_at' => now(),
        ]);

        return $id;
    }

    private function linkRef(string $connectionId, string $role, ?string $assetId, string $sourceUrl, string $attribution): void
    {
        DB::table('content.brand_asset_refs')->upsert(
            [[
                'id' => (string) Str::uuid(),
                'connection_id' => $connectionId,
                'role' => $role,
                'asset_id' => $assetId,
                'source_url' => $sourceUrl,
                'attribution' => $attribution === '' ? null : $attribution,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['connection_id', 'role'],
            ['asset_id', 'source_url', 'attribution', 'updated_at'],
        );
    }

    /** Always null — the return exists so every refusal is one `return` at the call site. */
    private function refuse(string $connectionId, string $role, string $reason): null
    {
        Log::info('brand_asset.refused', [
            'connection_id' => $connectionId,
            'role' => $role,
            'reason' => $reason,
        ]);

        return null;
    }
}
