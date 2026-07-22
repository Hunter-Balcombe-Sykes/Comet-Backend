# Profile Design Presets (factor-machine removal) Implementation Plan

> **Execution mode (owner-locked): INLINE ONLY.** Execute in-chat with superpowers:executing-plans, task-by-task — do NOT use subagent-driven development or dispatch subagents for implementation. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 13-factor integration-scanning design preset engine with a two-tier read-time mapping from the user's own `sector` field (bucket base + per-slug refinement) to design-kit values, and delete the entire stored-contribution machine.

**Architecture:** A pure static service (`ProfileDesignPresets::forUser(User): array`) maps user fields → sparse `[design_kit column => value]` overlays at read time: the sector's bucket sets the industry base, a per-slug refinement layer sharpens it (a spa and a barber share a bucket but not a look). Consumers (public payload builder, email brand resolver, rationale service) call it directly and overlay the manual `site.design_kits` row on top — manual always wins per column. No jobs, no stored contributions, no priority merge, no `site.design_kit_contributions` table, no integration scanning.

**Tech Stack:** Laravel 12 / PHP 8.2, Pest 4, Supabase raw-SQL migrations (`supabase db push`). Repo rules apply: no Laravel migration files, feature-branch off `development`, `composer test` + `vendor/bin/pint --dirty` before every commit.

---

## Ground rules (owner-clarified 2026-07-22 — bind every task)

- **Fields, not sources.** Presets read STORED user/profile fields only (`core.users.sector` in v1). Where a value came from is irrelevant — manual pick or IdentitySync fold from Google both count. What's forbidden is reading `platform_connections` payloads, calling platform APIs, or triggering/scheduling any scan from the design path.
- **The design-kit var system is untouched.** No `site.design_kits` columns added/removed, no monorepo package change, no defaults/vars/types edits, no editor change. This work replaces only the layer that AUTO-SETS kit values for the user. The single DB change is dropping `site.design_kit_contributions` (factor bookkeeping, not a kit table).
- **Integrations + scanning stay 100% intact.** Connection lifecycle, post/media scraping, IdentitySync, previous-website scans — all untouched. The ONLY thing removed from those paths is their design hook (`resolveDesignPresets()` dispatch). `SectorTaxonomy::fromGoogleCategory()/fromInstagramCategory()` (used by IdentitySync to fold categories INTO the sector field) keep working — Task 1 re-homes their classifier before the old home dies.

## Locked decisions (approved at plan review)

1. **Read-time compute, table dropped.** The contribution table only existed so 13 async factors could be priority-merged and frozen. One deterministic field mapping needs none of that.
2. **Sector styles regardless of `sector_source`** (`manual` OR `google`) — direct consequence of "fields, not sources" above. The old manual-only gate existed to avoid double-counting with `GoogleBusinessTypeFactor`, which dies here. Pre-account GBP-seeded builds keep a styled page.
3. **KEEP `DesignKitAccentApplier`** (`app/Services/WebsiteScan/`) — a separate fill-once-if-empty accent write from the previous-website scraper, NOT part of the factor machine. Rip later if wanted.
4. **KEEP media palette extraction** (`ImageVariantService` palette jsonb writes + `MediaPaletteExtractionTest`). Its only design consumer (`ImageryPaletteFactor`) dies, but the metadata is cheap, computed at image-processing time (not a scan job), and future-useful. Candidate follow-up deletion.
5. **The industry→values mapping is REWORKED, not carried verbatim** (owner call 2026-07-22 — "that's key"). Two tiers, both sparse deltas over the ALD-calibrated package defaults: a 10-bucket BASE (accents/fonts kept from the 2026-07-15 tuning; rows equal to package defaults dropped; the now-unclaimed identity axes — shadow, image treatment, link style — assigned where they genuinely differentiate) + 37 per-SLUG refinements recovering the nuance the dead refiner factors provided (spa ≠ barber, bar ≠ cafe, therapist ≠ gym). `SectorStylePresets` is the ONE file where all of this gets tuned forever after.
6. **`theme_mode` IS presettable** (owner override 2026-07-22, superseding the 2026-07-10 "user-only" lock): the palette is the site's colour identity, so the industry table assigns it where an industry has a clear room-tone (warm café, midnight tattoo studio). The user's own theme-mode pick is a manual `design_kits` column and still beats the preset per the universal manual-wins rule. `theme_night_shift_auto` alone stays user-only (functional toggle).

## The aesthetic model (what Task 1 encodes)

Baseline = the package defaults (ALD-calibrated: near-mono, square, 12px body, weight 400/500, flat, pace normal, accent `#0066ff`, font `geist`). An overlay only sets a column where the industry meaningfully departs. Accents stay mid-range — near-ink accents vanish on the dark theme modes, near-white ones on light. Fonts only from the live 7-font roster: `geist`, `inter`, `general-sans`, `forma-djr`, `monument-grotesk`, `helvetica-now`, `helvetica-neue`.

