<?php

namespace App\Services\Media;

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Slice 1b D9. Attach owned bytes to an asset row ProjectionWriter already
 * minted — it does NOT mint its own.
 *
 * That is the whole point. BrandAssetPipeline keys `content.media_assets` on a
 * bare content hash of the decoded bytes; ProjectionWriter keys it on
 * `url-sha1(...)`. Both write into the same UNIQUE (user_id, fingerprint)
 * index. A mirror that minted its own row would leave two assets for one photo
 * with `item_media.asset_id` pointing at whichever it happened to link, and
 * nothing would error. So this only ever UPDATEs, and it never touches
 * `fingerprint` — identity belongs to whoever minted the row.
 *
 * Only OWNED-class media reaches here (D1). A Google Places photo is borrowed:
 * the terms grant photos no caching exemption, so mirroring one would be a
 * licence violation, not merely wasted work.
 */
final class MediaMirror
{
    /**
     * Matches InstagramConnectionSeeder::MAX_IMAGE_BYTES. A pathological file
     * must not fill the temp disk or R2 before the decoder ever sees it.
     */
    private const MAX_BYTES = 15728640;

    /**
     * Gallery photos, not logos: the upload pipeline's own `optimized` tier
     * allows a 2400px long edge, and mirrored media sits beside uploads in the
     * same pool. Read from config so the two cannot drift apart silently.
     */
    private const FALLBACK_EDGE = 2400;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly WebpEncoder $encoder,
    ) {}

    /**
     * Fetch, re-encode and store the bytes for one asset.
     *
     * Returns false and leaves the row untouched on ANY failure, and logs at
     * warning rather than throwing: this runs off the back of a projection run,
     * and one dead CDN link must not fail the whole sync.
     */
    public function mirror(string $userId, string $assetId, string $sourceUrl): bool
    {
        // Category B. An Instagram CDN url arrived in a third-party scrape
        // payload and is untrusted by definition — a host allowlist would not
        // be the fix here, it would be a way of not asking the question.
        $response = $this->fetcher->tryFetch($sourceUrl);

        // tryFetch returns null on refusal or transport failure; dereferencing
        // before this check is the repo's known null-deref trap.
        if ($response === null || ($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            return $this->fail($assetId, 'fetch_failed', $sourceUrl);
        }

        $body = (string) ($response['body'] ?? '');
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return $this->fail($assetId, 'body_rejected', $sourceUrl);
        }

        $variant = $this->encoder->encode($body, $this->maxEdge());
        if ($variant === null) {
            return $this->fail($assetId, 'undecodable', $sourceUrl);
        }

        // CONTENT-addressed, never connection-addressed. InstagramConnectionSeeder
        // derives its folder from the connection's created_at, which never
        // changes, so every refresh overwrites one fixed photo.jpg in place —
        // silently replacing an image a user already picked. Hashing the encoded
        // bytes means changed bytes land at a NEW path and the old object stays
        // exactly where whoever referenced it expects.
        $path = 'content-media/'.$userId.'/'.substr(hash('sha256', $variant['bytes']), 0, 32).'.webp';

        try {
            Storage::disk(config('partna.media_disk'))->put($path, $variant['bytes']);
        } catch (\Throwable $e) {
            return $this->fail($assetId, 'store_failed', $sourceUrl, $e->getMessage());
        }

        // fingerprint is deliberately absent from this update.
        DB::connection('pgsql')->table('content.media_assets')
            ->where('id', $assetId)
            ->update([
                'storage_path' => $path,
                'mime_type' => 'image/webp',
                'width' => $variant['width'],
                'height' => $variant['height'],
                // We decoded the image ourselves, so the dimensions are
                // measured rather than taken from a header someone else wrote.
                'dims_confidence' => 'measured',
                'variant_family' => 'native',
            ]);

        return true;
    }

    private function maxEdge(): int
    {
        $configured = config('partna.image_variants.optimized.width');

        return is_int($configured) && $configured > 0 ? $configured : self::FALLBACK_EDGE;
    }

    private function fail(string $assetId, string $reason, string $sourceUrl, ?string $error = null): bool
    {
        Log::warning('media_mirror.failed', array_filter([
            'asset_id' => $assetId,
            'reason' => $reason,
            'host' => parse_url($sourceUrl, PHP_URL_HOST),
            'error' => $error,
        ]));

        return false;
    }
}
