<?php

/**
 * OV-A — StaffUserController::index gains sector + account_type filters and
 * q matches sector text.
 *
 * TEST-105: converted from an in-process `app(StaffUserController::class)->index($request)`
 * call to real HTTP requests. index() has NO authorizeForUser call (see its
 * docblock — the PII gate is the enforcement point, not a per-row Gate check),
 * so the route middleware stack (routes/api/staff.php:35-40 — supabase.jwt,
 * require.email_verified, staff, require.aal2, throttle:staff, staff.audit)
 * is the ONLY protection on this endpoint. An in-process call skips that
 * entire stack, so it could never notice if the stack were removed.
 *
 * TEST-104: adds coverage for the `q` ILIKE path (handle/display_name/
 * sector/etc, plus email/phone now gated to admin — #W1-SEC-3). ILIKE is
 * Postgres-only syntax with no SQLite equivalent, so this file now lives in
 * the applied-schema lane (tests/Schema/) rather than behind a driver-gated
 * skip in tests/Feature/ — see the #COV-LANE-4 note below for why the move
 * needed real fixture fixes, not just a relocation.
 *
 * #W1-SEC-3 (2026-08-29): primary_email/phone were ILIKE search predicates
 * for every staff tier even though StaffUserListResource nulls both fields
 * unless $showPii — a support actor could confirm the exact hidden value
 * existed by searching for it. Fixed by gating those two legs behind
 * $showPii in StaffUserController::index(); the support-actor email test
 * below was INVERTED (was a positive match, now asserts no narrowing) and a
 * phone case plus an admin positive control were added alongside it.
 *
 * #COV-LANE-4 drift bug (FIXTURE fault, not production): core.users.sector
 * has a real CHECK constraint (users_sector_check, baseline_pilot.sql) fixed
 * to an enum of real sector slugs. The old SQLite fixture used fabricated
 * values ('dj', 'hairdresser', 'sector-noise-a'/'b'/'c', 'zqneedle-sector',
 * 'totally-unrelated') that PASS under SQLite (no CHECK enforcement in the
 * test-schema stand-in) and are REJECTED outright by real Postgres. Nothing
 * in app/ ever writes a sector value outside that enum — StaffUpdateSite /
 * ProfessionalSite request validation is the enforcement point on the write
 * side — so this is the fixture inventing data prod would never hold, not a
 * production defect. Fixed by swapping in real CHECK-list slugs.
 *
 * The FK failure on the 403 test (test 3 below) was a second, independent
 * fixture bug: it explicitly overrode auth_user_id with a fresh random UUID
 * that doesn't exist in auth.users (users_auth_user_id_fkey). That override
 * served no purpose — actingAsUser() stubs the JWT verification middleware
 * entirely and falls back to its own random UUID when auth_user_id is null,
 * so the intruder's identity for the assertion (a non-staff actor is
 * rejected) never depended on the DB row carrying a real one. Removed rather
 * than routed through the auth.users shim, since seeding a real auth user for
 * a value nothing reads would just be ceremony.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

function ovaSearchUser(array $overrides = []): string
{
    $id = (string) Str::uuid();
    $attrs = array_merge([
        'id' => $id,
        'handle' => 'search-'.Str::random(8),
        'display_name' => 'Search Target',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides);

    // Derived AFTER the merge so an override that changes 'handle' (several
    // call sites below do) still yields a consistent handle_lc/first_name,
    // rather than baking a mismatch from the base handle.
    $attrs['handle_lc'] ??= strtolower($attrs['handle']);
    $attrs['first_name'] ??= ucfirst($attrs['handle']);

    DB::connection('pgsql')->table('core.users')->insert($attrs);

    return $id;
}

/**
 * No RefreshDatabase in this lane — the DB is persistent and shared across
 * the whole run. Every ovaSearchUser() call site is responsible for tearing
 * down the ids it created via this, typically in a try/finally.
 */
function ovaSearchCleanup(array $ids): void
{
    $ids = array_filter($ids);

    if ($ids === []) {
        return;
    }

    DB::connection('pgsql')->table('core.users')->whereIn('id', $ids)->delete();
}

/** Unsaved support-role staff — index() has no per-row Gate check, so any staff role reaches it. */
function ovaSearchStaffActor(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_SUPPORT;
    $staff->primary_email = 'ova-search-staff@partna.au';

    return $staff;
}

