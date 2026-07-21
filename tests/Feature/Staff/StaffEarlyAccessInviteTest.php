<?php

/**
 * OV-A — staff invite flow: inviting waitlist rows mints a token + queues the
 * invite mail; manual entries create rows with invite_meta prefills;
 * signed-up rows are skipped; markSignedUp burns the token.
 */

use App\Mail\EarlyAccess\EarlyAccessInviteMail;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\EarlyAccess\EarlyAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    setupEarlyAccessTable();
    setupPartnaStaffTable();

    // staff.audit middleware writes here after each staff response.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\',
        status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\',
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');

    DB::connection('pgsql')->statement('DELETE FROM core.early_access_signups');
    Mail::fake();
});

function ovaAdminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-ova@partna.au';

    return $staff;
}

function ovaWaitlistRow(string $email): EarlyAccessSignup
{
    // status removed from $fillable (S4 Tier 2b) — forceFill so the returned
    // in-memory instance is correct immediately (DB DEFAULT happens to match
    // 'waitlist' here, but the in-memory attribute would otherwise read null).
    $signup = new EarlyAccessSignup;
    $signup->forceFill([
        'email' => $email,
        'email_lc' => $email,
        'type' => 'partna',
        'status' => 'waitlist',
        'source' => 'marketing',
        'platforms' => ['instagram', 'fresha'],
    ]);
    $signup->save();

    return $signup;
}

it('invites existing waitlist rows: status flips, token hash stored, mail queued', function () {
    $row = ovaWaitlistRow('inv1@example.test');

    actingAsStaff(ovaAdminStaff())
        ->postJson('/api/staff/early-access/invite', ['ids' => [$row->id]])
        ->assertStatus(200)
        ->assertJson(['invited_count' => 1, 'invited_ids' => [$row->id], 'skipped_ids' => []]);

    $row->refresh();

    expect($row->status)->toBe('invited')
        ->and($row->invite_token_hash)->not->toBeNull()
        ->and($row->invited_at)->not->toBeNull();

    Mail::assertQueued(EarlyAccessInviteMail::class, function (EarlyAccessInviteMail $mail) {
        return $mail->recipientEmail === 'inv1@example.test'
            && str_contains($mail->signupUrl, '/signup?invite=');
    });
});

it('creates manual-entry rows with invite_meta prefills and invites them', function () {
    actingAsStaff(ovaAdminStaff())
        ->postJson('/api/staff/early-access/invite', [
            'entries' => [[
                'email' => 'Manual@Example.Test',
                'type' => 'business',
                'workplace_or_industry' => 'Cafe',
                'platforms' => ['google-business', 'instagram'],
                'integrations' => [
                    'instagram' => 'the_cafe',
                    'other' => [['platform' => 'fresha', 'url' => 'https://fresha.example/the-cafe']],
                ],
                'architecture' => ['theme_mode' => 'warm'],
            ]],
        ])
        ->assertStatus(200)
        ->assertJson(['invited_count' => 1]);

    $row = EarlyAccessSignup::query()->where('email_lc', 'manual@example.test')->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('invited')
        ->and($row->source)->toBe('manual')
        ->and($row->invite_meta['integrations']['instagram'])->toBe('the_cafe')
        ->and($row->invite_meta['architecture']['theme_mode'])->toBe('warm');

    Mail::assertQueued(EarlyAccessInviteMail::class);
});

it('skips already-signed-up rows and reports them', function () {
    $done = ovaWaitlistRow('done@example.test');
    // status removed from $fillable (S4 Tier 2b) — Model::update() calls
    // fill() internally, so a plain ->update() here would silently no-op and
    // leave the row 'waitlist', defeating the assertion this test exists for.
    $done->forceFill(['status' => 'signed_up'])->save();

    actingAsStaff(ovaAdminStaff())
        ->postJson('/api/staff/early-access/invite', ['ids' => [$done->id]])
        ->assertStatus(200)
        ->assertJson(['invited_count' => 0, 'skipped_ids' => [$done->id]]);

    Mail::assertNothingQueued();
});

it('marks a signup as signed_up and burns the token via the service', function () {
    $row = ovaWaitlistRow('finish@example.test');
    $service = app(EarlyAccessService::class);

    $token = $service->invite($row);
    expect($token)->not->toBeNull()
        ->and(EarlyAccessSignup::findByInviteToken($token)?->id)->toBe($row->id);

    $service->markSignedUp('Finish@Example.Test');

    $row->refresh();
    expect($row->status)->toBe('signed_up')
        ->and($row->invite_token_hash)->toBeNull()
        ->and($row->signed_up_at)->not->toBeNull()
        ->and(EarlyAccessSignup::findByInviteToken($token))->toBeNull();
});

it('rejects invite sends from support-role staff (admin-only power)', function () {
    $row = ovaWaitlistRow('support-cant@example.test');

    $support = new PartnaStaff;
    $support->id = (string) Str::uuid();
    $support->role = PartnaStaff::ROLE_SUPPORT;

    // The staff.admin middleware on the write group rejects support staff
    // before the policy is even consulted.
    actingAsStaff($support)
        ->postJson('/api/staff/early-access/invite', ['ids' => [$row->id]])
        ->assertStatus(403);

    expect($row->fresh()->status)->toBe('waitlist');
});
