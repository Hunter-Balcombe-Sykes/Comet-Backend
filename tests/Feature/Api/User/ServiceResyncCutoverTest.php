<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Content\ManualServiceItems;
use App\Services\Content\ServiceCollections;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 3b Task 10: the last three service routes move onto content.*.
//
//  - resync/resyncBulk: an owner edit IS a content.manual_overrides row, so
//    "revert to the synced version" is DELETING those rows — the per-source
//    facet values the connector wrote are already there and become visible
//    again immediately. The legacy 422 ("no longer offered on Fresha") maps
//    to "the item has no LIVE content.source_items row on a connection
//    source".
//  - updateCategory: 3a denied anything not source='fresha' as 404 because
//    content.* had no membership concept. It has one now (content.collections
//    / content.collection_items, Task 8), so the gate comes off.
//
// The coupling those two halves share is the whole point of this file:
// ManualServiceItems::publicList() hardcoded 'category' => 'Services' for
// every row, and ServicePolicy::updateCategory()'s docblock said that
// constant was "only honest while this restriction holds". Both move here or
// neither does — a page that labels every category "Services" while the
// dashboard says otherwise is exactly the silent divergence slice 3a's
// gate existed to prevent.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupBlocksTable();
    // C1's cross-surface case drives the staff grouped list too — the whole
    // point is that the two dashboards agree, which cannot be asserted from
    // one of them.
    setupPartnaStaffTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();

    // staff.audit middleware writes here on every staff request.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\',
        status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\',
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

/** A professional with a site, resolved with the site relation the controller reads. */
function serviceResyncUser(): array
{
    [$userId, $siteId] = seedUserWithSite();

    return [User::query()->with('site')->findOrFail($userId), $siteId];
}

/**
 * The user's single content.sources row for a Fresha connection
 * (kind = 'connection') — the OTHER side of the two-surface split from
 * ManualServiceItems' kind = 'manual'. Named uniquely: Pest parses every
 * sibling test file under a --filter scan, and Task 12's
 * FreshaBookingSurfaceTest already declares a file-scope helper for this.
 */
function serviceResyncConnectionSource(string $userId): string
{
    $id = (string) Str::uuid();

    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => null, 'label' => 'Fresha', 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Lands one Fresha-shaped service item into content.*, the shape
 * FreshaServiceProjector + ProjectionWriter produce — built by hand so this
 * file stays independent of the connector tasks landing in the same wave.
 */
function serviceResyncFreshaItem(string $userId, string $sourceId, string $title = 'Standard Haircut'): string
{
    $itemId = addItem($userId, 'service', $title);

    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'fresha:store:s:'.substr($itemId, 0, 8), 'record_key' => 's:'.substr($itemId, 0, 8),
        'item_id' => $itemId, 'kind' => 'service', 'projector_version' => 1,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => $title, 'body' => 'Synced description', 'updated_at' => now(),
    ]);

    DB::table('content.offers')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'channel' => 'fresha', 'qualifier' => 'exact', 'amount_minor' => 6500,
        'currency' => 'AUD', 'updated_at' => now(),
    ]);

    return $itemId;
}

/** An owner edit, in the only form content.* has for one: a per-column override row. */
function serviceResyncOverride(string $itemId, string $column = 'headline', string $value = 'My Edited Name'): void
{
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId,
        'facet' => 'f_text', 'column_name' => $column,
        'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** An owner-authored service, created through the real endpoint so it lands exactly as production does. */
function serviceResyncOwnerItem(User $user, string $title = 'My Own Service'): string
{
    return actingAsUser($user)->postJson('/api/services', [
        'title' => $title, 'price_cents' => 9000, 'currency_code' => 'AUD',
    ])->assertCreated()->json('service.id');
}

it('reverts an owner edit by dropping the override', function () {
    [$user] = serviceResyncUser();
    $sourceId = serviceResyncConnectionSource($user->id);
    $itemId = serviceResyncFreshaItem($user->id, $sourceId);
    serviceResyncOverride($itemId);

    actingAsUser($user)->postJson("/api/services/{$itemId}/resync")->assertOk();

    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)->count())->toBe(0);
});