/**
 * Admin-role sibling of ovaSearchStaffActor() — #W1-SEC-3 positive control:
 * $showPii gates the `q` search predicate on primary_email/phone, and admin
 * is the only tier that should still be able to search (and see) them.
 */
function ovaSearchAdminActor(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'ova-search-admin@partna.au';

    return $staff;
}

/**
 * Real HTTP request against GET /api/staff/professionals through the full
 * middleware stack (TEST-105). Returns the ids of matching professionals.
 * $actor defaults to the support-role helper; pass ovaSearchAdminActor() to
 * exercise the admin tier (#W1-SEC-3).
 */
function ovaSearchIds(array $query = [], ?PartnaStaff $actor = null): array
{
    $response = actingAsStaff($actor ?? ovaSearchStaffActor())
        ->getJson('/api/staff/professionals?'.http_build_query($query));

    $response->assertOk();

    return array_column($response->json('professionals'), 'id');
}

it('filters by sector exactly', function () {
    // Real users_sector_check slugs (baseline_pilot.sql) — 'dj'/'hairdresser'
    // were never valid values, just never checked under SQLite.
    $musician = ovaSearchUser(['sector' => 'musician']);
    $hairSalon = ovaSearchUser(['sector' => 'hair-salon']);

    try {
        $ids = ovaSearchIds(['sector' => 'musician']);

        expect($ids)->toBe([$musician]);
    } finally {
        ovaSearchCleanup([$musician, $hairSalon]);
    }
});

it('filters by account_type; staff is no longer an accepted value', function () {
    $partnaA = ovaSearchUser(['account_type' => 'partna']);
    $biz = ovaSearchUser(['account_type' => 'business']);
    $partnaB = ovaSearchUser(['account_type' => 'partna']);

    try {
        expect(ovaSearchIds(['account_type' => 'business']))->toBe([$biz]);

        // 'staff' is no longer an accepted filter → treated like any unknown
        // value → ignored, i.e. all three of this test's users still come
        // back. Asserted as a subset (not an exact count/array) because this
        // lane is a persistent, shared database — an unfiltered query can
        // also return rows other tests or the seed fixture left behind, so a
        // bare count would only ever hold on a fresh empty table.
        $unfilteredByStaff = ovaSearchIds(['account_type' => 'staff']);
        expect($unfilteredByStaff)->toContain($partnaA)
            ->toContain($biz)
            ->toContain($partnaB);

        $unfilteredByBogus = ovaSearchIds(['account_type' => 'bogus']);
        expect($unfilteredByBogus)->toContain($partnaA)
            ->toContain($biz)
            ->toContain($partnaB);
    } finally {
        ovaSearchCleanup([$partnaA, $biz, $partnaB]);
    }
});

// ---------------------------------------------------------------------------
// TEST-105 — proves the route middleware stack (not a per-row Gate check) is
// what protects this endpoint. index() itself has no authorizeForUser call —
// if 'staff' were dropped from the route's middleware list in
// routes/api/staff.php, this request would sail through to the controller
// and return 200, which would fail the assertStatus(403) below. Passing aal2
// claims explicitly isolates the assertion to the staff check: require.aal2
// runs AFTER staff in the middleware list, so it can't be what's rejecting
// this request.
// ---------------------------------------------------------------------------

it('rejects a non-staff authenticated actor at the real staff middleware (not the actingAsStaff stub)', function () {
    // No auth_user_id override here — actingAsUser() falls back to its own
    // random UUID when the model's auth_user_id is null, so the intruder's
    // JWT identity for this assertion never needed a row in auth.users.
    $intruderId = ovaSearchUser([
        'handle' => 'intruder-'.Str::random(6),
    ]);

    try {
        $intruder = User::query()->findOrFail($intruderId);

        $response = actingAsUser($intruder, [
            'aal' => 'aal2',
            'amr' => [['method' => 'totp', 'timestamp' => time()]],
        ])->getJson('/api/staff/professionals');

        $response->assertStatus(403);
        expect($response->json('error'))->toBe('staff_required');
    } finally {
        ovaSearchCleanup([$intruderId]);
    }
});

it('rejects an unauthenticated request with 401', function () {
    $response = $this->getJson('/api/staff/professionals');

    $response->assertStatus(401);
});

