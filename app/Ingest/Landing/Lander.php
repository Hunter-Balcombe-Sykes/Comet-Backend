<?php

namespace App\Ingest\Landing;

use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $seen = 0;
        $changed = 0;
        $seenKeys = [];

        foreach ($records as $record) {
            $doc = Redactor::apply($record->doc, $redactions);
            $hash = DocHasher::hash($doc, $spec->volatile);
            $seenKeys[] = $record->key;
            $seen++;

            // DO NOTHING on conflict: an identical doc is not an event.
            $inserted = DB::table('ingest.record_versions')->insertOrIgnore([
                'stream_id' => $streamId,
                'key' => $record->key,
                'doc_hash' => $hash,
                'doc' => json_encode($doc),
                'first_seen_run' => $runId,
                'first_seen_at' => now(),
                'is_current' => true,
            ]);

            if ($inserted > 0) {
                $changed++;
                // Demote the previous current version for this key.
                DB::table('ingest.record_versions')
                    ->where('stream_id', $streamId)
                    ->where('key', $record->key)
                    ->where('doc_hash', '!=', $hash)
                    ->update(['is_current' => false]);
            }

            $versionId = DB::table('ingest.record_versions')
                ->where('stream_id', $streamId)
                ->where('key', $record->key)
                ->where('doc_hash', $hash)
                ->value('id');

            DB::table('ingest.record_state')->upsert([[
                'stream_id' => $streamId,
                'key' => $record->key,
                'current_version_id' => $versionId,
                'last_seen_run' => $runId,
                'last_seen_at' => now(),
                // Reappearance clears absence completely — a key that came
                // back was never really gone.
                'absent_since' => null,
                'absent_runs' => 0,
                'tombstoned_at' => null,
            ]], ['stream_id', 'key'], ['current_version_id', 'last_seen_run', 'last_seen_at', 'absent_since', 'absent_runs', 'tombstoned_at']);
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

        $live = DB::table('ingest.record_state')
            ->where('stream_id', $streamId)
            ->whereNull('tombstoned_at')
            ->get(['key', 'current_version_id', 'absent_runs']);

        $seenLookup = array_flip($seenKeys);
        $dominatedAbsent = [];

        foreach ($live as $row) {
            if (isset($seenLookup[$row->key])) {
                continue;
            }
            $orderValue = $this->orderValueFor($streamId, $row->key, $spec);
            if ($covered->dominates($row->key, $orderValue)) {
                $dominatedAbsent[] = $row;
            }
        }

        // Delete-guard: an implausible share vanishing at once is far more
        // likely to be a login wall than a real bulk deletion.
        $liveCount = $live->count();
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

        $tombstoned = 0;
        foreach ($dominatedAbsent as $row) {
            $runs = (int) $row->absent_runs + 1;
            $update = ['absent_runs' => $runs, 'absent_since' => DB::raw('COALESCE(absent_since, now())')];

            if ($runs >= self::TOMBSTONE_RUNS) {
                $update['tombstoned_at'] = now();
                $tombstoned++;
            }

            DB::table('ingest.record_state')
                ->where('stream_id', $streamId)
                ->where('key', $row->key)
                ->update($update);
        }

        return ['tombstoned' => $tombstoned, 'guard_tripped' => false];
    }

    /** The value Coverage reasons about for this key (its order field). */
    private function orderValueFor(string $streamId, string $key, StreamSpec $spec): mixed
    {
        if ($spec->orderField === null) {
            return null;
        }

        $doc = DB::table('ingest.record_versions')
            ->where('stream_id', $streamId)
            ->where('key', $key)
            ->where('is_current', true)
            ->value('doc');

        if ($doc === null) {
            return null;
        }

        $decoded = is_string($doc) ? json_decode($doc, true) : $doc;

        return is_array($decoded) ? ($decoded[$spec->orderField] ?? null) : null;
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
