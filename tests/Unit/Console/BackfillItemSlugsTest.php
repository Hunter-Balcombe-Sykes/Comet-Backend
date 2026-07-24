<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class)->in(__FILE__);

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
