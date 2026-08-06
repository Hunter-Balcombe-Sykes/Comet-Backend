<?php

/**
 * Task 4 — Status-machine gating sweep (spec §2, §7).
 *
 * Widens PublicSiteResolver's public-read gate so a PUBLISHED unclaimed
 * (pre-account) site resolves the same as an active owner's — the publish
 * knob, not claim state, controls public visibility. Everything else on the
 * status machine stays fail-closed (suspended/disabled owners still refused;
 * the staff manual-expiry/force-delete path still works on an unclaimed row).
 *
 * Step 4 sweep — `git grep -n "'status'.*active" app/Services/PublicSite
 * app/Services/Cache app/Http/Controllers/Api/PublicSite`, plus a broader
 * manual `status` keyword sweep of the same three directories (the literal
 * grep only catches quoted `'status'` array-key usage, not `->status`
 * property access):
 *
 *   - app/Services/PublicSite/PublicSiteResolver.php:27
 *     (`$q->where('status', 'active')`) — WIDEN to
 *     `whereIn('status', ['active', 'unclaimed'])`. This is the actual public
 *     read-path gate deciding whether a published site resolves for an
 *     anonymous visitor — exactly what this task targets. Only hit from the
 *     literal grep.
 *
 *   - app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:21
 *     (`$professional->status !== 'active'`) — LEAVE. Found via the broader
 *     sweep (property access, not a quoted array key, so the literal grep
 *     misses it). This is an AUTHENTICATED MUTATION endpoint (an owner
 *     toggling their own site's `published` flag), not a public render gate
 *     — fail-closed is correct here. Unclaimed users have `auth_user_id =
 *     NULL` and can never hold a Supabase JWT, so they cannot reach this
 *     route regardless of the check; widening it would be a no-op for this
 *     feature while weakening a defensive check on a write path.
 *
 *   - app/Http/Controllers/Api/PublicSite/BootstrapController.php:62
 *     (`$invite->status !== EarlyAccessSignup::STATUS_INVITED`) — NOT
 *     APPLICABLE. False positive from the keyword sweep: this is
 *     `EarlyAccessSignup.status`, unrelated to `User.status` / claim state.
 *
 *   - app/Services/Cache/{SiteCacheService,SiteCacheInvalidator,UserCacheService}.php
 *     — no `User.status` filter anywhere. SiteCacheService reads from the
 *     `site.public_site_payload` DB view (already widened in Task 1) and has
 *     no PHP-side status gate of its own.
 *
 *   - IndividualProfileController / IndividualProfilePayloadBuilder /
 *     SitepageDataResolverService / QrCodeController / PublicMenuController
 *     — none gate on `User.status` at all (they resolve purely on handle/site
 *     existence + `is_published`), so there is nothing to widen or leave.
 *
 * Deviation from the brief: this task ALSO wires `tests/Feature/PreAccount`
 * into scripts/audit/audit.sh's codebase_chunks() (feature-misc-tail chunk),
 * mirroring commit 49583207's one-line shape exactly, so
 * AuditPipelineIntegrityTest's "every feature namespace is covered" check
 * stays green through this task instead of red until Task 19 (coordinator
 * decision — a red suite between tasks is unacceptable). Task 19 Step 1
 * becomes a verification that this wiring already exists; it still needs to
 * wire app/Services/PreAccount and app/Jobs/PreAccount once those
 * directories exist (they don't yet).
 */

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\PublicSite\PublicSiteResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // PublicSiteResolver always falls through to the alias table on a direct
    // miss (even when a direct hit is expected) — required for every test here.
    setupSubdomainAliasesTable();

    // Only the staff force-delete test needs these, but the schema helpers
    // are idempotent (CREATE TABLE IF NOT EXISTS / ATTACH wrapped in
    // try/catch) so it's safe — and simpler — to set them up for every test
    // in the file rather than split beforeEach per describe block.
    setupPartnaStaffTable();
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

    config([
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-service-role-key',
    ]);
    Http::fake(['*/auth/v1/admin/users/*' => Http::response('', 200)]);

    // staff.audit middleware (RecordStaffAuditEntry) writes to
    // audit.staff_audit_log after the response — set it up so terminate()
    // doesn't throw on SQLite. Mirrors StaffForceDestroyCustomDomainRetireTest.
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

function makeUnclaimedWithSite(array $siteAttrs = []): array
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    $site = Site::factory()->create(array_merge(['user_id' => $user->id, 'is_published' => true], $siteAttrs));

    return [$user, $site];
}

it('resolves a PUBLISHED unclaimed site publicly (the marketing pitch)', function () {
    [, $site] = makeUnclaimedWithSite(['subdomain' => 'janedoe']);

    $result = app(PublicSiteResolver::class)->resolvePublishedSite('janedoe');
    expect($result['site']?->id)->toBe($site->id);
});

it('does NOT resolve an UNPUBLISHED unclaimed site (signup-path default)', function () {
    makeUnclaimedWithSite(['subdomain' => 'janedoe', 'is_published' => false]);

    expect(app(PublicSiteResolver::class)->resolvePublishedSite('janedoe')['site'])->toBeNull();
});

it('still refuses suspended/disabled owners', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => true, 'subdomain' => 'suss']);

    expect(app(PublicSiteResolver::class)->resolvePublishedSite('suss')['site'])->toBeNull();
});

it('staff force-delete works on an unclaimed user (the manual expiry — spec §7)', function () {
    Bus::fake();

    // Arrange a staff actor with fresh AAL2 exactly as StaffUserController
    // forceDestroy tests do (StaffForceDestroyCustomDomainRetireTest /
    // StaffUserControllerFreshAal2Test) — admin role, default actingAsStaff
    // claims carry a fresh totp amr entry (timestamped at time()).
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    [$user] = makeUnclaimedWithSite(['subdomain' => 'takedown']);

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$user->id}/force", ['reason' => 'Manual expiry — unclaimed takedown'])
        ->assertStatus(200);

    // Hard delete — PERMANENT. withTrashed() confirms the row is gone
    // outright, not merely soft-deleted.
    expect(User::withTrashed()->find($user->id))->toBeNull();

    // UserObserver::deleted always dispatches SyncSubdomainToKvJob to retire
    // the handle's KV routing entry, regardless of user status.
    Bus::assertDispatched(SyncSubdomainToKvJob::class);
});
