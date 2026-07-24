<?php

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupSitesTable();
    setupIntegrationConnectionsTable();
    setupItemSlugsTable();

    $this->pro = createTenant('backfillpro');

    $this->menuId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => $this->menuId, 'user_id' => $this->pro->id,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    // Insert menu items directly (bypassing the model/observer) to simulate
    // pre-existing content that predates the slug system.
    $this->itemId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menu_items')->insert([
        'id' => $this->itemId, 'menu_id' => $this->menuId, 'name' => 'Garlic Bread',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    // Insert a platform_connections row directly (bypassing EventsCatalog/the
    // observer) to simulate a pre-existing synced event.
    IntegrationConnection::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $this->pro->id,
        'platform' => 'eventbrite',
        'resource_id' => 'event-preexistinghex',
        'resource_kind' => 'event', // standalone-event row shape (EventsCatalog::storeStandalone)
        'payload' => ['kind' => 'event', 'id' => 'preexistinghex', 'name' => 'Legacy Trivia'],
        'is_active' => true,
    ]);
});

it('backfills slugs for pre-existing menu items and events', function () {
    $this->artisan('slugs:backfill')->assertOk();

    $menuSlug = DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_type', 'menu_item')->where('item_key', $this->itemId)
        ->where('is_current', 1)->value('slug');
    expect($menuSlug)->toBe('garlic-bread');

    $eventSlug = DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_type', 'event')->where('item_key', 'preexistinghex')
        ->where('is_current', 1)->value('slug');
    expect($eventSlug)->toBe('legacy-trivia');
});

it('is idempotent — a second run mints nothing new', function () {
    $this->artisan('slugs:backfill')->assertOk();
    $firstCount = DB::connection('pgsql')->table('site.item_slugs')->count();

    $this->artisan('slugs:backfill')->assertOk();
    $secondCount = DB::connection('pgsql')->table('site.item_slugs')->count();

    expect($secondCount)->toBe($firstCount);
});

// ── --prune: opt-in orphan sweep (271-DINT-1/4 heal path) ─────────────
// The only mechanism that can clean rows already stranded in production from
// before the rebuild/refresh writers learned to retire. Off by default: a
// diff-driven mass DELETE gets a verified manual run first.

/** An item_slugs row inserted straight in, standing in for a stranded orphan. */
function seedOrphanSlug(string $userId, string $itemType, string $itemKey, string $slug): void
{
    DB::connection('pgsql')->table('site.item_slugs')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId, 'item_type' => $itemType, 'item_key' => $itemKey,
        'slug' => $slug, 'is_current' => 1, 'created_at' => now()->toDateTimeString(),
    ]);
}

it('leaves orphaned rows alone without --prune', function () {
    seedOrphanSlug($this->pro->id, 'menu_item', (string) Str::uuid(), 'ghost-dish');
    seedOrphanSlug($this->pro->id, 'event', 'ghosthex', 'ghost-event');

    $this->artisan('slugs:backfill')->assertOk();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('slug', 'ghost-dish')->count())->toBe(1);
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('slug', 'ghost-event')->count())->toBe(1);
});

it('--prune deletes menu-item and event slugs whose item no longer exists', function () {
    seedOrphanSlug($this->pro->id, 'menu_item', (string) Str::uuid(), 'ghost-dish');
    seedOrphanSlug($this->pro->id, 'event', 'ghosthex', 'ghost-event');

    $this->artisan('slugs:backfill', ['--prune' => true])->assertOk();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('slug', 'ghost-dish')->count())->toBe(0);
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('slug', 'ghost-event')->count())->toBe(0);
    // The live dish + live event minted by the backfill pass survive.
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', $this->itemId)->count())->toBe(1);
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'preexistinghex')->count())->toBe(1);
});

it('--prune keeps event slugs claimed by an INACTIVE connection', function () {
    // Same inclusive set the observer's sibling guard uses: inactive means
    // hidden from the sitepage, not deleted.
    IntegrationConnection::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $this->pro->id,
        'platform' => 'humanitix',
        'resource_id' => 'event-inactivehex',
        'resource_kind' => 'event',
        'payload' => ['kind' => 'event', 'id' => 'inactivehex', 'name' => 'Hidden Show'],
        'is_active' => false,
    ]);

    // The connect itself minted the slug (the observer mints on create for
    // active and inactive rows alike). The backfill's SYNC pass only walks
    // active connections, so prune is the only thing that could eat it.
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'inactivehex')->count())->toBe(1);

    $this->artisan('slugs:backfill', ['--prune' => true])->assertOk();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'inactivehex')->count())->toBe(1);
});

it('--prune removes every menu-item slug for a user who has no menu row at all', function () {
    $orphanUser = createTenant('nomenupro');
    seedOrphanSlug($orphanUser->id, 'menu_item', (string) Str::uuid(), 'stranded-dish');

    $this->artisan('slugs:backfill', ['--prune' => true])->assertOk();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('user_id', $orphanUser->id)->count())->toBe(0);
    // Another profile's live rows are untouched.
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('user_id', $this->pro->id)->count())->toBe(2);
});
