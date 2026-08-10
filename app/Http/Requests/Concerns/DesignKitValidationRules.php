<?php

namespace App\Http\Requests\Concerns;

// Shared design_kit.* validation rules used by both UpdateSiteRequest and
// StaffUpdateSiteRequest. Extraction prevents the two classes from drifting
// (TEST-5 / LIFE-4). To add or remove a design_kit column: update this trait
// only — both request classes pick it up automatically.
//
// ─── 2026-08-09: PRESET-ONLY. 57 columns → 8. ────────────────────────────────
//
// The design-kit go-live (brief §3.2 / §8.2) replaces the per-token storage
// model with a small set of SELECTIONS. Two things ended at once:
//
//   1. The PROMOTION MODEL. Every token used to carry its own nullable column
//      so any one of them "could become a per-user override later". It never
//      did, and 49 of those columns held nothing on dev. They are gone.
//   2. The AUTO/MANUAL GRAMMAR. There is no longer a "null means follow the
//      sector preset" tier. Sector presets are a starting default at signup,
//      not a living link, so nothing is an *override* of anything.
//
// `nullable` survives on every rule, and it means one thing only: CLEAR THIS
// BACK TO THE SHIPPED PACKAGE DEFAULT. The columns stay nullable in Postgres
// because the trg_create_empty_design_kit trigger inserts an all-NULL row for
// every new site, and because the pages app fills nulls from the design
// system's own defaults (mergeDesignKit). Null is "unset", never "inherit".
//
// The eight survivors, and what each drives:
//
//   color_accent            free-form hex, scraped from brand
//   typography_font_family  slug from the four-font roster
//   text_size               small | medium | large      (5 font + 5 icon roles)
//   spacing                 default | spacious          (4 gaps, 3 paddings,
//                                                        3 card heights)
//   corners                 default | rounded           (--dk-radius: 8/16px)
//   border_thickness        default | none              ← WAS A LENGTH ('1px')
//   theme_mode              bleach                      (the one palette)
//   theme_night_shift_auto  boolean
//
// The four selection vocabularies mirror @partnaau/design-system's presets.ts
// (TEXT_SIZE_PRESETS / SPACING_PRESETS / CORNER_PRESETS / THICKNESS_PRESETS)
// key-for-key. That file declares itself the single source of truth the
// dispatcher, the dashboard and this backend all read; the backend cannot
// import TS, so — like EmailBrandDefaults::ACCENT — this is a hand-kept
// mirror. CHANGE ONE, CHANGE BOTH.
//
// 'medium' is shown as "Default" in the UI. The key stays `medium` so it still
// maps to TEXT_SIZE_PRESETS.
//
// No CHECK constraint backs these four. That is deliberate and matches
// theme_mode, whose single legal value has always lived in this rule alone:
// the request layer is the only write path that carries a vocabulary
// (DesignKitAutopilot and DesignKitAccentApplier write color_accent only, and
// DesignKitRestyleService replays autopilot's own proposals). Adding CHECKs
// later is a NOT VALID → VALIDATE pair of migrations plus three entries in
// tests/Feature/Database/ConstraintVocabularyLockstepTest.php — see that
// file's header for the lockstep contract.
//
// ─── History ────────────────────────────────────────────────────────────────
// 2026-07-07 regenerated against the live schema. 2026-07-10 theme/surface
// rework, semantic text scale, surfaces. 2026-07-15 sitepage rebuild dropped
// effect_surface and the glass satellites. 2026-08-06 simplification dropped
// theme_contrast, typography_tracking, typography_weight, typography_uppercase,
// motion_pace and border_style, and narrowed theme_mode to 'bleach'.
trait DesignKitValidationRules
{
    /**
     * Returns the full design_kit.* allowlist — one entry per live
     * site.design_kits column (snake_case, matching the DB).
     *
     * @return array<string, list<string>>
     */
    protected function designKitRules(): array
    {
        // Hex-only colors: values land in inline CSS, so the regex is a
        // safety boundary, not just format pedantry.
        $hex = ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'];

        // Every selection is `sometimes|nullable|string|in:...` — null clears
        // the column back to the package default (see the header).
        $selection = static fn (string $values): array => [
            'sometimes', 'nullable', 'string', 'in:'.$values,
        ];

        return [
            'design_kit' => ['sometimes', 'array'],

            // The user's brand accent. The only free-form value left in the
            // kit; everything else is pick-from-a-fixed-set.
            'design_kit.color_accent' => $hex,

            // Font slug resolved by @partnaau/design-system/design-assets from
            // the four-font roster (nb-architekt, helvetica-neue,
            // monument-grotesk, forma-djr). Left as a free string rather than
            // an `in:` list because the roster lives in the design system and
            // FontKeywordClassifier already owns the retired-slug migration
            // map; a hard list here would be a fourth place to keep in step.
            'design_kit.typography_font_family' => ['sometimes', 'nullable', 'string', 'max:64'],

            // The four selections (presets.ts mirror — see the header).
            'design_kit.text_size' => $selection('small,medium,large'),
            'design_kit.spacing' => $selection('default,spacious'),
            'design_kit.corners' => $selection('default,rounded'),
            'design_kit.border_thickness' => $selection('default,none'),

            // Theme — mode owns the bg/text anchors server-side (email
            // theming via ThemeModePalettes) and 'bleach' is its only legal
            // value. Night Shift Auto switches day/night by visitor clock;
            // user-only, never preset.
            'design_kit.theme_mode' => ['sometimes', 'nullable', 'string', 'in:bleach'],
            'design_kit.theme_night_shift_auto' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
