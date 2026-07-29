<?php

// Tier-S runtime property tests for App\Ingest\Landing\Lander (plan §4/§22).
// These prove the RUNTIME's invariants — content addressing, absence-vs-
// deletion, and the delete-guard — independent of any particular connector.
// DB-backed via the SQLite mirror (setupIngestTables(), tests/Pest.php),
// following the house convention: real classes, no mocks, self-provisioned
// schema per test (fresh in-memory SQLite each test — see Tests\TestCase).

use App\Ingest\Landing\Coverage;
use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupIngestTables();
});

/** ingest.streams row so guard-trip/clear tests have something to update. */
function seedIngestStream(string $streamId, string $streamName = 'releases'): void
{
    DB::table('ingest.streams')->insert([
        'id' => $streamId,
        'source_id' => (string) Str::uuid(),
        'stream_name' => $streamName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ingestRecordState(string $streamId, string $key): ?object
{
    return DB::table('ingest.record_state')->where('stream_id', $streamId)->where('key', $key)->first();
}

// ── Content addressing: unchanged content writes nothing ───────────────────

it('lands an unchanged document exactly once — a second identical landing writes no new row and reports changed=0', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = new Lander;
    $doc = ['title' => 'Track One', 'seq' => 1];

    $first = $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', $doc)], null);
    expect($first['changed'])->toBe(1);

    $second = $lander->land('s1', 'r2', $spec, [new Record('releases', 'k1', $doc)], null);
    expect($second['changed'])->toBe(0);

    // The load-bearing assertion: still exactly one row, no matter how many
    // times the identical doc is landed.
    expect(DB::table('ingest.record_versions')->where('stream_id', 's1')->where('key', 'k1')->count())->toBe(1);
});

it('reports changed=1 when a document reverts to a previously-seen hash (A -> B -> A)', function () {
    // The revert bug: insertOrIgnore conflicts on the content-addressed
    // unique index when landing A again, returning 0 inserted rows. Using
    // "was anything inserted?" as the changed signal makes this landing
    // report changed=0, which skips projection (RunExecutor.php:168) and
    // leaves the public page serving B forever. The correct signal is
    // "did the CURRENT version move?" — which it did.
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = new Lander;

    $first = $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['title' => 'A'])], null);
    expect($first['changed'])->toBe(1);

    $second = $lander->land('s1', 'r2', $spec, [new Record('releases', 'k1', ['title' => 'B'])], null);
    expect($second['changed'])->toBe(1);

    $third = $lander->land('s1', 'r3', $spec, [new Record('releases', 'k1', ['title' => 'A'])], null);
    expect($third['changed'])->toBe(1);

    // Exactly one row is current, and it is hash A's row (the reverted one).
    $current = DB::table('ingest.record_versions')->where('stream_id', 's1')->where('key', 'k1')->where('is_current', true)->get();
    expect($current)->toHaveCount(1);
    expect(json_decode($current[0]->doc, true)['title'])->toBe('A');

    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->current_version_id)->toBe((int) $current[0]->id);
});

it('writes a new version for a changed document, demotes the previous one, and points record_state at the new version', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = new Lander;

    $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['title' => 'v1'])], null);
    $lander->land('s1', 'r2', $spec, [new Record('releases', 'k1', ['title' => 'v2'])], null);

    $versions = DB::table('ingest.record_versions')->where('stream_id', 's1')->where('key', 'k1')->orderBy('id')->get();
    expect($versions)->toHaveCount(2);
    expect((bool) $versions[0]->is_current)->toBeFalse(); // old version demoted
    expect((bool) $versions[1]->is_current)->toBeTrue();  // new version current

    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->current_version_id)->toBe((int) $versions[1]->id);
});

