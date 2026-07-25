<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupBlocksTable();
    setupDesignKitsTable();
    setupContentSelectionTable();
    setupSiteMediaTable();
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
