<?php

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
    // (empty — every PII-bearing model is currently exported)
];

/** Column names that mark a model as carrying direct PII. */
const PII_MARKERS = ['email', 'email_lc', 'consent_ip_hash'];

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

it('every EXPORT_EXEMPT entry resolves to a real model class', function () {
    $unresolved = array_values(array_filter(
        EXPORT_EXEMPT,
        fn (string $class) => ! class_exists($class),
    ));

    expect($unresolved)->toBe([], "EXPORT_EXEMPT entries that do not resolve to an existing class:\n  - ".implode("\n  - ", $unresolved));
});