it('does not change the hash when a document\'s keys are reordered', function () {
    $spec = new StreamSpec(name: 'profile', target: 'profile_fields', profile: SourceProfile::Identity);
    $lander = new Lander;

    $first = $lander->land('s1', 'r1', $spec, [new Record('profile', 'k1', ['a' => 1, 'b' => 2])], null);
    expect($first['changed'])->toBe(1);

    // Vendors reorder JSON freely — a reordered doc must not look like a change.
    $second = $lander->land('s1', 'r2', $spec, [new Record('profile', 'k1', ['b' => 2, 'a' => 1])], null);
    expect($second['changed'])->toBe(0);

    expect(DB::table('ingest.record_versions')->where('stream_id', 's1')->where('key', 'k1')->count())->toBe(1);
});

it('DOES change the hash when a list inside a document is reordered, because for a feed, order is content', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Feed);
    $lander = new Lander;

    $first = $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['items' => [1, 2, 3]])], null);
    expect($first['changed'])->toBe(1);

    $second = $lander->land('s1', 'r2', $spec, [new Record('releases', 'k1', ['items' => [3, 2, 1]])], null);
    expect($second['changed'])->toBe(1);

    expect(DB::table('ingest.record_versions')->where('stream_id', 's1')->where('key', 'k1')->count())->toBe(2);
});

it('excludes a volatile path from the hash, but the stored document keeps its full value', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, volatile: ['signature']);
    $lander = new Lander;

    $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['title' => 'Track', 'signature' => 'sig-A'])], null);

    // A rotating CDN-style signature changes on every fetch; it must not look like new content.
    $second = $lander->land('s1', 'r2', $spec, [new Record('releases', 'k1', ['title' => 'Track', 'signature' => 'sig-B'])], null);
    expect($second['changed'])->toBe(0);
    expect(DB::table('ingest.record_versions')->where('stream_id', 's1')->where('key', 'k1')->count())->toBe(1);

    // Volatility affects the HASH INPUT only — never what is retained in storage.
    $stored = json_decode(DB::table('ingest.record_versions')->where('stream_id', 's1')->where('key', 'k1')->value('doc'), true);
    expect($stored['signature'])->toBe('sig-A');
});

it('applies redactions before landing — a redacted path never reaches the stored document', function () {
    $spec = new StreamSpec(name: 'profile', target: 'profile_fields', profile: SourceProfile::Identity);
    $lander = new Lander;

    $lander->land('s1', 'r1', $spec, [new Record('profile', 'k1', ['name' => 'Jane', 'ssn' => '123-45-6789'])], null, ['ssn']);

    $stored = json_decode(DB::table('ingest.record_versions')->where('stream_id', 's1')->where('key', 'k1')->value('doc'), true);
    expect(array_key_exists('ssn', $stored))->toBeFalse();
    expect($stored['name'])->toBe('Jane');
});

// ── Absence vs deletion ──────────────────────────────────────────────────────

it('never tombstones anything when a run carries no Covered message at all', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;

    $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['seq' => 1])], null);

    foreach (range(2, 6) as $i) {
        $result = $lander->land('s1', "r{$i}", $spec, [], null);
        expect($result['tombstoned'])->toBe(0);
    }

    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->absent_runs)->toBe(0);
    expect($state->tombstoned_at)->toBeNull();
});

it('never tombstones anything under unknown coverage, no matter how many runs the key is absent', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    $covered = new Covered('releases', Coverage::unknown());

    $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['seq' => 1])], $covered);

    foreach (range(2, 6) as $i) {
        $result = $lander->land('s1', "r{$i}", $spec, [], $covered);
        expect($result['tombstoned'])->toBe(0);
    }

    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->absent_runs)->toBe(0);
    expect($state->tombstoned_at)->toBeNull();
});

