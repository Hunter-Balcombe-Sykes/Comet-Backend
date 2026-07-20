<?php

// LIFE-107 / LIFE-108: IdentitySync::applyFromGooglePayload folds a Google
// Business payload onto site.workplaces + core.users under a locked re-read
// (DB transaction + lockForUpdate) instead of the check-then-write it used to
// run against whatever instance the connection-save observer happened to be
// holding — closing the race where a concurrent manual pick (or a second
// in-flight sync) is read as stale and silently overwritten.
//
// HONEST LIMITATION: tests run on SQLite (see CLAUDE.md), where
// `lockForUpdate()` compiles to a no-op — SQLite has no row-level locking.
// These tests can prove the CODE takes a transaction and reads inside it
// (real, observable behaviour), but CANNOT prove row-level locking actually
// serializes two real concurrent Postgres connections — that guarantee comes
// from Postgres semantics, not from anything a single-connection SQLite test
// can observe. Do not add an assertion claiming a "FOR UPDATE query ran": on
// SQLite an unknown quoted identifier parses as a STRING LITERAL, so such an
// assertion can pass against code that locks nothing.

use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Platforms\IdentitySync;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also sets up site.workplaces
});

function idsyncConcUser(string $handle, string $accountType): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => $accountType,
        'status' => 'active',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

/** @return string the created site's id */
function idsyncConcSite(User $user): string
{
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => $user->handle,
        'is_published' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $siteId;
}

// ── LIFE-107 ──────────────────────────────────────────────────────────────

it('does not clobber a concurrent manual sector pick with a stale in-flight Google sync', function () {
    // Business → $overwrite = true, the branch the pre-fix bug lived in
    // (business overwrites ANY differing value, manual pick or not, unless
    // the manual guard trips against a CURRENT read).
    $user = idsyncConcUser('life107', 'business');
    idsyncConcSite($user);
    expect($user->sector)->toBeNull();
    expect($user->sector_source)->toBeNull();

    // The instance the observer/refresh job would be holding for this call —
    // loaded BEFORE the concurrent manual pick below lands, so it never sees it.
    $staleUser = User::find($user->id);

    // Simulate SectorController's manual pick landing WHILE this fold is in
    // flight — a raw table write bypassing the model, so $staleUser's
    // in-memory attributes stay exactly what they were at load: genuinely stale.
    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update([
        'sector' => 'barber', 'sector_source' => 'manual',
    ]);

    // Pick a category that provably maps to something OTHER than 'barber' —
    // asserted here so this test fails loudly (not silently passes for the
    // wrong reason) if SectorTaxonomy's keyword map ever changes.
    $category = 'Nail salon';
    $mapped = SectorTaxonomy::fromGoogleCategory($category);
    expect($mapped)->not->toBeNull();
    expect($mapped)->not->toBe('barber');

    app(IdentitySync::class)->applyFromGooglePayload($staleUser, ['category' => $category]);

    // Today (pre-fix): $staleUser's in-memory sector_source is null, so the
    // manual guard never trips, $overwrite is true, and Google's mapped
    // sector overwrites the concurrent manual pick — this assertion fails.
    $fresh = User::query()->find($user->id);
    expect($fresh->sector)->toBe('barber');
    expect($fresh->sector_source)->toBe('manual');
});

// ── LIFE-108 ──────────────────────────────────────────────────────────────

it('folds the workplace identity fields inside an open DB transaction', function () {
    $user = idsyncConcUser('life108', 'business');
    $siteId = idsyncConcSite($user);

    // Every workplace-table query, and the Workplace model's own saving hook,
    // records the transaction nesting level active at that moment.
    $levels = [];
    DB::listen(function ($query) use (&$levels) {
        if (str_contains($query->sql, 'workplaces')) {
            $levels[] = DB::connection('pgsql')->transactionLevel();
        }
    });
    Workplace::saving(function () use (&$levels) {
        $levels[] = DB::connection('pgsql')->transactionLevel();
    });

    app(IdentitySync::class)->applyFromGooglePayload($user, [
        'name' => 'Nail Bar', 'phone' => '(03) 5555 5555', 'category' => 'Nail salon',
    ]);

    // Today (pre-fix): applyFromGooglePayload never opens a transaction, so
    // every recorded level is 0 — this assertion fails.
    expect($levels)->not->toBeEmpty();
    foreach ($levels as $level) {
        expect($level)->toBeGreaterThanOrEqual(1);
    }

    // Data half: proves this ran a real fold, not an empty/no-op transaction.
    $workplace = Workplace::query()->where('site_id', $siteId)->firstOrFail();
    expect($workplace->name)->toBe('Nail Bar');
    expect($workplace->phone)->toBe('(03) 5555 5555');
    expect($workplace->field_sources['name']['source'])->toBe('google-business');
});

// ── Source contract (weak by construction — see file header) ──────────────
// Mirrors tests/Feature/Platforms/NoUntypedPayloadAccessTest.php's file-content
// grep pattern. SQLite can't prove locking semantically (see HONEST
// LIMITATION above), so this exists purely to pin "the code path still calls
// lockForUpdate() and opens a transaction", catching an accidental revert
// that the SQLite-backed tests above might not otherwise notice.

it('IdentitySync.php source calls lockForUpdate and opens a transaction at least twice (once for the workplace row, once for the user row)', function () {
    $source = file_get_contents(app_path('Services/Platforms/IdentitySync.php'));

    expect(substr_count($source, 'lockForUpdate'))->toBeGreaterThanOrEqual(2);
    expect(substr_count($source, '->transaction('))->toBeGreaterThanOrEqual(2);
});
