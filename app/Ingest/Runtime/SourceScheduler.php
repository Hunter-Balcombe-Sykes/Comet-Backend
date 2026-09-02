<?php

namespace App\Ingest\Runtime;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Decides WHICH sources run next and holds the only run lock in the system.
 *
 * The claim is a conditional UPDATE … RETURNING on the source row itself, not
 * a queue uniqueness constraint and not a Redis lock (plan §4: ONE
 * mechanism). Two reasons: the lock is visible in the same row as the
 * schedule that produced it, so "why isn't this running?" is one query; and a
 * lock living in Redis can outlive or predecease the row it protects, which
 * is how sources end up permanently stuck for reasons nobody can see.
 *
 * Cadence is MEASURED, not configured: a source that keeps returning
 * unchanged content drifts toward its maximum interval on its own, and one
 * that changes every run drifts toward its minimum. Config sets the band; the
 * source's own behaviour picks the point inside it.
 */
class SourceScheduler
{
    /** Fallback default for config('partna.ingest.source_change_rate_alpha') (CFG-16). */
    private const ALPHA = 0.3;

    /** Fallback default for config('partna.ingest.source_stranded_after_seconds') (CFG-16). */
    private const STRANDED_AFTER_SECONDS = 7200;

    /**
     * Claim up to $limit due sources for this dispatcher tick.
     *
     * @return list<array<string, mixed>>
     */
    public function claimDue(int $limit, string $runId): array
    {
        $candidates = $this->scoreDue($limit * 3);
        $claimed = [];

        foreach ($candidates as $candidate) {
            if (count($claimed) >= $limit) {
                break;
            }

            // The claim IS the race resolution: only one worker's UPDATE can
            // match `in_flight_since IS NULL`.
            $won = DB::table('ingest.sources')
                ->where('id', $candidate->id)
                ->whereNull('in_flight_since')
                ->update([
                    'in_flight_since' => now(),
                    'in_flight_run_id' => $runId,
                    'updated_at' => now(),
                ]);

            if ($won === 1) {
                $claimed[] = (array) DB::table('ingest.sources')->where('id', $candidate->id)->first();
            }
        }

        return $claimed;
    }

