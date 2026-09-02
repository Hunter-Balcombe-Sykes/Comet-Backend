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
 * ─── 2026-08-27: FULL LOOKS (plan 02 step 4). ────────────────────────────────
 *
 * Every bucket is a complete authored look now — accent, font, text_size,
 * spacing, corners, typography_uppercase — transcribed from
 * partna-monorepo docs/overnight-run-2026-08-27/design-taste-map.md, which
 * is the source of truth these tables are checked against (the critic
 * verifies row-for-row). SPARSITY RULE: a value equal to the package
 * default is not written (the default IS the authored value); a slug
 * refinement that needs the default where its bucket departs re-emits it
 * explicitly. The package defaults these tables compose over:
 * helvetica-neue / medium / default spacing / default corners (0.35rem) /
 * uppercase TRUE (base.css's force line retired into the default,
 * 2026-08-27).
 *
 * Vocabulary notes:
 *   • corners gained 'sharp' (0px) tonight — the signage/gallery/technical
 *     edge nine looks below need.
 *   • typography_uppercase is a real column again (20260827090000);
 *     sentence-case is the single cheapest "quiet sector" signal.
 *   • nb-architekt is REACHABLE now (taste map §5): tattoo-artist and
 *     it-services wear it here, and FontKeywordClassifier's technical/mono
 *     register routes scanned-website evidence to it. It is an all-caps
 *     face — always authored WITH uppercase true (composition rule §1.1).
 *
 * History: 2026-08-09's preset-only migration collapsed these to
 * accent+font ("let the gutted sectors collapse"); the 2026-08-27 axes
 * restore full looks on the surviving 6-column schema. Every value still
 * targets a live design_kits column — see
 * tests/Unit/Design/FontRosterTest.php, which fails if a preset seeds a
 * column the schema no longer has.
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
        // The menu board: appetite-red caps on a sturdy grotesque, dense
        // enough to read a menu off.
        self::FOOD_DRINK => [
            'color_accent' => '#e0491f',
            'typography_font_family' => 'monument-grotesk',
        ],
        // The salon: polished, soft, a little luxe — air, cushioned
        // corners, sentence-case calm.
        self::BEAUTY_PERSONAL_CARE => [
            'color_accent' => '#b8375a',
            'spacing' => 'spacious',
            'typography_uppercase' => false,
        ],
        // The gym-floor poster: big emerald-dark energy in caps.
        self::HEALTH_FITNESS => [
            'color_accent' => '#2f6b57',
            'typography_font_family' => 'monument-grotesk',
            'text_size' => 'large',
        ],
        // The letterhead: navy, neutral face, sentence case — documents
        // do not shout.
        self::PROFESSIONAL_SERVICES => [
            'color_accent' => '#1d3557',
            'typography_uppercase' => false,
        ],
        // The lookbook: editorial fashion caps in classic Helvetica.
        self::RETAIL_SHOPPING => [
            'color_accent' => '#d6336c',
        ],
        // The van livery: warm, sturdy, legible — a person you would call,
        // painted on a door.
        self::HOME_SERVICES => [
            'color_accent' => '#d97706',
            'typography_font_family' => 'forma-djr',
        ],
        // The lounge: warm ink, air, a lowered voice — the welcome, not
        // the sign outside.
        self::HOSPITALITY => [
            'color_accent' => '#7c2d12',
            'typography_font_family' => 'monument-grotesk',
            'spacing' => 'spacious',
            'typography_uppercase' => false,
        ],
        // Garage signage: big red caps, machined edges.
        self::AUTOMOTIVE => [
            'color_accent' => '#c81e1e',
            'typography_font_family' => 'monument-grotesk',
            'text_size' => 'large',
            'corners' => 'sharp',
        ],
        // The portfolio poster: expressive face, jewel accent, name in
        // lights.
        self::CREATIVE_ENTERTAINMENT => [
            'color_accent' => '#7c3aed',
            'typography_font_family' => 'forma-djr',
        ],
        // The friendly classroom: clear blue, approachable corners,
        // sentence case.
        self::EDUCATION_COACHING => [
            'color_accent' => '#2563eb',
            'typography_uppercase' => false,
        ],
    ];

    /**
     * Slug refinements — sparse deltas merged OVER the slug's bucket base.
     * Only slugs that meaningfully depart from their bucket appear here.
     *
     * @var array<string, array<string, string|bool>>
     */
    private const SLUG_REFINEMENTS = [
        // ── food_drink (menu-board caps over monument) ──────────────────
        // Espresso-toned neighbourhood calm — the chalkboard is
        // handwritten, not stamped.
        'cafe' => ['color_accent' => '#92400e', 'typography_uppercase' => false],
        // Caramel warmth, pastry-soft edges.
        'bakery' => ['color_accent' => '#b45309', 'typography_uppercase' => false],
        // Late-night poster: the wine-red gets volume.
        'bar' => ['color_accent' => '#be123c', 'text_size' => 'large'],
        // Van-side type — read it from across the street.
        'food-truck' => ['text_size' => 'large'],
        // Private fine-dining: the quiet, plated register — the menu
        // board's opposite.
        'personal-chef' => ['typography_font_family' => 'helvetica-neue', 'spacing' => 'spacious', 'typography_uppercase' => false],

        // ── beauty_personal_care (salon-soft base) ──────────────────────
        // The barbershop is the salon's trad-masculine inverse: pole-red
        // caps, hard edges, no cushion — flips 4 of 6 bucket axes on
        // purpose (spacing/uppercase re-emit package defaults to undo the
        // bucket's departures).
        'barber' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#b91c1c', 'spacing' => 'default', 'corners' => 'sharp', 'typography_uppercase' => true],
        // Eucalyptus over the bucket's calm-luxe body.
        'spa' => ['color_accent' => '#0f766e'],
        // Flash-sheet red on the technical grotesk — the taste map's
        // canonical nb-architekt look (all-caps face ⇒ uppercase true).
        'tattoo-artist' => ['typography_font_family' => 'nb-architekt', 'color_accent' => '#dc2626', 'spacing' => 'default', 'corners' => 'sharp', 'typography_uppercase' => true],
        // Editorial glam speaks caps (the Vogue register) over the
        // bucket's soft body.
        'makeup-artist' => ['typography_uppercase' => true],

        // ── health_fitness (poster base) ────────────────────────────────
        // Brighter emerald on the bucket's poster.
        'gym' => ['color_accent' => '#10b981'],
        // The full quiet flip — a counselling room must not look like a
        // gym floor.
        'therapist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#64748b', 'text_size' => 'medium', 'spacing' => 'spacious', 'typography_uppercase' => false],
        // Grounded sage calm; keeps the grotesque so it stays studio, not
        // clinic.
        'yoga-instructor' => ['color_accent' => '#5f7a61', 'text_size' => 'medium', 'spacing' => 'spacious', 'typography_uppercase' => false],
        // Fresh + factual, sentence case.
        'nutritionist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#4d7c0f', 'text_size' => 'medium', 'typography_uppercase' => false],
        // Clinical trust reads sentence-case teal, not poster caps.
        'physiotherapist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#0e7490', 'text_size' => 'medium', 'typography_uppercase' => false],
        'chiropractor' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#0e7490', 'text_size' => 'medium', 'typography_uppercase' => false],
        'dentist' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#0e7490', 'text_size' => 'medium', 'typography_uppercase' => false],

        // ── professional_services (letterhead base) ─────────────────────
        // Creative-pro breaks the suit — expressive face, violet, caps.
        'marketing-agency' => ['typography_font_family' => 'forma-djr', 'color_accent' => '#6d28d9', 'typography_uppercase' => true],
        // Premium property: dark gold + listing-page air.
        'real-estate-agent' => ['color_accent' => '#a16207', 'spacing' => 'spacious'],
        // The spec-sheet look IS the industry signal — blueprint navy on
        // the technical grotesk (all-caps face ⇒ uppercase true).
        'it-services' => ['typography_font_family' => 'nb-architekt', 'corners' => 'sharp', 'typography_uppercase' => true],

        // ── retail_shopping (lookbook base) ─────────────────────────────
        // Velvet-case luxe: gold, widely-set caps.
        'jewellery' => ['color_accent' => '#ca8a04', 'spacing' => 'spacious'],
        // Soft botanical — air and petals, not editorial pink caps.
        'florist' => ['typography_font_family' => 'monument-grotesk', 'spacing' => 'spacious', 'typography_uppercase' => false],
        // Friendly counter, not runway.
        'gift-shop' => ['typography_uppercase' => false],
        // Muted interior calm — the stone showroom.
        'homewares' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#57534e', 'spacing' => 'spacious', 'typography_uppercase' => false],
        // Handmade warmth in the crafted face.
        'artisan-maker' => ['typography_font_family' => 'forma-djr', 'color_accent' => '#9a3412', 'typography_uppercase' => false],

        // ── home_services (van-livery base — accents only, the bucket IS
        //    the trade look) ───────────────────────────────────────────
        'plumber' => ['color_accent' => '#0369a1'],
        'electrician' => ['color_accent' => '#1d4ed8'],
        'landscaper' => ['color_accent' => '#3f6212'],
        'cleaner' => ['color_accent' => '#0891b2'],

        // ── hospitality (lounge base) ───────────────────────────────────
        // Romance: rose-gold, soft edges, over the bucket's air.
        'wedding-planner' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#b76e79'],
        // The mobile bar borrows the bar's poster energy, not the hotel's
        // murmur (spacing re-emits the default to undo the bucket's air).
        'bartender' => ['color_accent' => '#be123c', 'spacing' => 'default', 'typography_uppercase' => true],

        // ── automotive (garage base — the bucket IS the signage) ────────
        // Gloss-and-water blue over the garage base.
        'car-detailer' => ['color_accent' => '#0284c7'],

        // ── creative_entertainment (portfolio base) ─────────────────────
        // The quiet gallery: slate, small editorial type, hard mats, air —
        // the work is the site.
        'photographer' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#475569', 'text_size' => 'small', 'spacing' => 'spacious', 'corners' => 'sharp', 'typography_uppercase' => false],
        // Letterboxed restraint.
        'videographer' => ['corners' => 'sharp', 'typography_uppercase' => false],
        // Swiss-poster modernism — the designer's own site is the
        // credential.
        'graphic-designer' => ['typography_font_family' => 'helvetica-neue', 'corners' => 'sharp', 'typography_uppercase' => false],
        // Gallery hang: frames and wall-space.
        'artist' => ['spacing' => 'spacious', 'corners' => 'sharp', 'typography_uppercase' => false],
        // The gig poster, full volume.
        'musician' => ['typography_font_family' => 'monument-grotesk', 'color_accent' => '#e11d48', 'text_size' => 'large', 'corners' => 'sharp'],
        // Vibrant + friendly, feed-native.
        'content-creator' => ['typography_font_family' => 'helvetica-neue', 'color_accent' => '#db2777'],
        // Literary restraint — ink-navy sentences in the crafted text cut.
        'writer' => ['color_accent' => '#1d3557', 'text_size' => 'small', 'typography_uppercase' => false],

        // ── education_coaching (classroom base) ─────────────────────────
        // Editorial polish over the classroom.
        'life-coach' => ['spacing' => 'spacious'],
        // Movement poster over the friendly base.
        'dance-instructor' => ['color_accent' => '#db2777', 'text_size' => 'large', 'typography_uppercase' => true],
    ];

    /**
     * REGISTER (owner, 2026-09-02, batch 2 D.1): every sector reads either
     * masculine or feminine by default, and the register — not the sector —
     * decides corners, face and the fallback accent (the accent a site wears
     * when nothing was scraped; a scraped or manual accent still wins).
     *   masculine → square corners, the NB Architekt all-caps face, a neon.
     *   feminine  → curved corners, any other face, a tonal pastel — pink for
     *               the beauty/retail/hospitality buckets, baby blue elsewhere.
     * A slug in either list overrides its bucket's reading.
     */
    public const MASCULINE = 'masculine';

    public const FEMININE = 'feminine';

    public const MASCULINE_ACCENT = '#00d856';

    public const FEMININE_ACCENT_PINK = '#f7c5d8';

    public const FEMININE_ACCENT_BLUE = '#bfe1f6';

    /** @var list<string> */
    private const MASCULINE_BUCKETS = [self::AUTOMOTIVE, self::HOME_SERVICES, self::HEALTH_FITNESS];

    /** @var list<string> */
    private const PINK_BUCKETS = [self::BEAUTY_PERSONAL_CARE, self::RETAIL_SHOPPING, self::HOSPITALITY];

    /** @var list<string> */
    private const MASCULINE_SLUGS = ['barber', 'tattoo-artist', 'it-services', 'videographer', 'musician', 'gym', 'bartender', 'plumber', 'electrician', 'landscaper', 'car-detailer', 'graphic-designer'];

    /** @var list<string> */
    private const FEMININE_SLUGS = ['yoga-instructor', 'therapist', 'nutritionist', 'dance-instructor', 'physiotherapist', 'cleaner'];

    public static function registerFor(?string $bucket, string $slug): string
    {
        if (in_array($slug, self::MASCULINE_SLUGS, true)) {
            return self::MASCULINE;
        }
        if (in_array($slug, self::FEMININE_SLUGS, true)) {
            return self::FEMININE;
        }

        return in_array($bucket, self::MASCULINE_BUCKETS, true) ? self::MASCULINE : self::FEMININE;
    }

    /**
     * The register's axes over a composed look.
     *
     * @param  array<string, string|bool>  $look
     * @return array<string, string|bool>
     */
    public static function applyRegister(array $look, ?string $bucket, string $register): array
    {
        if ($register === self::MASCULINE) {
            return array_merge($look, [
                'corners' => 'sharp',
                'typography_font_family' => 'nb-architekt',
                'typography_uppercase' => true,
                'color_accent' => self::MASCULINE_ACCENT,
            ]);
        }

        $feminine = [
            'corners' => 'default',
            'color_accent' => in_array($bucket, self::PINK_BUCKETS, true) ? self::FEMININE_ACCENT_PINK : self::FEMININE_ACCENT_BLUE,
        ];
        if (($look['typography_font_family'] ?? null) === 'nb-architekt') {
            $feminine['typography_font_family'] = 'helvetica-neue';
            $feminine['typography_uppercase'] = false;
        }

        return array_merge($look, $feminine);
    }

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
