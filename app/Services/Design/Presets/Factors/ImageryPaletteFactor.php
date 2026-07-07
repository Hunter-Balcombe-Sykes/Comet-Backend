<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;

/**
 * Media factor (band 22, Auto — just above OwnMediaAccent 20, refining it): the
 * dominant PALETTE of a user's own gallery imagery is a genuine style signal — a
 * warm-toned gallery wants a warm image treatment + a warm bg tint; a
 * low-saturation gallery wants a muted/mono read. It reads palette metadata via
 * IdentityEvidence::mediaPalette().
 *
 * CURRENT STATE (spec §2 row 2 + §5): site.site_media stores NO colour metadata
 * today (verified — no dominant_color / palette / saturation column, no data
 * blob). mediaPalette() therefore returns [] and this factor ABSTAINS for every
 * user. The mapping below is complete and correct so that when the deferred
 * pixel-extraction job lands and populates the palette, this factor lights up
 * with no further change. Until then it is a provable no-op.
 *
 * When a palette IS present:
 *   warm-dominant   → warm image treatment + a warm bg tint.
 *   low-saturation  → muted image treatment (or mono when very desaturated).
 *   high-saturation → keep accent, treatment none (let the vibrant imagery speak).
 * It emits image-treatment + at most a bg tint — NEVER the accent column (that
 * stays OwnMediaAccent's, band 20).
 *
 * Auto: recomputes as the gallery changes. Abstains on absent/ambiguous palette.
 */
class ImageryPaletteFactor implements EvidenceFactor
{
    public const SOURCE = 'imagery-palette:treatment';

    /** Saturation thresholds (0..1) for the muted / vibrant reads. */
    private const LOW_SATURATION = 0.20;

    private const HIGH_SATURATION = 0.65;

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'imagery-palette';
    }

    public function mode(): FactorMode
    {
        return FactorMode::Auto;
    }

    public function priority(): int
    {
        // Band 22 — one notch above OwnMediaAccent (20) so it refines the media
        // read; below InstagramCategory (30).
        return 22;
    }

    /** @return array<string, string> */
    public function detect(IdentityEvidence $evidence): array
    {
        $palette = $evidence->mediaPalette();
        if ($palette === []) {
            return []; // no palette metadata stored (today: always) — abstain
        }

        $treatment = $this->treatmentFor($palette);
        if ($treatment === null) {
            return [];
        }

        $out = ['effect_image_treatment' => $treatment];

        // A warm-dominant palette also earns a warm background tint (never the
        // accent — that's OwnMediaAccent's column).
        if ($treatment === 'warm') {
            $out['color_bg'] = '#f7f4ee'; // warm light (StyleTiers warm_light)
        }

        return $out;
    }

    /**
     * The image treatment a palette implies, or null (ambiguous → abstain). Reads
     * a defensive shape: {warm: bool, saturation: float} — whatever the future
     * pixel job writes. Missing/garbage fields degrade to null.
     *
     * @param  array<string, mixed>  $palette
     */
    private function treatmentFor(array $palette): ?string
    {
        $saturation = $palette['saturation'] ?? null;
        $warm = $palette['warm'] ?? null;

        if (is_float($saturation) || is_int($saturation)) {
            if ($saturation <= self::LOW_SATURATION) {
                return $saturation <= self::LOW_SATURATION / 2 ? 'mono' : 'muted';
            }
            if ($saturation >= self::HIGH_SATURATION) {
                return 'none'; // vibrant imagery speaks for itself
            }
        }

        if ($warm === true) {
            return 'warm';
        }

        return null;
    }
}
