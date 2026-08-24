<?php

namespace App\Services\Analytics;

/**
 * The item-scoring vocabulary (smart ordering v2, 2026-08-23): which
 * content_popularity_scores family a content.items.kind scores in, and the
 * per-family signal weights (config/partna.php `pools.smart`).
 *
 *   score(item) = Σ_days (w_click·clicks_d + w_view·views_d + w_dwell·dwell_s_d) · 2^(−age_d/90)
 *               + w_fresh · 2^(−ageSince(publishedAt ?? firstSeenAt) / half_life_days)
 *
 * Events never score (occurrence order is the only honest order) and
 * reviews never rank, so neither kind has a family here. Every family is
 * keyed by content.items.id.
 */
final class ItemFamily
{
    /** content.items.kind => scoring family. */
    public const KIND_TO_FAMILY = [
        'product' => 'shop_product',
        'link' => 'link_item',
        'video' => 'watch_item',
        'track' => 'listen_item',
        'release' => 'listen_item',
        'episode' => 'listen_item',
        'menu_item' => 'menu_item',
        'service' => 'service',
        'media' => 'gallery_item',
    ];

    /** Category families: score = Σ member item scores, keyed by collection id. */
    public const CATEGORY_FAMILIES = ['menus' => 'menu_category', 'services' => 'service_category'];

    public static function forKind(string $kind): ?string
    {
        return self::KIND_TO_FAMILY[$kind] ?? null;
    }

    /**
     * Signal weights for one family; the `default` row covers any family
     * the table does not name (today's 3 / 1 / 0 / 0).
     *
     * @return array{click: float, view: float, dwell: float, fresh: float, half_life_days: float}
     */
    public static function weightsFor(string $family): array
    {
        $table = (array) config('partna.pools.smart', []);
        $row = (array) ($table[$family] ?? $table['default'] ?? []);

        return [
            'click' => (float) ($row['click'] ?? 3.0),
            'view' => (float) ($row['view'] ?? 1.0),
            'dwell' => (float) ($row['dwell'] ?? 0.0),
            'fresh' => (float) ($row['fresh'] ?? 0.0),
            'half_life_days' => (float) ($row['half_life_days'] ?? 14.0),
        ];
    }
}
