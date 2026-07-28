<?php

namespace App\Console\Commands;

use App\Ingest\SourceProvisioner;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Console\Command;
use Throwable;

// One-shot (idempotent, re-runnable) seam repair: walks every live account
// connection and provisions its ingest.sources row via SourceProvisioner —
// the same code path the observer runs at connect time, so backfilled rows
// and connect-time rows can never drift. Prints per-status/per-platform
// tallies plus every skip reason, because a silent skip here is a connection
// that never syncs and nobody finds out.
class IngestBackfillSourcesCommand extends Command
{
    protected $signature = 'ingest:backfill-sources
        {--user= : Only this user id}
        {--dry-run : Report what would happen without writing}';

    protected $description = 'Provision ingest.sources rows for every existing platform connection with a registered connector.';

    public function handle(SourceProvisioner $provisioner): int
    {
        $query = IntegrationConnection::query()
            ->whereNull('resource_kind')
            ->orderBy('created_at');

        if ($this->option('user') !== null) {
            $query->where('user_id', (string) $this->option('user'));
        }

        $dryRun = (bool) $this->option('dry-run');
        $tally = [];
        $skips = [];
        $failures = 0;

        foreach ($query->cursor() as $connection) {
            $sourceKey = SourceProvisioner::sourceKeyFor((string) $connection->getAttributes()['surface_key']);
            if ($sourceKey === null) {
                // Off-registry brands are the normal majority (socials,
                // link-only surfaces) — not worth a per-row line.
                $tally['no_connector'] = ($tally['no_connector'] ?? 0) + 1;

                continue;
            }

            if ($dryRun) {
                $tally['would_process'] = ($tally['would_process'] ?? 0) + 1;
                $this->line("would process: {$sourceKey} connection {$connection->id}");

                continue;
            }

            try {
                $result = $provisioner->sync($connection);
            } catch (Throwable $e) {
                report($e);
                $failures++;
                $this->error("failed: {$sourceKey} connection {$connection->id} — {$e->getMessage()}");

                continue;
            }

            $tally[$result['status']] = ($tally[$result['status']] ?? 0) + 1;

            if ($result['status'] === 'skipped') {
                $skips[] = [$sourceKey, $connection->id, $result['reason'] ?? '?'];
            }
        }

        ksort($tally);
        $this->table(['outcome', 'count'], array_map(null, array_keys($tally), array_values($tally)));

        if ($skips !== []) {
            $this->warn('Skipped connections (no usable identifier — these never sync until repaired):');
            $this->table(['source', 'connection', 'reason'], $skips);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
