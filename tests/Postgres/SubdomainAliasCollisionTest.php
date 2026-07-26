<?php

// Regression sentinel for the PATCH /api/site 500 observed on dev 2026-07-26
// (Nightwatch a90fbbc0, 3 occurrences):
//
//   SQLSTATE[25P02]: In failed sql transaction ... update "site"."site_subdomain_aliases"
//   set "reclaim_until" = ..., "expires_at" = ... where "site_id" = ... and lower(subdomain) = ...
//
// The reported statement was the VICTIM, not the culprit. RenameSubdomainAction
// used to insert the outgoing subdomain's alias bare and "recover" in a catch:
//
//     try   { SiteSubdomainAlias::create([...]); }
//     catch (UniqueConstraintViolationException) { ...->update([...]); }
//
// Two independent faults, both of which this file pins:
//
//  1. `core_site_subdomain_aliases_subdomain_lower_unique` is GLOBAL — it indexes
//     lower(subdomain) ALONE, not (site_id, lower(subdomain)). So the colliding row
//     often belongs to a DIFFERENT site, and the recovery UPDATE (filtered
//     `where site_id = <us>`) matched zero rows even in a healthy transaction.
//     Live dev data: site 019f9c9a held subdomain `simondoylehair-1` while site
//     019f987e held an ACTIVE alias on the same name.
//
//  2. PostgreSQL aborts the ENTIRE transaction on ANY statement error. Catching the
//     23505 in PHP restores the application's control flow but not the database's:
//     every later statement in UpdateSiteAction's transaction then failed with 25P02.
//     The fix inserts inside a NESTED DB::transaction() so Laravel emits
//     SAVEPOINT / ROLLBACK TO SAVEPOINT, which clears the aborted state.
//
// SQLite — the driver behind `composer test` — does NOT abort the transaction on a
// statement error, so fault 2 is INVISIBLE to the default suite: those tests pass
// with or without the fix. This bug class has now shipped FOUR times (signup site
// provisioning, the pre-pilot slug slice, MenuScanApplier, and this rename path),
// which is why the sentinel lives in the real-Postgres lane.
//
// Runs via `composer test:pg` / phpunit.pg.xml against a disposable Postgres; see
// Tests\PostgresTestCase for the skip-without-Postgres and disposable-database guards.
// Companion mechanism sentinels: tests/Postgres/ItemSlugAllocatorSavepointTest.php.

use App\Models\Core\HandleChangeLog;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Site\RenameSubdomainAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

// Schema replicated from supabase/migrations/20260726000000_baseline_pilot.sql.
// Only the columns this action reads or writes — the global unique index is the
// load-bearing part and is copied verbatim.
//
// core.users and site.sites are shared with sibling tests in this lane (e.g.
// ItemSlugAllocatorRegressionTest FKs site.item_slugs to core.users), so they are
// created additively — CREATE IF NOT EXISTS plus defensive ADD COLUMN IF NOT EXISTS,
// mirroring the setupSitesTable() pattern in tests/Pest.php — and never dropped.
// Dropping them here fails with 2BP01 once a sibling's FK exists, and dropping with
// CASCADE would silently demolish that sibling's fixture. Only the two tables this
// file exclusively owns get dropped.
// Fixture subdomains owned by this file. Purged up front so a run aborted before
// afterEach (or an older revision of this file) can't leave a row squatting one of
// them — RenameSubdomainAction's conflict pre-check would then throw before ever
// reaching the alias insert under test.
const ALIAS_FIXTURE_SUBDOMAINS = [
    'simondoylehair-1', 'simon-doyle', 'lapsed-name', 'fresh-name',
    'own-name', 'new-name', 'control-name', 'unused',
];

