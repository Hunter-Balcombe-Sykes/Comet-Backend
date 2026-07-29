<?php

namespace App\Ingest\Landing;

use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns a connector's Messages into durable rows. Two properties matter more
 * than anything else here:
 *
 *   1. UNCHANGED CONTENT WRITES NOTHING. The content-addressed unique index
 *      plus DO NOTHING means re-landing an identical doc costs one statement
 *      and zero rows, which is what makes frequent polling affordable and
 *      "did anything change?" free to answer.
 *
 *   2. ABSENCE IS NOT DELETION. A key only accrues absence when the run's
 *      Coverage DOMINATES it — i.e. we can honestly say we would have seen
 *      it. Everything else is silence, and silence means nothing.
 */
class Lander
{
    /** Consecutive dominated absences before a key is tombstoned. */
    private const TOMBSTONE_RUNS = 3;

    /**
     * Share of a stream's live keys that may vanish in one run before the
     * delete-guard trips. A login wall or a vendor outage looks exactly like
     * "everything was deleted", so a large drop must stop deletion and ask a
     * human rather than act.
     */
    private const GUARD_THRESHOLD = 0.4;

    /**
     * @param  list<Record>  $records
     * @return array{seen: int, changed: int, tombstoned: int, guard_tripped: bool}
     */
    public function land(
        string $streamId,
        string $runId,
        StreamSpec $spec,
        array $records,
        ?Covered $covered,
        array $redactions = [],
    ): array {
        // Include duplicates: a run that carries the same key twice really
        // did see it twice, and $seenKeys is array_flip'ed by foldAbsence()
        // so the duplicate is harmless there.
        $seen = count($records);
        $seenKeys = [];
        foreach ($records as $record) {
            $seenKeys[] = $record->key;
        }

        $changed = 0;
        $chunkSize = max(1, (int) config('partna.ingest.land_chunk', 500));

        foreach (array_chunk($records, $chunkSize) as $chunk) {
            try {
                // Fast path (SCALE-4): one chunk transaction lands up to
                // $chunkSize records in a handful of statements instead of
                // one round-trip-heavy transaction per record.
                $changed += DB::transaction(fn () => $this->landChunk($streamId, $runId, $spec, $chunk, $redactions));
            } catch (Throwable $e) {
                // A genuine per-record failure can still occur — e.g. a doc
                // containing a literal NUL byte, rejected by jsonb with
                // 22P05 (a plausible shape for a scraped caption). That must
                // not cost the OTHER records in the chunk their durable log
                // entry (Unit 2's DINT-9 objection to a batch-wide
                // transaction). By the time we're here the chunk transaction
                // has already fully rolled back — this catch sits OUTSIDE
                // DB::transaction(), so no caught-and-recovered failure ever
                // runs inside an open Postgres transaction, which is what
                // poisons it with 25P02 (this repo has shipped that savepoint
                // bug three times; see ItemSlugAllocatorSavepointTest).
                // Falling back to the per-record path isolates the one bad
                // record and lands the rest durably, just slower.
                report($e);
                $changed += $this->landRecordsIndividually($streamId, $runId, $spec, $chunk, $redactions);
            }
        }

        $absence = $this->foldAbsence($streamId, $spec, $covered, $seenKeys);

        return [
            'seen' => $seen,
            'changed' => $changed,
            'tombstoned' => $absence['tombstoned'],
            'guard_tripped' => $absence['guard_tripped'],
        ];
    }

