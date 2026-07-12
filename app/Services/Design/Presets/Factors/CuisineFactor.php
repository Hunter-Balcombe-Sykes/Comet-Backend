<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\CuisineLexicon;
use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;

/**
 * Refiner factor (band 44, OneShot): a food business's CUISINE refines the
 * generic hospitality look (GoogleBusinessType, band 40) — a fine-dining room, a
 * neighbourhood cafe, and a takeaway joint are all "food" but should not look
 * alike. The cuisine hint is derived from the Google-Business category display
 * string via IdentityEvidence::cuisineHint() (the only cuisine signal stored
 * today — there is no structured serves-cuisine field).
 *
 * Cuisine hint → sparse nudge (colours/font largely stay with the category
 * recipe; this leans the CHARACTER — backgrounds are theme_mode-owned since
 * 2026-07-10, so darkness/brightness reads through weight/surface instead):
 *   fine_dining  → editorial + restrained (light weight, elegant serif, outline,
 *                  flat) — also feeds the fine-dining-editorial launch recipe.
 *   cafe         → warm + rounded + friendly.
 *   fast_casual  → bold + heavy + energetic solid.
 *   specific     → a restrained, considered read (a specific cuisine reads as
 *                  "established", so calm motion + a refined serif + outline).
 * Not a food business, or an unreadable cuisine → ABSTAIN (spec §4.1).
 *
 * OneShot: cuisine is identity-stable. SAFETY: only design VALUES persist; the
 * category string is read and discarded here.
 */
class CuisineFactor implements EvidenceFactor
{
    public const SOURCE = 'cuisine:character';

    /**
     * Hint → sparse overlay. Values are all from the established vocabulary
     * (StyleTiers literals + the four effect_* identity enums). `specific` is the
     * fallback for any named-cuisine token (italian, japanese, …).
     */
    private const HINT_TARGETS = [
        CuisineLexicon::FINE_DINING => [
            'border_radius' => '0',             // square — the editorial shape; refines the food bucket's soft corners (44 > 40)
            'weight_regular' => '300',          // light
            'typography_font_family' => 'general-sans',
            'motion_pace' => 'slow',
            'effect_surface' => 'outline',      // hairline editorial restraint
            'effect_shadow_style' => 'flat',
            'effect_image_treatment' => 'duotone',
        ],
        CuisineLexicon::CAFE => [
            'border_radius' => '0.85rem',       // rounded/friendly
            'weight_regular' => '400',
            'motion_pace' => 'normal',
            'effect_shadow_style' => 'soft',
            'effect_image_treatment' => 'warm',
        ],
        CuisineLexicon::FAST_CASUAL => [
            'border_radius' => '0.25rem',       // sharp
            'weight_regular' => '600',          // chunky
            'motion_pace' => 'fast',
            'effect_surface' => 'solid',        // counter-service confidence
            'effect_shadow_style' => 'hard',
        ],
    ];

    /**
     * The restrained overlay for any specific named cuisine (italian, japanese, …).
     * Image treatment stays WARM, not muted — appetite rules food photography;
     * the restraint reads through the serif + slow pace + outline surface.
     */
    private const SPECIFIC_TARGET = [
        'weight_regular' => '400',
        'typography_font_family' => 'helvetica-neue', // refined classic (7-font simplification 2026-07-12)
        'motion_pace' => 'slow',
        'effect_surface' => 'outline',
        'effect_image_treatment' => 'warm',
    ];

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'cuisine';
    }

    public function mode(): FactorMode
    {
        return FactorMode::OneShot;
    }

    public function priority(): int
    {
        // Band 44 — above GoogleBusinessType (40) so it refines the hospitality
        // look, below the band-D refiners (52+). Category (C) band per spec §2.
        return 44;
    }

    /** @return array<string, string> */
    public function detect(IdentityEvidence $evidence): array
    {
        $hint = $evidence->cuisineHint();
        if ($hint === null) {
            return []; // not a food business, or no readable cuisine
        }

        // A specific named cuisine (not one of the three character tokens) → the
        // restrained "established cuisine" overlay.
        return self::HINT_TARGETS[$hint] ?? self::SPECIFIC_TARGET;
    }
}
