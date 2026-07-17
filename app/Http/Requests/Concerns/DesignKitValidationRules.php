<?php

namespace App\Http\Requests\Concerns;

// Shared design_kit.* validation rules used by both UpdateSiteRequest and
// StaffUpdateSiteRequest. Extraction prevents the two classes from drifting
// (TEST-5 / LIFE-4). To add or remove a design_kit column: update this trait
// only — both request classes pick it up automatically.
//
// Regenerated 2026-07-07 against the live site.design_kits schema — the
// previous revision still allowlisted long-dropped columns (typography_h1_*,
// sizing_*, button_*, effect_overlay_*) and missed the current text_* /
// weight_* / motion selection columns. writeDesignKit() filters against
// information_schema.columns so stale keys were harmless, but the trait is
// the documented contract and should mirror the schema exactly.
//
// 2026-07-10 theme/surface rework (migration 20260710160000): the bg colour,
// visual-style bundle and entrance-animation columns dropped; theme_mode is now
// the 5-value palette selection; theme_night_shift_auto added.
//
// 2026-07-10 semantic text scale (migration 20260710190000): the seven
// size-named text_* columns became the nine semantic slots (caption/body/
// h3/h2/h1/display + desktop body/h1/display).
//
// 2026-07-10 surfaces (migration 20260710210000): effect_button_fill dropped;
// the glass knobs were added (and removed again 2026-07-15 — see below).
//
// 2026-07-15 sitepage rebuild: effect_surface (glass/solid/outline Surface
// type) dropped (migration 20260714210000), then its glass satellites —
// effect_scrim_blur / effect_glass_blur / motion_glass_shine_duration —
// dropped too (migration 20260714230000). Component look flows from the kit
// vars directly.
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
        $len = ['sometimes', 'nullable', 'string', 'max:16'];
        $size = ['sometimes', 'nullable', 'string', 'max:32'];

        return [
            'design_kit' => ['sometimes', 'array'],

            // Colors — bg is gone: the background is owned by theme_mode's
            // palette (2026-07-10 rework).
            'design_kit.color_accent' => $hex,
            'design_kit.color_text' => $hex,
            'design_kit.color_text_muted' => $hex,
            'design_kit.color_secondary_text' => $hex,
            'design_kit.color_accent_contrast' => $hex,
            'design_kit.color_placeholder' => $hex,
            'design_kit.color_contrasting_bg' => $hex,
            'design_kit.color_contrasting_text' => $hex,

            // Typography — fontFamily is a slug resolved by
            // @partnaau/design-system/design-assets. tracking is the
            // letter-spacing register selection (2026-07-15 axis); weight is
            // the overall weight register selection (2026-07-17 axis — the
            // renderer shifts the whole weight system one rung).
            'design_kit.typography_font_family' => ['sometimes', 'nullable', 'string', 'max:64'],
            'design_kit.typography_line_height' => $len,
            'design_kit.typography_logo_height' => $len,
            'design_kit.typography_uppercase' => ['sometimes', 'nullable', 'boolean'],
            'design_kit.typography_tracking' => ['sometimes', 'nullable', 'string', 'in:tight,normal,wide'],
            'design_kit.typography_weight' => ['sometimes', 'nullable', 'string', 'in:light,regular,bold'],

            // Text scale — semantic slots (2026-07-10): body is the value
            // base; the rest are inferred but keep nullable columns for
            // promotion.
            'design_kit.text_caption' => $size,
            'design_kit.text_body' => $size,
            'design_kit.text_h3' => $size,
            'design_kit.text_h2' => $size,
            'design_kit.text_h1' => $size,
            'design_kit.text_display' => $size,
            'design_kit.text_desktop_body' => $size,
            'design_kit.text_desktop_h1' => $size,
            'design_kit.text_desktop_display' => $size,

            // Weight scale — regular is the value base; light/medium/semibold/
            // bold are inferred (base∓100/200/300). heading is its own Value
            // (2026-07-15 axis): heading/display weight independent of body.
            'design_kit.weight_regular' => $len,
            'design_kit.weight_heading' => $len,
            'design_kit.weight_light' => $len,
            'design_kit.weight_medium' => $len,
            'design_kit.weight_semibold' => $len,
            'design_kit.weight_bold' => $len,

            // Borders
            'design_kit.border_thickness' => $len,
            'design_kit.border_color' => $hex,
            'design_kit.border_radius' => $len,
            'design_kit.border_small_radius' => $len,

            // Space scale — regular is the value base (+ desktop companions).
            'design_kit.space_xxs' => $len,
            'design_kit.space_xs' => $len,
            'design_kit.space_s' => $len,
            'design_kit.space_regular' => $len,
            'design_kit.space_medium' => $len,
            'design_kit.space_large' => $len,
            'design_kit.space_xl' => $len,
            'design_kit.space_desktop_regular' => $len,
            'design_kit.space_desktop_xl' => $len,

            // Icons
            'design_kit.icon_size' => $len,
            'design_kit.icon_color' => $hex,
            'design_kit.icons_large_size' => $len,
            'design_kit.icons_xl_size' => $len,
            'design_kit.icons_xxl_size' => $len,
            'design_kit.icons_stroke_width' => $len,
            'design_kit.icons_large_stroke_width' => $len,
            'design_kit.icons_brand_logo_height' => $len,

            // Motion — pace is the selection; durations/curve are inferred
            // from pace but keep columns for promotion. (Entrance removed
            // entirely in the 2026-07-10 rework.)
            'design_kit.motion_pace' => ['sometimes', 'nullable', 'string', 'in:slow,normal,fast'],
            'design_kit.motion_fade_duration' => $len,
            'design_kit.motion_expand_duration' => $len,
            'design_kit.motion_spin_duration' => $len,
            'design_kit.motion_spring_curve' => ['sometimes', 'nullable', 'string', 'max:64'],

            // Effects — media-scrim blur values + the per-axis identity
            // selections (Surface type removed 2026-07-15).
            'design_kit.effect_shadow_style' => ['sometimes', 'nullable', 'string', 'in:flat,soft,hard'],
            'design_kit.effect_link_style' => ['sometimes', 'nullable', 'string', 'in:underline-hover,underline-always,plain'],
            'design_kit.effect_image_treatment' => ['sometimes', 'nullable', 'string', 'in:none,mono,duotone,warm,muted'],

            // Layout + border character axes.
            'design_kit.layout_density' => ['sometimes', 'nullable', 'string', 'regex:/^(0\.(8[5-9]|9\d)|1(\.([01]\d|2[0-5]))?)$/'],
            'design_kit.border_style' => ['sometimes', 'nullable', 'string', 'in:solid,double,none'],

            // Theme — mode is the user-picked palette that owns bg/text/border
            // anchors. Night Shift Auto switches day/night palette variants by
            // visitor clock; user-only, default true code-side.
            'design_kit.theme_mode' => ['sometimes', 'nullable', 'string', 'in:bleach,dust,warm,dusk,midnight'],
            'design_kit.theme_night_shift_auto' => ['sometimes', 'nullable', 'boolean'],
            // Contrast register (2026-07-15 axis) — the dispatcher transforms
            // the mode's palette variants server-side.
            'design_kit.theme_contrast' => ['sometimes', 'nullable', 'string', 'in:soft,normal,stark'],
        ];
    }
}
