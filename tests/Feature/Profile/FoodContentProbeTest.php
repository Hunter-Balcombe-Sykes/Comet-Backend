<?php

// The probe behind the food-demotion guard. Each clause is a distinct way a
// business can have live food content that a sector demotion would strand.

use App\Models\Core\User\User;
use App\Services\Profile\FoodContentProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();      // site.menus, site.menu_items, site.platform_connections
    setupSectionsTables();  // site.pages, site.sections
});

function probeUser(): User
{
    return User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']);
}

function probeSite(User $user): string
{
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => $user->handle,
        'architecture_id' => 'staple',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $siteId;
}

it('is false for a user with no food content at all', function () {
    $user = probeUser();
    probeSite($user);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeFalse();
});

it('is true when a menu carries items', function () {
    $user = probeUser();
    $menuId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => $menuId, 'user_id' => $user->id, 'fetch_status' => 'ok',
    ]);
    DB::connection('pgsql')->table('site.menu_items')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'name' => 'Laksa',
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeTrue();
});

it('is true for a Menu page with no dishes yet', function () {
    // THE case the guard exists for. Zero menu items, but a live public page
    // the owner would be 403'd out of editing after a demotion.
    $user = probeUser();
    $siteId = probeSite($user);
    DB::connection('pgsql')->table('site.pages')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $siteId,
        'key' => 'menu', 'label' => 'Menu', 'sort_order' => 1, 'capability' => 'menu',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeTrue();
});

it('is true when an online-ordering platform is connected', function () {
    $user = probeUser();
    probeSite($user);
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        // platform is GENERATED from surface_key; this is the arm that yields
        // 'online-ordering'.
        'surface_key' => 'partna.order_link',
        'routing_class' => 'ordering',
        'resource_id' => 'https://order.example.test',
        'is_active' => 1,
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeTrue();
});

it('is true when a menu_item content item exists', function () {
    // menu_item is a content item kind (PageCapabilities::GATED_KINDS), not a
    // section kind — site.sections.kind does not permit it.
    $user = probeUser();
    probeSite($user);
    DB::connection('pgsql')->table('content.items')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'kind' => 'menu_item',
        'first_seen_at' => now()->toDateTimeString(), 'last_seen_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeTrue();
});

it('ignores a soft-deleted menu', function () {
    $user = probeUser();
    probeSite($user);
    $menuId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => $menuId, 'user_id' => $user->id, 'fetch_status' => 'ok',
        'deleted_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.menu_items')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'name' => 'Laksa',
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeFalse();
});

it('does not lazy-load the site relation', function () {
    // preventLazyLoading is on outside production; a relation access would throw.
    $user = probeUser();
    probeSite($user);

    expect(fn () => app(FoodContentProbe::class)->existsFor($user))->not->toThrow(Exception::class);
});
