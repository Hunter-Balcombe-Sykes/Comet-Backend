<?php

namespace App\Console\Commands;

use App\Exceptions\Ingest\UnresolvedCriticalAnomalyException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// LIFE-20: alarm for `ingest.anomalies` rows that are severity='critical',
// still unresolved, and old enough that self-healing (Lander::clearGuardIfRecovered())
// has had a fair chance to clear them. A DB queue reaches nobody on its own —
// report() is what pages (see CheckPlatformRefreshBacklogCommand). Structured
// as a near-copy of IngestEffectsCommand's mode shape: default is the
// scheduled alarm, --list is the operator's read-only view.
//
// Alerts ONCE per row via a `detail.alerted_at` stamp, not once per sweep run
// — a persisting anomaly must not re-page every hour. A row whose detector
// already report()ed at DETECTION time (EffectLedger::markAbandoned's
// effect_abandoned rows) arrives pre-stamped, so this sweep never double-pages
// it; see EffectLedger.php's markAbandoned() for the other half of that
// handshake.
class IngestAnomaliesCommand extends Command
{
    protected $signature = 'ingest:anomalies
        {--list : Read-only listing of open critical anomalies, no alerting}
        {--older-than= : Override the age gate, in minutes}';

    protected $description = 'Alert on ingest.anomalies rows that are critical, unresolved, and unalerted past the age gate.';

    public function handle(): int
    {
        $olderThanMinutes = $this->option('older-than');
        $ageMinutes = $olderThanMinutes !== null
            ? (int) $olderThanMinutes
            : (int) config('partna.ingest.anomalies.critical_alert_after_minutes');
        $cutoff = now()->subMinutes($ageMinutes);

        // Drives idx_anomalies_open. No dedicated `alerted_at` column — like
        // ingest.effects' digest match in IngestEffectsCommand::settle(), the
        // open-critical set is bounded and small by design, so filtering in
        // PHP avoids a JSON-path WHERE that would need separate Postgres/
        // SQLite-mirror phrasing.
        $rows = DB::table('ingest.anomalies')
            ->where('severity', 'critical')
            ->whereNull('resolved_at')
            ->where('detected_at', '<', $cutoff)
            ->orderBy('detected_at')
            ->get(['id', 'kind', 'detail', 'detected_at']);

        $unalerted = $rows->filter(function ($row) {
            $detail = json_decode((string) $row->detail, true);

            return ! (is_array($detail) && array_key_exists('alerted_at', $detail));
        })->values();

        if ($this->option('list')) {
            if ($unalerted->isEmpty()) {
                $this->info('No open critical anomalies past the age gate.');
            } else {
                $this->table(
                    ['id', 'kind', 'detected_at'],
                    $unalerted->map(fn ($row) => [$row->id, $row->kind, $row->detected_at])->all()
                );
            }

            return self::SUCCESS;
        }

        if ($unalerted->isEmpty()) {
            $this->info('Ingest anomalies sweep: nothing unalerted past the age gate.');

            return self::SUCCESS;
        }

        $kinds = $unalerted->pluck('kind')->unique()->values()->all();
        $oldestDetectedAt = (string) $unalerted->first()->detected_at;

        // ONE aggregate exception per sweep, never one per row (LIFE-19/
        // LIFE-20's shared anti-fatigue rule) — a login wall tripping 50
        // delete_guard rows at once must page once, not 50 times.
        report(new UnresolvedCriticalAnomalyException($unalerted->count(), $kinds, $oldestDetectedAt));

        $now = now()->toISOString();
        foreach ($unalerted as $row) {
            $detail = json_decode((string) $row->detail, true);
            $detail = is_array($detail) ? $detail : [];
            $detail['alerted_at'] = $now;

            DB::table('ingest.anomalies')->where('id', $row->id)->update([
                'detail' => json_encode($detail),
            ]);
        }

        $this->warn(sprintf(
            'Ingest anomalies sweep: reported %d unresolved critical anomal%s — reported to Nightwatch.',
            $unalerted->count(),
            $unalerted->count() === 1 ? 'y' : 'ies'
        ));

        return self::SUCCESS;
    }
}
