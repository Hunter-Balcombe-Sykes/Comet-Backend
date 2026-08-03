<?php

// Plan §28.17 audit DATA-4 sweep test.
//
// Discovers every model under app/Models/ that uses the SoftDeletes trait via
// reflection and asserts each one is either:
//   1. listed in PurgeSoftDeleted::PURGE_HANDLED (retention-loop purge),
//   2. listed in PurgeSoftDeleted::PURGE_EXEMPT with a justification, or
//   3. listed in PurgeSoftDeleted::PURGE_OTHER_PATH with a justification.
//
// Adding a new SoftDeletes-using model and forgetting to wire purge is a
// silent storage-growth bug; this test fails the build on first run after
// such an omission.
//
// Note: audit DATA-4 originally claimed `Block` uses SoftDeletes — verification
// showed Block does NOT use the trait, so the audit was outdated on that
// specific finding. The sweep test still has value as forward protection.

use App\Console\Commands\PurgeSoftDeleted;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\Architecture\ModelSweep;
use Tests\Support\Architecture\SweepGuard;

function discoverSoftDeleteModels(): array
{
    // Namespace derivation is shared with PolicyCoverageTest and
    // DataExportCoverageTest via ModelSweep — this file used to hardcode
    // 'App\Models\', so a PSR-4 move could break one sweep and not the others
    // (COV-GUARD-4).
    $models = ModelSweep::resolvedModelClasses();
    SweepGuard::assertDenominator($models, 50, 'app/Models soft-delete sweep');

    $softDeleting = [];

    foreach ($models as $class) {
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $softDeleting[] = $class;
        }
    }

    sort($softDeleting);

    return $softDeleting;
}

it('every SoftDeletes-using model is covered by PurgeSoftDeleted or has a documented exemption', function () {
    $covered = array_merge(
        PurgeSoftDeleted::PURGE_HANDLED,
        array_keys(PurgeSoftDeleted::PURGE_EXEMPT),
        array_keys(PurgeSoftDeleted::PURGE_OTHER_PATH),
    );

    $found = discoverSoftDeleteModels();
    SweepGuard::assertDenominator($found, 8, 'SoftDeletes-using models');
    $uncovered = array_diff($found, $covered);

    expect($uncovered)->toBe(
        [],
        'These models use SoftDeletes but are not listed in PurgeSoftDeleted::PURGE_HANDLED / PURGE_EXEMPT / PURGE_OTHER_PATH. '
        ."Add them to the appropriate list (with a justification for the exempt ones) so soft-deleted rows don't accumulate forever:\n"
        .implode("\n", $uncovered)
    );
});

it('every entry in PURGE_HANDLED actually uses SoftDeletes', function () {
    foreach (PurgeSoftDeleted::PURGE_HANDLED as $class) {
        expect(class_exists($class))->toBeTrue("PURGE_HANDLED references missing class: {$class}");
        $uses = class_uses_recursive($class);
        expect(in_array(SoftDeletes::class, $uses, true))
            ->toBeTrue("PURGE_HANDLED entry {$class} does not use SoftDeletes — its forceDelete will be a no-op.");
    }
});

it('every PURGE_EXEMPT entry carries a non-empty justification', function () {
    foreach (PurgeSoftDeleted::PURGE_EXEMPT as $class => $reason) {
        expect(trim($reason))->not->toBe('', "Empty justification for PURGE_EXEMPT entry {$class}");
    }
});

// Positive control (COV-GUARD-4): the cheap inline form — an empty discovery
// set must redden the floor, proving the guard itself can fail.
it('proves the denominator guard can fail: an empty set is rejected', function () {
    expect(fn () => SweepGuard::assertDenominator([], 8, 'probe'))
        ->toThrow(ExpectationFailedException::class);
});
