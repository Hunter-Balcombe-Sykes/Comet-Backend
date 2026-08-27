<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Plan 05 pass 6: the dev-insights breakdown showed 0.00 freshness for every
// event — event_item's config fresh weight is 0.0, so ContentFreshness yields
// nothing for the family, and the controller never overlaid the
// EventTimeRelevance term the scoring job actually uses. The one surface
// built to explain "why is this first" was blind for the family that got the
// most scoring work. The controller now mirrors the job's overlay.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();
    setupSectionsTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    setupServicesTable();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupItemViewsTable();
    setupActionEventsTable();
    setupContentPopularityScoresTable();
});

it('shows the time-relevance term as an upcoming event\'s freshness in the score breakdown', function () {
    $tenant = createTenant('dev-insights-evt');
    $source = poolSource($tenant->id, null);

    $soon = poolItem($tenant->id, $source, 'event', 'Gig tomorrow', now()->addDay()->toISOString());
    $far = poolItem($tenant->id, $source, 'event', 'Gig next year', now()->addDays(300)->toISOString());
    foreach ([[$soon, now()->addDay()], [$far, now()->addDays(300)]] as [$id, $at]) {
        DB::connection('pgsql')->table('content.f_occurrence')->insert([
            'item_id' => $id,
            'source_id' => $source,
            'starts_at_local' => $at->toDateTimeString(),
            'starts_at_utc' => $at->toISOString(),
            'updated_at' => now(),
        ]);
        DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
            'id' => (string) Str::uuid(), 'site_id' => $tenant->site->id,
            'content_type' => 'event_item', 'content_key' => $id,
            'score' => 1.0, 'rank' => 1, 'computed_at' => now(),
        ]);
    }

    $rows = collect(actingAsUser($tenant)->getJson('/api/professional/dev-insights')
        ->assertOk()
        ->json('items.event_item'));

    $soonRow = $rows->firstWhere('content_key', $soon);
    $farRow = $rows->firstWhere('content_key', $far);

    // Tomorrow's event carries a near-peak relevance term; one 300 days out
    // has faded to nothing — exactly the numbers the scoring job used, where
    // the pre-fix payload read 0.00 for both.
    expect((float) $soonRow['freshness'])->toBeGreaterThan(0.0)
        ->and((float) $farRow['freshness'])->toBe(0.0);
});