    /**
     * Claim ONE known source, for a caller that already knows which row it
     * wants — the eager-on-connect trigger (Manifest::runsEagerlyOnConnect).
     *
     * Deliberately the same conditional UPDATE as claimDue(), not a second
     * locking scheme: this class's docblock commits to ONE mechanism, and a
     * caller that dispatched RunSourceJob WITHOUT claiming would let a
     * scheduler tick or a second connect event run the same source
     * concurrently. For a billed connector that is a double charge, which the
     * EffectLedger settles per digest and cannot refund.
     *
     * Does NOT consult auto_sync or next_attempt_at: the whole point of the
     * eager trigger is to run a source the scheduler is barred from claiming.
     * Whether this source SHOULD run eagerly is the manifest's decision, made
     * before we get here — mixing that policy in would give one source two
     * disagreeing gates.
     *
     * @return bool true when the claim was won and the caller owns the run
     */
    public function claimOne(string $sourceId, string $runId): bool
    {
        return DB::table('ingest.sources')
            ->where('id', $sourceId)
            ->whereNull('in_flight_since')
            ->update([
                'in_flight_since' => now(),
                'in_flight_run_id' => $runId,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Due sources, most valuable first.
     *
     * score = staleness × visibility ÷ (cost × (1 + units already spent today))
     *
     * The denominator is what stops one expensive user monopolising the lane:
     * every unit spent on a user this cycle makes their next source cheaper to
     * defer, without ever excluding them outright.
     *
     * @return list<object>
     */
    private function scoreDue(int $limit): array
    {
        $spentToday = DB::table('ingest.runs')
            ->join('ingest.sources', 'ingest.sources.id', '=', 'ingest.runs.source_id')
            ->where('ingest.runs.started_at', '>=', now()->startOfDay())
            ->groupBy('ingest.sources.user_id')
            ->select('ingest.sources.user_id', DB::raw('sum(ingest.runs.cost_claimed) as units'))
            ->pluck('units', 'user_id');

        $due = DB::table('ingest.sources')
            ->whereNull('in_flight_since')
            // #LIFE-5: `auto_sync` OR an undischarged eager obligation. The
            // connect run is a one-shot that nothing retried, and auto_sync is
            // false on exactly the paid connectors it matters most for — so a
            // dispatch lost to a queue blip meant that user's media never
            // arrived at all. Reading the flag HERE rather than from a separate
            // reconcile command is the point of the design: every guard around
            // it already exists and applies unchanged — next_attempt_at honours
            // a deferral or a failure backoff, health != 'dead' stops a source
            // that keeps failing, and the in_flight claim below stops two
            // workers taking it at once.
            ->where(fn ($q) => $q->where('auto_sync', true)->orWhere('needs_eager_run', true))
            ->where('health', '!=', 'dead')
            ->where('next_attempt_at', '<=', now())
            ->limit($limit * 2)
            ->get();

        $scored = $due->map(function (object $source) use ($spentToday) {
            $staleness = $source->last_run_at === null
                ? 10.0 // never run: the strongest claim on the lane there is
                : max(0.0, (time() - strtotime((string) $source->last_run_at)) / max(1, (int) $source->min_interval_secs));

            $spent = (float) ($spentToday[$source->user_id] ?? 0);
            $score = ($staleness * (float) $source->visibility) / (max(1, (int) $source->cost_units) * (1 + $spent / 100));

            return (object) ['id' => $source->id, 'score' => $score];
        })->sortByDesc('score')->take($limit)->values();

        return $scored->all();
    }

    /**
     * Release a claim and set the next attempt.
     *
     * `$changed` feeds the EWMA: this is where a quiet source earns a longer
     * interval and a busy one earns a shorter, without anybody tuning a
     * config value per platform.
     */
    /** How many failed FIRST pulls retry fast, and how fast (O.1). */
    public const FIRST_PULL_FAST_RETRIES = 3;

    public const FIRST_PULL_RETRY_SECONDS = 900;

    public function release(string $sourceId, string $outcome, bool $changed, ?int $retryAfterSeconds = null): void
    {
        $source = DB::table('ingest.sources')->where('id', $sourceId)->first();
        if ($source === null) {
            return;
        }

        $update = [
            'in_flight_since' => null,
            'in_flight_run_id' => null,
            'last_run_at' => now(),
            'updated_at' => now(),
        ];

        // A Deferred run is not evidence about change rate — the vendor
        // simply asked us to come back. Reschedule and leave the EWMA alone.
        if ($outcome === 'deferred' && $retryAfterSeconds !== null) {
            $update['next_attempt_at'] = now()->addSeconds(max(60, $retryAfterSeconds));
            DB::table('ingest.sources')->where('id', $sourceId)->update($update);

            return;
        }

        // `degraded` qualifies because the fetch and the landing both SUCCEEDED
        // — only a projection, i.e. our own code, failed (RunExecutor #JOB-4).
        // It is evidence about us, not the vendor, so it must never feed
        // consecutive_failures: ten runs of one projector bug would otherwise
        // mark a perfectly healthy source `dead`, which scoreDue() excludes
        // permanently and which reconnecting deliberately does not reset
        // (SourceProvisioner). The record log is durable, so the fix for a
        // failed projection is `ingest:project`, not a slower fetch cadence.
        $qualifies = in_array($outcome, ['ok', 'not_modified', 'degraded'], true);

        if ($qualifies) {
            $alpha = (float) config('partna.ingest.source_change_rate_alpha', self::ALPHA);
            $rate = $alpha * ($changed ? 1.0 : 0.0) + (1 - $alpha) * (float) $source->change_rate;
            $update['change_rate'] = $rate;
            $update['consecutive_failures'] = 0;
            // Not `ok` for a degraded run: the fetch is healthy but the content
            // derived from it is stale, and stamping `ok` would claim otherwise.
            // `degraded` is a non-`dead` health, so the source stays claimable.
            $update['health'] = $outcome === 'degraded' ? 'degraded' : 'ok';
            $update['next_attempt_at'] = now()->addSeconds($this->intervalFor($source, $rate));
            // #LIFE-5: the eager obligation is discharged the moment content
            // actually lands, after which the row is governed by auto_sync
            // alone. Cleared ONLY on a qualifying outcome — a failed or
            // deferred run leaves it set, which is what makes the retry a
            // retry rather than a single second chance.
            $update['needs_eager_run'] = false;
        } else {
            $failures = (int) $source->consecutive_failures + 1;
            $update['consecutive_failures'] = $failures;
            $update['health'] = $failures >= 10 ? 'dead' : $this->healthFor($outcome);
            // Exponential backoff on failure, capped at the source's own
            // maximum interval — a broken source must not vanish forever, it
            // must simply stop being expensive.
            $backoff = min((int) $source->max_interval_secs, (int) $source->min_interval_secs * (2 ** min(6, $failures)));
            // FIRST-PULL BACKOFF (O.1, 2026-09-02): a source that has never
            // landed — needs_eager_run is still set, since only a qualifying
            // outcome clears it — is a signup's connection whose card reads
            // "pending" until it does. The exponential curve was built for a
            // source with history (TikTok: 12h min → a day's wait after ONE
            // vendor blip, a week after three); for a first pull the person
            // is waiting, so the first three failures retry within 15
            // minutes. From the fourth the ordinary curve applies.
            if ((bool) $source->needs_eager_run && $failures <= self::FIRST_PULL_FAST_RETRIES) {
                $backoff = min($backoff, self::FIRST_PULL_RETRY_SECONDS);
            }
            $update['next_attempt_at'] = now()->addSeconds($backoff);
        }

        DB::table('ingest.sources')->where('id', $sourceId)->update($update);
    }

    /**
     * Interval from the measured change rate, inside the profile's band.
     * rate 1.0 (changes every run) → min; rate 0.0 (never changes) → max.
     */
    private function intervalFor(object $source, float $rate): int
    {
        $min = (int) $source->min_interval_secs;
        $max = (int) $source->max_interval_secs;

        return (int) round($max - ($max - $min) * max(0.0, min(1.0, $rate)));
    }

    /**
     * Health for a NON-qualifying outcome. `degraded` no longer reaches here
     * (release() qualifies it), but the arm is kept deliberately: it returns
     * the same value that branch sets, so a future caller cannot land it in
     * the `unavailable` default by accident.
     */
    private function healthFor(string $outcome): string
    {
        return match ($outcome) {
            'shape' => 'shape',
            'budget_skipped' => 'degraded',
            'degraded' => 'degraded',
            default => 'unavailable',
        };
    }

    /**
     * Sources whose claim outlived any plausible run: a worker was killed
     * mid-flight. Released so they can run again, and reported — a stranded
     * claim is a real signal, not noise to be swept quietly.
     *
     * @return list<string> released source ids
     */
    public function releaseStranded(): array
    {
        $cutoff = now()->subSeconds((int) config('partna.ingest.source_stranded_after_seconds', self::STRANDED_AFTER_SECONDS));

        $candidates = DB::table('ingest.sources')
            ->whereNotNull('in_flight_since')
            ->where('in_flight_since', '<', $cutoff)
            ->get(['id', 'in_flight_since', 'in_flight_run_id']);

        $released = [];

        foreach ($candidates as $candidate) {
            // #WHK-1: the SELECT above and this UPDATE are two round trips —
            // a worker can win claimDue() for this exact source in between
            // (it had genuinely gone stale, then got legitimately re-claimed).
            // Re-checking the same predicate here, under the row lock, is
            // what stops us releasing a run that is actually in progress:
            // claimDue() writes a fresh in_flight_since, which is NOT
            // < $cutoff, so this UPDATE matches nothing for that row.
            $cleared = DB::table('ingest.sources')
                ->where('id', $candidate->id)
                ->whereNotNull('in_flight_since')
                ->where('in_flight_since', '<', $cutoff)
                ->update([
                    'in_flight_since' => null,
                    'in_flight_run_id' => null,
                    'next_attempt_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($cleared !== 1) {
                continue; // re-claimed under us — leave the new run's lock alone
            }

            $released[] = $candidate->id;

            // Filed per ACTUAL release, not per candidate — a source that
            // was re-claimed between the SELECT and the UPDATE above never
            // reaches here, so it never pages an operator about a claim that
            // was never really stranded.
            DB::table('ingest.anomalies')->insert([
                'id' => (string) Str::uuid(),
                'source_id' => $candidate->id,
                'kind' => 'stranded',
                'severity' => 'warning',
                'summary' => 'Source claim outlived its run — a worker died holding it',
                'detail' => json_encode([
                    'in_flight_run_id' => $candidate->in_flight_run_id,
                    'in_flight_since' => $candidate->in_flight_since,
                ]),
                'detected_at' => now(),
            ]);
        }

        return $released;
    }
}
