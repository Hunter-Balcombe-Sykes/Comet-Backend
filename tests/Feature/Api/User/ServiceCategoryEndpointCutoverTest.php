<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\User\User;
use App\Site\Documents\BuildState;
use App\Site\Documents\DocumentBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 3b Task 9: the seven /service-categories/* routes now read and write
// content.collections (kind='service_category') through ServiceCollections
// instead of site.service_categories. The wire shape does not move; only the
// backing store does. The regression this file exists to catch is the same
// one slice 2 shipped — a dashboard write landing somewhere the read path
// never consults — plus its 3b-specific twin: ServiceCategoryResource handed
// a collection row and silently emitting null for EVERY category's `source`.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    // store()/update()/reorder() take a pg_advisory_xact_lock(hashtext(...))
    // on service-categories:{user} — shim it under SQLite so the real locked
    // code path still runs.
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

/** @return array{0: User, 1: string} [$pro, $siteId] */
function svcCatTenant(): array
{
    $pro = createTenant('svccat-'.Str::lower(Str::random(8)));

    return [$pro, (string) $pro->site->id];
}

/** POST /api/service-categories and hand back the new collection id. */
function svcCatCreate(User $pro, string $title): string
{
    return (string) actingAsUser($pro)
        ->postJson('/api/service-categories', ['title' => $title])
        ->assertCreated()
        ->json('category.id');
}

/**
 * A machine-derived collection inserted directly — mirrors what the Fresha
 * projector lands (is_user_created=false, external_ref = the vendor key).
 * ServiceCollections::list() hides a machine collection with no LIVE members,
 * so this seeds one real item + membership too.
 *
 * @return array{0: string, 1: string} [$collectionId, $itemId]
 */
function svcCatFreshaCollection(string $userId, string $label, string $externalRef): array
{
    $collectionId = (string) Str::uuid();
    $itemId = svcCatServiceItem($userId, $label.' Service');
    $now = now();

    DB::table('content.collections')->insert([
        'id' => $collectionId, 'user_id' => $userId, 'parent_id' => null,
        'label' => $label, 'kind' => 'service_category', 'external_ref' => $externalRef,
        'removed_at' => null, 'position' => 0, 'is_user_created' => false,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId, 'source_id' => null, 'position' => 0,
    ]);

    return [$collectionId, $itemId];
}

/** A bare live content.items row of kind='service'. */
function svcCatServiceItem(string $userId, string $headline): string
{
    $id = (string) Str::uuid();
    $now = now();

    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'service',
        'headline_cache' => $headline, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => $now, 'last_seen_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    return $id;
}

/**
 * The three invalidation lanes, asserted as an EXACT revision delta of 1.
 * A ">0" check is worthless here: slice 3a shipped a three-lane test that
 * stayed green with the whole BuildState lane deleted, because a neighbouring
 * write already cleared that bar. Nothing in a category write bumps
 * content_revision except ManualServiceWriter::invalidate() itself, so the
 * delta is exactly 1 or the lane is gone.
 */
function svcCatAssertThreeLanes(string $siteId, Closure $act): void
{
    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $beforeUpdatedAt = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $beforeRevision = BuildState::read($siteId)['content_revision'];

    $act();

    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($beforeRevision + 1);
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($beforeUpdatedAt);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
}

// ── index ───────────────────────────────────────────────────────────────────

it('lists categories with the unchanged response shape', function () {
    [$pro] = svcCatTenant();
    svcCatCreate($pro, 'Colour');

    actingAsUser($pro)->getJson('/api/service-categories')
        ->assertOk()
        ->assertJsonStructure([
            'categories' => [[
                'id', 'user_id', 'title', 'source', 'sort_order',
                'created_at', 'updated_at', 'deleted_at',
            ]],
            'filters' => ['include_archived', 'only_archived'],
        ]);
});

