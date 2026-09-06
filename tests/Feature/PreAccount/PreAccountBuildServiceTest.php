<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Exceptions\Site\SubdomainUnavailableException;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\PreAccount\SourcePrefetch;
use App\Services\User\SiteProvisioningService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    // LIFE-2: requestBuild() now takes a pg_advisory_xact_lock inside the build
    // transaction for every signup-path build — without the shim this errors on
    // SQLite (no such function) and breaks every test in this file.
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

// Arrange a staff actor exactly as UnclaimedGatingTest's staff force-delete
// test does (admin role, no persistence needed — associate() only reads the key).
function makePartnaStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

it('creates a pending, identity-less build and dispatches the job; materialization earns the user', function () {
    $result = app(PreAccountBuildService::class)->requestBuild(
        accountType: 'partna', sourceType: 'instagram', rawSourceRef: '@JaneDoe',
        sourceName: null, ipHash: hash('sha256', '1.2.3.4'),
    );

    $build = $result['build'];
    expect($result['reused'])->toBeFalse()
        ->and($build->build_state)->toBe(PreAccountBuild::STATE_PENDING)
        ->and($build->source_ref)->toBe('janedoe')          // IG normalization strips @ + lowercases
        ->and($build->source_ref_lc)->toBe('janedoe')
        ->and($build->built_via)->toBe(PreAccountBuild::VIA_SIGNUP)
        // Item 1a: NO identity exists yet — that is the whole point. The
        // request-time facts identity creation needs ride the row instead.
        ->and($build->user_id)->toBeNull()
        ->and($build->account_type)->toBe('partna');

    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));
    $user = $build->refresh()->user;
    expect($user->status)->toBe('unclaimed')
        ->and($user->auth_user_id)->toBeNull()
        ->and($user->primary_email)->toBeNull()
        ->and($user->account_type->value)->toBe('partna')
        ->and($user->first_name)->not->toBeNull()            // NOT NULL on live Postgres
        ->and($user->site->is_published)->toBeFalse()
        ->and($user->site->subdomain)->toBe('janedoe');

    Queue::assertPushed(GeneratePreAccountSiteJob::class, fn ($job) => $job->buildId === $build->id);
});

it('rolls back the provisional user, not just the build, when site creation fails after the user already exists (C2, 2026-09-06)', function () {
    // Before this fix: createProvisionalUserWithRetry() committed the user row
    // in its own standalone transaction, so a createSiteForHandle() failure
    // (a genuine handle/subdomain race — HandleAllocator already required the
    // handle to be free as a site subdomain too, so this is anomalous, not
    // ordinary input) left the user permanently squatting its handle_lc while
    // the build sat failed with user_id still null — invisible to
    // PruneExpiredPreAccountBuilds, which assumes a null-user_id failed build
    // has no footprint to clean up.
    $result = app(PreAccountBuildService::class)->requestBuild(
        accountType: 'partna', sourceType: 'instagram', rawSourceRef: '@JaneDoe',
        sourceName: null, ipHash: hash('sha256', '1.2.3.6'),
    );
    $build = $result['build'];

    $this->mock(SiteProvisioningService::class, function ($mock) {
        $mock->shouldReceive('createSiteForHandle')->andThrow(new SubdomainUnavailableException('forced for test'));
    });

    expect(fn () => app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: [])))
        ->toThrow(SubdomainUnavailableException::class);

    $build->refresh();
    expect($build->user_id)->toBeNull()
        ->and(User::query()->where('handle_lc', 'janedoe')->exists())->toBeFalse();
});

// Sign-up preview (2026-09-02, A.5): the identity note now also carries
// displayName and sourcePlatform so the scene shows a name and mark before
// any media lands.
it('lands displayName and sourcePlatform alongside handle on the identity note', function () {
    setupPreAccountBuildEventsTable();
    $result = app(PreAccountBuildService::class)->requestBuild('partna', 'instagram', 'identitypreview', null, hash('sha256', 'idp'));
    $build = $result['build'];

    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));

    $event = PreAccountBuildEvent::query()->where('build_id', $build->id)->where('stage', PreAccountBuildEvent::STAGE_IDENTITY)->where('status', 'landed')->firstOrFail();
    expect($event->payload['sourcePlatform'])->toBe('instagram')
        ->and($event->payload['handle'])->toBe('identitypreview')
        ->and($event->payload)->toHaveKey('displayName');
});

