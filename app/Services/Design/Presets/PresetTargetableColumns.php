<?php

namespace App\Services\Design\Presets;

/**
 * The design_kits columns a preset factor is allowed to set — the VALUE and
 * SELECTION vars (the genuinely user-settable knobs), never inferred vars
 * (which derive automatically from these at render time).
 *
 * This is the contribution `target_var` domain: the resolver drops any value a
 * factor emits for a column outside this list, so a factor can never write an
 * inferred or unknown column.
 *
 * NOTE: `typography_uppercase` is intentionally EXCLUDED. It is a boolean
 * column, but contributions store TEXT values; a preset-set 'true'/'false'
 * string would break the boolean semantics downstream. If preset-controlled
 * uppercase is ever wanted, add boolean coercion first.
 */
final class PresetTargetableColumns
{
    /** @var list<string> */
    public const COLUMNS = [
        // Palette (value) — only bg + accent; the rest of the palette is inferred.
        'color_bg',
        'color_accent',
        // Typography (value + selection)
        'text_xs',
        'text_desktop_xs',
        'weight_regular',
        'typography_line_height',
        'typography_logo_height',
        'typography_font_family',   // selection (font slug)
        // Structure (value + selection)
        'border_thickness',
        'border_radius',
        'space_regular',
        'space_desktop_regular',
        'layout_density',           // value multiplier ("0.85".."1.25")
        'border_style',             // selection (solid | double | none)
        // Animation + effects (selection)
        'motion_pace',
        'motion_entrance',
        'effect_style',
        // R6 identity axes (migration 20260707130000) — the factor system is the
        // PRIMARY setter of these; the dashboard exposes only the Visual Style
        // preset + Customize expando (spec §1, §6).
        'effect_shadow_style',      // flat | soft | hard
        'effect_link_style',        // underline-hover | underline-always | underline-grow | plain
        'effect_button_fill',       // solid | outline | ghost
        'effect_image_treatment',   // none | mono | duotone | warm | muted
    ];

    public static function isValid(string $column): bool
    {
        return in_array($column, self::COLUMNS, true);
    }

    /**
     * Keep only whitelisted columns from a factor's emitted map.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public static function filter(array $values): array
    {
        return array_intersect_key($values, array_flip(self::COLUMNS));
    }
}