it('reads categories out of content.collections, not site.service_categories', function () {
    [$pro] = svcCatTenant();
    // A legacy row that the cut-over read path must NOT surface any more.
    createServiceCategoryFor($pro, ['title' => 'Legacy Only', 'sort_order' => 0]);
    svcCatCreate($pro, 'Live One');

    $titles = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->pluck('title')->all();

    expect($titles)->toBe(['Live One']);
});

// ── store ───────────────────────────────────────────────────────────────────

it('creates a category owned by the user', function () {
    [$pro] = svcCatTenant();

    $response = actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Colour']);

    $response->assertCreated();
    $id = $response->json('category.id');

    $row = DB::table('content.collections')->where('id', $id)->first();
    expect($row->user_id)->toBe($pro->id);
    expect($row->label)->toBe('Colour');
    expect($row->kind)->toBe('service_category');
    expect((bool) $row->is_user_created)->toBeTrue();
    expect($row->external_ref)->toBeNull();
    expect($row->removed_at)->toBeNull();

    // Wire: an owner-authored category carries source=null.
    expect($response->json('category.title'))->toBe('Colour');
    expect($response->json('category.source'))->toBeNull();
    expect($response->json('category.user_id'))->toBe($pro->id);
});

it('appends a new category after every existing one', function () {
    [$pro] = svcCatTenant();
    $first = svcCatCreate($pro, 'First');
    $second = svcCatCreate($pro, 'Second');

    expect(DB::table('content.collections')->where('id', $first)->value('position'))->toBe(0);
    expect(DB::table('content.collections')->where('id', $second)->value('position'))->toBe(1);
});

it('honours an explicit sort_order on create by splicing the new category into that slot', function () {
    [$pro] = svcCatTenant();
    $first = svcCatCreate($pro, 'First');
    $second = svcCatCreate($pro, 'Second');

    $spliced = actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Jumped', 'sort_order' => 0])
        ->assertCreated()->json('category.id');

    $order = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->pluck('id')->all();

    expect($order)->toBe([$spliced, $first, $second]);
});

// ── update ──────────────────────────────────────────────────────────────────

it('renames a category', function () {
    [$pro] = svcCatTenant();
    $id = svcCatCreate($pro, 'Colur');

    $response = actingAsUser($pro)->patchJson("/api/service-categories/{$id}", ['title' => 'Colour']);

    $response->assertOk();
    expect($response->json('category.title'))->toBe('Colour');
    expect(DB::table('content.collections')->where('id', $id)->value('label'))->toBe('Colour');

    // And the rename is visible on a fresh read, not just in the write's own echo.
    $titles = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->pluck('title')->all();
    expect($titles)->toBe(['Colour']);
});

it('moves a category when the PATCH carries a sort_order', function () {
    [$pro] = svcCatTenant();
    $a = svcCatCreate($pro, 'A');
    $b = svcCatCreate($pro, 'B');
    $c = svcCatCreate($pro, 'C');

    actingAsUser($pro)->patchJson("/api/service-categories/{$c}", ['sort_order' => 0])->assertOk();

    $order = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->pluck('id')->all();

    expect($order)->toBe([$c, $a, $b]);
});

it('404s a PATCH against a removed category', function () {
    [$pro] = svcCatTenant();
    $id = svcCatCreate($pro, 'Gone');
    actingAsUser($pro)->deleteJson("/api/service-categories/{$id}")->assertOk();

    actingAsUser($pro)->patchJson("/api/service-categories/{$id}", ['title' => 'Back?'])->assertNotFound();
});

// ── reorder ─────────────────────────────────────────────────────────────────