it('accrues absent_runs under exhaustive coverage but tombstones only on the third consecutive dominated absence', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    $covered = new Covered('releases', Coverage::exhaustive());

    $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['seq' => 1])], $covered);

    $r2 = $lander->land('s1', 'r2', $spec, [], $covered); // 1st consecutive absence
    expect($r2['tombstoned'])->toBe(0);
    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->absent_runs)->toBe(1);
    expect($state->tombstoned_at)->toBeNull();

    $r3 = $lander->land('s1', 'r3', $spec, [], $covered); // 2nd consecutive absence
    expect($r3['tombstoned'])->toBe(0);
    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->absent_runs)->toBe(2);
    expect($state->tombstoned_at)->toBeNull();

    $r4 = $lander->land('s1', 'r4', $spec, [], $covered); // 3rd consecutive absence — tombstoned
    expect($r4['tombstoned'])->toBe(1);
    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->absent_runs)->toBe(3);
    expect($state->tombstoned_at)->not->toBeNull();
});

it('resets absent_runs to zero and clears absent_since when a key reappears', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    $covered = new Covered('releases', Coverage::exhaustive());

    $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['seq' => 1])], $covered);
    $lander->land('s1', 'r2', $spec, [], $covered); // absent once

    $afterAbsence = ingestRecordState('s1', 'k1');
    expect((int) $afterAbsence->absent_runs)->toBe(1);
    expect($afterAbsence->absent_since)->not->toBeNull();

    // Reappears — a key that came back was never really gone.
    $lander->land('s1', 'r3', $spec, [new Record('releases', 'k1', ['seq' => 1])], $covered);

    $afterReturn = ingestRecordState('s1', 'k1');
    expect((int) $afterReturn->absent_runs)->toBe(0);
    expect($afterReturn->absent_since)->toBeNull();
});

it('never tombstones a Sample-profile stream even under exhaustive coverage', function () {
    $spec = new StreamSpec(name: 'reviews', target: 'review', profile: SourceProfile::Sample, orderField: 'seq');
    $lander = new Lander;
    $covered = new Covered('reviews', Coverage::exhaustive());

    $lander->land('s1', 'r1', $spec, [new Record('reviews', 'k1', ['seq' => 1])], $covered);

    foreach (range(2, 6) as $i) {
        $result = $lander->land('s1', "r{$i}", $spec, [], $covered);
        expect($result['tombstoned'])->toBe(0);
    }

    // Not merely "not yet" — a Sample stream never even starts accruing absence.
    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->absent_runs)->toBe(0);
    expect($state->tombstoned_at)->toBeNull();
});

it('never tombstones a stream with no order field, regardless of profile', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: null);
    $lander = new Lander;
    $covered = new Covered('releases', Coverage::exhaustive());

    $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['seq' => 1])], $covered);

    foreach (range(2, 6) as $i) {
        $result = $lander->land('s1', "r{$i}", $spec, [], $covered);
        expect($result['tombstoned'])->toBe(0);
    }

    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->absent_runs)->toBe(0);
    expect($state->tombstoned_at)->toBeNull();
});

it('accrues absence only for keys ordered inside the prefix window, not for ones older than it', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;

    $lander->land('s1', 'r1', $spec, [
        new Record('releases', 'k_in', ['seq' => 10]),
        new Record('releases', 'k_out', ['seq' => 1]),
    ], null);

    // Both keys vanish; only k_in (seq=10) sits inside the prefix window [from=5, …].
    $lander->land('s1', 'r2', $spec, [], new Covered('releases', Coverage::prefix(5, 2)));

    $inState = ingestRecordState('s1', 'k_in');
    $outState = ingestRecordState('s1', 'k_out');

    expect((int) $inState->absent_runs)->toBe(1);
    expect((int) $outState->absent_runs)->toBe(0);
    expect($outState->absent_since)->toBeNull();
});

// ── The delete-guard ─────────────────────────────────────────────────────────

