<?php

use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Content\ManualMenuWriter;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// The menu pass's WIRE SHAPE, as opposed to its pass list (SetupControllerTest)
// or its provenance stamping (MenuProvenanceTest). Both facts pinned here were
// live defects on 2026-09-03:
//
//   · no item carried `selected` at all, so the dashboard — which seeds its
//     ticks from that key — rendered every dish OFF under a heading reading
//     "Everything's on. Untick anything that's off the menu." 75 checkboxes,
//     0 ticked, measured in the browser.
//   · `found` was a list of ITEMS while the dashboard maps it as a list of
//     CATEGORIES and reads `.items` off each. Any account with a scan
//     discovery would have hit `.items.map` on undefined. It never fired only
//     because no dev account had one.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    setupIngestTables();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    AccountCapabilities::flushCache();
    Queue::fake();
});

function smwUser(string $handle): User
{
    $user = createTenant($handle);
    $user->forceFill(['account_type' => 'business', 'sector' => 'restaurant'])->save();
    AccountCapabilities::flushCache();

    return $user;
}

/** A platform-owned dish — lands in a real category, not in `found`. */
function smwPlatformDish(User $user, Menu $menu, string $name): void
{
    $writer = app(ManualMenuWriter::class);
    $writer->write(
        (string) $user->id,
        $writer->coordFor((string) $menu->id, $name),
        $writer->projectionFor(
            (object) ['name' => $name, 'description' => 'On the menu.', 'base_price' => 14.0],
            [['id' => (string) Str::uuid(), 'name' => 'Mains', 'position' => 0]],
            [],
            $menu,
        ),
    );
}

/** Pull the menu pass off the composed setup payload. */
function smwMenuPass(User $user): array
{
    $passes = actingAsUser($user)->getJson('/api/site/setup')->json('passes');

    return collect($passes)->firstWhere('key', 'menu') ?? [];
}

it('ships every menu dish pre-selected, as the step copy already promises', function () {
    $user = smwUser('smw-selected');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats',
        'currency' => 'AUD', 'fetch_status' => 'ok',
    ]);
    smwPlatformDish($user, $menu, 'Margherita');
    smwPlatformDish($user, $menu, 'Marinara');

    $pass = smwMenuPass($user);

    $items = collect($pass['categories'] ?? [])->flatMap(fn ($c) => $c['items']);

    expect($items)->not->toBeEmpty()
        ->and($items->pluck('name')->all())->toContain('Margherita', 'Marinara');

    // The point of the test: not one of them is off.
    foreach ($items as $item) {
        expect($item)->toHaveKey('selected')
            ->and($item['selected'])->toBeTrue();
    }
});

it('sends found dishes as a CATEGORY, not as bare items', function () {
    $user = smwUser('smw-found');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats',
        'currency' => 'AUD', 'fetch_status' => 'ok',
    ]);
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Scan Special', 'description' => 'From the photo scan.', 'price' => 9.5, 'category' => 'Specials'],
    ], enrichOnly: true);

    $found = smwMenuPass($user)['found'] ?? [];

    expect($found)->not->toBeEmpty();

    // Every entry is a category the dashboard can map: an id, a name to head
    // it with, and an `items` LIST. Reading `.items` is exactly what the
    // dashboard does, so asserting the key is asserting the contract.
    foreach ($found as $group) {
        expect($group)->toHaveKeys(['id', 'name', 'items'])
            ->and($group['items'])->toBeArray()
            ->and($group['items'])->not->toBeEmpty();

        foreach ($group['items'] as $item) {
            expect($item)->toHaveKeys(['id', 'name', 'selected', 'photo'])
                ->and($item['selected'])->toBeTrue();
        }
    }

    expect(collect($found)->flatMap(fn ($g) => $g['items'])->pluck('name')->all())
        ->toContain('Scan Special');
});

it('omits the found group entirely when nothing was discovered', function () {
    $user = smwUser('smw-nofound');
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats',
        'currency' => 'AUD', 'fetch_status' => 'ok',
    ]);
    smwPlatformDish($user, $menu, 'Margherita');

    // An empty ARRAY, never a category with no items — the dashboard would
    // otherwise head an empty grid with a "Found on your website" title.
    expect(smwMenuPass($user)['found'] ?? null)->toBe([]);
});
