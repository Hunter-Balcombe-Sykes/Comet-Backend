<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\PreAccount\ApproveEarlyAccessBuildJob;
use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\Generators\SiteSourceGenerator;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\PreAccount\SourceGenerationException;
use App\Services\PreAccount\SourceGeneratorRegistry;
use App\Services\PreAccount\SourcePrefetch;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    setupEarlyAccessTable();
    config(['app.frontend_url' => 'https://app.partna.au']);
});

it('re-scrapes IG, opens the window, flips to invited, and leaves the invite to the sweep', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    // Published, as the real lane leaves it: requestBuild(publish: true) ->
    // GeneratePreAccountSiteJob flips is_published before this job ever runs.
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_jane', 'is_published' => true]);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    // user_id is deliberately not $fillable on EarlyAccessSignup (B11 doctrine) —
    // create() would silently drop it, so link it via forceFill like the real
    // write path (EarlyAccessService::signupFromMarketing) does.
    $signup = EarlyAccessSignup::create([
        'email' => 'lead@example.com', 'email_lc' => 'lead@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class implements SiteSourceGenerator
        {
            public function normalizeRef(string $raw): string
            {
                return $raw;
            }

            public function dedupeKey(string $normalizedRef): string
            {
                return $normalizedRef;
            }

            public function handleSeed(string $normalizedRef, ?string $sourceName): string
            {
                return $normalizedRef;
            }

            public function prefetch(string $sourceRef, ?string $sourceName, ?string $userId = null): SourcePrefetch
            {
                return new SourcePrefetch(payload: []);
            }

            public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false, ?SourcePrefetch $prefetch = null): void {}
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new ApproveEarlyAccessBuildJob($signup->id, $build->source_type))->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    expect($build->fresh()->expires_at)->not->toBeNull()
        ->and($signup->fresh()->status)->toBe('invited');
    // Moved off build_state=ready (2026-09-03): approval opens the window, the
    // sweep sends once the cascade has actually landed.
    Mail::assertNotQueued(ClaimInviteMail::class);

    $build->fresh()->forceFill(['content_filled_at' => now(), 'enriched_at' => now()])->save();
    markBuildPlatformsLanded($build);
    $this->artisan('builds:settle-sweep');

    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
});

it('does not re-scrape a GBP early-access build, but still opens the window; the sweep invites', function () {
    Mail::fake();
    // GBP stays on the official-API refresh treadmill (spec §3.4): $needsScrape is
    // gated on build_state=failed OR source_type=instagram, so a healthy GBP build
    // must skip the registry entirely — never re-scrape on approval.
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_gbp_jane', 'is_published' => true]);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'google_business', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead-gbp@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    $signup = EarlyAccessSignup::create([
        'email' => 'lead-gbp@example.com', 'email_lc' => 'lead-gbp@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    // Asserting `for()` is never called proves generate() can't have run either —
    // the registry is only ever touched inside the $needsScrape branch.
    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $mock->shouldReceive('for')->never();
    });

    (new ApproveEarlyAccessBuildJob($signup->id, $build->source_type))->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    expect($build->fresh()->expires_at)->not->toBeNull()
        ->and($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY)
        ->and($signup->fresh()->status)->toBe('invited');
    Mail::assertNotQueued(ClaimInviteMail::class);

    // A google_business build needs no platforms row -- that term is
    // instagram-only -- so the two tier stamps are the whole settle rule here.
    $build->fresh()->forceFill(['content_filled_at' => now(), 'enriched_at' => now()])->save();
    $this->artisan('builds:settle-sweep');

    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead-gbp@example.com');
});