    /**
     * Batched landing for one chunk (SCALE-4). Must run inside a single
     * DB::transaction() supplied by the caller, and must stay free of any
     * try/catch of its own — a caught-and-recovered failure INSIDE an open
     * Postgres transaction poisons it with 25P02 (see land()'s catch for the
     * full reasoning). Any failure here is meant to escape and roll the
     * whole chunk back so the caller's per-record fallback can run clean.
     *
     * @param  list<Record>  $records
     * @return int the number of keys whose CURRENT version moved this chunk
     */
    private function landChunk(string $streamId, string $runId, StreamSpec $spec, array $records, array $redactions): int
    {
        // Two de-duplications, on two DIFFERENT keys — subtle and load-
        // bearing. $versionRows is keyed by (key, doc_hash): two different
        // hashes for the same key in one run are two legitimate durable log
        // rows, and both must be inserted. $winners is keyed by key alone,
        // last occurrence wins: record_state has exactly one row per key, so
        // only the LAST record for a given key in this chunk determines its
        // final current-version and absence state — exactly what the
        // per-record loop converges to when it processes the same key twice
        // in one run.
        $versionRows = [];
        $winners = [];
        $hashesSeen = [];

        foreach ($records as $record) {
            $doc = Redactor::apply($record->doc, $redactions);
            $hash = DocHasher::hash($doc, $spec->volatile);

            $versionRows["{$record->key}\0{$hash}"] = [
                'stream_id' => $streamId,
                'key' => $record->key,
                'doc_hash' => $hash,
                'doc' => json_encode($doc),
                'first_seen_run' => $runId,
                'first_seen_at' => now(),
                'is_current' => false,
            ];
            $hashesSeen[$hash] = true;
            $winners[$record->key] = $hash;
        }

        if ($versionRows === []) {
            return 0;
        }

        // 1. Land the version. is_current is deliberately FALSE here, same
        //    as the per-record path — currency is decided below, not by
        //    whether the row was new. DO NOTHING on conflict: an identical
        //    doc is not an event. Duplicate (key, doc_hash) pairs WITHIN
        //    this one multi-row INSERT are harmless under DO NOTHING (unlike
        //    the record_state upsert below, which uses DO UPDATE).
        DB::table('ingest.record_versions')->insertOrIgnore(array_values($versionRows));

        // 2. ONE round trip resolves id + currency for every winning
        //    (key, doc_hash) pair in the chunk. `doc_hash IN (:hashes)` uses
        //    every distinct hash the chunk carried (a superset of the
        //    winners') — widening it to only the winners' hashes would save
        //    nothing once this is already one round trip, and the PHP index
        //    below is keyed on the (key, doc_hash) pair regardless, so a
        //    pulled-in non-winner row is simply never looked up.
        //    `OR is_current` pulls the incumbent too, exactly as the
        //    per-record path does, so a key with no is_current row at all
        //    still converges. Keep `CASE WHEN ... THEN 1 ELSE 0 END`
        //    verbatim — pdo_pgsql returns boolean columns as the STRINGS
        //    't'/'f', and `(bool) 'f'` is `true` in PHP.
        $rows = DB::table('ingest.record_versions')
            ->where('stream_id', $streamId)
            ->whereIn('key', array_keys($winners))
            ->where(fn ($q) => $q->whereIn('doc_hash', array_keys($hashesSeen))->orWhere('is_current', true))
            ->selectRaw('id, key, doc_hash, CASE WHEN is_current THEN 1 ELSE 0 END AS is_current_flag')
            ->get();

        $index = [];
        foreach ($rows as $row) {
            $index["{$row->key}\0{$row->doc_hash}"] = $row;
        }

        $changed = 0;
        $changedKeys = [];
        $changedWinnerIds = [];
        // LIFE-4: split by whether THIS transaction promoted the winner.
        // $movedStateRows keys are ones we demote/promote below plus
        // idx_record_versions_one_current provably make ours at commit — the
        // pointer is ours to write. $unmovedStateRows keys are ones we left
        // alone (already current); nothing here arbitrated who owns the
        // pointer for those, so current_version_id must not travel in that
        // payload (see the upsert below).
        $movedStateRows = [];
        $unmovedStateRows = [];

        foreach ($winners as $key => $hash) {
            $landedRow = $index["{$key}\0{$hash}"] ?? null;

            if ($landedRow === null) {
                // Should be structurally impossible — every winner's
                // (key, doc_hash) pair was just insertOrIgnore'd above and
                // is queried for by the same pair. Fail loudly rather than
                // let a null property access fatal somewhere below.
                throw new RuntimeException("Lander::landChunk could not resolve the landed version row for stream {$streamId}, key {$key} — the winning (key, doc_hash) pair was not returned by the resolving SELECT.");
            }

            $versionId = (int) $landedRow->id;
            $wasCurrent = ((int) $landedRow->is_current_flag) === 1;

            $row = [
                'stream_id' => $streamId,
                'key' => $key,
                'current_version_id' => $versionId,
                'last_seen_run' => $runId,
                'last_seen_at' => now(),
                // Reappearance clears absence completely — a key that came
                // back was never really gone.
                'absent_since' => null,
                'absent_runs' => 0,
                'tombstoned_at' => null,
            ];

            // 3. Only when the CURRENT version actually moved.
            if (! $wasCurrent) {
                $changed++;
                $changedKeys[] = $key;
                $changedWinnerIds[] = $versionId;
                $movedStateRows[] = $row;
            } else {
                $unmovedStateRows[] = $row;
            }
        }

        // 4. Demote-then-promote, in that order — STILL two ordered
        //    statements, only widened from per-key to per-chunk, never
        //    merged into one `SET is_current = (id = ...)`. For a CHANGED
        //    key the winner is by definition not current, so demoting every
        //    is_current row for the changed keys cannot touch a winner; a
        //    single combined UPDATE could transiently violate
        //    idx_record_versions_one_current mid-statement, and that partial
        //    unique index cannot be made DEFERRABLE (only unique
        //    constraints can be, and those cannot be partial). Skipped
        //    entirely when nothing changed — a pure unchanged re-land chunk
        //    costs nothing here.
        if ($changedKeys !== []) {
            DB::table('ingest.record_versions')
                ->where('stream_id', $streamId)
                ->whereIn('key', $changedKeys)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            DB::table('ingest.record_versions')
                ->where('stream_id', $streamId)
                ->whereIn('id', $changedWinnerIds)
                ->update(['is_current' => true]);
        }

        // 5. Two multi-row record_state upserts, disjoint by key (every key
        //    in $winners lands in exactly one of $movedStateRows or
        //    $unmovedStateRows), so both stay 21000-safe for the same reason
        //    the single payload was: at most one row per key, per payload.
        //    Keys whose currency THIS transaction moved: the demote/promote
        //    pair above plus idx_record_versions_one_current make our winner
        //    provably the is_current row at commit, so the pointer is ours
        //    to write.
        if ($movedStateRows !== []) {
            DB::table('ingest.record_state')->upsert(
                $movedStateRows,
                ['stream_id', 'key'],
                ['current_version_id', 'last_seen_run', 'last_seen_at', 'absent_since', 'absent_runs', 'tombstoned_at']
            );
        }

        // LIFE-4: keys we did NOT promote. $versionId here came from a
        // SELECT this transaction never fenced — a concurrent lander can
        // have moved currency since, and there is no one-current index
        // violation to catch it because we promote nothing. current_version_id
        // is therefore OMITTED from the update column list: on an existing
        // row the incumbent pointer (written by whoever actually moved
        // currency) survives; on a brand-new row the INSERT arm still
        // supplies it, which is correct because there is no incumbent to
        // clobber.
        if ($unmovedStateRows !== []) {
            DB::table('ingest.record_state')->upsert(
                $unmovedStateRows,
                ['stream_id', 'key'],
                ['last_seen_run', 'last_seen_at', 'absent_since', 'absent_runs', 'tombstoned_at']
            );
        }

        return $changed;
    }