it('a second sign-up for the same source creates an independent build', function () {
    // Owner (2026-09-03): the signup lane never dedupes — the same Instagram
    // or Google Business source may start any number of sign-ups, each with
    // its own build (and later its own user/site/token). SUPERSEDES the
    // signup half of spec §4.1's re-serve; outreach lanes keep dedupe below.
    $svc = app(PreAccountBuildService::class);
    $first = $svc->requestBuild('partna', 'instagram', 'janedoe', null, hash('sha256', 'a'));
    $second = $svc->requestBuild('partna', 'instagram', '@JANEDOE', null, hash('sha256', 'b'));

    expect($second['reused'])->toBeFalse()
        ->and($second['build']->id)->not->toBe($first['build']->id);
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
});

it('retries a failed live build on dedupe hit (F3, outreach lane)', function () {
    // The re-serve/retry machinery is outreach-only now — a failed SIGNUP
    // build is simply left behind and the next signup builds fresh.
    $staff = makePartnaStaff();
    $svc = app(PreAccountBuildService::class);
    $first = $svc->requestBuild('partna', 'instagram', 'janedoe', null, null, staff: $staff);
    $first['build']->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => 'scrape_failed'])->save(); // B11 SEC-4

    // GeneratePreAccountSiteJob is ShouldBeUnique (keyed on build id); the lock a
    // real worker releases on job completion never releases under Queue::fake()
    // (jobs are captured, not processed). Release it manually so this test's
    // hand-simulated "first job already failed" matches what a real worker would
    // have already done by the time a retry request lands.
    (new UniqueLock(app(Repository::class)))
        ->release(new GeneratePreAccountSiteJob($first['build']->id, $first['build']->source_type));

    $second = $svc->requestBuild('partna', 'instagram', 'janedoe', null, null, staff: $staff);
    expect($second['reused'])->toBeTrue()
        ->and($second['build']->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING)
        ->and($second['build']->fresh()->failure_code)->toBeNull();
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
});

// Task 4's metering concern survives the signup lane losing re-serve: a
// failed build stays LIVE (claimed_at is what drops it, not build_state),
// so a re-sign-up for the same source is a NEW paid scrape metered by the
// same per-IP cap.
it('meters a re-sign-up for a source whose failed build is still live', function () {
    config(['partna.pre_account.max_unclaimed_per_ip' => 1]);
    $svc = app(PreAccountBuildService::class);
    $ip = hash('sha256', 'reserve-cap-ip');

    $result = $svc->requestBuild('partna', 'instagram', 'reservecap', null, $ip);
    $result['build']->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => 'scrape_failed'])->save();

    $svc->requestBuild('partna', 'instagram', 'reservecap', null, $ip);
})->throws(PreAccountBuildException::class);

it('does not meter a staff re-serve of a failed build', function () {
    $staff = makePartnaStaff();
    $svc = app(PreAccountBuildService::class);
    $result = $svc->requestBuild('partna', 'instagram', 'staffreservecap', null, null, staff: $staff);
    $result['build']->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => 'scrape_failed'])->save();

    config(['partna.pre_account.max_unclaimed_per_ip' => 0]); // would block ANY public caller
    $second = $svc->requestBuild('partna', 'instagram', 'staffreservecap', null, null, staff: $staff);

    expect($second['reused'])->toBeTrue()
        ->and($second['build']->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING);
});

it('rejects a wrong account_type/source_type pairing from the config map', function () {
    app(PreAccountBuildService::class)->requestBuild('partna', 'google_business', 'x', 'Cafe', hash('sha256', 'a'));
})->throws(PreAccountBuildException::class);

it('caps outstanding unclaimed builds per IP', function () {
    config(['partna.pre_account.max_unclaimed_per_ip' => 1]);
    $svc = app(PreAccountBuildService::class);
    $svc->requestBuild('partna', 'instagram', 'first', null, hash('sha256', 'same-ip'));

    $svc->requestBuild('partna', 'instagram', 'second', null, hash('sha256', 'same-ip'));
})->throws(PreAccountBuildException::class);

