<?php

// Feature tests for `ingest:anomalies` (LIFE-20): alerts on ingest.anomalies
// rows that are severity='critical', unresolved, and old enough that
// self-healing has had a fair chance — with a `detail.alerted_at` stamp so a
// persisting anomaly pages ONCE, not every sweep. DB-backed via the SQLite
// mirror (setupIngestTables(), tests/Pest.php).

use App\Exceptions\Ingest\AbandonedEffectException;
use App\Exceptions\Ingest\UnresolvedCriticalAnomalyException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupIngestTables();
    config()->set('partna.ingest.anomalies.critical_alert_after_minutes', 120);
});

/** ingest.anomalies row, defaulted to an aged, unresolved, unalerted critical row. */
function seedAnomalyRow(array $overrides = []): string
{
    $id = $overrides['id'] ?? (string) Str::uuid();

    DB::table('ingest.anomalies')->insert(array_merge([
        'id' => $id,
        'stream_id' => null,
        'source_id' => null,
        'run_id' => null,
        'kind' => 'delete_guard',
        'severity' => 'critical',
        'summary' => 'test anomaly',
        'detail' => json_encode([]),
        'detected_at' => now()->subMinutes(200)->toDateTimeString(),
        'resolved_at' => null,
    ], $overrides));

    return $id;
}

it('an aged unresolved critical anomaly reports once and exits 0', function () {
    Exceptions::fake();
    seedAnomalyRow();

    $this->artisan('ingest:anomalies')->assertExitCode(0);

    Exceptions::assertReported(fn (UnresolvedCriticalAnomalyException $e) => $e->count === 1);
});

it('a second run does not re-page — the alerted_at stamp dedupes', function () {
    Exceptions::fake();
    $id = seedAnomalyRow();

    $this->artisan('ingest:anomalies')->assertExitCode(0);
    $this->artisan('ingest:anomalies')->assertExitCode(0);

    Exceptions::assertReportedCount(1);

    $detail = json_decode((string) DB::table('ingest.anomalies')->where('id', $id)->value('detail'), true);
    expect($detail)->toHaveKey('alerted_at');
});

it('an effect_abandoned row filed by Unit A (EffectLedger::markAbandoned) is never swept', function () {
    Exceptions::fake();

    // Real charge-once ledger flow: seed a claimed-but-unsettled effect old
    // enough to abandon, then run the sweep that transitions it — this is
    // the SAME path markAbandoned() runs on, so its report() AND its
    // pre-stamped alerted_at are both real, not simulated.
    $digest = hash('sha256', (string) Str::uuid());
    DB::table('ingest.effects')->insert([
        'digest' => $digest,
        'run_id' => null,
        'source_id' => null,
        'kind' => 'http',
        'cost_tag' => null,
        'cost_units' => 1,
        'claimed_at' => now()->subSeconds(901)->toDateTimeString(),
        'settled_at' => null,
        'status' => 'claimed',
        'meta' => json_encode([]),
    ]);
    $this->artisan('ingest:effects', ['--resolve' => true])->assertExitCode(0);

    // Backdate detection so it clears ingest:anomalies' own age gate too —
    // otherwise the age gate alone (not the alerted_at stamp) would explain
    // a clean sweep, and this test would prove nothing.
    DB::table('ingest.anomalies')
        ->where('kind', 'effect_abandoned')
        ->update(['detected_at' => now()->subMinutes(200)->toDateTimeString()]);

    Exceptions::assertReportedCount(1); // the AbandonedEffectException from Unit A, at detection time
    Exceptions::assertReported(AbandonedEffectException::class);

    $this->artisan('ingest:anomalies')->assertExitCode(0);

    // No SECOND report added — the effect_abandoned row arrived pre-stamped.
    Exceptions::assertReportedCount(1);
    Exceptions::assertNotReported(UnresolvedCriticalAnomalyException::class);
});

it('a warning-severity anomaly is ignored', function () {
    Exceptions::fake();
    seedAnomalyRow(['severity' => 'warning']);

    $this->artisan('ingest:anomalies')->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('a critical anomaly inside the age gate is ignored', function () {
    Exceptions::fake();
    seedAnomalyRow(['detected_at' => now()->subMinutes(10)->toDateTimeString()]);

    $this->artisan('ingest:anomalies')->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('a resolved critical anomaly is ignored', function () {
    Exceptions::fake();
    seedAnomalyRow(['resolved_at' => now()->toDateTimeString()]);

    $this->artisan('ingest:anomalies')->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('50 aged critical rows produce ONE aggregate report — the anti-fatigue invariant', function () {
    Exceptions::fake();
    foreach (range(1, 50) as $i) {
        seedAnomalyRow();
    }

    $this->artisan('ingest:anomalies')->assertExitCode(0);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (UnresolvedCriticalAnomalyException $e) => $e->count === 50);
});

it('--list mutates nothing and never reports', function () {
    Exceptions::fake();
    $id = seedAnomalyRow();

    $this->artisan('ingest:anomalies', ['--list' => true])->assertExitCode(0);

    Exceptions::assertNothingReported();
    $detail = json_decode((string) DB::table('ingest.anomalies')->where('id', $id)->value('detail'), true);
    expect($detail)->not->toHaveKey('alerted_at');
});

it('--older-than overrides the default age gate', function () {
    Exceptions::fake();
    seedAnomalyRow(['detected_at' => now()->subMinutes(10)->toDateTimeString()]);

    // Default 120min gate leaves a 10min-old row alone.
    $this->artisan('ingest:anomalies')->assertExitCode(0);
    Exceptions::assertNothingReported();

    $this->artisan('ingest:anomalies', ['--older-than' => 5])->assertExitCode(0);
    Exceptions::assertReportedCount(1);
});
