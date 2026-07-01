<?php

namespace App\Services\Design\Presets;

/**
 * Shared style buckets for category-driven design factors (GoogleBusinessTypeFactor,
 * InstagramCategoryFactor, and any future one). Buckets are keyed by REAL-WORLD
 * BUSINESS THEME, not by platform — a restaurant found via Google and a
 * restaurant found via Instagram render identically, because both factors
 * resolve into the same bucket here. This is what lets the priority merge
 * (DesignPresetResolver) treat the two sources as genuinely comparable: when
 * both match the SAME bucket the values are identical (no visible conflict);
 * when they match DIFFERENT buckets, the higher-priority factor's full bucket
 * wins every shared column (all buckets set the same 7 keys), not a partial
 * blend — that's the intended behaviour of a per-column priority merge over
 * two same-shaped contributions, not a bug.
 *
 * Every bucket sets the same 7 value/selection columns (all in
 * PresetTargetableColumns) so buckets are directly comparable; differentiation
 * comes from accent hue, font, weight, radius, and motion pace — not from
 * varying which columns are set.
 */
final class CategoryStylePresets
{
    public const FOOD_DRINK = 'food_drink';

    public const BEAUTY_PERSONAL_CARE = 'beauty_personal_care';

    public const HEALTH_FITNESS = 'health_fitness';

    public const PROFESSIONAL_SERVICES = 'professional_services';

    public const RETAIL_SHOPPING = 'retail_shopping';

    public const HOME_SERVICES = 'home_services';

    public const HOSPITALITY = 'hospitality';

    public const AUTOMOTIVE = 'automotive';

    public const CREATIVE_ENTERTAINMENT = 'creative_entertainment';

    public const EDUCATION_COACHING = 'education_coaching';

    /** @var array<string, array<string, string>> */
    private const BUCKETS = [
        // Warm, appetite-driving, some character — the original shipped
        // restaurant recipe, unchanged (ollies' existing frozen contributions
        // already match this exactly).
        self::FOOD_DRINK => [
            'color_bg' => '#f7f4ee',
            'color_accent' => '#e0491f',
            'text_xs' => '0.8rem',
            'weight_regular' => '300',
            'border_radius' => '0.25rem',
            'typography_font_family' => 'forma-djr',
            'motion_pace' => 'fast',
        ],
        // Soft, elegant, a little luxe.
        self::BEAUTY_PERSONAL_CARE => [
            'color_bg' => '#faf6f3',
            'color_accent' => '#b8375a',
            'text_xs' => '0.85rem',
            'weight_regular' => '400',
            'border_radius' => '1.5rem',
            'typography_font_family' => 'neue-haas-grotesk',
            'motion_pace' => 'normal',
        ],
        // Calmer, minimal — muted colour, clean type, unhurried motion.
        self::HEALTH_FITNESS => [
            'color_bg' => '#f5f6f5',
            'color_accent' => '#2f6b57',
            'text_xs' => '0.85rem',
            'weight_regular' => '400',
            'border_radius' => '0.6rem',
            'typography_font_family' => 'helvetica-neue',
            'motion_pace' => 'slow',
        ],
        // Trustworthy, structured, confident.
        self::PROFESSIONAL_SERVICES => [
            'color_bg' => '#f8f8f7',
            'color_accent' => '#1d3557',
            'text_xs' => '0.85rem',
            'weight_regular' => '500',
            'border_radius' => '0.4rem',
            'typography_font_family' => 'neue-haas-grotesk',
            'motion_pace' => 'normal',
        ],
        // Bold, boutique, fashion-forward.
        self::RETAIL_SHOPPING => [
            'color_bg' => '#ffffff',
            'color_accent' => '#d6336c',
            'text_xs' => '0.85rem',
            'weight_regular' => '500',
            'border_radius' => '0.3rem',
            'typography_font_family' => 'nb-architekt',
            'motion_pace' => 'fast',
        ],
        // Practical, sturdy, high-visibility trade colour.
        self::HOME_SERVICES => [
            'color_bg' => '#f9f9f7',
            'color_accent' => '#d97706',
            'text_xs' => '0.9rem',
            'weight_regular' => '500',
            'border_radius' => '0.5rem',
            'typography_font_family' => 'helvetica-neue',
            'motion_pace' => 'normal',
        ],
        // Warm, inviting, relaxed.
        self::HOSPITALITY => [
            'color_bg' => '#f6f1e7',
            'color_accent' => '#7c2d12',
            'text_xs' => '0.85rem',
            'weight_regular' => '400',
            'border_radius' => '1rem',
            'typography_font_family' => 'forma-djr',
            'motion_pace' => 'slow',
        ],
        // Bold, industrial, high-contrast.
        self::AUTOMOTIVE => [
            'color_bg' => '#f2f2f2',
            'color_accent' => '#c81e1e',
            'text_xs' => '0.85rem',
            'weight_regular' => '600',
            'border_radius' => '0.2rem',
            'typography_font_family' => 'helvetica-neue',
            'motion_pace' => 'fast',
        ],
        // Expressive, distinctive, gallery-like.
        self::CREATIVE_ENTERTAINMENT => [
            'color_bg' => '#fafafa',
            'color_accent' => '#7c3aed',
            'text_xs' => '0.85rem',
            'weight_regular' => '400',
            'border_radius' => '0.3rem',
            'typography_font_family' => 'nb-architekt',
            'motion_pace' => 'fast',
        ],
        // Approachable, clear, encouraging.
        self::EDUCATION_COACHING => [
            'color_bg' => '#f7f8fa',
            'color_accent' => '#2563eb',
            'text_xs' => '0.9rem',
            'weight_regular' => '400',
            'border_radius' => '0.8rem',
            'typography_font_family' => 'helvetica-neue',
            'motion_pace' => 'normal',
        ],
    ];

    /** @return array<string, string> the bucket's preset values, or [] for an unknown key */
    public static function forBucket(string $bucket): array
    {
        return self::BUCKETS[$bucket] ?? [];
    }

    /**
     * Classify a raw category string against an ORDERED keyword => bucket map.
     * First substring match wins (case-insensitive) — callers must order
     * colliding keywords specific-before-generic (e.g. 'barber' before 'bar',
     * so "Barber shop" doesn't fall through to the food_drink 'bar' keyword).
     *
     * @param  array<string, string>  $orderedKeywordToBucket
     */
    public static function classify(string $raw, array $orderedKeywordToBucket): ?string
    {
        $lower = strtolower(trim($raw));
        if ($lower === '' || $lower === 'none') {
            return null;
        }

        foreach ($orderedKeywordToBucket as $keyword => $bucket) {
            if (str_contains($lower, $keyword)) {
                return $bucket;
            }
        }

        return null;
    }
}
