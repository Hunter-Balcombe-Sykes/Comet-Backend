<?php

// SIGNUP-1 regression. The invariant nobody ever asserted: after
// PreAccountBuildService::requestBuild(), the provisional user's handle_lc EQUALS
// their site's subdomain. It shipped broken because the only existing coverage
// exercised a first, non-colliding build with an already-slug-shaped seed — the
// one input for which the two normalisations happen to agree.
//
// Three independent divergence causes are covered below, all reproduced from real
// diverged dev rows:
//   1. normalisation — Str::slug() DROPS '.'/'\'' where subdomainBaseFromHandle()
//      replaced them with '-'  (handle errols / subdomain errol-s)
//   2. collision suffix — HandleAllocator appends '2', buildCandidate appended '-2'
//      (handle simondoylehair2 / subdomain simondoylehair-3)
//   3. the name being unavailable on only ONE of the two sides (an existing site,
//      or an ACTIVE subdomain alias, holding a subdomain whose handle is free)

use App\Exceptions\Site\SubdomainUnavailableException;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\User\SiteProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.site_subdomain_aliases
    setupPreAccountBuildsTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

/** The invariant under test, asserted from the DB rather than the in-memory models. */
function expectConverged(User $user): void
{
    $subdomain = DB::connection('pgsql')->table('site.sites')
        ->where('user_id', $user->id)
        ->value('subdomain');

    expect($subdomain)->not->toBeNull()
        ->and(strtolower((string) $subdomain))->toBe($user->fresh()->handle_lc);
}

it('provisions the site on the handle the user was actually allocated', function () {
    $build = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'janedoe', null, hash('sha256', 'a'),
    )['build'];

    expect($build->user->handle_lc)->toBe('janedoe')
        ->and($build->user->site->subdomain)->toBe('janedoe');
    expectConverged($build->user);
});

// Cause 1 — dotted Instagram ref. Str::slug('d.o.c.pizza') = 'docpizza';
// subdomainBaseFromHandle('d.o.c.pizza') = 'd-o-c-pizza'. Live dev row.
it('converges when the source ref contains periods (D.O.C. Pizza shape)', function () {
    $build = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'd.o.c.pizza', null, hash('sha256', 'b'),
    )['build'];

    expect($build->user->handle_lc)->toBe('docpizza')
        ->and($build->user->site->subdomain)->toBe('docpizza');
    expectConverged($build->user);
});

// Cause 1 — apostrophe in a Google Business name. Str::slug("Errol's") = 'errols';
// subdomainBaseFromHandle("Errol's") = 'errol-s'. Live dev row.
it('converges when the source name contains an apostrophe (Errol\'s shape)', function () {
    $build = app(PreAccountBuildService::class)->requestBuild(
        'business', 'google_business', 'ChIJerrols00000', "Errol's", hash('sha256', 'c'),
    )['build'];

    expect($build->user->handle_lc)->toBe('errols')
        ->and($build->user->site->subdomain)->toBe('errols');
    expectConverged($build->user);
});

// Cause 2 — THE path the shipped coverage never took. Pre-fix this produced
// handle 'janedoe1' (bare-integer suffix, because the handle was taken) beside
// subdomain 'janedoe' (no suffix at all, because no site held it).
it('converges on the handle-collision path', function () {
    User::factory()->create(['handle' => 'janedoe', 'handle_lc' => 'janedoe']);

    $build = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'janedoe', null, hash('sha256', 'd'),
    )['build'];

    expect($build->user->handle_lc)->toBe('janedoe1')
        ->and($build->user->site->subdomain)->toBe('janedoe1');
    expectConverged($build->user);
});

it('converges through several consecutive collisions', function () {
    $svc = app(PreAccountBuildService::class);

    // Google Business seeds the handle from the business NAME, so three distinct
    // place_ids with the same name collide three times in a row.
    foreach (['e', 'f', 'g'] as $i => $salt) {
        $build = $svc->requestBuild('business', 'google_business', 'ChIJtwin0000'.$i, 'Twin', hash('sha256', $salt))['build'];
        expectConverged($build->user);
    }

    // Same seed name each time → 'twin', 'twin1', 'twin2' on BOTH sides.
    expect(DB::connection('pgsql')->table('site.sites')->orderBy('subdomain')->pluck('subdomain')->all())
        ->toBe(['twin', 'twin1', 'twin2']);
});

