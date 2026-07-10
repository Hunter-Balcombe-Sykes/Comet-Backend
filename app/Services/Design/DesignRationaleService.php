<?php

namespace App\Services\Design;

use App\Models\Core\Site\DesignKitContribution;
use Illuminate\Support\Facades\DB;

/**
 * Produces the user-facing TRANSPARENCY LINE data for a site's design — the plain-
 * language "your info sets the design, not you (unless you override)" promise made
 * legible (spec §3).
 *
 * Reads the site's DesignKitContribution rows (which record source + integration +
 * target_var per contributed column) and the manual design_kits row (non-null
 * columns = explicit user picks), and returns:
 *   { summary, hasOverrides, items: [{ area, sourceLabel, reason }] }
 *
 * SAFETY (spec §3 + §4.4): it maps each source to a FRIENDLY reason and NEVER
 * exposes a raw column name, a factor key, or any sensitive/demographic detail.
 * The aesthetic-expression source surfaces only as a neutral "your profile's
 * style" — never anything about presentation or demographics. Manual columns are
 * labelled "You set this."
 */
class DesignRationaleService
{
    /**
     * User-facing AREAS a column belongs to. Columns collapse into these five so
     * the transparency line talks about "your colours / fonts / layout", never a
     * raw variable. Any column not listed maps to the generic 'style' area.
     *
     * @var array<string, string>
     */
    private const COLUMN_AREAS = [
        'color_accent' => 'Colours',
        'text_xs' => 'Typography',
        'text_desktop_xs' => 'Typography',
        'weight_regular' => 'Typography',
        'typography_line_height' => 'Typography',
        'typography_logo_height' => 'Typography',
        'typography_font_family' => 'Typography',
        'border_thickness' => 'Layout',
        'border_radius' => 'Layout',
        'space_regular' => 'Layout',
        'space_desktop_regular' => 'Layout',
        'layout_density' => 'Layout',
        'border_style' => 'Layout',
        'motion_pace' => 'Motion',
        'effect_surface' => 'Style',
        'effect_shadow_style' => 'Style',
        'effect_link_style' => 'Style',
        'effect_button_fill' => 'Style',
        'effect_image_treatment' => 'Style',
    ];

    /**
     * Source PREFIX (the stable part before ':') → { label, reason }. The label is
     * a short provenance name; the reason is the friendly sentence. Ordered by the
     * conceptual precedence a user would expect to read them in.
     *
     * NOTE the aesthetic-expression entry is deliberately neutral — it never hints
     * at what the "style" reading was derived from.
     *
     * @var array<string, array{label: string, reason: string}>
     */
    private const SOURCE_LABELS = [
        'previous-website' => [
            'label' => 'Your previous website',
            'reason' => 'Your fonts and colours carry over from your previous website.',
        ],
        'launch-recipe' => [
            'label' => 'A curated look',
            'reason' => 'A curated look chosen to match your profile.',
        ],
        'aesthetic-expression' => [
            'label' => "Your profile's style",
            'reason' => "Tuned to your profile's style.",
        ],
        'profile' => [
            'label' => 'Your profession',
            'reason' => 'Your design reflects the profession you chose.',
        ],
        'store' => [
            'label' => 'Your store',
            'reason' => "Your layout reflects your store's price points.",
        ],
        'cuisine' => [
            'label' => 'Your menu',
            'reason' => 'Tuned to the kind of food you serve.',
        ],
        'google-business' => [
            'label' => 'Your Google Business listing',
            'reason' => 'Your design adapts from your Google Business listing.',
        ],
        'music-genre' => [
            'label' => 'Your music',
            'reason' => 'Tuned to the style of your music.',
        ],
        'instagram' => [
            'label' => 'Your Instagram',
            'reason' => 'Your colours and style adapt from your Instagram.',
        ],
        'hours-rhythm' => [
            'label' => 'Your opening hours',
            'reason' => 'Tuned to your opening hours.',
        ],
        'imagery-palette' => [
            'label' => 'Your photos',
            'reason' => 'Your colours adapt from your own photos.',
        ],
        'own-media' => [
            'label' => 'Your photos',
            'reason' => 'Your accent colour adapts from your own photos.',
        ],
        'platform-mix' => [
            'label' => 'Your linked platforms',
            'reason' => 'Your style reflects the platforms you link.',
        ],
        'outside-websites' => [
            'label' => 'Your linked websites',
            'reason' => 'Your fonts and colours adapt from the websites you link.',
        ],
    ];

