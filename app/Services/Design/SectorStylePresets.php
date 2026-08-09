<?php

namespace App\Services\Design;

/**
 * Per-industry design overlays — THE single tunable surface for "what does a
 * barber's site feel like by default". Two sparse tiers over the package
 * defaults, both keyed off the user's sector field:
 *
 *   1. BUCKET base (SectorTaxonomy bucket) — the industry's shared register.
 *   2. SLUG refinement — sharpens within the bucket (a spa and a barber share
 *      beauty_personal_care but not a look). Merged OVER the base by
 *      ProfileDesignPresets; a refinement may re-emit a package default to
 *      undo a base departure.
 *
 * ─── 2026-08-09: ACCENT AND FONT, AND THAT IS ALL. ───────────────────────────
 *
 * The preset-only migration (20260809090001) took the schema from 57 columns
 * to 8. Of the columns these presets used to set, only two survive as things
 * a sector can meaningfully say:
 *
 *   • color_accent            — kept
 *   • typography_font_family  — kept
 *   • weight_regular / weight_heading / text_body / text_desktop_body /
 *     typography_line_height / space_regular / border_radius  — COLUMNS GONE
 *   • effect_shadow_style / effect_link_style / effect_image_treatment
 *                             — AXES DELETED (brief §3.1): shadow becomes the
 *                               opt-in `.floating` class, link and image each
 *                               get one fixed treatment in the components
 *   • border_thickness        — column survives, but as a two-value selection
 *                               ('default' | 'none'). The three presets that
 *                               set it wanted '2px' — chunky signage — which
 *                               the new control cannot express; 'default' is
 *                               just the default, so re-emitting it would be
 *                               noise. Dropped rather than flattened.
 *
 * OWNER DECISION (2026-08-09, brief §9 / plan 6.3): **let the gutted sectors
 * collapse.** No new differentiation was invented to fill the gap. Sectors
 * that are now accent-plus-font — which is all of them — are accepted as-is.
 *
 * Three slug refinements lost every key they had and were removed outright
 * rather than left as empty arrays: `food-truck` (weights + hard shadow +
 * 2px rules), `personal-chef` (weights + untreated imagery + space + leading)
 * and `personal-trainer` (heading weight alone). Each now takes its bucket
 * base unmodified, which is the honest result. `videographer` had already
 * gone the same way on 2026-08-06 when the palette roster collapsed.
 *
 * Every value still targets a live design_kits column — see
 * tests/Unit/Design/FontRosterTest.php, which fails if a preset seeds a
 * column the schema no longer has.
 *
 * Narrowed 2026-08-06 with the design-kit simplification: theme_mode,
 * theme_contrast, typography_tracking, typography_uppercase, motion_pace and
 * border_style went as columns. Every site renders on the bleach palette,
 * which is why the accents here are all mid-range — the dark-room re-tuning
 * those slugs used to need has no dark room left to serve. Fonts only from
 * the live 4-font roster.
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
        // Appetite-led: tomato accent, menu-board grotesque.
        self::FOOD_DRINK => [
            'color_accent' => '#e0491f',
            'typography_font_family' => 'monument-grotesk',
        ],
        // Polished, soft, a little luxe.
        self::BEAUTY_PERSONAL_CARE => [
            'color_accent' => '#b8375a',
            'typography_font_family' => 'helvetica-neue',
        ],
        // Athletic poster-energy.
        self::HEALTH_FITNESS => [
            'color_accent' => '#2f6b57',
            'typography_font_family' => 'monument-grotesk',
        ],
        // Trustworthy, structured, documentary. Font stays the package default.
        self::PROFESSIONAL_SERVICES => [
            'color_accent' => '#1d3557',
        ],
        // Fashion-editorial: classic Helvetica.
        self::RETAIL_SHOPPING => [
            'color_accent' => '#d6336c',
            'typography_font_family' => 'helvetica-neue',
        ],
        // Practical, sturdy, legible — the workhorse font. (A homeowner is
        // hiring a person, not a factory; the softer corners that used to say
        // so left with border_radius.)
        self::HOME_SERVICES => [
            'color_accent' => '#d97706',
            'typography_font_family' => 'forma-djr',
        ],
        // The lounge welcome: warm ink.
        self::HOSPITALITY => [
            'color_accent' => '#7c2d12',
            'typography_font_family' => 'monument-grotesk',
        ],
        // Garage-signage confidence. (The heavy weights, chunky rules and
        // hard-offset shadows that carried this went with their columns; the
        // red and the grotesque are what is left of it.)
        self::AUTOMOTIVE => [
            'color_accent' => '#c81e1e',
            'typography_font_family' => 'monument-grotesk',
        ],
        // Gallery-like: expressive workhorse font — the work speaks.
        self::CREATIVE_ENTERTAINMENT => [
            'color_accent' => '#7c3aed',
            'typography_font_family' => 'forma-djr',
        ],
        // Approachable and clear: proven UI font.
        self::EDUCATION_COACHING => [
            'color_accent' => '#2563eb',
            'typography_font_family' => 'helvetica-neue',
        ],
    ];

    /**
     * Slug refinements — sparse deltas merged OVER the slug's bucket base.
     * Only slugs that meaningfully depart from their bucket appear here.
     *
     * @var array<string, array<string, string|bool>>
     */
    private const SLUG_REFINEMENTS = [
        // Espresso-toned neighbourhood calm.
        'cafe' => ['color_accent' => '#92400e'],
        // Caramel warmth.
        'bakery' => ['color_accent' => '#b45309'],
        // Late-night mood carried by the ink alone: bright wine.
        'bar' => ['color_accent' => '#be123c'],
        // (food-truck and personal-chef departed from their bucket only in
        // weights, shadows, imagery, space and leading — every one of those
        // columns is gone, so neither has a refinement left to make.)

        // Sharp + classic: pole-red over a sturdy grotesque.
        'barber' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#b91c1c'],
        // Calm-luxe eucalyptus.
        'spa' => ['color_accent' => '#0f766e'],
        // Flash-sheet red.
        'tattoo-artist' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#dc2626'],
        // Editorial glam: Helvetica, the look book unfiltered.
        'makeup-artist' => ['typography_font_family' => 'helvetica-neue'],

        // Poster register for the gym floor: emerald.
        'gym' => ['color_accent' => '#10b981'],
        // (personal-trainer's only departure was a heavier heading weight.)
        // The opposite of gym energy: soft, approachable slate.
        'therapist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#64748b'],
        // Grounded calm: sage.
        'yoga-instructor' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#5f7a61'],
        // Fresh + factual.
        'nutritionist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#4d7c0f'],
        // Clinical trust: clinical teal.
        'physiotherapist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#0e7490'],
        'chiropractor' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#0e7490'],
        'dentist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#0e7490'],

        // Creative-pro breaks the suit: expressive font, violet.
        'marketing-agency' => ['typography_font_family' => 'forma-djr', 'color_accent' => '#6d28d9'],
        // Premium property: dark gold.
        'real-estate-agent' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#a16207'],

        // Velvet-case luxe: gold, editorial type.
        'jewellery' => ['color_accent' => '#ca8a04', 'typography_font_family' => 'helvetica-neue'],
        // Soft botanical.
        'florist' => ['typography_font_family' => 'monument-grotesk'],
        // Handmade warmth: terracotta.
        'artisan-maker' => ['typography_font_family' => 'forma-djr', 'color_accent' => '#9a3412'],
        // Muted interior calm: stone.
        'homewares' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#57534e'],

        'plumber' => ['color_accent' => '#0369a1'],
        'electrician' => ['color_accent' => '#1d4ed8'],
        'landscaper' => ['color_accent' => '#3f6212'],
        'cleaner' => ['color_accent' => '#0891b2'],

        // Romantic editorial: rose-gold.
        'wedding-planner' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#b76e79'],
        // The bar-room wine, over the hospitality base.
        'bartender' => ['color_accent' => '#be123c'],

        // Gloss-and-water blue over the garage base.
        'car-detailer' => ['color_accent' => '#0284c7'],

        // Quiet gallery: light editorial Helvetica, neutral slate.
        'photographer' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#475569'],
        // Gig-poster: grotesque, hot pink-red.
        'musician' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#e11d48'],
        // (videographer's only refinement was a cinematic dark theme_mode —
        // it departed from its bucket in nothing else, so with one palette
        // left it has no refinement to make.)
        // Literary restraint: navy ink.
        'writer' => ['color_accent' => '#1d3557'],
        // Vibrant + friendly.
        'content-creator' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#db2777'],

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