it('flips to failed, does not notify, and reports when the re-scrape throws (never invite on a failed refresh) (R3-OBS-4)', function () {
    Exceptions::fake();
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_scrapefail_jane']);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead-scrapefail@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    $signup = EarlyAccessSignup::create([
        'email' => 'lead-scrapefail@example.com', 'email_lc' => 'lead-scrapefail@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class implements SiteSourceGenerator
        {
            public function prefetch(string $sourceRef, ?string $sourceName, ?string $userId = null): SourcePrefetch
            {
                return new SourcePrefetch(payload: []);
            }

            public function normalizeRef(string $raw): string
            {
                return $raw;
            }

            public function dedupeKey(string $normalizedRef): string
            {
                return $normalizedRef;
            }

            public function handleSeed(string $normalizedRef, ?string $sourceName): string
            {
                return $normalizedRef;
            }

            public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false, ?SourcePrefetch $prefetch = null): void
            {
                throw SourceGenerationException::scrapeFailed('boom');
            }
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new ApproveEarlyAccessBuildJob($signup->id, $build->source_type))->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_FAILED)
        ->and($build->fresh()->expires_at)->toBeNull()
        ->and($signup->fresh()->status)->toBe('waitlist');
    Mail::assertNothingQueued();
    Exceptions::assertReported(fn (SourceGenerationException $e) => $e->failureCode === PreAccountBuild::FAILURE_SCRAPE_FAILED);
});

it('no-ops when the signup has no linked build (user_id null)', function () {
    Mail::fake();
    $signup = EarlyAccessSignup::create([
        'email' => 'unlinked@example.com', 'email_lc' => 'unlinked@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);

    // No linked build → source_type is unused (the job early-returns); pass a
    // valid literal to satisfy the required constructor arg.
    (new ApproveEarlyAccessBuildJob($signup->id, 'instagram'))->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    expect($signup->fresh()->status)->toBe('waitlist');
    Mail::assertNothingQueued();
});

it('no-ops when the build is already claimed', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'active', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_claimed_jane']);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead-claimed@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    $build->forceFill(['claimed_at' => now()])->save(); // SEC-4: claimed_at is not fillable

    $signup = EarlyAccessSignup::create([
        'email' => 'lead-claimed@example.com', 'email_lc' => 'lead-claimed@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    (new ApproveEarlyAccessBuildJob($signup->id, $build->source_type))->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    expect($build->fresh()->expires_at)->toBeNull()
        ->and($signup->fresh()->status)->toBe('waitlist');
    Mail::assertNothingQueued();
});

// ── The regression that shipped in 45a87669a, now pinned ────────────────────
// Removing the synchronous build from the public marketing form (it was a
// permanent handle squat: anonymous, no IP cap, expires_at NULL so never
// pruned) left EVERY new signup with user_id = null. This job only FRESHENED
// an existing build and early-returned on a null link, so approval became a
// silent no-op — staff got 202 {ok:true} and nothing was ever built. Nothing
// in the suite caught it, because every existing test here pre-links a build.
it('CREATES the build when the signup has none — approval is where outreach sites are born now', function () {
    Mail::fake();
    Queue::fake();

    $staff = PartnaStaff::factory()->create();
    $signup = EarlyAccessSignup::create([
        'email' => 'newlead@example.com',
        'email_lc' => 'newlead@example.com',
        'type' => 'partna',
        'source' => 'marketing',
        'source_type' => 'instagram',
        'source_ref' => 'brandnewhandle',
    ]);
    expect($signup->user_id)->toBeNull()
        ->and(PreAccountBuild::count())->toBe(0);

    (new ApproveEarlyAccessBuildJob($signup->id, 'instagram', $staff->id))
        ->handle(
            app(SourceGeneratorRegistry::class),
            app(PreAccountBuildService::class)
        );

    $build = PreAccountBuild::firstOrFail();
    expect($build->built_via)->toBe(PreAccountBuild::VIA_EARLY_ACCESS)
        // Stamped with the approving staff, which is what makes
        // PreAccountBuild::isOutreach() true and applies the claim invite-gate.
        ->and($build->built_by_staff_id)->toBe($staff->id)
        // The invited address is on the row, so the gate has someone to admit.
        ->and($build->contact_email)->toBe('newlead@example.com')
        // And the signup is linked, so a second approval freshens rather than
        // building a duplicate.
        ->and($signup->fresh()->user_id)->toBe($build->user_id);
});

it('does not build twice when approval runs again for an already-linked signup', function () {
    Mail::fake();
    Queue::fake();

    $staff = PartnaStaff::factory()->create();
    $signup = EarlyAccessSignup::create([
        'email' => 'twice@example.com', 'email_lc' => 'twice@example.com', 'type' => 'partna',
        'source' => 'marketing', 'source_type' => 'instagram', 'source_ref' => 'twicehandle',
    ]);

    $job = fn () => (new ApproveEarlyAccessBuildJob($signup->id, 'instagram', $staff->id))
        ->handle(
            app(SourceGeneratorRegistry::class),
            app(PreAccountBuildService::class)
        );
    $job();
    $job();

    expect(PreAccountBuild::count())->toBe(1);
});

// #JOB-3 — four post-report `return`s meant Horizon recorded these runs as
// PROCESSED. Staff approve an early-access signup, the job hits a failure, and
// the only queue-level signal says "fine" while the invitee is never invited.
// report() reaches Nightwatch; the HORIZON signal is what was missing.
//
// InteractsWithQueue::fail() is a no-op without a bound queue job, so these
// tests attach one — same shape as CloudflareCachePurgeJobTest.

/** A generator whose generate() throws whatever it is handed. */
function throwingGenerator(Throwable $toThrow): SiteSourceGenerator
{
    return new class($toThrow) implements SiteSourceGenerator
    {
        public function prefetch(string $sourceRef, ?string $sourceName, ?string $userId = null): SourcePrefetch
        {
            return new SourcePrefetch(payload: []);
        }

        public function __construct(private Throwable $toThrow) {}

        public function normalizeRef(string $raw): string
        {
            return $raw;
        }

        public function dedupeKey(string $normalizedRef): string
        {
            return $normalizedRef;
        }

        public function handleSeed(string $normalizedRef, ?string $sourceName): string
        {
            return $normalizedRef;
        }

        public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false, ?SourcePrefetch $prefetch = null): void
        {
            throw $this->toThrow;
        }
    };
}

/** Signup + unclaimed user + READY early-access build, linked as the real path does. */
function approvableSignup(string $slug): EarlyAccessSignup
{
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_'.$slug]);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => $slug.'@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    $signup = EarlyAccessSignup::create([
        'email' => $slug.'@example.com', 'email_lc' => $slug.'@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    return $signup;
}

it('fails the job when the re-scrape throws SourceGenerationException (#JOB-3)', function () {
    Exceptions::fake();
    Mail::fake();
    $signup = approvableSignup('jobfail-scrape');
    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $mock->shouldReceive('for')->andReturn(throwingGenerator(SourceGenerationException::scrapeFailed('boom')));
    });

    $job = new ApproveEarlyAccessBuildJob($signup->id, 'instagram');
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(SourceGenerationException::class));
    $job->setJob($queueJob);

    $job->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    // The pre-existing contract still holds: failed state, no invite.
    expect($signup->fresh()->status)->toBe('waitlist');
    Mail::assertNothingQueued();
});

it('fails the job when the re-scrape throws an unclassified Throwable (#JOB-3)', function () {
    Exceptions::fake();
    Mail::fake();
    $signup = approvableSignup('jobfail-generic');
    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $mock->shouldReceive('for')->andReturn(throwingGenerator(new RuntimeException('unclassified')));
    });

    $job = new ApproveEarlyAccessBuildJob($signup->id, 'instagram');
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(RuntimeException::class));
    $job->setJob($queueJob);

    $job->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    expect($signup->fresh()->build?->build_state ?? PreAccountBuild::STATE_FAILED)->toBe(PreAccountBuild::STATE_FAILED);
});

