<?php

namespace App\Services\Media;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\User\User;
use App\Services\Analytics\Concerns\EscalatesRepeatedFaults;
use App\Services\Cache\SiteCacheService;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
    use EscalatesRepeatedFaults;

    /**
     * Matches InstagramConnectionSeeder::MAX_IMAGE_BYTES. A pathological file
     * must not fill the temp disk or R2 before the decoder ever sees it.
     */
    private const MAX_BYTES = 15728640;

    /** Reels are short; 80 MB is far above a typical 15–60s mp4 and below anything hostile (R7). */
    private const MAX_VIDEO_BYTES = 83886080;

    /** Every image master lives under this prefix; videos and thumbnails too. */
    private const PATH_PREFIX = 'content-media/';

    /**
     * The thumbnail tier's key is DERIVED from the master's, not stored: no
     * schema change, and `storage_path` stays the one column that says
     * "bytes are ours". MediaUrlResolver derives the thumb url by string
     * substitution with no existence check, so it cannot know which edge a
     * given row used — which is why the edge is a CONST, not config.
     *
     * THUMB_SUFFIX and THUMB_EDGE are frozen TOGETHER. Changing the tier means
     * a new suffix AND a re-encode of every object already on R2; editing one
     * of these alone either mislabels new bytes or orphans old ones. The
     * flexibility worth having one day is MORE tiers (320, 1280) each in its
     * own filename with a map on the wire — the srcset shape, a different
     * design. Build it when a consumer asks.
     */
    public const THUMB_SUFFIX = '.640.webp';

    public const THUMB_EDGE = 640;

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
    private const OWNED_REF_PREFIXES = ['instagram:', 'tiktok:', 'facebook:', 'threads:'];

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly WebpEncoder $encoder,
        private readonly InstagramMediaUrl $instagramUrls,
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
        // #SCALE-3. The bytes land on temp disk, never in PHP memory — see
        // stream()'s docblock for why. Created and deleted HERE so that every
        // return path below, including the failure ones, drops the file.
        //
        // The one case this cannot cover is a SIGKILL — Horizon's own memory
        // restart, or the box OOM-killing the worker — which leaves the file
        // orphaned because no `finally` runs. That is a real residual, stated
        // rather than hidden: the trade is an OOM-restart failure mode for an
        // occasional orphaned temp file reclaimed with the container. It is
        // the better half of that trade, and it is strictly less likely now
        // that the 80 MB is no longer on the heap causing the restart.
        // R3 pre-flight (2026-08-27): Instagram signs media URLs with an
        // oe= expiry, and the profile actor's cached crawls can hand us URLs
        // that are ALREADY dead — every such fetch 403s by construction.
        // Refresh by shortcode (embed page, actor fallback) instead of
        // burning mirror_attempts on a known corpse; no refreshable identity
        // → fail with the honest reason and the poster keeps rendering.
        if ($this->instagramUrls->isExpired($sourceUrl)) {
            $fresh = $this->refreshedInstagramUrl($userId, $assetId, $sourceUrl);
            if ($fresh === null) {
                return $this->fail($assetId, 'source_expired', $sourceUrl, userId: $userId);
            }
            $wrote = DB::connection('pgsql')->table('content.media_assets')
                ->where('id', $assetId)
                // #SEC-5, see the video/image branches below: a mismatched
                // pair should write nothing rather than land a refreshed URL
                // on another user's row.
                ->where('user_id', $userId)
                ->update(['source_url' => $fresh]);
            // See the video branch: a zero-row UPDATE is a failure, not a
            // success — continuing to stream() a URL that was never
            // persisted would report a mirror that never happened.
            if ($wrote !== 1) {
                return $this->fail($assetId, 'asset_unwritable', $sourceUrl, userId: $userId);
            }
            $sourceUrl = $fresh;
        }

        $temp = tempnam($this->tempDir(), 'media-mirror-');
        if ($temp === false) {
            return $this->fail($assetId, 'store_failed', $sourceUrl, 'could not open a temp file', $userId);
        }

        try {
            return $this->stream($userId, $assetId, $sourceUrl, $temp);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * shortcode + carousel position for the asset, from the coord its item's
     * instagram source row carries (`instagram:acct-{hash}:{shortCode}`) and
     * the asset's item_media position — then a freshly-signed URL for it.
     *
     * #SEC-5: `content.item_media` carries no `user_id` of its own, so both
     * legs join through `content.media_assets` and filter on `ma.user_id` to
     * stay in the caller's owner scope. This read runs BEFORE the write and
     * BEFORE `freshUrl()` spends an embed fetch plus a billed Apify actor
     * call, so a mismatched pair costs nothing rather than merely writing
     * nothing.
     */
    private function refreshedInstagramUrl(string $userId, string $assetId, string $sourceUrl): ?string
    {
        // Correlate by the media row's OWN source_item_id where the writer
        // recorded it (gate critic, 2026-08-27 — a fingerprint-deduped asset
        // shared across items would otherwise pick an arbitrary row); the
        // item_id join stays as the fallback for legacy rows that predate
        // the origin column.
        $row = DB::connection('pgsql')->table('content.item_media as im')
            ->join('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
            ->join('content.source_items as si', 'si.id', '=', 'im.source_item_id')
            ->where('im.asset_id', $assetId)
            ->where('ma.user_id', $userId)
            ->where('si.coord', 'like', 'instagram:%')
            ->orderBy('im.position')
            ->first(['si.coord', 'im.position']);
        $row ??= DB::connection('pgsql')->table('content.item_media as im')
            ->join('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
            ->join('content.source_items as si', 'si.item_id', '=', 'im.item_id')
            ->where('im.asset_id', $assetId)
            ->where('ma.user_id', $userId)
            ->where('si.coord', 'like', 'instagram:%')
            ->orderBy('im.position')
            ->first(['si.coord', 'im.position']);
        if ($row === null) {
            return null;
        }

        $shortCode = (string) last(explode(':', (string) $row->coord));
        $kind = str_contains($sourceUrl, '/o1/v/') ? 'video' : 'image';

        return $this->instagramUrls->freshUrl($shortCode, $kind, (int) $row->position);
    }

    /**
     * The mirror itself, with $temp guaranteed to exist and be cleaned up.
     *
     * #SCALE-3: this used to hold the whole response as a PHP string. The
     * fetcher's byte cap does not prevent that — its own docblock says Guzzle
     * has already buffered the body by the time the cap sees it — so an 80 MB
     * reel was 80 MB of heap, and `Storage::put()` on a string copies it again
     * into the write stream. Against supervisor-1's 256 MB restart threshold
     * one large reel could take the worker out mid-job, which is a mirror that
     * fails with no reason recorded on the row.
     *
     * The video branch never needs the bytes in memory at all (it stores them
     * verbatim under a content hash), so it works entirely off the file. The
     * image branch still needs a string, because GD's imagecreatefromstring()
     * takes one — but only AFTER the size check has bounded it at 15 MB, so
     * the 80 MB ceiling no longer reaches PHP memory on any path.
     */
    private function stream(string $userId, string $assetId, string $sourceUrl, string $temp): bool
    {
        // Category B. An Instagram CDN url arrived in a third-party scrape
        // payload and is untrusted by definition — a host allowlist would not
        // be the fix here, it would be a way of not asking the question.
        // A reel's mp4 is well over the fetcher's 10 MB page/image default;
        // the video branch below enforces its own cap after the fact.
        $response = $this->fetcher->withMaxBytes(self::MAX_VIDEO_BYTES)->tryFetchToFile($sourceUrl, $temp);

        // tryFetchToFile returns null on refusal or transport failure;
        // dereferencing before this null check is the repo's known trap. Past
        // it, the shape is guaranteed — no `?? 0` defaults, which would only
        // hide a change to SafeUrlFetcher's contract behind a plausible-looking
        // fallback. Note there is no 'body' key by design: the bytes are in
        // $temp, and reading a `body` here would put them straight back on the
        // heap.
        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return $this->fail($assetId, 'fetch_failed', $sourceUrl, userId: $userId);
        }

        // Belt-and-braces, and deliberately NOT justified by a failure anyone
        // has reproduced: PHP invalidates its own stat cache for writes it
        // performs, and both file_put_contents() and an fopen/fwrite/fclose
        // cycle (which is how Guzzle fills a sink) were checked on this repo's
        // PHP 8.4 — filesize() reports the post-write size with or without
        // this call. It is kept because the size below is the ONLY thing
        // standing between an 80 MB file and file_get_contents(), and one free
        // stat invalidation is a cheaper insurance premium than reasoning
        // about which stream wrapper a future fetcher change might use.
        clearstatcache(true, $temp);
        $bytes = (int) filesize($temp);

        // Video (a reel's mp4, R7): stored as-is under its content hash — no
        // re-encode, its own byte cap. Detected by the ISO BMFF 'ftyp' box at
        // offset 4, never by extension (a signed CDN url has none). Read as 12
        // bytes off the front of the file rather than a substr of the whole
        // body — same test, none of the memory.
        if ($this->isIsoBaseMedia($temp, $bytes)) {
            // A video body for an asset every item uses as an IMAGE (cover /
            // poster / logo roles only) is not that image — TikTok's `cover`
            // can be its animated clip (2026-09-02). Refuse rather than store
            // a file the card cannot draw; the row keeps its source URL.
            if ($this->isImageOnlyAsset($assetId)) {
                return $this->fail($assetId, 'video_body_for_image_role', $sourceUrl, userId: $userId);
            }
            if ($bytes > self::MAX_VIDEO_BYTES) {
                return $this->fail($assetId, 'video_too_large', $sourceUrl, userId: $userId);
            }
            // hash_file streams the file in blocks; hash() on a string would
            // need the string. Same digest, same path, no heap.
            $path = 'content-media/'.$userId.'/'.substr(hash_file('sha256', $temp), 0, 32).'.mp4';
            try {
                $handle = fopen($temp, 'rb');
                if ($handle === false) {
                    return $this->fail($assetId, 'store_failed', $sourceUrl, 'could not reopen the temp file', $userId);
                }
                try {
                    // writeStream, not put: put() takes a string and would undo
                    // the whole point of streaming into $temp.
                    $stored = Storage::disk(config('partna.media_disk'))
                        ->writeStream($path, $handle, ['ContentType' => 'video/mp4']);
                } finally {
                    // The adapter consumes the handle but does not own it.
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
            } catch (\Throwable $e) {
                return $this->fail($assetId, 'store_failed', $sourceUrl, $e->getMessage(), $userId);
            }

            // The media disk is `throw => true`, so a failed write normally
            // arrives as the exception above — but PARTNA_MEDIA_DISK can point
            // at the non-throwing `public_dev` alias, where a failed write is a
            // false return. Unchecked, that wrote a storage_path for an object
            // that does not exist and reported success.
            if ($stored === false) {
                return $this->fail($assetId, 'store_failed', $sourceUrl, 'the disk rejected the write', $userId);
            }

            $wrote = DB::connection('pgsql')->table('content.media_assets')
                ->where('id', $assetId)
                // #SEC-5: keyed on id AND owner. The id alone is sufficient in
                // practice — it came from ProjectionWriter — but it costs
                // nothing, and a mirror job dispatched with a mismatched pair
                // should write nothing rather than land one user's bytes on
                // another user's row. Every DB access in this class now carries
                // the same scoping: the image branch below, mirror()'s
                // source_url refresh, refreshedInstagramUrl()'s two joins, and
                // both halves of fail(). (This comment used to claim it was the
                // only write in the class; #W1-SEC-1 and #W2-SEC-11 closed the
                // ones it had missed.)
                ->where('user_id', $userId)
                ->update([
                    'storage_path' => $path,
                    'mime_type' => 'video/mp4',
                    'variant_family' => 'native',
                ] + $this->clearedMirrorState());

            // The affected-row count is READ, not discarded. Scoping the UPDATE
            // on user_id introduced a way for it to match nothing — a mismatched
            // pair, or a row deleted between dispatch and run — and returning
            // true there would report a mirror that never happened: no bytes, no
            // failure reason, no attempt counted, so nothing would ever retry it
            // and no operator would ever see it. Silence is the worse bug.
            //
            // fail() keeps the user_id scoping, so on a genuinely mismatched
            // pair the attempt counter does NOT move — correctly: there is no
            // row here we are entitled to write, and bumping the victim's
            // counter would be the same cross-tenant write #SEC-5 exists to
            // stop. The log line and the false return carry the signal instead.
            return $wrote === 1 ? $this->landed($userId) : $this->fail($assetId, 'asset_unwritable', $sourceUrl, userId: $userId);
        }
        // Checked BEFORE the file is read into a string, which is the other
        // half of #SCALE-3 (and closes #SCALE-15): every fetch here is capped
        // at the 80 MB video ceiling because we cannot know it is not a reel
        // until the bytes arrive, so an oversized image used to be buffered in
        // full and only then rejected. Now the 80 MB lands on disk and the 15 MB
        // image cap is what bounds PHP memory.
        if ($bytes === 0 || $bytes > self::MAX_BYTES) {
            return $this->fail($assetId, 'body_rejected', $sourceUrl, userId: $userId);
        }

        // Bounded at MAX_BYTES by the check above. GD's imagecreatefromstring()
        // needs a string, so the image path is where the bytes legitimately
        // become one.
        $body = file_get_contents($temp);
        if ($body === false) {
            return $this->fail($assetId, 'store_failed', $sourceUrl, 'could not read the temp file', $userId);
        }

        // #SEC-1. The byte cap above is not a decompression-bomb defence and
        // never was: a 20000x20000 PNG of flat colour is ~90 bytes on the wire
        // and 1.6 GB once imagecreatefromstring() rasterises it — four orders
        // of magnitude under the 15 MB limit, on a queue fed by a third-party
        // scrape this file's own docblock calls "untrusted by definition".
        // Header-only read, so nothing is decoded to find out.
        // Format FIRST. imagecreatefromstring() decodes strictly more formats
        // than getimagesizefromstring() can parse — GD2 among them, where 854 KB
        // on the wire is 144 million pixels — so a pixel check on its own is
        // bypassable by choosing a format the header reader does not know.
        // (Found by review; the first cut of this guard had exactly that hole.)
        if (! ImagePixelBudget::decodable($body)) {
            return $this->fail($assetId, 'undecodable', $sourceUrl, userId: $userId);
        }

        if (ImagePixelBudget::exceeds($body)) {
            return $this->fail($assetId, 'pixel_budget', $sourceUrl, userId: $userId);
        }

        // Master + thumbnail from ONE decode (2026-09-04): the 640px tier is
        // what setup tiles and cards load (~32 KB against the master's
        // ~260 KB), and decoding twice would double the step that dominates.
        $encoded = $this->encoder->encodeMany($body, [
            'master' => [$this->maxEdge(), 90],
            'thumb' => [$this->thumbEdge(), $this->thumbQuality()],
        ]);
        if ($encoded === null) {
            return $this->fail($assetId, 'undecodable', $sourceUrl, userId: $userId);
        }
        $variant = $encoded['master'];

        // CONTENT-addressed, never connection-addressed. InstagramConnectionSeeder
        // derives its folder from the connection itself, which never changes, so
        // every refresh overwrites one fixed photo.jpg in place — silently
        // replacing an image a user already picked. Hashing the encoded
        // bytes means changed bytes land at a NEW path and the old object stays
        // exactly where whoever referenced it expects.
        $path = self::PATH_PREFIX.$userId.'/'.substr(hash('sha256', $variant['bytes']), 0, 32).'.webp';

        try {
            $disk = Storage::disk(config('partna.media_disk'));
            // Thumbnail FIRST: MediaUrlResolver derives the thumb URL from
            // storage_path without a HEAD, so the master's path must never be
            // recorded while its thumbnail is missing. A failed thumb put is
            // a failed mirror, retried like any other store failure.
            $stored = $disk->put((string) self::thumbPath($path), $encoded['thumb']['bytes'])
                && $disk->put($path, $variant['bytes']);
        } catch (\Throwable $e) {
            return $this->fail($assetId, 'store_failed', $sourceUrl, $e->getMessage(), $userId);
        }

        // See the video branch: the media disk is `throw => true`, so a failed
        // write normally arrives as the exception above — but PARTNA_MEDIA_DISK
        // can point at the non-throwing `public_dev` alias, where a failed write
        // is a false return. Unchecked, that fell straight through to the UPDATE
        // below, writing a storage_path for an object that does not exist AND
        // clearing the mirror state, so nothing ever retried it.
        if ($stored === false) {
            return $this->fail($assetId, 'store_failed', $sourceUrl, 'the disk rejected the write', $userId);
        }

        // fingerprint is deliberately absent from this update.
        $wrote = DB::connection('pgsql')->table('content.media_assets')
            ->where('id', $assetId)
            ->where('user_id', $userId)   // #SEC-5, see the video branch above
            ->update([
                'storage_path' => $path,
                'mime_type' => 'image/webp',
                'width' => $variant['width'],
                'height' => $variant['height'],
                // We decoded the image ourselves, so the dimensions are
                // measured rather than taken from a header someone else wrote.
                'dims_confidence' => 'measured',
                'variant_family' => 'native',
            ] + $this->clearedMirrorState());

        // See the video branch: a zero-row UPDATE is a failure, not a success.
        return $wrote === 1 ? $this->landed($userId) : $this->fail($assetId, 'asset_unwritable', $sourceUrl, userId: $userId);
    }

    /**
     * The ONE image-vs-video decision. Two call sites need different READS —
     * this class asks about one asset from inside its job, where
     * content.item_media is written; ProjectionWriter asks at dispatch time,
     * before those rows exist, from the projection entries. Only the rule is
     * shared, and it has to be: the flag it produces chooses the queue lane,
     * and a reel on the managed queue dies at MANAGED_TIMEOUT.
     *
     * @param  list<string>  $roles
     */
    public static function rolesIndicateVideo(array $roles): bool
    {
        return in_array('video', $roles, true);
    }

    /**
     * True when every item_media row pointing at this asset is an image role
     * — the asset was minted as a cover/poster/logo, never as the video.
     * Fail-open (false) on any read problem: the sniff branch then stores
     * what it fetched, as before.
     */
    private function isImageOnlyAsset(string $assetId): bool
    {
        try {
            $roles = DB::connection('pgsql')->table('content.item_media')
                ->where('asset_id', $assetId)
                ->pluck('role')
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return $roles !== [] && ! self::rolesIndicateVideo($roles);
    }

    /**
     * The ISO BMFF 'ftyp' box at offset 4 — an mp4/mov, read off the front of
     * the file instead of out of a full-body string (#SCALE-3).
     *
     * The `> 12` length guard is kept exactly as it was: a file of 12 bytes or
     * fewer cannot carry a box header AND payload, and treating one as a video
     * would store a truncated object under a content hash.
     */
    private function isIsoBaseMedia(string $temp, int $bytes): bool
    {
        if ($bytes <= 12) {
            return false;
        }

        $handle = fopen($temp, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $head = fread($handle, 12);
        } finally {
            fclose($handle);
        }

        return is_string($head) && substr($head, 4, 4) === 'ftyp';
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

    /**
     * Where the fetched body is spooled. Defaults to the system temp dir, which
     * is correct on Laravel Cloud; overridable because the spool is a real file
     * on a real shared directory (a worker box may want a specific volume, and
     * the leak tests need a directory a parallel worker is not also writing to).
     */
    private function tempDir(): string
    {
        $configured = config('partna.media_mirror_temp_dir');

        return is_string($configured) && $configured !== '' ? $configured : sys_get_temp_dir();
    }

    private function maxEdge(): int
    {
        $configured = config('partna.image_variants.optimized.width');

        return is_int($configured) && $configured > 0 ? $configured : self::FALLBACK_EDGE;
    }

    private function thumbEdge(): int
    {
        return self::THUMB_EDGE;
    }

    private function thumbQuality(): int
    {
        $configured = (int) config('partna.media.thumb_quality', 80);

        return $configured >= 1 && $configured <= 100 ? $configured : 80;
    }

    /**
     * The thumbnail object key for a mirrored image master, or null when the
     * path is not one this class wrote an image master to (a video, an
     * upload variant, a brand asset) — the ONE place the derivation lives, so
     * the writer and MediaUrlResolver cannot disagree about it.
     */
    public static function thumbPath(string $storagePath): ?string
    {
        if (! str_starts_with($storagePath, self::PATH_PREFIX)
            || ! str_ends_with($storagePath, '.webp')
            || str_ends_with($storagePath, self::THUMB_SUFFIX)) {
            return null;
        }

        return substr($storagePath, 0, -strlen('.webp')).self::THUMB_SUFFIX;
    }

    /**
     * The success stamp, folded into whichever UPDATE actually lands the bytes.
     *
     * A separate UPDATE would be a second round-trip and, worse, a window in
     * which the row has bytes but still reads as failed. Both write paths need
     * it — the video branch returns before the image UPDATE, so a reel that
     * recovers would otherwise keep a stale reason forever.
     *
     * @return array<string, mixed>
     */
    private function clearedMirrorState(): array
    {
        return [
            'mirror_attempts' => 0,
            'mirror_last_attempt_at' => now(),
            'mirror_last_reason' => null,
        ];
    }

    /**
     * The bytes are ours: the site's payload rotates NOW (owner, 2026-09-02).
     * Until this, a landed mirror sat behind the payload's TTL and the next
     * projection, so a fresh site served raw CDN links — empty gallery cards
     * — for minutes after the build read "done". A few cache deletes per
     * landing is nothing next to that.
     */
    private function landed(string $userId): bool
    {
        try {
            $site = User::query()->find($userId)?->site;
            if ($site !== null) {
                app(SiteCacheService::class)->invalidateSitePayload($site);
                // The edge holds the rendered sitepage until a purge (the
                // router overlays a day-long s-maxage) — so the payload
                // rotating is not enough. One purge per site per 15s: a
                // build wave lands hundreds of mirrors.
                $subdomain = (string) ($site->subdomain ?? '');
                if ($subdomain !== '' && Cache::add('media_mirror:purge:'.$subdomain, 1, 15)) {
                    CloudflareCachePurgeJob::dispatch($subdomain);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return true;
    }

    /**
     * R8. Record the failure ON THE ROW, not only in the log.
     *
     * The log line is not the record and never was: LOG_LEVEL can silence it
     * (prod defaults to `warning`, and an aggregate at info would vanish), a
     * capture window can miss it, and a line says nothing about the assets that
     * were never attempted. The row survives all three, and `mirror_attempts`
     * is what lets a reader tell "queued" from "dead".
     *
     * The counter is incremented IN SQL (increment(), not read-modify-write):
     * two workers can hold the same asset if the ShouldBeUnique lock lapses
     * past $uniqueFor, and a lost update there would under-count towards the
     * cap — restoring the unbounded retry this exists to end.
     */
    private function fail(string $assetId, string $reason, string $sourceUrl, ?string $error = null, ?string $userId = null): bool
    {
        Log::warning('media_mirror.failed', array_filter([
            'asset_id' => $assetId,
            'reason' => $reason,
            'host' => parse_url($sourceUrl, PHP_URL_HOST),
            'error' => $error,
        ]));

        // #LIFE-12: `store_failed` is the one reason that is unambiguously OUR
        // problem rather than a third party's — the fetch worked and the object
        // store did not. Sustained, that is an R2 outage silently eating every
        // mirror, and Log::warning does not reach Nightwatch (CLAUDE.md). The
        // other reasons stay quiet on purpose: a dead CDN link is not an
        // operator event, and a build wave's few 404s would page nobody usefully.
        // The trait is triple-guarded and cannot throw out of here.
        if ($reason === 'store_failed') {
            self::escalateIfSustained(
                new RuntimeException('media mirror store failed: '.($error ?? 'unknown')),
                'media_mirror_store',
            );
        }

        $update = DB::connection('pgsql')->table('content.media_assets')
            ->where('id', $assetId);
        if ($userId !== null) {
            $update->where('user_id', $userId); // #SEC-5, as the success paths do
        }
        $update->increment('mirror_attempts', 1, [
            'mirror_last_attempt_at' => now(),
            'mirror_last_reason' => $reason,
        ]);

        // Read back rather than trusting a local count: the row is the shared
        // truth, and this is the ONE line an operator gets for an asset we are
        // giving up on.
        //
        // #W2-SEC-11: scoped EXACTLY as the increment above it, and for a
        // reason beyond consistency. On a mismatched pair the increment matches
        // no row, so an id-only read-back would return the OTHER user's counter
        // — and if theirs already sat at the cap, this would emit a `gave_up`
        // line and a Nightwatch escalation for an asset we never touched, about
        // an owner we are not entitled to read. Scoped, the read misses too and
        // $attempts falls to 0: no spurious give-up, no cross-tenant read.
        $attemptsQuery = DB::connection('pgsql')->table('content.media_assets')
            ->where('id', $assetId);
        if ($userId !== null) {
            $attemptsQuery->where('user_id', $userId);
        }
        $attempts = (int) $attemptsQuery->value('mirror_attempts');

        // `>=`, not `===`. A strict equality misses the line entirely whenever
        // the counter steps past the boundary — two in-flight jobs incrementing
        // once the ShouldBeUnique lock has lapsed, or a lowered cap — and a
        // missed give-up line is the failure this whole change exists to end.
        // The duplicate it risks is bounded and self-limiting: dispatchMirrors
        // stops queuing at the cap, so only already-queued jobs reach here.
        if ($attempts >= self::maxAttempts()) {
            Log::warning('media_mirror.gave_up', [
                'asset_id' => $assetId,
                'attempts' => $attempts,
                'reason' => $reason,
                'host' => parse_url($sourceUrl, PHP_URL_HOST),
            ]);

            // #LIFE-12: one abandoned asset is ordinary; a RUN of them is the
            // systemic outage this class had no way to report. Counted
            // fleet-wide by the trait, so five give-ups inside ten minutes
            // reach Nightwatch once — not once per asset.
            self::escalateIfSustained(
                new RuntimeException("media mirror gave up on {$assetId} after {$attempts} attempts: {$reason}"),
                'media_mirror_gave_up',
            );
        }

        return false;
    }

    /**
     * Consecutive failures after which ProjectionWriter stops re-dispatching.
     * Public because the dispatch-side filter must read the SAME number — two
     * readings would let the writer keep queuing an asset this class has
     * already given up on, silently restoring the infinite retry.
     */
    public static function maxAttempts(): int
    {
        $configured = config('partna.media_mirror_max_attempts');

        return is_int($configured) && $configured > 0 ? $configured : 5;
    }
}
