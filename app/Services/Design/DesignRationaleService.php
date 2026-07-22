<?php

namespace App\Services\Design;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

/**
 * Produces the user-facing TRANSPARENCY LINE data for a site's design — the
 * plain-language "your profile sets the design, not you (unless you
 * override)" promise made legible.
 *
 * Computes the profile preset overlay (ProfileDesignPresets) at read time,
 * subtracts manually-set design_kits columns (manual wins), and returns:
 *   { summary, hasOverrides, items: [{ area, sourceLabel, reason }] }
 *
 * SAFETY: never exposes a raw column name or field value — columns collapse
 * into friendly AREAS and the one auto-source surfaces as "Your industry".
 * Wire shape is FROZEN (frontend renders it verbatim).
 */
class DesignRationaleService
{
    /**
     * User-facing AREAS a targetable column belongs to. Any column not
     * listed maps to the generic 'Style' area.
     *
     * @var array<string, string>
     */
    private const COLUMN_AREAS = [
        'theme_mode' => 'Colours',
        'color_accent' => 'Colours',
        'theme_contrast' => 'Colours',
        'text_body' => 'Typography',
        'text_desktop_body' => 'Typography',
        'weight_regular' => 'Typography',
        'weight_heading' => 'Typography',
        'typography_line_height' => 'Typography',
        'typography_logo_height' => 'Typography',
        'typography_font_family' => 'Typography',
        'typography_uppercase' => 'Typography',
        'typography_tracking' => 'Typography',
        'border_thickness' => 'Layout',
        'border_radius' => 'Layout',
        'space_regular' => 'Layout',
        'space_desktop_regular' => 'Layout',
        'layout_density' => 'Layout',
        'border_style' => 'Layout',
        'motion_pace' => 'Motion',
        'effect_shadow_style' => 'Style',
        'effect_link_style' => 'Style',
        'effect_image_treatment' => 'Style',
    ];

    /**
     * @return array{
     *     summary: string,
     *     hasOverrides: bool,
     *     items: list<array{area: string, sourceLabel: string, reason: string}>
     * }
     */
    public function forSite(string $siteId, ?string $userId = null): array
    {
        $manualColumns = $this->manualColumns($siteId);
        $items = [];

        $user = $userId !== null ? User::query()->find($userId) : null;
        $preset = ProfileDesignPresets::forUser($user);
        $surviving = array_diff_key($preset, array_flip($manualColumns));

        if ($surviving !== []) {
            $areas = array_values(array_unique(array_map(
                static fn (string $col): string => self::COLUMN_AREAS[$col] ?? 'Style',
                array_keys($surviving),
            )));
            sort($areas);
            $items[] = [
                'area' => implode(' & ', $areas),
                'sourceLabel' => 'Your industry',
                'reason' => 'Your design reflects the industry you chose.',
            ];
        }

        $hasOverrides = $manualColumns !== [];
        if ($hasOverrides) {
            array_unshift($items, [
                'area' => 'Your changes',
                'sourceLabel' => 'You',
                'reason' => 'You set this.',
            ]);
        }

        return [
            'summary' => $this->summary($items, $hasOverrides),
            'hasOverrides' => $hasOverrides,
            'items' => $items,
        ];
    }

    /**
     * The design_kits columns the user has set manually (non-null).
     * Fail-closed to "none" on any read error (SQLite test mirror may lack
     * site.design_kits) — a rationale read must never break the SiteResource
     * response embedding it.
     *
     * @return list<string>
     */
    private function manualColumns(string $siteId): array
    {
        try {
            $row = DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->first();
        } catch (\Throwable) {
            return [];
        }

        if ($row === null) {
            return [];
        }

        $vars = (array) $row;
        unset($vars['site_id'], $vars['created_at'], $vars['updated_at']);

        return array_keys(array_filter($vars, static fn ($v): bool => $v !== null));
    }

    /** @param  list<array{area: string, sourceLabel: string, reason: string}>  $items */
    private function summary(array $items, bool $hasOverrides): string
    {
        $derived = $hasOverrides ? count($items) - 1 : count($items);

        if ($derived === 0) {
            return $hasOverrides
                ? 'Your design is set from your own choices.'
                : 'Your design uses the default look — set your industry to tailor it automatically.';
        }

        $base = 'Your design is tailored automatically from the information on your profile.';

        return $hasOverrides
            ? $base.' Your own changes always take priority.'
            : $base;
    }
}
