<?php

namespace App\Console\Commands;

use App\Ingest\Runtime\EffectLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Operator entry point for the charge-once ledger (LIFE-18): EffectLedger's own
// docblock has promised `ingest:effects --resolve` as the deliberate-act resolution
// path since before this command existed. Three modes, mutually exclusive:
//   (default)  read-only listing of every unsettled claim.
//   --resolve  sweep long-dead claims to 'abandoned' via EffectLedger::reconcileAbandoned().
//   --settle   the actual unblock: mark ONE digest terminal and close its anomaly.
class IngestEffectsCommand extends Command
{
    protected $signature = 'ingest:effects
        {--resolve : Mark long-dead claims abandoned and file an anomaly}
        {--older-than= : Override the abandon window, in seconds}
        {--limit=500 : Max claims to sweep per --resolve run}
        {--settle= : Digest to settle deliberately, unblocking a stuck claim}
        {--as=failed : Terminal status for --settle (failed|ok)}';

    protected $description = 'Inspect and resolve the ingest.effects charge-once ledger.';

    public function handle(EffectLedger $ledger): int
    {
        $digest = $this->option('settle');
        if ($digest !== null) {
            return $this->settle($digest);
        }

        if ($this->option('resolve')) {
            return $this->resolve($ledger);
        }

        return $this->listUnsettled();
    }

    private function listUnsettled(): int
    {
        $rows = DB::table('ingest.effects')
            ->whereNull('settled_at')
            ->orderBy('claimed_at')
            ->get(['digest', 'kind', 'cost_tag', 'cost_units', 'status', 'claimed_at']);

        if ($rows->isEmpty()) {
            $this->info('No unsettled ingest effect claims.');

            return self::SUCCESS;
        }

        $this->table(
            ['digest', 'kind', 'cost_tag', 'cost_units', 'status', 'age'],
            $rows->map(fn ($row) => [
                $row->digest,
                $row->kind,
                $row->cost_tag,
                $row->cost_units,
                $row->status,
                Carbon::parse($row->claimed_at)->diffForHumans(null, Carbon::DIFF_ABSOLUTE),
            ])->all()
        );

        return self::SUCCESS;
    }

    private function resolve(EffectLedger $ledger): int
    {
        $olderThan = $this->option('older-than');
        $limit = (int) $this->option('limit');

        $abandoned = $ledger->reconcileAbandoned($olderThan !== null ? (int) $olderThan : null, $limit);

        if ($abandoned === []) {
            $this->info('Ingest effects sweep: nothing to abandon.');

            return self::SUCCESS;
        }

        // reconcileAbandoned() -> markAbandoned() already reported one
        // AbandonedEffectException PER digest — an aggregate report() here would
        // duplicate the Nightwatch issue rather than add information.
        $this->warn(sprintf(
            'Ingest effects sweep: abandoned %d claim(s): %s',
            count($abandoned),
            implode(', ', $abandoned)
        ));

        return self::SUCCESS;
    }

    private function settle(string $digest): int
    {
        $as = $this->option('as');
        if (! in_array($as, ['failed', 'ok'], true)) {
            $this->error("--as must be 'failed' or 'ok', got '{$as}'.");

            return self::FAILURE;
        }

        $row = DB::table('ingest.effects')->where('digest', $digest)->first();
        if ($row === null) {
            $this->error("No ingest.effects row for digest '{$digest}'.");

            return self::FAILURE;
        }
        if ($row->settled_at !== null) {
            $this->error("Digest '{$digest}' is already settled (status={$row->status}).");

            return self::FAILURE;
        }

        $meta = json_decode((string) $row->meta, true);
        if (! is_array($meta)) {
            $meta = [];
        }
        $meta['resolved_by'] = 'ingest:effects --settle';

        DB::table('ingest.effects')->where('digest', $digest)->update([
            'status' => $as,
            'settled_at' => now(),
            'meta' => json_encode($meta),
        ]);

        // No dedicated `digest` column on ingest.anomalies — it lives inside `detail`
        // (json_encode'd by EffectLedger::markAbandoned()), so the open effect_abandoned
        // anomalies (a small, bounded set by design) are matched in PHP rather than via
        // a JSON-path WHERE that would need separate Postgres/SQLite-mirror phrasing.
        $anomaly = DB::table('ingest.anomalies')
            ->where('kind', 'effect_abandoned')
            ->whereNull('resolved_at')
            ->get(['id', 'detail'])
            ->first(function ($candidate) use ($digest) {
                $detail = json_decode((string) $candidate->detail, true);

                return is_array($detail) && ($detail['digest'] ?? null) === $digest;
            });

        if ($anomaly !== null) {
            // Mirrors Lander::clearGuardIfRecovered()'s resolve shape.
            DB::table('ingest.anomalies')->where('id', $anomaly->id)->update([
                'resolved_at' => now(),
                'resolved_by' => 'ingest:effects --settle',
                'resolution' => "settled as {$as}",
            ]);
        }

        $this->info("Settled digest '{$digest}' as '{$as}'.");

        return self::SUCCESS;
    }
}
