<?php

namespace App\Services\Design\Presets;

/**
 * Semantic style tiers → our curated design-kit literals.
 *
 * Website analysis (WebsiteStyleAnalyzer) classifies a site's raw CSS into
 * SEMANTIC tiers ('warm_light', 'sharp', …); the factors map tiers to literals
 * here. A scraped value can never land in a design-kit column directly — this
 * table is the only bridge (accent colour is the one deliberate exception,
 * handled in PreviousWebsiteFactor). Literals reuse values already established
 * in CategoryStylePresets where one exists.
 *
 * NOTE: the analyzer still emits a `bg` signal, but it is NOT mapped — the
 * background is owned by the user-picked theme_mode palette (2026-07-10
 * rework), so a scraped bg tier deliberately degrades to "no contribution".
 */
final class StyleTiers
{
    /** Analysis signals → design_kits columns (the 6 snapped signals). */
    public const SIGNAL_COLUMNS = [
        'font' => 'typography_font_family',
        'weight' => 'weight_regular',
        'text' => 'text_body',
        'radius' => 'border_radius',
        'space' => 'space_regular',
        'motion' => 'motion_pace',
    ];

    /** @var array<string, array<string, string>> signal => tier => literal */
    private const TIERS = [
        // Font tiers ARE the catalog slugs — validated against this set.
        // 9-font roster locked 2026-07-09 (docs/design/font-icon-knowledge.md).
        // RETIRED — never reintroduce: the 4 pre-2026-07 slugs plus the 13
        // dropped in the 17->9 swap (see RETIRED_FONT_MIGRATION in the registry).
        'font' => [
            'geist' => 'geist',
            'humane' => 'humane',
            'melodrama' => 'melodrama',
            'origin' => 'origin',
            'ostrich-sans' => 'ostrich-sans',
            'oswald' => 'oswald',
            'parceh' => 'parceh',
            'reglo' => 'reglo',
            'young-serif' => 'young-serif',
        ],
        'weight' => [
            'light' => '300',
            'regular' => '400',
            'medium' => '500',
        ],
        'text' => [
            'small' => '0.8rem',
            'regular' => '0.85rem',
            'large' => '0.9rem',
        ],
        // 2026-07-10 corner vocabulary {0, 0.25, 0.85, 1.5}rem — the four
        // analyzer tiers map 1:1 onto the four stops. 'sharp' means the old
        // site's median radius was under 4px (a population dominated by true
        // 0px designs), so the faithful match is the Square stop, not Soft;
        // collapsing sharp+moderate onto 0.25rem made two distinct analyzer
        // conclusions indistinguishable downstream.
        'radius' => [
            'sharp' => '0',
            'moderate' => '0.25rem',
            'rounded' => '0.85rem',
            'very_rounded' => '1.5rem',
        ],
        // 2026-07-12: rescaled to the new package-default regular (0.7rem,
        // matched to espaciolanube.com's measured spacing unit) — same
        // ratios as before (x0.7/0.95).
        'space' => [
            'compact' => '0.6rem',
            'regular' => '0.7rem',
            'generous' => '0.85rem',
        ],
        'motion' => [
            'fast' => 'fast',
            'normal' => 'normal',
            'slow' => 'slow',
        ],
    ];

    /**
     * Map an analysis tier map (signal => tier|null) to design-kit columns.
     * Unknown signals/tiers are dropped — an analyzer bug degrades to "no
     * contribution", never to a bad value.
     *
     * @param  array<string, mixed>  $tiers
     * @return array<string, string>
     */
    public static function columnsFromTiers(array $tiers): array
    {
        $out = [];
        foreach (self::SIGNAL_COLUMNS as $signal => $column) {
            $tier = $tiers[$signal] ?? null;
            if (is_string($tier) && isset(self::TIERS[$signal][$tier])) {
                $out[$column] = self::TIERS[$signal][$tier];
            }
        }

        return $out;
    }
}
