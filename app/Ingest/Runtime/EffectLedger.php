<?php

namespace App\Ingest\Runtime;

use App\Exceptions\Ingest\AbandonedEffectException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Charge-once for billed effects (C6). Every effect that costs money is
 * identified by a digest of the REQUEST, so:
 *
 *   - a retry of the same job finds the settled row and reuses its result
 *     instead of paying twice;
 *   - a process that died mid-effect leaves a CLAIMED-but-unsettled row,
 *     which is REFUSED rather than retried — we cannot know whether the
 *     vendor charged us, and guessing wrong costs real money either way.
 *     Resolving it is a deliberate act (`ingest:effects --resolve`).
 *
 * This generalises GoogleBusinessEnrichJob's refuse-to-re-bill policy, which
 * is the behaviour this whole class was extracted from.
 *
 * ONE path removes a row: a driver raising EffectNotAttempted from inside once(),
 * which by that class's contract happens before its first vendor call. No
 * request left the process, so there is no charge to protect and no reason to
 * hold the digest for the rest of the freshness window. Every other ending —
 * ok, failed, abandoned — settles in place, and reconcileAbandoned() still
 * never deletes anything at all.
 */
class EffectLedger
{
    /** Fallback default for config('partna.ingest.effect_abandon_after_seconds') (CFG-16). */
    private const ABANDON_AFTER_SECONDS = 900;

    /**
     * Largest result stored inline in `meta` for replay. Bigger payloads are
     * summarised only; their replay is REFUSED (never ok-with-null) until the
     * P7 drivers wire `body_ref` to durable off-row storage — local disk is
     * ephemeral across Cloud workers, so a file pointer would lie.
     */
    private const RESULT_INLINE_MAX_BYTES = 1_000_000;