it('keeps the single resync response shape byte-identical', function () {
    [$user] = serviceResyncUser();
    $sourceId = serviceResyncConnectionSource($user->id);
    $itemId = serviceResyncFreshaItem($user->id, $sourceId);
    serviceResyncOverride($itemId);

    $response = actingAsUser($user)->postJson("/api/services/{$itemId}/resync")->assertOk();

    // The legacy shape: a single `service` key holding the full
    // ServiceResource allowlist — no more, no fewer keys.
    expect(array_keys($response->json()))->toBe(['service']);
    expect(array_keys($response->json('service')))->toEqualCanonicalizing([
        'id', 'user_id', 'category_id', 'category_ids', 'title', 'description',
        'price_cents', 'currency_code', 'duration_minutes', 'is_active',
        'sort_order', 'source', 'is_manual', 'external_id',
        'created_at', 'updated_at', 'deleted_at',
    ]);
    // Provenance survives the move: this row IS a Fresha-synced service, and
    // it is no longer owner-edited (the "sync broken" chip must clear).
    expect($response->json('service.source'))->toBe('fresha');
    expect($response->json('service.is_manual'))->toBeFalse();
});

it('422s a service that is no longer offered on Fresha', function () {
    [$user] = serviceResyncUser();
    $sourceId = serviceResyncConnectionSource($user->id);
    $itemId = serviceResyncFreshaItem($user->id, $sourceId);
    serviceResyncOverride($itemId);

    // No LIVE source item = nothing to revert to.
    DB::table('content.source_items')->where('item_id', $itemId)->update(['removed_at' => now()]);

    actingAsUser($user)->postJson("/api/services/{$itemId}/resync")->assertStatus(422);

    // And the owner's edit is kept, not silently dropped — the 422 copy tells
    // them to keep it or delete the service.
    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)->count())->toBe(1);
});

it('422s a resync of an owner-authored service', function () {
    // Unchanged vocabulary: an owner-authored service was never synced, so
    // there is nothing to revert to — the same 422 the pre-cutover code
    // returned for a source IS NULL row.
    [$user] = serviceResyncUser();
    $itemId = serviceResyncOwnerItem($user);

    actingAsUser($user)->postJson("/api/services/{$itemId}/resync")->assertStatus(422);
});

it('404s another professional\'s Fresha service item', function () {
    [$owner] = serviceResyncUser();
    $sourceId = serviceResyncConnectionSource($owner->id);
    $itemId = serviceResyncFreshaItem($owner->id, $sourceId);
    serviceResyncOverride($itemId);

    [$stranger] = serviceResyncUser();

    actingAsUser($stranger)->postJson("/api/services/{$itemId}/resync")->assertNotFound();
    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)->count())->toBe(1);
});

it('reverts every edited service in bulk and reports the counts', function () {
    [$user] = serviceResyncUser();
    $sourceId = serviceResyncConnectionSource($user->id);

    $reverted = serviceResyncFreshaItem($user->id, $sourceId, 'Haircut');
    $gone = serviceResyncFreshaItem($user->id, $sourceId, 'Retired Treatment');
    $untouched = serviceResyncFreshaItem($user->id, $sourceId, 'Never Edited');
    serviceResyncOverride($reverted);
    serviceResyncOverride($gone);

    // $gone left the vendor's menu — nothing to revert to, so it is SKIPPED,
    // not resynced, and its owner edit stays.
    DB::table('content.source_items')->where('item_id', $gone)->update(['removed_at' => now()]);

    $response = actingAsUser($user)->postJson('/api/services/resync')->assertOk();

    // The legacy shape: exactly two integer counters, nothing else.
    expect(array_keys($response->json()))->toBe(['resynced', 'skipped']);
    expect($response->json('resynced'))->toBe(1);
    expect($response->json('skipped'))->toBe(1);

    expect(DB::table('content.manual_overrides')->where('item_id', $reverted)->count())->toBe(0);
    expect(DB::table('content.manual_overrides')->where('item_id', $gone)->count())->toBe(1);
    // A never-edited service is not in the candidate set at all.
    expect(DB::table('content.manual_overrides')->where('item_id', $untouched)->count())->toBe(0);
});

