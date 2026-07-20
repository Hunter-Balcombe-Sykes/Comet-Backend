<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Policies\SitePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

it('cleans up the orphaned Cloudflare hostname and returns 422 when a concurrent save loses the unique-domain race (LIFE-5)', function () {
    [$user, $site] = domainUserWithSite('racer');
    [$rival, $rivalSite] = domainUserWithSite('rival');

    // Real partial unique index so a duplicate lower(custom_domain) actually throws
    // UniqueConstraintViolationException on the sqlite test driver (mirrors prod).
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS site.sites_custom_domain_unique '.
        'ON sites (lower(custom_domain)) WHERE custom_domain IS NOT NULL'
    );

    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => true,
        'result' => ['id' => 'ch_orphan', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']],
    ], 200)]);
    Queue::fake();

    // One-shot hook: the instant the racer's site is saved with the domain, a rival
    // claims the same domain first — opening the TOCTOU window the pre-check missed.
    $fired = false;
    Site::saving(function (Site $s) use (&$fired, $rivalSite) {
        if (! $fired && $s->custom_domain === 'race.com') {
            $fired = true;
            DB::connection('pgsql')->table('site.sites')
                ->where('id', $rivalSite->id)
                ->update(['custom_domain' => 'race.com', 'custom_domain_status' => 'active']);
        }
    });

    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'race.com'])
        ->assertStatus(422);

    // The orphaned CF hostname was torn down (DELETE to .../custom_hostnames/ch_orphan).
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'custom_hostnames/ch_orphan'));

    // The racer's own row did not keep the domain (save rolled back).
    expect($site->fresh()->custom_domain)->toBeNull();
});

// ── SEC-108: ownership gates on every read/mutator ─────────────────────────
// siteOrFail() now authorizes 'view' (covers show() + every mutator's entry
// point), and each mutator additionally authorizes 'update' immediately
// before its own write. Ownership is already structurally guaranteed (site
// is always resolved off the caller's OWN relation), so none of this can
// actually deny through a real request today — these prove the happy paths
// are unaffected and the no-site 404 still fires the same way it always has.

it('sets and unsets the primary domain via the dedicated endpoint', function () {
    [$user, $site] = domainUserWithSite('domuprimary');
    $site->custom_domain = 'bookwith.me';
    $site->custom_domain_status = 'active';
    $site->custom_domain_primary = false;
    $site->saveQuietly();

    actingAsUser($user)->postJson('/api/site/custom-domain/primary', ['primary' => true])
        ->assertOk()
        ->assertJsonPath('primary', true);
    expect((bool) $site->fresh()->custom_domain_primary)->toBeTrue();

    actingAsUser($user)->postJson('/api/site/custom-domain/primary', ['primary' => false])
        ->assertOk()
        ->assertJsonPath('primary', false);
    expect((bool) $site->fresh()->custom_domain_primary)->toBeFalse();
});

it('404s show/store/verify/setPrimary/destroy for a user with no site', function () {
    $user = User::create([
        'handle' => 'nosite', 'handle_lc' => 'nosite', 'display_name' => 'Nosite',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'nosite@example.com',
    ]);

    actingAsUser($user)->getJson('/api/site/custom-domain')->assertStatus(404);
    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'bookwith.me'])->assertStatus(404);
    actingAsUser($user)->postJson('/api/site/custom-domain/verify')->assertStatus(404);
    actingAsUser($user)->postJson('/api/site/custom-domain/primary', ['primary' => true])->assertStatus(404);
    actingAsUser($user)->deleteJson('/api/site/custom-domain')->assertStatus(404);
});

it('never calls Cloudflare create() when the ownership authorize denies before any external write', function () {
    [$user] = domainUserWithSite('domudeny');

    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => true,
        'result' => ['id' => 'ch_should_never_exist'],
    ], 200)]);

    // Force the 'update' gate to deny for this request only — the ownership
    // check can't actually deny through a real request (site is always the
    // caller's own), so this simulates the guard catching something (a future
    // by-id path). Proves the authorize call really does sit BEFORE
    // cf->create(), not merely before $site->save() — a denial here must
    // leave zero trace of a live CF custom hostname.
    $this->app->bind(SitePolicy::class, fn () => new class extends SitePolicy
    {
        public function update(User $actor, Model $resource): bool|Response
        {
            return Response::denyAsNotFound();
        }
    });

    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'bookwith.me'])
        ->assertStatus(404);

    Http::assertNothingSent();
});

it('never calls Cloudflare delete() when the ownership authorize denies before any external write', function () {
    [$user, $site] = domainUserWithSite('domudeny2');
    $site->custom_domain = 'bookwith.me';
    $site->custom_domain_cf_id = 'ch_should_never_be_deleted';
    $site->custom_domain_status = 'active';
    $site->saveQuietly();

    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true], 200)]);

    // Force the 'update' gate to deny for this request only — the ownership
    // check can't actually deny through a real request (site is always the
    // caller's own), so this simulates the guard catching something (a future
    // by-id path). Proves the authorize call really does sit BEFORE
    // cf->delete(), not merely before $site->save() — a denial here must
    // leave the live CF custom hostname untouched.
    $this->app->bind(SitePolicy::class, fn () => new class extends SitePolicy
    {
        public function update(User $actor, Model $resource): bool|Response
        {
            return Response::denyAsNotFound();
        }
    });

    actingAsUser($user)->deleteJson('/api/site/custom-domain')
        ->assertStatus(404);

    Http::assertNothingSent();
});