**Axes the table speaks through — ALL existing `site.design_kits` columns, nothing new anywhere:** **theme mode** (`bleach|dust|warm|dusk|midnight` — the palette identity across the whole site; owner override 2026-07-22 unlocked it for presets), accent, contrast register (`theme_contrast` `soft|normal|stark`), font, body size (+ its desktop companion whenever mobile body is bumped — pairing them fixes the old table's latent desktop-smaller-than-mobile bug), weight (`weight_regular` + `weight_heading`), line-height, letter case (`typography_uppercase`), letter spacing (`typography_tracking` `tight|normal|wide`), spacing base (`space_regular` — no desktop pair needed: the desktop spacing column has no stored default, so the base cascades), corners (`border_radius`), border thickness + style (`solid|double|none`), motion pace `slow|normal|fast`, shadow `flat|soft|hard`, link `underline-hover|underline-always|plain`, image `none|mono|duotone|warm|muted`.

**Palette assignments** (bleach is the default — omitted): *warm* (cream) for café, bakery, the whole Hospitality bucket, artisan-maker, life-coach · *dust* (greige) for spa, yoga, homewares · *dusk* (charcoal) for barber, gym, jewellery, the whole Automotive bucket, bartender · *midnight* (ALD night) for bar, tattoo artist, musician, videographer. Every slug landing on a dark palette gets its accent re-tuned to survive a dark ground (bar/bartender wine → `#be123c`, barber → pole-red `#b91c1c`, gym → emerald `#10b981`, jewellery → gold `#ca8a04`).

`typography_uppercase` is a BOOLEAN column — the old contribution rows stored TEXT so factors could never set it; the read-time PHP overlay has no such limit, so overlay values are `string|bool` and letter case is now presettable.

**Deliberate exclusions (the only kit values the table does NOT set, each for a stated reason):** `theme_night_shift_auto` (functional day/night behaviour toggle, not aesthetic identity — stays user-only), `typography_weight` register (shifts the whole weight system ±100 ON TOP of `weight_regular`/`weight_heading` — setting both would compound; the table owns the numerics instead), `layout_density` (multiplies the same spacing sites `space_regular` already tunes — one spacing lever, no double-dipping), `typography_logo_height` (depends on the user's actual logo file, not their industry), and the deep text ramp `text_caption/h3/h2/h1/display` (the hand-tuned ALD hierarchy; body + its desktop pair are the industry knobs — the ramp steps sit in the allowlist so a future table row CAN tune them, but none does today). A refinement may re-emit a default value to UNDO a bucket-base departure (e.g. makeup artist resets beauty's rounding to square) — harmless, the emit pipeline treats defaults as no-ops or identical values.

## What dies (inventory)

| Kind | Paths |
|---|---|
| Services | `app/Services/Design/Presets/` — entire dir (DesignFactor, SiteDesignFactor, EvidenceFactor, FactorMode, DesignFactorRegistry, DesignPresetResolver, IdentityEvidence, IntegrationConnectionFactorAdapter, RecipeSignals, LaunchRecipes, AestheticLexicon, CuisineLexicon, CategoryStylePresets, PresetTargetableColumns, `Factors/` ×13). `SectorTaxonomy`'s bucket-constant + `classify()` dependencies on CategoryStylePresets are re-homed in Task 1 BEFORE the deletion. |
| Job | `app/Jobs/Design/ResolveDesignPresetsJob.php` (dir becomes empty — remove dir) |
| Commands | `app/Console/Commands/ResolveAllDesignPresetsCommand.php`, `app/Console/Commands/SweepStaleDesignKitContributionsCommand.php` (neither is scheduled) |
| Model | `app/Models/Core/Site/DesignKitContribution.php` |
| DB | `site.design_kit_contributions` (drop migration, applied post-deploy) |
| Tests | `tests/Feature/Design/{DesignPresetResolverDefensiveTest,DesignPresetSystemTest,EvidenceFactorPrecedenceTest,FactorSweepTest,IdentityFactorsTest,ResolveAllDesignPresetsCommandTest}.php`, `tests/Feature/Console/SweepStaleDesignKitContributionsCommandTest.php`, `tests/Unit/Design/Presets/` — entire dir |
| Edits | `AppServiceProvider` (registry singleton + ~15 use lines), `PolicyCoverageTest` (exemption entry), `tests/Pest.php` (`setupDesignKitContributionsTable`), `IntegrationConnectionCacheRefresher` (`resolveDesignPresets()`), `SectorController` (job dispatch → site touch) |

## What survives untouched

`SectorTaxonomy` (slug list, labels, food predicate, Google/Instagram category folding — only its style-bucket import re-homes), `DesignKitAccentApplier` + `ScanPreviousWebsiteContentJob`, `ImageVariantService` palette writes, the manual `site.design_kits` write path (`PATCH /site`), the whole monorepo/frontend design-kit var system, `design_rationale` wire shape (`{summary, hasOverrides, items[{area,sourceLabel,reason}]}`) — frontend needs zero changes.

---

### Task 0: Sync and branch

The local `development` is synced; the feature branch was parked at the dev tip during plan review.

- [ ] **Step 1: Sync and branch** (reuse the parked branch)

```bash
cd ~/Developer/Comet-Backend
git checkout development && git pull
git checkout feature/profile-design-presets 2>/dev/null || git checkout -b feature/profile-design-presets
git merge --ff-only development   # no-op unless development moved since the branch was parked
```

- [ ] **Step 2: Confirm the factor system is unchanged upstream**

Run: `ls app/Services/Design/Presets/Factors/ | wc -l`
Expected: `13`. If files moved/renamed upstream, STOP and report — don't guess.

---

### Task 1: SectorStylePresets (two-tier table) + re-home the taxonomy coupling

**Files:**
- Create: `app/Services/Design/SectorStylePresets.php`
- Modify: `app/Services/Profile/SectorTaxonomy.php` (import swap + private `classify()`)
- Test: `tests/Unit/Design/SectorStylePresetsTest.php`
- Test: `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

- [ ] **Step 1: Write the failing table test**

```php
<?php

/**
 * Pure unit tests for SectorStylePresets — the two-tier tunable table mapping
 * a SectorTaxonomy bucket (base) and slug (refinement) to sparse design_kits
 * overlays. No DB, no container.
 */

use App\Services\Design\SectorStylePresets;
use App\Services\Profile\SectorTaxonomy;

it('returns a non-empty overlay for every declared bucket', function () {
    foreach (SectorStylePresets::buckets() as $bucket) {
        expect(SectorStylePresets::forBucket($bucket))->not->toBe([]);
    }
});

it('returns [] for an unknown bucket and an unrefined slug', function () {
    expect(SectorStylePresets::forBucket('astral_projection'))->toBe([])
        ->and(SectorStylePresets::forSlug('restaurant'))->toBe([])
        ->and(SectorStylePresets::forSlug('not-a-slug'))->toBe([]);
});

it('only ever sets snake_case design_kits column keys with string or bool values, in both tiers', function () {
    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }
    foreach ($overlays as $overlay) {
        foreach ($overlay as $column => $value) {
            expect($column)->toMatch('/^[a-z][a-z0-9_]+$/')
                ->and(is_string($value) || is_bool($value))->toBeTrue("non string/bool value for {$column}");
        }
    }
});