// Cause 3, site side — a legacy diverged row holds the subdomain while the
// handle is free. Pre-fix the allocator never looked at site.sites, so it handed
// back 'janedoe' and provisioning silently suffixed the SITE to 'janedoe-1'.
it('rejects a handle whose subdomain is already held by another site', function () {
    $other = User::factory()->create(['handle' => 'other', 'handle_lc' => 'other']);
    Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'janedoe']);

    $build = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'janedoe', null, hash('sha256', 'h'),
    )['build'];

    expect($build->user->handle_lc)->toBe('janedoe1')
        ->and($build->user->site->subdomain)->toBe('janedoe1');
    expectConverged($build->user);
});

// Cause 3, alias side. The alias table's unique index is GLOBAL on
// lower(subdomain), so an active alias makes the name unusable as a subdomain
// even though site.sites has no row for it — and the handle side never knew.
it('rejects a handle whose subdomain is held by an ACTIVE alias', function () {
    $other = User::factory()->create(['handle' => 'other', 'handle_lc' => 'other']);
    $site = Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'other']);

    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $site->id,
        'subdomain' => 'janedoe',
        'expires_at' => now()->addDays(90),
        'created_at' => now(),
    ]);

    $build = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'janedoe', null, hash('sha256', 'i'),
    )['build'];

    expect($build->user->handle_lc)->toBe('janedoe1')
        ->and($build->user->site->subdomain)->toBe('janedoe1');
    expectConverged($build->user);
});

// The mirror case: an EXPIRED alias has released the name back to the pool and
// must not lock anyone out (matches RenameSubdomainAction / the ->active() scope).
it('allows a handle whose subdomain is held only by an EXPIRED alias', function () {
    $other = User::factory()->create(['handle' => 'other', 'handle_lc' => 'other']);
    $site = Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'other']);

    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $site->id,
        'subdomain' => 'janedoe',
        'expires_at' => now()->subDay(),
        'created_at' => now()->subDays(91),
    ]);

    $build = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'janedoe', null, hash('sha256', 'j'),
    )['build'];

    expect($build->user->handle_lc)->toBe('janedoe')
        ->and($build->user->site->subdomain)->toBe('janedoe');
});

it('never allocates a reserved subdomain as a handle', function () {
    $build = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'admin', null, hash('sha256', 'k'),
    )['build'];

    expect($build->user->handle_lc)->toBe('admin1');
    expectConverged($build->user);
});

// The provisioning guard itself: an exact-path collision is an anomaly, and the
// decided behaviour is a loud failure rather than a silently suffixed subdomain.
it('createSiteForHandle refuses to suffix and throws when the subdomain is taken', function () {
    $other = User::factory()->create(['handle' => 'other', 'handle_lc' => 'other']);
    Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'janedoe']);
    $user = User::factory()->create(['handle' => 'janedoe', 'handle_lc' => 'janedoe']);

    expect(fn () => app(SiteProvisioningService::class)->createSiteForHandle($user->id, 'janedoe'))
        ->toThrow(SubdomainUnavailableException::class);

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('createSiteForHandle refuses a handle longer than the 63-char DNS label limit', function () {
    $user = User::factory()->create(['handle' => 'x', 'handle_lc' => 'x']);

    expect(fn () => app(SiteProvisioningService::class)->createSiteForHandle($user->id, str_repeat('a', 64)))
        ->toThrow(SubdomainUnavailableException::class);
});

// The guard applies the reserved predicate ITSELF rather than trusting the caller:
// only the pre-account path allocates through HandleAllocator. BootstrapRequest
// never checks a client-supplied handle against the reserved list.
it('createSiteForHandle refuses a reserved subdomain', function () {
    $user = User::factory()->create(['handle' => 'support', 'handle_lc' => 'support']);

    expect(fn () => app(SiteProvisioningService::class)->createSiteForHandle($user->id, 'support'))
        ->toThrow(SubdomainUnavailableException::class, 'reserved subdomain');

    expect(DB::connection('pgsql')->table('site.sites')->count())->toBe(0);
});

