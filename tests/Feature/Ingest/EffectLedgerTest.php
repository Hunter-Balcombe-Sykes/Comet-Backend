<?php

// Tier-S runtime property tests for App\Ingest\Runtime\EffectLedger (plan
// §4/§22, C6): charge-once for billed effects. DB-backed via the SQLite
// mirror (setupIngestTables(), tests/Pest.php).

use App\Ingest\Runtime\EffectLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupIngestTables();
});

it('runs an effect exactly once for a digest — a second call with the same digest returns cached without re-running it', function () {
    $ledger = new EffectLedger;
    $digest = EffectLedger::digestFor('places_fetch', ['place_id' => 'abc']);
    $calls = 0;

    $first = $ledger->once($digest, 'http', function () use (&$calls) {
        $calls++;

        return 'result';
    });
    expect($first)->toBe(['status' => 'ok', 'result' => 'result', 'cached' => false]);
    expect($calls)->toBe(1);

    $second = $ledger->once($digest, 'http', function () use (&$calls) {
        $calls++;

        return 'result-again';
    });
    // The replay carries the ORIGINAL result: a cross-stream or retry caller
    // gets the data it paid for, not a data-less "ok" that folds into
    // Unavailable downstream.
    expect($second)->toBe(['status' => 'ok', 'result' => 'result', 'cached' => true]);
    expect($calls)->toBe(1); // the closure must NOT run again
});

it('refuses (never ok-with-null) when a settled row predates result persistence', function () {
    $digest = EffectLedger::digestFor('places_fetch', ['place_id' => 'legacy-row']);
    // A row settled by the pre-replay ledger: ok, but no stored result.
    DB::table('ingest.effects')->insert([
        'digest' => $digest, 'kind' => 'http', 'claimed_at' => now(),
        'settled_at' => now(), 'status' => 'ok', 'meta' => json_encode(['summary' => 'string(6)']),
    ]);

    $ledger = new EffectLedger;
    $calls = 0;
    $verdict = $ledger->once($digest, 'http', function () use (&$calls) {
        $calls++;

        return 'nope';
    });

    // Fail closed: an "ok" whose data is gone must not masquerade as success —
    // callers fold refused into Unavailable, which is honest.
    expect($verdict)->toBe(['status' => 'refused', 'result' => null, 'cached' => true]);
    expect($calls)->toBe(0);
});

it('omits an oversized result from the ledger and refuses its replay rather than returning ok-with-null', function () {
    $ledger = new EffectLedger;
    $digest = EffectLedger::digestFor('actor_run', ['actor' => 'huge-output']);
    $huge = ['blob' => str_repeat('x', 1_100_000)];

    $first = $ledger->once($digest, 'actor', fn () => $huge);
    // The live call still returns the data — only the REPLAY is lost.
    expect($first['status'])->toBe('ok');
    expect($first['result'])->toBe($huge);

    $verdict = $ledger->once($digest, 'actor', fn () => 'nope');
    expect($verdict)->toBe(['status' => 'refused', 'result' => null, 'cached' => true]);
});

it('scopes a freshness-windowed digest to its bucket: stable within the window, different in the next one', function () {
    Carbon::setTestNow('2026-07-28 10:00:00');
    $week = 604800;

    $d1 = EffectLedger::digestFor('places_fetch', ['place_id' => 'abc'], $week);
    Carbon::setTestNow('2026-07-28 11:00:00'); // same bucket
    $d2 = EffectLedger::digestFor('places_fetch', ['place_id' => 'abc'], $week);
    expect($d2)->toBe($d1);

    Carbon::setTestNow('2026-08-11 10:00:00'); // two windows on
    $d3 = EffectLedger::digestFor('places_fetch', ['place_id' => 'abc'], $week);
    expect($d3)->not->toBe($d1);

    // No window = the historical forever-digest, unchanged.
    $d4 = EffectLedger::digestFor('places_fetch', ['place_id' => 'abc']);
    expect($d4)->toBe(EffectLedger::digestFor('places_fetch', ['place_id' => 'abc']));
    expect($d4)->not->toBe($d1);

    Carbon::setTestNow();
});

it('settles a failing effect as failed and rethrows, then refuses to retry a known failure on a later call', function () {
    $ledger = new EffectLedger;
    $digest = EffectLedger::digestFor('places_fetch', ['place_id' => 'boom']);

    expect(fn () => $ledger->once($digest, 'http', function () {
        throw new RuntimeException('vendor exploded');
    }))->toThrow(RuntimeException::class, 'vendor exploded');

    $row = DB::table('ingest.effects')->where('digest', $digest)->first();
    expect($row->status)->toBe('failed');
    expect($row->settled_at)->not->toBeNull();

    $calls = 0;
    $second = $ledger->once($digest, 'http', function () use (&$calls) {
        $calls++;

        return 'should not run';
    });
    expect($second)->toBe(['status' => 'failed', 'result' => null, 'cached' => true]);
    expect($calls)->toBe(0); // a known failure is never retried automatically
});

it('refuses rather than running the closure when a claim is fresh and unsettled', function () {
    $digest = EffectLedger::digestFor('places_fetch', ['place_id' => 'in-flight']);
    // Simulate another worker holding this exact effect mid-flight.
    DB::table('ingest.effects')->insert([
        'digest' => $digest, 'kind' => 'http', 'claimed_at' => now(), 'status' => 'claimed', 'meta' => json_encode([]),
    ]);

    $ledger = new EffectLedger;
    $calls = 0;
    $result = $ledger->once($digest, 'http', function () use (&$calls) {
        $calls++;

        return 'nope';
    });

    expect($result)->toBe(['status' => 'refused', 'result' => null, 'cached' => true]);
    expect($calls)->toBe(0);
});

it('marks a long-abandoned claim as abandoned and still refuses to run the closure — we cannot know if the vendor charged', function () {
    $digest = EffectLedger::digestFor('places_fetch', ['place_id' => 'dead-worker']);
    // Just past the 900s abandon window: a process died mid-effect and left this claim behind.
    DB::table('ingest.effects')->insert([
        'digest' => $digest, 'kind' => 'http',
        'claimed_at' => now()->subSeconds(901)->toDateTimeString(),
        'status' => 'claimed', 'meta' => json_encode([]),
    ]);

    $ledger = new EffectLedger;
    $calls = 0;
    $result = $ledger->once($digest, 'http', function () use (&$calls) {
        $calls++;

        return 'nope';
    });

    expect($result)->toBe(['status' => 'abandoned', 'result' => null, 'cached' => true]);
    expect($calls)->toBe(0);

    $row = DB::table('ingest.effects')->where('digest', $digest)->first();
    expect($row->status)->toBe('abandoned');
});

it('computes a digest that is stable regardless of request key order, but differs for a different request', function () {
    $d1 = EffectLedger::digestFor('places_fetch', ['place_id' => 'abc', 'fields' => ['name', 'photos']]);
    $d2 = EffectLedger::digestFor('places_fetch', ['fields' => ['name', 'photos'], 'place_id' => 'abc']);
    expect($d1)->toBe($d2);

    $d3 = EffectLedger::digestFor('places_fetch', ['place_id' => 'xyz', 'fields' => ['name', 'photos']]);
    expect($d3)->not->toBe($d1);
});