it('staff builds record the staff id, skip the IP cap, and honour expires_days', function () {
    $staff = makePartnaStaff(); // copy the arrange helper used by existing staff feature tests
    $result = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'prospect', null, null,
        staff: $staff, publish: true, expiresDays: 60,
    );

    expect($result['build']->built_via)->toBe(PreAccountBuild::VIA_STAFF)
        ->and($result['build']->built_by_staff_id)->toBe($staff->id)
        ->and($result['build']->expires_at->isAfter(now()->addDays(59)))->toBeTrue();
});

// Finding 9: a self-serve build has contact_email NULL by construction — a
// person mid-signup on their own source. A staff CSV/import naming the same
// source (e.g. a coincidental duplicate) must not attach its address here:
// that would silently hand the build's invite-gate to staff's address and
// lock the actual signer-upper out.
it('a staff build beside a live self-serve build creates its own row — the address never lands on the self-serve one', function () {
    // Signup builds are invisible to the outreach dedupe (findLive excludes
    // built_via=signup, mirroring the unique index), so staff outreach for a
    // source someone self-signed-up with gets its OWN gated build instead of
    // silently handing the self-serve build's invite gate to staff's address.
    $svc = app(PreAccountBuildService::class);
    $selfServe = $svc->requestBuild('partna', 'instagram', 'selfserve', null, hash('sha256', 'x'));
    expect($selfServe['build']->built_via)->toBe(PreAccountBuild::VIA_SIGNUP);

    $staffBuild = $svc->requestBuild(
        'partna', 'instagram', 'selfserve', null, null,
        staff: makePartnaStaff(), contactEmail: 'staff@example.com',
    );

    expect($staffBuild['reused'])->toBeFalse()
        ->and($staffBuild['build']->id)->not->toBe($selfServe['build']->id)
        ->and($staffBuild['build']->fresh()->contact_email)->toBe('staff@example.com')
        ->and($selfServe['build']->fresh()->contact_email)->toBeNull();
});

it('attaches a staff-provided contact_email to an outreach build on dedupe', function () {
    $staff = makePartnaStaff();
    $svc = app(PreAccountBuildService::class);
    $outreach = $svc->requestBuild('partna', 'instagram', 'outreach1', null, null, staff: $staff);
    expect($outreach['build']->contact_email)->toBeNull();

    $reused = $svc->requestBuild(
        'partna', 'instagram', 'outreach1', null, null,
        staff: $staff, contactEmail: 'lead@example.com',
    );

    expect($reused['build']->fresh()->contact_email)->toBe('lead@example.com');
});

it('throws CONTACT_EMAIL_CONFLICT when a staff request disagrees with the address on file', function () {
    $staff = makePartnaStaff();
    $svc = app(PreAccountBuildService::class);
    $svc->requestBuild(
        'partna', 'instagram', 'outreach2', null, null,
        staff: $staff, contactEmail: 'first@example.com',
    );

    try {
        $svc->requestBuild(
            'partna', 'instagram', 'outreach2', null, null,
            staff: $staff, contactEmail: 'second@example.com',
        );
        expect(false)->toBeTrue('Expected a PreAccountBuildException.');
    } catch (PreAccountBuildException $e) {
        expect($e->errorCode)->toBe(PreAccountBuildException::CONTACT_EMAIL_CONFLICT);
    }
});

// LIFE-2: the IP abuse-cap check now runs INSIDE the build transaction, guarded by
// a pg_advisory_xact_lock keyed on the IP hash — mirrors
// tests/Unit/Services/Site/InsertWithSortOrderTest.php's SQL-emission assertion.
it('takes an IP advisory lock inside the build transaction for a signup-path build', function () {
    $lockQueries = [];
    DB::listen(function ($query) use (&$lockQueries) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $lockQueries[] = $query->sql;
        }
    });

    app(PreAccountBuildService::class)->requestBuild(
        accountType: 'partna', sourceType: 'instagram', rawSourceRef: 'locktest',
        sourceName: null, ipHash: hash('sha256', '9.9.9.9'),
    );

    expect($lockQueries)->toHaveCount(1);
});

