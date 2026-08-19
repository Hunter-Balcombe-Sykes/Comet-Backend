<?php

use App\Catalog\CompiledCatalog;
use App\Catalog\DetectorSuspensions;
use Illuminate\Support\Facades\DB;

// The operator surface for the kill-switch. Artisan rather than HTTP on
// purpose: this is the same access boundary `catalog:sync` already sits behind,
// and an HTTP surface would need a policy, an AAL2 gate and a staff route
// before it did anything a command does in one line.

beforeEach(function () {
    setupCatalogRuntimeTables();
});

function anyRealDetectorId(): string
{
    return array_key_first(CompiledCatalog::detectors());
}

it('suspends a detector for a bounded window', function () {
    $id = anyRealDetectorId();

    $this->artisan('catalog:suspend-detector', [
        'detector' => $id,
        '--reason' => 'placing the wrong brand',
        '--hours' => 6,
        '--by' => 'staff:josh',
    ])->assertSuccessful();

    $row = DB::connection('pgsql')->table('catalog.detector_suspensions')->first();
    expect($row->detector_id)->toBe($id);
    expect($row->reason)->toBe('placing the wrong brand');
    expect($row->set_by)->toBe('staff:josh');
    expect(Carbon\Carbon::parse($row->expires_at)->isAfter(now()->addHours(5)))->toBeTrue();
    expect(Carbon\Carbon::parse($row->expires_at)->isBefore(now()->addHours(7)))->toBeTrue();
});

it('refuses a detector id the catalog does not contain', function () {
    // The whole failure mode this guard exists for: a mistyped id writes a row
    // that suspends nothing, and the operator walks away believing the
    // detector is off while it keeps placing links.
    $this->artisan('catalog:suspend-detector', [
        'detector' => 'no-such-detector',
        '--reason' => 'typo',
    ])->assertFailed();

    expect(DB::connection('pgsql')->table('catalog.detector_suspensions')->count())->toBe(0);
});

it('requires a reason', function () {
    // A kill-switch with no stated reason is unreviewable — the next person
    // cannot tell a deliberate suspension from a forgotten one.
    $this->artisan('catalog:suspend-detector', ['detector' => anyRealDetectorId()])
        ->assertFailed();

    expect(DB::connection('pgsql')->table('catalog.detector_suspensions')->count())->toBe(0);
});

it('releases a suspension', function () {
    $id = anyRealDetectorId();
    app(DetectorSuspensions::class)->suspend($id, 'temporary', 'staff:josh', now()->addDay());

    $this->artisan('catalog:suspend-detector', ['detector' => $id, '--release' => true])
        ->assertSuccessful();

    expect(app(DetectorSuspensions::class)->active())->toBe([]);
});

it('reports when a release found nothing to lift', function () {
    // Distinguishing "released" from "there was nothing there" matters during
    // an incident: the same command output otherwise reads as success whether
    // or not the suspension the operator thought existed actually did.
    $this->artisan('catalog:suspend-detector', ['detector' => anyRealDetectorId(), '--release' => true])
        ->assertFailed();
});

it('lists the live suspensions', function () {
    $id = anyRealDetectorId();
    app(DetectorSuspensions::class)->suspend($id, 'placing the wrong brand', 'staff:josh', now()->addDay());

    $this->artisan('catalog:suspend-detector', ['--list' => true])
        ->expectsOutputToContain($id)
        ->assertSuccessful();
});

it('caps how long a single suspension can run', function () {
    // A kill-switch is a temporary measure; an unbounded one is a fork of the
    // catalog that nobody remembers making. expires_at being NOT NULL in the
    // DDL says the same thing — this enforces a ceiling on top of it.
    $this->artisan('catalog:suspend-detector', [
        'detector' => anyRealDetectorId(),
        '--reason' => 'forever please',
        '--hours' => 100000,
    ])->assertFailed();

    expect(DB::connection('pgsql')->table('catalog.detector_suspensions')->count())->toBe(0);
});
