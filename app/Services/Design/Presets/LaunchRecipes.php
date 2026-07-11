<?php

namespace App\Services\Design\Presets;

/**
 * The curated LAUNCH RECIPE table — the ~6 recognizable identity ARCHETYPES that,
 * when strongly evidenced by a COMBINATION of independent signals, deserve a
 * complete hand-tuned coherent look stamped in one shot (spec §1). This is the
 * answer to "individual factors each nudge one axis and can stack into an
 * incoherent combination": a recipe replaces the piecemeal nudges with one
 * internally-coherent set at band 70.
 *
 * Each recipe is `{ key, label, trigger, minGroups, values }`:
 *   - key       — stable identifier (→ never surfaced raw; DesignRationaleService
 *                 maps it to a friendly label).
 *   - label     — the human archetype name (for the rationale line + logging).
 *   - trigger   — a predicate over RecipeSignals; returns the count of INDEPENDENT
 *                 signal groups that concur for this recipe (0 = does not apply).
 *   - minGroups — independent concordant groups required to fire (default 2).
 *   - values    — a COMPLETE, coherent [design_kit column => value] set. Every
 *                 value is drawn from the established vocabulary (StyleTiers::TIERS
 *                 literals + AestheticExpression DIRECTION_TARGETS forms + the
 *                 effect_* identity-axis enums incl. effect_surface). Fonts are
 *                 roster slugs.
 *
 * Backgrounds are theme_mode-owned (2026-07-10 rework) and entrance animation is
 * removed, so a recipe's identity rides accent/weight/surface/shadow/imagery —
 * e.g. fine-dining reads "dark luxury" through gold + light weight + outline +
 * duotone on ANY user-picked theme, rather than forcing a dark ground.
 *
 * COHERENCE CONTRACT: the golden-profiles sweep (FactorSweepTest) asserts every
 * recipe's value set is internally coherent — no "glass surface + hard shadow +
 * heavy weight" contradictions. When editing a recipe, keep the surface/radius/
 * weight/motion axis pulling the SAME direction (soft⇢glass/rounded-or-pill/
 * light/slow, bold⇢solid/square/heavy/fast, editorial⇢outline/flat/square/
 * light/slow). Recipes MAY use the Square (0) corner stop — a 2+-signal
 * archetype has earned the extreme — while broad category buckets stay on the
 * tempered stops (automotive excepted).
 */