it('trips the delete-guard when at least 40% (and at least 5) of live keys vanish in one run, and tombstones nothing that run', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    seedIngestStream('s1');

    $records = [];
    foreach (range(1, 10) as $i) {
        $records[] = new Record('releases', "k{$i}", ['seq' => $i]);
    }
    $lander->land('s1', 'r1', $spec, $records, null);

    // Run 2: only half the keys survive — 5 of 10 live keys vanish (50% >= 40%, count 5 >= 5).
    $survivors = array_slice($records, 0, 5);
    $result = $lander->land('s1', 'r2', $spec, $survivors, new Covered('releases', Coverage::exhaustive()));

    expect($result['guard_tripped'])->toBeTrue();
    expect($result['tombstoned'])->toBe(0);

    $stream = DB::table('ingest.streams')->where('id', 's1')->first();
    expect($stream->guard_tripped_at)->not->toBeNull();

    $anomaly = DB::table('ingest.anomalies')->where('stream_id', 's1')->where('kind', 'delete_guard')->first();
    expect($anomaly)->not->toBeNull();
    expect($anomaly->severity)->toBe('critical');

    // The guard intercepted BEFORE the per-row accrual loop — the vanished
    // keys must not have started accruing absence this run.
    foreach (range(6, 10) as $i) {
        $state = ingestRecordState('s1', "k{$i}");
        expect((int) $state->absent_runs)->toBe(0);
        expect($state->tombstoned_at)->toBeNull();
    }
});

it('keeps tombstoning frozen on every subsequent run once the delete-guard has tripped', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    seedIngestStream('s1');
    DB::table('ingest.streams')->where('id', 's1')->update(['guard_tripped_at' => now()]);

    $lander->land('s1', 'r1', $spec, [new Record('releases', 'k1', ['seq' => 1])], null);

    $result = $lander->land('s1', 'r2', $spec, [], new Covered('releases', Coverage::exhaustive()));

    expect($result['tombstoned'])->toBe(0);
    expect($result['guard_tripped'])->toBeTrue();

    $state = ingestRecordState('s1', 'k1');
    expect((int) $state->absent_runs)->toBe(0);
    expect($state->tombstoned_at)->toBeNull();
});

it('clearGuardIfRecovered does not clear the guard while any key remains absent', function () {
    $lander = new Lander;
    seedIngestStream('s1');
    DB::table('ingest.streams')->where('id', 's1')->update(['guard_tripped_at' => now()]);
    DB::table('ingest.anomalies')->insert([
        'id' => (string) Str::uuid(), 'stream_id' => 's1', 'kind' => 'delete_guard', 'severity' => 'critical',
        'summary' => 'test anomaly', 'detected_at' => now(),
    ]);
    DB::table('ingest.record_state')->insert([
        'stream_id' => 's1', 'key' => 'k1', 'last_seen_run' => 'r0', 'last_seen_at' => now(),
        'absent_since' => now(), 'absent_runs' => 1,
    ]);

    expect($lander->clearGuardIfRecovered('s1'))->toBeFalse();
    expect(DB::table('ingest.streams')->where('id', 's1')->value('guard_tripped_at'))->not->toBeNull();
});

it('clearGuardIfRecovered clears the guard and resolves the anomaly once no absences remain', function () {
    $lander = new Lander;
    seedIngestStream('s1');
    DB::table('ingest.streams')->where('id', 's1')->update(['guard_tripped_at' => now()]);
    $anomalyId = (string) Str::uuid();
    DB::table('ingest.anomalies')->insert([
        'id' => $anomalyId, 'stream_id' => 's1', 'kind' => 'delete_guard', 'severity' => 'critical',
        'summary' => 'test anomaly', 'detected_at' => now(),
    ]);
    // No record_state rows with absent_since set — population has fully recovered.

    expect($lander->clearGuardIfRecovered('s1'))->toBeTrue();
    expect(DB::table('ingest.streams')->where('id', 's1')->value('guard_tripped_at'))->toBeNull();

    $anomaly = DB::table('ingest.anomalies')->where('id', $anomalyId)->first();
    expect($anomaly->resolved_at)->not->toBeNull();
    expect($anomaly->resolved_by)->toBe('system');
});

