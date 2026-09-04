<?php

// Tier-S runtime property tests for App\Ingest\Runtime\SourceScheduler (plan
// §4): the claim/release contract that is the system's ONLY run lock. DB-backed
// via the SQLite mirror (setupIngestTables(), tests/Pest.php), following the
// house convention: real classes, no mocks, self-provisioned schema per test.
//
// No sleep()/time-travel helpers — every "past"/"future" state is written
// directly onto the row via now()->addSeconds()/subSeconds(), mirroring
// EffectLedgerTest's convention (e.g. its abandoned-claim test).

use App\Ingest\Runtime\SourceScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupIngestTables();
    setupSitesTable();
});

/**
 * ingest.sources row, defaulted to "immediately due, healthy, not in flight" —
 * the baseline every test starts from and overrides away from as needed.
 *
 * The owner's site is seeded PUBLISHED by default: since 2026-09-04 the
 * scheduled (auto_sync) refresh only claims sources whose site is live. Pass
 * 'site' => 'unpublished' or 'site' => null to exercise the gate.
 */
function seedSourceForScheduler(array $overrides = []): string
{
    $id = (string) Str::uuid();
    $userId = (string) ($overrides['user_id'] ?? Str::uuid());
    $site = array_key_exists('site', $overrides) ? $overrides['site'] : 'published';
    unset($overrides['site']);

    if ($site !== null) {
        DB::connection('pgsql')->table('site.sites')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'subdomain' => 'sch-'.Str::lower(Str::random(8)),
            'is_published' => $site === 'published' ? 1 : 0,
        ]);
    }

    DB::table('ingest.sources')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'connection_id' => null,
        'source_key' => 'bandcamp',
        'surface_key' => 'music',
        'identifier' => 'https://someartist.bandcamp.com',
        'cost_units' => 1,
        'min_interval_secs' => 3600,
        'max_interval_secs' => 604800,
        'change_rate' => 0.5,
        'next_attempt_at' => now()->subMinute(),
        'last_run_at' => null,
        'visibility' => 1.0,
        'in_flight_since' => null,
        'in_flight_run_id' => null,
        'health' => 'ok',
        'consecutive_failures' => 0,
        'auto_sync' => true,
        'scope' => 'all',
        'scope_n' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

// ── claimDue(): eligibility + the mutual-exclusion claim ────────────────────

it('claims a due source and stamps in_flight_since and in_flight_run_id', function () {
    $id = seedSourceForScheduler();

    $claimed = (new SourceScheduler)->claimDue(10, 'run-abc');

    expect($claimed)->toHaveCount(1)
        ->and($claimed[0]['id'])->toBe($id)
        ->and($claimed[0]['in_flight_run_id'])->toBe('run-abc')
        ->and($claimed[0]['in_flight_since'])->not->toBeNull();

    $row = DB::table('ingest.sources')->where('id', $id)->first();
    expect($row->in_flight_since)->not->toBeNull()
        ->and($row->in_flight_run_id)->toBe('run-abc');
});

// THE core mutual-exclusion property: once claimed, a source is invisible to
// every subsequent claimDue() call until it is released.
it('never claims a source that is already in flight, even across repeated calls', function () {
    $id = seedSourceForScheduler();
    $scheduler = new SourceScheduler;

    $first = $scheduler->claimDue(10, 'run-1');
    expect($first)->toHaveCount(1)
        ->and($first[0]['id'])->toBe($id);

    $second = $scheduler->claimDue(10, 'run-2');
    expect($second)->toBe([]);

    // The claim must still reflect the FIRST call — the second attempt never
    // touched the row.
    $row = DB::table('ingest.sources')->where('id', $id)->first();
    expect($row->in_flight_run_id)->toBe('run-1');
});

it('does not claim a source whose next_attempt_at is in the future', function () {
    seedSourceForScheduler(['next_attempt_at' => now()->addHour()]);

    $claimed = (new SourceScheduler)->claimDue(10, 'run-1');

    expect($claimed)->toBe([]);
});

it('does not claim a source with auto_sync disabled', function () {
    seedSourceForScheduler(['auto_sync' => false]);

    $claimed = (new SourceScheduler)->claimDue(10, 'run-1');

    expect($claimed)->toBe([]);
});

// ── the published-site gate (owner, 2026-09-04) ─────────────────────────────

it('does not schedule a refresh for a source whose site is unpublished', function () {
    seedSourceForScheduler(['site' => 'unpublished']);
    seedSourceForScheduler(['site' => null]);

    expect((new SourceScheduler)->claimDue(10, 'run-gate'))->toBe([]);
});