// sites_user_unique and core_sites_subdomain_lower_unique both surface as a bare
// 23505 out of tryCreateSite(); reporting the wrong one sends a debugger down the
// wrong path entirely.
it('createSiteForHandle names the real cause when the user already has a site', function () {
    $user = User::factory()->create(['handle' => 'janedoe', 'handle_lc' => 'janedoe']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);

    expect(fn () => app(SiteProvisioningService::class)->createSiteForHandle($user->id, 'janedoe'))
        ->toThrow(SubdomainUnavailableException::class, 'another site already holds this subdomain');

    // The other branch: the user holds a site under a DIFFERENT subdomain, so the
    // subdomain pre-check passes and only sites_user_unique can fire.
    $second = User::factory()->create(['handle' => 'twin', 'handle_lc' => 'twin']);
    Site::factory()->create(['user_id' => $second->id, 'subdomain' => 'somethingelse']);

    expect(fn () => app(SiteProvisioningService::class)->createSiteForHandle($second->id, 'twin'))
        ->toThrow(SubdomainUnavailableException::class, 'this user already has a site');
});

// The active-alias leg is pre-checked rather than left to tryCreateSite()'s null
// return, which is indistinguishable there from a lost race. An active alias is
// stable state that reproduces on every retry, so labelling it "concurrent" sends
// a debugger after a race that cannot exist.
it('createSiteForHandle names an active subdomain alias as the cause, not a race', function () {
    $user = User::factory()->create(['handle' => 'errol-s', 'handle_lc' => 'errol-s']);
    $holder = Site::factory()->create(['subdomain' => 'errols']);

    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $holder->id,
        'subdomain' => 'errol-s',
        'expires_at' => now()->addDays(90),
        'created_at' => now(),
    ]);

    expect(fn () => app(SiteProvisioningService::class)->createSiteForHandle($user->id, 'errol-s'))
        ->toThrow(SubdomainUnavailableException::class, 'an active subdomain alias holds this name');

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->exists())->toBeFalse();
});

// Whether a refusal is an alarm is the CALLER's policy, not the guard's. Without
// this pair, moving report() back inside SiteProvisioningService would leave every
// other test green while bootstrap filed a Nightwatch exception per user typo.
it('leaves the guard itself silent — reporting is the caller decision', function () {
    Exceptions::fake();

    $user = User::factory()->create(['handle' => 'support', 'handle_lc' => 'support']);

    expect(fn () => app(SiteProvisioningService::class)->createSiteForHandle($user->id, 'support'))
        ->toThrow(SubdomainUnavailableException::class);

    Exceptions::assertNothingReported();
});

it('reports a pre-account refusal, because there the handle was machine-allocated', function () {
    Exceptions::fake();

    // HandleAllocator now proves a candidate free as a subdomain too, so the guard
    // is unreachable through requestBuild() by data alone — which is the point of
    // the fix. Stub the provisioner to fire it anyway: what is under test is the
    // CALL SITE's reaction (report + re-throw), not the guard's own condition.
    // Bound before requestBuild() resolves the service out of the container.
    $provisioning = Mockery::mock(SiteProvisioningService::class);
    $provisioning->shouldReceive('createSiteForHandle')
        ->once()
        ->andThrow(new SubdomainUnavailableException('allocator guarantee broke'));
    app()->instance(SiteProvisioningService::class, $provisioning);

    expect(fn () => app(PreAccountBuildService::class)->requestBuild(
        accountType: 'partna',
        sourceType: 'instagram',
        rawSourceRef: '@janedoe',
        sourceName: 'Jane Doe',
        ipHash: null,
    ))->toThrow(SubdomainUnavailableException::class);

    Exceptions::assertReported(SubdomainUnavailableException::class);
});
