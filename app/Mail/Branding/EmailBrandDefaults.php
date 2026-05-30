<?php

namespace App\Mail\Branding;

/**
 * Single source of truth for email-safe design-kit defaults.
 *
 * These mirror the email-relevant subset of @partnaau/design-system's
 * design-kit/defaults.ts. That package is the system-wide source of truth; this
 * is a deliberate, contained PHP copy because the package is not reachable from
 * Blade at render time. WHEN A DEFAULT CHANGES THERE, CHANGE IT HERE.
 *
 * Two token kinds:
 *  - 6 STATIC tokens have literal defaults below.
 *  - 2 DERIVED tokens (button_primary_bg / button_primary_text) are NULLABLE
 *    columns with no DB default and no defaults.ts entry; the design system
 *    derives them from accent / accent-contrast at render time, so we do the same.
 */
final class EmailBrandDefaults
{
    public const ACCENT = '#3a6efc';

    public const ACCENT_CONTRAST = '#ffffff';

    public const BG = '#ffffff';

    public const TEXT = '#1d1d1f';

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

        return new EmailPalette(
            accent: $accent,
            accentContrast: $accentContrast,
            bg: self::pick($kit, 'color_bg', self::BG),
            text: self::pick($kit, 'color_text', self::TEXT),
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