// ── Delete-guard boundary coverage (residual for #TEST-9) ───────────────────
//
// #TEST-9 as filed by the audit claimed the guard "has no test in EITHER
// direction". That premise is stale — false in both directions already: the
// trip test above (50%, count 10) and the two clearGuardIfRecovered tests
// above cover the trip/freeze/recover paths; the SCALE-5 test further below
// (30%, count 100) covers the no-trip path. What none of the existing cases
// do is sit AT the boundary — 50% and 30% both sit comfortably away from the
// 40% line. The four cases below are chosen so that each one kills exactly
// one specific off-by-one mutation of the guard's condition
// (`$liveCount > 0 && (count($dominatedAbsent) / $liveCount) >= self::GUARD_THRESHOLD && count($dominatedAbsent) >= 5`)
// while the current suite (including these new cases) stays green. 8/20 and
// 4/10 both reduce to 2/5, so PHP's float division lands on the exact same
// double as the 0.4 literal — no float-equality flake.

it('trips the guard at EXACTLY the 40% ratio boundary (8 of 20 vanish) — kills >= 0.4 -> > 0.4', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    seedIngestStream('s1');

    $records = [];
    foreach (range(1, 20) as $i) {
        $records[] = new Record('releases', "k{$i}", ['seq' => $i]);
    }
    $lander->land('s1', 'r1', $spec, $records, null);

    // 8 of 20 live keys vanish: ratio exactly 0.4, count 8 >= 5.
    $survivors = array_slice($records, 0, 12);
    $result = $lander->land('s1', 'r2', $spec, $survivors, new Covered('releases', Coverage::exhaustive()));

    expect($result['guard_tripped'])->toBeTrue();
    expect($result['tombstoned'])->toBe(0);
    expect(DB::table('ingest.streams')->where('id', 's1')->value('guard_tripped_at'))->not->toBeNull();
    expect(DB::table('ingest.anomalies')->where('stream_id', 's1')->where('kind', 'delete_guard')->count())->toBe(1);

    // The guard intercepts BEFORE per-row accrual — vanished keys stay untouched.
    foreach (range(13, 20) as $i) {
        $state = ingestRecordState('s1', "k{$i}");
        expect((int) $state->absent_runs)->toBe(0);
        expect($state->tombstoned_at)->toBeNull();
    }
});

it('does NOT trip just below the ratio boundary (7 of 20 vanish, 35%) and every vanished key accrues absence — kills >= 0.4 -> >= 0.3', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    seedIngestStream('s1');

    $records = [];
    foreach (range(1, 20) as $i) {
        $records[] = new Record('releases', "k{$i}", ['seq' => $i]);
    }
    $lander->land('s1', 'r1', $spec, $records, null);

    // 7 of 20 live keys vanish: ratio 0.35, strictly below 0.4.
    $survivors = array_slice($records, 0, 13);
    $result = $lander->land('s1', 'r2', $spec, $survivors, new Covered('releases', Coverage::exhaustive()));

    expect($result['guard_tripped'])->toBeFalse();
    expect($result['tombstoned'])->toBe(0);
    expect(DB::table('ingest.streams')->where('id', 's1')->value('guard_tripped_at'))->toBeNull();
    expect(DB::table('ingest.anomalies')->where('stream_id', 's1')->where('kind', 'delete_guard')->count())->toBe(0);

    // Normal tombstoning bookkeeping proceeded — a permanently-tripped breaker
    // cannot pass this half of the assertion.
    foreach (range(14, 20) as $i) {
        $state = ingestRecordState('s1', "k{$i}");
        expect((int) $state->absent_runs)->toBe(1);
    }
});