final class LaunchRecipes
{
    /**
     * The recipe definitions. Ordered by specificity (most specific archetype
     * first) only for readability — selection is purely by score, ties abstain.
     *
     * @return array<int, array{key: string, label: string, minGroups: int, trigger: callable(RecipeSignals): int, values: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            // 1. beauty-soft — beauty/wellness/bridal AND a soft or premium read.
            [
                'key' => 'beauty-soft',
                'label' => 'Soft Beauty',
                'minGroups' => 2,
                'trigger' => static function (RecipeSignals $s): int {
                    $groups = $s->familyConcordance()[RecipeSignals::FAM_BEAUTY] ?? 0;
                    if ($groups < 1) {
                        return 0;
                    }
                    // A second INDEPENDENT confirmation: soft aesthetic (IG group)
                    // OR premium price (store group). Both are distinct from the
                    // category groups that established the beauty family EXCEPT the
                    // IG one — so only count aesthetic when the beauty family did
                    // NOT itself come solely from Instagram.
                    $confirmations = 0;
                    if ($s->aestheticLean() === AestheticLexicon::SOFT
                        && ($s->familiesByGroup()[RecipeSignals::GROUP_INSTAGRAM] ?? null) !== RecipeSignals::FAM_BEAUTY) {
                        $confirmations++;
                    }
                    if ($s->pricePoint() === RecipeSignals::PRICE_PREMIUM) {
                        $confirmations++;
                    }

                    return $groups + $confirmations;
                },
                'values' => [
                    'color_accent' => '#b8375a',        // muted rose (beauty bucket)
                    'border_radius' => '1.5rem',        // very rounded
                    'weight_regular' => '300',          // light
                    'typography_font_family' => 'melodrama', // the roster's beauty-premium display serif (matches the beauty bucket)
                    'space_regular' => '1.15rem',       // generous/airy
                    'motion_pace' => 'slow',
                    'effect_surface' => 'glass',        // soft translucency carries the luxe read
                    'effect_shadow_style' => 'soft',
                    'effect_link_style' => 'underline-hover',
                    'effect_image_treatment' => 'warm',
                ],
            ],

            // 2. fitness-bold — fitness/sport AND a bold or video-heavy read.
            [
                'key' => 'fitness-bold',
                'label' => 'Bold Fitness',
                'minGroups' => 2,
                'trigger' => static function (RecipeSignals $s): int {
                    $groups = $s->familyConcordance()[RecipeSignals::FAM_FITNESS] ?? 0;
                    if ($groups < 1) {
                        return 0;
                    }
                    $confirmations = 0;
                    if ($s->aestheticLean() === AestheticLexicon::BOLD
                        && ($s->familiesByGroup()[RecipeSignals::GROUP_INSTAGRAM] ?? null) !== RecipeSignals::FAM_FITNESS) {
                        $confirmations++;
                    }

                    return $groups + $confirmations;
                },
                'values' => [
                    'color_accent' => '#c81e1e',        // hard red
                    'border_radius' => '0',             // square — full poster-brutalist shape (2-signal archetype earns the extreme stop)
                    'weight_regular' => '600',          // chunky
                    'typography_font_family' => 'oswald', // condensed grotesque
                    'space_regular' => '0.8rem',        // compact/energetic
                    'motion_pace' => 'fast',
                    'effect_surface' => 'solid',        // filled poster blocks
                    'effect_shadow_style' => 'hard',
                    'effect_link_style' => 'plain',
                    'effect_image_treatment' => 'mono',
                ],
            ],

            // 3. hospitality-warm — food business AND NOT fine-dining.
            [
                'key' => 'hospitality-warm',
                'label' => 'Warm Hospitality',
                'minGroups' => 2,
                'trigger' => static function (RecipeSignals $s): int {
                    // Fine dining has its own recipe — this is the warm, friendly
                    // everyday-hospitality look; abstain if the cuisine reads fine.
                    if (CuisineLexicon::isFineDining($s->cuisineHint())) {
                        return 0;
                    }
                    $groups = $s->familyConcordance()[RecipeSignals::FAM_HOSPITALITY] ?? 0;
                    if ($groups < 1) {
                        return 0;
                    }

                    // A cafe cuisine hint (from the Google group already counted for
                    // the hospitality family) doesn't add an independent group; a
                    // second CATEGORY source (IG + Google both hospitality) does.
                    return $groups;
                },
                'values' => [
                    'color_accent' => '#7c2d12',        // warm brown (hospitality bucket)
                    'border_radius' => '0.85rem',       // rounded — sharp corners contradicted the glass/soft/warm read (soft direction ⇢ rounded)
                    'weight_regular' => '400',          // regular
                    'typography_font_family' => 'young-serif', // artisanal warmth — cafés are its home turf
                    'space_regular' => '0.95rem',       // regular
                    'motion_pace' => 'normal',
                    'effect_surface' => 'glass',        // soft, welcoming panels
                    'effect_shadow_style' => 'soft',
                    'effect_link_style' => 'underline-hover',
                    'effect_image_treatment' => 'warm',
                ],
            ],

            // 4. fine-dining-editorial — fine-dining cuisine OR (hospitality AND premium).
            [
                'key' => 'fine-dining-editorial',
                'label' => 'Fine Dining',
                'minGroups' => 2,
                'trigger' => static function (RecipeSignals $s): int {
                    $fineDining = CuisineLexicon::isFineDining($s->cuisineHint());
                    $hospitalityGroups = $s->familyConcordance()[RecipeSignals::FAM_HOSPITALITY] ?? 0;
                    $premium = $s->pricePoint() === RecipeSignals::PRICE_PREMIUM;

                    // Path A: an explicit fine-dining cuisine (Google group) + any
                    // other hospitality confirmation or premium price.
                    if ($fineDining) {
                        // cuisine derives from the Google category → same group as a
                        // Google hospitality family. Count the cuisine as the first
                        // group; a premium store read OR a second category source is
                        // the independent second.
                        $second = ($premium ? 1 : 0)
                            + ((($s->familiesByGroup()[RecipeSignals::GROUP_INSTAGRAM] ?? null) === RecipeSignals::FAM_HOSPITALITY) ? 1 : 0);

                        return 1 + $second;
                    }

                    // Path B: a hospitality business at a premium price point (two
                    // independent groups: category + store).
                    if ($hospitalityGroups >= 1 && $premium) {
                        return $hospitalityGroups + 1;
                    }

                    return 0;
                },
                // Dark-luxury identity without a bg column: gold + light weight +
                // outline + flat + duotone read restrained on any theme mode (and
                // become the full old look if the user picks midnight).
                'values' => [
                    'color_accent' => '#c9a24b',        // restrained gold
                    'border_radius' => '0',             // square — luxury print is square; completes the editorial outline/flat register
                    'weight_regular' => '300',          // light
                    'typography_font_family' => 'young-serif', // elegant serif
                    'space_regular' => '1.15rem',       // generous
                    'motion_pace' => 'slow',
                    'effect_surface' => 'outline',      // hairline editorial restraint
                    'effect_shadow_style' => 'flat',
                    'effect_link_style' => 'underline-always',
                    'effect_image_treatment' => 'duotone',
                ],
            ],

            // 5. creative-editorial — creative sector AND a portfolio-ish platform lean.
            [
                'key' => 'creative-editorial',
                'label' => 'Creative Portfolio',
                'minGroups' => 2,
                'trigger' => static function (RecipeSignals $s): int {
                    $groups = $s->familyConcordance()[RecipeSignals::FAM_CREATIVE] ?? 0;
                    if ($groups < 1) {
                        return 0;
                    }

                    // A second category source confirms; two creative category
                    // groups (e.g. sector + Google) already clears the bar.
                    return $groups;
                },
                'values' => [
                    'color_accent' => '#7c3aed',        // distinctive violet (creative bucket)
                    'border_radius' => '0',             // square — gallery/editorial media frames are square by convention
                    'weight_regular' => '500',          // medium
                    'typography_font_family' => 'geist', // gallery-neutral grotesque — the chrome recedes, the work speaks
                    'space_regular' => '0.95rem',       // regular
                    'motion_pace' => 'normal',
                    'effect_surface' => 'outline',      // gallery frames
                    'effect_shadow_style' => 'flat',
                    // NB: use 'underline-always' — 'underline-grow' is in the spec's
                    // vocabulary list but the renderer + design-kit validator only
                    // support underline-hover|underline-always|plain today, so
                    // 'underline-grow' would silently fall back to hover. Keep this
                    // to a value the sitepage can actually render (matches the
                    // editorial link treatment fine-dining uses).
                    'effect_link_style' => 'underline-always',
                    // A portfolio's imagery IS the product — never filter it. (Was
                    // duotone pre-2026-07-10, which misrepresented photographers'
                    // actual work; duotone stays a fine-dining/jazz MOOD treatment.)
                    'effect_image_treatment' => 'none',
                ],
            ],

            // 6. maker-craft — active shop + accessible price + maker/craft family.
            [
                'key' => 'maker-craft',
                'label' => 'Maker & Craft',
                'minGroups' => 2,
                'trigger' => static function (RecipeSignals $s): int {
                    $makerGroups = $s->familyConcordance()[RecipeSignals::FAM_MAKER] ?? 0;
                    if ($makerGroups < 1 || ! $s->hasActiveShop()) {
                        return 0;
                    }
                    // The store only counts as an INDEPENDENT second group when it
                    // carries a REAL price read (≥4 products → accessible or mid).
                    // A null price = too few products = no price evidence: a bare
                    // shop connection is not a second signal, so abstain (spec §1b
                    // requires an actual accessible price, not just a connected
                    // shop). A premium price contradicts the accessible-maker
                    // archetype outright.
                    $price = $s->pricePoint();
                    if ($price !== RecipeSignals::PRICE_ACCESSIBLE && $price !== RecipeSignals::PRICE_MID) {
                        return 0;
                    }

                    return $makerGroups + 1; // +1 for the independent, price-backed store group
                },
                'values' => [
                    'color_accent' => '#a15c2b',        // terracotta/craft
                    'border_radius' => '0.85rem',       // rounded
                    'weight_regular' => '400',          // regular
                    'typography_font_family' => 'sentient', // sturdy serif (origin retired 2026-07-12)
                    'space_regular' => '0.95rem',       // regular
                    'motion_pace' => 'normal',
                    // Solid, not glass: handmade goods read tactile on filled cards;
                    // glass is a digital-luxe texture that fights the earthy register.
                    'effect_surface' => 'solid',
                    'effect_shadow_style' => 'soft',
                    'effect_link_style' => 'underline-hover',
                    'effect_image_treatment' => 'warm',
                ],
            ],
        ];
    }
}
