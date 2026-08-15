<?php

use App\Ingest\Projection\MenuItemProjector;
use App\Ingest\Projection\RecordView;
use App\Services\Platforms\MenuProjectionMapper;

// Slice 4 Unit 4: the projector has to emit `collections`, not just a category
// tag, because IdentityKeyDeriver derives offering_name_in_category from the
// collections entries — and that key is what makes short dish names mergeable
// across platforms at all.

function menuRecord(array $doc): RecordView
{
    return new RecordView($doc, 'rec-1');
}

it('emits the category as a collection, not only as a tag', function () {
    $projection = (new MenuItemProjector)->project(menuRecord([
        'name' => 'Fries', 'category' => 'Sides', 'price' => 4.5, 'currency' => 'AUD', 'position' => 3,
    ]));

    expect($projection['collections'])->toBe([[
        'kind' => 'menu_category',
        'external_ref' => 'menu:sides',
        'label' => 'Sides',
        'position' => 3,
    ]]);
});

it('agrees with the backfiller about a category ref, or the two mint duplicates', function () {
    // The backfilled menu and the scraped one describe the SAME category. If
    // the two derive external_ref differently, the collections upsert's natural
    // key (user_id, kind, external_ref) sees two rows and the owner gets the
    // category twice — once from each lane.
    $fromProjector = (new MenuItemProjector)->project(menuRecord([
        'name' => 'Fries', 'category' => 'Sides & Snacks',
    ]))['collections'][0];

    $fromBackfill = MenuProjectionMapper::categoryRef('Sides & Snacks');

    expect($fromProjector['external_ref'])->toBe($fromBackfill);
});

it('still emits the category tag SectionCandidates reads', function () {
    $projection = (new MenuItemProjector)->project(menuRecord(['name' => 'Fries', 'category' => 'Sides']));

    expect($projection['tags'])->toContain(['tag' => 'Sides', 'tag_type' => 'category']);
});

it('emits no collection for a dish with no category', function () {
    $projection = (new MenuItemProjector)->project(menuRecord(['name' => 'Fries']));

    expect($projection['collections'])->toBe([])
        ->and($projection['tags'])->toBe([]);
});

it('falls back to a hash when a category label slugifies to nothing', function () {
    // An all-emoji or all-punctuation category name would slug to '', and
    // 'menu:' as a shared ref would fold every such category into one.
    $projection = (new MenuItemProjector)->project(menuRecord(['name' => 'Fries', 'category' => '🔥🔥']));

    expect($projection['collections'][0]['external_ref'])
        ->toBe(MenuProjectionMapper::categoryRef('🔥🔥'))
        ->not->toBe('menu:');
});

it('bumps its version, because the projection shape changed', function () {
    // A projector version is how a rebuild knows a landed row predates the
    // current mapping. The collections key is a shape change, not a fix.
    expect(MenuItemProjector::version())->toBe(2);
});
