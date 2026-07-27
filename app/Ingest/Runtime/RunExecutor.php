<?php

namespace App\Ingest\Runtime;

use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Message\Bookmark;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Deferred;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Executes one run of one source: drains the connector's Messages, lands
 * them, and records the outcome. The connector decides nothing about
 * persistence; this class decides nothing about vendors. That split is what
 * lets a connector be tested with no database and this class be tested with
 * no network.
 *
 * Outcome rules that matter:
 *   - A stream that yields NO records and NO Unavailable is `ok` with zero
 *     records — but its Coverage still governs deletion, so "empty and
 *     exhaustive" is the only way a stream legitimately empties (C5).
 *   - `Deferred` releases the claim and reschedules; it is not a failure and
 *     must not look stranded.
 *   - A required-path violation is `shape`: the offending records are NOT
 *     landed, because landing malformed docs poisons every later projection.
 */
class RunExecutor
{
    public function __construct(private readonly Lander $lander) {}

    /**
     * @param  array<string, mixed>  $source  row from ingest.sources
     * @return array{outcome: string, run_id: string, streams: array<string, mixed>, retry_after: ?int}
     */
    public function execute(array $source, Connector $connector, Manifest $manifest, string $trigger = 'schedule'): array
    {
        $runId = (string) Str::uuid();

        DB::table('ingest.runs')->insert([
            'id' => $runId,
            'source_id' => $source['id'],
            'trigger' => $trigger,
            'started_at' => now(),
        ]);

        $totals = ['seen' => 0, 'changed' => 0, 'tombstoned' => 0];
        $streamOutcomes = [];
        $notes = [];
        $retryAfter = null;
        $worstOutcome = 'ok';

        foreach ($manifest->streams as $streamName => $spec) {
            $streamId = $this->ensureStream((string) $source['id'], $streamName);

            $suppressedUntil = DB::table('ingest.streams')->where('id', $streamId)->value('suppressed_until');
            if ($suppressedUntil !== null && strtotime((string) $suppressedUntil) > time()) {
                // Backpressure: a chronically broken stream stops asking.
                $streamOutcomes[$streamName] = 'suppressed';

                continue;
            }

            $pull = new Pull(
                identifier: (string) $source['identifier'],
                stream: $spec,
                cursor: $this->cursorFor($streamId),
                config: ['scope' => $source['scope'] ?? 'all', 'scope_n' => $source['scope_n'] ?? null],
                isClaimed: (bool) ($source['is_claimed'] ?? true),
            );

            $io = $this->ioFor($manifest, $runId, (string) $source['id']);

            try {
                $result = $this->drain($connector, $pull, $io);
            } catch (EffectRefused $e) {
                $streamOutcomes[$streamName] = 'budget_skipped';
                $worstOutcome = $this->worse($worstOutcome, 'budget_skipped');
                $this->recordStreamFailure($streamId, 'budget');

                continue;
            } catch (\Throwable $e) {
                report($e);
                $streamOutcomes[$streamName] = 'error';
                $worstOutcome = $this->worse($worstOutcome, 'error');
                $this->recordStreamFailure($streamId, class_basename($e));

                continue;
            }

            if ($result['deferred'] !== null) {
                $retryAfter = max($retryAfter ?? 0, $result['deferred']->retryAfterSeconds);
                $streamOutcomes[$streamName] = 'deferred';
                $worstOutcome = $this->worse($worstOutcome, 'deferred');

                continue;
            }

            if ($result['unavailable'] !== null) {
                $streamOutcomes[$streamName] = 'unavailable';
                $worstOutcome = $this->worse($worstOutcome, 'unavailable');
                $this->recordStreamFailure($streamId, 'unavailable');

                continue;
            }

            $shapeViolations = $this->checkShape($result['records'], $spec->requires);
            if ($shapeViolations !== []) {
                $streamOutcomes[$streamName] = 'shape';
                $worstOutcome = $this->worse($worstOutcome, 'shape');
                $this->recordStreamFailure($streamId, 'shape');
                DB::table('ingest.anomalies')->insert([
                    'id' => (string) Str::uuid(),
                    'stream_id' => $streamId,
                    'source_id' => $source['id'],
                    'run_id' => $runId,
                    'kind' => 'shape',
                    'severity' => 'warning',
                    'summary' => sprintf('%d record(s) missing required paths: %s', count($shapeViolations), implode(', ', array_slice($spec->requires, 0, 5))),
                    'detail' => json_encode(['violations' => array_slice($shapeViolations, 0, 20)]),
                    'detected_at' => now(),
                ]);

                continue;
            }

            $landed = $this->lander->land(
                streamId: $streamId,
                runId: $runId,
                spec: $spec,
                records: $result['records'],
                covered: $result['covered'],
                redactions: $manifest->redactionsFor($pull->isClaimed),
            );

            $totals['seen'] += $landed['seen'];
            $totals['changed'] += $landed['changed'];
            $totals['tombstoned'] += $landed['tombstoned'];
            $streamOutcomes[$streamName] = $landed['guard_tripped'] ? 'guard_tripped' : 'ok';

            if ($result['bookmark'] !== null) {
                DB::table('ingest.streams')->where('id', $streamId)->update([
                    'cursor' => json_encode($result['bookmark']->cursor),
                    'updated_at' => now(),
                ]);
            }

            $this->recordStreamSuccess($streamId, $result['covered']);
            $this->lander->clearGuardIfRecovered($streamId);
            $notes = array_merge($notes, array_map(fn (Note $n) => ['code' => $n->code, 'message' => $n->message], $result['notes']));
        }

        DB::table('ingest.runs')->where('id', $runId)->update([
            'finished_at' => now(),
            'outcome' => $worstOutcome,
            'records_seen' => $totals['seen'],
            'records_changed' => $totals['changed'],
            'records_tombstoned' => $totals['tombstoned'],
            'detail' => json_encode(['streams' => $streamOutcomes, 'notes' => array_slice($notes, 0, 20)]),
        ]);

        return ['outcome' => $worstOutcome, 'run_id' => $runId, 'streams' => $streamOutcomes, 'retry_after' => $retryAfter];
    }

