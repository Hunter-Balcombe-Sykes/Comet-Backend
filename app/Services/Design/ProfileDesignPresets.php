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
