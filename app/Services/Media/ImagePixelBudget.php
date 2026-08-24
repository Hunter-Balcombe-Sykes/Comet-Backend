<?php

namespace App\Services\Media;

use finfo;

/**
 * The gate every decode of image bytes we did not create must pass (#SEC-1).
 *
 * TWO checks. Both are load-bearing, and they do DIFFERENT work — this class
 * first shipped with a pixel check that failed OPEN on an unreadable header, an
 * independent review broke it in minutes, and a second review then pinned down
 * which half fixes what. Written down rather than left to be rediscovered:
 *
 *   1. FORMAT ALLOWLIST (`decodable()`). `imagecreatefromstring()` decodes
 *      strictly MORE formats than `getimagesizefromstring()` can parse — GD's
 *      own native GD2 among them. A 12000x12000 GD2 image is ~854 KB on the
 *      wire, `getimagesize` reports FALSE for it, and GD decodes it happily into
 *      144 million pixels. finfo byte-sniffs, so a lying content-type or
 *      extension buys nothing. Only jpeg/png/webp get through, matching
 *      ImageVariantService::ALLOWED_IMAGE_MIMES and GalleryAutoGrabber's
 *      ALLOWED_MIMES exactly — three lists, one set, deliberately.
 *
 *      What this check contributes is the ACCEPTED SURFACE: it refuses GIF, BMP,
 *      WBMP, XBM and GD2 outright, at any size, and gives MediaMirror an honest
 *      reason to record. It is not, on its own, what stops the bomb.
 *
 *   2. PIXEL CEILING, FAILING CLOSED (`exceeds()`). A byte-length cap is not a
 *      decompression-bomb defence: a 20000x20000 PNG of flat colour is under 100
 *      bytes on the wire and about 1.6 GB once rasterised — four orders of
 *      magnitude under MediaMirror's 15 MB limit, on a queue fed by a
 *      third-party scrape.
 *
 *      The DIRECTION is the security-relevant part. An unreadable header returns
 *      TRUE (refuse). The original returned false — "unreadable is not hostile" —
 *      and that single assumption was the whole GD2 bypass: verified by mutation,
 *      removing the allowlist but keeping this direction still refuses the GD2
 *      payload. Never soften it back.
 *
 * ImageVariantService::loadImage() has enforced the same pair for uploads since
 * long before slice 1b, but PATH-based: `assertImageMime($path)` then
 * `getimagesize($path)`, both of which take a filename. MediaMirror and
 * WebpEncoder hold BYTES and there is no file. Hence this twin rather than a
 * copied constant — same two questions, read from a string.
 *
 * @see ImageVariantService::loadImage() the path-based twin
 */
final class ImagePixelBudget
{
    /** Mirrors ImageVariantService::loadImage()'s fallback — 24 MP. */
    public const FALLBACK_MAX_PIXELS = 24_000_000;

    /**
     * Byte-sniffed formats we are willing to hand to GD. Must stay identical to
     * ImageVariantService::ALLOWED_IMAGE_MIMES — widening one without the other
     * is how a decoder path grows a format nobody audited.
     */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function maxPixels(): int
    {
        $configured = config('partna.image_max_pixels');

        return is_int($configured) && $configured > 0 ? $configured : self::FALLBACK_MAX_PIXELS;
    }

    /**
     * Are these bytes a format we will decode at all?
     *
     * finfo byte-sniffs, exactly as assertImageMime() does for the path lane, so
     * a hostile content-type header or file extension buys nothing. This is the
     * check that closes the GD2 hole — see the class docblock.
     */
    public static function decodable(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }

        return in_array((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes), self::ALLOWED_MIMES, true);
    }

    /**
     * Would decoding these bytes allocate more pixels than we allow?
     *
     * Header-only: getimagesizefromstring() parses dimensions and never
     * rasterises, so this costs nothing and runs BEFORE any decoder sees the
     * bytes. Same pattern as GalleryAutoGrabber and LogoAutoGrabber.
     *
     * Returns TRUE — refuse — when the dimensions cannot be read. It fails
     * CLOSED on purpose: the earlier "unreadable is not hostile" reading was
     * exactly the assumption the GD2 bypass exploited. Callers gate on
     * decodable() first, so in practice an unreadable header here means bytes
     * that finfo called a jpeg/png/webp and getimagesize could not parse —
     * i.e. malformed or crafted, and not something to hand a decoder.
     */
    public static function exceeds(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return true;
        }

        // No `?? 0`: getimagesizefromstring() either returns false (handled
        // above) or an array whose 0 and 1 offsets always exist.
        $width = $info[0];
        $height = $info[1];

        if ($width < 1 || $height < 1) {
            return true;
        }

        return $width * $height > self::maxPixels();
    }

    /**
     * The single call a decoder should make. Both checks, in the order that
     * makes the second one meaningful.
     */
    public static function safeToDecode(string $bytes): bool
    {
        return self::decodable($bytes) && ! self::exceeds($bytes);
    }
}
