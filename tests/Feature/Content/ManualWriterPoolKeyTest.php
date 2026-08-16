<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\Site;
use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Content\ManualMenuWriter;
use App\Services\Content\ManualServiceWriter;
use App\Services\Platforms\MenuProjectionMapper;
use App\Site\Pools\PoolSectionProvisioner;

// Slice 7 unit A: the writer's ONLY pool-specific value is the section key it
// provisions curation against. Threading it is what lets menus reuse this class
// instead of growing a parallel copy that drifts.
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSectionsTables();
});

function mwpkSite(): Site
{
    $site = new Site;
    $site->id = '00000000-0000-4000-a000-0000000000aa';

    return $site;
}

it('provisions curation against the pool it was constructed with', function () {
    $sections = Mockery::mock(PoolSectionProvisioner::class);
    $sections->shouldReceive('ensure')
        ->once()
        ->withArgs(fn ($site, $pool) => $pool === 'menus')
        ->andReturn((object) ['id' => '00000000-0000-4000-a000-0000000000bb']);

    $writer = new ManualMenuWriter(
        app(ProjectionWriter::class),
        $sections,
        app(ContentItemSlugAllocator::class),
        app(MenuProjectionMapper::class),
    );

    $writer->pin(mwpkSite(), '00000000-0000-4000-a000-0000000000cc', 1.0);
});

it('pins services for the services writer, so every existing caller is unchanged', function () {
    $sections = Mockery::mock(PoolSectionProvisioner::class);
    $sections->shouldReceive('ensure')
        ->once()
        ->withArgs(fn ($site, $pool) => $pool === 'services')
        ->andReturn((object) ['id' => '00000000-0000-4000-a000-0000000000bb']);

    $writer = new ManualServiceWriter(
        app(ProjectionWriter::class),
        $sections,
        app(ContentItemSlugAllocator::class),
    );

    $writer->exclude(mwpkSite(), '00000000-0000-4000-a000-0000000000cc');
});
