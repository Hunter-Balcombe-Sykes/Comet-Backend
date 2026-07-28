<?php

namespace App\Ingest\Projection;

/**
 * Landed menu item (Square / Uber Eats / DoorDash — one shared doc shape,
 * see App\Ingest\Support\MenuRecords) → the `menu_item` kind. The category
 * lands as a tag (tag_type 'category') — the cross-source category grouping
 * lives in collections (§6), which read exactly this tag, and the position
 * keeps the vendor's stable ordering available to the menu projector logic.
 */
class MenuItemProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'menu_item';
    }

    public function project(RecordView $view): ?array
    {
        $name = $view->string('name');
        if ($name === null) {
            return null;
        }

        $price = $view->float('price');

        return [
            'kind' => self::kind(),
            'headline' => $name,
            'facets' => array_filter([
                'f_text' => $view->string('description') === null ? null : ['body' => $view->string('description')],
            ]),
            'offers' => $price === null ? [] : [[
                'amount_minor' => (int) round($price * 100),
                'currency' => $view->string('currency'),
                'qualifier' => $price === 0.0 ? 'free' : 'exact',
            ]],
            'tags' => $view->string('category') === null ? [] : [
                ['tag' => $view->string('category'), 'tag_type' => 'category'],
            ],
            'media' => $view->string('image') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('image')],
            ],
        ];
    }
}
