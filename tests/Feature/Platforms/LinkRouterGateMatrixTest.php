<?php
// tests/Feature/Platforms/LinkRouterGateMatrixTest.php

use App\Enums\AccountType;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\Queue;

// B2 (spec 2026-08-18-pipeline-assurance §5): every LinkRouter category × account
// shape, pinned. Two cells carry product consequences found by live waves:
//   • booking INVERTS on food — `$isBusiness ? !$isFood : true`
//   • a restaurant that signs up via Instagram is `partna` (config
//     partna.pre_account.sources), so its reservations/online-ordering demote to custom.
// gateAllows() is private; the matrix drives it by reflection so it needs no DB,
// and two end-to-end cells below prove the reflection reflects route().

function gateUser(string $accountType, ?string $sector): User
{
    $u = new User;
    $u->forceFill(['account_type' => $accountType, 'sector' => $sector]);

    return $u;
}

function gateAllows(User $user, string $category): bool
{
    $m = new ReflectionMethod(LinkRouter::class, 'gateAllows');

    return (bool) $m->invoke(app(LinkRouter::class), $user, $category);
}

dataset('gate_matrix', function () {
    $shapes = [
        'partna' => ['partna', 'hair-salon'],
        'business non-food' => ['business', 'barber'],
        'business food' => ['business', 'restaurant'],
    ];
    $expected = [
        'social' => [true, true, true],
        'booking' => [true, true, false],
        'event' => [true, true, true],
        'event-organiser' => [true, true, true],
        'shop' => [true, true, true],
        'link' => [true, true, true],
        'reservations' => [false, false, true],
        'online-ordering' => [false, false, true],
        'not-a-category' => [false, false, false],
    ];
    $rows = [];
    $i = 0;
    foreach ($shapes as $shapeName => [$type, $sector]) {
        foreach ($expected as $category => $cells) {
            $rows["{$category} × {$shapeName}"] = [$type, $sector, $category, $cells[$i]];
        }
        $i++;
    }

    return $rows;
});

it('gates each category per account shape', function (string $type, string $sector, string $category, bool $allowed) {
    expect(gateAllows(gateUser($type, $sector), $category))->toBe($allowed);
})->with('gate_matrix');

it('treats a business with an unresolved sector as non-food (the gelato gap)', function () {
    // SectorTaxonomy::FOOD_SECTORS has no gelato/ice-cream/dessert slug, so a
    // gelateria whose sector never resolves — or resolves outside FOOD_SECTORS —
    // takes the non-food column: booking allowed, reservations/ordering denied.
    $gelateria = gateUser('business', null);
    expect(gateAllows($gelateria, 'booking'))->toBeTrue()
        ->and(gateAllows($gelateria, 'online-ordering'))->toBeFalse()
        ->and(gateAllows($gelateria, 'reservations'))->toBeFalse();
});

it('is the gate route() actually applies — a denied booking link becomes a custom link end to end', function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupNotificationsTable();
    Queue::fake();

    $user = User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']);
    // Sanity: the URL classifies as booking before the gate sees it.
    expect(app(WebsiteLinkHarvester::class)->classify('https://www.fresha.com/a/doc-cuts')['category'])->toBe('booking');

    $result = app(LinkRouter::class)->route($user, 'https://www.fresha.com/a/doc-cuts', new RouteContext);

    expect($result->outcome)->toBe('custom');
});

it('is the gate route() actually applies — the same booking link on a partna account is not gate-denied', function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupNotificationsTable();
    Queue::fake();

    $user = User::factory()->create(['account_type' => 'partna', 'sector' => 'hair-salon']);

    $result = app(LinkRouter::class)->route($user, 'https://www.fresha.com/a/doc-cuts', new RouteContext);

    // seeded / pending / conflict are all "the gate let it through"; only custom is a denial.
    expect($result->outcome)->not->toBe('custom', "partna booking link came back {$result->outcome}");
});
