<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPartnaStaffTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
});

function batchStaffActor(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

it('builds one row per CSV line and reports the summary', function () {
    actingAsStaff(batchStaffActor());
    Queue::fake();

    $csv = "account_type,source_type,source_ref,source_name,contact_email,auto_invite\n"
        ."partna,instagram,alice_ig,,alice@example.com,false\n"
        ."partna,instagram,bob_ig,,bob@example.com,true\n";
    $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

    $this->post('/api/staff/builds/batch', ['file' => $file])
        ->assertStatus(200)
        ->assertJsonPath('built', 2)
        ->assertJsonPath('failed', [])
        ->assertJsonPath('total', 2)
        ->assertJsonPath('processed', 2)
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('time_budget_exceeded', false);

    Queue::assertPushed(GeneratePreAccountSiteJob::class, 2);
    expect(PreAccountBuild::where('source_ref_lc', 'alice_ig')->firstOrFail()->auto_invite)->toBeFalse()
        ->and(PreAccountBuild::where('source_ref_lc', 'bob_ig')->firstOrFail()->contact_email)->toBe('bob@example.com');
});

it('collects a bad row without aborting the batch', function () {
    actingAsStaff(batchStaffActor());
    Queue::fake();

    $csv = "account_type,source_type,source_ref,source_name,contact_email,auto_invite\n"
        ."partna,tiktok,nope,,x@example.com,true\n"          // tiktok = invalid source
        ."partna,instagram,good_ig,,good@example.com,true\n";
    $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

    $this->post('/api/staff/builds/batch', ['file' => $file])
        ->assertStatus(200)
        ->assertJsonPath('built', 1)
        ->assertJsonPath('failed.0.row', 1);

    Queue::assertPushed(GeneratePreAccountSiteJob::class, 1);
});

it('stops on the time budget and reports where it got to', function () {
    actingAsStaff(batchStaffActor());
    Queue::fake();
    config(['partna.pre_account.batch_time_budget_seconds' => 0]);

    $csv = "account_type,source_type,source_ref,source_name,contact_email,auto_invite\n"
        ."partna,instagram,alice_ig,,alice@example.com,true\n"
        ."partna,instagram,bob_ig,,bob@example.com,true\n"
        ."partna,instagram,carol_ig,,carol@example.com,true\n";
    $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

    $this->post('/api/staff/builds/batch', ['file' => $file])
        ->assertStatus(200)
        ->assertJsonPath('total', 3)
        ->assertJsonPath('processed', 1)
        ->assertJsonPath('remaining', 2)
        ->assertJsonPath('time_budget_exceeded', true)
        ->assertJsonPath('truncated', false);

    // Proves rows 2-3 were genuinely never started, not merely uncounted.
    Queue::assertPushed(GeneratePreAccountSiteJob::class, 1);
});

it('records a non-PreAccountBuildException row as failed and continues', function () {
    actingAsStaff(batchStaffActor());
    Exceptions::fake();

    $fake = Mockery::mock(PreAccountBuildService::class);
    $fake->shouldReceive('requestBuild')->once()->andThrow(new RuntimeException('boom'));
    $fake->shouldReceive('requestBuild')->once()->andReturn(['build' => new PreAccountBuild, 'reused' => false]);
    $this->app->instance(PreAccountBuildService::class, $fake);

    $csv = "account_type,source_type,source_ref,source_name,contact_email,auto_invite\n"
        ."partna,instagram,alice_ig,,alice@example.com,true\n"
        ."partna,instagram,bob_ig,,bob@example.com,true\n";
    $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

    $this->post('/api/staff/builds/batch', ['file' => $file])
        ->assertStatus(200)
        ->assertJsonPath('failed.0.row', 1)
        ->assertJsonPath('failed.0.code', 'ROW_FAILED')
        ->assertJsonPath('built', 1)
        ->assertJsonPath('processed', 2)
        ->assertJsonPath('remaining', 0);

    Exceptions::assertReported(RuntimeException::class);
});
