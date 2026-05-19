<?php

/** @phpstan-ignore-all */

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    setupCoreSchema();

    DB::table('site.professional_handle_aliases')->delete();
    DB::table('site.site_subdomain_aliases')->delete();
    DB::table('site.public_site_payload')->delete();
    DB::table('site.sites')->delete();
    DB::table('core.professionals')->delete();
})->group('subdomain');

it('prevents professionals from changing subdomain within 30 days', function () {
    Carbon::setTestNow('2025-01-15');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
    ]);

    DB::table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'old',
        'subdomain_changed_at' => Carbon::now()->subDays(10)->toDateTimeString(),
    ]);

    $professional = Professional::findOrFail($proId);

    $action = app(UpdateSiteAction::class);

    $this->expectException(ValidationException::class);
    $action->execute($professional, ['subdomain' => 'new']);
});

it('stores old subdomain as alias after a valid change', function () {
    Carbon::setTestNow('2025-01-15');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
    ]);

    DB::table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'old',
        'subdomain_changed_at' => Carbon::now()->subDays(31)->toDateTimeString(),
    ]);

    $professional = Professional::findOrFail($proId);
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

    DB::table('core.professionals')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
        'handle' => 'old',
        'handle_lc' => 'old',
    ]);

    DB::table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'old',
        'subdomain_changed_at' => Carbon::now()->subDays(31)->toDateTimeString(),
    ]);

    $professional = Professional::findOrFail($proId);
    $action = app(UpdateSiteAction::class);

    $action->execute($professional, ['subdomain' => 'new']);

    // Site, professional, and alias rows must all reflect the new handle.
    $proRow = DB::table('core.professionals')->where('id', $proId)->first();
    expect($proRow->handle)->toBe('new');
    expect($proRow->handle_lc)->toBe('new');

    $handleAlias = DB::table('site.professional_handle_aliases')
        ->where('professional_id', $proId)
        ->first();

    expect($handleAlias)->not->toBeNull();
    expect($handleAlias->handle)->toBe('old');
});

it('redirects old subdomain to new site host', function () {
    $domain = config('partna.public_domain');
    $oldHost = 'old.'.$domain;
    $newHost = 'new.'.$domain;

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $aliasId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $proId,
        'display_name' => 'Test Pro',
    ]);

    DB::table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'new',
        'is_published' => 1,
    ]);

    DB::table('site.site_subdomain_aliases')->insert([
        'id' => $aliasId,
        'site_id' => $siteId,
        'subdomain' => 'old',
        'created_at' => now()->toDateTimeString(),
    ]);

    DB::table('site.public_site_payload')->insert([
        'site_id' => $siteId,
        'subdomain' => 'new',
        'payload' => json_encode(['site' => ['id' => $siteId]]),
    ]);

    $response = $this->get('http://'.$oldHost.'/api/public/site');

    $response->assertStatus(301);
    $response->assertRedirect('http://'.$newHost.'/api/public/site');
});

function setupCoreSchema(): void
{
    // Run all schema setup on the 'pgsql' connection explicitly. Models in
    // this project extend BaseModel which forces $connection = 'pgsql', so
    // any tables we create on a different connection are invisible to them
    // — even though both connections may resolve to the same SQLite driver.
    $conn = DB::connection('pgsql');
    $driver = $conn->getDriverName();

    if ($driver === 'sqlite') {
        // SQLite doesn't have schemas; fake them via ATTACH DATABASE so
        // models that reference 'core.professionals' / 'site.public_site_payload'
        // resolve correctly.
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS core");
        } catch (\Throwable $e) {
            // Ignore if already attached.
        }

        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS site");
        } catch (\Throwable $e) {
            // Ignore if already attached.
        }

        // Permissive professionals table — only the columns this test (and
        // soft-delete scopes added by Eloquent) need. Everything nullable
        // because we don't care about prod constraints in tests.
        $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
            id TEXT PRIMARY KEY,
            display_name TEXT NULL,
            handle TEXT NULL,
            handle_lc TEXT NULL,
            professional_type TEXT NULL,
            account_type TEXT NULL,
            status TEXT NULL,
            deleted_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        // Sites live under site.sites in production (Site model: $table = 'site.sites').
        // Add deleted_at for soft-delete scope compatibility.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
            id TEXT PRIMARY KEY,
            professional_id TEXT NULL,
            subdomain TEXT NULL,
            subdomain_changed_at TEXT NULL,
            is_published INTEGER NULL,
            settings TEXT NULL,
            deleted_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        // site_subdomain_aliases lives under the 'site' schema in production.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
            id TEXT PRIMARY KEY,
            site_id TEXT NOT NULL,
            subdomain TEXT NOT NULL,
            reclaim_until TEXT NULL,
            expires_at TEXT NULL,
            created_at TEXT NOT NULL
        )');

        // professional_handle_aliases — historical handle row written when a
        // professional's subdomain changes (the canonical handle changes too,
        // so the old handle becomes an alias for HydrogenAffiliateController
        // lookups to keep resolving).
        $conn->statement('CREATE TABLE IF NOT EXISTS site.professional_handle_aliases (
            id TEXT PRIMARY KEY,
            professional_id TEXT NOT NULL,
            handle TEXT NOT NULL,
            reclaim_until TEXT NULL,
            expires_at TEXT NULL,
            notified_t3_at TEXT NULL,
            notified_t1_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        // The PublicSitePayload view lives under the 'site' schema in production;
        // mirror it as a plain table here so the model's table reference resolves.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.public_site_payload (
            site_id TEXT PRIMARY KEY,
            subdomain TEXT NULL,
            payload TEXT NULL
        )');

        // Append-only audit log for handle/subdomain renames. Trigger-locked in
        // production; in SQLite we just create it so the action can INSERT rows.
        $conn->statement('CREATE TABLE IF NOT EXISTS core.handle_change_log (
            id TEXT PRIMARY KEY,
            professional_id TEXT NULL,
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

    DB::statement('CREATE SCHEMA IF NOT EXISTS core');

    DB::statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id uuid PRIMARY KEY,
        display_name varchar(255) NULL
    )');

    DB::statement('CREATE TABLE IF NOT EXISTS core.sites (
        id uuid PRIMARY KEY,
        professional_id uuid NULL,
        subdomain varchar(63) NULL,
        subdomain_changed_at timestamptz NULL,
        is_published boolean NULL,
        created_at timestamptz NULL,
        updated_at timestamptz NULL
    )');

    DB::statement('CREATE TABLE IF NOT EXISTS core.site_subdomain_aliases (
        id uuid PRIMARY KEY,
        site_id uuid NOT NULL,
        subdomain varchar(63) NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )');

    DB::statement('CREATE TABLE IF NOT EXISTS core.public_site_payload (
        site_id uuid PRIMARY KEY,
        subdomain varchar(63) NULL,
        payload jsonb NULL
    )');
}
