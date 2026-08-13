<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\Staff\StaffAuditEntry;
use App\Services\User\AccountDeletionService;
use App\Services\User\DataExport\DataExportPayloadBuilder;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\Architecture\ModelSweep;
use Tests\Support\Architecture\SweepGuard;
use Tests\Support\SchemaDrift\MigrationColumnReplay;
use Tests\Support\SchemaDrift\SelectStatementParser;

/*
|--------------------------------------------------------------------------
| Data Export Coverage Sweep
|--------------------------------------------------------------------------
| Every model carrying direct PII (email / consent telemetry) must either
| (a) have its table listed in DataExportPayloadBuilder::COVERED_PII_TABLES,
| or (b) appear in EXPORT_EXEMPT below with a justification.
|
| sectionDescriptors() is a hand-maintained array, so a new PII table is
| silently ABSENT rather than loudly missing — exactly how
| core.early_access_signups shipped 2026-07-10 with no export or purge
| coverage while the dead core.waitlist_signups kept both. This test turns
| that silent omission into a failing build.
*/

const EXPORT_EXEMPT = [
    // Internal Partna employee record, not a data subject of this exporter.
    // DataExportPayloadBuilder exports one PROFESSIONAL's own account by
    // user_id; staff have no per-professional linkage here. The design spec
    // (docs/superpowers/specs/2026-04-25-data-export-design.md, "Ring 3 —
    // always exclude") explicitly excludes "internal staff notes" and calls
    // out staff data as third-party PII that must NOT appear in a
    // professional's export. There is no separate staff/employee DSAR
    // mechanism in this codebase (out of scope, not a silent omission).
    PartnaStaff::class,

    // audit.staff_audit_log: staff_email_snapshot and impersonator_email_snapshot
    // are the ACTING STAFF MEMBER's (and impersonator's) own email — third-party
    // staff PII, not the professional's. Same Ring 3 "always exclude" rationale
    // as PartnaStaff above. This builder is scoped to one professional's
    // user_id; professional_handle_snapshot on this table is already exported
    // via streamAudit()/streamDeletionAudit() where it's the subject's own data —
    // here it's a bystander column on a row about a staff action, not a
    // professional-initiated record, so the row as a whole stays out.
    StaffAuditEntry::class,
];

/**
 * Column names that mark a model as carrying direct, raw PII.
 *
 * Deliberately CURATED, not a substring/pattern match (e.g. `str_contains($col,
 * 'email')`). A substring match sweeps in non-PII metadata columns too
 * (email_sent_at, email_delivery_status) — forcing a large exemption list that
 * everyone eventually silences, which defeats the guard. `recipient_email_hash`
 * (core.supabase_email_events / SupabaseEmailEvent) is deliberately NOT a
 * marker for a different reason than hashing: that table has no user_id or
 * any other per-professional FK, so it is structurally unscopable to a
 * per-professional export regardless of whether the column is raw or hashed.
 *
 * When a model gains a new raw-PII column, add its exact name here rather
 * than switching to a substring match.
 *
 * PRIV-3: `reporter_email` (moderation.case_signals / CaseSignal — the only
 * model carrying it) was missing, so the one raw email column belonging to a
 * THIRD PARTY rather than the account holder was the one the sweep could not
 * see. Adding a marker only became safe once moderation.case_signals was in
 * COVERED_PII_TABLES — the detector below turns red the moment a marker
 * matches an uncovered table, which is the ordering constraint, not a
 * coincidence.
 */
const PII_MARKERS = [
    'email', 'email_lc', 'consent_ip_hash',
    'primary_email', 'public_contact_email', 'reply_email',
    'contact_email', 'recipient_email', 'reporter_email',
    'professional_email_snapshot',
    'staff_email_snapshot', 'impersonator_email_snapshot',
];