it('still claims the eager (connect-time) pull for an unpublished site', function () {
    $id = seedSourceForScheduler(['site' => 'unpublished', 'auto_sync' => false, 'needs_eager_run' => true]);

    $claimed = (new SourceScheduler)->claimDue(10, 'run-eager');

    expect($claimed)->toHaveCount(1)
        ->and($claimed[0]['id'])->toBe($id);
});

it('claims the scheduled refresh once the site is published', function () {
    $id = seedSourceForScheduler(['site' => 'published']);

    $claimed = (new SourceScheduler)->claimDue(10, 'run-pub');

    expect($claimed)->toHaveCount(1)
        ->and($claimed[0]['id'])->toBe($id);
});

it('does not claim a source whose health is dead', function () {
    seedSourceForScheduler(['health' => 'dead']);

    $claimed = (new SourceScheduler)->claimDue(10, 'run-1');

    expect($claimed)->toBe([]);
});

// ── release(): the EWMA cadence + backoff/health bookkeeping ────────────────

// Direction, not exact floats: changed=true must pull the rate UP (and the
// next attempt sooner, toward min_interval_secs) relative to changed=false,
// which must pull it DOWN (next attempt later, toward max_interval_secs).
it('release() with changed=true moves change_rate up and schedules sooner than changed=false', function () {
    $idChanged = seedSourceForScheduler(['change_rate' => 0.5]);
    $idUnchanged = seedSourceForScheduler(['change_rate' => 0.5]);
    $scheduler = new SourceScheduler;

    $scheduler->release($idChanged, 'ok', true);
    $scheduler->release($idUnchanged, 'ok', false);

    $rowChanged = DB::table('ingest.sources')->where('id', $idChanged)->first();
    $rowUnchanged = DB::table('ingest.sources')->where('id', $idUnchanged)->first();

    expect((float) $rowChanged->change_rate)->toBeGreaterThan(0.5)
        ->and((float) $rowUnchanged->change_rate)->toBeLessThan(0.5);

    expect(strtotime((string) $rowChanged->next_attempt_at))
        ->toBeLessThan(strtotime((string) $rowUnchanged->next_attempt_at));

    // A qualifying outcome also clears failure state and marks the source healthy.
    expect((int) $rowChanged->consecutive_failures)->toBe(0)
        ->and($rowChanged->health)->toBe('ok')
        ->and($rowChanged->in_flight_since)->toBeNull()
        ->and($rowChanged->in_flight_run_id)->toBeNull();
});

it('release() with deferred reschedules by retryAfterSeconds and leaves change_rate and failure bookkeeping untouched', function () {
    $id = seedSourceForScheduler(['change_rate' => 0.42, 'consecutive_failures' => 2, 'health' => 'ok']);
    $before = now();

    (new SourceScheduler)->release($id, 'deferred', false, 900);

    $row = DB::table('ingest.sources')->where('id', $id)->first();

    // A vendor asking us to wait is not evidence about how often content
    // changes — the EWMA and failure counters must be untouched.
    expect((float) $row->change_rate)->toBe(0.42)
        ->and((int) $row->consecutive_failures)->toBe(2)
        ->and($row->health)->toBe('ok');

    $expectedAt = $before->copy()->addSeconds(900)->getTimestamp();
    expect(abs(strtotime((string) $row->next_attempt_at) - $expectedAt))->toBeLessThanOrEqual(2);

    // The claim is still released like every other outcome.
    expect($row->in_flight_since)->toBeNull()
        ->and($row->in_flight_run_id)->toBeNull();
});

it('release() with a failing outcome increments consecutive_failures and backs off exponentially', function () {
    $id = seedSourceForScheduler([
        'consecutive_failures' => 2,
        'min_interval_secs' => 100,
        'max_interval_secs' => 100_000,
    ]);
    $before = now();

    (new SourceScheduler)->release($id, 'unavailable', false);

    $row = DB::table('ingest.sources')->where('id', $id)->first();
    expect((int) $row->consecutive_failures)->toBe(3);

    // backoff = min(max_interval_secs, min_interval_secs * 2^min(6, failures))
    //         = min(100000, 100 * 2^3) = 800
    $expectedAt = $before->copy()->addSeconds(800)->getTimestamp();
    expect(abs(strtotime((string) $row->next_attempt_at) - $expectedAt))->toBeLessThanOrEqual(2);
});