    /**
     * Build the rationale structure for a site.
     *
     * @return array{
     *     summary: string,
     *     hasOverrides: bool,
     *     items: list<array{area: string, sourceLabel: string, reason: string}>
     * }
     */
    public function forSite(string $siteId): array
    {
        $manualColumns = $this->manualColumns($siteId);
        $items = $this->contributionItems($siteId, $manualColumns);

        $hasOverrides = $manualColumns !== [];
        if ($hasOverrides) {
            // Manual picks are the user's own — one line, always first.
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
     * One friendly line per SOURCE that contributed at least one column NOT
     * overridden manually. Ordered by SOURCE_LABELS declaration order (a stable,
     * user-sensible reading order), each carrying the area(s) it touched.
     *
     * @param  list<string>  $manualColumns
     * @return list<array{area: string, sourceLabel: string, reason: string}>
     */
    private function contributionItems(string $siteId, array $manualColumns): array
    {
        try {
            $rows = DesignKitContribution::query()
                ->where('site_id', $siteId)
                ->get(['source', 'target_var', 'priority']);
        } catch (\Throwable) {
            // Fail-closed to "no attributions" — a rationale read must NEVER break
            // the SiteResource response that embeds it (symmetric with presetLayer
            // / designKitVars). Also covers the SQLite mirror when a test hasn't
            // created site.design_kit_contributions.
            return [];
        }

        // For each column, only the WINNING source (highest priority; ties break
        // by source asc — mirrors the resolver) actually shows. A column overridden
        // manually shows nothing here (the manual line covers it).
        /** @var array<string, array{priority:int, source:string}> $winners */
        $winners = [];
        foreach ($rows as $row) {
            $var = (string) $row->target_var;
            if (in_array($var, $manualColumns, true)) {
                continue; // manual wins — not attributed to a factor
            }
            $current = $winners[$var] ?? null;
            $wins = $current === null
                || (int) $row->priority > $current['priority']
                || ((int) $row->priority === $current['priority'] && (string) $row->source < $current['source']);
            if ($wins) {
                $winners[$var] = ['priority' => (int) $row->priority, 'source' => (string) $row->source];
            }
        }

        // Group winning columns by source prefix → the set of areas it drives.
        /** @var array<string, array<string, true>> $sourceAreas */
        $sourceAreas = [];
        foreach ($winners as $var => $winner) {
            $prefix = $this->sourcePrefix($winner['source']);
            $area = self::COLUMN_AREAS[$var] ?? 'Style';
            $sourceAreas[$prefix][$area] = true;
        }

        // Emit in SOURCE_LABELS order so the reading order is stable + sensible.
        $items = [];
        foreach (self::SOURCE_LABELS as $prefix => $meta) {
            if (! isset($sourceAreas[$prefix])) {
                continue;
            }
            $areas = array_keys($sourceAreas[$prefix]);
            sort($areas);
            $items[] = [
                'area' => implode(' & ', $areas),
                'sourceLabel' => $meta['label'],
                'reason' => $meta['reason'],
            ];
        }

        return $items;
    }

    /**
     * The design_kits columns the user has set manually (non-null). Fail-closed to
     * "none" on any read error (SQLite test mirror has no site.design_kits table).
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

    /** The stable source prefix (before the first ':'), e.g. 'instagram:category' → 'instagram'. */
    private function sourcePrefix(string $source): string
    {
        $pos = strpos($source, ':');

        return $pos === false ? $source : substr($source, 0, $pos);
    }

    /**
     * The one-line summary the frontend renders above the editor. Reflects both
     * whether anything was auto-derived and whether the user has overridden.
     *
     * @param  list<array{area: string, sourceLabel: string, reason: string}>  $items
     */
    private function summary(array $items, bool $hasOverrides): string
    {
        // Count factor-driven items (exclude the leading manual line, if present).
        $derived = $hasOverrides ? count($items) - 1 : count($items);

        if ($derived === 0) {
            return $hasOverrides
                ? 'Your design is set from your own choices.'
                : 'Your design uses the default look — connect your profiles to tailor it automatically.';
        }

        $base = 'Your design is tailored automatically from the information on your profile.';

        return $hasOverrides
            ? $base.' Your own changes always take priority.'
            : $base;
    }
}