it('every PII-bearing model is covered by the data export', function () {
    $models = ModelSweep::resolvedModelClasses();
    SweepGuard::assertDenominator($models, 50, 'app/Models data-export sweep');

    $missing = [];
    $piiBearing = [];

    foreach ($models as $class) {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }

        // Defensive: a model that cannot be no-arg constructed is not something
        // this sweep can inspect. Skip rather than fail the whole guard.
        try {
            $model = new $class;
        } catch (Throwable) {
            continue;
        }

        $columns = array_merge($model->getFillable(), $model->getHidden());

        if (array_intersect(PII_MARKERS, $columns) === []) {
            continue;
        }

        $piiBearing[] = $class;

        if (in_array($class, EXPORT_EXEMPT, true)) {
            continue;
        }

        if (! in_array($model->getTable(), DataExportPayloadBuilder::COVERED_PII_TABLES, true)) {
            $missing[] = $class.' (table: '.$model->getTable().')';
        }
    }

    // The set that actually reaches the assertion. If PII_MARKERS stops matching
    // (a $fillable → $guarded refactor, a renamed column) this collapses to 0
    // and the guard passes having inspected no PII at all.
    SweepGuard::assertDenominator($piiBearing, 10, 'PII-bearing models');

    expect($missing)->toBe([], "PII-bearing models missing from the data export:\n  - ".implode("\n  - ", $missing)."\n\nEither add the table to DataExportPayloadBuilder::COVERED_PII_TABLES (and wire a section in sectionDescriptors()) or add the model to EXPORT_EXEMPT in this test with a justification.");
});

// Positive control (COV-GUARD-4): the cheap inline form — an empty discovery
// set must redden the floor, proving the guard itself can fail.
it('proves the denominator guard can fail: an empty set is rejected', function () {
    expect(fn () => SweepGuard::assertDenominator([], 50, 'probe'))
        ->toThrow(ExpectationFailedException::class);
});

it('every COVERED_PII_TABLES entry is actually referenced by the builder', function () {
    $source = file_get_contents(
        app_path('Services/User/DataExport/DataExportPayloadBuilder.php')
    );

    foreach (DataExportPayloadBuilder::COVERED_PII_TABLES as $table) {
        // core.users is reached via Eloquent (metadata/profile sections), not a
        // DB::table() call, so it is exempt from the source-reference check.
        if ($table === 'core.users') {
            continue;
        }

        // Pest's toContain() is variadic (mixed ...$needles) — it does not take a
        // trailing $message argument the way toBe()/toBeTrue() do. Passing one
        // silently turns it into a second required needle, which always fails.
        // Assert via str_contains() + toBeTrue() to get both the check and a
        // useful failure message.
        expect(str_contains($source, $table))->toBeTrue(
            "COVERED_PII_TABLES lists {$table} but the builder never references it — the entry is stale."
        );
    }
});

/*
| Erasure side of the same contract. Exporting a PII table is only half the
| obligation — Article 17 also requires it be erasable. core.early_access_signups
| shipped missing BOTH, so a guard that only checked export would still let the
| next email-keyed table through with no erasure path.
|
| Every COVERED_PII_TABLES entry must fall into exactly one of three buckets:
| an explicit purge (AccountDeletionService::PURGED_PII_TABLES), an FK cascade,
| or a documented deliberate retention.
*/

/** Erased by FK ON DELETE CASCADE — no explicit purge call needed. */
const CASCADE_ERASED = [
    'site.customers',   // customers_user_fk -> core.users ON DELETE CASCADE
    'site.enquiries',   // enquiries_professional_fk -> core.users ON DELETE CASCADE
    'site.workplaces',  // site_id PK -> site.sites ON DELETE CASCADE (site dies with the user)
    'core.pre_account_builds',  // user_id UNIQUE FK -> core.users ON DELETE CASCADE (1:1 origin record dies with the user)
    'content.sources',       // user_id FK -> core.users ON DELETE CASCADE
    'content.items',         // user_id FK -> core.users ON DELETE CASCADE
    'content.source_items',  // source_id FK -> content.sources -> core.users ON DELETE CASCADE
    'content.source_stats',  // source_id PK/FK -> content.sources -> core.users ON DELETE CASCADE (20260813110001)
    'content.f_text',        // item_id FK -> content.items -> core.users ON DELETE CASCADE
    'content.f_place',       // ditto
    'content.f_review',      // ditto
    'content.f_authored',    // ditto
    'content.f_channel',     // ditto
];

/*
| Deliberately RETAINED after account deletion — not an erasure gap.
|
| Both are append-only compliance trails in the `audit` schema, where
| app_backend holds only SELECT/INSERT (no DELETE grant), and both FKs are
| ON DELETE SET NULL rather than CASCADE. Their email columns survive the
| purge on purpose: you cannot evidence that an erasure request was honoured
| if you delete the record of honouring it.
|
| NOTE: purgeExportZips() deletes the R2 export FILES but deliberately leaves
| the audit ROW (and its recipient_email) in place — so these are retained,
| NOT cascade-erased. Recording that distinction is the point of this bucket:
| claiming they cascade would assert an erasure that does not happen.
*/
const RETAINED_BY_DESIGN = [
    'audit.data_export_audit',    // recipient_email — evidences DSAR fulfilment
    'audit.user_deletion_audit',  // professional_email_snapshot — IS the erasure record
];

