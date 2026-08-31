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
        {--connector=* : Only these source keys (e.g. --connector=uber_eats --connector=square)}
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

        /**
         * Slice 4: provisioning a source is not free — it hands the scheduler a
         * row it will then RUN, and several connectors here are billed (Apify
         * actors, Places details). Unscoped, this command's dry run showed it
         * would process 80 connections across every connector; even scoped to
         * one user it picked up google_business, instagram, eventbrite and
         * humanitix alongside the three menu platforms. --connector narrows it
         * to the lane you actually mean, so proving one connector never spends
         * money on four others.
         *
         * @var list<string> $connectors
         */
        $connectors = array_values(array_filter(array_map('strval', (array) $this->option('connector'))));

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

            if ($connectors !== [] && ! in_array($sourceKey, $connectors, true)) {
                // Counted, not silent: "I asked for uber_eats and it processed
                // nothing" needs to be distinguishable from "there were no
                // connections at all".
                $tally['filtered_out'] = ($tally['filtered_out'] ?? 0) + 1;

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

            // A no_identifier RETIREMENT reports the same operator-visible
            // fact as a no_identifier skip — this connection does not sync —
            // and it is the arm that quietly unschedules a row that WAS
            // running, so keying the table on status alone would have hidden
            // the louder of the two.
            if ($result['status'] === 'skipped' || ($result['reason'] ?? null) === 'no_identifier') {
                $skips[] = [$sourceKey, $connection->id, $result['reason'] ?? '?'];
            }
        }

        ksort($tally);
        $this->table(['outcome', 'count'], array_map(null, array_keys($tally), array_values($tally)));

        if ($skips !== []) {
            $this->warn('Connections that do not sync (no usable identifier — repaired ones re-schedule themselves):');
            $this->table(['source', 'connection', 'reason'], $skips);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