it('narrows a bulk resync to the ids it was given', function () {
    [$user] = serviceResyncUser();
    $sourceId = serviceResyncConnectionSource($user->id);

    $named = serviceResyncFreshaItem($user->id, $sourceId, 'Named');
    $unnamed = serviceResyncFreshaItem($user->id, $sourceId, 'Unnamed');
    serviceResyncOverride($named);
    serviceResyncOverride($unnamed);

    actingAsUser($user)->postJson('/api/services/resync', ['ids' => [$named]])
        ->assertOk()
        ->assertJson(['resynced' => 1, 'skipped' => 0]);

    expect(DB::table('content.manual_overrides')->where('item_id', $named)->count())->toBe(0);
    expect(DB::table('content.manual_overrides')->where('item_id', $unnamed)->count())->toBe(1);
});

it('keeps the bulk ids validation', function () {
    [$user] = serviceResyncUser();

    actingAsUser($user)->postJson('/api/services/resync', ['ids' => ['not-a-uuid']])
        ->assertStatus(422);

    actingAsUser($user)->postJson('/api/services/resync', ['ids' => array_fill(0, 501, (string) Str::uuid())])
        ->assertStatus(422);
});

it('never touches another professional\'s overrides in bulk', function () {
    [$owner] = serviceResyncUser();
    $ownerSource = serviceResyncConnectionSource($owner->id);
    $ownerItem = serviceResyncFreshaItem($owner->id, $ownerSource, 'Theirs');
    serviceResyncOverride($ownerItem);

    [$stranger] = serviceResyncUser();

    actingAsUser($stranger)->postJson('/api/services/resync', ['ids' => [$ownerItem]])
        ->assertOk()
        ->assertJson(['resynced' => 0, 'skipped' => 0]);

    expect(DB::table('content.manual_overrides')->where('item_id', $ownerItem)->count())->toBe(1);
});

it('lets an owner-authored service be assigned to a category', function () {
    // 3a gated this to source='fresha' because content.* had no membership
    // concept. It has one now, so the gate comes off.
    [$user] = serviceResyncUser();
    $itemId = serviceResyncOwnerItem($user);
    $categoryId = app(ServiceCollections::class)->create($user->id, 'Colour');

    $response = actingAsUser($user)
        ->patchJson("/api/services/{$itemId}/category", ['category_id' => $categoryId])
        ->assertOk();

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->value('collection_id'))
        ->toBe($categoryId);
    // Owner-authored memberships live on the null-source lane
    // (ServiceCollections::assign()'s rule 4) — never on a connector's.
    expect(DB::table('content.collection_items')->where('item_id', $itemId)->value('source_id'))->toBeNull();
    // The response is not a lie: the membership it just wrote is in it.
    expect($response->json('service.category_id'))->toBe($categoryId);
    expect($response->json('service.category_ids'))->toBe([$categoryId]);
});

it('moves an owner-authored service back to Uncategorised on an explicit null', function () {
    [$user] = serviceResyncUser();
    $itemId = serviceResyncOwnerItem($user);
    $categoryId = app(ServiceCollections::class)->create($user->id, 'Colour');

    actingAsUser($user)->patchJson("/api/services/{$itemId}/category", ['category_id' => $categoryId])->assertOk();

    $response = actingAsUser($user)
        ->patchJson("/api/services/{$itemId}/category", ['category_id' => null])
        ->assertOk();

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->count())->toBe(0);
    expect($response->json('service.category_id'))->toBeNull();
});

it('422s an owner-authored assignment to another professional\'s category', function () {
    [$user] = serviceResyncUser();
    [$other] = serviceResyncUser();
    $itemId = serviceResyncOwnerItem($user);
    $foreignCategoryId = app(ServiceCollections::class)->create($other->id, 'Not Yours');

    actingAsUser($user)
        ->patchJson("/api/services/{$itemId}/category", ['category_id' => $foreignCategoryId])
        ->assertStatus(422);

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->count())->toBe(0);
});

it('renders the real category on the public payload, not the Services constant', function () {
    [$user] = serviceResyncUser();
    $itemId = serviceResyncOwnerItem($user);
    $categoryId = app(ServiceCollections::class)->create($user->id, 'Colour');
    app(ServiceCollections::class)->assign($user->id, $itemId, $categoryId, null);

    $row = app(ManualServiceItems::class)->publicList($user->id, $user->site);

    expect($row[0]['category'])->toBe('Colour');
});