it('every exported PII table has an erasure path or a documented retention', function () {
    $unaccounted = array_values(array_diff(
        DataExportPayloadBuilder::COVERED_PII_TABLES,
        AccountDeletionService::PURGED_PII_TABLES,
        CASCADE_ERASED,
        RETAINED_BY_DESIGN,
    ));

    expect($unaccounted)->toBe([], "Exported PII tables with no erasure path:\n  - ".implode("\n  - ", $unaccounted)."\n\nEvery table in COVERED_PII_TABLES must be one of:\n  - purged explicitly -> add a purge*() call in AccountDeletionService::purge() and list it in PURGED_PII_TABLES\n  - erased by FK cascade -> add to CASCADE_ERASED in this test, naming the cascading parent\n  - deliberately retained -> add to RETAINED_BY_DESIGN in this test with the legal/architectural reason");
});

/*
| PRIV-3: the erasure register above only asserts that a table is NAMED in
| PURGED_PII_TABLES. It cannot tell a real purge from a stale claim — the
| constant is a hand-maintained list of strings, so a purge method that is
| renamed, deleted, or quietly dropped out of purge()'s call list leaves the
| register still asserting coverage that no longer runs. That is the exact
| failure mode this pair of constants + the test below closes: every entry must
| name the method(s) that erase it, and each of those methods must still exist
| AND still be called from inside purge() itself.
*/

/**
 * PURGED_PII_TABLES entry => the AccountDeletionService method(s) that erase it.
 *
 * Kept set-equal to PURGED_PII_TABLES by assertion (i) below, so neither list
 * can grow or shrink without the other.
 *
 * @var array<string, list<string>>
 */
const PURGE_MECHANISMS = [
    'core.users' => ['forceDelete'],
    'core.early_access_signups' => ['purgeEarlyAccessSignup'],
    'core.feedback' => ['purgeFeedbackRows'],
    'notifications.email_subscriptions' => ['purgeGlobalEmailSubscriptions', 'purgeCrossTenantSubscriptions'],
    'analytics.item_views' => ['purgeItemViewsPii'],
    'analytics.action_events' => ['purgeActionEventsPii'],
    'ingest.effects' => ['purgeIngestEffects'],
    'moderation.case_signals' => ['purgeCaseSignalPii'],
    'moderation.evidence' => ['purgeReportedUserEvidencePii'],
];

it('every PURGED_PII_TABLES entry names a purge method that exists and is still called by purge()', function () {
    // (i) The two registers describe the same set of tables.
    $registered = AccountDeletionService::PURGED_PII_TABLES;
    $mechanised = array_keys(PURGE_MECHANISMS);
    sort($registered);
    sort($mechanised);

    expect($mechanised)->toBe($registered, "PURGE_MECHANISMS and AccountDeletionService::PURGED_PII_TABLES disagree.\n  PURGED_PII_TABLES: ".implode(', ', $registered)."\n  PURGE_MECHANISMS:  ".implode(', ', $mechanised)."\n\nAdd the missing table => [method] line to PURGE_MECHANISMS in tests/Feature/Security/DataExportCoverageTest.php, or remove the stale one. Every erasure claim must name the code that honours it.");

    // Read purge()'s own body once — assertion (iii) checks each method is
    // called from THERE, not merely defined somewhere on the class.
    $purge = new ReflectionMethod(AccountDeletionService::class, 'purge');
    $lines = file($purge->getFileName());
    $purgeBody = implode('', array_slice(
        $lines,
        $purge->getStartLine() - 1,
        $purge->getEndLine() - $purge->getStartLine() + 1,
    ));

    $reflection = new ReflectionClass(AccountDeletionService::class);
    $missingMethods = [];
    $uncalled = [];

    foreach (PURGE_MECHANISMS as $table => $methods) {
        foreach ($methods as $method) {
            // (ii) core.users is the deletion subject itself — erased by
            // $professional->forceDelete(), an Eloquent call on the model rather
            // than a method on this service. Exempt from the hasMethod() check
            // for the same reason it is exempt from the builder-reference check
            // above: it is reached through Eloquent, not through a named local.
            if ($table === 'core.users') {
                expect(str_contains($purgeBody, '->forceDelete('))->toBeTrue(
                    'PURGE_MECHANISMS claims core.users is erased by forceDelete, but AccountDeletionService::purge() no longer calls ->forceDelete(). Restore the call, or update the core.users line in PURGE_MECHANISMS in tests/Feature/Security/DataExportCoverageTest.php.'
                );

                continue;
            }

            if (! $reflection->hasMethod($method)) {
                $missingMethods[] = "{$table} => {$method}()";

                continue;
            }

            // (iii) The one with teeth: defined-but-never-called is still a gap.
            if (! str_contains($purgeBody, '$this->'.$method.'(')) {
                $uncalled[] = "{$table} => {$method}()";
            }
        }
    }

    expect($missingMethods)->toBe([], "PURGE_MECHANISMS names methods that do not exist on AccountDeletionService:\n  - ".implode("\n  - ", $missingMethods)."\n\nThe method was renamed or removed. Fix the name in PURGE_MECHANISMS in tests/Feature/Security/DataExportCoverageTest.php, or restore the method — but do not just delete the line: the table is still in PURGED_PII_TABLES, so something must erase it.");

    expect($uncalled)->toBe([], "PURGE_MECHANISMS names methods that exist but are NEVER CALLED from AccountDeletionService::purge():\n  - ".implode("\n  - ", $uncalled)."\n\nThe method is dead code and the table's erasure claim in PURGED_PII_TABLES is a lie. Either add the \$this->method(...) call back into purge(), or remove the table from PURGED_PII_TABLES and account for it in CASCADE_ERASED / RETAINED_BY_DESIGN in this file.");
});

