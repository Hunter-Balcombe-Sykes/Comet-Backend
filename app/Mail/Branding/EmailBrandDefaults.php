<?php

namespace App\Mail\Branding;

use App\Services\Design\ThemeModePalettes;

/**
 * Single source of truth for email-safe design-kit defaults.
 *
 * These mirror the email-relevant subset of @partnaau/design-system's
 * design-kit/defaults.ts. That package is the system-wide source of truth; this
 * is a deliberate, contained PHP copy because the package is not reachable from
 * Blade at render time. WHEN A DEFAULT CHANGES THERE, CHANGE IT HERE.
 *
 * Three token kinds:
 *  - 4 STATIC tokens have literal defaults below.
 *  - bg/text come from the theme-mode palette (2026-07-10 rework): the kit's
 *    theme_mode selects its ThemeModePalettes default-variant anchors — the
 *    old color_bg column is gone, and emails don't night-shift.
 *  - 2 DERIVED tokens (button_primary_bg / button_primary_text) are NULLABLE
 *    columns with no DB default and no defaults.ts entry; the design system
 *    derives them from accent / accent-contrast at render time, so we do the same.
 */
final class EmailBrandDefaults
{
    public const ACCENT = '#3a6efc';

    public const ACCENT_CONTRAST = '#ffffff';

    public const TEXT_MUTED = '#6e6e73';

    public const BORDER_RADIUS = '8px';

    /**
     * Build a fully-populated palette from a raw site.design_kits row
     * (flat snake_case column => value; nulls/missing/empty fall back).
     *
     * @param  array<string, mixed>  $kit
     */
    public static function palette(array $kit): EmailPalette
    {
        $accent = self::pick($kit, 'color_accent', self::ACCENT);
        $accentContrast = self::pick($kit, 'color_accent_contrast', self::ACCENT_CONTRAST);

        // bg/text are theme-mode-owned: an unknown/missing mode falls back to
        // bleach inside anchorsFor (never breaks an email over a bad kit row).
        $mode = $kit['theme_mode'] ?? null;
        $anchors = ThemeModePalettes::anchorsFor(is_string($mode) ? $mode : null);

        return new EmailPalette(
            accent: $accent,
            accentContrast: $accentContrast,
            bg: $anchors['bg'],
            text: $anchors['text'],
            textMuted: self::pick($kit, 'color_text_muted', self::TEXT_MUTED),
            // Derived: stored value wins, else fall back to the resolved base token.
            buttonBg: self::pick($kit, 'button_primary_bg', $accent),
            buttonText: self::pick($kit, 'button_primary_text', $accentContrast),
            borderRadius: self::pick($kit, 'border_radius', self::BORDER_RADIUS),
        );
    }

    /** Default palette (empty kit) — used by EmailBrand::partna(). */
    public static function defaults(): EmailPalette
    {
        return self::palette([]);
    }

    /** Stored value if a non-empty string, else the fallback. */
    private static function pick(array $kit, string $key, string $fallback): string
    {
        $value = $kit[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $fallback;
    }
}