it('reorders categories', function () {
    [$pro] = svcCatTenant();
    $a = svcCatCreate($pro, 'A');
    $b = svcCatCreate($pro, 'B');
    $c = svcCatCreate($pro, 'C');

    actingAsUser($pro)->postJson('/api/service-categories/reorder', ['ids' => [$c, $a, $b]])
        ->assertOk()->assertJson(['ok' => true]);

    expect(DB::table('content.collections')->where('id', $c)->value('position'))->toBe(0);
    expect(DB::table('content.collections')->where('id', $a)->value('position'))->toBe(1);
    expect(DB::table('content.collections')->where('id', $b)->value('position'))->toBe(2);

    $order = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->pluck('id')->all();
    expect($order)->toBe([$c, $a, $b]);
});

it('treats a partial reorder as authoritative for the ids it names and appends the rest', function () {
    [$pro] = svcCatTenant();
    $a = svcCatCreate($pro, 'A');
    $b = svcCatCreate($pro, 'B');
    $c = svcCatCreate($pro, 'C');

    actingAsUser($pro)->postJson('/api/service-categories/reorder', ['ids' => [$c]])->assertOk();

    $order = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->pluck('id')->all();
    expect($order)->toBe([$c, $a, $b]);
});

// ── destroy ─────────────────────────────────────────────────────────────────

it('soft-deletes a category and hides it from the list', function () {
    [$pro] = svcCatTenant();
    $kept = svcCatCreate($pro, 'Kept');
    $id = svcCatCreate($pro, 'Doomed');

    actingAsUser($pro)->deleteJson("/api/service-categories/{$id}")
        ->assertOk()->assertJson(['deleted' => true]);

    expect(DB::table('content.collections')->where('id', $id)->value('removed_at'))->not->toBeNull();

    $listed = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->pluck('id')->all();
    expect($listed)->toBe([$kept]);

    // ...but still reachable through the archived filters and the withTrashed
    // show route, which is what the dashboard's restore affordance reads.
    $archived = collect(actingAsUser($pro)->getJson('/api/service-categories?only_archived=1')->assertOk()->json('categories'))
        ->pluck('id')->all();
    expect($archived)->toBe([$id]);

    actingAsUser($pro)->getJson("/api/service-categories/{$id}")->assertNotFound();
    actingAsUser($pro)->getJson("/api/service-categories/{$id}?include_archived=1")
        ->assertOk()->assertJsonPath('category.id', $id);
});

it('deletes to collections.removed_at and never to the member items', function () {
    [$pro] = svcCatTenant();
    $id = svcCatCreate($pro, 'Doomed');
    $itemId = svcCatServiceItem($pro->id, 'Still Alive');
    DB::table('content.collection_items')->insert([
        'collection_id' => $id, 'item_id' => $itemId, 'source_id' => null, 'position' => 0,
    ]);

    actingAsUser($pro)->deleteJson("/api/service-categories/{$id}")->assertOk();

    // The membership survives (so restore() brings the group back intact) and
    // the item itself is untouched — deleting a category is not deleting its
    // services.
    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull();
    expect(DB::table('content.collection_items')->where('collection_id', $id)->count())->toBe(1);
});

it('404s a second DELETE of the same category', function () {
    [$pro] = svcCatTenant();
    $id = svcCatCreate($pro, 'Doomed');

    actingAsUser($pro)->deleteJson("/api/service-categories/{$id}")->assertOk();
    actingAsUser($pro)->deleteJson("/api/service-categories/{$id}")->assertNotFound();
});

// ── restore ─────────────────────────────────────────────────────────────────

it('restores a soft-deleted category', function () {
    [$pro] = svcCatTenant();
    $id = svcCatCreate($pro, 'Undelete Me');
    actingAsUser($pro)->deleteJson("/api/service-categories/{$id}")->assertOk();
    expect(DB::table('content.collections')->where('id', $id)->value('removed_at'))->not->toBeNull();

    $response = actingAsUser($pro)->postJson("/api/service-categories/{$id}/restore")
        ->assertOk()->assertJson(['restored' => true]);

    expect($response->json('category.id'))->toBe($id);
    expect($response->json('category.deleted_at'))->toBeNull();
    expect(DB::table('content.collections')->where('id', $id)->value('removed_at'))->toBeNull();

    $listed = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->pluck('id')->all();
    expect($listed)->toBe([$id]);
});

