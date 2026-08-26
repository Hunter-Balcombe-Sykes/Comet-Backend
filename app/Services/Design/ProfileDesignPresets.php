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
     * design_kits columns a profile preset may set. The user's own manual pick
     * always wins, per the universal manual-over-preset rule.
     *
     * This is the PERMISSION surface, not the content: it lists every column a
     * preset is allowed to write. The selections are listed so a sector
     * differentiation is a data change in SectorStylePresets rather than an
     * edit here.
     *
     * Rewritten 2026-08-09 for the preset-only schema (20260809090001). Every
     * column that left the table left this list with it: text_body,
     * text_desktop_body, weight_regular, weight_heading,
     * typography_line_height, typography_logo_height, border_radius,
     * space_regular, space_desktop_regular, layout_density and the three
     * effect_* axes. border_thickness survived to 2026-08-27, then died with
     * theme_mode and theme_night_shift_auto (plan 02).
     *
     * (layout_density and space_desktop_regular were listed as "orphaned" in
     * the go-live brief §8.3. They were not — this list and
     * DesignRationaleService::COLUMN_AREAS both referenced them, and
     * layout_density in particular has never had a `layout` entry in the
     * config prefix map, so it was reachable by a preset yet invisible in the
     * public payload. Both go now, deliberately.)
     *
     * Narrowed 2026-08-06 by the design-kit simplification: theme_contrast,
     * typography_tracking, typography_uppercase, motion_pace and border_style
     * left the schema entirely. theme_mode KEPT its column but lost its
     * roster — 'bleach' is the only legal value, so presetting it could only
     * ever write the default, and it dropped off this list then.
     *
     * @var list<string>
     */
    private const TARGETABLE = [
        'color_accent',
        'typography_font_family',
        'text_size',
        'spacing',
        'corners',
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