it('falls back to Services on the public payload when the item has no category', function () {
    // Today's output, preserved exactly for the unassigned case — the whole
    // reason the fallback is the old constant and not null.
    [$user] = serviceResyncUser();
    serviceResyncOwnerItem($user);

    $row = app(ManualServiceItems::class)->publicList($user->id, $user->site);

    expect($row[0]['category'])->toBe('Services');
});

it('drops a deleted category off the public payload rather than resurrecting it', function () {
    [$user] = serviceResyncUser();
    $itemId = serviceResyncOwnerItem($user);
    $collections = app(ServiceCollections::class);
    $categoryId = $collections->create($user->id, 'Colour');
    $collections->assign($user->id, $itemId, $categoryId, null);
    $collections->remove($user->id, $categoryId);

    $row = app(ManualServiceItems::class)->publicList($user->id, $user->site);

    expect($row[0]['category'])->toBe('Services');
});

it('fires all three invalidation lanes on a category assignment', function () {
    [$user, $siteId] = serviceResyncUser();
    $itemId = serviceResyncOwnerItem($user);
    $categoryId = app(ServiceCollections::class)->create($user->id, 'Colour');

    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $beforeRevision = BuildState::read($siteId)['content_revision'];
    Queue::fake();

    actingAsUser($user)->patchJson("/api/services/{$itemId}/category", ['category_id' => $categoryId])->assertOk();

    // EXACTLY one bump: ServiceCollections is a raw write that deliberately
    // does not self-invalidate (it holds no site context), so this delta IS
    // ManualServiceWriter::invalidate()'s own lane and nothing else. A
    // ">= 1" assertion here would still pass with that call deleted.
    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($beforeRevision + 1);
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('fires all three invalidation lanes on a resync', function () {
    [$user, $siteId] = serviceResyncUser();
    $sourceId = serviceResyncConnectionSource($user->id);
    $itemId = serviceResyncFreshaItem($user->id, $sourceId);
    serviceResyncOverride($itemId);

    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $beforeRevision = BuildState::read($siteId)['content_revision'];
    Queue::fake();

    actingAsUser($user)->postJson("/api/services/{$itemId}/resync")->assertOk();

    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($beforeRevision + 1);
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

// ── C1 (final review): the grouped dashboard list ──────────────────────────
//
// Two id spaces are live at once, and a service's memberships only ever point
// into one of them. Before this fix the OWNER's grouped list enumerated only
// site.service_categories, so a service assigned to a content.collections
// category matched no block AND failed the `=== []` uncategorised filter — it
// appeared in NEITHER list and vanished from the dashboard. The staff twin had
// already been fixed, so the two surfaces disagreed about identical data,
// which is the worse half of the bug.

/** File-local, uniquely named: a helper in one Pest file is not visible to another under a single-file run. */
function serviceResyncStaffAdmin(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

it('shows an owner-authored service under its own collection block in the grouped list', function () {
    [$user] = serviceResyncUser();
    $itemId = serviceResyncOwnerItem($user, 'Balayage');
    $categoryId = app(ServiceCollections::class)->create($user->id, 'Colour');

    actingAsUser($user)->patchJson("/api/services/{$itemId}/category", ['category_id' => $categoryId])->assertOk();

    $response = actingAsUser($user)->getJson('/api/services?grouped=1')->assertOk();

    $block = collect($response->json('categories'))->firstWhere('id', $categoryId);
    expect($block)->not->toBeNull();
    expect($block['title'])->toBe('Colour');
    expect(collect($block['services'])->pluck('id')->all())->toBe([$itemId]);
});

it('never drops a categorised owner-authored service from the grouped list entirely', function () {
    // C1 stated as the property that actually failed: whichever list it
    // belongs in, a live service must appear in EXACTLY ONE of them. Asserting
    // only "under its block" would still pass if it were also duplicated into
    // uncategorised; asserting only "not in uncategorised" was true even while
    // the bug was live. The count is what pins it.
    [$user] = serviceResyncUser();
    $assigned = serviceResyncOwnerItem($user, 'Balayage');
    $unassigned = serviceResyncOwnerItem($user, 'Blow Dry');
    $categoryId = app(ServiceCollections::class)->create($user->id, 'Colour');

    actingAsUser($user)->patchJson("/api/services/{$assigned}/category", ['category_id' => $categoryId])->assertOk();

    $response = actingAsUser($user)->getJson('/api/services?grouped=1')->assertOk();

    $inBlocks = collect($response->json('categories'))->flatMap(fn ($c) => collect($c['services'])->pluck('id'));
    $inUncategorised = collect($response->json('uncategorised_services'))->pluck('id');
    $everywhere = $inBlocks->concat($inUncategorised);

    expect($everywhere->filter(fn ($id) => $id === $assigned)->count())->toBe(1);
    expect($everywhere->filter(fn ($id) => $id === $unassigned)->count())->toBe(1);
    expect($inUncategorised->all())->toBe([$unassigned]);
});

// ── The round trip: what the grouped GET emits, the layout POST must accept ──
//
// C1 made collection blocks render, which made this reachable: reorderLayout()
// validated block ids against site.service_categories only, so saving a
// drag-and-drop of the very layout just rendered came back 422. Posting a
// HAND-BUILT payload would not have caught it — the bug lives in the
// relationship between the two endpoints, so the test has to feed one into
// the other.

/** The grouped payload, reshaped into exactly what reorder-layout expects. */
function serviceResyncLayoutFromGrouped(array $grouped): array
{
    $blocks = collect($grouped['categories'])->map(fn ($category) => [
        'id' => $category['id'],
        'service_ids' => collect($category['services'])->pluck('id')->all(),
    ])->all();

    $blocks[] = [
        'id' => null,
        'service_ids' => collect($grouped['uncategorised_services'])->pluck('id')->all(),
    ];

    return ['categories' => $blocks];
}

it('accepts the exact layout its own grouped list just returned', function () {
    [$user] = serviceResyncUser();
    $collections = app(ServiceCollections::class);

    $colour = $collections->create($user->id, 'Colour');
    $cuts = $collections->create($user->id, 'Cuts');
    $balayage = serviceResyncOwnerItem($user, 'Balayage');
    $trim = serviceResyncOwnerItem($user, 'Trim');
    $loose = serviceResyncOwnerItem($user, 'Loose');
    actingAsUser($user)->patchJson("/api/services/{$balayage}/category", ['category_id' => $colour])->assertOk();
    actingAsUser($user)->patchJson("/api/services/{$trim}/category", ['category_id' => $cuts])->assertOk();

    $grouped = actingAsUser($user)->getJson('/api/services?grouped=1')->assertOk()->json();

    actingAsUser($user)
        ->postJson('/api/services/reorder-layout', serviceResyncLayoutFromGrouped($grouped))
        ->assertOk()
        ->assertJson(['ok' => true]);

    // Round trip, not just a 200: the same grouping survives a re-read.
    $after = actingAsUser($user)->getJson('/api/services?grouped=1')->assertOk()->json();
    expect(serviceResyncLayoutFromGrouped($after))->toBe(serviceResyncLayoutFromGrouped($grouped));
    expect(collect($after['uncategorised_services'])->pluck('id')->all())->toBe([$loose]);
});

it('reorders collection blocks and their services, and the new order survives a re-read', function () {
    [$user] = serviceResyncUser();
    $collections = app(ServiceCollections::class);

    $colour = $collections->create($user->id, 'Colour');
    $cuts = $collections->create($user->id, 'Cuts');
    $balayage = serviceResyncOwnerItem($user, 'Balayage');
    $tint = serviceResyncOwnerItem($user, 'Tint');
    $trim = serviceResyncOwnerItem($user, 'Trim');
    foreach ([$balayage, $tint] as $id) {
        actingAsUser($user)->patchJson("/api/services/{$id}/category", ['category_id' => $colour])->assertOk();
    }
    actingAsUser($user)->patchJson("/api/services/{$trim}/category", ['category_id' => $cuts])->assertOk();

    // Cuts moves ahead of Colour, and inside Colour the two swap.
    actingAsUser($user)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $cuts, 'service_ids' => [$trim]],
            ['id' => $colour, 'service_ids' => [$tint, $balayage]],
            ['id' => null, 'service_ids' => []],
        ],
    ])->assertOk();

    $after = actingAsUser($user)->getJson('/api/services?grouped=1')->assertOk()->json();

    expect(collect($after['categories'])->pluck('id')->all())->toBe([$cuts, $colour]);
    $colourServiceIds = collect(collect($after['categories'])->firstWhere('id', $colour)['services'])
        ->pluck('id')->all();
    expect($colourServiceIds)->toBe([$tint, $balayage]);
    // The order the block reports is the order the flat list reports — one
    // shared rank across both halves, not a per-block counter.
    $flat = collect(actingAsUser($user)->getJson('/api/services?include_archived=1')->assertOk()->json('services'))
        ->pluck('id')->all();
    expect(array_search($tint, $flat, true))->toBeLessThan(array_search($balayage, $flat, true));
});

it('fires all three invalidation lanes on a layout reorder', function () {
    [$user, $siteId] = serviceResyncUser();
    $collections = app(ServiceCollections::class);
    $colour = $collections->create($user->id, 'Colour');
    $itemId = serviceResyncOwnerItem($user, 'Balayage');
    actingAsUser($user)->patchJson("/api/services/{$itemId}/category", ['category_id' => $colour])->assertOk();

    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $beforeRevision = BuildState::read($siteId)['content_revision'];
    Queue::fake();

    actingAsUser($user)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $colour, 'service_ids' => [$itemId]],
            ['id' => null, 'service_ids' => []],
        ],
    ])->assertOk();

    // EXACTLY one bump. reposition() and pin() are raw writes that bump
    // nothing on their own, so this delta IS ManualServiceWriter::invalidate()
    // and nothing else — a ">= 1" assertion would survive deleting it.
    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($beforeRevision + 1);
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('rejects a legacy site.services id in a layout block', function () {
    // Was: "rejects a Fresha service filed under an owner-authored category
    // block" — the cross-space guard that made accepting TWO category id
    // spaces safe. Services cutover Task 5 left one space, so the mismatch it
    // described cannot occur; what a legacy id gets now is the plain
    // unknown-id 422, before any block-space question is asked.
    [$user] = serviceResyncUser();
    $colour = app(ServiceCollections::class)->create($user->id, 'Colour');
    $legacyId = ownerService($user->id, ['title' => 'Fresha Cut', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 0]);

    actingAsUser($user)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $colour, 'service_ids' => [$legacyId]],
            ['id' => null, 'service_ids' => []],
        ],
    ])->assertStatus(422)->assertJsonPath('message', 'One or more service IDs are invalid.');
});