beforeEach(function () {
    $pg = DB::connection('pgsql');
    $GLOBALS['aliasFixtureSiteIds'] = [];
    $GLOBALS['aliasFixtureUserIds'] = [];

    foreach (['core', 'site', 'audit'] as $schema) {
        $pg->statement("CREATE SCHEMA IF NOT EXISTS {$schema}");
    }

    $pg->statement('DROP TABLE IF EXISTS site.site_subdomain_aliases');
    $pg->statement('DROP TABLE IF EXISTS audit.handle_change_log');

    $pg->statement('CREATE TABLE IF NOT EXISTS core.users (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid()
    )');
    foreach ([
        'handle' => 'character varying(63)',
        'handle_lc' => 'character varying(63)',
        'deleted_at' => 'timestamptz',
        'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        'updated_at' => 'timestamptz NOT NULL DEFAULT now()',
    ] as $col => $type) {
        $pg->statement("ALTER TABLE core.users ADD COLUMN IF NOT EXISTS {$col} {$type}");
    }

    $pg->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id              uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        subdomain            character varying(63) NOT NULL,
        subdomain_changed_at timestamptz,
        settings             jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        is_published         boolean NOT NULL DEFAULT false,
        created_at           timestamptz NOT NULL DEFAULT now(),
        updated_at           timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE site.site_subdomain_aliases (
        id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        site_id       uuid NOT NULL REFERENCES site.sites(id) ON DELETE CASCADE,
        subdomain     character varying(63) NOT NULL,
        created_at    timestamptz NOT NULL DEFAULT now(),
        updated_at    timestamptz NOT NULL DEFAULT now(),
        reclaim_until timestamptz,
        expires_at    timestamptz
    )');

    // THE load-bearing constraint: global on lower(subdomain), NOT scoped by site_id.
    $pg->statement('CREATE UNIQUE INDEX core_site_subdomain_aliases_subdomain_lower_unique
        ON site.site_subdomain_aliases (lower(subdomain::text))');

    $pg->statement('CREATE TABLE audit.handle_change_log (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id    uuid,
        old_handle text,
        new_handle text NOT NULL,
        reason     text NOT NULL,
        actor_id   uuid,
        ip_address inet,
        user_agent text,
        changed_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT handle_change_log_reason_check
            CHECK (reason IN (\'rename\', \'reclaim\', \'staff_rename\', \'system\'))
    )');

    // Self-heal against residue from an aborted earlier run (see const above).
    $stale = $pg->table('site.sites')
        ->whereIn(DB::raw('lower(subdomain)'), ALIAS_FIXTURE_SUBDOMAINS)
        ->orWhere('subdomain', 'like', 'other-%')
        ->pluck('user_id', 'id');

    if ($stale->isNotEmpty()) {
        $pg->table('site.sites')->whereIn('id', $stale->keys())->delete();
        $pg->table('core.users')->whereIn('id', $stale->values())->delete();
    }
});

afterEach(function () {
    $pg = DB::connection('pgsql');
    $pg->statement('DROP TABLE IF EXISTS site.site_subdomain_aliases');
    $pg->statement('DROP TABLE IF EXISTS audit.handle_change_log');

    // core.users / site.sites are shared and never dropped (see beforeEach), so this
    // file must remove its OWN rows. Leaving them behind is not merely untidy: a site
    // this file renamed still occupies the target subdomain on the next run, and
    // RenameSubdomainAction's conflict pre-check then throws a ValidationException
    // BEFORE reaching the alias insert under test — a false failure that masks what
    // the sentinel is actually pinning.
    $pg->table('site.sites')->whereIn('id', $GLOBALS['aliasFixtureSiteIds'] ?? [])->delete();
    $pg->table('core.users')->whereIn('id', $GLOBALS['aliasFixtureUserIds'] ?? [])->delete();
    $GLOBALS['aliasFixtureSiteIds'] = [];
    $GLOBALS['aliasFixtureUserIds'] = [];
});

/**
 * Seeds one user + site via raw SQL (no model events, so no observer/queue/Redis
 * reach from this lane) and returns the hydrated models the action operates on.
 *
 * The user's handle is set to the INCOMING subdomain deliberately: that makes
 * RenameSubdomainAction skip its handle-sync branch, so this file exercises the
 * subdomain-alias path — the one that actually 500'd — without dragging
 * UserObserver (cache invalidation, SyncSubdomainToKvJob) into the lane.
 */
function seedRenameFixture(string $currentSubdomain, string $incomingSubdomain): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $GLOBALS['aliasFixtureUserIds'][] = $userId;
    $GLOBALS['aliasFixtureSiteIds'][] = $siteId;

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $incomingSubdomain,
        'handle_lc' => $incomingSubdomain,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $currentSubdomain,
        'subdomain_changed_at' => null,
    ]);

    return [Site::query()->findOrFail($siteId), User::query()->findOrFail($userId)];
}

/** Creates a second site owned by a second user, to hold a foreign alias. */
function seedForeignAlias(string $subdomain, ?string $expiresAt): string
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $GLOBALS['aliasFixtureUserIds'][] = $userId;
    $GLOBALS['aliasFixtureSiteIds'][] = $siteId;

    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'other-'.Str::lower(Str::random(6)),
    ]);
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'subdomain' => $subdomain,
        'expires_at' => $expiresAt,
    ]);

    return $siteId;
}

// ─── 1. The production repro ──────────────────────────────────────────────────
//
// Pre-fix this throws 25P02 out of the transaction closure. The HandleChangeLog
// assertion is the real proof: it is written AFTER the colliding insert, in the
// SAME transaction, so it can only exist if the collision was isolated to a
// savepoint and left the outer transaction usable.

