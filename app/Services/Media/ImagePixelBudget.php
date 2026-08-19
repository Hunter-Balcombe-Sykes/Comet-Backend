<?php

namespace App\Services\Media;

/**
 * The pixel-count ceiling for image bytes we did not create (#SEC-1).
 *
 * A byte-length cap is not a decompression-bomb defence. A 20000×20000 PNG of
 * flat colour compresses to a few hundred KB — well under MediaMirror's 15 MB
 * limit — and `imagecreatefromstring()` then allocates 20000 × 20000 × 4 bytes,
 * about 1.6 GB, before anything else in the pipeline gets a say. On the ingest
 * queue that is a worker kill from a source the mirror's own docblock calls
 * "untrusted by definition".
 *
 * ImageVariantService::loadImage() has enforced this for uploads since well
 * before slice 1b, but that guard is **path**-based: it hands a filename to
 * getimagesize(). MediaMirror and WebpEncoder hold BYTES, and there is no file.
 * Hence a second entry point rather than a copied constant — the number and the
 * config key stay in one place, and the two shapes differ only in where they
 * read the header from.
 *
 * @see ImageVariantService::loadImage() the path-based twin
 */
final class ImagePixelBudget
{
    /** Mirrors ImageVariantService::loadImage()'s fallback — 24 MP. */
    public const FALLBACK_MAX_PIXELS = 24_000_000;

    public static function maxPixels(): int
    {
        $configured = config('partna.image_max_pixels');

        return is_int($configured) && $configured > 0 ? $configured : self::FALLBACK_MAX_PIXELS;
    }

    /**
     * Does a header-only read say these bytes decode to more pixels than we are
     * willing to allocate?
     *
     * getimagesizefromstring() reads the header and never rasterises, so this
     * costs nothing and — crucially — runs BEFORE any decoder sees the bytes.
     * The same pattern is already used by GalleryAutoGrabber and LogoAutoGrabber.
     *
     * Returns false when the dimensions cannot be read at all. That is the
     * deliberate direction to be wrong in: "unreadable" is not "hostile", and
     * the decoder immediately downstream already refuses bytes it cannot decode.
     * A bomb necessarily has a READABLE header — declaring enormous dimensions
     * is how it works — so the attack this exists to stop is always caught.
     */
    public static function exceeds(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return false;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width < 1 || $height < 1) {
            return false;
        }

        return $width * $height > self::maxPixels();
    }
}
