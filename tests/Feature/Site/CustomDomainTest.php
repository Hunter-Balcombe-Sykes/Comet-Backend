<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Policies\SitePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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

// ── EDGE-101: CloudflareCustomHostnameService::delete() now throws on an error
// response, so these try/catch(Throwable){report($e)} sites are no longer dead
// code. Each proves report() actually fires AND the fail-open contract holds —
// the local DB write still proceeds even though the CF teardown call failed.

it('reports (does not silently swallow) when the previous-hostname teardown delete fails during store() (EDGE-101)', function () {
    [$user, $site] = domainUserWithSite('domuedge101a');
    $site->custom_domain = 'oldbook.me';
    $site->custom_domain_cf_id = 'ch_old';
    $site->custom_domain_status = 'active';
    $site->saveQuietly();

    Exceptions::fake();
    Http::fake([
        // DELETE (previous-hostname teardown) fails...
        'api.cloudflare.com/*/custom_hostnames/ch_old' => Http::response(['success' => false], 500),
        // ...but create() for the NEW domain still succeeds.
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => ['id' => 'ch_new', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']],
        ], 200),
    ]);
    Queue::fake();

    // Fail-open: the teardown failure must not block connecting the new domain.
    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'newbook.me'])
        ->assertOk()
        ->assertJsonPath('domain', 'newbook.me');

    expect($site->fresh()->custom_domain)->toBe('newbook.me');
    Exceptions::assertReported(RequestException::class);
});

it('reports (does not silently swallow) when the Cloudflare delete fails during destroy() (EDGE-101)', function () {
    [$user, $site] = domainUserWithSite('domuedge101b');
    $site->custom_domain = 'bookwith.me';
    $site->custom_domain_cf_id = 'ch_gone';
    $site->custom_domain_status = 'active';
    $site->saveQuietly();

    Exceptions::fake();
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 500)]);
    Queue::fake();

    // Fail-open: the CF-side teardown failing must not block the local disconnect.
    actingAsUser($user)->deleteJson('/api/site/custom-domain')
        ->assertOk()
        ->assertJsonPath('domain', null);

    expect($site->fresh()->custom_domain)->toBeNull();
    Exceptions::assertReported(RequestException::class);
});

it('reports when the orphan-cleanup delete itself fails after losing the unique-domain race (EDGE-101)', function () {
    [$user, $site] = domainUserWithSite('racer2');
    [, $rivalSite] = domainUserWithSite('rival2');

    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS site.sites_custom_domain_unique '.
        'ON sites (lower(custom_domain)) WHERE custom_domain IS NOT NULL'
    );

    Exceptions::fake();
    Http::fake(['api.cloudflare.com/*' => Http::sequence()
        // create() for the racer's attempted domain succeeds...
        ->push(['success' => true, 'result' => ['id' => 'ch_orphan2', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']]], 200)
        // ...but the orphan-cleanup delete() (triggered by the lost race) fails.
        ->push(['success' => false], 500),
    ]);
    Queue::fake();

    $fired = false;
    Site::saving(function (Site $s) use (&$fired, $rivalSite) {
        if (! $fired && $s->custom_domain === 'race2.com') {
            $fired = true;
            DB::connection('pgsql')->table('site.sites')
                ->where('id', $rivalSite->id)
                ->update(['custom_domain' => 'race2.com', 'custom_domain_status' => 'active']);
        }
    });

    // The 422 conflict response still lands — the cleanup failure doesn't change
    // the caller-facing outcome, it just no longer vanishes silently server-side.
    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'race2.com'])
        ->assertStatus(422);

    Exceptions::assertReported(RequestException::class);
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

// ── TEST-106: Cloudflare error handling on create() ────────────────────────
//
// The audit's proposed test (Http 500 -> assert 502) already passes against
// current code — cf->create() is wrapped in try/catch(Throwable) (lines
// 78-84 above) and CloudflareCustomHostnameService::create() calls ->throw(),
// which fires on any 4xx/5xx. That's a fine regression guard but not the gap.
//
// THE REAL GAP: Cloudflare can return HTTP 200 with `success: false` in the
// body — a genuine CF behaviour for validation errors (e.g. a hostname that
// already exists elsewhere). ->throw() does NOT fire on a 2xx status, so
// create() sails through, `json('result', [])` returns [], shape() turns
// that into all-null fields, and the controller proceeds as if creation
// succeeded: the domain gets written to the DB with status 'pending' and a
// NULL custom_domain_cf_id — no real Cloudflare hostname exists behind it.

it('returns 502 when Cloudflare create() responds with a real HTTP error status (regression guard)', function () {
    [$user] = domainUserWithSite('domucf500');

    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 500)]);

    actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'bookwith.me'])
        ->assertStatus(502);
});

it('silently stores a domain as pending with no CF id when Cloudflare returns HTTP 200 + success:false (TEST-106 gap)', function () {
    [$user, $site] = domainUserWithSite('domucfsoftfail');

    // No "result" key at all — mirrors a real CF validation-error body.
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => false,
        'errors' => [['code' => 1004, 'message' => 'Hostname already exists.']],
    ], 200)]);
    Queue::fake();

    // KNOWN GAP: the 200 status means ->throw() never fires, so this request
    // returns 200 OK — as if Cloudflare had accepted the hostname — even
    // though nothing was actually created there.
    $response = actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'bookwith.me'])
        ->assertOk();

    expect($response->json('status'))->toBe('pending');

    $site->refresh();
    expect($site->custom_domain)->toBe('bookwith.me');
    expect($site->custom_domain_status)->toBe('pending');
    // No real CF hostname was ever created — this id is fabricated as null,
    // silently, with no signal to the caller that Cloudflare rejected it.
    expect($site->custom_domain_cf_id)->toBeNull();
});
