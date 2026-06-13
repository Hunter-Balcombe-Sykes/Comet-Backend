<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config([
        'services.cloudflare.zone_id' => 'zone-1',
        'services.cloudflare.saas_api_token' => 'cf-token',
        'services.cloudflare.saas_cname_target' => 'cname.partna.au',
    ]);
});

/** @return array{0: User, 1: Site} */
function domainUserWithSite(string $h): array
{
    $user = User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);

    $site = new Site(['subdomain' => $h]);
    $site->id = (string) Str::uuid();
    $site->user_id = $user->id;
    $site->saveQuietly(); // skip the observer so it doesn't queue a KV sync

    return [$user, $site];
}

it('connects a custom domain and stores it pending + returns the CNAME target', function () {
    [$user, $site] = domainUserWithSite('domuser');

    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => true,
        'result' => ['id' => 'ch_123', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']],
    ], 200)]);
    Queue::fake();

    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'https://Bookwith.me/'])
        ->assertOk()
        ->assertJsonPath('domain', 'bookwith.me')
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('cname_target', 'cname.partna.au');

    $site->refresh();
    expect($site->custom_domain)->toBe('bookwith.me');
    expect($site->custom_domain_cf_id)->toBe('ch_123');
    expect($site->custom_domain_status)->toBe('pending');

    Queue::assertPushed(SyncSubdomainToKvJob::class);
});

it('returns 503 when Cloudflare for SaaS is not configured', function () {
    config([
        'services.cloudflare.zone_id' => '',
        'services.cloudflare.saas_api_token' => '',
        'services.cloudflare.api_token' => '',
    ]);
    [$user] = domainUserWithSite('domu2');

    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'bookwith.me'])
        ->assertStatus(503);
});

it('rejects an invalid domain and a partna.au domain', function () {
    [$user] = domainUserWithSite('domu3');

    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'not a domain'])->assertStatus(422);
    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'alice.partna.au'])->assertStatus(422);
});

it('rejects a domain already connected to another site', function () {
    [$owner, $ownerSite] = domainUserWithSite('owner');
    $ownerSite->custom_domain = 'taken.com';
    $ownerSite->custom_domain_status = 'active';
    $ownerSite->saveQuietly();

    [$other] = domainUserWithSite('other');

    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'x']], 200)]);

    actingAsUser($other)->putJson('/api/site/custom-domain', ['domain' => 'taken.com'])
        ->assertStatus(422);
});

it('disconnects a custom domain and dispatches the retire job', function () {
    [$user, $site] = domainUserWithSite('domu4');
    $site->custom_domain = 'bookwith.me';
    $site->custom_domain_cf_id = 'ch_123';
    $site->custom_domain_status = 'active';
    $site->saveQuietly();

    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true], 200)]);
    Queue::fake();

    actingAsUser($user)->deleteJson('/api/site/custom-domain')
        ->assertOk()
        ->assertJsonPath('domain', null);

    $site->refresh();
    expect($site->custom_domain)->toBeNull();
    expect($site->custom_domain_status)->toBeNull();

    Queue::assertPushed(SyncSubdomainToKvJob::class, fn ($job) => $job->retireCustomDomain === 'bookwith.me');
});

it('auto-promotes the domain to primary on first successful verification', function () {
    [$user, $site] = domainUserWithSite('domverify');
    $site->custom_domain = 'bookwith.me';
    $site->custom_domain_cf_id = 'ch_1';
    $site->custom_domain_status = 'pending';
    $site->custom_domain_primary = false;
    $site->saveQuietly();

    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => true,
        'result' => ['id' => 'ch_1', 'status' => 'active', 'ssl' => ['status' => 'active']],
    ], 200)]);
    Queue::fake();

    actingAsUser($user)->postJson('/api/site/custom-domain/verify')
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('primary', true);

    expect((bool) $site->fresh()->custom_domain_primary)->toBeTrue();
});