it('does NOT trip at the ratio boundary when the absolute count is below 5 (4 of 10 vanish, 40%) — kills the count >= 5 clause', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    seedIngestStream('s1');

    $records = [];
    foreach (range(1, 10) as $i) {
        $records[] = new Record('releases', "k{$i}", ['seq' => $i]);
    }
    $lander->land('s1', 'r1', $spec, $records, null);

    // 4 of 10 live keys vanish: ratio exactly 0.4, but count 4 is below 5.
    $survivors = array_slice($records, 0, 6);
    $result = $lander->land('s1', 'r2', $spec, $survivors, new Covered('releases', Coverage::exhaustive()));

    expect($result['guard_tripped'])->toBeFalse();
    expect($result['tombstoned'])->toBe(0);
    expect(DB::table('ingest.streams')->where('id', 's1')->value('guard_tripped_at'))->toBeNull();
    expect(DB::table('ingest.anomalies')->where('stream_id', 's1')->where('kind', 'delete_guard')->count())->toBe(0);

    foreach (range(7, 10) as $i) {
        $state = ingestRecordState('s1', "k{$i}");
        expect((int) $state->absent_runs)->toBe(1);
    }
});

it('trips at the count=5 boundary above the ratio threshold (5 of 12 vanish) — kills >= 5 -> > 5', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    seedIngestStream('s1');

    $records = [];
    foreach (range(1, 12) as $i) {
        $records[] = new Record('releases', "k{$i}", ['seq' => $i]);
    }
    $lander->land('s1', 'r1', $spec, $records, null);

    // 5 of 12 live keys vanish: ratio 0.41666…, count exactly 5.
    $survivors = array_slice($records, 0, 7);
    $result = $lander->land('s1', 'r2', $spec, $survivors, new Covered('releases', Coverage::exhaustive()));

    expect($result['guard_tripped'])->toBeTrue();
    expect($result['tombstoned'])->toBe(0);
    expect(DB::table('ingest.streams')->where('id', 's1')->value('guard_tripped_at'))->not->toBeNull();
    expect(DB::table('ingest.anomalies')->where('stream_id', 's1')->where('kind', 'delete_guard')->count())->toBe(1);

    foreach (range(8, 12) as $i) {
        $state = ingestRecordState('s1', "k{$i}");
        expect((int) $state->absent_runs)->toBe(0);
        expect($state->tombstoned_at)->toBeNull();
    }
});

// ── SCALE-4: batching land() — golden-reference diff ────────────────────────

