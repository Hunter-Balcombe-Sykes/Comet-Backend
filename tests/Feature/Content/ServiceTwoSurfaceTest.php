<?php

use App\Models\Core\Site\Site;
use App\Services\Content\FreshaServiceItems;
use App\Services\Content\ManualServiceItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 3b Task 13: the two-surface rule pins WHERE a service-kind content
// item is allowed to render. ManualServiceItems (content.sources.kind =
// 'manual') feeds the public services section; FreshaServiceItems
// (kind = 'connection') feeds the booking surface. A Fresha-scraped row
// leaking into the services section, or an owner-authored row leaking onto
// the booking surface, is the exact bug the split exists to prevent — these
// tests fail if either reader's scoping is ever widened onto the other
// source kind.
//
// Mutation-checked 2026-08-13: scoping FreshaServiceItems::rows() to
// cs.kind = 'manual' (instead of 'connection') turned the booking-surface
// case below RED on both its assertions — the positive control stopped
// seeing the Fresha item, and the negative assertion started seeing the
// owner-authored one. The services-section case stayed green (it reads
// ManualServiceItems, untouched by that mutation) — confirming the guard
// targets the surface it names. Restored byte-identically afterward, diffed
// against a pre-edit backup. See task-13-report.md for the full log.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

/**
 * A service-kind content item landed by a CONNECTION source — what 3b's
 * Fresha connector produces. Written directly (mirrors
 * ServicesPublicReadTest.php's file-local freshaServiceItem(), duplicated
 * here under a distinct name rather than shared: two Pest test files
 * declaring the same global function name is a fatal redeclaration the
 * moment both load in one process, and this task may not edit tests/Pest.php
 * to promote a shared helper).
 */
function twoSurfaceFreshaService(string $userId, string $title): string
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();

    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'fresha:store:s:'.random_int(100000, 999999),
        'record_key' => 's:'.random_int(100000, 999999),
        'item_id' => $itemId, 'kind' => 'service',
        'projector_version' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    return $itemId;
}

/**
 * A tenant with ONE owner-authored (manual) service, landed through the real
 * manual lane (ownerServiceItem() — the same path ServicesPublicReadTest.php
 * uses), and ONE Fresha-landed service on the same user. Both surfaces get
 * read against this single fixture so a mutation to either reader's scoping is
 * guaranteed to touch a live row.
 *
 * FIXTURE ONLY — not one assertion in this file changed. It used to build the
 * manual half as `ownerService() + ServiceBackfiller::run()`: a site.services
 * row migrated into content.*. The services cutover dropped that table and
 * retired the backfiller, so the item is written directly through
 * ManualServiceWriter — the same collaborator the backfiller called per row.
 *
 * @return array{0: string, 1: Site, 2: string, 3: string} [userId, site, manualTitle, freshaTitle]
 */
function twoSurfaceFixture(): array
{
    [$userId, $siteId] = seedUserWithSite();
    $manualTitle = 'Owner Haircut '.Str::lower(Str::random(6));
    $freshaTitle = 'Fresha Colour '.Str::lower(Str::random(6));

    ownerServiceItem($userId, ['title' => $manualTitle]);
    twoSurfaceFreshaService($userId, $freshaTitle);

    $site = Site::query()->findOrFail($siteId);

    return [$userId, $site, $manualTitle, $freshaTitle];
}

it('never renders a Fresha service in the public services section', function () {
    [$userId, $site, $manualTitle, $freshaTitle] = twoSurfaceFixture();

    $titles = collect(app(ManualServiceItems::class)->publicList($userId, $site))->pluck('title');

    // Positive control: the services section is not simply empty — it does
    // show the owner-authored row that belongs there.
    expect($titles)->toContain($manualTitle)
        ->and($titles)->not->toContain($freshaTitle);
});

it('never renders an owner-authored service on the booking surface', function () {
    [$userId, $site, $manualTitle, $freshaTitle] = twoSurfaceFixture();

    $names = collect(app(FreshaServiceItems::class)->selectionServices($userId))->pluck('name');

    // Positive control: the booking surface is not simply empty — it does
    // show the Fresha-landed row that belongs there.
    expect($names)->toContain($freshaTitle)
        ->and($names)->not->toContain($manualTitle);
});