it('does not take the IP advisory lock for a staff build (no IP to cap)', function () {
    $lockQueries = [];
    DB::listen(function ($query) use (&$lockQueries) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $lockQueries[] = $query->sql;
        }
    });

    app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'staffnolock', null, null,
        staff: makePartnaStaff(),
    );

    expect($lockQueries)->toHaveCount(0);
});

// LIFE-4: reserve() re-dispatches a build stuck in pending/building past the SLA,
// same as it already does for a STATE_FAILED build.
it('re-dispatches a re-served outreach build stuck in pending past the SLA', function () {
    config(['partna.pre_account.stuck_build_sla_minutes' => 30]);
    $svc = app(PreAccountBuildService::class);
    $staff = makePartnaStaff();
    $first = $svc->requestBuild('partna', 'instagram', 'stuckpending', null, null, staff: $staff);

    // Simulate a worker crash mid-scrape: still 'pending', last touched 31m ago.
    // Direct attribute assignment (not update()) — 'updated_at' isn't in
    // PreAccountBuild::$fillable, so update()'s mass-assignment would silently
    // drop it and Eloquent's own updateTimestamps() would re-stamp it to "now"
    // (mirrors PruneExpiredBuildsTest's precedent for the same model).
    $first['build']->updated_at = now()->subMinutes(31);
    $first['build']->save();

    // GeneratePreAccountSiteJob is ShouldBeUnique (keyed on build id) — the lock
    // from the first dispatch is still held under Queue::fake() (a real worker
    // never ran to release it), so the re-dispatch below would be silently
    // swallowed without this release (mirrors the F3 "retries a failed live
    // build" test above).
    (new UniqueLock(app(Repository::class)))
        ->release(new GeneratePreAccountSiteJob($first['build']->id, $first['build']->source_type));

    $second = $svc->requestBuild('partna', 'instagram', 'stuckpending', null, null, staff: $staff);

    expect($second['reused'])->toBeTrue()
        ->and($second['build']->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING);
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
});

it('re-dispatches a re-served outreach build stuck in building past the SLA', function () {
    config(['partna.pre_account.stuck_build_sla_minutes' => 30]);
    $svc = app(PreAccountBuildService::class);
    $staff = makePartnaStaff();
    $first = $svc->requestBuild('partna', 'instagram', 'stuckbuilding', null, null, staff: $staff);
    $first['build']->build_state = PreAccountBuild::STATE_BUILDING;
    $first['build']->updated_at = now()->subMinutes(45);
    $first['build']->save();

    (new UniqueLock(app(Repository::class)))
        ->release(new GeneratePreAccountSiteJob($first['build']->id, $first['build']->source_type));

    $second = $svc->requestBuild('partna', 'instagram', 'stuckbuilding', null, null, staff: $staff);

    expect($second['reused'])->toBeTrue()
        ->and($second['build']->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING);
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
});

it('does not re-dispatch a re-served outreach build still pending within the SLA', function () {
    config(['partna.pre_account.stuck_build_sla_minutes' => 30]);
    $svc = app(PreAccountBuildService::class);
    $staff = makePartnaStaff();
    $first = $svc->requestBuild('partna', 'instagram', 'freshpending', null, null, staff: $staff);
    $first['build']->updated_at = now()->subMinutes(5);
    $first['build']->save();

    $second = $svc->requestBuild('partna', 'instagram', 'freshpending', null, null, staff: $staff);

    expect($second['reused'])->toBeTrue()
        ->and($second['build']->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING);
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 1);
});

it('creates an early-access build with null expiry and built_via early_access', function () {
    $result = app(PreAccountBuildService::class)->requestBuild(
        accountType: 'partna', sourceType: 'instagram', rawSourceRef: 'ea_prospect',
        sourceName: null, ipHash: null, staff: null, publish: false,
        expiresDays: null, contactEmail: 'lead@example.com',
        builtVia: PreAccountBuild::VIA_EARLY_ACCESS,
    );

    $build = $result['build'];
    expect($build->built_via)->toBe('early_access')
        ->and($build->expires_at)->toBeNull()
        ->and($build->contact_email)->toBe('lead@example.com');
});