    /**
     * @return array{records: list<Record>, covered: ?Covered, bookmark: ?Bookmark, notes: list<Note>, deferred: ?Deferred, unavailable: ?Unavailable}
     */
    private function drain(Connector $connector, Pull $pull, Io $io): array
    {
        $out = ['records' => [], 'covered' => null, 'bookmark' => null, 'notes' => [], 'deferred' => null, 'unavailable' => null];

        foreach ($connector->pull($pull, $io) as $message) {
            match (true) {
                $message instanceof Record => $out['records'][] = $message,
                $message instanceof Covered => $out['covered'] = $message,
                $message instanceof Bookmark => $out['bookmark'] = $message,
                $message instanceof Note => $out['notes'][] = $message,
                $message instanceof Deferred => $out['deferred'] = $message,
                $message instanceof Unavailable => $out['unavailable'] = $message,
                default => Log::warning('ingest.unknown_message', ['class' => $message::class]),
            };

            // A Deferred or Unavailable ends the stream: nothing after it can
            // be trusted to be complete.
            if ($out['deferred'] !== null || $out['unavailable'] !== null) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<Record>  $records
     * @param  list<string>  $requires
     * @return list<string> keys of records missing a required path
     */
    private function checkShape(array $records, array $requires): array
    {
        if ($requires === []) {
            return [];
        }

        $violations = [];
        foreach ($records as $record) {
            foreach ($requires as $path) {
                if (! $this->hasPath($record->doc, explode('.', $path))) {
                    $violations[] = $record->key;
                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $segments
     */
    private function hasPath(array $doc, array $segments): bool
    {
        $cursor = $doc;
        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor !== null && $cursor !== '';
    }

    private function ensureStream(string $sourceId, string $streamName): string
    {
        $existing = DB::table('ingest.streams')
            ->where('source_id', $sourceId)
            ->where('stream_name', $streamName)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();
        DB::table('ingest.streams')->insert([
            'id' => $id,
            'source_id' => $sourceId,
            'stream_name' => $streamName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function cursorFor(string $streamId): array
    {
        $cursor = DB::table('ingest.streams')->where('id', $streamId)->value('cursor');
        if ($cursor === null) {
            return [];
        }
        $decoded = is_string($cursor) ? json_decode($cursor, true) : $cursor;

        return is_array($decoded) ? $decoded : [];
    }

    private function recordStreamSuccess(string $streamId, ?Covered $covered): void
    {
        DB::table('ingest.streams')->where('id', $streamId)->update([
            'health' => 'ok',
            'consecutive_failures' => 0,
            'suppressed_until' => null,
            'coverage' => $covered !== null ? json_encode($covered->coverage->toArray()) : null,
            'run_seq' => DB::raw('run_seq + 1'),
            'updated_at' => now(),
        ]);
    }

    /**
     * Per-stream suppression as backpressure: a stream that keeps failing
     * backs off 1h → 7d rather than retrying every cycle forever. Its healthy
     * siblings are unaffected — that is the whole point of failing per stream
     * instead of per source.
     */
    private function recordStreamFailure(string $streamId, string $errorClass): void
    {
        $failures = (int) DB::table('ingest.streams')->where('id', $streamId)->value('consecutive_failures') + 1;

        $backoffMinutes = min(10080, 60 * (2 ** min(7, max(0, $failures - 1))));

        DB::table('ingest.streams')->where('id', $streamId)->update([
            'health' => $errorClass === 'budget' ? 'degraded' : 'unavailable',
            'consecutive_failures' => $failures,
            'suppressed_until' => $failures >= 3 ? now()->addMinutes($backoffMinutes) : null,
            'updated_at' => now(),
        ]);
    }

    private function worse(string $current, string $candidate): string
    {
        $rank = ['ok' => 0, 'not_modified' => 1, 'deferred' => 2, 'budget_skipped' => 3, 'degraded' => 4, 'unavailable' => 5, 'shape' => 6, 'error' => 7];

        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }

    private function ioFor(Manifest $manifest, string $runId, string $sourceId): Io
    {
        return new HttpIo(
            manifest: $manifest,
            fetcher: app(SafeUrlFetcher::class),
            ledger: app(EffectLedger::class),
            runId: $runId,
            sourceId: $sourceId,
        );
    }
}
