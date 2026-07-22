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
 * inferred vars (they derive at render time). theme_mode IS in scope (the
 * palette is the site's colour identity); theme_night_shift_auto never is
 * (a functional toggle, not an aesthetic pick). Accents stay mid-range on
 * the default bleach palette but are re-tuned per-slug wherever a slug picks
 * a dark theme_mode, so they still read on that palette. Fonts only from the
 * live 7-font roster.
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
        // Appetite-led: tomato accent, warm humanist sans, light menu-board
        // type, quick energy, warm-cast photography.
        self::FOOD_DRINK => [
            'color_accent' => '#e0491f',
            'typography_font_family' => 'general-sans',
            'weight_regular' => '300',
            'motion_pace' => 'fast',
            'effect_image_treatment' => 'warm',
        ],
        // Polished, soft, a little luxe — gentle rounding + soft shadows;
        // photography untreated (colour fidelity rules beauty work).
        self::BEAUTY_PERSONAL_CARE => [
            'color_accent' => '#b8375a',
            'typography_font_family' => 'helvetica-now',
            'border_radius' => '0.25rem',
            'effect_shadow_style' => 'soft',
        ],
        // Athletic poster-energy: bold grotesque, medium weight, quick motion.
        self::HEALTH_FITNESS => [
            'color_accent' => '#2f6b57',
            'typography_font_family' => 'monument-grotesk',
            'weight_regular' => '500',
            'motion_pace' => 'fast',
        ],
        // Trustworthy, structured, documentary — confident weight and always-
        // underlined links (nothing to hide). Font stays the geist default.
        self::PROFESSIONAL_SERVICES => [
            'color_accent' => '#1d3557',
            'weight_regular' => '500',
            'effect_link_style' => 'underline-always',
        ],
        // Fashion-editorial: classic Helvetica, assertive weight, quick pace.
        self::RETAIL_SHOPPING => [
            'color_accent' => '#d6336c',
            'typography_font_family' => 'helvetica-neue',
            'weight_regular' => '500',
            'motion_pace' => 'fast',
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
        // The lounge welcome: cream room, warm, unhurried, softly rounded,
        // warm imagery.
        self::HOSPITALITY => [
            'theme_mode' => 'warm',
            'color_accent' => '#7c2d12',
            'typography_font_family' => 'general-sans',
            'border_radius' => '0.25rem',
            'motion_pace' => 'slow',
            'effect_image_treatment' => 'warm',
        ],
        // Garage-signage confidence: charcoal workshop, heavy CAPS, chunky
        // borders, square, hard-offset shadows, stark contrast, fast. All
        // four automotive slugs are garage-flavoured, so the register sits
        // on the bucket.
        self::AUTOMOTIVE => [
            'theme_mode' => 'dusk',
            'color_accent' => '#c81e1e',
            'typography_font_family' => 'monument-grotesk',
            'weight_regular' => '600',
            'weight_heading' => '600',
            'typography_uppercase' => true,
            'border_thickness' => '2px',
            'theme_contrast' => 'stark',
            'effect_shadow_style' => 'hard',
            'motion_pace' => 'fast',
        ],
        // Gallery-like: expressive workhorse font, chrome-less plain links —
        // the work speaks.
        self::CREATIVE_ENTERTAINMENT => [
            'color_accent' => '#7c3aed',
            'typography_font_family' => 'forma-djr',
            'motion_pace' => 'fast',
            'effect_link_style' => 'plain',
        ],
        // Approachable and clear: proven UI font, bigger body (desktop
        // paired), airier line-height, soft rounding and shadows — the
        // encouraging modern-app read.
        self::EDUCATION_COACHING => [
            'color_accent' => '#2563eb',
            'typography_font_family' => 'inter',
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
        // ── Food & Drink ────────────────────────────────────────────────
        // Espresso-toned neighbourhood calm in a cream room, gently rounded.
        'cafe' => ['theme_mode' => 'warm', 'color_accent' => '#92400e', 'border_radius' => '0.25rem', 'motion_pace' => 'normal'],
        // Caramel warmth on cream, soft pastry rounding.
        'bakery' => ['theme_mode' => 'warm', 'color_accent' => '#b45309', 'border_radius' => '0.25rem', 'motion_pace' => 'normal'],
        // Moody late-night room: midnight ground, bright wine that reads on
        // dark, slow, muted imagery, tonal borders.
        'bar' => ['theme_mode' => 'midnight', 'color_accent' => '#be123c', 'motion_pace' => 'slow', 'effect_image_treatment' => 'muted', 'weight_regular' => '400', 'theme_contrast' => 'soft'],
        // Street-sign punch: CAPS, chunky rules, heavy headings.
        'food-truck' => ['weight_regular' => '500', 'weight_heading' => '600', 'effect_shadow_style' => 'hard', 'typography_uppercase' => true, 'border_thickness' => '2px'],
        // Plated restraint: unhurried, airy, wide-tracked, untreated photography.
        'personal-chef' => ['weight_regular' => '400', 'weight_heading' => '400', 'motion_pace' => 'slow', 'effect_image_treatment' => 'none', 'typography_tracking' => 'wide', 'space_regular' => '0.75rem', 'typography_line_height' => '1.3'],

        // ── Beauty & Personal Care ──────────────────────────────────────
        // Sharp + classic: charcoal shop, pole-red, square, flat, stark,
        // mono portfolio, sturdy grotesque.
        'barber' => ['theme_mode' => 'dusk', 'typography_font_family' => 'monument-grotesk', 'color_accent' => '#b91c1c', 'border_radius' => '0', 'effect_shadow_style' => 'flat', 'weight_regular' => '500', 'effect_image_treatment' => 'mono', 'theme_contrast' => 'stark'],
        // Calm-luxe: greige room, eucalyptus, light airy type, wide tracking,
        // slow, tonal.
        'spa' => ['theme_mode' => 'dust', 'color_accent' => '#0f766e', 'weight_regular' => '300', 'weight_heading' => '400', 'motion_pace' => 'slow', 'effect_image_treatment' => 'muted', 'typography_tracking' => 'wide', 'space_regular' => '0.75rem', 'typography_line_height' => '1.3', 'theme_contrast' => 'soft'],
        // Flash-sheet: red on midnight ink, square, hard shadows, chunky rules.
        'tattoo-artist' => ['theme_mode' => 'midnight', 'typography_font_family' => 'monument-grotesk', 'color_accent' => '#dc2626', 'border_radius' => '0', 'effect_shadow_style' => 'hard', 'effect_image_treatment' => 'mono', 'border_thickness' => '2px', 'theme_contrast' => 'stark'],
        // Editorial glam: Helvetica, square, flat — the look book, unfiltered.
        'makeup-artist' => ['typography_font_family' => 'helvetica-neue', 'border_radius' => '0', 'effect_shadow_style' => 'flat'],

        // ── Health & Fitness ────────────────────────────────────────────
        // Poster register for the gym floor: charcoal, emerald that pops on
        // dark, CAPS, tight tracking, heavy headings.
        'gym' => ['theme_mode' => 'dusk', 'color_accent' => '#10b981', 'typography_uppercase' => true, 'typography_tracking' => 'tight', 'weight_heading' => '600'],
        // Coach energy without full signage CAPS.
        'personal-trainer' => ['weight_heading' => '600'],
        // The opposite of gym energy: soft, slow, airy, approachable slate.
        'therapist' => ['typography_font_family' => 'inter', 'color_accent' => '#64748b', 'weight_regular' => '400', 'weight_heading' => '400', 'motion_pace' => 'slow', 'border_radius' => '0.25rem', 'effect_shadow_style' => 'soft', 'theme_contrast' => 'soft', 'typography_line_height' => '1.3'],
        // Grounded calm: greige room, sage, light type, breathing room.
        'yoga-instructor' => ['theme_mode' => 'dust', 'typography_font_family' => 'general-sans', 'color_accent' => '#5f7a61', 'weight_regular' => '300', 'motion_pace' => 'slow', 'border_radius' => '0.25rem', 'space_regular' => '0.75rem', 'typography_line_height' => '1.3', 'theme_contrast' => 'soft'],
        // Fresh + factual.
        'nutritionist' => ['typography_font_family' => 'inter', 'color_accent' => '#4d7c0f', 'weight_regular' => '400', 'motion_pace' => 'normal'],
        // Clinical trust: neutral geist, clinical teal, gentle rounding.
        'physiotherapist' => ['typography_font_family' => 'geist', 'color_accent' => '#0e7490', 'weight_regular' => '400', 'motion_pace' => 'normal', 'border_radius' => '0.25rem'],
        'chiropractor' => ['typography_font_family' => 'geist', 'color_accent' => '#0e7490', 'weight_regular' => '400', 'motion_pace' => 'normal', 'border_radius' => '0.25rem'],
        'dentist' => ['typography_font_family' => 'geist', 'color_accent' => '#0e7490', 'weight_regular' => '400', 'motion_pace' => 'normal', 'border_radius' => '0.25rem', 'effect_shadow_style' => 'soft'],

        // ── Professional Services ───────────────────────────────────────
        // Creative-pro breaks the suit: expressive font, violet, quick.
        'marketing-agency' => ['typography_font_family' => 'forma-djr', 'color_accent' => '#6d28d9', 'weight_regular' => '400', 'motion_pace' => 'fast', 'effect_link_style' => 'underline-hover'],
        // Premium property: modern-premium font, dark gold, light headings, soft depth.
        'real-estate-agent' => ['typography_font_family' => 'helvetica-now', 'color_accent' => '#a16207', 'weight_regular' => '400', 'weight_heading' => '400', 'effect_shadow_style' => 'soft', 'effect_link_style' => 'underline-hover'],

        // ── Retail & Shopping ───────────────────────────────────────────
        // Velvet-case luxe: charcoal ground, gold that reads on dark, light
        // wide-tracked type, generous space, slow reveal.
        'jewellery' => ['theme_mode' => 'dusk', 'color_accent' => '#ca8a04', 'typography_font_family' => 'helvetica-now', 'weight_regular' => '300', 'weight_heading' => '400', 'motion_pace' => 'slow', 'typography_tracking' => 'wide', 'space_regular' => '0.875rem', 'theme_contrast' => 'soft'],
        // Soft botanical.
        'florist' => ['typography_font_family' => 'general-sans', 'weight_regular' => '400', 'border_radius' => '0.25rem', 'effect_shadow_style' => 'soft', 'motion_pace' => 'normal'],
        // Handmade warmth: cream ground, terracotta, warm imagery, soft corners.
        'artisan-maker' => ['theme_mode' => 'warm', 'typography_font_family' => 'forma-djr', 'color_accent' => '#9a3412', 'weight_regular' => '400', 'border_radius' => '0.25rem', 'effect_image_treatment' => 'warm', 'motion_pace' => 'normal'],
        // Muted interior calm: greige room, stone accent, muted imagery, roomy.
        'homewares' => ['theme_mode' => 'dust', 'typography_font_family' => 'general-sans', 'color_accent' => '#57534e', 'weight_regular' => '400', 'effect_image_treatment' => 'muted', 'motion_pace' => 'normal', 'space_regular' => '0.75rem', 'theme_contrast' => 'soft'],

        // ── Home & Trade Services (trade-colour identity) ───────────────
        'plumber' => ['color_accent' => '#0369a1'],
        'electrician' => ['color_accent' => '#1d4ed8'],
        'landscaper' => ['color_accent' => '#3f6212'],
        'cleaner' => ['color_accent' => '#0891b2'],

        // ── Hospitality & Events ────────────────────────────────────────
        // Romantic editorial: rose-gold, light airy wide-tracked type, soft depth.
        'wedding-planner' => ['typography_font_family' => 'helvetica-now', 'color_accent' => '#b76e79', 'weight_regular' => '300', 'weight_heading' => '400', 'effect_shadow_style' => 'soft', 'typography_tracking' => 'wide', 'space_regular' => '0.75rem', 'typography_line_height' => '1.3'],
        // The bar-room read, slightly livelier than stays: charcoal over the
        // bucket's cream, dark-safe wine.
        'bartender' => ['theme_mode' => 'dusk', 'color_accent' => '#be123c', 'motion_pace' => 'normal'],

        // ── Automotive ──────────────────────────────────────────────────
        // Gloss-and-water blue over the garage base.
        'car-detailer' => ['color_accent' => '#0284c7'],

        // ── Creative & Entertainment ────────────────────────────────────
        // NEVER filter a photographer's work; borderless quiet gallery,
        // light editorial Helvetica, generous space, neutral slate.
        'photographer' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#475569', 'weight_regular' => '300', 'weight_heading' => '400', 'motion_pace' => 'normal', 'space_regular' => '0.875rem', 'theme_contrast' => 'soft', 'border_style' => 'none'],
        // Gig-poster: midnight stage, grotesque CAPS, tight tracking, hard
        // shadows, stark.
        'musician' => ['theme_mode' => 'midnight', 'typography_font_family' => 'monument-grotesk', 'color_accent' => '#e11d48', 'effect_shadow_style' => 'hard', 'typography_uppercase' => true, 'typography_tracking' => 'tight', 'weight_heading' => '600', 'theme_contrast' => 'stark'],
        // Cinematic dark for moving image.
        'videographer' => ['theme_mode' => 'midnight'],
        // Literary restraint: navy ink, readable leading, honest links.
        'writer' => ['color_accent' => '#1d3557', 'weight_regular' => '400', 'motion_pace' => 'normal', 'effect_link_style' => 'underline-always', 'typography_line_height' => '1.3'],
        // Vibrant + friendly, app-bubble round.
        'content-creator' => ['typography_font_family' => 'inter', 'color_accent' => '#db2777', 'border_radius' => '0.85rem'],

        // ── Education & Coaching ────────────────────────────────────────
        // Premium-warm over the app-clean base: cream room, premium face.
        'life-coach' => ['theme_mode' => 'warm', 'typography_font_family' => 'helvetica-now'],
        // Expressive movement.
        'dance-instructor' => ['color_accent' => '#db2777', 'motion_pace' => 'fast'],
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
