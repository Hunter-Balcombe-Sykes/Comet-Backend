<?php

use App\Jobs\Moderation\FileCyberTipReportJob;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupAllModerationTables();
    setupUsersTable();
    config(['partna.moderation.csam.ncmec_max_attempts' => 5]);
});

it('dispatches FileCyberTipReportJob for pending and failed-under-max submissions', function () {
    Queue::fake();
    NcmecSubmission::factory()->create(['status' => 'pending', 'attempts' => 0]);
    NcmecSubmission::factory()->create(['status' => 'failed', 'attempts' => 2]);

    $this->artisan('moderation:retry-ncmec-submissions')->assertSuccessful();

    Queue::assertPushed(FileCyberTipReportJob::class, 2);
});

it('skips submissions that already succeeded', function () {
    Queue::fake();
    NcmecSubmission::factory()->submitted('NCMEC-001')->create();

    $this->artisan('moderation:retry-ncmec-submissions')->assertSuccessful();
    Queue::assertNotPushed(FileCyberTipReportJob::class);
});

it('skips submissions at manual_fallback_required', function () {
    Queue::fake();
    NcmecSubmission::factory()->create(['status' => 'manual_fallback_required', 'attempts' => 5]);

    $this->artisan('moderation:retry-ncmec-submissions')->assertSuccessful();
    Queue::assertNotPushed(FileCyberTipReportJob::class);
});

it('skips failed submissions that have hit max attempts', function () {
    Queue::fake();
    NcmecSubmission::factory()->create(['status' => 'failed', 'attempts' => 5]);

    $this->artisan('moderation:retry-ncmec-submissions')->assertSuccessful();
    Queue::assertNotPushed(FileCyberTipReportJob::class);
});
