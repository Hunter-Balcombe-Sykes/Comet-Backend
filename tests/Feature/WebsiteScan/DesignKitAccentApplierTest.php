<?php

use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\WebsiteScan\DesignKitAccentApplier;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
});

it('fills color_accent only when the row has none yet', function () {
    $site = Site::factory()->create();
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    app(DesignKitAccentApplier::class)->apply((string) $site->id, '#ff5500');

    $row = DB::connection('pgsql')->table('site.design_kits')->where('site_id', $site->id)->first();
    expect($row->color_accent)->toBe('#ff5500');
});

it('never overwrites an existing accent colour', function () {
    $site = Site::factory()->create();
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id, 'color_accent' => '#000000']);

    app(DesignKitAccentApplier::class)->apply((string) $site->id, '#ff5500');

    $row = DB::connection('pgsql')->table('site.design_kits')->where('site_id', $site->id)->first();
    expect($row->color_accent)->toBe('#000000');
});

it('creates the design_kits row if none exists yet', function () {
    $site = Site::factory()->create();

    app(DesignKitAccentApplier::class)->apply((string) $site->id, '#ff5500');

    $row = DB::connection('pgsql')->table('site.design_kits')->where('site_id', $site->id)->first();
    expect($row->color_accent)->toBe('#ff5500');
});

it('does nothing when given a null hex', function () {
    $site = Site::factory()->create();
    app(DesignKitAccentApplier::class)->apply((string) $site->id, null);
    expect(DB::connection('pgsql')->table('site.design_kits')->where('site_id', $site->id)->exists())->toBeFalse();
});

it('propagates cache invalidation via SiteCacheInvalidator::touchSite only when a write actually happened', function () {
    $siteWithExisting = Site::factory()->create();
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $siteWithExisting->id, 'color_accent' => '#000000']);

    $invalidator = Mockery::mock(SiteCacheInvalidator::class);
    $invalidator->shouldNotReceive('touchSite');

    (new DesignKitAccentApplier($invalidator))->apply((string) $siteWithExisting->id, '#ff5500'); // no-op, already set
});

it('touches the site with a resolvable closure and the right reason when a write did happen', function () {
    $site = Site::factory()->create();

    $invalidator = Mockery::mock(SiteCacheInvalidator::class);
    $invalidator->shouldReceive('touchSite')
        ->once()
        ->withArgs(function ($siteResolver, $reason, $context) use ($site) {
            return $siteResolver() instanceof Site
                && $siteResolver()->id === $site->id
                && $reason === 'website-accent-scan'
                && $context === ['site_id' => (string) $site->id];
        });

    (new DesignKitAccentApplier($invalidator))->apply((string) $site->id, '#ff5500');
});
