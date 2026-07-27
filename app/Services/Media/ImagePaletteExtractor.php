<?php

namespace App\Services\Media;

/**
 * Extracts dominant-colour + palette metadata from an image, using only the GD
 * extension already required for variant generation (#76 Part A). The result
 * feeds SiteAccentResolver (accent-colour fallback chain) and any future
 * palette consumers; the former factor pipeline (IdentityEvidence::
 * mediaPalette() → ImageryPaletteFactor) was retired with the whole
 * contribution-ledger machine.
 *
 * The whole extractor is best-effort: every entry point returns null on any
 * failure so a colour read can NEVER fail an upload or a backfill (the caller
 * treats null as "no palette", and the factor abstains). It is deliberately
 * cheap — the image is downsampled to a small thumbnail before any per-pixel
 * work, so cost is bounded regardless of source resolution.
 *
 * @phpstan-type Palette array{dominant: string, colors: list<string>, saturation: float, warm: bool}
 */
class ImagePaletteExtractor
{
    /** Longest edge of the working thumbnail. Small = fast + representative enough. */
    private const SAMPLE_MAX_EDGE = 64;

    /** Quantisation step per channel (0..255 → buckets of 32) for grouping near-identical colours. */
    private const BUCKET = 32;

    /** Max representative swatches returned in `colors`. */
    private const MAX_SWATCHES = 5;

    /**
     * Below this saturation a pixel is treated as neutral (grey/black/white) and
     * excluded from the warm-vs-cool vote and the dominant-hue swatches — a mostly
     * greyscale image shouldn't be called "warm" off a few stray beige pixels.
     */
    private const NEUTRAL_SATURATION = 0.10;

    /**
     * Extract a palette from a decoded GD image. Does NOT take ownership of
     * $image (the caller still destroys it). Returns null on any failure.
     *
     * @return Palette|null
     */
    public function fromGd(\GdImage $image): ?array
    {
        try {
            return $this->extract($image);
        } catch (\Throwable) {
            // Best-effort: a palette read must never propagate. Caller → null → abstain.
            return null;
        }
    }

    /**
     * Extract a palette from an image file on the local filesystem. Decodes with
     * GD (jpeg/png/webp), extracts, and destroys the temp image. Returns null on
     * any failure (unreadable, unsupported format, decode error).
     *
     * @return Palette|null
     */
    public function fromPath(string $path): ?array
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $image = $this->decode($path);
        if (! $image instanceof \GdImage) {
            return null;
        }