    /**
     * Run $effect at most once for this digest, ever.
     *
     * A lost claim race resolves to the winner's verdict; any other DB failure
     * propagates.
     *
     * @template T
     *
     * @param  callable(): T  $effect
     * @return array{status: string, result: mixed, cached: bool}
     */
    public function once(
        string $digest,
        string $kind,
        callable $effect,
        ?string $runId = null,
        ?string $sourceId = null,
        ?string $costTag = null,
        int $costUnits = 0,
    ): array {
        // ANY existing row for this digest means we do not act: it is either
        // already settled (reuse), freshly claimed elsewhere (refuse), or a
        // dead claim (refuse — the vendor may have charged us).
        $existing = DB::table('ingest.effects')->where('digest', $digest)->first();

        if ($existing !== null) {
            return $this->verdictFor($existing);
        }

        // Claim first, then act. An insert that loses the race means another
        // worker is doing (or did) this exact effect.
        try {
            DB::table('ingest.effects')->insert([
                'digest' => $digest,
                'run_id' => $runId,
                'source_id' => $sourceId,
                'kind' => $kind,
                'cost_tag' => $costTag,
                'cost_units' => $costUnits,
                'claimed_at' => now(),
                'status' => 'claimed',
                'meta' => json_encode([]),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Typed catch handles only 23505 (digest is the PRIMARY KEY) by
            // construction — no SQLSTATE string-matching. Anything else (deadlock
            // 40P01, connection loss 08006, not-null, …) propagates untouched to
            // the job's retry/backoff and to Nightwatch (#WHK-3).
            $row = DB::table('ingest.effects')->where('digest', $digest)->first();

            if ($row === null) {
                // A PK conflict with no row present means the winner's own
                // transaction rolled back after committing and then vanished —
                // the claim attempt itself failed, nothing is in flight.
                // Laundering that into a silent 'refused' is precisely what
                // #WHK-3 flagged; surface it instead.
                throw $e;
            }

            return $this->verdictFor($row);
        }

        try {
            $result = $effect();

            // Persist the result WITH the settlement: a replay (same-run
            // sibling stream, or a retry) must return the data that was paid
            // for, or charge-once quietly turns "ok" into "no data".
            $meta = ['summary' => $this->summarise($result)];
            $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false && strlen($encoded) <= self::RESULT_INLINE_MAX_BYTES) {
                $meta['result'] = $result;
            } else {
                $meta['result_omitted'] = true;
            }

            DB::table('ingest.effects')->where('digest', $digest)->update([
                'status' => 'ok',
                'settled_at' => now(),
                'meta' => json_encode($meta),
            ]);

            return ['status' => 'ok', 'result' => $result, 'cached' => false];
        } catch (EffectNotAttempted $e) {
            // The ONE deletion in this class. Safe only because EffectNotAttempted's
            // contract is "no request left the process" (see that class): nothing
            // was charged, so nothing needs protecting from a re-run. A settled row
            // would instead block this digest for the whole freshness window —
            // seven days locked out by one capped day or one config typo.
            //
            // Guarded exactly like markAbandoned()'s conditional UPDATE: a money
            // ledger should not carry an unqualified DELETE, and the predicate turns
            // "provably unsettled" from a comment into an enforced precondition.
            DB::table('ingest.effects')
                ->where('digest', $digest)
                ->where('status', 'claimed')
                ->whereNull('settled_at')
                ->delete();

            throw $e;
        } catch (EffectNoAnswer $e) {
            // A request went out and we did not get an answer, so we cannot know
            // whether the vendor billed us: the row STAYS, settled, and this digest
            // is inert until the freshness bucket rolls. That is the class contract
            // for an unknown charge, not an oversight.
            //
            // RETURNED rather than rethrown, though. A rethrow reaches RunExecutor's
            // catch-all and marks the stream 'error', which reads as our bug;
            // letting the connector see a non-ok verdict folds it to Unavailable,
            // which is what a vendor outage actually is.
            DB::table('ingest.effects')->where('digest', $digest)->update([
                'status' => 'failed',
                'settled_at' => now(),
                'meta' => json_encode(['error' => 'EffectNoAnswer', 'message' => mb_substr($e->getMessage(), 0, 500)]),
            ]);

            return ['status' => 'failed', 'result' => null, 'cached' => false];
        } catch (\Throwable $e) {
            // A failure IS settled: we know it happened and what it cost. It
            // is the UNKNOWN (process death) that must never auto-retry.
            DB::table('ingest.effects')->where('digest', $digest)->update([
                'status' => 'failed',
                'settled_at' => now(),
                'meta' => json_encode(['error' => class_basename($e), 'message' => mb_substr($e->getMessage(), 0, 500)]),
            ]);

            throw $e;
        }
    }

    /** @return array{status: string, result: mixed, cached: bool} */
    private function verdictFor(object $row): array
    {
        if ($row->settled_at !== null) {
            if ($row->status === 'ok') {
                $meta = json_decode((string) $row->meta, true);
                if (is_array($meta) && array_key_exists('result', $meta)) {
                    return ['status' => 'ok', 'result' => $meta['result'], 'cached' => true];
                }

                // Settled ok but the data is gone (pre-persistence row, or an
                // oversized result). Fail CLOSED: a data-less "ok" folds into
                // false success downstream — refused folds into Unavailable,
                // which is the honest outcome.
                Log::warning('ingest.effect.replay_unavailable', ['digest' => $row->digest, 'kind' => $row->kind]);

                return ['status' => 'refused', 'result' => null, 'cached' => true];
            }

            // A settled failure — known, charged, never auto-retried.
            return ['status' => $row->status, 'result' => null, 'cached' => true];
        }

        $claimedAt = strtotime((string) $row->claimed_at);
        if ($claimedAt !== false && (time() - $claimedAt) > $this->abandonAfterSeconds()) {
            // Long-dead claim: mark it so it stops blocking silently and is
            // visible to whoever reconciles spend, but STILL refuse — the
            // vendor may well have charged us for it.
            $this->markAbandoned($row);

            return ['status' => 'abandoned', 'result' => null, 'cached' => true];
        }

        // Freshly claimed by another worker — refuse, do not duplicate.
        return ['status' => 'refused', 'result' => null, 'cached' => true];
    }

    /**
     * CFG-16: the ONE read of the abandon window. The comparison in verdictFor(), the
     * operator-facing summary in markAbandoned(), and reconcileAbandoned()'s default must
     * never diverge — a money-adjacent critical anomaly that names a window other than the
     * one applied misleads the on-call engineer reconciling vendor spend.
     */
    private function abandonAfterSeconds(): int
    {
        return (int) config('partna.ingest.effect_abandon_after_seconds', self::ABANDON_AFTER_SECONDS);
    }

    /**
     * Flip a claimed-but-unsettled row past the abandon window to 'abandoned', file a
     * critical `ingest.anomalies` row, and report to Nightwatch (#WHK-4) — but ONLY on
     * the transition. The conditional UPDATE is the whole of what makes this safe to
     * alert on: verdictFor() re-checking the same digest, or reconcileAbandoned()
     * re-selecting it on a later sweep, affects zero rows and stays silent, so a
     * digest already marked abandoned never files (or pages) a second time.
     *
     * $windowSeconds is the window actually applied by the caller (verdictFor()'s own
     * abandonAfterSeconds(), or reconcileAbandoned()'s $olderThanSeconds override) — the
     * anomaly summary must name that window, never silently fall back to the default.
     *
     * @return bool whether THIS call performed the transition
     */
    private function markAbandoned(object $row, ?int $windowSeconds = null): bool
    {
        $affected = DB::table('ingest.effects')
            ->where('digest', $row->digest)
            ->where('status', 'claimed')
            ->whereNull('settled_at')
            ->update(['status' => 'abandoned']);

        if ($affected === 0) {
            return false;
        }

        Log::warning('ingest.effect.abandoned', ['digest' => $row->digest, 'kind' => $row->kind]);

        DB::table('ingest.anomalies')->insert([
            'id' => (string) Str::uuid(),
            'source_id' => $row->source_id,
            'run_id' => $row->run_id,
            'kind' => 'effect_abandoned',
            'severity' => 'critical', // money-adjacent — same class as Lander's delete_guard
            'summary' => sprintf('Billed effect claim abandoned after %ds — vendor may have charged', $windowSeconds ?? $this->abandonAfterSeconds()),
            'detail' => json_encode([
                'digest' => $row->digest,
                'kind' => $row->kind,
                'cost_tag' => $row->cost_tag,
                'cost_units' => $row->cost_units,
                'claimed_at' => $row->claimed_at,
                // LIFE-20: this row is filed AND report()ed in the same
                // breath (see the report() below), so the ingest:anomalies
                // sweep must not page it a second time. Stamping alerted_at
                // here is the whole handshake — the sweep's rule is "no
                // alerted_at means nobody has paged for this yet", with no
                // per-kind exclusion list to keep in sync.
                'alerted_at' => now()->toISOString(),
            ]),
            'detected_at' => now(),
        ]);

        // A Log::warning is a breadcrumb and the anomalies row is a DB queue — neither
        // reaches Nightwatch on its own. report() is the house pattern for exactly this.
        report(new AbandonedEffectException(1, [$row->digest]));

        return true;
    }

    /**
     * Sweep claimed-but-unsettled rows past the abandon window and make them honest
     * (LIFE-18): flips each to 'abandoned' and files/reports one anomaly per NEW
     * transition via markAbandoned(), so a dead claim is visible without an operator
     * having to trip over it lazily via once(). Drives idx_effects_unsettled.
     *
     * NEVER sets settled_at, never deletes — the class contract is that an
     * unknown-charge claim stays refused; this sweep only makes the stuck state
     * honest and visible. Resolving one is a deliberate act (`ingest:effects --settle`).
     *
     * @return list<string> digests newly marked abandoned by this call
     */
    public function reconcileAbandoned(?int $olderThanSeconds = null, int $limit = 500): array
    {
        $windowSeconds = $olderThanSeconds ?? $this->abandonAfterSeconds();
        $cutoff = now()->subSeconds($windowSeconds);

        $candidates = DB::table('ingest.effects')
            ->whereNull('settled_at')
            ->where('status', 'claimed')
            ->where('claimed_at', '<', $cutoff)
            ->orderBy('claimed_at')
            ->limit($limit)
            ->get();

        $abandoned = [];
        foreach ($candidates as $row) {
            if ($this->markAbandoned($row, $windowSeconds)) {
                $abandoned[] = $row->digest;
            }
        }

        return $abandoned;
    }

    /**
     * Deterministic digest of an effect. Two callers describing the same
     * request must produce the same digest, or charge-once is worthless — so
     * key order is normalised.
     *
     * $freshnessSeconds bounds HOW LONG the digest stays the same: within one
     * window, retries and sibling streams dedupe (and replay the stored
     * result); the next window produces a new digest, so a recurring billed
     * fetch re-bills DELIBERATELY instead of being one-shot forever. No
     * window = the historical forever-digest (true one-time effects).
     *
     * @param  array<string, mixed>  $request
     */
    public static function digestFor(string $kind, array $request, ?int $freshnessSeconds = null): string
    {
        ksort($request);

        $bucket = ($freshnessSeconds !== null && $freshnessSeconds > 0)
            ? '|'.intdiv(now()->getTimestamp(), $freshnessSeconds)
            : '';

        return substr(hash('sha256', $kind.'|'.json_encode($request, JSON_UNESCAPED_SLASHES).$bucket), 0, 32);
    }

    private function summarise(mixed $result): string
    {
        return match (true) {
            is_array($result) => 'array('.count($result).')',
            is_string($result) => 'string('.strlen($result).')',
            is_object($result) => class_basename($result),
            default => gettype($result),
        };
    }
}
