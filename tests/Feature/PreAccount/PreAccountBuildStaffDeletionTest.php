<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

// #SEM-2 regression pin. isOutreach() decides whether an unclaimed site is
// invite-gated or first-come claimable, and it used to key ONLY on
// built_by_staff_id — which is ON DELETE SET NULL. Deleting the staff row that
// created an outreach build therefore un-gated a real business's scraped site,
// silently, with nothing in the build row changing except one FK going null.
//
// tests/Unit/Models/PreAccountBuildTest.php already asserts the built_via arm,
// but it does so on an in-memory `new PreAccountBuild([...])` — it never touches
// a database, so it proves the boolean expression and nothing about the FK whose
// behaviour is the entire premise. This test does the real round trip.

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();

    // The shared fixture's core.pre_account_builds has NO foreign keys at all
    // (tests/Pest.php setupPreAccountBuildsTable), so on the SQLite lane
    // built_by_staff_id is an inert text column and deleting a staff row does
    // nothing to it — the exact thing this test needs to observe. Create the
    // table here with the one constraint that matters, transcribed from
    // pre_account_builds_built_by_staff_id_fkey in the baseline migration; the
    // shared setup below then no-ops on CREATE TABLE IF NOT EXISTS and only
    // runs its defensive column ALTERs.
    //
    // SQLite resolves a REFERENCES target within the same (attached) database,
    // so partna_staff is unqualified here — `core.partna_staff` would fail.
    //
    // This is why the file is listed in
    // scripts/launch-check/no-local-canonical-ddl-baseline.json. The shared
    // helper is NOT the fix here: adding the FK to it would 23503 every suite
    // that attributes a build to an UNSAVED PartnaStaff (makePartnaStaff() in
    // PreAccountBuildServiceTest, and the raw-uuid inserts in
    // PublicIntegrationControllerDarkUntilClaimedTest /
    // IndividualProfileControllerTest) — the same breakage
    // PreAccountBuildHandleRaceTest hit when it moved to real Postgres. The
    // bespoke table is scoped to this one file precisely so that blast radius
    // stays out of the shared fixture.
    DB::connection('pgsql')->statement('CREATE TABLE core.pre_account_builds (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        source_type TEXT NULL,
        source_ref TEXT NULL,
        source_ref_lc TEXT NULL,
        built_via TEXT NULL,
        built_by_staff_id TEXT NULL REFERENCES partna_staff(id) ON DELETE SET NULL,
        build_state TEXT NULL DEFAULT \'pending\',
        failure_code TEXT NULL,
        created_ip_hash TEXT NULL,
        expires_at TEXT NULL,
        claimed_at TEXT NULL,
        contact_email TEXT NULL,
        invited_at TEXT NULL,
        auto_invite INTEGER NOT NULL DEFAULT 1,
        thin_scrape_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    setupPreAccountBuildsTable();
});

it('keeps a staff-built build outreach-gated after its staff row is hard-deleted', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);

    $build = new PreAccountBuild([
        'source_type' => 'instagram',
        'source_ref' => 'RealBusiness',
        'source_ref_lc' => 'realbusiness',
        // Exactly what PreAccountBuildService writes for a staff request:
        // `$builtVia ?? ($staff ? VIA_STAFF : VIA_SIGNUP)`.
        'built_via' => PreAccountBuild::VIA_STAFF,
        'expires_at' => now()->addDays(30),
    ]);
    $build->user()->associate($user);
    $build->builtByStaff()->associate($staff); // never fillable
    $build->save();

    expect($build->fresh()->built_by_staff_id)->toBe($staff->id)
        ->and($build->fresh()->isOutreach())->toBeTrue();

    // The event that used to un-gate the site: staff offboarding.
    $staff->delete();
    expect(PartnaStaff::query()->whereKey($staff->id)->exists())->toBeFalse();

    $reloaded = PreAccountBuild::query()->findOrFail($build->id);

    // (a) the FK really is ON DELETE SET NULL — the premise of the finding
    expect($reloaded->built_by_staff_id)->toBeNull()
        // (b) ...and the claim gate survives it anyway
        ->and($reloaded->isOutreach())->toBeTrue();
});

// The assertion above is only as honest as the FK transcribed into the fixture.
// Pin the source of truth so the two cannot drift apart in silence: if the
// production constraint ever became ON DELETE CASCADE / NO ACTION, the test
// above would keep passing against a stale hand-written mirror.
it('pins the production FK that the fixture above mirrors', function () {
    $baseline = file_get_contents(base_path('supabase/migrations/20260726000000_baseline_pilot.sql'));

    expect($baseline)->toContain(
        'ADD CONSTRAINT "pre_account_builds_built_by_staff_id_fkey" FOREIGN KEY ("built_by_staff_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;'
    );
});
