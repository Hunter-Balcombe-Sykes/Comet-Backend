<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

    $siteId = (string) Str::uuid();
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
    $pageId = (string) Str::uuid();
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
    $sectionId = (string) Str::uuid();
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
        'id' => (string) Str::uuid(),
        'section_id' => $sectionId,
        'item_id' => (string) Str::uuid(),
        'state' => 'pinned',
        'created_at' => now(),
    ]);

    // Create a routing source intent
    DB::table('routing.source_intents')->insert([
        'id' => (string) Str::uuid(),
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
        'id' => (string) Str::uuid(),
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
    $ingestSourceId = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $ingestSourceId,
        'user_id' => $user->id,
        'source_key' => 'instagram:testuser',
        'surface_key' => 'instagram.profile',
        'identifier' => 'testuser',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Exercises the command's `user_id` direct-column branch on a table other
    // than the ones already covered above (content.items has no other FK path
    // to the user).
    DB::table('content.items')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'kind' => 'article',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Exercises the command's `source_id`-via-`ingest.sources` branch
    // (ingest.anomalies.source_id -> ingest.sources.id, NOT content.sources).
    DB::table('ingest.anomalies')->insert([
        'id' => (string) Str::uuid(),
        'source_id' => $ingestSourceId,
        'kind' => 'drift',
        'summary' => 'test anomaly',
        'detected_at' => now(),
    ]);

    // Exercises the command's `source_id`-via-`content.sources` branch
    // (content.source_items.source_id -> content.sources.id, a DIFFERENT
    // parent than ingest.anomalies.source_id above — found in Task 1 review:
    // both were resolved against ingest.sources, making this one a no-op).
    $contentSourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $contentSourceId,
        'user_id' => $user->id,
        'kind' => 'manual',
        'priority' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(),
        'source_id' => $contentSourceId,
        'coord' => 'test-coord',
        'kind' => 'article',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    // Exercises the command's `site_id` branch on a table with NO user_id
    // column at all (site.workplaces.site_id is its primary key).
    DB::table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => 'Test Workplace',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // content.storefronts: confirmed against the live dev schema (migration
    // 20260819000100_content_storefronts_user_id.sql) to carry its own
    // NOT NULL user_id column, denormalised from content.collections.user_id —
    // it is cleaned by the command's direct `user_id` branch, not by an FK
    // cascade from content.collections as previously assumed. Seeded here
    // (with a parent collection row) so that is exercised too.
    $collectionId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $collectionId,
        'user_id' => $user->id,
        'label' => 'Test Storefront Collection',
        'kind' => 'storefront',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('content.storefronts')->insert([
        'collection_id' => $collectionId,
        'provider' => 'shopify',
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create a pre-account build for rediscovery
    $buildId = (string) Str::uuid();
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
        'id' => (string) Str::uuid(),
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

    // ingest.sources rows themselves get wiped by the reset (they have a
    // direct user_id column), so the ids that ingest.anomalies.source_id
    // points at must be captured BEFORE the command runs — the source_id
    // branch's join target won't exist to query afterward.
    $aSourceId = DB::table('ingest.sources')->where('user_id', $a->id)->value('id');
    $bSourceId = DB::table('ingest.sources')->where('user_id', $b->id)->value('id');
    $aContentSourceId = DB::table('content.sources')->where('user_id', $a->id)->value('id');
    $bContentSourceId = DB::table('content.sources')->where('user_id', $b->id)->value('id');

    $this->artisan('setup:reset', ['user' => $a->handle, '--yes' => true, '--rediscover' => true])->assertSuccessful();

    // Check user-scoped tables are cleared for $a but not $b. content.items
    // and content.storefronts both exercise the command's direct `user_id`
    // branch (storefronts carries its own NOT NULL user_id since migration
    // 20260819000100 — confirmed against live dev schema — not merely an FK
    // cascade target from content.collections).
    foreach (['site.platform_connections', 'routing.source_intents', 'ingest.sources', 'content.items', 'content.storefronts'] as $t) {
        expect(DB::table($t)->where('user_id', $a->id)->count())->toBe(0, $t)
            ->and(DB::table($t)->where('user_id', $b->id)->count())->toBeGreaterThan(0, $t);
    }

    // ingest.anomalies has no user_id/site_id/build_id column — it is only
    // reached via the command's `source_id`-via-`ingest.sources` branch.
    expect(DB::table('ingest.anomalies')->where('source_id', $aSourceId)->count())->toBe(0)
        ->and(DB::table('ingest.anomalies')->where('source_id', $bSourceId)->count())->toBeGreaterThan(0);

    // content.source_items has no user_id/site_id/build_id column — it is only
    // reached via the command's `source_id`-via-`content.sources` branch,
    // which must resolve against content.sources, NOT ingest.sources.
    expect(DB::table('content.source_items')->where('source_id', $aContentSourceId)->count())->toBe(0)
        ->and(DB::table('content.source_items')->where('source_id', $bContentSourceId)->count())->toBeGreaterThan(0);

    // site.workplaces has no user_id column at all — site_id is its PRIMARY
    // KEY, so this exercises the command's `site_id`-only branch.
    expect(DB::table('site.workplaces')->where('site_id', $a->site->id)->count())->toBe(0)
        ->and(DB::table('site.workplaces')->where('site_id', $b->site->id)->count())->toBeGreaterThan(0);

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
    Queue::assertPushed(GeneratePreAccountSiteJob::class);
});

it('resolves the user argument by primary_email, not a nonexistent email column', function () {
    $user = seededSetupUser('emaillookup');

    $this->artisan('setup:reset', ['user' => $user->primary_email, '--yes' => true])->assertSuccessful();

    expect(DB::table('site.platform_connections')->where('user_id', $user->id)->count())->toBe(0);
});