it('rejects a payload that covers every legacy category but omits a collection', function () {
    // Coverage is per id space: a payload complete in one space and empty in
    // the other is still an incomplete layout, and silently repositioning
    // only what it named would scramble the rest.
    [$user] = serviceResyncUser();
    app(ServiceCollections::class)->create($user->id, 'Colour');
    $itemId = serviceResyncOwnerItem($user, 'Balayage');

    actingAsUser($user)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => null, 'service_ids' => [$itemId]],
        ],
    ])->assertStatus(422)->assertJsonPath(
        'message',
        'Layout payload must include all category IDs (use one block with id=null for uncategorised).'
    );
});

it('agrees with the staff grouped list about the same service', function () {
    // The half of C1 that is worse than the drop itself: two dashboards
    // rendering the same rows differently. Same professional, same data, both
    // surfaces — asserted across the pair, which neither side can do alone.
    [$user] = serviceResyncUser();
    $itemId = serviceResyncOwnerItem($user, 'Balayage');
    $categoryId = app(ServiceCollections::class)->create($user->id, 'Colour');
    actingAsUser($user)->patchJson("/api/services/{$itemId}/category", ['category_id' => $categoryId])->assertOk();

    $ownerBlock = collect(actingAsUser($user)->getJson('/api/services?grouped=1')->assertOk()->json('categories'))
        ->firstWhere('id', $categoryId);

    $staffBlock = collect(
        actingAsStaff(serviceResyncStaffAdmin())
            ->getJson("/api/staff/professionals/{$user->id}/services?grouped=1")
            ->assertOk()
            ->json('categories')
    )->firstWhere('id', $categoryId);

    expect($ownerBlock)->not->toBeNull();
    expect($staffBlock)->not->toBeNull();
    expect(collect($staffBlock['services'])->pluck('id')->all())
        ->toBe(collect($ownerBlock['services'])->pluck('id')->all());
    expect($staffBlock['title'])->toBe($ownerBlock['title']);
    expect($staffBlock['source'])->toBe($ownerBlock['source']);
});