it('is a no-op on a category that was never removed', function () {
    [$pro] = svcCatTenant();
    $id = svcCatCreate($pro, 'Never Gone');

    actingAsUser($pro)->postJson("/api/service-categories/{$id}/restore")
        ->assertOk()->assertJson(['restored' => true])->assertJsonPath('category.id', $id);

    expect(DB::table('content.collections')->where('id', $id)->value('removed_at'))->toBeNull();
});

// ── the `source` wire field ─────────────────────────────────────────────────

// The one field that would go silently null for EVERY category if
// ServiceCategoryResource were re-fed a collection row without being adapted:
// its own comment says the dashboard needs it to tell a synced category from
// an editable one, so a constant here is a real (invisible) wire regression.
it('emits source=fresha for a machine-derived category and null for an owner-created one', function () {
    [$pro] = svcCatTenant();
    [$freshaId] = svcCatFreshaCollection($pro->id, 'Cuts', 'fresha-cat-1');
    $ownId = svcCatCreate($pro, 'Colour');

    $categories = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->keyBy('id');

    expect($categories[$freshaId]['source'])->toBe('fresha');
    expect($categories[$ownId]['source'])->toBeNull();

    // Same on the single-resource route, which builds the resource separately.
    expect(actingAsUser($pro)->getJson("/api/service-categories/{$freshaId}")->assertOk()->json('category.source'))
        ->toBe('fresha');
    expect(actingAsUser($pro)->getJson("/api/service-categories/{$ownId}")->assertOk()->json('category.source'))
        ->toBeNull();
});

it('maps label to title and position to sort_order on the wire', function () {
    [$pro] = svcCatTenant();
    $first = svcCatCreate($pro, 'First');
    $second = svcCatCreate($pro, 'Second');

    $categories = collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->keyBy('id');

    expect($categories[$first]['title'])->toBe('First');
    expect($categories[$first]['sort_order'])->toBe(0);
    expect($categories[$second]['title'])->toBe('Second');
    expect($categories[$second]['sort_order'])->toBe(1);
});

