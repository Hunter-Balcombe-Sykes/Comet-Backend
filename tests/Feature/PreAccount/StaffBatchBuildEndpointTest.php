<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        ->assertJsonPath('failed', []);

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
