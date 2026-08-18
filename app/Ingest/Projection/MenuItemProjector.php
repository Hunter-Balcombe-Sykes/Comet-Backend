<?php

namespace App\Ingest\Projection;

use App\Services\Platforms\MenuProjectionMapper;

/**
 * Landed menu item (Square / Uber Eats / DoorDash — one shared doc shape,
 * see App\Ingest\Support\MenuRecords) → the `menu_item` kind.
 *
 * The category lands BOTH as a tag (tag_type 'category', which is what
 * SectionCandidates reads) and as a `collections` entry. The second is not
 * decoration: IdentityKeyDeriver derives `offering_name_in_category` —
 * norm(category)|norm(dish), minLength 5 — from the collections entries, and
 * that key is the only thing that makes short dish names ("Fries", "Cola")
 * mergeable across platforms at all. Bare `offering_name` has minLength 8 and
 * drops them. Emitting the tag alone would leave cross-platform merging
 * silently dead for exactly the dishes most likely to be sold on two
 * platforms.
 *
 * external_ref comes from MenuProjectionMapper::categoryRef() rather than
 * being derived here, so this lane and the slice-4 backfill cannot disagree
 * about what identifies a category — two derivations would hand the owner
 * their categories twice, once per lane. That method's docblock carries why
 * it keys on the label.
 */
class MenuItemProjector implements Projector
{
    public static function version(): int
    {
        // 2 (slice 4): the projection gained `collections`. A version bump is
        // how a rebuild knows a landed row predates the current mapping —
        // every row projected at v1 carries a category tag and no collection,
        // so its identity keys are missing offering_name_in_category.
        return 2;
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
        $category = $view->string('category');

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
            'tags' => $category === null ? [] : [
                ['tag' => $category, 'tag_type' => 'category'],
            ],
            'media' => $view->string('image') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('image')],
            ],
            // position (the category's rank) and item_position (the dish's
            // rank inside it) are SEEDS — ProjectionWriter writes them on
            // insert and never overwrites an owner's reorder (F30/F31,
            // 2026-08-18). Older records carry only `position` as a running
            // menu-wide index, which still orders categories correctly.
            'collections' => $category === null ? [] : [[
                'kind' => 'menu_category',
                'external_ref' => MenuProjectionMapper::categoryRef($category),
                'label' => $category,
                'position' => $view->int('category_position') ?? $view->int('position') ?? 0,
                'item_position' => $view->int('position') ?? 0,
            ]],
        ];
    }
}