it('does NOT fail the job on a happy approval — fail() is not fired indiscriminately (#JOB-3)', function () {
    // The negative half. Without it, a mutant that called fail() on every path
    // would satisfy both tests above.
    Mail::fake();
    $signup = approvableSignup('jobfail-happy');
    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class implements SiteSourceGenerator
        {
            public function normalizeRef(string $raw): string
            {
                return $raw;
            }

            public function dedupeKey(string $normalizedRef): string
            {
                return $normalizedRef;
            }

            public function handleSeed(string $normalizedRef, ?string $sourceName): string
            {
                return $normalizedRef;
            }

            public function prefetch(string $sourceRef, ?string $sourceName, ?string $userId = null): SourcePrefetch
            {
                return new SourcePrefetch(payload: []);
            }

            public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false, ?SourcePrefetch $prefetch = null): void {}
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    $job = new ApproveEarlyAccessBuildJob($signup->id, 'instagram');
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldNotReceive('fail');
    $job->setJob($queueJob);

    $job->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    expect($signup->fresh()->status)->toBe(EarlyAccessSignup::STATUS_INVITED);
});

// Review round 1 gap: the build_failed fail() and the collision NON-fail were
// asserted in the commit message but not proven. A mutant adding fail() to the
// collision branch passed every other test in this file, because the happy-path
// negative test uses a PRE-LINKED signup and so never enters the
// `if ($signup->user_id === null)` block where the collision check lives.

