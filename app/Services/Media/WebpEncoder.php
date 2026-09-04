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
     *                                                            bytes do not decode, exceed the pixel budget, or GD cannot emit WebP
     */
    public function encode(string $body, int $maxEdge, int $quality = 90): ?array
    {
        $tiers = $this->encodeMany($body, ['only' => [$maxEdge, $quality]]);

        return $tiers === null ? null : $tiers['only'];
    }

    /**
     * Several renditions from ONE decode — the mirror writes a 2400px master
     * and a 640px thumbnail per image, and decoding twice would double the
     * step that dominates encode time.
     *
     * @param  array<string, array{0: int, 1: int}>  $tiers  key => [maxEdge, quality]
     * @return array<string, array{bytes: string, width: int, height: int}>|null null when the
     *                                                                           bytes do not decode, exceed the pixel budget, or GD cannot emit WebP
     */
    public function encodeMany(string $body, array $tiers): ?array
    {
        if ($tiers === [] || ! extension_loaded('gd') || ! function_exists('imagewebp')) {
            return null;
        }

        // #SEC-1. The guard belongs HERE, at the decode seam, not only in the
        // callers: imagecreatefromstring() is the line that turns a few hundred
        // KB of flat-colour PNG into gigabytes of raster, and a guard that every
        // future caller has to remember to call is a guard that some caller
        // eventually forgets. MediaMirror checks the same two questions itself so
        // it can record the accurate reason on the row; this is the backstop that
        // makes forgetting impossible. safeToDecode() is BOTH checks — the format
        // allowlist matters as much as the pixel ceiling, see its docblock.
        if (! ImagePixelBudget::safeToDecode($body)) {
            return null;
        }

        $source = @imagecreatefromstring($body);
        if ($source === false) {
            return null;
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            $out = [];
            foreach ($tiers as $key => [$maxEdge, $quality]) {
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

                if ($bytes === '') {
                    return null;
                }
                $out[$key] = ['bytes' => $bytes, 'width' => $targetW, 'height' => $targetH];
            }

            return $out;
        } finally {
            imagedestroy($source);
        }
    }
}
