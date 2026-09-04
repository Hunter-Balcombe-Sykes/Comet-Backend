<?php

// The `occurrence` ordering and the `upcoming_occurrence` predicate are the
// only parts of the events pool written as raw SQL, and the SQLite feature
// lane cannot vouch for either on the engine that actually serves them.
//
// Two Postgres-specific things are under test here:
//   1. `ORDER BY (correlated scalar subquery) ASC NULLS LAST` parses and
//      sorts. NULLS LAST is standard SQL that SQLite also accepts, so a green
//      Feature suite says nothing about whether Postgres agrees.
//   2. The correlated MIN is a SCALAR, not a join — an item dated by two
//      sources must yield ONE candidate row. A join would fan it to two and
//      the public Events page would list the same event twice.
//
// Failure mode if this regresses: a 500 on every public profile belonging to
// a user with an events pool, which is why it is worth a lane of its own.

use App\Site\Sections\SectionCandidates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    foreach (['core', 'site', 'content'] as $schema) {
        $pg->statement("CREATE SCHEMA IF NOT EXISTS {$schema}");
    }

    // core.users and site.sites are SHARED across this lane and must never be
    // dropped — siblings FK to them, and a sibling created them first with a
    // wider shape. Create additively (the documented convention, see
    // SubdomainAliasCollisionTest's header) and own only the two content
    // tables this file actually tests.
    $pg->statement('CREATE TABLE IF NOT EXISTS core.users (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid()
    )');

    // site.sites must be created with the FULL shape, not the two columns
    // this file needs. Test files in this lane run in filesystem order and
    // `CREATE TABLE IF NOT EXISTS` means WHOEVER RUNS FIRST decides the shape
    // — so an under-provisioned table here is a 42703 in whichever sibling
    // runs later (SubdomainAliasCollisionTest inserts `subdomain`). Every
    // column carries a default so this file can still insert id/user_id alone.
    $pg->statement("CREATE TABLE IF NOT EXISTS site.sites (
        id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id              uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        subdomain            character varying(63) NOT NULL DEFAULT '',
        subdomain_changed_at timestamptz,
        settings             jsonb NOT NULL DEFAULT '{}'::jsonb,
        is_published         boolean NOT NULL DEFAULT false,
        created_at           timestamptz NOT NULL DEFAULT now(),
        updated_at           timestamptz NOT NULL DEFAULT now()
    )");

    // …and heal a minimal one a sibling created first, for the same reason.
    foreach ([
        'subdomain' => "character varying(63) NOT NULL DEFAULT ''",
        'subdomain_changed_at' => 'timestamptz',
        'settings' => "jsonb NOT NULL DEFAULT '{}'::jsonb",
        'is_published' => 'boolean NOT NULL DEFAULT false',
        'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        'updated_at' => 'timestamptz NOT NULL DEFAULT now()',
    ] as $col => $type) {
        $pg->statement("ALTER TABLE site.sites ADD COLUMN IF NOT EXISTS {$col} {$type}");
    }

    // A sibling that created `subdomain` without a default leaves it NOT NULL
    // and unfillable by this file's two-column insert.
    $pg->statement("ALTER TABLE site.sites ALTER COLUMN subdomain SET DEFAULT ''");

    $pg->statement('DROP TABLE IF EXISTS content.f_occurrence CASCADE');
    $pg->statement('DROP TABLE IF EXISTS content.items CASCADE');

    $pg->statement('CREATE TABLE content.items (
        id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id        uuid NOT NULL,
        kind           text NOT NULL,
        headline_cache text NULL,
        removed_at     timestamptz NULL,
        first_seen_at  timestamptz NOT NULL DEFAULT now(),
        last_seen_at   timestamptz NOT NULL DEFAULT now()
    )');

    // PK (item_id, source_id) exactly as production — that composite is the
    // whole reason the ordering may not join.
    $pg->statement('CREATE TABLE content.f_occurrence (
        item_id       uuid NOT NULL,
        source_id     uuid NOT NULL,
        starts_at_utc timestamptz NULL,
        updated_at    timestamptz NOT NULL DEFAULT now(),
        PRIMARY KEY (item_id, source_id)
    )');

    // LiveSourceScope (W6, 2026-08-18) made every section/pool candidate query
    // reach content.source_items -> content.sources -> site.platform_connections.
    // This file inserts into none of them — an item with NO source_items is LIVE
    // by the scope's first branch, which is exactly the shape these tests use —
    // but the tables must EXIST or the whole query is a 42P01 before ordering is
    // ever evaluated. That is what turned this file red on `development`.
    //
    // Additive and HEALED, never dropped: all three are shared across this lane
    // and whoever runs first decides the shape (same trap as site.sites above).
    // ItemTombstoneBackfillTest creates site.platform_connections with NO
    // is_active, so a bare CREATE IF NOT EXISTS would inherit that and 42703 on
    // `lpc.is_active` instead — the ADD COLUMN IF NOT EXISTS pass below is the
    // load-bearing half, not decoration.
    $pg->statement('CREATE TABLE IF NOT EXISTS content.sources (
        id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id       uuid NULL,
        kind          text NOT NULL DEFAULT \'manual\',
        connection_id uuid NULL,
        created_at    timestamptz NOT NULL DEFAULT now(),
        updated_at    timestamptz NOT NULL DEFAULT now()
    )');

    // No FKs: this file DROPs content.items CASCADE above, and a FK from here
    // would just be dropped with it on the next run.
    $pg->statement('CREATE TABLE IF NOT EXISTS content.source_items (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id  uuid NOT NULL,
        coord      text NOT NULL DEFAULT \'\',
        item_id    uuid NULL,
        kind       text NOT NULL DEFAULT \'media\',
        removed_at timestamptz NULL
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
        id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id     uuid NULL,
        is_active   boolean NOT NULL DEFAULT true,
        deleted_at  timestamptz NULL
    )');

    foreach ([
        'content.sources' => ['connection_id' => 'uuid', 'kind' => "text NOT NULL DEFAULT 'manual'"],
        'content.source_items' => ['item_id' => 'uuid', 'source_id' => 'uuid', 'removed_at' => 'timestamptz'],
        'site.platform_connections' => ['is_active' => 'boolean NOT NULL DEFAULT true', 'deleted_at' => 'timestamptz'],
    ] as $table => $columns) {
        foreach ($columns as $col => $type) {
            $pg->statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$col} {$type}");
        }
    }
});