/** An UNLINKED signup, so handle() takes the requestBuild() branch. */
function unlinkedSignup(string $slug): EarlyAccessSignup
{
    return EarlyAccessSignup::create([
        'email' => $slug.'@example.com', 'email_lc' => $slug.'@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
        'source_type' => 'instagram', 'source_ref' => $slug,
    ]);
}

it('fails the job when requestBuild() throws (#JOB-3 build_failed path)', function () {
    Exceptions::fake();
    Mail::fake();
    $signup = unlinkedSignup('jobfail-build');
    $this->mock(PreAccountBuildService::class, function ($mock) {
        $mock->shouldReceive('requestBuild')->once()->andThrow(new RuntimeException('build blew up'));
    });

    $job = new ApproveEarlyAccessBuildJob($signup->id, 'instagram');
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(RuntimeException::class));
    $job->setJob($queueJob);

    $job->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    expect($signup->fresh()->status)->toBe('waitlist');
    Mail::assertNothingQueued();
});

it('does NOT fail the job on a build collision — a live build already exists (#JOB-3)', function () {
    // The deliberate quiet-return path. Without this test a mutant that failed
    // the job here would be invisible.
    Mail::fake();
    $signup = unlinkedSignup('jobfail-collision');
    $other = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'ea_collision']);
    $colliding = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_STAFF, 'expires_at' => null,
    ]);
    $colliding->user()->associate($other);
    $colliding->save();

    // requestBuild()'s dedupe re-serves the existing live build for this source.
    $this->mock(PreAccountBuildService::class, function ($mock) use ($colliding) {
        $mock->shouldReceive('requestBuild')->once()->andReturn(['build' => $colliding]);
    });

    $job = new ApproveEarlyAccessBuildJob($signup->id, 'instagram');
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldNotReceive('fail');
    $job->setJob($queueJob);

    $job->handle(app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class));

    // A legitimate no-op: not failed, not invited, not linked to the other build.
    expect($signup->fresh()->status)->toBe('waitlist');
    expect($signup->fresh()->user_id)->toBeNull();
    Mail::assertNothingQueued();
});

// Opening the claim window has to make the subdomain resolve again. Until the
// window opens the build carries expires_at = NULL on purpose (an unapproved
// build must never be pruned), and SyncSubdomainToKvJob reads that same null as
// "gone" — so every sync before this point RETIRED the handle. Nothing
// re-dispatched afterwards: this job never referenced the KV job and
// PreAccountBuild has no observer, so the invite pointed at a subdomain that
// did not resolve.
//
// Found 2026-08-30 by a fleet sweep — 156/161 unclaimed sites answered 200, 3
// answered 404 correctly (failed builds), and the only two anomalies were
// READY-but-404 builds, both built_via=early_access with a null expires_at.
it('re-syncs the route when it opens the claim window', function () {
    Mail::fake();
    Queue::fake([SyncSubdomainToKvJob::class]);

    // GBP shape so the job skips the re-scrape branch entirely — this test is
    // about the route, not the generator.
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Kay']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_kv_kay']);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'google_business', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead-kv@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    $signup = EarlyAccessSignup::create([
        'email' => 'lead-kv@example.com', 'email_lc' => 'lead-kv@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    (new ApproveEarlyAccessBuildJob($signup->id, $build->source_type))->handle(
        app(SourceGeneratorRegistry::class), app(PreAccountBuildService::class),
    );

    expect($build->fresh()->expires_at)->not->toBeNull();
    Queue::assertPushed(
        SyncSubdomainToKvJob::class,
        fn (SyncSubdomainToKvJob $job) => $job->userId === (string) $build->user_id,
    );
});
