<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\Staff\StaffAuditEntry;
use App\Services\User\AccountDeletionService;
use App\Services\User\DataExport\DataExportPayloadBuilder;
use Symfony\Component\Finder\Finder;

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
 */
const PII_MARKERS = [
    'email', 'email_lc', 'consent_ip_hash',
    'primary_email', 'public_contact_email', 'reply_email',
    'contact_email', 'recipient_email',
    'professional_email_snapshot',
    'staff_email_snapshot', 'impersonator_email_snapshot',
];

it('every PII-bearing model is covered by the data export', function () {
    $modelFiles = (new Finder)
        ->files()
        ->in(app_path('Models'))
        ->name('*.php')
        ->notName('BaseModel.php')
        ->notPath('Views')
        ->getIterator();

    $missing = [];

    foreach ($modelFiles as $file) {
        $class = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], $file->getRealPath());
        if (! class_exists($class)) {
            continue;
        }

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

        if (in_array($class, EXPORT_EXEMPT, true)) {
            continue;
        }

        if (! in_array($model->getTable(), DataExportPayloadBuilder::COVERED_PII_TABLES, true)) {
            $missing[] = $class.' (table: '.$model->getTable().')';
        }
    }

    expect($missing)->toBe([], "PII-bearing models missing from the data export:\n  - ".implode("\n  - ", $missing)."\n\nEither add the table to DataExportPayloadBuilder::COVERED_PII_TABLES (and wire a section in sectionDescriptors()) or add the model to EXPORT_EXEMPT in this test with a justification.");
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

it('every EXPORT_EXEMPT entry resolves to a real model class', function () {
    $unresolved = array_values(array_filter(
        EXPORT_EXEMPT,
        fn (string $class) => ! class_exists($class),
    ));

    expect($unresolved)->toBe([], "EXPORT_EXEMPT entries that do not resolve to an existing class:\n  - ".implode("\n  - ", $unresolved));
});