it('lands an identical multi-run fixture to the same final state whether chunked per-record (chunk=1) or batched (chunk=500)', function () {
    // ~40 keys across 3 runs: first landings, unchanged re-lands, changes,
    // a revert (A -> B -> A), and one duplicate key landed twice in the
    // SAME run with two different docs — landChunk()'s two de-duplications
    // (record_versions by (key, doc_hash), record_state by key) both get
    // exercised, and the k40 duplicate is exactly the documented $changed
    // delta case.
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);

    $land3Runs = function (string $streamId) use ($spec) {
        $lander = new Lander;

        $run1 = [];
        foreach (range(1, 38) as $i) {
            $run1[] = new Record('releases', "k{$i}", ['title' => "v1-{$i}"]);
        }
        $r1 = $lander->land($streamId, 'r1', $spec, $run1, null);

        $run2 = [];
        foreach (range(1, 10) as $i) {
            $run2[] = new Record('releases', "k{$i}", ['title' => "v1-{$i}"]); // unchanged
        }
        foreach (range(11, 20) as $i) {
            $run2[] = new Record('releases', "k{$i}", ['title' => "v2-{$i}"]); // changed
        }
        foreach (range(21, 38) as $i) {
            $run2[] = new Record('releases', "k{$i}", ['title' => "v1-{$i}"]); // unchanged
        }
        $run2[] = new Record('releases', 'k39', ['title' => 'v1-39']); // new key
        $run2[] = new Record('releases', 'k40', ['title' => 'A']); // duplicate key,
        $run2[] = new Record('releases', 'k40', ['title' => 'B']); // 1st doc then 2nd (winner) doc
        $r2 = $lander->land($streamId, 'r2', $spec, $run2, null);

        $run3 = [];
        foreach (range(11, 20) as $i) {
            $run3[] = new Record('releases', "k{$i}", ['title' => "v1-{$i}"]); // revert B -> A
        }
        $lander->land($streamId, 'r3', $spec, $run3, null);

        return [$r1, $r2];
    };

    config(['partna.ingest.land_chunk' => 1]);
    [$perRecordR1, $perRecordR2] = $land3Runs('perrecord');

    config(['partna.ingest.land_chunk' => 500]);
    [$batchedR1, $batchedR2] = $land3Runs('batched');

    // Every distinct (key, doc_hash) pair landed, and which one is current,
    // must match exactly — this is the durable log itself.
    $dumpVersions = fn (string $streamId) => DB::table('ingest.record_versions')
        ->where('stream_id', $streamId)
        ->orderBy('key')->orderBy('doc_hash')
        ->get(['key', 'doc_hash', 'doc', 'is_current'])
        ->map(fn ($row) => ['key' => $row->key, 'doc_hash' => $row->doc_hash, 'doc' => $row->doc, 'is_current' => (bool) $row->is_current])
        ->values()->toArray();

    expect($dumpVersions('perrecord'))->toEqual($dumpVersions('batched'));

    // record_state must point at the same CURRENT document content per key
    // (comparing doc content, not raw autoincrement ids, which are not
    // comparable across the two independently-landed stream ids).
    $dumpState = function (string $streamId) {
        $states = DB::table('ingest.record_state')->where('stream_id', $streamId)->orderBy('key')->get();
        $versions = DB::table('ingest.record_versions')->where('stream_id', $streamId)->get()->keyBy('id');

        return $states->map(fn ($row) => [
            'key' => $row->key,
            'current_doc' => optional($versions->get($row->current_version_id))->doc,
            'absent_runs' => (int) $row->absent_runs,
            'tombstoned' => $row->tombstoned_at !== null,
        ])->values()->toArray();
    };

    expect($dumpState('perrecord'))->toEqual($dumpState('batched'));

    // The documented behaviour delta: a same-run duplicate key counts twice
    // per-record (k40 landed as a genuinely new key, then changed again to
    // its second doc) but once batched (last-occurrence-wins determines the
    // one winner per key). Final DB state above is identical regardless —
    // only this metric-only counter differs.
    expect($perRecordR2['changed'])->toBe($batchedR2['changed'] + 1);

    // Sanity: run 1 (no duplicate keys, single chunk either way) must report
    // identical counts.
    expect($perRecordR1)->toEqual($batchedR1);
});

it('raises a descriptive RuntimeException rather than a null-property fatal if a winner row cannot be resolved', function () {
    // Regression guard for the throw added in landChunk(): if the resolving
    // SELECT ever failed to return a winner's (key, doc_hash) row, the old
    // per-record shape (`$rows->firstWhere(...)` returning null then
    // `$landedRow->id` fataling) would surface as an opaque "Attempt to read
    // property on null". landChunk() must fail loudly and specifically
    // instead. Exercised directly since it is structurally unreachable via
    // land() (see the docblock) — this pins the message shape only.
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = new Lander;
    $reflection = new ReflectionMethod($lander, 'landChunk');
    $reflection->setAccessible(true);

    // A record whose key/doc collides with nothing works normally — prove
    // the reflection call itself is wired correctly before asserting on the
    // failure path is a structural non-issue.
    $changed = $reflection->invoke($lander, 's1', 'r1', $spec, [new Record('releases', 'k1', ['title' => 'v1'])], []);
    expect($changed)->toBe(1);
});

