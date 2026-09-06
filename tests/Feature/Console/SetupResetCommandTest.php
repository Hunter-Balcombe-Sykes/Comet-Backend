<?php

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    setupIngestTables();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    Queue::fake();
});

/**
 * Create a test user with a populated setup state, including:
 * - site with setup_step populated
 * - discovery/setup rows (platform_connections, source_intents, ingest.sources, etc.)
 * - a pre-account build to copy source from
 */
function seededSetupUser(string $handle): User
{
    // Create a basic user and site
    $user = User::factory()->create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'account_type' => 'partna',
        'status' => 'active',
    ]);

    $siteId = (string) \Illuminate\Support\Str::uuid();
    DB::table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => $handle,
        'is_published' => true,
        'settings' => json_encode([]),
        'setup_step' => 'platforms.social',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create a page for sections to attach to
    $pageId = (string) \Illuminate\Support\Str::uuid();
    DB::table('site.pages')->insert([
        'id' => $pageId,
        'site_id' => $siteId,
        'key' => 'home',
        'label' => 'Home',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create section and section_items
    $sectionId = (string) \Illuminate\Support\Str::uuid();
    DB::table('site.sections')->insert([
        'id' => $sectionId,
        'page_id' => $pageId,
        'site_id' => $siteId,
        'key' => 'social',
        'label' => 'Social',
        'kind' => 'collection',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('site.section_items')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'section_id' => $sectionId,
        'item_id' => (string) \Illuminate\Support\Str::uuid(),
        'state' => 'pinned',
        'created_at' => now(),
    ]);

    // Create a routing source intent
    DB::table('routing.source_intents')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'identifier' => 'testuser',
        'canonical_url' => 'https://instagram.com/testuser',
        'state' => 'proposed',
        'origin' => 'link_in_bio',
        'band' => 'auto',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create an integration connection (platform_connections)
    DB::table('site.platform_connections')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'resource_id' => 'testuser',
        'payload' => json_encode([]),
        'is_active' => true,
        'visibility' => 'visible',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create an ingest source with correct columns
    DB::table('ingest.sources')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'source_key' => 'instagram:testuser',
        'surface_key' => 'instagram.profile',
        'identifier' => 'testuser',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create a pre-account build for rediscovery
    $buildId = (string) \Illuminate\Support\Str::uuid();
    DB::table('core.pre_account_builds')->insert([
        'id' => $buildId,
        'user_id' => $user->id,
        'source_type' => 'instagram',
        'source_ref' => 'testuser',
        'source_ref_lc' => 'testuser',
        'source_name' => 'Test User',
        'built_via' => 'signup',
        'build_state' => 'ready',
        'claimed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create a pre-account build event for tracking
    DB::table('core.pre_account_build_events')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'build_id' => $buildId,
        'stage' => 'platforms',
        'status' => 'landed',
        'label' => 'Links routed',
        'payload' => json_encode([]),
        'created_at' => now(),
    ]);

    return $user->fresh()->load('site');
}

it('clears discovery state for one user and leaves another untouched', function () {
    [$a, $b] = [seededSetupUser('alpha'), seededSetupUser('beta')];

    $this->artisan('setup:reset', ['user' => $a->handle, '--yes' => true, '--rediscover' => true])->assertSuccessful();

    // Check user-scoped tables are cleared for $a but not $b
    foreach (['site.platform_connections', 'routing.source_intents', 'ingest.sources'] as $t) {
        expect(DB::table($t)->where('user_id', $a->id)->count())->toBe(0, $t)
            ->and(DB::table($t)->where('user_id', $b->id)->count())->toBeGreaterThan(0, $t);
    }

    // Check pre_account_build_events via build relationship
    $aBuildIds = DB::table('core.pre_account_builds')->where('user_id', $a->id)->pluck('id')->all();
    $bBuildIds = DB::table('core.pre_account_builds')->where('user_id', $b->id)->pluck('id')->all();
    if (count($aBuildIds) > 0) {
        expect(DB::table('core.pre_account_build_events')->whereIn('build_id', $aBuildIds)->count())->toBe(0);
    }
    if (count($bBuildIds) > 0) {
        expect(DB::table('core.pre_account_build_events')->whereIn('build_id', $bBuildIds)->count())->toBeGreaterThan(0);
    }

    // site.section_items is reached through site.sections.site_id
    $aSiteId = $a->site->id;
    $bSiteId = $b->site->id;
    $aItemCount = DB::table('site.section_items')
        ->whereIn('section_id', DB::table('site.sections')->where('site_id', $aSiteId)->pluck('id'))
        ->count();
    $bItemCount = DB::table('site.section_items')
        ->whereIn('section_id', DB::table('site.sections')->where('site_id', $bSiteId)->pluck('id'))
        ->count();
    expect($aItemCount)->toBe(0)
        ->and($bItemCount)->toBeGreaterThan(0);

    // Check setup_step is cleared
    expect($a->site->fresh()->setup_step)->toBeNull();

    // Check rediscovery created an unclaimed pre-account build
    $unclaimedBuilds = DB::table('core.pre_account_builds')->where('user_id', $a->id)->whereNull('claimed_at')->get();
    expect($unclaimedBuilds)->toHaveCount(1);

    // Check the job was dispatched
    Queue::assertPushed(\App\Jobs\PreAccount\GeneratePreAccountSiteJob::class);
});
