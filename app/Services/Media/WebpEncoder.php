<?php

namespace App\Services\Media;

/**
 * Decode arbitrary image bytes and re-encode them as WebP at a bounded edge.
 *
 * This is the SANITISING step as much as the variant step: decoding to a raster
 * and re-encoding drops every chunk that was not pixels, so an EXIF payload or
 * an archive appended to a JPEG cannot survive it. Undecodable bytes behind an
 * image content-type are refused, which is the shape a disguised payload takes.
 *
 * Extracted from BrandAssetPipeline in slice 1b so the brand lane and the media
 * mirror share one encoder rather than growing two. The max edge is a PARAMETER
 * rather than a constant, because the two callers genuinely differ: a brand logo
 * is capped at 512, and capping a gallery photo there would be a visible quality
 * regression — the upload pipeline's own `optimized` tier allows 2400.
 */
final class WebpEncoder
{
    /**
     * @return array{bytes: string, width: int, height: int}|null null when the
     *                                                            bytes do not decode, or GD cannot emit WebP
     */
    public function encode(string $body, int $maxEdge, int $quality = 90): ?array
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
            // Clamped at 1.0: never upscale. A 640px Instagram photo stays
            // 640px rather than being stretched to the cap.
            $scale = min(1.0, $maxEdge / max(1, max($width, $height)));
            $targetW = max(1, (int) round($width * $scale));
            $targetH = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor($targetW, $targetH);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

            ob_start();
            imagewebp($canvas, null, $quality);
            $bytes = (string) ob_get_clean();
            imagedestroy($canvas);

            return $bytes === '' ? null : ['bytes' => $bytes, 'width' => $targetW, 'height' => $targetH];
        } finally {
            imagedestroy($source);
        }
    }
}
