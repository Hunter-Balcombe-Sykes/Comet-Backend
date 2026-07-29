<?php

// Feature tests for `ingest:effects` (LIFE-18): the operator entry point for
// EffectLedger's charge-once ledger — read-only listing by default, --resolve to
// sweep long-dead claims to 'abandoned' (#WHK-4), --settle to deliberately unblock
// one digest. DB-backed via the SQLite mirror (setupIngestTables(), tests/Pest.php).

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupIngestTables();
});

/** ingest.effects row, defaulted to a fresh unsettled claim. */
function seedEffectRow(array $overrides = []): string
{
    $digest = $overrides['digest'] ?? hash('sha256', (string) Str::uuid());

    DB::table('ingest.effects')->insert(array_merge([
        'digest' => $digest,
        'run_id' => null,
        'source_id' => null,
        'kind' => 'http',
        'cost_tag' => null,
        'cost_units' => 1,
        'claimed_at' => now()->toDateTimeString(),
        'settled_at' => null,
        'status' => 'claimed',
        'meta' => json_encode([]),
    ], $overrides));

    return $digest;
}

it('--resolve abandons only claims older than the window, leaving fresh and settled rows alone', function () {
    $old = seedEffectRow(['claimed_at' => now()->subSeconds(901)->toDateTimeString()]);
    $fresh = seedEffectRow(['claimed_at' => now()->subSeconds(10)->toDateTimeString()]);
    $settled = seedEffectRow([
        'status' => 'ok',
        'settled_at' => now()->toDateTimeString(),
        'meta' => json_encode(['result' => 'x']),
    ]);

    $this->artisan('ingest:effects', ['--resolve' => true])->assertExitCode(0);

    expect(DB::table('ingest.effects')->where('digest', $old)->value('status'))->toBe('abandoned');
    expect(DB::table('ingest.effects')->where('digest', $fresh)->value('status'))->toBe('claimed');
    expect(DB::table('ingest.effects')->where('digest', $settled)->value('status'))->toBe('ok');

    expect(DB::table('ingest.anomalies')->where('kind', 'effect_abandoned')->count())->toBe(1);
});

it('--resolve is idempotent — a second run files no new anomaly and reports only once', function () {
    Exceptions::fake();
    $digest = seedEffectRow(['claimed_at' => now()->subSeconds(901)->toDateTimeString()]);

    $this->artisan('ingest:effects', ['--resolve' => true])->assertExitCode(0);
    $this->artisan('ingest:effects', ['--resolve' => true])->assertExitCode(0);

    expect(DB::table('ingest.anomalies')->where('kind', 'effect_abandoned')->count())->toBe(1);
    Exceptions::assertReportedCount(1);
});

it('--resolve never sets settled_at and never deletes — the charge-once contract survives the sweep', function () {
    $digest = seedEffectRow(['claimed_at' => now()->subSeconds(901)->toDateTimeString()]);

    $this->artisan('ingest:effects', ['--resolve' => true])->assertExitCode(0);

    $row = DB::table('ingest.effects')->where('digest', $digest)->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('abandoned');
    expect($row->settled_at)->toBeNull();
});

it('--settle marks the row terminal and resolves the open anomaly it created', function () {
    $digest = seedEffectRow(['claimed_at' => now()->subSeconds(901)->toDateTimeString()]);
    $this->artisan('ingest:effects', ['--resolve' => true])->assertExitCode(0);

    $this->artisan('ingest:effects', ['--settle' => $digest, '--as' => 'failed'])->assertExitCode(0);

    $row = DB::table('ingest.effects')->where('digest', $digest)->first();
    expect($row->status)->toBe('failed');
    expect($row->settled_at)->not->toBeNull();

    $anomaly = DB::table('ingest.anomalies')->where('kind', 'effect_abandoned')->first();
    expect($anomaly->resolved_at)->not->toBeNull();
    expect($anomaly->resolved_by)->not->toBeNull();
    expect($anomaly->resolution)->not->toBeNull();
});

it('--older-than overrides the default abandon window', function () {
    $digest = seedEffectRow(['claimed_at' => now()->subSeconds(120)->toDateTimeString()]);

    // Default 900s window leaves a 120s-old claim alone.
    $this->artisan('ingest:effects', ['--resolve' => true])->assertExitCode(0);
    expect(DB::table('ingest.effects')->where('digest', $digest)->value('status'))->toBe('claimed');

    $this->artisan('ingest:effects', ['--resolve' => true, '--older-than' => 60])->assertExitCode(0);
    expect(DB::table('ingest.effects')->where('digest', $digest)->value('status'))->toBe('abandoned');
});

it('--settle on an unknown digest fails', function () {
    $this->artisan('ingest:effects', ['--settle' => 'no-such-digest'])->assertExitCode(1);
});

it('--settle on an already-settled digest fails', function () {
    $digest = seedEffectRow(['status' => 'ok', 'settled_at' => now()->toDateTimeString(), 'meta' => json_encode(['result' => 'x'])]);

    $this->artisan('ingest:effects', ['--settle' => $digest])->assertExitCode(1);
});

it('the default (no flags) invocation lists unsettled claims without mutating them', function () {
    $digest = seedEffectRow(['claimed_at' => now()->subSeconds(901)->toDateTimeString()]);

    $this->artisan('ingest:effects')->assertExitCode(0);

    expect(DB::table('ingest.effects')->where('digest', $digest)->value('status'))->toBe('claimed');
});
