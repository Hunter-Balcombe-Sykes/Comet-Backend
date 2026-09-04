<?php

/** @phpstan-ignore-all */

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    setupCoreSchema();

    DB::table('core.user_handle_aliases')->delete();
    DB::table('site.site_subdomain_aliases')->delete();
    DB::table('site.sites')->delete();
    DB::table('core.users')->delete();
})->group('subdomain');

it('prevents professionals from changing subdomain within 30 days', function () {
    Carbon::setTestNow('2025-01-15');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::table('core.users')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
        'handle' => 'testpro',
        'handle_lc' => 'testpro',
        'first_name' => 'Test',
    ]);

    DB::table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'old',
        'subdomain_changed_at' => Carbon::now()->subDays(10)->toDateTimeString(),
    ]);

    $professional = User::findOrFail($proId);

    $action = app(UpdateSiteAction::class);

    $this->expectException(ValidationException::class);
    $action->execute($professional, ['subdomain' => 'new']);
});

it('stores old subdomain as alias after a valid change', function () {
    Carbon::setTestNow('2025-01-15');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::table('core.users')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
        'handle' => 'testpro',
        'handle_lc' => 'testpro',
        'first_name' => 'Test',
    ]);

    DB::table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'old',
        'subdomain_changed_at' => Carbon::now()->subDays(31)->toDateTimeString(),
    ]);

    $professional = User::findOrFail($proId);
    $action = app(UpdateSiteAction::class);

    $action->execute($professional, ['subdomain' => 'new']);

    $site = Site::findOrFail($siteId);
    expect($site->subdomain)->toBe('new');
    expect($site->subdomain_changed_at->toDateString())->toBe('2025-01-15');

    $alias = DB::table('site.site_subdomain_aliases')
        ->where('site_id', $siteId)
        ->first();

    expect($alias)->not->toBeNull();
    expect($alias->subdomain)->toBe('old');
});

it('syncs professional.handle + handle_lc and writes a handle alias on subdomain change', function () {
    Carbon::setTestNow('2025-01-15');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::table('core.users')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
        'handle' => 'old',
        'handle_lc' => 'old',
        'first_name' => 'Test',
    ]);

    DB::table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'old',
        'subdomain_changed_at' => Carbon::now()->subDays(31)->toDateTimeString(),
    ]);

    $professional = User::findOrFail($proId);
    $action = app(UpdateSiteAction::class);

    $action->execute($professional, ['subdomain' => 'new']);

    // Site, professional, and alias rows must all reflect the new handle.
    $proRow = DB::table('core.users')->where('id', $proId)->first();
    expect($proRow->handle)->toBe('new');
    expect($proRow->handle_lc)->toBe('new');

    $handleAlias = DB::table('core.user_handle_aliases')
        ->where('user_id', $proId)
        ->first();

    expect($handleAlias)->not->toBeNull();
    expect($handleAlias->handle)->toBe('old');
});

// REMOVED 2026-09-04: it('redirects old subdomain to new site host').
// It asserted a host-based 301 served by the domain-scoped /api/public/site
// route, which Task 1 retired (Task 5 removes the whole
// {subdomain}.partna.au group). Users lose nothing: the alias 301 is EDGE
// behaviour — the Cloudflare Worker serves it from SUBDOMAIN_KV on a
// {type:"alias"} entry written by SyncSubdomainToKvJob. The backend route was
// a second implementation of the same redirect, reachable only through a lane
// with no callers. The alias ROW this file still pins (see above) is the input
// that job reads.

function setupCoreSchema(): void
{
    // core.users / site.sites now come from the shared, prod-mirrored stubs
    // (also attaches the core/site/audit/... SQLite schema shims via
    // attachTestSchemas() — a no-op against real Postgres).
    setupUsersTable();
    setupSitesTable();

    // Run the rest of the schema setup on the 'pgsql' connection explicitly.
    // Models in this project extend BaseModel which forces $connection =
    // 'pgsql', so any tables we create on a different connection are
    // invisible to them — even though both connections may resolve to the
    // same SQLite driver.
    $conn = DB::connection('pgsql');
    $driver = $conn->getDriverName();

    if ($driver === 'sqlite') {
        // site_subdomain_aliases lives under the 'site' schema in production.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
            id TEXT PRIMARY KEY,
            site_id TEXT NOT NULL,
            subdomain TEXT NOT NULL,
            reclaim_until TEXT NULL,
            expires_at TEXT NULL,
            created_at TEXT NOT NULL
        )');

        // user_handle_aliases — historical handle row written when a
        // professional's subdomain changes (the canonical handle changes too,
        // so the old handle becomes an alias for the public site resolver
        // lookups to keep resolving).
        $conn->statement('CREATE TABLE IF NOT EXISTS core.user_handle_aliases (
            id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            handle TEXT NOT NULL,
            reclaim_until TEXT NULL,
            expires_at TEXT NULL,
            notified_t3_at TEXT NULL,
            notified_t1_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        // Append-only audit log for handle/subdomain renames. Trigger-locked in
        // production; in SQLite we just create it so the action can INSERT rows.
        $conn->statement('CREATE TABLE IF NOT EXISTS audit.handle_change_log (
            id TEXT PRIMARY KEY,
            user_id TEXT NULL,
            old_handle TEXT NULL,
            new_handle TEXT NULL,
            reason TEXT NULL,
            actor_id TEXT NULL,
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            changed_at TEXT NULL
        )');

        return;
    }

    // core.users / site.sites (formerly a quirky local core.sites) are already
    // provisioned above by setupUsersTable()/setupSitesTable() — a no-op here
    // since real Postgres already has both via supabase/migrations.
    DB::statement('CREATE SCHEMA IF NOT EXISTS core');

    DB::statement('CREATE TABLE IF NOT EXISTS core.site_subdomain_aliases (
        id uuid PRIMARY KEY,
        site_id uuid NOT NULL,
        subdomain varchar(63) NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )');
}
