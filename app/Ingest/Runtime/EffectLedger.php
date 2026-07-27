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
        $existing = DB::table('ingest.effects')->where('digest', $digest)->first();

        if ($existing !== null) {
            $verdict = $this->verdictFor($existing);
            if ($verdict !== null) {
                return $verdict;
            }
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
                : ($this->verdictFor($row) ?? ['status' => 'refused', 'result' => null, 'cached' => false]);
        }

        try {
            $result = $effect();

            DB::table('ingest.effects')->where('digest', $digest)->update([
                'status' => 'ok',
                'settled_at' => now(),
                'meta' => json_encode(['summary' => $this->summarise($result)]),
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

    /**
     * @return array{status: string, result: mixed, cached: bool}|null null when
     *                                                                 the caller should proceed
     */
    private function verdictFor(object $row): ?array
    {
        if ($row->settled_at !== null) {
            // Already done — success or failure, either way not again.
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
     * key order is normalised and nothing time-varying is included.
     *
     * @param  array<string, mixed>  $request
     */
    public static function digestFor(string $kind, array $request): string
    {
        ksort($request);

        return substr(hash('sha256', $kind.'|'.json_encode($request, JSON_UNESCAPED_SLASHES)), 0, 32);
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
