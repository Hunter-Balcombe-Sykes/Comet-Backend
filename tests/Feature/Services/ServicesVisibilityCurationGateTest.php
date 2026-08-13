<?php

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// B3 (final whole-branch review): ServicesVisibility, BookingVisibility and
// SitepageDataResolverService::presentPageIds()'s services page-presence
// probe each hand-rolled their own copy of "does this user have a manual
// service" with NO join to site.section_items — the content.* rewrite
// dropped the pre-cutover `->where('is_active', true)` filter and never
// replaced it, so ManualServiceWriter::exclude() ("hide this service") had
// no way to close any of the three gates. All three now route through the
// shared ManualServiceItems::activeQuery()/activePricedQuery(), the
// read-side twin of exclude(). Each test proves the OPEN state first, so a
// gate that's simply broken-closed (never opens) can't pass vacuously.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupServicesTable();
    setupBlocksTable();
    // store()/update() take the same pg_advisory_xact_lock as the
    // pre-cutover code — shim it under SQLite so the real path runs.
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

it('closes the booking gate once the only manual service is hidden', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Only Service', 'price_cents' => 5000])
        ->assertCreated()->json('service.id');

    // Booking also needs a destination — a live links-group block tagged
    // category='booking' — otherwise the gate is closed for an unrelated
    // reason and the "before" assertion below would pass vacuously.
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'site_id' => $siteId,
        'block_group' => Block::GROUP_LINKS,
        'block_type' => 'link',
        'category' => 'booking',
        'settings' => json_encode(['url' => 'https://example.com/book']),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    [$before] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($userId, $siteId, 'booking');
    expect($before)->toBeTrue();

    actingAsUser($user)->patchJson("/api/services/{$id}", ['is_active' => false])->assertOk();

    [$after, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($userId, $siteId, 'booking');
    expect($after)->toBeFalse();
    expect($reason)->toContain('active service');
});

it('closes the services gate once the only priced manual service is hidden', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Only Service', 'price_cents' => 5000])
        ->assertCreated()->json('service.id');

    [$before] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($userId, $siteId, 'services');
    expect($before)->toBeTrue();

    actingAsUser($user)->patchJson("/api/services/{$id}", ['is_active' => false])->assertOk();

    [$after, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($userId, $siteId, 'services');
    expect($after)->toBeFalse();
    expect($reason)->toContain('at least 1 service');
});

it('drops the services page from presence once the only manual service is hidden', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Only Service', 'price_cents' => 5000])
        ->assertCreated()->json('service.id');

    // presentPageIds() returns array_keys($present) — a plain list of
    // present page ids, not the associative map itself.
    $site = Site::query()->find($siteId);
    $before = app(SitepageDataResolverService::class)
        ->presentPageIds($site, AccountCapabilities::for($user), collect());
    expect($before)->toContain('services');

    actingAsUser($user)->patchJson("/api/services/{$id}", ['is_active' => false])->assertOk();

    $after = app(SitepageDataResolverService::class)
        ->presentPageIds($site, AccountCapabilities::for($user), collect());
    expect($after)->not->toContain('services');
});
