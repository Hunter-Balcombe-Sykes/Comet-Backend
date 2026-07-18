<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
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
    $first['build']->update(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => 'scrape_failed']);

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