/*
|--------------------------------------------------------------------------
| #SEM-4 — the race-loser branch must reconcile contact_email too
|--------------------------------------------------------------------------
| requestBuild() re-serves an existing live build from TWO places: the dedupe
| early-return (covered above) and the catch (UniqueConstraintViolationException)
| arm that fires when a concurrent request's INSERT committed first. Only the
| first one reconciled the caller's contact_email; losing the insert race threw
| the address away with no exception, no log, and no field in
| PreAccountBuildStatusResource to notice it by — so staff believed they had
| invite-gated a scraped business's site that was in fact still first-come
| claimable.
|
| HOW THE RACE IS DRIVEN. The SQLite lane's core.pre_account_builds carries no
| indexes at all — pre_account_builds_live_source_unique is a PARTIAL unique
| index that only exists on Postgres (baseline 20260726000000, line 2923) — so a
| genuine concurrent INSERT cannot raise the violation here, and a single
| in-memory connection cannot hold a competing row that survives the outer
| transaction's rollback either. Both halves are therefore staged explicitly:
|
|   1. SiteProvisioningService is swapped for one that throws the real
|      UniqueConstraintViolationException from inside the build transaction —
|      the exception class the catch arm keys on, thrown from a call the arm
|      genuinely sits downstream of.
|   2. The competitor build is seeded up front with claimed_at set, so
|      findLive()'s live() scope cannot see it on the way in, and un-claimed on
|      the TransactionRolledBack event — i.e. it becomes visible exactly between
|      the rollback and the catch arm's findLive(), which is where the winning
|      request's row appears in production.
|
| That stages the TIMING, not the assertion: everything from the catch arm
| onwards is the real code path.
*/

/** Seed the build that "won" the race — invisible to findLive() until revealed. */
function seedRaceCompetitor(PartnaStaff $staff, string $refLc, ?string $contactEmail): PreAccountBuild
{
    $user = User::factory()->create([
        'status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null,
    ]);

    $build = new PreAccountBuild([
        'source_type' => 'instagram',
        'source_ref' => $refLc,
        'source_ref_lc' => $refLc,
        'built_via' => PreAccountBuild::VIA_STAFF, // outreach — the attach guard's precondition
        'contact_email' => $contactEmail,
        'expires_at' => now()->addDays(30),
    ]);
    $build->user()->associate($user);
    $build->builtByStaff()->associate($staff);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->save();

    // Hidden from findLive() on the way in (scopeLive is whereNull('claimed_at')).
    $build->forceFill(['claimed_at' => now()])->save();

    return $build;
}

/**
 * Make the build transaction lose the insert race, and let the winner become
 * visible the instant that transaction rolls back.
 */
function stageLostInsertRace(PreAccountBuild $competitor): void
{
    // Item 1a: requestBuild() no longer provisions a site in-request, so the
    // race is staged at the point that CAN still lose it — the build INSERT
    // itself (the test schema carries no partial unique index to hit for
    // real, so the violation is faked exactly as before, one row earlier).
    $armed = new stdClass;
    $armed->fired = false;
    PreAccountBuild::creating(function () use ($armed) {
        if ($armed->fired) {
            return;
        }
        $armed->fired = true;
        throw new UniqueConstraintViolationException(
            'pgsql',
            'insert into "core"."pre_account_builds" ...',
            [],
            new PDOException('SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "pre_account_builds_live_source_unique"'),
        );
    });

    Event::listen(
        TransactionRolledBack::class,
        function ($event) use ($competitor) {
            // Only the OUTER rollback — a savepoint rollback from the handle
            // retry loop fires this event too, at transactionLevel >= 1.
            if ($event->connection->transactionLevel() !== 0) {
                return;
            }
            $competitor->forceFill(['claimed_at' => null])->save();
        }
    );
}

it('attaches the caller contact_email when the build LOSES the insert race', function () {
    $staff = makePartnaStaff();
    $competitor = seedRaceCompetitor($staff, 'raceattach', null);
    stageLostInsertRace($competitor);

    $result = app(PreAccountBuildService::class)->requestBuild(
        'partna', 'instagram', 'raceattach', null, null,
        staff: $staff, contactEmail: 'Lead@Example.com',
    );

    expect($result['reused'])->toBeTrue()
        ->and($result['build']->id)->toBe($competitor->id);

    // The whole point: the address the caller supplied is ON the row, not dropped.
    expect($competitor->fresh()->contact_email)->toBe('lead@example.com');
});