    /**
     * Per-record landing — today's original loop, retained VERBATIM except
     * the LIFE-4 pointer guard below, which must be identical in both paths
     * or the chunk=1/chunk=500 parity test and this method's role as
     * landChunk()'s fallback both diverge. Serves two roles: the
     * poison-record fallback for landChunk() (isolates one bad record
     * without costing the rest of the chunk its durable log entry), and the
     * golden reference the chunked path is diffed against in tests. Do not
     * otherwise "simplify" this to match landChunk() — its value is in
     * staying exactly what it always was.
     *
     * @param  list<Record>  $records
     * @return int the number of keys whose CURRENT version moved
     */
    private function landRecordsIndividually(string $streamId, string $runId, StreamSpec $spec, array $records, array $redactions): int
    {
        $changed = 0;

        foreach ($records as $record) {
            $doc = Redactor::apply($record->doc, $redactions);
            $hash = DocHasher::hash($doc, $spec->volatile);

            DB::transaction(function () use ($streamId, $runId, $record, $doc, $hash, &$changed) {
                // 1. Land the version. is_current is deliberately FALSE here:
                //    whether this doc is current is decided below, not by
                //    whether the row was new. DO NOTHING on conflict: an
                //    identical doc is not an event.
                DB::table('ingest.record_versions')->insertOrIgnore([
                    'stream_id' => $streamId,
                    'key' => $record->key,
                    'doc_hash' => $hash,
                    'doc' => json_encode($doc),
                    'first_seen_run' => $runId,
                    'first_seen_at' => now(),
                    'is_current' => false,
                ]);

                // 2. ONE round trip resolves both facts: this hash's version
                //    id, and whether it was ALREADY the current one. `OR
                //    is_current` pulls the incumbent too, so a landing that
                //    finds no is_current row at all (first landing, or a key
                //    desynced by the pre-fix bug) still converges.
                //    Currency is decided in SQL, not PHP: a plain `->get(['is_current'])`
                //    round-trips the raw driver value, and pdo_pgsql returns boolean
                //    columns as the STRINGS 't'/'f' — `(bool) 'f'` is `true` in PHP, so
                //    casting the column in PHP silently makes every row look current.
                //    `CASE WHEN ... THEN 1 ELSE 0 END` forces the driver to hand back an
                //    integer on both Postgres and the SQLite test mirror.
                $rows = DB::table('ingest.record_versions')
                    ->where('stream_id', $streamId)
                    ->where('key', $record->key)
                    ->where(fn ($q) => $q->where('doc_hash', $hash)->orWhere('is_current', true))
                    ->selectRaw('id, doc_hash, CASE WHEN is_current THEN 1 ELSE 0 END AS is_current_flag')
                    ->get();

                $landedRow = $rows->firstWhere('doc_hash', $hash);
                $versionId = (int) $landedRow->id;
                $wasCurrent = ((int) $landedRow->is_current_flag) === 1;

                // 3. Only when the CURRENT version actually moved.
                //    Demote-then-promote, in that order — a single UPDATE
                //    covering both rows can transiently violate the
                //    one-current partial unique index mid-statement.
                if (! $wasCurrent) {
                    $changed++;

                    DB::table('ingest.record_versions')
                        ->where('stream_id', $streamId)->where('key', $record->key)
                        ->where('doc_hash', '!=', $hash)->where('is_current', true)
                        ->update(['is_current' => false]);

                    DB::table('ingest.record_versions')
                        ->where('stream_id', $streamId)->where('key', $record->key)
                        ->where('doc_hash', $hash)
                        ->update(['is_current' => true]);
                }

                // 4. record_state upsert. LIFE-4: current_version_id is only
                //    in the update column list when THIS transaction moved
                //    currency (! $wasCurrent) — same guard as landChunk(),
                //    for the same reason: when $wasCurrent, $versionId came
                //    from a SELECT this transaction never fenced, and there
                //    is no promote here to arbitrate a concurrent lander's
                //    pointer write.
                $updateColumns = ['last_seen_run', 'last_seen_at', 'absent_since', 'absent_runs', 'tombstoned_at'];
                if (! $wasCurrent) {
                    $updateColumns[] = 'current_version_id';
                }

                DB::table('ingest.record_state')->upsert([[
                    'stream_id' => $streamId,
                    'key' => $record->key,
                    'current_version_id' => $versionId,
                    'last_seen_run' => $runId,
                    'last_seen_at' => now(),
                    // Reappearance clears absence completely — a key that
                    // came back was never really gone.
                    'absent_since' => null,
                    'absent_runs' => 0,
                    'tombstoned_at' => null,
                ]], ['stream_id', 'key'], $updateColumns);
            });
        }

        return $changed;
    }

