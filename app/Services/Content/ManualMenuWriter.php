<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Services\Platforms\MenuProjectionMapper;
use App\Site\Pools\PoolSectionProvisioner;

/**
 * Slice 7 unit A: the menus twin of the services writer. Both extend
 * ManualPoolWriter, which owns every kind-agnostic method; neither inherits the
 * other's projection mapping, which is the point — an earlier draft had this
 * subclass ManualServiceWriter directly and PHPStan caught it, but the type
 * error was the smaller half: it would also have inherited a projectionFor()
 * hardcoding 'kind' => 'service'.
 *
 * The mapping is MenuProjectionMapper, unchanged and un-wrapped, because slice
 * 4's backfill used it for all 318 existing dishes. A second derivation of
 * either the projection or the coord would mint a duplicate content item for
 * every one of them — which is why coordFor() delegates to the mapper's static
 * rather than re-implementing three lines of hashing.
 */
class ManualMenuWriter extends ManualPoolWriter
{
    public function __construct(
        ProjectionWriter $writer,
        PoolSectionProvisioner $sections,
        ContentItemSlugAllocator $slugs,
        private readonly MenuProjectionMapper $mapper,
    ) {
        parent::__construct($writer, $sections, $slugs, 'menus');
    }

    /** The slice-4 coord: menu-scoped, name-derived. See MenuProjectionMapper. */
    public function coordFor(string $menuId, string $name): string
    {
        return MenuProjectionMapper::coordFor($menuId, $name);
    }

    /**
     * @param  list<array{id: string, name: string, position: int}>  $categories
     * @param  list<object>  $platformRows  site.menu_item_platforms-shaped rows
     * @return array<string, mixed>
     */
    public function projectionFor(object $dish, array $categories = [], array $platformRows = [], ?object $menu = null): array
    {
        return $this->mapper->project($dish, $categories, $platformRows, $menu ?? (object) []);
    }
}