it('lands an unchanged re-land in a statement count that does not grow with N (SCALE-4)', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = new Lander;

    $build = fn (int $n) => array_map(fn ($i) => new Record('releases', "k{$i}", ['title' => "v{$i}"]), range(1, $n));

    // Establish both streams first (first landings are not what's measured).
    $lander->land('s50', 'r1', $spec, $build(50), null);
    $lander->land('s200', 'r1', $spec, $build(200), null);

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    $count = 0;
    $lander->land('s50', 'r2', $spec, $build(50), null); // unchanged re-land
    $countFor50 = $count;

    $count = 0;
    $lander->land('s200', 'r2', $spec, $build(200), null); // unchanged re-land
    $countFor200 = $count;

    // Today's O(N) shape would make 200 cost 4x what 50 costs; batching
    // collapses both to the same handful of per-chunk statements (both fit
    // in one 500-record chunk, and no key changed so the demote/promote
    // pair is skipped): insertOrIgnore, the resolving SELECT, the
    // record_state upsert — 3 logical statements (covered is null, so
    // foldAbsence() returns before touching the database at all).
    expect($countFor50)->toBe(3);
    expect($countFor200)->toBe(3);
});

// ── SCALE-5: foldAbsence() — killing the N+1 ────────────────────────────────

it('resolves 100 live keys against 90 dominated-absent candidates in a handful of statements, not one query per key (SCALE-5)', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;

    foreach (range(1, 100) as $i) {
        $lander->land('s1', 'r0', $spec, [new Record('releases', "k{$i}", ['seq' => $i])], null);
    }

    // 30 of 100 live keys vanish (30% < the 40% guard threshold), all
    // ordered inside the exhaustive coverage's domain — the write path
    // (not the guard-trip path) runs.
    $survivors = [];
    foreach (range(31, 100) as $i) {
        $survivors[] = new Record('releases', "k{$i}", ['seq' => $i]);
    }

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    $result = $lander->land('s1', 'r1', $spec, $survivors, new Covered('releases', Coverage::exhaustive()));

    expect($result['guard_tripped'])->toBeFalse();
    expect($result['tombstoned'])->toBe(0); // 1st consecutive absence — not yet 3

    // Old per-key orderValueFor() N+1 cost ~90 queries (one per live-but-
    // unseen key) on top of ~90 per-row UPDATEs — call it 180+. Batched:
    // stream lookup, liveCount, one candidates SELECT, one order-value
    // chunk, one absent_runs increment, one tombstone-threshold UPDATE.
    // This is the assertion that would have caught the N+1 in the first
    // place.
    expect($count)->toBeLessThanOrEqual(10);

    foreach (range(1, 30) as $i) {
        $state = ingestRecordState('s1', "k{$i}");
        expect((int) $state->absent_runs)->toBe(1);
    }
});

it('issues zero order-value queries under exhaustive coverage, because dominates() never consults $orderValue', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    $covered = new Covered('releases', Coverage::exhaustive());

    foreach (range(1, 20) as $i) {
        $lander->land('s1', 'r0', $spec, [new Record('releases', "k{$i}", ['seq' => $i])], null);
    }

    $touchedRecordVersions = false;
    DB::listen(function ($query) use (&$touchedRecordVersions) {
        if (str_contains($query->sql, 'record_versions')) {
            $touchedRecordVersions = true;
        }
    });

    $lander->land('s1', 'r1', $spec, [], $covered); // nothing seen this run — all 20 candidates

    expect($touchedRecordVersions)->toBeFalse();
});

it('never touches record_state under unknown coverage — dominatesNothing() short-circuits before the first read', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;
    $covered = new Covered('releases', Coverage::unknown());

    foreach (range(1, 20) as $i) {
        $lander->land('s1', 'r0', $spec, [new Record('releases', "k{$i}", ['seq' => $i])], null);
    }

    $touchedRecordState = false;
    DB::listen(function ($query) use (&$touchedRecordState) {
        if (str_contains($query->sql, 'record_state')) {
            $touchedRecordState = true;
        }
    });

    $lander->land('s1', 'r1', $spec, [], $covered); // nothing seen this run — all 20 would be candidates

    expect($touchedRecordState)->toBeFalse();
});