    /**
     * @param  list<string>  $seenKeys
     * @return array{tombstoned: int, guard_tripped: bool}
     */
    private function foldAbsence(string $streamId, StreamSpec $spec, ?Covered $covered, array $seenKeys): array
    {
        // No coverage claim, or a stream that may never delete: absence means
        // nothing at all and we stop here.
        if ($covered === null || ! $spec->mayDelete()) {
            return ['tombstoned' => 0, 'guard_tripped' => false];
        }

        $stream = DB::table('ingest.streams')->where('id', $streamId)->first();
        if ($stream !== null && $stream->guard_tripped_at !== null) {
            // Deletion stays frozen until the anomaly is resolved.
            return ['tombstoned' => 0, 'guard_tripped' => true];
        }

        $coverage = $covered->coverage;

        // SCALE-5: UnknownCoverage can never dominate anything, so nothing
        // can accrue absence and the guard can't trip — skip record_state
        // entirely rather than materialising it for no reason.
        if ($coverage->dominatesNothing()) {
            return ['tombstoned' => 0, 'guard_tripped' => false];
        }

        // $liveCount is only needed for the guard ratio — one COUNT(*),
        // independent of how many candidates come back below.
        $liveCount = DB::table('ingest.record_state')
            ->where('stream_id', $streamId)
            ->whereNull('tombstoned_at')
            ->count();

        // SCALE-5: push "not seen this run" into SQL so a healthy exhaustive
        // re-landing pulls zero candidate rows, instead of materialising
        // every live key and filtering seen ones out in PHP.
        $candidates = $this->liveNotSeen($streamId, $seenKeys);

        $dominatedAbsent = [];
        foreach (array_chunk($candidates, 500) as $chunk) {
            // SCALE-5: one batched order-value read per chunk of candidates,
            // replacing the old per-key orderValueFor() N+1 (one query per
            // live-but-unseen key, per run, each pulling a whole doc).
            $orderValues = $coverage->needsOrderValue()
                ? $this->orderValuesFor($streamId, array_map(fn ($row) => $row->key, $chunk), $spec)
                : [];

            foreach ($chunk as $row) {
                $orderValue = $orderValues[$row->key] ?? null;
                if ($coverage->dominates($row->key, $orderValue)) {
                    $dominatedAbsent[] = $row;
                }
            }
        }

        // #WHK-2 residual: a healthy run with nothing dominated-absent opens
        // no transaction at all. The guard can never trip on zero candidates
        // (it needs count >= 5), so this is safe to check before the branch
        // below.
        if ($dominatedAbsent === []) {
            return ['tombstoned' => 0, 'guard_tripped' => false];
        }

        // #WHK-2 residual: everything from here down is one all-or-nothing
        // unit. Un-transacted, a crash or statement timeout mid-fold could
        // leave chunk 1's keys a run closer to tombstoning than chunk 2's,
        // or leave guard_tripped_at set with no anomaly row explaining it
        // (or the anomaly filed with the guard never actually tripped) — an
        // operator-visible inconsistency in the guard's own bookkeeping.
        // Bounded in practice: the guard trips at 40% of live keys, so a
        // fold that reaches the write phase below stays well under the
        // stream's population. NO try/catch in this closure — a caught-and-
        // recovered failure inside an open Postgres transaction poisons it
        // with 25P02 (see land()'s catch for the full reasoning); any
        // failure here must escape and roll the whole fold back.
        return DB::transaction(function () use ($streamId, $dominatedAbsent, $liveCount) {
            // Delete-guard: an implausible share vanishing at once is far
            // more likely to be a login wall than a real bulk deletion.
            if ($liveCount > 0 && (count($dominatedAbsent) / $liveCount) >= self::GUARD_THRESHOLD && count($dominatedAbsent) >= 5) {
                DB::table('ingest.streams')->where('id', $streamId)->update(['guard_tripped_at' => now(), 'updated_at' => now()]);
                DB::table('ingest.anomalies')->insert([
                    'id' => (string) Str::uuid(),
                    'stream_id' => $streamId,
                    'kind' => 'delete_guard',
                    'severity' => 'critical',
                    'summary' => sprintf('%d of %d records vanished in one run — deletion frozen', count($dominatedAbsent), $liveCount),
                    'detail' => json_encode(['absent' => count($dominatedAbsent), 'live' => $liveCount]),
                    'detected_at' => now(),
                ]);

                return ['tombstoned' => 0, 'guard_tripped' => true];
            }

            // SCALE-5: batched absence/tombstone writes — two statements per
            // chunk of dominated-absent keys instead of one UPDATE per row.
            // Order matters: increment absent_runs first, THEN tombstone
            // only rows that crossed the threshold as a result.
            $tombstoned = 0;
            foreach (array_chunk($dominatedAbsent, 500) as $chunk) {
                $keys = array_map(fn ($row) => $row->key, $chunk);

                DB::table('ingest.record_state')
                    ->where('stream_id', $streamId)
                    ->whereIn('key', $keys)
                    ->update([
                        'absent_runs' => DB::raw('absent_runs + 1'),
                        'absent_since' => DB::raw('COALESCE(absent_since, now())'),
                    ]);

                $tombstoned += DB::table('ingest.record_state')
                    ->where('stream_id', $streamId)
                    ->whereIn('key', $keys)
                    ->whereNull('tombstoned_at')
                    ->where('absent_runs', '>=', self::TOMBSTONE_RUNS)
                    ->update(['tombstoned_at' => now()]);
            }

            return ['tombstoned' => $tombstoned, 'guard_tripped' => false];
        });
    }

