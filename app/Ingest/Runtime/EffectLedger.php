<?php

namespace App\Ingest\Runtime;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 */
class EffectLedger
{
    /** How long a claimed-but-unsettled effect blocks a retry. */
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
        } catch (\Throwable) {
            $row = DB::table('ingest.effects')->where('digest', $digest)->first();

            return $row === null
                ? ['status' => 'refused', 'result' => null, 'cached' => false]
                : $this->verdictFor($row);
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
        if ($claimedAt !== false && (time() - $claimedAt) > self::ABANDON_AFTER_SECONDS) {
            // Long-dead claim: mark it so it stops blocking silently and is
            // visible to whoever reconciles spend, but STILL refuse — the
            // vendor may well have charged us for it.
            DB::table('ingest.effects')->where('digest', $row->digest)->update(['status' => 'abandoned']);
            Log::warning('ingest.effect.abandoned', ['digest' => $row->digest, 'kind' => $row->kind]);

            return ['status' => 'abandoned', 'result' => null, 'cached' => true];
        }

        // Freshly claimed by another worker — refuse, do not duplicate.
        return ['status' => 'refused', 'result' => null, 'cached' => true];
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