it('every EXPORT_EXEMPT entry resolves to a real model class', function () {
    $unresolved = array_values(array_filter(
        EXPORT_EXEMPT,
        fn (string $class) => ! class_exists($class),
    ));

    expect($unresolved)->toBe([], "EXPORT_EXEMPT entries that do not resolve to an existing class:\n  - ".implode("\n  - ", $unresolved));
});

/*
|--------------------------------------------------------------------------
| SELECT * guard (T1) + column-existence guard (T2)
|--------------------------------------------------------------------------
| gdpr-deletion-export/PRIV-2: nine sections queried DataExportPayloadBuilder
| tables with no explicit ->select() (bare SELECT *), so a future migration
| adding an internal/staff-only column would silently start appearing in
| every subsequent export. T1 below closes that.
|
| T2 is the more important half. On 2026-07-19 streamMedia() shipped
| SELECTing columns that don't exist in Postgres (site_media.width/height)
| and it passed CI green, because SQLite silently reinterprets an unknown
| double-quoted identifier as a STRING LITERAL rather than erroring — no
| SQLite-backed assertion can catch a bad column name. T2 sidesteps that
| entirely: it never touches a database. It parses supabase/migrations/*.sql
| directly and replays CREATE TABLE / ADD COLUMN / DROP COLUMN / RENAME
| COLUMN / RENAME TO / SET SCHEMA in filename order to derive each table's
| REAL current column set, then checks every ->select([...]) column name
| against it.
*/

/**
 * Tables the SELECT * guard (T1) does not require an explicit ->select()
 * for. Each entry needs a written justification — this is a deliberate,
 * reviewed exception, not a way to silence the guard.
 */
const SELECT_STAR_EXEMPT = [
    // No PII by construction; design-token columns only (colors, spacing,
    // typography, motion — see site.design_kits DDL). 27 churn migrations
    // across the design-kit var system make a hand-maintained allowlist a
    // stale-name hazard far more likely than a real leak here.
    'site.design_kits' => 'No PII by construction; design-token columns only. 27 churn migrations make a hand-maintained allowlist a stale-name hazard.',
];

/**
 * Splits a PHP statement into one segment per ->table() call, each running
 * from that call to the start of the next ->table() call in the same
 * statement (or to the end of the statement). T1/T2 previously searched the
 * WHOLE statement for ->select()/column matches, so a statement containing
 * two ->table() calls (e.g. inside a closure or array literal — statements
 * are split on ';', not on ->table(), so this is possible) would let an
 * unselected second table silently inherit credit from the first table's
 * ->select(). Scoping to each call's own segment closes that.
 *
 * Known limit: segmentation is purely offset-ordered, so a table whose own
 * ->select() sits textually AFTER a nested ->table() (e.g. an outer query whose
 * where(function () { ... ->table(...) ... }) closure precedes its ->select())
 * would attribute that ->select() to the nested table's segment. No such shape
 * exists in DataExportPayloadBuilder today — the one closure there
 * (streamEmailSubscriptions) contains no nested ->table() and the outer
 * ->select() precedes it. Revisit if that pattern is ever introduced.
 *
 * @return array<int, array{0: string, 1: string}> list of [table, segment]
 */
