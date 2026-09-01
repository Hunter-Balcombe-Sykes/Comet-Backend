<?php

use App\Jobs\Platforms\GoogleMenuPhotoScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * T6 (2026-08-27 unclaimed-signup quality plan, D1/D5): when an ordering
 * platform (Uber Eats / DoorDash / Square) already supplies a sufficient menu
 * (≥ 8 items), the Google-photos OCR scan runs ENRICH-ONLY WITH NO NEW ITEMS —
 * on St Ali it added price-less specials fragments ("Strawberry", "Biscoff &
 * Chocolate") next to an 86-item Uber Eats menu. And when NO ordering platform
 * is connected, the scan is the user's only menu source, so it should not sit
 * behind the fixed 5-minute settling delay (that delay exists purely to let a
 * same-connect MenuFetchJob finish first).
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupIntegrationConnectionsTable();
    Queue::fake();
});

function msgUser(string $handle): User
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

it('allowNew=false updates matched dishes but never creates scan-owned rows', function () {
    $user = msgUser('msgnonew');
    $applier = app(MenuScanApplier::class);

    // A platform-era dish the scan can match.
    $applier->apply($user, [
        ['name' => 'Fillet of Fish', 'description' => null, 'price' => 25.0, 'category' => 'Lunch'],
    ]);

    $result = $applier->apply($user, [
        // Matches the stored dish → enrichment allowed.
        ['name' => 'Fillet of Fish', 'description' => 'Crumbed market fish with tartare', 'price' => 25.0, 'category' => 'Lunch', 'dietary' => []],
        // Genuinely new → must be SKIPPED under allowNew=false.
        ['name' => 'Strawberry', 'description' => null, 'price' => null, 'category' => null],
    ], enrichOnly: true, allowNew: false);

    expect($result['added'])->toBe(0);
    expect($result['updated'])->toBe(1);

    $names = app(ManualMenuItems::class)->rows((string) $user->id)->pluck('headline');
    expect($names)->toContain('Fillet of Fish');
    expect($names)->not->toContain('Strawberry');
});

it('allowNew defaults true — existing behaviour unchanged', function () {
    $user = msgUser('msgdefault');
    $result = app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Lamb Ragu', 'description' => null, 'price' => 25.0, 'category' => 'Lunch'],
    ], enrichOnly: true);

    expect($result['added'])->toBe(1);
});

it('dispatchAfterEnrich defers only when an ordering fetch has not settled (9e)', function () {
    // No ordering platform → the scan is the only menu source; fire now.
    $bare = msgUser('msgbare');
    GoogleMenuPhotoScanJob::dispatchAfterEnrich((string) $bare->id, 'place-bare');
    Queue::assertPushed(GoogleMenuPhotoScanJob::class, function (GoogleMenuPhotoScanJob $job) {
        return $job->userId !== '' && $job->delay === null;
    });

    // Ordering platform connected with its fetch unsettled → no dispatch at
    // all: MenuFetchJob's completion chains the scan (was a blind 5-min hold;
    // the chain itself is pinned in MenuEventChainingTest).
    $ordered = msgUser('msgordered');
    IntegrationConnection::create([
        'user_id' => $ordered->id,
        'platform' => 'uber_eats.order', // surface key; DB generates platform='uber_eats' from it
        'resource_id' => 'order-x',
        'payload' => ['url' => 'https://www.ubereats.com/au/store/x'],
        'is_active' => true,
    ]);
    GoogleMenuPhotoScanJob::dispatchAfterEnrich((string) $ordered->id, 'place-ordered');
    Queue::assertNotPushed(GoogleMenuPhotoScanJob::class, function (GoogleMenuPhotoScanJob $job) use ($ordered) {
        return $job->userId === (string) $ordered->id;
    });
});

it('platformMenuSufficient is true only with a successful platform fetch AND ≥ 8 items', function () {
    $user = msgUser('msgsuff');

    // 8 platform-era items + a Menu row with a successful fetch stamp.
    $items = [];
    foreach (range(1, 8) as $i) {
        $items[] = ['name' => "Dish {$i}", 'description' => null, 'price' => 10.0 + $i, 'category' => 'Mains'];
    }
    app(MenuScanApplier::class)->apply($user, $items);
    Menu::query()->where('user_id', $user->id)->firstOrFail()
        ->forceFill(['last_successful_fetch_at' => now()])->save();

    expect(GoogleMenuPhotoScanJob::platformMenuSufficient((string) $user->id))->toBeTrue();

    // A user with items but NO successful platform fetch: not sufficient.
    $scanOnly = msgUser('msgscanonly');
    app(MenuScanApplier::class)->apply($scanOnly, $items);
    expect(GoogleMenuPhotoScanJob::platformMenuSufficient((string) $scanOnly->id))->toBeFalse();
});
