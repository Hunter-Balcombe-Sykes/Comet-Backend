<?php

namespace App\Http\Requests\Concerns;

// Shared design_kit.* validation rules used by both UpdateSiteRequest and
// StaffUpdateSiteRequest. Extraction prevents the two classes from drifting
// (TEST-5 / LIFE-4). To add or remove a design_kit column: update this trait
// only — both request classes pick it up automatically.
trait DesignKitValidationRules
{
    /**
     * Returns the full design_kit.* allowlist.
     *
     * Keys are individual site.design_kits column names (snake_case, matching
     * the DB). The controller's writeDesignKit() filters against
     * information_schema.columns so unknown keys are silently dropped, but we
     * allowlist the known shapes here for clear 422s on typos and to document
     * the contract.
     *
     * @return array<string, list<string>>
     */
    protected function designKitRules(): array
    {
        return [
            'design_kit' => ['sometimes', 'array'],
            // Colors group — hex-only: regex enforced so values land safely in inline CSS
            'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.color_text' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.color_text_muted' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.color_accent_contrast' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.color_placeholder' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.color_contrasting_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.color_contrasting_text' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            // Typography group — body + heading scale (h1 / h2; h3 reuses
            // body fontSize). fontFamily is a slug resolved by
            // @partnaau/design-system/design-assets.
            'design_kit.typography_font_family' => ['sometimes', 'nullable', 'string', 'max:64'],
            'design_kit.typography_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_font_weight' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.typography_h1_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_h1_font_weight' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.typography_h2_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_h2_font_weight' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.typography_uppercase' => ['sometimes', 'nullable', 'boolean'],
            // Borders (focus color is a derived default — null = follow accent)
            'design_kit.border_thickness' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.border_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.border_radius' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.border_focus_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Space — single scale used for both CSS padding props AND
            // flex/grid gap props. Replaces the prior padding + spacing pair.
            'design_kit.space_xs' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_s' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_regular' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_medium' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Icons
            'design_kit.icon_size' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.icon_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.icons_xl_size' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.icons_xxl_size' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.icons_stroke_width' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.icons_large_stroke_width' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Effects
            'design_kit.effect_overlay_blur' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.effect_overlay_opacity' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Sizing — UI control intrinsic dimensions
            'design_kit.sizing_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_input_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_header_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Responsive companion groups — single desktop breakpoint
            // (@media min-width 550px). Empty values cascade from the
            // mobile base via the CSS cascade.
            'design_kit.space_desktop_xs' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_desktop_s' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_desktop_regular' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_desktop_medium' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_desktop_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_desktop_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_desktop_input_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_desktop_header_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.typography_desktop_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_desktop_h1_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_desktop_h2_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Motion — animation durations consumed by skeleton interactions
            'design_kit.motion_expand_duration' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.motion_fade_duration' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Buttons — all derived-default colors (null = follow the linked
            // colors.* var via vars.css; non-null = explicit override). Hex-only.
            'design_kit.button_primary_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.button_primary_text' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.button_secondary_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.button_secondary_text' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.button_general_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'design_kit.button_general_text' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        ];
    }
}
