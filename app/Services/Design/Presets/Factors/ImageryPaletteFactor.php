<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;

/**
 * Media factor (band 22, Auto — just above OwnMediaAccent 20, refining it): the
 * dominant PALETTE of a user's own gallery imagery is a genuine style signal — a
 * warm-toned gallery wants a warm image treatment; a low-saturation gallery
 * wants a muted/mono read. It reads palette metadata via
 * IdentityEvidence::mediaPalette() (populated by ImageVariantService since #76
 * Part A — the factor is LIVE).
 *
 * When a palette is present:
 *   warm-dominant   → warm image treatment.
 *   low-saturation  → muted image treatment (or mono when very desaturated).
 *   high-saturation → keep accent, treatment none (let the vibrant imagery speak).
 * It emits the image treatment ONLY — never the accent column (that stays
 * OwnMediaAccent's, band 20), and no bg tint since 2026-07-10 (backgrounds are
 * owned by the user-picked theme_mode palette).
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

        return ['effect_image_treatment' => $treatment];
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
