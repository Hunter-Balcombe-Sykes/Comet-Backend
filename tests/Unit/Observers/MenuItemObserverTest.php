<?php

use App\Models\Core\Site\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupSitesTable();      // sites + menus + menu_items mirror
    setupItemSlugsTable();
    $this->pro = createTenant('ollies');
    $this->menuId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => $this->menuId, 'user_id' => $this->pro->id,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
});

function menuItemSlug(string $itemId): ?string
{
    return DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_type', 'menu_item')->where('item_key', $itemId)
        ->where('is_current', 1)->value('slug');
}

it('mints a slug when a menu item is created', function () {
    $item = new MenuItem(['name' => 'Garlic Bread']);
    $item->menu_id = $this->menuId;
    $item->save();

    expect(menuItemSlug($item->id))->toBe('garlic-bread');
});

it('re-slugs + retains a redirect when the name changes', function () {
    $item = new MenuItem(['name' => 'Garlic Bread']);
    $item->menu_id = $this->menuId;
    $item->save();
    $item->update(['name' => 'Cheesy Garlic Bread']);

    $rows = DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_key', $item->id)->pluck('is_current', 'slug');
    expect((int) $rows['garlic-bread'])->toBe(0);
    expect((int) $rows['cheesy-garlic-bread'])->toBe(1);
});

it('forgets slugs when the item is deleted', function () {
    $item = new MenuItem(['name' => 'Garlic Bread']);
    $item->menu_id = $this->menuId;
    $item->save();
    $itemId = $item->id;
    $item->delete();

    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', $itemId)->count())->toBe(0);
});