// ---------------------------------------------------------------------------
// TEST-104 — q ILIKE path (handle/display_name/first_name/last_name/sector/
// subdomain, plus email/phone gated to admin). Runs for real now that this
// file lives in the applied-schema lane; sector fixtures use real
// users_sector_check slugs (see file-level docblock) rather than the
// fabricated strings SQLite never validated.
// ---------------------------------------------------------------------------

it('q matches handle, display_name, and sector case-insensitively for a support actor', function () {
    $byHandle = ovaSearchUser(['handle' => 'zqneedle-handle', 'sector' => 'photographer']);
    $byEmail = ovaSearchUser(['primary_email' => 'zqneedle-email@example.test', 'sector' => 'videographer']);
    $byDisplayName = ovaSearchUser(['display_name' => 'Zqneedle Display Name', 'sector' => 'graphic-designer']);
    $bySector = ovaSearchUser(['sector' => 'course-creator']);
    $bystander = ovaSearchUser(['sector' => 'other', 'handle' => 'bystander-'.Str::random(6)]);

    try {
        // Case-insensitive substring match against handle.
        expect(ovaSearchIds(['q' => 'ZQNEEDLE-HANDLE']))->toBe([$byHandle]);

        // #W1-SEC-3: primary_email is hidden from a support actor's response
        // (StaffUserListResource nulls it), so it must not be usable as a
        // search predicate either — matching would be an existence oracle
        // over the exact value being withheld. A support actor's search must
        // NOT narrow at all: the result is identical to an unrelated string
        // that matches nothing, not merely "empty" by coincidence.
        $supportEmailHit = ovaSearchIds(['q' => 'zqneedle-email']);
        $supportUnrelated = ovaSearchIds(['q' => 'zqneedle-unrelated-'.Str::random(8)]);
        expect($supportEmailHit)->toBe([])
            ->and($supportEmailHit)->toBe($supportUnrelated);

        // Positive control: admin staff (who DO see primary_email in the
        // response) retain the ability to search it.
        expect(ovaSearchIds(['q' => 'zqneedle-email'], ovaSearchAdminActor()))->toBe([$byEmail]);

        // Matches display_name, including the embedded space.
        expect(ovaSearchIds(['q' => 'Zqneedle Display']))->toBe([$byDisplayName]);

        // Matches sector — the OV-A addition to the q path. 'course-creator' is
        // a real users_sector_check slug, distinct from every other sector/
        // handle/email/display_name value seeded in this test.
        expect(ovaSearchIds(['q' => 'course-creator']))->toBe([$bySector]);

        // A query that matches nothing returns an empty result, not every row.
        expect(ovaSearchIds(['q' => 'zqneedle-does-not-exist-anywhere']))->toBe([]);
    } finally {
        ovaSearchCleanup([$byHandle, $byEmail, $byDisplayName, $bySector, $bystander]);
    }
});

it('q still matches subdomain for a support actor — not gated by #W1-SEC-3 (rendered to every staff tier)', function () {
    $ownerId = ovaSearchUser(['handle' => 'zqneedle-subdomain-owner']);

    try {
        $siteId = Str::uuid()->toString();
        DB::connection('pgsql')->table('site.sites')->insert([
            'id' => $siteId,
            'user_id' => $ownerId,
            'subdomain' => 'zqneedle-subdomain-'.Str::random(6),
        ]);
        $subdomain = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('subdomain');

        expect(ovaSearchIds(['q' => $subdomain]))->toBe([$ownerId]);
    } finally {
        ovaSearchCleanup([$ownerId]);
    }
});

it('#W1-SEC-3: q does not let a support actor use phone as a search oracle, but admin retains it', function () {
    $byPhone = ovaSearchUser(['phone' => '+61491570156', 'sector' => 'writer']);

    try {
        // Support actor: no narrowing at all — identical to an unrelated
        // string, not merely "empty" by coincidence.
        $supportPhoneHit = ovaSearchIds(['q' => '+61491570156']);
        $supportUnrelated = ovaSearchIds(['q' => 'zqneedle-unrelated-'.Str::random(8)]);
        expect($supportPhoneHit)->toBe([])
            ->and($supportPhoneHit)->toBe($supportUnrelated);

        // Admin positive control: the capability is preserved for the tier
        // that already sees the value.
        expect(ovaSearchIds(['q' => '+61491570156'], ovaSearchAdminActor()))->toBe([$byPhone]);
    } finally {
        ovaSearchCleanup([$byPhone]);
    }
});
