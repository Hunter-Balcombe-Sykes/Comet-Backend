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
 *
 * `theme_mode` and `theme_night_shift_auto` are likewise EXCLUDED: the theme
 * palette and Night Shift Auto are user-only selections (locked decision,
 * plan 2026-07-10) — factors must never set them. (night_shift_auto is also
 * a boolean, so the coercion caveat above applies to it too.)
 */
final class PresetTargetableColumns
{
    /** @var list<string> */
    public const COLUMNS = [
        // Palette (value) — accent only; bg/text are owned by the user-picked
        // theme_mode palette (2026-07-10 rework), the rest is inferred.
        'color_accent',
        // Typography (value + selection)
        'text_body',
        'text_desktop_body',
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
        // R6 identity axes (migration 20260707130000) — the factor system is the
        // PRIMARY setter of these; the dashboard exposes only the Visual Style
        // preset + Customize expando (spec §1, §6).
        'effect_shadow_style',      // flat | soft | hard
        'effect_link_style',        // underline-hover | underline-always | plain  (NOT underline-grow — unrenderable; the sitepage renderer + kit validator only accept these three, a stray 'underline-grow' silently falls back to hover)
        'effect_image_treatment',   // none | mono | duotone | warm | muted
        // effect_button_fill retired 2026-07-10; effect_surface retired
        // 2026-07-15 (the surface-type kit axis is gone — stale contributions
        // targeting it are dropped by this allowlist). The glass knobs
        // (effect_glass_blur, motion_glass_shine_duration) are deliberately
        // NOT factor-targetable.
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
