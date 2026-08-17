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

    /** Reels are short; 80 MB is far above a typical 15–60s mp4 and below anything hostile (R7). */
    private const MAX_VIDEO_BYTES = 83886080;

    /**
     * Gallery photos, not logos: the upload pipeline's own `optimized` tier
     * allows a 2400px long edge, and mirrored media sits beside uploads in the
     * same pool. Read from config so the two cannot drift apart silently.
     */
    private const FALLBACK_EDGE = 2400;

    /**
     * Ref namespaces whose bytes we are entitled to hold. The counterpart of
     * `App\Site\Pools\BorrowedMedia::BORROWED_SOURCE_KEYS` — keep the two in
     * view of each other, since a source is either owned or borrowed.
     *
     * An ALLOWLIST, not "everything except Google". Inferring owned-ness from
     * the absence of a known borrowed source means the next borrowed source
     * starts mirroring the moment someone adds it and nobody remembers this
     * file — and for a licensed feed that is a terms violation, not a bug.
     * Silence here fails safe: an unlisted source simply keeps serving from
     * its source_url.
     */
    private const OWNED_REF_PREFIXES = ['instagram:'];

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
        // A reel's mp4 is well over the fetcher's 10 MB page/image default;
        // the video branch below enforces its own cap after the fact.
        $response = $this->fetcher->withMaxBytes(self::MAX_VIDEO_BYTES)->tryFetch($sourceUrl);

        // tryFetch returns null on refusal or transport failure; dereferencing
        // before this null check is the repo's known trap. Past it, the shape
        // is guaranteed — no `?? 0` defaults, which would only hide a change to
        // SafeUrlFetcher's contract behind a plausible-looking fallback.
        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return $this->fail($assetId, 'fetch_failed', $sourceUrl);
        }

        $body = $response['body'];
        // Video (a reel's mp4, R7): stored as-is under its content hash — no
        // re-encode, its own byte cap. Detected by the ISO BMFF 'ftyp' box at
        // offset 4, never by extension (a signed CDN url has none).
        if (strlen($body) > 12 && substr($body, 4, 4) === 'ftyp') {
            if (strlen($body) > self::MAX_VIDEO_BYTES) {
                return $this->fail($assetId, 'video_too_large', $sourceUrl);
            }
            $path = 'content-media/'.$userId.'/'.substr(hash('sha256', $body), 0, 32).'.mp4';
            try {
                Storage::disk(config('partna.media_disk'))->put($path, $body, ['ContentType' => 'video/mp4']);
            } catch (\Throwable $e) {
                return $this->fail($assetId, 'store_failed', $sourceUrl, $e->getMessage());
            }
            DB::connection('pgsql')->table('content.media_assets')
                ->where('id', $assetId)
                ->update(['storage_path' => $path, 'mime_type' => 'video/mp4', 'variant_family' => 'native']);

            return true;
        }
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

    /**
     * May this projected media entry's bytes be mirrored?
     *
     * @param  array<string, mixed>  $entry
     */
    public static function isOwnedEntry(array $entry): bool
    {
        $ref = $entry['ref'] ?? null;
        if (! is_string($ref) || $ref === '') {
            return false;
        }

        foreach (self::OWNED_REF_PREFIXES as $prefix) {
            if (str_starts_with($ref, $prefix)) {
                return true;
            }
        }

        return false;
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
