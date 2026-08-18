<?php

namespace App\Ingest\Runtime;

use App\Exceptions\Ingest\UnknownConnectorMessageException;
use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Message\Bookmark;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Deferred;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\ProjectionWriter;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\DB;
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
    public function __construct(
        private readonly Lander $lander,
        private readonly ProjectionWriter $projections,
    ) {}

    /**
     * @param  array<string, mixed>  $source  row from ingest.sources
     * @return array{outcome: string, run_id: string, streams: array<string, mixed>, retry_after: ?int}
     */
    public function execute(array $source, Connector $connector, Manifest $manifest, string $trigger = 'schedule'): array
    {
        $runId = (string) Str::uuid();

        // A previous run of this source that never finished — the worker was
        // killed, the job timed out (RunSourceJob::$timeout), the box rebooted
        // — leaves its ingest.runs row open AND, worse, a half-written
        // projection: source items and identity keys land per record, the
        // facets in a later pass, so a kill in between leaves items with no
        // headline, link or date. The next run then saw "0 changed" and
        // projected nothing, so those blanks lived forever (F25, session 3:
        // 200 blank Song Exploder episodes). Close the abandoned rows and
        // make THIS run project regardless of change.
        $abandoned = DB::table('ingest.runs')
            ->where('source_id', $source['id'])
            ->whereNull('finished_at')
            ->update([
                'finished_at' => now(),
                // 'error' is what runs_outcome_check admits; the class says why.
                'outcome' => 'error',
                'error_class' => 'RunAbandoned',
            ]);
        // RunSourceJob::failed() closes the row itself when it gets the chance
        // (a job timeout does reach failed()), so an 'error' latest run is
        // the same signal seen from the other side.
        $latestOutcome = DB::table('ingest.runs')
            ->where('source_id', $source['id'])
            ->whereNotNull('finished_at')
            ->orderByDesc('started_at')
            ->value('outcome');
        $forceProject = $abandoned > 0 || $latestOutcome === 'error';

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
                config: [
                    'scope' => $source['scope'] ?? 'all',
                    'scope_n' => $source['scope_n'] ?? null,
                    'selection_ref' => $source['selection_ref'] ?? null,
                ],
                isClaimed: $this->isClaimed($source),
            );

            $userId = $source['user_id'] ?? null;
            $io = $this->ioFor($manifest, $runId, (string) $source['id'], $userId === null ? null : (string) $userId);

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

            // Landing → Projection, in the same run (plan §4): content rows
            // exist the moment records land, not on some later sweep. Only
            // when something moved — an unchanged run has nothing to project
            // — unless the previous run was abandoned mid-projection (above);
            // and never fatal to the fetch: the record log is durable, so a
            // projection bug is recoverable by `ingest:project` after a fix.
            if (($landed['changed'] > 0 || $landed['tombstoned'] > 0 || $forceProject)
                && ProjectorRegistry::has((string) $source['source_key'], $streamName)) {
                try {
                    $this->projections->projectStream($source, $streamId, $streamName);
                } catch (\Throwable $e) {
                    report($e);
                    // JOB-4: a projection failure must move the run outcome off 'ok' —
                    // the landing succeeded but the derived content it feeds did not,
                    // so 'ok' would silently hide it. 'degraded' (worse() rank 4) is
                    // between budget_skipped and unavailable: worse than an
                    // unexceptional run, but not as bad as a stream that failed to
                    // land or fetch at all.
                    $worstOutcome = $this->worse($worstOutcome, 'degraded');
                    $notes[] = ['code' => 'projection_error', 'message' => $e->getMessage()];
                    DB::table('ingest.anomalies')->insert([
                        'id' => (string) Str::uuid(),
                        'stream_id' => $streamId,
                        'source_id' => $source['id'],
                        'run_id' => $runId,
                        'kind' => 'projection',
                        // 'critical', not 'warning' (#CACHE-3 brief, 2026-07-31):
                        // IngestAnomaliesCommand filters `->where('severity',
                        // 'critical')`, so a 'warning' row is written to a table
                        // nobody is ever woken by — the landing silently keeps
                        // succeeding while the derived content it feeds is stale.
                        // Nothing auto-resolves these rows, and the command stamps
                        // detail.alerted_at, so this pages ONCE per failure rather
                        // than once per sweep. Safe to raise: dev has recorded zero
                        // degraded runs ever, so this adds no standing noise.
                        'severity' => 'critical',
                        'summary' => 'Projection failed after a successful landing: '.mb_substr($e->getMessage(), 0, 300),
                        'detected_at' => now(),
                    ]);
                }
            }
        }

        // What this run actually spent. Both columns existed from the baseline
        // and NOTHING wrote either of them — every run row has read
        // effects_count = 0, cost_claimed = 0 since the lane began, while
        // ingest.effects carried the real figures (an instagram actor call
        // shows 0 here and 50 units there).
        //
        // cost_claimed is not cosmetic: scoreDue() sums it per user as the
        // fairness denominator (1 + spent/100), so a permanent 0 made the
        // "stops one expensive user monopolising the lane" guard a no-op.
        //
        // Aggregated from ingest.effects rather than counted in-flight: the
        // ledger is already the authority on what was claimed, and a separate
        // tally in this class could only ever drift from it.
        $spend = DB::table('ingest.effects')
            ->where('run_id', $runId)
            ->selectRaw('count(*) as n, coalesce(sum(cost_units), 0) as units')
            ->first();

        DB::table('ingest.runs')->where('id', $runId)->update([
            'finished_at' => now(),
            'outcome' => $worstOutcome,
            'records_seen' => $totals['seen'],
            'records_changed' => $totals['changed'],
            'records_tombstoned' => $totals['tombstoned'],
            'effects_count' => (int) ($spend->n ?? 0),
            'cost_claimed' => (int) ($spend->units ?? 0),
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
                // OBS-7: this used to Log::warning and silently keep draining
                // — invisible to Nightwatch, and an incomplete-looking drain
                // (a message type dropped, not landed) is exactly the shape
                // that should abort the stream rather than continue as if
                // nothing happened, matching the Deferred/Unavailable break
                // two lines below. The outer catch in execute() reports this,
                // marks the stream 'error', and lets sibling streams finish.
                default => throw new UnknownConnectorMessageException($message::class, $connector::class),
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

    /**
     * Whether this source's owner has claimed their account.
     *
     * This decides which redactions apply (Manifest::redactionsFor), so it
     * must be read from the user rather than assumed: defaulting to "claimed"
     * would land third-party personal data for accounts nobody has claimed,
     * which is the exact regression the claim-state-scoped redaction rule
     * exists to prevent.
     *
     * @param  array<string, mixed>  $source
     */
    private function isClaimed(array $source): bool
    {
        $userId = $source['user_id'] ?? null;
        if ($userId === null) {
            return false;
        }

        $status = DB::table('core.users')->where('id', $userId)->value('status');

        return $status !== null && $status !== 'unclaimed';
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
        // LIFE-5: atomic bump — a lost increment here silently SHORTENS the
        // backoff, which is the wrong direction to be wrong in.
        DB::table('ingest.streams')->where('id', $streamId)->update([
            'health' => $errorClass === 'budget' ? 'degraded' : 'unavailable',
            'consecutive_failures' => DB::raw('consecutive_failures + 1'),
            'updated_at' => now(),
        ]);

        // suppressed_until derives from the POST-increment count. Read back
        // rather than compute in SQL: the expression needs POWER() and
        // INTERVAL, neither of which survives the SQLite mirror the Feature
        // lane runs on. A concurrent second failure landing between these
        // statements only makes this read HIGHER, never lower — the only
        // drift is toward a longer backoff, the safe direction.
        $failures = (int) DB::table('ingest.streams')->where('id', $streamId)->value('consecutive_failures');
        $backoffMinutes = min(10080, 60 * (2 ** min(7, max(0, $failures - 1))));

        DB::table('ingest.streams')->where('id', $streamId)->update([
            'suppressed_until' => $failures >= 3 ? now()->addMinutes($backoffMinutes) : null,
        ]);
    }

    private function worse(string $current, string $candidate): string
    {
        $rank = ['ok' => 0, 'not_modified' => 1, 'deferred' => 2, 'budget_skipped' => 3, 'degraded' => 4, 'unavailable' => 5, 'shape' => 6, 'error' => 7];

        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }

    /**
     * $userId is nullable on purpose and never fabricated: isClaimed() already
     * treats an ownerless source as a real state, and a driver that spends
     * per-user budget must be able to see the absence rather than be handed an id.
     */
    private function ioFor(Manifest $manifest, string $runId, string $sourceId, ?string $userId): Io
    {
        return new HttpIo(
            manifest: $manifest,
            fetcher: app(SafeUrlFetcher::class),
            ledger: app(EffectLedger::class),
            drivers: app(BilledEffectDriverRegistry::class),
            runId: $runId,
            sourceId: $sourceId,
            userId: $userId,
        );
    }
}