        try {
            return $this->fromGd($image);
        } finally {
            unset($image);
        }
    }

    /** Decode a file to a GD image without allocating a bomb-sized bitmap. */
    private function decode(string $path): \GdImage|false
    {
        $info = @getimagesize($path);
        if (! $info) {
            return false;
        }

        return match ($info[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
    }

    /**
     * The real work: downsample, sample every thumbnail pixel, quantise into
     * colour buckets, then derive dominant / swatches / average saturation / warm.
     *
     * @return Palette|null
     */
    private function extract(\GdImage $source): ?array
    {
        $thumb = $this->downsample($source);
        if (! $thumb instanceof \GdImage) {
            return null;
        }

        try {
            $w = imagesx($thumb);
            $h = imagesy($thumb);
            if ($w < 1 || $h < 1) {
                return null;
            }

            /** @var array<int, array{count:int, r:int, g:int, b:int}> $buckets keyed by quantised colour */
            $buckets = [];
            $satSum = 0.0;
            $satCount = 0;
            $warmVotes = 0;
            $coolVotes = 0;

            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgb = imagecolorat($thumb, $x, $y);
                    // On a truecolor thumbnail the high byte is alpha (0 opaque..127 transparent).
                    $alpha = ($rgb >> 24) & 0x7F;
                    if ($alpha > 64) {
                        continue; // mostly transparent — ignore
                    }
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    [$hue, $sat] = $this->hueSaturation($r, $g, $b);
                    $satSum += $sat;
                    $satCount++;

                    // Only saturated pixels vote on warmth and seed swatches — neutral
                    // greys carry no temperature.
                    if ($sat >= self::NEUTRAL_SATURATION) {
                        if ($this->isWarmHue($hue)) {
                            $warmVotes++;
                        } else {
                            $coolVotes++;
                        }
                    }

                    $key = $this->bucketKey($r, $g, $b);
                    if (! isset($buckets[$key])) {
                        $buckets[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                    }
                    $buckets[$key]['count']++;
                    $buckets[$key]['r'] += $r;
                    $buckets[$key]['g'] += $g;
                    $buckets[$key]['b'] += $b;
                }
            }

            if ($satCount === 0 || $buckets === []) {
                return null;
            }

            // Most-populated buckets first; each swatch is the bucket's average colour.
            uasort($buckets, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            $swatches = [];
            foreach ($buckets as $bucket) {
                $swatches[] = $this->hex(
                    (int) round($bucket['r'] / $bucket['count']),
                    (int) round($bucket['g'] / $bucket['count']),
                    (int) round($bucket['b'] / $bucket['count']),
                );
                if (count($swatches) >= self::MAX_SWATCHES) {
                    break;
                }
            }

            $saturation = round($satSum / $satCount, 4);
            // Warm only when warm hues form a clear majority of the coloured pixels.
            // A tie or a mostly-neutral image (few votes) reads as not-warm → the
            // factor won't force a warm treatment off a weak signal.
            $warm = $warmVotes > $coolVotes;

            return [
                'dominant' => $swatches[0],
                'colors' => $swatches,
                'saturation' => $saturation,
                'warm' => $warm,
            ];
        } finally {
            unset($thumb);
        }
    }

    /** Downsample to a small truecolor thumbnail so per-pixel cost is bounded. */
    private function downsample(\GdImage $source): \GdImage|false
    {
        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW < 1 || $srcH < 1) {
            return false;
        }

        $ratio = min(self::SAMPLE_MAX_EDGE / $srcW, self::SAMPLE_MAX_EDGE / $srcH, 1);
        $dstW = max(1, (int) round($srcW * $ratio));
        $dstH = max(1, (int) round($srcH * $ratio));

        $thumb = imagecreatetruecolor($dstW, $dstH);
        // Preserve alpha so transparent PNG/WebP pixels stay detectable as such.
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);

        // No failure check: with two valid GdImages and ≥1 dimensions (both
        // guaranteed above), imagecopyresampled cannot fail — current PHP stubs
        // type it `true`, so a === false branch is statically dead.
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $thumb;
    }

    /**
     * Hue (0..360) + saturation (0..1) from RGB. Only the pieces of HSL/HSV we
     * need — saturation uses the HSV definition (max-min over max) so a vivid
     * colour reads high regardless of lightness.
     *
     * @return array{0: float, 1: float}
     */
    private function hueSaturation(int $r, int $g, int $b): array
    {
        $rf = $r / 255;
        $gf = $g / 255;
        $bf = $b / 255;

        $max = max($rf, $gf, $bf);
        $min = min($rf, $gf, $bf);
        $delta = $max - $min;

        $saturation = $max <= 0.0 ? 0.0 : $delta / $max;

        if ($delta <= 0.0) {
            return [0.0, $saturation]; // grey — hue undefined, call it 0
        }

        if ($max === $rf) {
            $hue = 60 * fmod(($gf - $bf) / $delta, 6);
        } elseif ($max === $gf) {
            $hue = 60 * ((($bf - $rf) / $delta) + 2);
        } else {
            $hue = 60 * ((($rf - $gf) / $delta) + 4);
        }

        if ($hue < 0) {
            $hue += 360;
        }

        return [$hue, $saturation];
    }

    /**
     * Warm hues = reds / oranges / yellows through to the warm side, plus magentas/
     * pinks. Concretely: hue ≤ 90 (red→yellow-green boundary) OR ≥ 300 (magenta→red).
     * Cool = greens / cyans / blues / violets in between. This split matches the
     * factor's warm/cool intent (warm image treatment + warm bg tint).
     */
    private function isWarmHue(float $hue): bool
    {
        return $hue <= 90.0 || $hue >= 300.0;
    }

    /** Quantised bucket key so near-identical colours group together. */
    private function bucketKey(int $r, int $g, int $b): int
    {
        $qr = intdiv($r, self::BUCKET);
        $qg = intdiv($g, self::BUCKET);
        $qb = intdiv($b, self::BUCKET);

        // 8 buckets per channel (256/32) → 3 bits each, packed into one int key.
        return ($qr << 6) | ($qg << 3) | $qb;
    }

    private function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}
