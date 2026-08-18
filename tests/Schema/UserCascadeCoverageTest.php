<?php

// R3 (overnight 2026-08-18), the class-level half. The FK added by migration
// 20260819002100 stops routing.link_observations leaking orphans; this stops
// the NEXT table doing the same thing.
//
// Every table carrying a user_id must have a route by which closing an account
// removes (or pseudonymises) its rows. routing.link_observations had none for
// three weeks, and nobody noticed because nothing looks: the writer
// (LinkObserver::record()) swallows failures into a Log::warning, there is no
// model, no observer and no policy on that path. A DB-level guarantee is the
// only kind a raw DB::table() insert cannot walk past — and this test is the
// only thing that checks the guarantee exists.
//
// Runs in the applied-schema lane (phpunit.schema.xml / `composer test:schema`,
// see Tests\SchemaTestCase) against a container the real supabase/migrations/
// set has been applied to. It asserts the SCHEMA AS BUILT, deliberately, not
// the migration text: a column that arrived by a hand-applied ALTER counts just
// the same as one from a migration file.
//
// Measured when written (dev glncumufgaqcmqhzwrxm, 2026-08-18): 48 tables carry
// user_id; 39 are cascade-reachable, 8 are SET NULL by design, and exactly one
// — routing.link_observations — was neither. So this went in green with an
// empty exemption list, which is the only honest moment to add a guard.
//
// Scope limit, stated rather than hidden: this keys on the column NAME user_id.
// A user link named anything else is invisible to it. That is acceptable only
// because CLAUDE.md fixes user_id as the convention for the core.users FK.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

/**
 * Schemas that hold user-scoped application data. 'public' is Laravel infra
 * (jobs, cache, sessions) but is included so a stray user_id there is caught.
 */
const USER_CASCADE_SCHEMAS = [
    'core', 'site', 'notifications', 'analytics', 'audit',
    'moderation', 'catalog', 'content', 'ingest', 'routing', 'public',
];

/**
 * Tables where ON DELETE SET NULL is the INTENDED behaviour: the event is kept
 * and the person is unlinked. Legitimate only where the surviving row carries
 * no personal data of its own — which is exactly the test
 * routing.link_observations failed (raw_url IS the personal data, so unlinking
 * would have kept the thing that must go).
 *
 * An entry here is a claim that the remaining columns are safe to retain
 * indefinitely. Do not add one to silence a failure.
 */
const USER_PSEUDONYMISED = [
    // Append-only audit trails: the compliance record is the point, and both
    // carry a reject-mutation trigger, so the row cannot be deleted anyway.
    'audit.staff_audit_log',
    'audit.handle_change_log',
    // Auth/security and deletion forensics — retained to prove what happened.
    'audit.auth_factor_events',
    'audit.data_export_audit',
    'audit.user_deletion_audit',
    // Product surfaces whose value survives the account: lead capture and
    // feedback are kept unattributed.
    'analytics.lead_submissions',
    'core.feedback',
    'core.early_access_signups',
];

/**
 * Tables with neither a cascade path nor SET NULL, accepted anyway. EMPTY BY
 * DESIGN — every entry is a table whose rows outlive their owner. If you are
 * adding one, write the justification inline and be sure it is not PII.
 */
const USER_CASCADE_EXEMPT = [];

/**
 * Every table reachable from core.users by following ON DELETE CASCADE FK edges
 * — i.e. every table a `DELETE FROM core.users` empties, at any depth. This is
 * what makes analytics.action_events legitimate: its user_id has no FK, but the
 * row dies anyway via site_id -> site.sites -> core.users.
 *
 * Partitions are excluded from the candidate list (relispartition): the FK
 * lives on the partitioned parent and propagates, so a partition would
 * double-count its parent.
 */
