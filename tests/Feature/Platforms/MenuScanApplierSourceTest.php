<?php

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function msaSourceUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'account_type' => 'business', 'sector' => 'restaurant', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'subdomain' => $handle,
        'is_published' => 1, 'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    return $user->fresh();
}

it('tags a custom source when passed, defaulting to "scan" when omitted', function () {
    $defaultUser = msaSourceUser('msadefault');
    app(MenuScanApplier::class)->apply($defaultUser, [
        ['name' => 'Margherita', 'description' => 'Classic', 'price' => 18.0, 'category' => 'Pizza'],
    ]);
    $defaultMenu = Menu::query()->where('user_id', $defaultUser->id)->firstOrFail();
    expect($defaultMenu->content_source)->toBe('scan');
    expect(MenuCategory::query()->where('menu_id', $defaultMenu->id)->first()->source_platform)->toBe('scan');

    $customUser = msaSourceUser('msacustom');
    app(MenuScanApplier::class)->apply($customUser, [
        ['name' => 'Margherita', 'description' => 'Classic', 'price' => 18.0, 'category' => 'Pizza'],
    ], enrichOnly: true, source: 'website-scan');
    $customMenu = Menu::query()->where('user_id', $customUser->id)->firstOrFail();
    expect($customMenu->content_source)->toBe('website-scan');
    expect(MenuCategory::query()->where('menu_id', $customMenu->id)->first()->source_platform)->toBe('website-scan');
});

it('scopes the scan-category lookup to the given source, not "scan" unconditionally', function () {
    $user = msaSourceUser('msascoped');

    // Seed a 'scan'-sourced category first.
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Margherita', 'description' => null, 'price' => 18.0, 'category' => 'Pizza'],
    ]);

    // A 'website-scan' apply for the SAME category name must create its OWN
    // category, not reuse the 'scan' one — different source_platform tags.
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Carbonara', 'description' => null, 'price' => 20.0, 'category' => 'Pizza'],
    ], enrichOnly: true, source: 'website-scan');

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $categories = MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Pizza')->get();
    expect($categories)->toHaveCount(2);
    expect($categories->pluck('source_platform')->sort()->values()->all())->toBe(['scan', 'website-scan']);
});
