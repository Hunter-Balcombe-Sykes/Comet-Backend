<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Slice 7 Tasks 7 + 8: BOTH lanes now land in content.* — the scan through
    // MenuScanApplier → ManualMenuWriter, the scrape through MenuFetchJob.
    setupContentTables();
    Queue::fake();
});

function mfjwspUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
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

function mfjwspOrdering(User $user): IntegrationConnection
{
    $url = 'https://www.ubereats.com/store/x';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    return IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'uber_eats.order', 'resource_id' => $rid,
        'payload' => ['id' => $rid, 'provider' => 'custom', 'url' => $url, 'name' => 'Order', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
}

it('protects website-scan-sourced menu content from an ordering-platform rebuild wipe, same as scan/manual', function () {
    $user = mfjwspUser('mfjwsp1');
    mfjwspOrdering($user);

    // A food-Business's website-scan menu content, seeded BEFORE any ordering
    // platform connects — the exact scenario Task A4.4 protects.
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'House Special Pasta', 'description' => 'From our old website', 'price' => 22.0, 'category' => 'Mains'],
    ], enrichOnly: true, source: 'website-scan');

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $items = app(ManualMenuItems::class);
    $websiteScanCategoryId = (string) $items->categories((string) $user->id)
        ->firstWhere('external_ref', MenuScanApplier::categoryRefFor('website-scan', 'Mains'))
        ->id;

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Drinks', 'items' => [
                ['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0],
            ]]],
        ]]);
        $m->shouldReceive('lastFailureReasons')->andReturn([]);
    });

    (new MenuFetchJob((string) $user->id, false))->handle(
        app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class)
    );

    // The website-scan category and its dish must survive the rebuild
    // untouched. Since slice 7 Task 8 the protection is structural rather than
    // a source_platform filter: the category lives in the `menu:website-scan:*`
    // external_ref namespace, which a scrape's own category refs can never
    // reach, so a rebuild has nothing to delete it with.
    $categories = app(ManualMenuItems::class)->categories((string) $user->id)->keyBy('id');
    expect($categories->has($websiteScanCategoryId))->toBeTrue()
        ->and((string) $categories[$websiteScanCategoryId]->label)->toBe('Mains')
        ->and(app(ManualMenuItems::class)->rows((string) $user->id)->pluck('headline')->all())
        // Both lanes land in content.* since Task 7 — the SCRAPED dish ('Cola')
        // now sits beside the website-scanned one instead of in site.menu_items.
        // What this test guards is unchanged: the scrape did not destroy the
        // website-scan dish.
        ->toBe(['Cola', 'House Special Pasta']);

    // The uber-eats rebuild still happened normally alongside it — in content.*,
    // which is where BOTH lanes write since Tasks 7 + 8. The old form of this
    // pair asked site.menu_categories for a 'uber-eats' / 'website-scan'
    // source_platform row; neither lane writes that table any more, so the same
    // two questions are asked of the labels that survived the rebuild instead:
    // 'Drinks' proves the scrape ran, 'Mains' proves it did not take the
    // website-scan category with it.
    $labels = DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $user->id)->where('kind', 'menu_category')
        ->whereNull('removed_at')->pluck('label')->sort()->values()->all();
    expect($labels)->toBe(['Drinks', 'Mains']);

    // And the scrape's own dish landed, under its own category — not merged
    // into the website-scan one.
    expect(DB::connection('pgsql')->table('content.collection_items as ci')
        ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
        ->join('content.items as i', 'i.id', '=', 'ci.item_id')
        ->where('c.user_id', $user->id)->where('c.label', 'Drinks')
        ->pluck('i.headline_cache')->all())->toBe(['Cola']);
});

it('reports (but does not fail the scrape on) a scan-reapply failure (R3-OBS-3)', function () {
    Exceptions::fake();
    $user = mfjwspUser('mfjwsp2');
    mfjwspOrdering($user);

    // Pre-seed scan_items (as GoogleMenuPhotoScanJob would) so the post-rebuild
    // reapply branch actually runs.
    Menu::updateOrCreate(['user_id' => $user->id], [
        'scan_items' => ['items' => [
            ['name' => 'Cola', 'description' => 'From Google photos', 'price' => 3.0, 'category' => 'Drinks'],
        ]],
    ]);

    $this->mock(MenuApifyScraper::class, function ($m) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
            'categories' => [['name' => 'Drinks', 'items' => [
                ['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0],
            ]]],
        ]]);
        $m->shouldReceive('lastFailureReasons')->andReturn([]);
    });

    $this->mock(MenuScanApplier::class, function ($m) {
        $m->shouldReceive('apply')->once()->andThrow(new RuntimeException('boom'));
    });

    (new MenuFetchJob((string) $user->id, false))->handle(
        app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class)
    );

    // The core scrape must still complete despite the swallowed reapply failure.
    expect(Menu::query()->where('user_id', $user->id)->first()->fetch_status)->toBe('ok');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');
});
