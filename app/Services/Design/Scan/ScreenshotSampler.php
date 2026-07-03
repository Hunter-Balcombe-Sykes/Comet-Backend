<?php

namespace App\Services\Design\Scan;

/**
 * Pixel evidence from the snapshot screenshot (GD). Two read-outs:
 *
 *  - bg: modal colour of the page-edge samples — literally what the page
 *    paints. A photo-dominated fold spreads the histogram wide and FAILS the
 *    modality gate (null) — pixels then simply have no opinion; they never
 *    "disagree" because a hero image exists.
 *  - accent: the dominant saturated cluster above the fold. Used ONLY to
 *    corroborate an accent candidate found in the DOM evidence — photos
 *    produce saturated clusters too, so pixels never nominate an accent.
 */
class ScreenshotSampler
{
    /**
     * Edge samples must concentrate this hard into one bucket to call a bg.
     * Fallback default for config('partna.brand_scan.sampler.bg_modality_min').
     */
    private const BG_MODALITY_MIN = 0.55;

    /** Fallback default for config('partna.brand_scan.sampler.accent_min_samples'). */
    private const ACCENT_MIN_SAMPLES = 200;

    /**
     * Of saturated samples. Fallback default for
     * config('partna.brand_scan.sampler.accent_cluster_min_share').
     */
    private const ACCENT_CLUSTER_MIN_SHARE = 0.30;

    /**
     * @return array{bg: ?string, accent: ?string} hex colours or null
     */
    public function summarize(string $pngBytes): array
    {
        if ($pngBytes === '') {
            return ['bg' => null, 'accent' => null];
        }
        $img = @imagecreatefromstring($pngBytes);
        if ($img === false) {
            return ['bg' => null, 'accent' => null];
        }

        try {
            return [
                'bg' => $this->modalEdgeColor($img),
                'accent' => $this->saturatedCluster($img),
            ];
        } finally {
            imagedestroy($img);
        }
    }

    /** Sample the left/right page margins (below any announcement bar). */
    private function modalEdgeColor(\GdImage $img): ?string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 40 || $h < 40) {
            return null;
        }

        $buckets = [];
        $members = [];
        $total = 0;
        $xs = [max(1, (int) ($w * 0.015)), min($w - 2, (int) ($w * 0.985))];
        for ($y = (int) ($h * 0.10); $y < (int) ($h * 0.95); $y += 8) {
            foreach ($xs as $x) {
                [$r, $g, $b] = $this->rgbAt($img, $x, $y);
                $key = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
                $members[$key][0] = ($members[$key][0] ?? 0) + $r;
                $members[$key][1] = ($members[$key][1] ?? 0) + $g;
                $members[$key][2] = ($members[$key][2] ?? 0) + $b;
                $total++;
            }
        }
        if ($total < 40 || $buckets === []) {
            return null;
        }

        arsort($buckets);
        $topKey = array_key_first($buckets);
        $topCount = $buckets[$topKey];
        if ($topCount / $total < (float) config('partna.brand_scan.sampler.bg_modality_min', self::BG_MODALITY_MIN)) {
            return null; // no dominant flat colour at the edges — abstain
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round($members[$topKey][0] / $topCount),
            (int) round($members[$topKey][1] / $topCount),
            (int) round($members[$topKey][2] / $topCount),
        );
    }

    /** Dominant saturated cluster above the fold (corroborator only). */
    private function saturatedCluster(\GdImage $img): ?string
    {
        $w = imagesx($img);
        $h = imagesy($img);

        $buckets = [];
        $members = [];
        $saturated = 0;
        for ($y = 0; $y < $h; $y += 6) {
            for ($x = 0; $x < $w; $x += 6) {
                [$r, $g, $b] = $this->rgbAt($img, $x, $y);
                $max = max($r, $g, $b);
                $sat = $max === 0 ? 0.0 : ($max - min($r, $g, $b)) / $max;
                $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
                if ($sat < 0.35 || $lum < 0.10 || $lum > 0.90) {
                    continue;
                }
                $key = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
                $members[$key][0] = ($members[$key][0] ?? 0) + $r;
                $members[$key][1] = ($members[$key][1] ?? 0) + $g;
                $members[$key][2] = ($members[$key][2] ?? 0) + $b;
                $saturated++;
            }
        }
        if ($saturated < (int) config('partna.brand_scan.sampler.accent_min_samples', self::ACCENT_MIN_SAMPLES) || $buckets === []) {
            return null;
        }

        arsort($buckets);
        $topKey = array_key_first($buckets);
        $topCount = $buckets[$topKey];
        if ($topCount / $saturated < (float) config('partna.brand_scan.sampler.accent_cluster_min_share', self::ACCENT_CLUSTER_MIN_SHARE)) {
            return null;
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round($members[$topKey][0] / $topCount),
            (int) round($members[$topKey][1] / $topCount),
            (int) round($members[$topKey][2] / $topCount),
        );
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgbAt(\GdImage $img, int $x, int $y): array
    {
        $rgb = imagecolorat($img, $x, $y);

        return [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
    }
}
