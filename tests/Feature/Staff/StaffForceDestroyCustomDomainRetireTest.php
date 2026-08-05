<?php

/**
 * EDGE-1 — staff hard-delete (via the /force endpoint's
 * AccountDeletionService::adminPurgeNow → purge() path) must retire the
 * custom-domain KV pointer. forceDelete() cascade-deletes the site row before
 * the queued KV sync runs, so retire() can no longer resolve $pro->site;
 * purge() captures the active custom domain up front and threads it through
 * the job's $retireCustomDomain param. See
 * app/Jobs/Cloudflare/SyncSubdomainToKvJob.php.
 */

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupPartnaStaffTable();
    setupUsersTable();
    setupSitesTable();
    setupHandleAliasesTable();
    attachTestSchemas();

    // Task 5: /force now runs the FULL immediate purge (AccountDeletionService::
    // adminPurgeNow → executeConfirmation + purge()), not a bare forceDelete().
    // executeConfirmation forceFills deletion_*/admin_notes columns that the
    // shared setupUsersTable() stub doesn't carry (it predates the full-purge
    // write path) — defensive ALTER, same pattern as the sector/sector_source
    // block in setupUsersTable() itself. purgeMediaArtifacts() also queries
    // site.site_media unconditionally once a site exists.
    foreach ([
        'deletion_token_hash TEXT NULL',
        'deletion_requested_at TEXT NULL',
        'deletion_confirmed_at TEXT NULL',
        'deletion_previous_status TEXT NULL',
        'deletion_mail_sent_at TEXT NULL',
        'admin_notes TEXT NULL',
    ] as $col) {
        try {
            DB::connection('pgsql')->statement('ALTER TABLE core.users ADD COLUMN '.$col);
        } catch (Throwable $e) {
            // already exists — ignore
        }
    }
    setupMediaTables();
    setupUserDeletionAuditTable();

    // staff.audit middleware (RecordStaffAuditEntry) writes to audit.staff_audit_log
    // after the response — set it up so terminate() doesn't throw on SQLite.
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

    config([
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-service-role-key',
    ]);
    Http::fake(['*/auth/v1/admin/users/*' => Http::response('', 200)]);
});

function makeForceDestroyAdmin(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

it('retires the custom-domain KV pointer when force-deleting a user with an active domain (EDGE-1)', function () {
    Bus::fake();

    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'nukedpro',
        'handle_lc' => 'nukedpro',
        'display_name' => 'Nukedpro',
        'first_name' => 'Nukedpro',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'n@example.test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'subdomain' => 'nukedpro',
        'is_published' => 1,
        'custom_domain' => 'nuked.example',
        'custom_domain_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    actingAsStaff(makeForceDestroyAdmin())
        ->deleteJson("/api/staff/professionals/{$proId}/force", ['reason' => 'Force delete — ticket #EDGE1'])
        ->assertStatus(200);

    // The orphan-able custom-domain pointer is retired via $retireCustomDomain.
    Bus::assertDispatched(SyncSubdomainToKvJob::class, function (SyncSubdomainToKvJob $job) use ($proId) {
        return $job->userId === $proId && $job->retireCustomDomain === 'nuked.example';
    });
});

it('does not thread a custom-domain retirement when force-deleting a user without an active domain (EDGE-1 guard)', function () {
    Bus::fake();

    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'plainpro',
        'handle_lc' => 'plainpro',
        'display_name' => 'Plainpro',
        'first_name' => 'Plainpro',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'p@example.test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'subdomain' => 'plainpro',
        'is_published' => 1,
        'custom_domain' => null,
        'custom_domain_status' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    actingAsStaff(makeForceDestroyAdmin())
        ->deleteJson("/api/staff/professionals/{$proId}/force", ['reason' => 'Force delete — ticket #EDGE1'])
        ->assertStatus(200);

    // No dispatch should carry a non-null retireCustomDomain.
    Bus::assertNotDispatched(SyncSubdomainToKvJob::class, function (SyncSubdomainToKvJob $job) {
        return $job->retireCustomDomain !== null;
    });
});
