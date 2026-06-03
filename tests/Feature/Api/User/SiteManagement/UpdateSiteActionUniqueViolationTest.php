<?php

// LIFE-1: UpdateSiteAction's three unique-violation catch blocks must catch the
// typed Illuminate\Database\UniqueConstraintViolationException, not a generic
// QueryException whose SQLSTATE is string-compared against '23505'.
//
// '23505' is the *Postgres* SQLSTATE for a unique violation. SQLite reports
// '23000' (generic integrity-constraint) for the same error, so the old
// `if ($e->getCode() !== '23505') { throw $e; }` guard actually RE-THROWS the
// violation under SQLite — the catch was silently broken on any non-Postgres
// driver. The typed exception works on both because each connection has its own
// isUniqueConstraintError(). This test reproduces that on the alias-creation
// path: it asserts the rename succeeds and the stale alias's lifecycle
// timestamps are refreshed (the catch block's job), which only happens when the
// violation is actually caught.

use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupHandleChangeLogTable();

    // The alias create only throws a unique violation if the column is actually
    // constrained. Production has a unique index on subdomain; mirror it so the
    // catch path is reachable under SQLite.
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS site.ux_test_alias_subdomain ON site_subdomain_aliases (subdomain)'
    );
});

it('refreshes a stale alias instead of re-throwing when the rename re-creates an existing alias row', function () {
    $pro = createTenant('oldsub');

    // A stale alias already exists for the site's CURRENT subdomain. The rename
    // flow will try to INSERT this same (site_id, subdomain) again — a unique
    // violation the catch block must absorb and convert into a timestamp refresh.
    $staleTs = Carbon::now()->subDays(100)->toDateTimeString();
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $pro->site->id,
        'subdomain' => 'oldsub',
        'reclaim_until' => $staleTs,
        'expires_at' => $staleTs,
        'created_at' => $staleTs,
    ]);

    $site = app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'newsub']);

    // The rename landed despite the duplicate-alias violation mid-flight.
    expect($site->subdomain)->toBe('newsub');
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('subdomain'))
        ->toBe('newsub');

    // The catch block refreshed the stale alias's lifecycle timestamps to the future.
    $alias = DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('site_id', $pro->site->id)
        ->whereRaw('lower(subdomain) = ?', ['oldsub'])
        ->first();

    expect($alias)->not->toBeNull();
    expect(Carbon::parse($alias->expires_at)->isFuture())->toBeTrue();
    expect(Carbon::parse($alias->reclaim_until)->isFuture())->toBeTrue();
});
