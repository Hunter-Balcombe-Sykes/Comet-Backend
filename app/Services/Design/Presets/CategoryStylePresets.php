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
 * wins every shared column (all buckets set the same 8 keys), not a partial
 * blend — that's the intended behaviour of a per-column priority merge over
 * two same-shaped contributions, not a bug.
 *
 * Every bucket sets the same 7 value/selection columns (all in
 * PresetTargetableColumns) so buckets are directly comparable; differentiation
 * comes from accent hue, font, weight, radius, motion pace, and surface type —
 * not from varying which columns are set. Backgrounds are OWNED by the
 * user-picked theme_mode palette (2026-07-10 rework) and entrance animation is
 * removed entirely, so buckets express character through the surviving axes:
 * a dark-leaning sector reads dark through weight/surface/accent, never bg.
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
        // Warm, appetite-driving, some character — young-serif artisanal
        // headlines, tomato accent, quick menu-board energy on solid cards.
        // (Ollies' frozen contributions predate the 2026-07-10 rework; the
        // surviving columns still match.)
        self::FOOD_DRINK => [
            'color_accent' => '#e0491f',
            'text_body' => '0.8rem',
            'weight_regular' => '300',
            'border_radius' => '0.25rem',
            'typography_font_family' => 'young-serif',
            'motion_pace' => 'fast',
            'effect_surface' => 'solid',
        ],
        // Soft, elegant, a little luxe — glass panels carry the premium-soft
        // read the old pastel bg used to.
        self::BEAUTY_PERSONAL_CARE => [
            'color_accent' => '#b8375a',
            'text_body' => '0.85rem',
            'weight_regular' => '400',
            'border_radius' => '1.5rem',
            'typography_font_family' => 'melodrama', // 17->9 swap (PROVISIONAL)
            'motion_pace' => 'normal',
            'effect_surface' => 'glass',
        ],
        // Athletic, poster-energy — gym customers respond to drive, not calm:
        // condensed caps, medium weight, quick motion, sturdy solid cards.
        // (Re-tuned 2026-07-10 from the old calm/minimal read; HoursRhythm
        // still refines pace for the daytime-clinic end of the bucket.)
        self::HEALTH_FITNESS => [
            'color_accent' => '#2f6b57',
            'text_body' => '0.85rem',
            'weight_regular' => '500',
            'border_radius' => '0.25rem',
            'typography_font_family' => 'oswald',
            'motion_pace' => 'fast',
            'effect_surface' => 'solid',
        ],
        // Trustworthy, structured, confident — outline cards read precise,
        // nothing-to-hide.
        self::PROFESSIONAL_SERVICES => [
            'color_accent' => '#1d3557',
            'text_body' => '0.85rem',
            'weight_regular' => '500',
            'border_radius' => '0.25rem',
            'typography_font_family' => 'geist', // 17->9 swap (PROVISIONAL)
            'motion_pace' => 'normal',
            'effect_surface' => 'outline',
        ],
        // Bold, boutique, fashion-forward — editorial serif + shoppable
        // solid-card energy.
        self::RETAIL_SHOPPING => [
            'color_accent' => '#d6336c',
            'text_body' => '0.85rem',
            'weight_regular' => '500',
            'border_radius' => '0.25rem',
            'typography_font_family' => 'origin', // 17->9 swap (PROVISIONAL)
            'motion_pace' => 'fast',
            'effect_surface' => 'solid',
        ],
        // Practical, sturdy, high-visibility trade colour — homeowners want
        // legible text and dependable filled panels, no design flourish.
        self::HOME_SERVICES => [
            'color_accent' => '#d97706',
            'text_body' => '0.9rem',
            'weight_regular' => '500',
            'border_radius' => '0.25rem',
            'typography_font_family' => 'humane', // 17->9 swap (PROVISIONAL)
            'motion_pace' => 'normal',
            'effect_surface' => 'solid',
        ],
        // Warm, inviting, relaxed — airy glass + unhurried motion, the lounge
        // welcome.
        self::HOSPITALITY => [
            'color_accent' => '#7c2d12',
            'text_body' => '0.85rem',
            'weight_regular' => '400',
            'border_radius' => '0.85rem',
            'typography_font_family' => 'origin', // 17->9 swap (PROVISIONAL)
            'motion_pace' => 'slow',
            'effect_surface' => 'glass',
        ],
        // Bold, industrial, high-contrast — garage-signage confidence: heavy
        // weight, near-square corners, filled panels.
        self::AUTOMOTIVE => [
            'color_accent' => '#c81e1e',
            'text_body' => '0.85rem',
            'weight_regular' => '600',
            'border_radius' => '0.25rem',
            'typography_font_family' => 'geist', // 17->9 swap (PROVISIONAL)
            'motion_pace' => 'fast',
            'effect_surface' => 'solid',
        ],
        // Expressive, distinctive, gallery-like — outline frames let the work
        // speak.
        self::CREATIVE_ENTERTAINMENT => [
            'color_accent' => '#7c3aed',
            'text_body' => '0.85rem',
            'weight_regular' => '400',
            'border_radius' => '0.25rem',
            'typography_font_family' => 'reglo',
            'motion_pace' => 'fast',
            'effect_surface' => 'outline',
        ],
        // Approachable, clear, encouraging — larger text, friendly rounding,
        // modern-app glass.
        self::EDUCATION_COACHING => [
            'color_accent' => '#2563eb',
            'text_body' => '0.9rem',
            'weight_regular' => '400',
            'border_radius' => '0.85rem',
            'typography_font_family' => 'geist', // 17->9 swap (PROVISIONAL)
            'motion_pace' => 'normal',
            'effect_surface' => 'glass',
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
