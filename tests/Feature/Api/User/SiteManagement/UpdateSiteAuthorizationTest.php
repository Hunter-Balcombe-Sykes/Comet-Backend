<?php

// SEC-1: UserSiteController::update() must enforce SitePolicy, not just rely on
// the EnforcePendingDeletionReadOnly HTTP middleware.
//
// Ownership is already structurally guaranteed (the action resolves the site
// from the authenticated professional), so the policy's job here is the
// pending-deletion 423 gate — defense-in-depth that fires even if the
// middleware is ever removed or bypassed on the route. We prove that by
// disabling EnforcePendingDeletionReadOnly and asserting the controller's own
// policy call still blocks the write. Before the fix this returns 200.

use App\Http\Middleware\Context\EnforcePendingDeletionReadOnly;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Route carries throttle:authenticated — disable so repeated test runs in a
    // single process don't trip the limiter.
    config(['partna.throttle.enabled' => false]);
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    // A successful publish fires the async cache-warm path, which reads the
    // public_site_payload view. Seed the (empty) table so that query resolves
    // cleanly and the test isolates the policy gate, not the cache machinery.
    setupPublicSitePayloadTable();
});

it('lets an active owner update their site through the policy gate', function () {
    $pro = createTenant('active-owner');

    // Must patch an OBSERVABLE column. This used to send architecture_id, which
    // stopped being a valid probe twice over: it is CHECK-pinned to 'staple'
    // (so the assertion held either way) and since 2026-08-20 it is not on the
    // wire at all (so the PATCH would carry no writable field). is_published is
    // seeded true by createTenant and is not pinned, so flipping it is what
    // actually proves the gate let the write through.
    actingAsUser($pro)
        ->patchJson('/api/site', ['is_published' => false])
        ->assertOk();

    expect((int) DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('is_published'))
        ->toBe(0);
});

it('blocks a pending-deletion professional at the controller policy even when the read-only middleware is bypassed', function () {
    $pro = createTenant('pending-owner');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update(['status' => 'pending_deletion']);
    $pro = $pro->fresh()->load('site');

    // Bypass the HTTP-layer read-only gate so the only thing that can stop the
    // write is the controller's own authorizeForUser() → SitePolicy::update.
    $this->withoutMiddleware(EnforcePendingDeletionReadOnly::class);

    // Send an UNPINNED column (is_published, seeded true by createTenant) alongside
    // architecture_id so a landed write would leave an observable trace.
    actingAsUser($pro)
        ->patchJson('/api/site', ['architecture_id' => 'staple', 'is_published' => false])
        ->assertStatus(423);

    // The write must not have landed. Since scroll became the platform default
    // (0c4eb24b9, owner 2026-08-27) the architecture column is a REAL probe
    // again: the blocked PATCH tried to write 'staple', so the row still
    // reading the seeded default 'scroll' proves the write never landed —
    // alongside is_published staying at its seeded value.
    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();
    expect((int) $row->is_published)->toBe(1);
    expect($row->architecture_id)->toBe('scroll');
});