it('release() flips health to dead at exactly 10 consecutive failures, not before', function () {
    $idAlmost = seedSourceForScheduler(['consecutive_failures' => 8, 'health' => 'ok']); // -> 9 after release
    $idAtTen = seedSourceForScheduler(['consecutive_failures' => 9, 'health' => 'ok']);   // -> 10 after release
    $scheduler = new SourceScheduler;

    $scheduler->release($idAlmost, 'unavailable', false);
    $scheduler->release($idAtTen, 'unavailable', false);

    expect(DB::table('ingest.sources')->where('id', $idAlmost)->value('health'))->toBe('unavailable')
        ->and(DB::table('ingest.sources')->where('id', $idAlmost)->value('consecutive_failures'))->toBe(9);

    expect(DB::table('ingest.sources')->where('id', $idAtTen)->value('health'))->toBe('dead')
        ->and(DB::table('ingest.sources')->where('id', $idAtTen)->value('consecutive_failures'))->toBe(10);
});

// ── release('degraded'): OUR bug must not kill the vendor's source ──────────
//
// RunExecutor marks a run 'degraded' when the fetch and the landing succeeded
// but a projection threw (#JOB-4). That is evidence about our code, so it must
// not accumulate toward the 10-failure 'dead' verdict that scoreDue() excludes
// permanently and that reconnecting deliberately does not reset.

it('release() with degraded does not increment consecutive_failures', function () {
    $id = seedSourceForScheduler(['consecutive_failures' => 3]);

    (new SourceScheduler)->release($id, 'degraded', true);

    $row = DB::table('ingest.sources')->where('id', $id)->first();

    // Reset, not merely held: the fetch itself succeeded this run.
    expect((int) $row->consecutive_failures)->toBe(0)
        ->and($row->in_flight_since)->toBeNull()
        ->and($row->in_flight_run_id)->toBeNull();
});

it('ten consecutive degraded releases never mark a source dead and leave it claimable', function () {
    $id = seedSourceForScheduler();
    $scheduler = new SourceScheduler;

    for ($i = 0; $i < 10; $i++) {
        $scheduler->release($id, 'degraded', true);
    }

    $row = DB::table('ingest.sources')->where('id', $id)->first();
    expect($row->health)->toBe('degraded')
        ->and($row->health)->not->toBe('dead')
        ->and((int) $row->consecutive_failures)->toBe(0);

    // Simulate the measured interval elapsing (this file's convention: write
    // the time state onto the row rather than sleeping) and prove the health
    // value is not itself an exclusion.
    DB::table('ingest.sources')->where('id', $id)->update(['next_attempt_at' => now()->subMinute()]);

    $claimed = $scheduler->claimDue(10, 'run-after-degraded');
    expect($claimed)->toHaveCount(1)
        ->and($claimed[0]['id'])->toBe($id);
});

// Pins the per-field decision for 'degraded' so it cannot drift into being
// incidental: EWMA yes, failure reset yes, measured cadence yes (no backoff),
// health 'degraded' rather than 'ok' (the fetch is healthy, the derived content
// is not — stamping 'ok' would claim the projected data is good).
it('release() with degraded applies the EWMA and the measured interval, and stamps health degraded not ok', function () {
    $id = seedSourceForScheduler([
        'change_rate' => 0.5,
        'consecutive_failures' => 4,
        'min_interval_secs' => 100,
        'max_interval_secs' => 100_000,
    ]);
    $before = now();

    (new SourceScheduler)->release($id, 'degraded', true);

    $row = DB::table('ingest.sources')->where('id', $id)->first();

    // rate = 0.3 * 1.0 + 0.7 * 0.5 = 0.65
    expect((float) $row->change_rate)->toBe(0.65)
        ->and((int) $row->consecutive_failures)->toBe(0)
        ->and($row->health)->toBe('degraded');

    // Measured cadence: max - (max - min) * rate = 100000 - 99900 * 0.65 = 35065.
    $expectedAt = $before->copy()->addSeconds(35065)->getTimestamp();
    expect(abs(strtotime((string) $row->next_attempt_at) - $expectedAt))->toBeLessThanOrEqual(2);

    // Explicitly NOT the failure backoff a fifth failure would have produced:
    // min(100000, 100 * 2^min(6, 5)) = 3200.
    $backoffAt = $before->copy()->addSeconds(3200)->getTimestamp();
    expect(abs(strtotime((string) $row->next_attempt_at) - $backoffAt))->toBeGreaterThan(2);
});

it('drives change_rate to exactly 1.0 after one changed run when alpha is 1.0 (CFG-16)', function () {
    config()->set('partna.ingest.source_change_rate_alpha', 1.0);
    $id = seedSourceForScheduler(['change_rate' => 0.5]);

    (new SourceScheduler)->release($id, 'ok', true);

    $row = DB::table('ingest.sources')->where('id', $id)->first();

    // rate = 1.0 * 1.0 + (1 - 1.0) * 0.5 = 1.0, exact in IEEE — under the
    // default alpha (0.3) this would be 0.65, so this proves the read.
    expect((float) $row->change_rate)->toBe(1.0)
        ->and($row->health)->toBe('ok')
        ->and((int) $row->consecutive_failures)->toBe(0);
});