it('survives a collision with another site\'s ACTIVE alias without poisoning the caller transaction', function () {
    [$site, $professional] = seedRenameFixture('simondoylehair-1', 'simon-doyle');
    $foreignSiteId = seedForeignAlias('simondoylehair-1', now()->addDays(90)->toDateTimeString());

    DB::connection('pgsql')->transaction(function () use ($site, $professional) {
        app(RenameSubdomainAction::class)->execute($site, 'simon-doyle', $professional);
        $site->save();
    });

    expect($site->fresh()->subdomain)->toBe('simon-doyle', 'The rename must still complete.');

    // Statements after the collision ran → the transaction was never aborted.
    expect(HandleChangeLog::query()->where('user_id', $professional->id)->count())
        ->toBe(1, 'The audit-log insert runs AFTER the colliding alias insert; a 25P02 would have killed it.');

    // The contested subdomain still has exactly one alias, still the foreign site's.
    $aliases = DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->whereRaw('lower(subdomain) = ?', ['simondoylehair-1'])->get();

    expect($aliases)->toHaveCount(1);
    expect((string) $aliases[0]->site_id)->toBe($foreignSiteId, 'An active foreign alias keeps its claim.');
});

// ─── 2. Expired foreign alias is prune-debt, not a claim ──────────────────────

it('reclaims a subdomain another site holds as an EXPIRED alias', function () {
    [$site, $professional] = seedRenameFixture('lapsed-name', 'fresh-name');
    seedForeignAlias('lapsed-name', now()->subDay()->toDateTimeString());

    DB::connection('pgsql')->transaction(function () use ($site, $professional) {
        app(RenameSubdomainAction::class)->execute($site, 'fresh-name', $professional);
        $site->save();
    });

    $aliases = DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->whereRaw('lower(subdomain) = ?', ['lapsed-name'])->get();

    expect($aliases)->toHaveCount(1, 'The expired foreign row is replaced, not duplicated.');
    expect((string) $aliases[0]->site_id)->toBe((string) $site->id);
    expect($aliases[0]->expires_at)->not->toBeNull();
});

// ─── 3. Own stale alias row — the branch the pre-fix UPDATE meant to serve ─────

it('refreshes its own pre-existing alias row rather than failing', function () {
    [$site, $professional] = seedRenameFixture('own-name', 'new-name');

    // A stale self-owned alias, e.g. from an earlier rename cycle the pruner missed.
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $site->id,
        'subdomain' => 'own-name',
        'expires_at' => now()->subDay()->toDateTimeString(),
        'reclaim_until' => now()->subDays(60)->toDateTimeString(),
    ]);

    DB::connection('pgsql')->transaction(function () use ($site, $professional) {
        app(RenameSubdomainAction::class)->execute($site, 'new-name', $professional);
        $site->save();
    });

    $alias = DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->whereRaw('lower(subdomain) = ?', ['own-name'])->first();

    expect($alias)->not->toBeNull();
    expect((string) $alias->site_id)->toBe((string) $site->id);
    expect(strtotime($alias->expires_at))->toBeGreaterThan(time(), 'Stale lifecycle timestamps must be refreshed.');
});

// ─── 4. Negative control ──────────────────────────────────────────────────────
//
// Proves this lane genuinely reproduces the abort semantics, so a future
// regression in tests 1–3 would be meaningful rather than a false green.

it('confirms a bare insert-and-catch on the global alias index poisons the outer transaction', function () {
    [$site] = seedRenameFixture('control-name', 'unused');
    seedForeignAlias('control-name', now()->addDays(90)->toDateTimeString());

    try {
        DB::connection('pgsql')->beginTransaction();

        $caught = false;
        try {
            // The pre-fix shape: no savepoint.
            DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
                'id' => (string) Str::uuid(),
                'site_id' => $site->id,
                'subdomain' => 'control-name',
            ]);
        } catch (QueryException $e) {
            $caught = $e->getCode() === '23505';
        }

        $poisoned = false;
        try {
            // The pre-fix "recovery" UPDATE — the statement Nightwatch reported.
            DB::connection('pgsql')->table('site.site_subdomain_aliases')
                ->where('site_id', $site->id)
                ->whereRaw('lower(subdomain) = ?', ['control-name'])
                ->update(['expires_at' => now()->addDays(90)->toDateTimeString()]);
        } catch (QueryException $e) {
            $poisoned = $e->getCode() === '25P02';
        }

        expect($caught)->toBeTrue('Expected a 23505 on the global lower(subdomain) unique index.');
        expect($poisoned)->toBeTrue('Expected 25P02 — this is the exact production failure being pinned.');
    } finally {
        DB::connection('pgsql')->rollBack();
    }
});
