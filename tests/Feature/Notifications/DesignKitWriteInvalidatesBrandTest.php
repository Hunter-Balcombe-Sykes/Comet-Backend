<?php

use App\Mail\Branding\ProEmailBrandResolver;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
    setupSubdomainAliasesTable();
    setupMediaTables();
    setupBlocksTable();
});

it('reflects a design-kit-only update in the next resolved brand', function () {
    $user = User::factory()->create([
        'display_name' => 'Jane Doe', 'handle' => 'jane', 'handle_lc' => 'jane',
    ]);
    $site = Site::factory()->create(['user_id' => $user->id]);

    // The production DB has trg_create_empty_design_kit which inserts an empty
    // row on site creation. SQLite tests don't run that trigger, so we seed it
    // manually to ensure the kit update hits a row.
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    // Prime the brand cache with the default accent.
    $first = app(ProEmailBrandResolver::class)->forSite($site->id);
    expect($first->palette->accent)->toBe('#3a6efc');

    // Simulate the controller's design-kit write path + the post-write bust.
    DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $site->id)->update(['color_accent' => '#aa0000']);
    app(\App\Services\Cache\SiteCacheService::class)->invalidateSite($site->fresh());

    $second = app(ProEmailBrandResolver::class)->forSite($site->id);
    expect($second->palette->accent)->toBe('#aa0000');
});
