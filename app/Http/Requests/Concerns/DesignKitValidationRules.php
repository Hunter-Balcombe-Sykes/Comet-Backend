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

            // Colors
            'design_kit.color_bg' => $hex,
            'design_kit.color_accent' => $hex,
            'design_kit.color_text' => $hex,
            'design_kit.color_text_muted' => $hex,
            'design_kit.color_secondary_text' => $hex,
            'design_kit.color_accent_contrast' => $hex,
            'design_kit.color_placeholder' => $hex,
            'design_kit.color_contrasting_bg' => $hex,
            'design_kit.color_contrasting_text' => $hex,

            // Typography — fontFamily is a slug resolved by
            // @partnaau/design-system/design-assets.
            'design_kit.typography_font_family' => ['sometimes', 'nullable', 'string', 'max:64'],
            'design_kit.typography_line_height' => $len,
            'design_kit.typography_logo_height' => $len,
            'design_kit.typography_uppercase' => ['sometimes', 'nullable', 'boolean'],

            // Text scale — xs is the value base; the rest are inferred but
            // keep nullable columns for promotion.
            'design_kit.text_xs' => $size,
            'design_kit.text_sm' => $size,
            'design_kit.text_md' => $size,
            'design_kit.text_lg' => $size,
            'design_kit.text_xl' => $size,
            'design_kit.text_xxl' => $size,
            'design_kit.text_desktop_xs' => $size,

            // Weight scale — regular is the value base; light/medium/semibold/
            // bold are inferred (base∓100/200/300).
            'design_kit.weight_regular' => $len,
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

            // Motion — pace + entrance are selections; durations/curve are
            // inferred from pace but keep columns for promotion.
            'design_kit.motion_pace' => ['sometimes', 'nullable', 'string', 'in:slow,normal,fast'],
            'design_kit.motion_entrance' => ['sometimes', 'nullable', 'string', 'in:none,fade,rise,stagger'],
            'design_kit.motion_fade_duration' => $len,
            'design_kit.motion_expand_duration' => $len,
            'design_kit.motion_spin_duration' => $len,
            'design_kit.motion_spring_curve' => ['sometimes', 'nullable', 'string', 'max:64'],

            // Effects
            'design_kit.effect_style' => ['sometimes', 'nullable', 'string', 'in:soft-glass'],
            'design_kit.effect_scrim_blur' => $len,

            // Theme
            'design_kit.theme_mode' => ['sometimes', 'nullable', 'string', 'in:light,dark'],
        ];
    }
}