function tableCallSegments(string $statement): array
{
    return SelectStatementParser::tableCallSegments($statement);
}

it('every ->table() call in the export builder chains an explicit ->select() (or ->value()), unless exempted', function () {
    $source = (string) file_get_contents(app_path('Services/User/DataExport/DataExportPayloadBuilder.php'));

    // Chains span multiple lines — match per PHP statement (split on ';'),
    // not per line, so a ->select() on a later line than ->table() is still
    // associated with it correctly.
    $statements = explode(';', $source);

    $violations = [];

    foreach ($statements as $statement) {
        // Scoped per ->table() call (not the whole statement) — a statement
        // with two ->table() calls (e.g. inside a closure/array literal)
        // must not let an unselected second table inherit credit from the
        // first table's ->select().
        foreach (tableCallSegments($statement) as [$table, $segment]) {
            if (array_key_exists($table, SELECT_STAR_EXEMPT)) {
                continue;
            }

            $hasSelect = str_contains($segment, '->select(');
            // ->value('col') compiles to `select "col"` — an explicit,
            // single-column select. Safe; accepted as satisfying the guard.
            $hasValue = str_contains($segment, '->value(');

            if (! $hasSelect && ! $hasValue) {
                $violations[] = $table;
            }
        }
    }

    $violations = array_values(array_unique($violations));

    expect($violations)->toBe([], "DataExportPayloadBuilder queries these tables with no explicit ->select() (bare SELECT *) and no SELECT_STAR_EXEMPT entry:\n  - ".implode("\n  - ", $violations)."\n\nAdd an explicit ->select([...]) allowlist, or add the table to SELECT_STAR_EXEMPT with a written justification.");
});

it('every explicit select() column exists in the real, migration-derived schema', function () {
    $source = (string) file_get_contents(app_path('Services/User/DataExport/DataExportPayloadBuilder.php'));
    $realSchema = MigrationColumnReplay::tables();

    $statements = explode(';', $source);
    $violations = [];

    foreach ($statements as $statement) {
        // Scoped per ->table() call, same reasoning as T1 above — a second
        // ->table()/->select() pair later in the statement must be checked
        // against its OWN select list, not skipped because an earlier
        // ->table() call's select already matched.
        foreach (tableCallSegments($statement) as [$primaryTable, $segment]) {
            // No explicit select for this table call — T1's concern, not T2's.
            if (! preg_match('/->select\(\s*\[(.*?)\]\s*\)/s', $segment, $selectMatch)) {
                continue;
            }

            preg_match_all('/[\'"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'"]/', $selectMatch[1], $colMatches);

            foreach ($colMatches[1] as $col) {
                if (str_contains($col, '.')) {
                    // Qualified column from a joined table (e.g.
                    // 'site.site_subdomain_aliases.id') — validate against the
                    // table it actually names, not the statement's primary FROM.
                    $lastDot = strrpos($col, '.');
                    $tableRef = substr($col, 0, $lastDot);
                    $colName = substr($col, $lastDot + 1);
                } else {
                    $tableRef = $primaryTable;
                    $colName = $col;
                }

                $realCols = $realSchema[$tableRef] ?? null;

                if ($realCols === null) {
                    $violations[] = "{$tableRef}.{$colName} — table not found in migration replay (typo in ->table(), or the migration parser doesn't recognise how this table is declared)";

                    continue;
                }

                if (! isset($realCols[$colName])) {
                    $known = implode(', ', array_keys($realCols));
                    $violations[] = "{$tableRef}.{$colName} — not a real column. Known columns: {$known}";
                }
            }
        }
    }

    expect($violations)->toBe([], "DataExportPayloadBuilder selects columns that do not exist in the migration-derived schema:\n  - ".implode("\n  - ", $violations)."\n\nVerify against supabase/migrations/*.sql. This is exactly the class of bug that broke the GDPR export in production on 2026-07-19 (streamMedia queried nonexistent columns; SQLite masked it because an unknown quoted identifier is silently treated as a string literal there, not an error).");
});
