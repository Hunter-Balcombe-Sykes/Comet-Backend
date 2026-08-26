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
 *  - bg/text come from ThemeModePalettes' one bleach anchor pair (the
 *    theme_mode column died 2026-08-27, plan 02).
 *  - 2 DERIVED tokens (button_primary_bg / button_primary_text) are NULLABLE
 *    columns with no DB default and no defaults.ts entry; the design system
 *    derives them from accent / accent-contrast at render time, so we do the same.
 */
final class EmailBrandDefaults
{
    // One of the accent fallbacks the design system ships
    // (design-kit/accents.ts). It was #3a6efc, a fourth blue belonging to no
    // roster — a user with no scraped accent got one colour in their email and
    // a different one on their site.
    //
    // VERIFIED 2026-08-09 against accents.ts: DEFAULT_ACCENT_OPTIONS still
    // carries { hex: '#1367fb', name: 'Blue', ink: 'white' }. The roster grew a
    // fourth option (#fffe06 Yellow) the same day; this pin tracks the blue, so
    // it is unaffected.
    public const ACCENT = '#1367fb';

    public const ACCENT_CONTRAST = '#ffffff';

    public const TEXT_MUTED = '#6e6e73';

    // The resolved default corner, in PIXELS. Mirrors the design system's
    // CORNER_PRESETS.default = '0.5rem' at a 16px root; px rather than rem
    // because these values land in an inline `style` attribute and rem support
    // across email clients is unreliable.
    //
    // Was '0' — the ALD square default (2026-07-14). The go-live's owner
    // decision (brief §3.4) makes 8px the default corner and 16px the Rounded
    // step, so every site changes shape and email follows it.
    public const BORDER_RADIUS = '8px';

    // corners selection → email-safe px radius. Mirrors CORNER_PRESETS
    // ({default: '0.5rem', rounded: '1rem'}) — see BORDER_RADIUS on the unit.
    private const CORNER_RADII = [
        'default' => '8px',
        'rounded' => '16px',
    ];

    /**
     * Build a fully-populated palette from a raw site.design_kits row
     * (flat snake_case column => value; nulls/missing/empty fall back).
     *
     * @param  array<string, mixed>  $kit
     */
    public static function palette(array $kit): EmailPalette
    {
        $accent = self::pick($kit, 'color_accent', self::ACCENT);
        // color_accent_contrast and color_text_muted were dropped by the
        // preset-only migration (20260809090001) — both were INFERRED columns
        // the dispatcher computes at render time, and both held zero non-null
        // values. pick() is kept rather than replaced by the bare constant so
        // a ProfileDesignPresets overlay or a future derived column can still
        // supply one; today it always falls through.
        $accentContrast = self::pick($kit, 'color_accent_contrast', self::ACCENT_CONTRAST);

        // bg/text come from the one bleach anchor pair (the theme_mode
        // column died 2026-08-27, plan 02 — anchorsFor(null) is the bleach
        // default and the only path left).
        $anchors = ThemeModePalettes::anchorsFor(null);

        return new EmailPalette(
            accent: $accent,
            accentContrast: $accentContrast,
            bg: $anchors['bg'],
            text: $anchors['text'],
            textMuted: self::pick($kit, 'color_text_muted', self::TEXT_MUTED),
            // Derived: stored value wins, else fall back to the resolved base token.
            buttonBg: self::pick($kit, 'button_primary_bg', $accent),
            buttonText: self::pick($kit, 'button_primary_text', $accentContrast),
            borderRadius: self::radius($kit),
        );
    }

    /**
     * Corner radius for email. The kit stores a SELECTION now (`corners`:
     * default | rounded), not the raw length the dropped `border_radius`
     * column held, so the value has to be resolved through the preset table
     * rather than emitted straight into the style attribute.
     *
     * The legacy key is still read first: a caller may hand this a
     * pre-migration array (EmailBrand::fromArray hydrates from a queued job's
     * serialised payload, and a job enqueued before the deploy is drained
     * after it), and a stored length is still valid CSS. Drop the fallback
     * once no queue can carry one.
     *
     * @param  array<string, mixed>  $kit
     */
    private static function radius(array $kit): string
    {
        $legacy = $kit['border_radius'] ?? null;
        if (is_string($legacy) && trim($legacy) !== '') {
            return $legacy;
        }

        $corners = $kit['corners'] ?? null;

        return is_string($corners)
            ? (self::CORNER_RADII[$corners] ?? self::BORDER_RADIUS)
            : self::BORDER_RADIUS;
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
