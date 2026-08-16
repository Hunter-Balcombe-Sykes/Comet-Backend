<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // SiteActionsService reads the `custom_links` pool for the `custom:`
    // action family (convergence Phase 6), so site.sections must exist.
    setupSectionsTables();
    setupServicesTable();
    setupBlocksTable();
    setupDesignKitsTable();
    setupSiteMediaTable();
    setupSubdomainAliasesTable();
});

function bookingPresenceUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'status' => 'active',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function bookingPresenceSite(User $user): Site
{
    // Site::user_id is not fillable (tenancy FK) — Site::create() would
    // silently drop it, so go through the relation like ->associate() would.
    return $user->site()->create([
        'subdomain' => $user->handle,
        'is_published' => true,
    ]);
}

function seedPresenceFresha(User $user, ?array $selection): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/anseo-studio',
            'selection' => $selection,
            'source' => 'instagram',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
}

it('keeps the services page action for a fresha row that HAS a selection', function () {
    $user = bookingPresenceUser('hassel');
    bookingPresenceSite($user);
    seedPresenceFresha($user, ['mode' => 'employee', 'services' => [], 'hiddenServiceIds' => []]);

    $res = $this->getJson("/api/public/profiles/{$user->handle}")->assertOk();

    expect($res->json('data.pageOrder'))->toContain('services');

    $action = collect($res->json('data.rankedActions'))->firstWhere('id', 'booking-services');
    expect($action)->not->toBeNull()
        ->and($action['kind'])->toBe('page')
        ->and($action['pageId'])->toBe('services');
});

it('does not emit a duplicate booking action when the services page is present', function () {
    $user = bookingPresenceUser('nodupes');
    bookingPresenceSite($user);
    seedPresenceFresha($user, ['mode' => 'employee', 'services' => [], 'hiddenServiceIds' => []]);

    $res = $this->getJson("/api/public/profiles/{$user->handle}")->assertOk();

    expect(collect($res->json('data.rankedActions'))->where('id', 'booking-services'))->toHaveCount(1);
});

it('withholds the services page and emits an external Book-now for a selection-less fresha row', function () {
    $user = bookingPresenceUser('nosel');
    bookingPresenceSite($user);
    seedPresenceFresha($user, null);

    $res = $this->getJson("/api/public/profiles/{$user->handle}")->assertOk();

    expect($res->json('data.pageOrder'))->not->toContain('services');

    $action = collect($res->json('data.rankedActions'))->firstWhere('id', 'booking-services');
    expect($action)->not->toBeNull()
        ->and($action['kind'])->toBe('external')
        ->and($action['pageId'])->toBeNull()
        ->and($action['url'])->toBe('https://www.fresha.com/a/anseo-studio');
});

it('still exposes the harvested url on /integrations so the renderer can build the button', function () {
    $user = bookingPresenceUser('intact');
    bookingPresenceSite($user);
    seedPresenceFresha($user, null);

    $this->getJson("/api/public/profiles/{$user->handle}/integrations")
        ->assertOk()
        ->assertJsonPath('data.platforms.fresha.0.payload.url', 'https://www.fresha.com/a/anseo-studio')
        ->assertJsonPath('data.platforms.fresha.0.payload.selection', null);
});

it('restores the services page once a selection is saved', function () {
    $user = bookingPresenceUser('restored');
    bookingPresenceSite($user);
    $row = seedPresenceFresha($user, null);

    expect($this->getJson("/api/public/profiles/{$user->handle}")->json('data.pageOrder'))->not->toContain('services');

    // The public-profile cache key is timestamped to site.updated_at at
    // second granularity (IndividualProfileController) — travel forward so
    // the touch() the observer fires below lands on a distinct second and
    // the second request can't hit the same cache key as the first.
    $this->travel(1)->seconds();

    $row->update(['payload' => [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => ['mode' => 'employee', 'services' => [], 'hiddenServiceIds' => []],
    ]]);

    expect($this->getJson("/api/public/profiles/{$user->handle}")->json('data.pageOrder'))->toContain('services');
});

it('does not gate a platform that never declares completeness', function () {
    $user = bookingPresenceUser('ungated');
    bookingPresenceSite($user);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'youtube',
        'resource_id' => 'youtube',
        'payload' => ['url' => 'https://youtube.com/@someone'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    expect($this->getJson("/api/public/profiles/{$user->handle}")->json('data.pageOrder'))->toContain('watch');
});

it('does not touch the site on a routine payload write for a platform with no completeness predicate', function () {
    // Guards IntegrationConnectionObserver::saved()'s touch() scoping:
    // page-presence only depends on connection payload content for
    // hasCompletenessPredicate() platforms (fresha, shop today), so a
    // routine scheduled-refresh-style payload write for any other platform
    // (youtube here, standing in for the many refreshable platforms
    // RefreshConnectionJob/PlatformRefresher cycle through) must NOT roll
    // site.updated_at — that would fan the observer's cache-touch fallout
    // (SiteObserver's purge + invalidation + warm) out to every platform's
    // routine writes instead of just the two whose presence can change.
    $user = bookingPresenceUser('untouched');
    $site = bookingPresenceSite($user);
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'youtube',
        'resource_id' => 'youtube',
        'payload' => ['url' => 'https://youtube.com/@someone'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    $before = $site->fresh()->updated_at;
    $this->travel(1)->seconds();
    $connection->update(['payload' => ['url' => 'https://youtube.com/@someone-else']]);

    expect($site->fresh()->updated_at->equalTo($before))->toBeTrue();
});