    /**
     * Live (non-tombstoned) record_state keys not seen this run — the
     * candidate set foldAbsence() must weigh against Coverage.
     *
     * @param  list<string>  $seenKeys
     * @return list<object{key: string, current_version_id: mixed, absent_runs: int}>
     */
    private function liveNotSeen(string $streamId, array $seenKeys): array
    {
        // whereIn/whereNotIn bind one placeholder per key. Past ~1,000 keys
        // the bind list itself becomes the cost SCALE-7 names — fall back to
        // a chunked, key-ordered keyset walk with the seen-filter in PHP.
        if (count($seenKeys) <= 1000) {
            return DB::table('ingest.record_state')
                ->where('stream_id', $streamId)
                ->whereNull('tombstoned_at')
                ->whereNotIn('key', $seenKeys)
                ->get(['key', 'current_version_id', 'absent_runs'])
                ->all();
        }

        $seenLookup = array_flip($seenKeys);
        $candidates = [];
        $lastKey = null;

        while (true) {
            $page = DB::table('ingest.record_state')
                ->where('stream_id', $streamId)
                ->whereNull('tombstoned_at')
                ->when($lastKey !== null, fn ($q) => $q->where('key', '>', $lastKey))
                ->orderBy('key')
                ->limit(500)
                ->get(['key', 'current_version_id', 'absent_runs']);

            if ($page->isEmpty()) {
                break;
            }

            foreach ($page as $row) {
                if (! isset($seenLookup[$row->key])) {
                    $candidates[] = $row;
                }
            }

            $lastKey = $page->last()->key;

            if ($page->count() < 500) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * Batched replacement for the old per-key orderValueFor() N+1 (SCALE-5):
     * one query per chunk of candidate keys, not one query per key.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed> order value keyed by record key
     */
    private function orderValuesFor(string $streamId, array $keys, StreamSpec $spec): array
    {
        if ($spec->orderField === null || $keys === []) {
            return [];
        }

        $rows = DB::table('ingest.record_versions')
            ->where('stream_id', $streamId)
            ->whereIn('key', $keys)
            ->where('is_current', true)
            ->get(['key', 'doc']);

        $values = [];
        foreach ($rows as $row) {
            // Preserve the driver-handling verbatim: doc may already be an
            // array (test mirrors) or a JSON string (pdo_pgsql).
            $decoded = is_string($row->doc) ? json_decode($row->doc, true) : $row->doc;
            $values[$row->key] = is_array($decoded) ? ($decoded[$spec->orderField] ?? null) : null;
        }

        return $values;
    }

    /**
     * The guard's other release valve: a stream whose population recovered on
     * its own was a blip, not a deletion. Called after a healthy run.
     */
    public function clearGuardIfRecovered(string $streamId): bool
    {
        $stream = DB::table('ingest.streams')->where('id', $streamId)->first();
        if ($stream === null || $stream->guard_tripped_at === null) {
            return false;
        }

        $absent = DB::table('ingest.record_state')
            ->where('stream_id', $streamId)
            ->whereNull('tombstoned_at')
            ->whereNotNull('absent_since')
            ->count();

        if ($absent > 0) {
            return false;
        }

        DB::table('ingest.streams')->where('id', $streamId)->update(['guard_tripped_at' => null, 'updated_at' => now()]);
        DB::table('ingest.anomalies')
            ->where('stream_id', $streamId)
            ->where('kind', 'delete_guard')
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_by' => 'system', 'resolution' => 'population recovered']);

        return true;
    }
}
