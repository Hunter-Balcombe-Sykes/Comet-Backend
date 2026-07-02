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
 * in CategoryStylePresets where one exists; `dark` is the system's first dark
 * background (the palette auto-derives via the dispatcher's luminance picks).
 */
final class StyleTiers
{
    /** Analysis signals → design_kits columns (the 7 snapped signals). */
    public const SIGNAL_COLUMNS = [
        'bg' => 'color_bg',
        'font' => 'typography_font_family',
        'weight' => 'weight_regular',
        'text' => 'text_xs',
        'radius' => 'border_radius',
        'space' => 'space_regular',
        'motion' => 'motion_pace',
    ];

    /** @var array<string, array<string, string>> signal => tier => literal */
    private const TIERS = [
        'bg' => [
            'warm_light' => '#f7f4ee',
            'cool_light' => '#f7f8fa',
            'dark' => '#151515',
        ],
        // Font tiers ARE the catalog slugs — validated against this set.
        'font' => [
            'forma-djr' => 'forma-djr',
            'helvetica-neue' => 'helvetica-neue',
            'neue-haas-grotesk' => 'neue-haas-grotesk',
            'nb-architekt' => 'nb-architekt',
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
        'radius' => [
            'sharp' => '0.25rem',
            'moderate' => '0.6rem',
            'rounded' => '1rem',
            'very_rounded' => '1.5rem',
        ],
        'space' => [
            'compact' => '0.8rem',
            'regular' => '0.95rem',
            'generous' => '1.15rem',
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
