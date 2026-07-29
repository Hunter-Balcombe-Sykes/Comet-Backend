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