// The counterpart guard: widening $qualifies must not have weakened the real
// failure path. A source that genuinely cannot be fetched still dies.
it('ten consecutive unavailable releases still reach dead and stop being claimable', function () {
    $id = seedSourceForScheduler();
    $scheduler = new SourceScheduler;

    for ($i = 0; $i < 10; $i++) {
        $scheduler->release($id, 'unavailable', false);
    }

    $row = DB::table('ingest.sources')->where('id', $id)->first();
    expect($row->health)->toBe('dead')
        ->and((int) $row->consecutive_failures)->toBe(10);

    DB::table('ingest.sources')->where('id', $id)->update(['next_attempt_at' => now()->subMinute()]);

    expect($scheduler->claimDue(10, 'run-after-dead'))->toBe([]);
});

// ── releaseStranded(): the 2h dead-worker backstop ──────────────────────────

it('releaseStranded() releases a claim older than 2h, files a stranded anomaly, and leaves a fresh claim alone', function () {
    $stale = seedSourceForScheduler([
        'in_flight_since' => now()->subSeconds(7200 + 60)->toDateTimeString(),
        'in_flight_run_id' => 'stale-run',
    ]);
    $fresh = seedSourceForScheduler([
        'in_flight_since' => now()->subMinutes(5)->toDateTimeString(),
        'in_flight_run_id' => 'fresh-run',
    ]);

    $released = (new SourceScheduler)->releaseStranded();

    expect($released)->toBe([$stale]);

    $staleRow = DB::table('ingest.sources')->where('id', $stale)->first();
    expect($staleRow->in_flight_since)->toBeNull()
        ->and($staleRow->in_flight_run_id)->toBeNull();

    $freshRow = DB::table('ingest.sources')->where('id', $fresh)->first();
    expect($freshRow->in_flight_since)->not->toBeNull()
        ->and($freshRow->in_flight_run_id)->toBe('fresh-run');

    $anomaly = DB::table('ingest.anomalies')->where('source_id', $stale)->where('kind', 'stranded')->first();
    expect($anomaly)->not->toBeNull()
        ->and($anomaly->severity)->toBe('warning');

    // The fresh claim must not have generated an anomaly of its own.
    expect(DB::table('ingest.anomalies')->where('source_id', $fresh)->count())->toBe(0);
});

// O.1 (2026-09-02): a source that has never landed (needs_eager_run still
// set) is a signup's connection whose card reads "pending" until it does;
// the exponential curve was built for sources with history (TikTok's 12h
// minimum → a full day after one vendor blip). The first three failures of a
// first pull retry within 15 minutes; the fourth falls back to the curve.
it('release() retries a never-landed source within 15 minutes for its first three failures, then backs off normally', function () {
    $id = seedSourceForScheduler([
        'consecutive_failures' => 0,
        'min_interval_secs' => 43_200,
        'max_interval_secs' => 604_800,
        'needs_eager_run' => 1,
    ]);
    $scheduler = new SourceScheduler;

    foreach ([1, 2, 3] as $n) {
        $before = now();
        $scheduler->release($id, 'unavailable', false);
        $row = DB::table('ingest.sources')->where('id', $id)->first();
        expect((int) $row->consecutive_failures)->toBe($n)
            ->and((bool) $row->needs_eager_run)->toBeTrue();
        $expectedAt = $before->copy()->addSeconds(SourceScheduler::FIRST_PULL_RETRY_SECONDS)->getTimestamp();
        expect(abs(strtotime((string) $row->next_attempt_at) - $expectedAt))->toBeLessThanOrEqual(2);
    }

    // Fourth failure: min(604800, 43200 * 2^4) = 604800 — the ordinary curve.
    $before = now();
    $scheduler->release($id, 'unavailable', false);
    $row = DB::table('ingest.sources')->where('id', $id)->first();
    $expectedAt = $before->copy()->addSeconds(604_800)->getTimestamp();
    expect(abs(strtotime((string) $row->next_attempt_at) - $expectedAt))->toBeLessThanOrEqual(2);
});

it('release() keeps the ordinary backoff for a source that has landed before, even on its first failure', function () {
    $id = seedSourceForScheduler([
        'consecutive_failures' => 0,
        'min_interval_secs' => 43_200,
        'max_interval_secs' => 604_800,
        'needs_eager_run' => 0,
    ]);
    $before = now();

    (new SourceScheduler)->release($id, 'unavailable', false);

    $row = DB::table('ingest.sources')->where('id', $id)->first();
    $expectedAt = $before->copy()->addSeconds(86_400)->getTimestamp();
    expect(abs(strtotime((string) $row->next_attempt_at) - $expectedAt))->toBeLessThanOrEqual(2);
});
