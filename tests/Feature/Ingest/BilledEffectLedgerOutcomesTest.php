<?php

use App\Ingest\Runtime\EffectLedger;
use App\Ingest\Runtime\EffectNoAnswer;
use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\EffectRefused;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupIngestTables();
});

it('removes the claim and rethrows when a driver refuses on budget', function () {
    $ledger = new EffectLedger;

    expect(fn () => $ledger->once(
        digest: 'budget-refused-digest',
        kind: 'api',
        effect: fn () => throw new EffectNotAttempted('places daily cap reached'),
        costTag: 'places.details',
    ))->toThrow(EffectNotAttempted::class);

    // No lingering row: the digest is stable for the whole freshness window, so a
    // settled row here would lock this place out for seven days over one capped day.
    expect(DB::table('ingest.effects')->where('digest', 'budget-refused-digest')->count())->toBe(0);
});

it('lets the same digest be claimed again immediately after a budget refusal', function () {
    $ledger = new EffectLedger;

    try {
        $ledger->once('retryable-digest', 'api', fn () => throw new EffectNotAttempted('capped'));
    } catch (EffectNotAttempted) {
        // expected
    }

    $second = $ledger->once('retryable-digest', 'api', fn () => ['place' => 'ok']);

    expect($second)->toBe(['status' => 'ok', 'result' => ['place' => 'ok'], 'cached' => false])
        ->and(DB::table('ingest.effects')->where('digest', 'retryable-digest')->value('status'))->toBe('ok');
});

it('keeps the claim for a plain EffectRefused raised inside the effect', function () {
    // EffectNotAttempted's no-charge guarantee is specific to it. A bare EffectRefused
    // from inside the closure could follow a vendor call (an admission failure
    // partway through a paid poll), so it must settle failed and keep the row.
    $ledger = new EffectLedger;

    expect(fn () => $ledger->once(
        digest: 'plain-refused-digest',
        kind: 'actor',
        effect: fn () => throw new EffectRefused('off-manifest host'),
    ))->toThrow(EffectRefused::class);

    $row = DB::table('ingest.effects')->where('digest', 'plain-refused-digest')->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('failed')
        ->and($row->settled_at)->not->toBeNull();
});

it('settles a no-answer as failed and returns instead of rethrowing', function () {
    $ledger = new EffectLedger;

    $outcome = $ledger->once(
        digest: 'no-answer-digest',
        kind: 'api',
        effect: fn () => throw new EffectNoAnswer('places returned 503'),
    );

    expect($outcome)->toBe(['status' => 'failed', 'result' => null, 'cached' => false]);

    $row = DB::table('ingest.effects')->where('digest', 'no-answer-digest')->first();

    expect($row->status)->toBe('failed')
        ->and($row->settled_at)->not->toBeNull()
        ->and(json_decode((string) $row->meta, true)['message'])->toBe('places returned 503');
});

it('holds a settled no-answer for the freshness window rather than re-running it', function () {
    // DELIBERATE, and the counterpart to the two tests above: a request went out
    // and we did not get an answer, so we cannot know whether the vendor billed us.
    // The ledger refuses to guess. The no-CHARGE causes — a denied budget claim,
    // an unconfigured credential — throw EffectNotAttempted instead and ARE
    // retryable; see the plan's D6 for why the line is drawn there and for the
    // ingest:effects --settle escape hatch.
    $ledger = new EffectLedger;
    $ledger->once('replayed-digest', 'api', fn () => throw new EffectNoAnswer('503'));

    $ran = false;
    $second = $ledger->once('replayed-digest', 'api', function () use (&$ran) {
        $ran = true;

        return ['place' => 'never'];
    });

    expect($ran)->toBeFalse()
        ->and($second)->toBe(['status' => 'failed', 'result' => null, 'cached' => true]);
});

it('never deletes a row that is already settled', function () {
    // The DELETE is guarded on (status=claimed, settled_at IS NULL). Without the
    // predicate, any future re-entrant path that raised EffectNotAttempted after a
    // settle would destroy a money row with no trace.
    $ledger = new EffectLedger;
    $ledger->once('settled-then-refused', 'api', fn () => ['place' => 'paid for']);

    DB::table('ingest.effects')->where('digest', 'settled-then-refused')->update(['status' => 'claimed']);

    expect(DB::table('ingest.effects')->where('digest', 'settled-then-refused')->count())->toBe(1);

    // status='claimed' but settled_at is set — the guard must still refuse to delete.
    try {
        $ledger->once('settled-then-refused', 'api', fn () => throw new EffectNotAttempted('cap'));
    } catch (EffectNotAttempted) {
        // The row is settled, so once() replays its verdict and never reaches the
        // closure — which is itself the point: no delete is even attempted.
    }

    expect(DB::table('ingest.effects')->where('digest', 'settled-then-refused')->count())->toBe(1);
});

it('settles an answered-but-empty result as ok with a null result', function () {
    // A 404 from Places is an ANSWER. Settling it ok stops us re-billing a dead
    // place id on every run inside the freshness window.
    $ledger = new EffectLedger;

    $outcome = $ledger->once('empty-answer-digest', 'api', fn () => null);

    expect($outcome)->toBe(['status' => 'ok', 'result' => null, 'cached' => false])
        ->and(DB::table('ingest.effects')->where('digest', 'empty-answer-digest')->value('status'))->toBe('ok');

    expect($ledger->once('empty-answer-digest', 'api', fn () => ['unreachable']))
        ->toBe(['status' => 'ok', 'result' => null, 'cached' => true]);
});