it('raises CONTACT_EMAIL_CONFLICT when the build LOSES the insert race against a different address', function () {
    $staff = makePartnaStaff();
    $competitor = seedRaceCompetitor($staff, 'raceconflict', 'first@example.com');
    stageLostInsertRace($competitor);

    try {
        app(PreAccountBuildService::class)->requestBuild(
            'partna', 'instagram', 'raceconflict', null, null,
            staff: $staff, contactEmail: 'second@example.com',
        );
        expect(false)->toBeTrue('Expected a PreAccountBuildException.');
    } catch (PreAccountBuildException $e) {
        expect($e->errorCode)->toBe(PreAccountBuildException::CONTACT_EMAIL_CONFLICT);
    }

    // ...and the address on file is untouched — no silent overwrite either.
    expect($competitor->fresh()->contact_email)->toBe('first@example.com');
});

// The old "does not attach a staff address to a SELF-SERVE build that wins
// the race" test is gone with the scenario itself: signup rows left the
// unique index (2026-09-03), so a staff insert can no longer collide with a
// self-serve build at all. The guard it pinned is structural now — findLive
// excludes built_via=signup — and the coexistence test above asserts it.

// Handle seed (2026-09-02): a username that carries no part of the person's
// own name is a chosen brand and seeds the handle. Fixtures are real dev
// builds — see docs/plans/2026-09-02-handle-seed-prefers-brand-username.md.

it('seeds the handle from a brand username that carries no part of the name', function () {
    $svc = app(PreAccountBuildService::class);
    $build = $svc->requestBuild('partna', 'instagram', 'themetapunter', null, hash('sha256', 'tmp'))['build'];

    $svc->materializeIdentity($build, new SourcePrefetch(payload: [], displayName: 'Joe Osborne'));

    $user = $build->refresh()->user;
    expect($user->handle_lc)->toBe('themetapunter')
        // Handle and subdomain converge or nothing downstream resolves.
        ->and($user->site->subdomain)->toBe('themetapunter')
        // Only the address moved: the person is still called by their name.
        ->and($user->display_name)->toBe('Joe Osborne');
});

it('still trims a username that decorates the person own name', function () {
    $svc = app(PreAccountBuildService::class);
    $build = $svc->requestBuild('partna', 'instagram', 'ryanfitzsimonshair', null, hash('sha256', 'rfs'))['build'];

    $svc->materializeIdentity($build, new SourcePrefetch(payload: [], displayName: 'Ryan Fitzsimons'));

    expect($build->refresh()->user->handle_lc)->toBe('ryanfitzsimons');
});

it('falls back to the person name when the brand username is taken', function () {
    // The ladder HandleAllocator already has: untrimmedSeed before a digit.
    $other = User::factory()->create(['handle' => 'themetapunter', 'handle_lc' => 'themetapunter']);
    Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'themetapunter']);

    $svc = app(PreAccountBuildService::class);
    $build = $svc->requestBuild('partna', 'instagram', 'themetapunter', null, hash('sha256', 'tmp2'))['build'];

    $svc->materializeIdentity($build, new SourcePrefetch(payload: [], displayName: 'Joe Osborne'));

    // 'joeosborne', NOT 'themetapunter1'.
    expect($build->refresh()->user->handle_lc)->toBe('joeosborne');
});

it('leaves a google business build seeding from the listing name', function () {
    // A place_id is opaque — there is no username to prefer, and this branch
    // must never fire for GB.
    $svc = app(PreAccountBuildService::class);
    $build = $svc->requestBuild('business', 'google_business', 'ChIJfamishedwolf', 'The Famished Wolf', hash('sha256', 'fw'))['build'];

    $svc->materializeIdentity($build, new SourcePrefetch(payload: [], displayName: 'The Famished Wolf'));

    expect($build->refresh()->user->handle_lc)->toBe('thefamishedwolf');
});
