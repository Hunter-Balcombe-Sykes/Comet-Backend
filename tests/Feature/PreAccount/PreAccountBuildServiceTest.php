<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
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

it('creates provisional user + unpublished site + pending build and dispatches the job', function () {
    $result = app(PreAccountBuildService::class)->requestBuild(
        accountType: 'partna', sourceType: 'instagram', rawSourceRef: '@JaneDoe',
        sourceName: null, ipHash: hash('sha256', '1.2.3.4'),
    );

    $build = $result['build'];
    expect($result['reused'])->toBeFalse()
        ->and($build->build_state)->toBe(PreAccountBuild::STATE_PENDING)
        ->and($build->source_ref)->toBe('janedoe')          // IG normalization strips @ + lowercases
        ->and($build->source_ref_lc)->toBe('janedoe')
        ->and($build->built_via)->toBe(PreAccountBuild::VIA_SIGNUP);

    $user = $build->user;
    expect($user->status)->toBe('unclaimed')
        ->and($user->auth_user_id)->toBeNull()
        ->and($user->primary_email)->toBeNull()
        ->and($user->account_type->value)->toBe('partna')
        ->and($user->first_name)->not->toBeNull()            // NOT NULL on live Postgres
        ->and($user->site->is_published)->toBeFalse()
        ->and($user->site->subdomain)->toBe('janedoe');

    Queue::assertPushed(GeneratePreAccountSiteJob::class, fn ($job) => $job->buildId === $build->id);
});

it('re-serves an existing LIVE build for the same source without re-scraping', function () {
    $svc = app(PreAccountBuildService::class);
    $first = $svc->requestBuild('partna', 'instagram', 'janedoe', null, hash('sha256', 'a'));
    $second = $svc->requestBuild('business', 'instagram', '@JANEDOE', null, hash('sha256', 'b'));

    expect($second['reused'])->toBeTrue()
        ->and($second['build']->id)->toBe($first['build']->id)
        // re-served build keeps its ORIGINAL account_type (spec §4.1)
        ->and($second['build']->user->account_type->value)->toBe('partna');
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 1);
});

it('retries a failed live build on dedupe hit (F3)', function () {
    $svc = app(PreAccountBuildService::class);
    $first = $svc->requestBuild('partna', 'instagram', 'janedoe', null, hash('sha256', 'a'));
    $first['build']->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => 'scrape_failed'])->save(); // B11 SEC-4

    // GeneratePreAccountSiteJob is ShouldBeUnique (keyed on build id); the lock a
    // real worker releases on job completion never releases under Queue::fake()
    // (jobs are captured, not processed). Release it manually so this test's
    // hand-simulated "first job already failed" matches what a real worker would
    // have already done by the time a retry request lands.
    (new UniqueLock(app(Repository::class)))
        ->release(new GeneratePreAccountSiteJob($first['build']->id, $first['build']->source_type));

    $second = $svc->requestBuild('partna', 'instagram', 'janedoe', null, hash('sha256', 'a'));
    expect($second['build']->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING)
        ->and($second['build']->fresh()->failure_code)->toBeNull();
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
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
it('re-dispatches a re-served build stuck in pending past the SLA', function () {
    config(['partna.pre_account.stuck_build_sla_minutes' => 30]);
    $svc = app(PreAccountBuildService::class);
    $first = $svc->requestBuild('partna', 'instagram', 'stuckpending', null, hash('sha256', 'stuck-a'));

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

    $second = $svc->requestBuild('partna', 'instagram', 'stuckpending', null, hash('sha256', 'stuck-b'));

    expect($second['reused'])->toBeTrue()
        ->and($second['build']->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING);
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
});

it('re-dispatches a re-served build stuck in building past the SLA', function () {
    config(['partna.pre_account.stuck_build_sla_minutes' => 30]);
    $svc = app(PreAccountBuildService::class);
    $first = $svc->requestBuild('partna', 'instagram', 'stuckbuilding', null, hash('sha256', 'stuck-c'));
    $first['build']->build_state = PreAccountBuild::STATE_BUILDING;
    $first['build']->updated_at = now()->subMinutes(45);
    $first['build']->save();

    (new UniqueLock(app(Repository::class)))
        ->release(new GeneratePreAccountSiteJob($first['build']->id, $first['build']->source_type));

    $second = $svc->requestBuild('partna', 'instagram', 'stuckbuilding', null, hash('sha256', 'stuck-d'));

    expect($second['reused'])->toBeTrue()
        ->and($second['build']->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING);
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
});

it('does not re-dispatch a re-served build still pending within the SLA', function () {
    config(['partna.pre_account.stuck_build_sla_minutes' => 30]);
    $svc = app(PreAccountBuildService::class);
    $first = $svc->requestBuild('partna', 'instagram', 'freshpending', null, hash('sha256', 'stuck-e'));
    $first['build']->updated_at = now()->subMinutes(5);
    $first['build']->save();

    $second = $svc->requestBuild('partna', 'instagram', 'freshpending', null, hash('sha256', 'stuck-f'));

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
