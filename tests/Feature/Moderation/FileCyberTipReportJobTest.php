<?php

use App\Jobs\Moderation\FileCyberTipReportJob;
use App\Models\Moderation\NcmecSubmission;
use App\Services\Moderation\NcmecSubmissionService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    setupAllModerationTables();
    setupUsersTable();
    setupSitesTable();
    setupSiteMediaTable();

    config([
        'partna.moderation.csam.ncmec_endpoint'     => 'https://hashmatching.api.missingkids.org/cybertip',
        'partna.moderation.csam.ncmec_api_key'      => 'test',
        'partna.moderation.csam.ncmec_esp_id'       => 'ESP',
        'partna.moderation.csam.ncmec_max_attempts' => 5,
    ]);
});

it('submits a pending row and marks it submitted on 200', function () {
    Http::fake(['*' => Http::response(['tipId' => 'NCMEC-100'], 200)]);
    $sub = NcmecSubmission::factory()->create();

    (new FileCyberTipReportJob($sub->id))->handle(app(NcmecSubmissionService::class));

    $sub->refresh();
    expect($sub->status)->toBe('submitted');
    expect($sub->ncmec_tip_id)->toBe('NCMEC-100');
})->group('postgres');

it('is a no-op if submission is already submitted', function () {
    Http::fake(['*' => Http::response(['tipId' => 'should-not-call'], 200)]);
    $sub = NcmecSubmission::factory()->submitted('NCMEC-EXISTING')->create();

    (new FileCyberTipReportJob($sub->id))->handle(app(NcmecSubmissionService::class));

    // Confirm no HTTP call happened and tip ID unchanged.
    Http::assertNothingSent();
    expect($sub->fresh()->ncmec_tip_id)->toBe('NCMEC-EXISTING');
})->group('postgres');