function userCascadeAudit(): array
{
    $schemas = "'".implode("','", USER_CASCADE_SCHEMAS)."'";

    return DB::select(
        "WITH RECURSIVE fk AS (
            SELECT con.conrelid AS child, con.confrelid AS parent, con.confdeltype
            FROM pg_constraint con
            WHERE con.contype = 'f'
         ),
         reach AS (
            SELECT 'core.users'::regclass::oid AS oid
            UNION
            SELECT fk.child FROM fk JOIN reach ON fk.parent = reach.oid
            WHERE fk.confdeltype = 'c'
         ),
         candidates AS (
            SELECT n.nspname AS sch, c.relname AS tbl, c.oid
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE a.attname = 'user_id'
              AND a.attnum > 0 AND NOT a.attisdropped
              AND c.relkind IN ('r', 'p')
              AND NOT c.relispartition
              AND n.nspname IN ({$schemas})
         )
         SELECT cd.sch || '.' || cd.tbl AS tbl,
                (cd.oid IN (SELECT oid FROM reach)) AS cascade_reachable,
                EXISTS (
                    SELECT 1 FROM pg_constraint con
                    JOIN unnest(con.conkey) k(attnum) ON true
                    JOIN pg_attribute a2 ON a2.attrelid = con.conrelid AND a2.attnum = k.attnum
                    WHERE con.conrelid = cd.oid
                      AND con.contype = 'f'
                      AND a2.attname = 'user_id'
                      AND con.confrelid = 'core.users'::regclass
                      AND con.confdeltype = 'n'
                ) AS direct_set_null
         FROM candidates cd
         ORDER BY 1"
    );
}

it('every table with a user_id is emptied or unlinked when the account closes', function () {
    $rows = userCascadeAudit();

    // Non-vacuity: an empty or tiny result would make every assertion below
    // pass while proving nothing — the exact rot UpdatedAtTriggerCoverageTest
    // documents. 48 tables qualified when this was written.
    expect(count($rows))->toBeGreaterThan(40, 'user_id audit returned implausibly few tables — is the schema applied?');

    $uncovered = [];

    foreach ($rows as $row) {
        if ($row->cascade_reachable) {
            continue;
        }

        if ($row->direct_set_null && in_array($row->tbl, USER_PSEUDONYMISED, true)) {
            continue;
        }

        if (in_array($row->tbl, USER_CASCADE_EXEMPT, true)) {
            continue;
        }

        $uncovered[] = $row->direct_set_null
            ? "{$row->tbl} (ON DELETE SET NULL but not listed in USER_PSEUDONYMISED)"
            : "{$row->tbl} (no cascade path to core.users at all)";
    }

    expect($uncovered)->toBe([], "Tables whose rows survive their owner's deletion:\n  - "
        .implode("\n  - ", $uncovered)
        ."\n\nGive the table an ON DELETE CASCADE FK to core.users (or a cascading parent), or — if the "
        .'rows must outlive the account and carry no personal data — add it to USER_PSEUDONYMISED / '
        .'USER_CASCADE_EXEMPT in this test with a written justification.');
});

it('every pseudonymised entry still names a real table that is still SET NULL', function () {
    $byTable = collect(userCascadeAudit())->keyBy('tbl');

    foreach (USER_PSEUDONYMISED as $table) {
        expect($byTable->has($table))->toBeTrue(
            "USER_PSEUDONYMISED lists [{$table}], which has no user_id column (dropped or renamed?). Remove the entry."
        );
        expect($byTable[$table]->direct_set_null)->toBeTrue(
            "USER_PSEUDONYMISED lists [{$table}] but its user_id FK is no longer ON DELETE SET NULL. The entry is now fiction."
        );
    }
});

it('every cascade exemption still names a real table', function () {
    $known = collect(userCascadeAudit())->pluck('tbl')->all();

    foreach (USER_CASCADE_EXEMPT as $table) {
        expect($known)->toContain($table);
    }
});

it('routing.link_observations specifically cascades — the R3 regression', function () {
    $row = DB::selectOne(
        "SELECT con.confdeltype::text AS on_delete
           FROM pg_constraint con
          WHERE con.conrelid = 'routing.link_observations'::regclass
            AND con.contype = 'f'
            AND con.confrelid = 'core.users'::regclass"
    );

    expect($row)->not->toBeNull('routing.link_observations.user_id has no FK to core.users — migration 20260819002100 did not apply.');
    // 'c' = CASCADE. SET NULL would keep raw_url, which is the personal data.
    expect($row->on_delete)->toBe('c');
});
