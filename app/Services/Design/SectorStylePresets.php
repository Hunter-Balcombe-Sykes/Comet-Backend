<?php

namespace App\Services\Design;

/**
 * Per-industry design overlays — THE single tunable surface for "what does a
 * barber's site feel like by default". Two sparse tiers over the
 * ALD-calibrated package defaults, both keyed off the user's sector field:
 *
 *   1. BUCKET base (SectorTaxonomy bucket) — the industry's shared register.
 *   2. SLUG refinement — sharpens within the bucket (a spa and a barber share
 *      beauty_personal_care but not a look). Merged OVER the base by
 *      ProfileDesignPresets; a refinement may re-emit a package default to
 *      undo a base departure.
 *
 * Every value targets a VALUE/SELECTION design_kits column only — never
 * inferred vars (they derive at render time).
 *
 * Narrowed 2026-08-06 with the design-kit simplification: theme_mode,
 * theme_contrast, typography_tracking, typography_uppercase, motion_pace and
 * border_style are gone as columns, so no preset sets them. Every site now
 * renders on the bleach palette, which is why the accents here are all
 * mid-range — the dark-room re-tuning those slugs used to need has no dark
 * room left to serve. Fonts only from the live 4-font roster.
 */
final class SectorStylePresets
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

    /** @var array<string, array<string, string|bool>> */
    private const BUCKETS = [
        // Appetite-led: tomato accent, light menu-board type, warm-cast
        // photography.
        self::FOOD_DRINK => [
            'color_accent' => '#e0491f',
            'typography_font_family' => 'monument-grotesk',
            'weight_regular' => '300',
            'effect_image_treatment' => 'warm',
        ],
        // Polished, soft, a little luxe — gentle rounding + soft shadows;
        // photography untreated (colour fidelity rules beauty work).
        self::BEAUTY_PERSONAL_CARE => [
            'color_accent' => '#b8375a',
            'typography_font_family' => 'helvetica-neue',
            'border_radius' => '0.25rem',
            'effect_shadow_style' => 'soft',
        ],
        // Athletic poster-energy: bold grotesque, medium weight.
        self::HEALTH_FITNESS => [
            'color_accent' => '#2f6b57',
            'typography_font_family' => 'monument-grotesk',
            'weight_regular' => '500',
        ],
        // Trustworthy, structured, documentary — confident weight and always-
        // underlined links (nothing to hide). Font stays the package default.
        self::PROFESSIONAL_SERVICES => [
            'color_accent' => '#1d3557',
            'weight_regular' => '500',
            'effect_link_style' => 'underline-always',
        ],
        // Fashion-editorial: classic Helvetica, assertive weight.
        self::RETAIL_SHOPPING => [
            'color_accent' => '#d6336c',
            'typography_font_family' => 'helvetica-neue',
            'weight_regular' => '500',
        ],
        // Practical, sturdy, legible — bigger body (desktop paired so wide
        // screens never shrink), workhorse font, soft rounding (a homeowner
        // is hiring a person, not a factory).
        self::HOME_SERVICES => [
            'color_accent' => '#d97706',
            'typography_font_family' => 'forma-djr',
            'text_body' => '0.8125rem',
            'text_desktop_body' => '0.8125rem',
            'weight_regular' => '500',
            'border_radius' => '0.25rem',
        ],
        // The lounge welcome: warm ink, softly rounded, warm imagery.
        self::HOSPITALITY => [
            'color_accent' => '#7c2d12',
            'typography_font_family' => 'monument-grotesk',
            'border_radius' => '0.25rem',
            'effect_image_treatment' => 'warm',
        ],
        // Garage-signage confidence: heavy weight, chunky borders, hard-offset
        // shadows. All four automotive slugs are garage-flavoured, so the
        // register sits on the bucket.
        self::AUTOMOTIVE => [
            'color_accent' => '#c81e1e',
            'typography_font_family' => 'monument-grotesk',
            'weight_regular' => '600',
            'weight_heading' => '600',
            'border_thickness' => '2px',
            'effect_shadow_style' => 'hard',
        ],
        // Gallery-like: expressive workhorse font, chrome-less plain links —
        // the work speaks.
        self::CREATIVE_ENTERTAINMENT => [
            'color_accent' => '#7c3aed',
            'typography_font_family' => 'forma-djr',
            'effect_link_style' => 'plain',
        ],
        // Approachable and clear: proven UI font, bigger body (desktop
        // paired), airier line-height, soft rounding and shadows — the
        // encouraging modern-app read.
        self::EDUCATION_COACHING => [
            'color_accent' => '#2563eb',
            'typography_font_family' => 'helvetica-neue',
            'text_body' => '0.8125rem',
            'text_desktop_body' => '0.8125rem',
            'typography_line_height' => '1.3',
            'border_radius' => '0.25rem',
            'effect_shadow_style' => 'soft',
        ],
    ];

    /**
     * Slug refinements — sparse deltas merged OVER the slug's bucket base.
     * Only slugs that meaningfully depart from their bucket appear here.
     *
     * @var array<string, array<string, string|bool>>
     */
    private const SLUG_REFINEMENTS = [
        // Espresso-toned neighbourhood calm, gently rounded.
        'cafe' => ['color_accent' => '#92400e', 'border_radius' => '0.25rem'],
        // Caramel warmth, soft pastry rounding.
        'bakery' => ['color_accent' => '#b45309', 'border_radius' => '0.25rem'],
        // Late-night mood carried by the ink alone now: bright wine, muted
        // imagery, regular weight.
        'bar' => ['color_accent' => '#be123c', 'effect_image_treatment' => 'muted', 'weight_regular' => '400'],
        // Street-sign punch: chunky rules, heavy headings, hard shadows.
        'food-truck' => ['weight_regular' => '500', 'weight_heading' => '600', 'effect_shadow_style' => 'hard', 'border_thickness' => '2px'],
        // Plated restraint: airy, untreated photography, generous leading.
        'personal-chef' => ['weight_regular' => '400', 'weight_heading' => '400', 'effect_image_treatment' => 'none', 'space_regular' => '0.75rem', 'typography_line_height' => '1.3'],

        // Sharp + classic: pole-red, square, flat, mono portfolio, sturdy
        // grotesque.
        'barber' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#b91c1c', 'border_radius' => '0', 'effect_shadow_style' => 'flat', 'weight_regular' => '500', 'effect_image_treatment' => 'mono'],
        // Calm-luxe: eucalyptus, light airy type, muted imagery, room to
        // breathe.
        'spa' => ['color_accent' => '#0f766e', 'weight_regular' => '300', 'weight_heading' => '400', 'effect_image_treatment' => 'muted', 'space_regular' => '0.75rem', 'typography_line_height' => '1.3'],
        // Flash-sheet: red, square, hard shadows, chunky rules, mono work.
        'tattoo-artist' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#dc2626', 'border_radius' => '0', 'effect_shadow_style' => 'hard', 'effect_image_treatment' => 'mono', 'border_thickness' => '2px'],
        // Editorial glam: Helvetica, square, flat — the look book, unfiltered.
        'makeup-artist' => ['typography_font_family' => 'helvetica-neue', 'border_radius' => '0', 'effect_shadow_style' => 'flat'],

        // Poster register for the gym floor: emerald, heavy headings.
        'gym' => ['color_accent' => '#10b981', 'weight_heading' => '600'],
        // Coach energy, one notch below the gym's signage.
        'personal-trainer' => ['weight_heading' => '600'],
        // The opposite of gym energy: soft, airy, approachable slate.
        'therapist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#64748b', 'weight_regular' => '400', 'weight_heading' => '400', 'border_radius' => '0.25rem', 'effect_shadow_style' => 'soft', 'typography_line_height' => '1.3'],
        // Grounded calm: sage, light type, breathing room.
        'yoga-instructor' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#5f7a61', 'weight_regular' => '300', 'border_radius' => '0.25rem', 'space_regular' => '0.75rem', 'typography_line_height' => '1.3'],
        // Fresh + factual.
        'nutritionist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#4d7c0f', 'weight_regular' => '400'],
        // Clinical trust: clinical teal, gentle rounding.
        'physiotherapist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#0e7490', 'weight_regular' => '400', 'border_radius' => '0.25rem'],
        'chiropractor' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#0e7490', 'weight_regular' => '400', 'border_radius' => '0.25rem'],
        'dentist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#0e7490', 'weight_regular' => '400', 'border_radius' => '0.25rem', 'effect_shadow_style' => 'soft'],

        // Creative-pro breaks the suit: expressive font, violet.
        'marketing-agency' => ['typography_font_family' => 'forma-djr', 'color_accent' => '#6d28d9', 'weight_regular' => '400', 'effect_link_style' => 'underline-hover'],
        // Premium property: dark gold, light headings, soft depth.
        'real-estate-agent' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#a16207', 'weight_regular' => '400', 'weight_heading' => '400', 'effect_shadow_style' => 'soft', 'effect_link_style' => 'underline-hover'],

        // Velvet-case luxe: gold, light editorial type, generous space.
        'jewellery' => ['color_accent' => '#ca8a04', 'typography_font_family' => 'helvetica-neue', 'weight_regular' => '300', 'weight_heading' => '400', 'space_regular' => '0.875rem'],
        // Soft botanical.
        'florist' => ['typography_font_family' => 'monument-grotesk', 'weight_regular' => '400', 'border_radius' => '0.25rem', 'effect_shadow_style' => 'soft'],
        // Handmade warmth: terracotta, warm imagery, soft corners.
        'artisan-maker' => ['typography_font_family' => 'forma-djr', 'color_accent' => '#9a3412', 'weight_regular' => '400', 'border_radius' => '0.25rem', 'effect_image_treatment' => 'warm'],
        // Muted interior calm: stone accent, muted imagery, roomy.
        'homewares' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#57534e', 'weight_regular' => '400', 'effect_image_treatment' => 'muted', 'space_regular' => '0.75rem'],

        'plumber' => ['color_accent' => '#0369a1'],
        'electrician' => ['color_accent' => '#1d4ed8'],
        'landscaper' => ['color_accent' => '#3f6212'],
        'cleaner' => ['color_accent' => '#0891b2'],

        // Romantic editorial: rose-gold, light airy type, soft depth.
        'wedding-planner' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#b76e79', 'weight_regular' => '300', 'weight_heading' => '400', 'effect_shadow_style' => 'soft', 'space_regular' => '0.75rem', 'typography_line_height' => '1.3'],
        // The bar-room wine, over the hospitality base.
        'bartender' => ['color_accent' => '#be123c'],

        // Gloss-and-water blue over the garage base.
        'car-detailer' => ['color_accent' => '#0284c7'],

        // NEVER filter a photographer's work; borderless quiet gallery,
        // light editorial Helvetica, generous space, neutral slate.
        'photographer' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#475569', 'weight_regular' => '300', 'weight_heading' => '400', 'space_regular' => '0.875rem'],
        // Gig-poster: grotesque, hot pink-red, hard shadows, heavy headings.
        'musician' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#e11d48', 'effect_shadow_style' => 'hard', 'weight_heading' => '600'],
        // (videographer's only refinement was a cinematic dark theme_mode —
        // it departed from its bucket in nothing else, so with one palette
        // left it has no refinement to make.)
        // Literary restraint: navy ink, readable leading, honest links.
        'writer' => ['color_accent' => '#1d3557', 'weight_regular' => '400', 'effect_link_style' => 'underline-always', 'typography_line_height' => '1.3'],
        // Vibrant + friendly, app-bubble round.
        'content-creator' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#db2777', 'border_radius' => '0.85rem'],

        // Editorial polish over the app-clean base.
        'life-coach' => ['typography_font_family' => 'helvetica-neue'],
        // Expressive movement.
        'dance-instructor' => ['color_accent' => '#db2777'],
    ];

    /** @return list<string> every declared bucket key */
    public static function buckets(): array
    {
        return array_keys(self::BUCKETS);
    }

    /** @return list<string> every slug with a refinement layer */
    public static function refinedSlugs(): array
    {
        return array_keys(self::SLUG_REFINEMENTS);
    }

    /** @return array<string, string|bool> the bucket's base overlay, or [] for an unknown key */
    public static function forBucket(string $bucket): array
    {
        return self::BUCKETS[$bucket] ?? [];
    }

    /** @return array<string, string|bool> the slug's refinement, or [] when the slug has none */
    public static function forSlug(string $slug): array
    {
        return self::SLUG_REFINEMENTS[$slug] ?? [];
    }
}