it('pairs text_desktop_body with every text_body bump so desktop never renders smaller than mobile', function () {
    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }
    foreach ($overlays as $overlay) {
        if (isset($overlay['text_body'])) {
            expect($overlay)->toHaveKey('text_desktop_body');
        }
    }
});

it('every refined slug is a real taxonomy slug with a real bucket', function () {
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("unknown slug: {$slug}")
            ->and(SectorTaxonomy::bucketFor($slug))->not->toBeNull();
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Design/SectorStylePresetsTest.php`
Expected: FAIL — `Class "App\Services\Design\SectorStylePresets" not found`

- [ ] **Step 3: Create the class** — the full two-tier table:

```php
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
```

- [ ] **Step 4: Run the table test**

Run: `vendor/bin/pest tests/Unit/Design/SectorStylePresetsTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Re-home SectorTaxonomy's coupling** (it currently imports `CategoryStylePresets` for bucket constants AND `classify()` — both must move before Task 7 deletes the old home; IdentitySync keeps calling `fromGoogleCategory()/fromInstagramCategory()`).

In `app/Services/Profile/SectorTaxonomy.php`:
1. Swap the import: `use App\Services\Design\Presets\CategoryStylePresets;` → `use App\Services\Design\SectorStylePresets;`
2. Replace every `CategoryStylePresets::<BUCKET_CONST>` in the `SECTORS` rows with `SectorStylePresets::<BUCKET_CONST>` (same constant names — `sed`-style find/replace of `CategoryStylePresets::` → `SectorStylePresets::` covers the whole file EXCEPT the two `classify()` calls, handled next).
3. Replace the two classifier calls (`fromGoogleCategory` line ~293, `fromInstagramCategory` line ~310): `CategoryStylePresets::classify($category, self::KEYWORD_SECTORS)` → `self::classify($category, self::KEYWORD_SECTORS)`.
4. Add the classifier as a private method (verbatim move of `CategoryStylePresets::classify()`):

```php
    /**
     * Classify a raw category string against an ORDERED keyword => slug map.
     * First substring match wins (case-insensitive) — KEYWORD_SECTORS must
     * order colliding keywords specific-before-generic ('barber' before
     * 'bar', so "Barber shop" doesn't fall through to the bar keyword).
     *
     * @param  array<string, string>  $orderedKeywordToSlug
     */
    private static function classify(string $raw, array $orderedKeywordToSlug): ?string
    {
        $lower = strtolower(trim($raw));
        if ($lower === '' || $lower === 'none') {
            return null;
        }

        foreach ($orderedKeywordToSlug as $keyword => $slug) {
            if (str_contains($lower, $keyword)) {
                return $slug;
            }
        }

        return null;
    }
```

- [ ] **Step 6: Pin the classifier ordering with a test** — create `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`:

```php
<?php

/**
 * Pins SectorTaxonomy's category classifier (re-homed from the deleted
 * CategoryStylePresets) — the specific-before-generic ordering contract in
 * KEYWORD_SECTORS, exercised through the public folding entrypoints
 * IdentitySync uses.
 */

use App\Services\Profile\SectorTaxonomy;

it('classifies "Barber shop" as barber, not bar', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Barber shop'))->toBe('barber')
        ->and(SectorTaxonomy::fromInstagramCategory('Barber Shop'))->toBe('barber');
});

it('still classifies a plain "Cocktail bar" as bar', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Cocktail bar'))->toBe('bar');
});

it('returns null for empty and unmatched categories', function () {
    expect(SectorTaxonomy::fromGoogleCategory(''))->toBeNull()
        ->and(SectorTaxonomy::fromGoogleCategory('Locksmith'))->toBeNull();
});
```

- [ ] **Step 7: Run the classification + IdentitySync tests**

Run: `vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php tests/Feature/Platforms/IdentitySyncTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Design/SectorStylePresets.php app/Services/Profile/SectorTaxonomy.php tests/Unit/Design/SectorStylePresetsTest.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php
git commit -m "feat(design): SectorStylePresets — two-tier industry overlay table; re-home taxonomy classifier"
```

---

### Task 2: ProfileDesignPresets (the read-time resolver)

**Files:**
- Create: `app/Services/Design/ProfileDesignPresets.php`
- Test: `tests/Unit/Design/ProfileDesignPresetsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

/**
 * Pure unit tests for ProfileDesignPresets — user profile fields in, sparse
 * design_kits overlay out. No DB, no container: forUser() reads model
 * properties only, so unsaved User instances are enough.
 */

use App\Models\Core\User\User;
use App\Services\Design\ProfileDesignPresets;
use App\Services\Design\SectorStylePresets;

it('returns the bucket base for a slug with no refinement', function () {
    $user = new User;
    $user->sector = 'restaurant'; // food_drink bucket, no slug refinement

    expect(ProfileDesignPresets::forUser($user))
        ->toBe(SectorStylePresets::forBucket(SectorStylePresets::FOOD_DRINK));
});

it('merges the slug refinement over the bucket base', function () {
    $user = new User;
    $user->sector = 'spa'; // beauty bucket + spa refinement

    $expected = array_merge(
        SectorStylePresets::forBucket(SectorStylePresets::BEAUTY_PERSONAL_CARE),
        SectorStylePresets::forSlug('spa'),
    );
    $out = ProfileDesignPresets::forUser($user);

    expect($out)->toBe($expected)
        ->and($out['color_accent'])->toBe('#0f766e')      // spa teal beats bucket rose
        ->and($out['typography_font_family'])->toBe('helvetica-now'); // bucket font survives
});

it('styles a google-sourced sector too — fields, not sources', function () {
    $user = new User;
    $user->sector = 'barber';
    $user->sector_source = 'google';

    expect(ProfileDesignPresets::forUser($user))->not->toBe([]);
});

it('returns [] for a null user', function () {
    expect(ProfileDesignPresets::forUser(null))->toBe([]);
});

it('returns [] for a blank sector', function () {
    $user = new User;
    $user->sector = '  ';

    expect(ProfileDesignPresets::forUser($user))->toBe([]);
});

it('returns [] for a slug with no taxonomy bucket', function () {
    $user = new User;
    $user->sector = 'not-a-real-sector-slug';

    expect(ProfileDesignPresets::forUser($user))->toBe([]);
});

it('both tiers pass the targetable-column allowlist untouched', function () {
    $targetable = (new ReflectionClass(ProfileDesignPresets::class))->getConstant('TARGETABLE');
    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }
    foreach ($overlays as $overlay) {
        expect(array_intersect_key($overlay, array_flip($targetable)))->toBe($overlay);
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Design/ProfileDesignPresetsTest.php`
Expected: FAIL — `Class "App\Services\Design\ProfileDesignPresets" not found`

- [ ] **Step 3: Create the class**

```php
<?php

namespace App\Services\Design;

use App\Models\Core\User\User;
use App\Services\Profile\SectorTaxonomy;

/**
 * Read-time design presets derived from the user's OWN profile fields — no
 * integrations, no stored contributions, no jobs, no scans. Pure and
 * deterministic: the same user row always yields the same sparse overlay, so
 * consumers call this at read time and overlay the manual site.design_kits
 * row on top (manual non-null always wins per column).
 *
 * v1 field: sector/industry (core.users.sector, ANY source — the field is
 * user-visible and user-editable, so a google-filled sector styles too).
 * Resolution is two-tier: the sector's taxonomy bucket sets the industry
 * base, the slug's refinement (if any) sharpens it — see SectorStylePresets.
 * Future user fields: add a private fromX(User): array method and merge it
 * in forUser() — later merges refine earlier ones.
 */
final class ProfileDesignPresets
{
    /**
     * design_kits columns a profile preset may set — VALUE/SELECTION vars
     * only, never inferred vars (they derive at render time). theme_mode
     * IS presettable (owner override 2026-07-22 — the palette is the site's
     * colour identity and industries have a clear room-tone); the user's own
     * manual pick still wins per the universal manual-over-preset rule.
     * theme_night_shift_auto is the ONE remaining user-only field (a
     * functional day/night toggle, not an aesthetic choice) — never preset.
     * typography_uppercase (boolean) IS presettable: the old TEXT-valued
     * contribution rows couldn't carry it, but the read-time PHP overlay can.
     *
     * @var list<string>
     */
    private const TARGETABLE = [
        'theme_mode',
        'color_accent',
        'theme_contrast',
        'text_body',
        'text_desktop_body',
        'weight_regular',
        'weight_heading',
        'typography_line_height',
        'typography_logo_height',
        'typography_font_family',
        'typography_uppercase',
        'typography_tracking',
        'border_thickness',
        'border_radius',
        'space_regular',
        'space_desktop_regular',
        'layout_density',
        'border_style',
        'motion_pace',
        'effect_shadow_style',
        'effect_link_style',
        'effect_image_treatment',
    ];

    /** @return array<string, string|bool> sparse [design_kits column => value]; [] when nothing applies */
    public static function forUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $overlay = self::fromSector($user);

        return array_intersect_key($overlay, array_flip(self::TARGETABLE));
    }

    /** @return array<string, string|bool> bucket base sharpened by the slug refinement */
    private static function fromSector(User $user): array
    {
        $slug = trim((string) ($user->sector ?? ''));
        if ($slug === '') {
            return [];
        }

        $bucket = SectorTaxonomy::bucketFor($slug);
        if ($bucket === null) {
            return [];
        }

        return array_merge(
            SectorStylePresets::forBucket($bucket),
            SectorStylePresets::forSlug($slug),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Design/ProfileDesignPresetsTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Design/ProfileDesignPresets.php tests/Unit/Design/ProfileDesignPresetsTest.php
git commit -m "feat(design): ProfileDesignPresets — read-time two-tier user-field preset resolver"
```

---

### Task 3: Rewrite DesignRationaleService

**Files:**
- Modify: `app/Services/Design/DesignRationaleService.php` (full rewrite)
- Modify: `app/Http/Resources/SiteResource.php:109` (pass user id)
- Test: `tests/Feature/Design/DesignRationaleServiceTest.php` (full rewrite)

Wire shape stays FROZEN: `{summary, hasOverrides, items: [{area, sourceLabel, reason}]}` — frontend untouched.

- [ ] **Step 1: Rewrite the test file** (replace entire contents)

```php
<?php

/**
 * Tests for DesignRationaleService — the transparency-line data. Under test:
 * the industry attribution line, the manual "You set this." line +
 * precedence, hasOverrides + summary copy, and the SAFETY contract — no raw
 * column name or internal key ever surfaces.
 */

use App\Services\Design\DesignRationaleService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
});

/** A tenant with a sector (default: plumber — home_services + accent-only refinement). */
function rationaleTenant(string $handle, ?string $sector = 'plumber')
{
    $user = createTenant($handle);
    $user->sector = $sector;
    $user->sector_source = $sector === null ? null : 'manual';
    $user->save();

    return $user;
}

it('attributes the design to the industry when a sector is set', function () {
    $user = rationaleTenant('sector-trade');

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    expect($out['hasOverrides'])->toBeFalse()
        ->and($out['items'])->toHaveCount(1)
        ->and($out['items'][0]['sourceLabel'])->toBe('Your industry')
        ->and($out['items'][0]['reason'])->toBe('Your design reflects the industry you chose.')
        ->and($out['items'][0]['area'])->toContain('Colours')
        ->and($out['items'][0]['area'])->toContain('Typography')
        ->and($out['summary'])->toContain('tailored automatically');
});

it('puts the manual line first and drops overridden areas from the industry line', function () {
    $user = rationaleTenant('sector-override');
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $user->site->id,
        'color_accent' => '#123456',
    ]);

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    expect($out['hasOverrides'])->toBeTrue()
        ->and($out['items'][0]['sourceLabel'])->toBe('You')
        ->and($out['items'][0]['reason'])->toBe('You set this.');

    $industry = collect($out['items'])->firstWhere('sourceLabel', 'Your industry');
    expect($industry)->not->toBeNull()
        ->and($industry['area'])->not->toContain('Colours'); // accent overridden
});

it('shows only the manual line when every preset column is overridden', function () {
    $user = rationaleTenant('all-manual');
    // Override every column the plumber overlay sets (home_services base +
    // accent refinement): accent, font, body size (+ desktop pair), weight, radius.
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $user->site->id,
        'color_accent' => '#123456',
        'typography_font_family' => 'geist',
        'text_body' => '0.8rem',
        'text_desktop_body' => '0.8rem',
        'weight_regular' => '400',
        'border_radius' => '0.5rem',
    ]);

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    expect($out['items'])->toHaveCount(1)
        ->and($out['items'][0]['sourceLabel'])->toBe('You')
        ->and($out['summary'])->toBe('Your design is set from your own choices.');
});

it('reads default-look copy when no sector and no overrides exist', function () {
    $user = rationaleTenant('no-signal', null);

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    expect($out['hasOverrides'])->toBeFalse()
        ->and($out['items'])->toBe([])
        ->and($out['summary'])
        ->toBe('Your design uses the default look — set your industry to tailor it automatically.');
});

it('never leaks a raw column name or internal key', function () {
    $user = rationaleTenant('safety');

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    $json = json_encode($out);
    expect($json)->not->toContain('color_accent')
        ->and($json)->not->toContain('typography_font_family')
        ->and($json)->not->toContain('sector');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Design/DesignRationaleServiceTest.php`
Expected: FAIL (old service still reads contributions; `forSite()` doesn't accept a user id)

- [ ] **Step 3: Rewrite the service** (replace entire contents of `app/Services/Design/DesignRationaleService.php`)

```php
<?php

namespace App\Services\Design;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

/**
 * Produces the user-facing TRANSPARENCY LINE data for a site's design — the
 * plain-language "your profile sets the design, not you (unless you
 * override)" promise made legible.
 *
 * Computes the profile preset overlay (ProfileDesignPresets) at read time,
 * subtracts manually-set design_kits columns (manual wins), and returns:
 *   { summary, hasOverrides, items: [{ area, sourceLabel, reason }] }
 *
 * SAFETY: never exposes a raw column name or field value — columns collapse
 * into friendly AREAS and the one auto-source surfaces as "Your industry".
 * Wire shape is FROZEN (frontend renders it verbatim).
 */
class DesignRationaleService
{
    /**
     * User-facing AREAS a targetable column belongs to. Any column not
     * listed maps to the generic 'Style' area.
     *
     * @var array<string, string>
     */
    private const COLUMN_AREAS = [
        'theme_mode' => 'Colours',
        'color_accent' => 'Colours',
        'theme_contrast' => 'Colours',
        'text_body' => 'Typography',
        'text_desktop_body' => 'Typography',
        'weight_regular' => 'Typography',
        'weight_heading' => 'Typography',
        'typography_line_height' => 'Typography',
        'typography_logo_height' => 'Typography',
        'typography_font_family' => 'Typography',
        'typography_uppercase' => 'Typography',
        'typography_tracking' => 'Typography',
        'border_thickness' => 'Layout',
        'border_radius' => 'Layout',
        'space_regular' => 'Layout',
        'space_desktop_regular' => 'Layout',
        'layout_density' => 'Layout',
        'border_style' => 'Layout',
        'motion_pace' => 'Motion',
        'effect_shadow_style' => 'Style',
        'effect_link_style' => 'Style',
        'effect_image_treatment' => 'Style',
    ];

    /**
     * @return array{
     *     summary: string,
     *     hasOverrides: bool,
     *     items: list<array{area: string, sourceLabel: string, reason: string}>
     * }
     */
    public function forSite(string $siteId, ?string $userId = null): array
    {
        $manualColumns = $this->manualColumns($siteId);
        $items = [];

        $user = $userId !== null ? User::query()->find($userId) : null;
        $preset = ProfileDesignPresets::forUser($user);
        $surviving = array_diff_key($preset, array_flip($manualColumns));

        if ($surviving !== []) {
            $areas = array_values(array_unique(array_map(
                static fn (string $col): string => self::COLUMN_AREAS[$col] ?? 'Style',
                array_keys($surviving),
            )));
            sort($areas);
            $items[] = [
                'area' => implode(' & ', $areas),
                'sourceLabel' => 'Your industry',
                'reason' => 'Your design reflects the industry you chose.',
            ];
        }

        $hasOverrides = $manualColumns !== [];
        if ($hasOverrides) {
            array_unshift($items, [
                'area' => 'Your changes',
                'sourceLabel' => 'You',
                'reason' => 'You set this.',
            ]);
        }

        return [
            'summary' => $this->summary($items, $hasOverrides),
            'hasOverrides' => $hasOverrides,
            'items' => $items,
        ];
    }

    /**
     * The design_kits columns the user has set manually (non-null).
     * Fail-closed to "none" on any read error (SQLite test mirror may lack
     * site.design_kits) — a rationale read must never break the SiteResource
     * response embedding it.
     *
     * @return list<string>
     */
    private function manualColumns(string $siteId): array
    {
        try {
            $row = DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->first();
        } catch (\Throwable) {
            return [];
        }

        if ($row === null) {
            return [];
        }

        $vars = (array) $row;
        unset($vars['site_id'], $vars['created_at'], $vars['updated_at']);

        return array_keys(array_filter($vars, static fn ($v): bool => $v !== null));
    }

    /** @param  list<array{area: string, sourceLabel: string, reason: string}>  $items */
    private function summary(array $items, bool $hasOverrides): string
    {
        $derived = $hasOverrides ? count($items) - 1 : count($items);

        if ($derived === 0) {
            return $hasOverrides
                ? 'Your design is set from your own choices.'
                : 'Your design uses the default look — set your industry to tailor it automatically.';
        }

        $base = 'Your design is tailored automatically from the information on your profile.';

        return $hasOverrides
            ? $base.' Your own changes always take priority.'
            : $base;
    }
}
```

- [ ] **Step 4: Pass the user id from SiteResource**

In `app/Http/Resources/SiteResource.php` line ~109, change:

```php
? ['design_rationale' => app(DesignRationaleService::class)->forSite((string) $this->id)]
```

to:

```php
? ['design_rationale' => app(DesignRationaleService::class)->forSite(
    (string) $this->id,
    $this->user_id !== null ? (string) $this->user_id : null,
)]
```

- [ ] **Step 5: Run the rationale + SiteResource tests**

Run: `vendor/bin/pest tests/Feature/Design/DesignRationaleServiceTest.php tests/Unit/Resources/SiteResourceTest.php`
Expected: rationale PASS (5 tests). If `SiteResourceTest` stubs `forSite()` with a one-arg expectation, update the stub to accept the second arg — shape assertions stay identical. If the SQLite `setupDesignKitsTable()` mirror is missing a column an inserted test row needs, the row insert will error — the tests above only insert long-standing columns (accent, font family, text_body, weight, radius), which the pre-existing suite already inserted, so this shouldn't occur; if it does, STOP and extend the mirror in `tests/Pest.php` to match the real `site.design_kits` DDL rather than changing the test.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Design/DesignRationaleService.php app/Http/Resources/SiteResource.php tests/Feature/Design/DesignRationaleServiceTest.php tests/Unit/Resources/SiteResourceTest.php
git commit -m "refactor(design): rationale line computes from profile presets, not contributions"
```

---

### Task 4: Rewire the public payload builder

**Files:**
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` (import ~line 15, constructor ~line 65, call site ~line 121, `loadDesignKit` ~lines 541-573)

- [ ] **Step 1: Swap the import**

Remove `use App\Services\Design\Presets\DesignPresetResolver;` — add `use App\Services\Design\ProfileDesignPresets;` (keep alphabetical order among the `use` lines).

- [ ] **Step 2: Remove the constructor dependency**

Delete the line `private readonly DesignPresetResolver $presetResolver,` from `__construct`.

- [ ] **Step 3: Pass the user at the call site**

Line ~121: `'design_kit' => $this->loadDesignKit($site),` → `'design_kit' => $this->loadDesignKit($site, $pro),` (`$pro` is the User already in scope — used by `buildPublicContact($pro, …)` a few lines above).

- [ ] **Step 4: Replace `loadDesignKit` entirely**

```php
    private function loadDesignKit(?Site $site, ?User $pro): array
    {
        if (! $site) {
            return [];
        }

        $row = DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $site->id)
            ->first();

        // Manual layer: the user's stored (non-null) columns. partna-pages fills
        // the remaining nulls from code-side defaults via mergeDesignKit().
        $manual = [];
        if ($row) {
            $cols = (array) $row;
            unset($cols['site_id']);
            $manual = array_filter($cols, fn ($v) => $v !== null);
        }

        // Overlay manual on the profile-derived preset layer:
        //   defaults <- profile presets <- manual   (manual non-null wins per column).
        $merged = array_merge(ProfileDesignPresets::forUser($pro), $manual);
        if ($merged === []) {
            return [];
        }

        return $this->groupKitColumns($merged);
    }
```

Also delete the docblock lines above the old method that referenced the preset resolver's defensiveness, if any sit outside the method body.

- [ ] **Step 5: Run every public-payload test**

Run: `grep -rln "IndividualProfilePayloadBuilder\|public/profiles" tests --include="*.php" | xargs vendor/bin/pest`
Expected: PASS. (`DesignPresetSystemTest` will fail — it dies in Task 7; skip it here: if it's in the grep result, run the others individually.)

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/PublicSite/IndividualProfilePayloadBuilder.php
git commit -m "refactor(design): public payload reads profile presets at render time"
```

---

### Task 5: Rewire the email brand resolver

**Files:**
- Modify: `app/Mail/Branding/ProEmailBrandResolver.php` (~lines 68-79)

- [ ] **Step 1: Replace the kit merge block**

Old (lines ~68-79):

```php
        // Merge the integration-driven preset layer under the user's manual kit
        // (manual wins) so white-label emails reflect the same auto-styling as
        // the sitepage. Falls back to the raw manual kit if resolution fails.
        try {
            $kit = app(DesignPresetResolver::class)->mergedFlatKit($siteId);
        } catch (\Throwable $e) {
            report($e);
            $kit = (array) (DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->first() ?? []);
        }
```

New:

```php
        // Merge the profile-derived preset layer under the user's manual kit
        // (manual wins) so white-label emails reflect the same auto-styling
        // as the sitepage.
        $manualRow = (array) (DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $siteId)
            ->first() ?? []);
        unset($manualRow['site_id']);
        $manual = array_filter($manualRow, static fn ($v) => $v !== null);
        $kit = array_merge(ProfileDesignPresets::forUser($user), $manual);
```

- [ ] **Step 2: Swap the import**

Remove `use App\Services\Design\Presets\DesignPresetResolver;` — add `use App\Services\Design\ProfileDesignPresets;`.

- [ ] **Step 3: Run email branding tests**

Run: `grep -rln "ProEmailBrandResolver\|EmailBrand" tests --include="*.php" | xargs vendor/bin/pest`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git add app/Mail/Branding/ProEmailBrandResolver.php
git commit -m "refactor(design): email brand kit merges profile presets at read time"
```

---

### Task 6: SectorController + connection cache refresher

**Files:**
- Modify: `app/Http/Controllers/Api/User/Profile/SectorController.php`
- Modify: `app/Services/Platforms/IntegrationConnectionCacheRefresher.php`

- [ ] **Step 1: SectorController — replace the job dispatch with a site touch**

Remove `use App\Jobs\Design\ResolveDesignPresetsJob;`. Replace:

```php
        // A manually-declared sector drives the SectorFactor design preset —
        // rebuild contributions so the sitepage restyles without waiting for
        // an unrelated connection write to trigger the resolve.
        if ($changed) {
            ResolveDesignPresetsJob::dispatch((string) $user->id);
        }
```

with:

```php
        // The sector drives the read-time profile design presets — touch the
        // site so the public payload + email caches roll and the sitepage
        // restyles immediately (SiteObserver::saved runs the purge chain).
        if ($changed) {
            $user->site()->first()?->touch();
        }
```

- [ ] **Step 2: IntegrationConnectionCacheRefresher — delete the preset hook**

Find the call site: `grep -n "resolveDesignPresets" app/Services/Platforms/IntegrationConnectionCacheRefresher.php`. Delete the call line AND the whole `resolveDesignPresets()` method (~lines 53-75), plus now-unused imports (`ResolveDesignPresetsJob`, `DesignFactorRegistry` — check with grep before removing each).

- [ ] **Step 3: Run the sector + platform tests**

Run: `vendor/bin/pest tests/Feature/Api/User/Profile/SectorControllerTest.php tests/Feature/Platforms/`
Expected: PASS — except any test asserting `ResolveDesignPresetsJob` was dispatched (`Queue::assertPushed`). Update those assertions to assert the site was touched instead (`expect($user->site->fresh()->updated_at)->toBeGreaterThan(...)`) or remove the assertion if the test's subject is unrelated.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/User/Profile/SectorController.php app/Services/Platforms/IntegrationConnectionCacheRefresher.php tests/
git commit -m "refactor(design): drop preset-resolve job dispatches — presets are read-time now"
```

---

### Task 7: Delete the factor machine

**Files:** (all deletions + 3 edits)

`SectorTaxonomy` no longer references `CategoryStylePresets` (re-homed in Task 1), so the whole `Presets/` dir goes.

- [ ] **Step 1: Delete files**

```bash
git rm -r app/Services/Design/Presets
git rm app/Jobs/Design/ResolveDesignPresetsJob.php
git rm app/Console/Commands/ResolveAllDesignPresetsCommand.php
git rm app/Console/Commands/SweepStaleDesignKitContributionsCommand.php
git rm app/Models/Core/Site/DesignKitContribution.php
git rm tests/Feature/Design/DesignPresetResolverDefensiveTest.php
git rm tests/Feature/Design/DesignPresetSystemTest.php
git rm tests/Feature/Design/EvidenceFactorPrecedenceTest.php
git rm tests/Feature/Design/FactorSweepTest.php
git rm tests/Feature/Design/IdentityFactorsTest.php
git rm tests/Feature/Design/ResolveAllDesignPresetsCommandTest.php
git rm tests/Feature/Console/SweepStaleDesignKitContributionsCommandTest.php
git rm -r tests/Unit/Design/Presets
rmdir app/Jobs/Design 2>/dev/null || true
```

- [ ] **Step 2: AppServiceProvider — remove the registry**

In `app/Providers/AppServiceProvider.php`:
- Delete these `use` lines: `DesignFactorRegistry` and all 13 `App\Services\Design\Presets\Factors\*` imports (lines ~64-77).
- Delete the whole singleton block (~lines 160-199): the explanatory comment paragraph ending "…a provable no-op, not a gap." AND `$this->app->singleton(DesignFactorRegistry::class, fn () => new DesignFactorRegistry([…]));`.
- Check `SafeUrlFetcher` / `PlatformRegistry` imports are still used elsewhere in the file (`grep -n "SafeUrlFetcher\|PlatformRegistry" app/Providers/AppServiceProvider.php`) — remove only if now unused.

- [ ] **Step 3: PolicyCoverageTest — drop the exemption**

In `tests/Feature/Security/PolicyCoverageTest.php`: delete `use App\Models\Core\Site\DesignKitContribution;` (line ~11) and the `DesignKitContribution::class,` entry (line ~47).

- [ ] **Step 4: Pest.php — drop the table helper**

In `tests/Pest.php`: delete the whole `setupDesignKitContributionsTable()` function (lines ~2375-2391). Verify nothing still calls it: `grep -rn "setupDesignKitContributionsTable" tests/` → only Pest.php itself before the edit, nothing after.

- [ ] **Step 5: Full-suite run**

Run: `composer test`
Expected: PASS, zero references to deleted classes. Any straggler failure = a missed consumer; fix it before committing (do NOT re-add deleted files).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "refactor(design)!: delete the integration factor machine (13 factors, resolver, contributions)"
```

---

### Task 8: Drop-table migration (written now, applied post-deploy)

**Files:**
- Create: `supabase/migrations/20260722100000_drop_design_kit_contributions.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Profile design presets are computed at read time from core.users fields
-- (ProfileDesignPresets); the stored integration-factor contribution layer
-- is retired. Manual site.design_kits columns are untouched.
drop table if exists site.design_kit_contributions;
```

Single statement — pipeline-safe per `supabase/migrations/CONVENTIONS.md`. Do NOT `db push` yet (Task 10 — after the code deploys, so no deployed code still reads the table; the old code's `presetLayer` is defensive anyway, so a brief overlap is harmless either way).

- [ ] **Step 2: Commit**

```bash
git add supabase/migrations/20260722100000_drop_design_kit_contributions.sql
git commit -m "chore(db): drop site.design_kit_contributions (factor machine retired)"
```

---

### Task 9: Verification sweep

- [ ] **Step 1: Leftover-reference grep — must ALL be empty**

```bash
grep -rn "Design\\\\Presets\|DesignKitContribution\|ResolveDesignPresetsJob\|DesignPresetResolver\|DesignFactorRegistry\|CategoryStylePresets\|IdentityEvidence\|PresetTargetableColumns\|IntegrationConnectionFactorAdapter\|LaunchRecipe\|RecipeSignals" app tests routes config database
```

- [ ] **Step 2: Docs sweep**

```bash
grep -rln "DesignPresetResolver\|factor" docs/runbooks docs/checklists 2>/dev/null
```
Update any hit that documents the dead system (delete the paragraph or mark superseded by this plan).

- [ ] **Step 3: Full suite + style**

```bash
composer test
vendor/bin/pint --test
```
Expected: both clean.

---

### Task 10: Ship

- [ ] **Step 1: Push + PR to development** (repo rule: feature-branch → PR → merge; do NOT promote to production)

```bash
git push -u origin feature/profile-design-presets
gh pr create --base development --title "Profile design presets: replace factor machine with read-time user-field mapping" --body "Deletes the 13-factor integration preset engine; design presets now compute at read time from core.users.sector via ProfileDesignPresets (two-tier: bucket base + slug refinement). Manual design_kits always wins. Drop-table migration applied post-merge. 🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

- [ ] **Step 2: After merge + Laravel Cloud auto-deploy — apply the migration**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run   # expect ONLY 20260722100000_drop_design_kit_contributions.sql
supabase db push
```

- [ ] **Step 3: Live verification**

```bash
cloud tinker development --code='$u = \App\Models\Core\User\User::query()->whereNotNull("sector")->first(); var_dump($u?->sector, \App\Services\Design\ProfileDesignPresets::forUser($u));'
# Purge + fetch a sectored handle; confirm designKit carries the overlay values under any manual overrides:
cloud tinker development --code='app(\App\Services\Cloudflare\CloudflarePurgeService::class)->purgeHandle("<handle>");'
curl -s https://dev-api.partna.au/api/public/profiles/<handle> | python3 -m json.tool | grep -A4 '"designKit"' | head -20
```

- [ ] **Step 4: Delete this plan file** (repo convention: plans die when shipped) and check logs:

```bash
cloud env:logs partna development --minutes 10
git rm docs/superpowers/plans/2026-07-22-profile-design-presets.md && git commit -m "chore: remove shipped plan" && git push
```

---

## Out of scope (explicitly NOT this change)

- `DesignKitAccentApplier` / previous-website scraping (separate subsystem, kept).
- `ImageVariantService` palette extraction (kept; orphaned consumer — candidate follow-up).
- Further tuning of `SectorStylePresets` values (the table ships with the pass above; iterate in that one file after seeing it live).
- Adding new user fields beyond sector (the `fromX()` seam is documented in `ProfileDesignPresets`). Eligible future fields = any STORED user/profile/site field regardless of provenance (e.g. workplace opening hours) — never `platform_connections` payloads.
- Any frontend or monorepo change (wire shapes frozen).

## If you get stuck

Write `/tmp/profile-presets-blocked-<timestamp>.md` with the failing step, the exact error, and what you tried — then STOP and report. Do not guess around missing upstream files or renamed helpers.
