<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
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
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
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