/**
 * A user + its site. site.sites FKs to core.users in this lane's shared
 * shape, so the user row is not optional.
 *
 * @return array{0: string, 1: string} [userId, siteId]
 */
function pgTenant(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);
    DB::connection('pgsql')->table('site.sites')->insert(['id' => $siteId, 'user_id' => $userId]);

    return [$userId, $siteId];
}

/**
 * The raw site.sections row shape ruleCandidates() reads.
 *
 * `id` is load-bearing, not decoration: 280043507 made ruleCandidates() memoise
 * on it, so a stub without one reads an undefined property and every section
 * would share the memo slot. A fresh uuid per call mirrors a real row and keeps
 * each test's lookup its own.
 */
function occurrenceSection(string $siteId): object
{
    return (object) [
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'order_by' => 'occurrence',
        'rule' => json_encode(['all' => [
            ['op' => 'kind_is', 'values' => ['event']],
            ['op' => 'upcoming_occurrence', 'values' => ['event']],
        ]]),
    ];
}

function pgEvent(string $userId, string $headline, ?string $startsAtUtc, int $sources = 1): string
{
    $itemId = (string) Str::uuid();
    DB::connection('pgsql')->table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'event',
        'headline_cache' => $headline,
    ]);

    for ($i = 0; $i < $sources; $i++) {
        DB::connection('pgsql')->table('content.f_occurrence')->insert([
            'item_id' => $itemId, 'source_id' => (string) Str::uuid(),
            // Later sources date the event further out, so a MIN that is not
            // really a MIN sorts differently from one that is.
            'starts_at_utc' => $startsAtUtc === null
                ? null
                : now()->parse($startsAtUtc)->addDays($i * 7)->toDateTimeString(),
        ]);
    }

    return $itemId;
}

it('orders upcoming events soonest-first on real Postgres', function () {
    [$userId, $siteId] = pgTenant();

    $furthest = pgEvent($userId, 'Furthest', now()->addDays(30)->toDateTimeString());
    $soonest = pgEvent($userId, 'Soonest', now()->addDays(2)->toDateTimeString());
    $middle = pgEvent($userId, 'Middle', now()->addDays(14)->toDateTimeString());
    pgEvent($userId, 'Long gone', now()->subDays(30)->toDateTimeString());

    $ids = app(SectionCandidates::class)->ruleCandidates(occurrenceSection($siteId), []);

    expect($ids)->toBe([$soonest, $middle, $furthest]);
});

it('emits one candidate row for an event dated by two sources', function () {
    [$userId, $siteId] = pgTenant();

    $twoSourced = pgEvent($userId, 'Double-listed', now()->addDays(5)->toDateTimeString(), sources: 2);

    $ids = app(SectionCandidates::class)->ruleCandidates(occurrenceSection($siteId), []);

    expect($ids)->toBe([$twoSourced]);
});

it('sorts an undated event last rather than dropping the ordering', function () {
    [$userId, $siteId] = pgTenant();

    // Undated rows never match `upcoming_occurrence`, so reach NULLS LAST via
    // a rule that admits them — the ordering must still be well-formed.
    $dated = pgEvent($userId, 'Dated', now()->addDays(3)->toDateTimeString());
    $undated = pgEvent($userId, 'Undated', null);

    $kindOnly = (object) [
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'order_by' => 'occurrence',
        'rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['event']]]]),
    ];

    expect(app(SectionCandidates::class)->ruleCandidates($kindOnly, []))
        ->toBe([$dated, $undated]);
});
