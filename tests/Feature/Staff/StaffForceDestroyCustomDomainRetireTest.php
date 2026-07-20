<?php

/**
 * EDGE-1 — staff hard-delete (forceDestroy) must retire the custom-domain KV
 * pointer. forceDelete() cascade-deletes the site row before the queued KV sync
 * runs, so retire() can no longer resolve $pro->site; forceDestroy captures the
 * active custom domain up front and threads it through the job's
 * $retireCustomDomain param. See app/Jobs/Cloudflare/SyncSubdomainToKvJob.php.
 */

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupPartnaStaffTable();
    setupUsersTable();
    setupSitesTable();
    setupHandleAliasesTable();
    attachTestSchemas();
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
        ->deleteJson("/api/staff/professionals/{$proId}/force")
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
        ->deleteJson("/api/staff/professionals/{$proId}/force")
        ->assertStatus(200);

    // No dispatch should carry a non-null retireCustomDomain.
    Bus::assertNotDispatched(SyncSubdomainToKvJob::class, function (SyncSubdomainToKvJob $job) {
        return $job->retireCustomDomain !== null;
    });
});