// Fix round 1, Finding 1. ServiceCollections::baseQuery() originally selected
// six columns and neither timestamp, so both of these fields serialised as
// null on EVERY category — keys present, values gone, no query error, no
// failing test: the same invisible-regression shape Ruling 2 caught for
// `source`. This test is what turns a future trim of that SELECT into a
// failure instead of two silent nulls.
it('emits real created_at/updated_at timestamps, not two silent nulls', function () {
    [$pro] = svcCatTenant();
    $id = svcCatCreate($pro, 'Colour');

    foreach ([
        actingAsUser($pro)->getJson("/api/service-categories/{$id}")->assertOk()->json('category'),
        collect(actingAsUser($pro)->getJson('/api/service-categories')->assertOk()->json('categories'))->firstWhere('id', $id),
    ] as $category) {
        expect($category['created_at'])->not->toBeNull();
        expect($category['updated_at'])->not->toBeNull();
        // Same ISO-8601 shape the legacy Eloquent path emitted — a raw
        // query-builder row hands timestamps back as driver-formatted
        // strings, so "non-null" alone would pass on an unparsed value.
        expect($category['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
        expect($category['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    }

    // And they track the row, rather than being any non-null constant: a
    // rename moves updated_at past created_at.
    $before = DB::table('content.collections')->where('id', $id)->value('updated_at');
    $this->travel(2)->seconds();
    actingAsUser($pro)->patchJson("/api/service-categories/{$id}", ['title' => 'Colour Deluxe'])->assertOk();

    expect(DB::table('content.collections')->where('id', $id)->value('updated_at'))->not->toBe($before);
    $category = actingAsUser($pro)->getJson("/api/service-categories/{$id}")->assertOk()->json('category');
    expect(Carbon::parse($category['updated_at'])->greaterThan(Carbon::parse($category['created_at'])))->toBeTrue();
});

it('maps removed_at onto deleted_at as an ISO-8601 string', function () {
    [$pro] = svcCatTenant();
    $id = svcCatCreate($pro, 'Doomed');
    actingAsUser($pro)->deleteJson("/api/service-categories/{$id}")->assertOk();

    $deletedAt = actingAsUser($pro)->getJson("/api/service-categories/{$id}?include_archived=1")
        ->assertOk()->json('category.deleted_at');

    expect($deletedAt)->not->toBeNull();
    expect($deletedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

// ── tenancy ─────────────────────────────────────────────────────────────────

it('404s another user\'s category on every route', function () {
    [$owner] = svcCatTenant();
    $id = svcCatCreate($owner, 'Owner Only');
    $sibling = svcCatCreate($owner, 'Owner Second');

    [$stranger] = svcCatTenant();

    // Every id-bound route in the group, one per line — not a spot check.
    $routes = [
        ['GET', "/api/service-categories/{$id}", []],
        ['GET', "/api/service-categories/{$id}?include_archived=1", []],
        ['PATCH', "/api/service-categories/{$id}", ['title' => 'Hijacked']],
        ['PATCH', "/api/service-categories/{$id}", ['sort_order' => 0]],
        ['DELETE', "/api/service-categories/{$id}", []],
        ['POST', "/api/service-categories/{$id}/restore", []],
    ];

    foreach ($routes as [$method, $uri, $payload]) {
        actingAsUser($stranger)->json($method, $uri, $payload)
            ->assertNotFound("{$method} {$uri} leaked another user's category");
    }

    // reorder is not id-bound (it 200s), so its tenancy guarantee is that a
    // foreign id consumes no slot and moves nothing.
    actingAsUser($stranger)->postJson('/api/service-categories/reorder', ['ids' => [$id]])->assertOk();

    // index never lists it either.
    expect(collect(actingAsUser($stranger)->getJson('/api/service-categories')->assertOk()->json('categories'))->pluck('id')->all())
        ->toBe([]);

    // The owner's rows came through all of that untouched.
    $row = DB::table('content.collections')->where('id', $id)->first();
    expect($row->label)->toBe('Owner Only');
    expect($row->removed_at)->toBeNull();
    expect((int) $row->position)->toBe(0);
    expect((int) DB::table('content.collections')->where('id', $sibling)->value('position'))->toBe(1);
});

// ── the three cache lanes, per write route ──────────────────────────────────

it('fires all three invalidation lanes on store', function () {
    [$pro, $siteId] = svcCatTenant();

    svcCatAssertThreeLanes($siteId, function () use ($pro) {
        actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Lanes'])->assertCreated();
    });
});

it('fires all three invalidation lanes on update', function () {
    [$pro, $siteId] = svcCatTenant();
    $id = svcCatCreate($pro, 'Lanes');

    svcCatAssertThreeLanes($siteId, function () use ($pro, $id) {
        actingAsUser($pro)->patchJson("/api/service-categories/{$id}", ['title' => 'Renamed'])->assertOk();
    });
});

it('fires all three invalidation lanes on destroy', function () {
    [$pro, $siteId] = svcCatTenant();
    $id = svcCatCreate($pro, 'Lanes');

    svcCatAssertThreeLanes($siteId, function () use ($pro, $id) {
        actingAsUser($pro)->deleteJson("/api/service-categories/{$id}")->assertOk();
    });
});

it('fires all three invalidation lanes on reorder', function () {
    [$pro, $siteId] = svcCatTenant();
    $a = svcCatCreate($pro, 'A');
    $b = svcCatCreate($pro, 'B');

    svcCatAssertThreeLanes($siteId, function () use ($pro, $a, $b) {
        actingAsUser($pro)->postJson('/api/service-categories/reorder', ['ids' => [$b, $a]])->assertOk();
    });
});

it('fires all three invalidation lanes on restore', function () {
    [$pro, $siteId] = svcCatTenant();
    $id = svcCatCreate($pro, 'Lanes');
    actingAsUser($pro)->deleteJson("/api/service-categories/{$id}")->assertOk();

    svcCatAssertThreeLanes($siteId, function () use ($pro, $id) {
        actingAsUser($pro)->postJson("/api/service-categories/{$id}/restore")->assertOk();
    });
});

// ── the cross-task SectionCandidates gap ────────────────────────────────────

/** A site.pages + automatic site.sections pair driven by one in_collection rule. */
function svcCatCollectionSection(string $siteId, string $collectionId): void
{
    $pageId = (string) Str::uuid();
    DB::table('site.pages')->insert([
        'id' => $pageId, 'site_id' => $siteId, 'key' => 'services', 'label' => 'Services',
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('site.sections')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $siteId, 'page_id' => $pageId,
        'kind' => 'collection', 'slot' => 'body', 'mode' => 'automatic',
        'render' => 'cards', 'order_by' => 'recency', 'on_empty' => 'show_anyway',
        'min_items' => 0, 'stale_display' => 'inherit',
        'rule' => json_encode(['all' => [['op' => 'in_collection', 'values' => [$collectionId]]]]),
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Every headline the built document renders, sorted. */
function svcCatDocumentHeadlines(string $siteId): array
{
    (new DocumentBuilder)->build($siteId);
    $document = json_decode((string) DB::table('site.site_documents')
        ->where('site_id', $siteId)->orderByDesc('version')->value('document'), true);

    $headlines = [];
    foreach ($document['pages'] as $page) {
        foreach ($page['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $headlines[] = $item['headline'];
            }
        }
    }
    sort($headlines);

    return $headlines;
}

// SectionCandidates' in_collection rule never filtered collections.removed_at,
// so an item kept serving through a category its owner had deleted. It was
// inert only because nothing set removed_at — destroy() above is what starts
// setting it, so the gap goes live with this task.
it('stops serving an item through a category the owner deleted', function () {
    [$pro, $siteId] = svcCatTenant();
    $collectionId = svcCatCreate($pro, 'Cuts');
    $itemId = svcCatServiceItem($pro->id, 'Fade');
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId, 'source_id' => null, 'position' => 0,
    ]);
    svcCatCollectionSection($siteId, $collectionId);

    expect(svcCatDocumentHeadlines($siteId))->toBe(['Fade']);

    actingAsUser($pro)->deleteJson("/api/service-categories/{$collectionId}")->assertOk();

    expect(svcCatDocumentHeadlines($siteId))->toBe([]);

    // ...and restoring the category brings it back — removed_at is a filter,
    // not a membership teardown.
    actingAsUser($pro)->postJson("/api/service-categories/{$collectionId}/restore")->assertOk();
    expect(svcCatDocumentHeadlines($siteId))->toBe(['Fade']);
});

it('matches a removed collection by label no more than by id', function () {
    // The label branch of in_collection is a separate OR arm — a fix applied
    // only to the id arm would leave the label arm serving the deleted group.
    [$pro, $siteId] = svcCatTenant();
    $collectionId = svcCatCreate($pro, 'Cuts');
    $itemId = svcCatServiceItem($pro->id, 'Fade');
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId, 'source_id' => null, 'position' => 0,
    ]);
    svcCatCollectionSection($siteId, $collectionId);
    DB::table('site.sections')->where('site_id', $siteId)->update([
        'rule' => json_encode(['all' => [['op' => 'in_collection', 'values' => ['Cuts']]]]),
    ]);

    expect(svcCatDocumentHeadlines($siteId))->toBe(['Fade']);

    actingAsUser($pro)->deleteJson("/api/service-categories/{$collectionId}")->assertOk();

    expect(svcCatDocumentHeadlines($siteId))->toBe([]);
});
