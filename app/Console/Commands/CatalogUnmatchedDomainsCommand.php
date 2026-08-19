<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The triage queue: which domains the router keeps failing to place, most
 * frequent first.
 *
 * This is the input to "which detector do we write next?". The ranking is the
 * point — a platform fifty users pasted is worth a rule, a domain someone
 * tried once is not, and only the hit count separates them.
 *
 * `--triage` is how the queue drains. Marking a domain triaged is a claim that
 * a decision was made about it (a detector was written, or it was judged not
 * worth one), not that the domain stopped appearing.
 */
class CatalogUnmatchedDomainsCommand extends Command
{
    protected $signature = 'catalog:unmatched
        {--all : include domains already marked triaged}
        {--limit=30 : how many rows to show}
        {--triage= : mark this registrable key triaged and exit}';

    protected $description = 'Show the unmatched-domain triage queue, or mark a domain triaged';

    public function handle(): int
    {
        $triage = (string) $this->option('triage');
        if ($triage !== '') {
            return $this->markTriaged($triage);
        }

        $query = DB::connection('pgsql')->table('catalog.unmatched_domains')
            ->orderByDesc('hits')
            ->orderByDesc('last_seen_at')
            ->limit(max(1, (int) $this->option('limit')));

        if (! $this->option('all')) {
            $query->whereNull('triaged_at');
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No untriaged unmatched domains.');

            return self::SUCCESS;
        }

        $this->table(
            ['domain', 'hits', 'shape', 'has rules?', 'first seen', 'last seen', 'triaged'],
            $rows->map(fn ($row) => [
                $row->registrable_key,
                $row->hits,
                $row->sample_path_shape ?? '—',
                // The column that decides what the work actually is: write a
                // new detector, or fix the patterns on one that already exists.
                $row->has_detectors ? 'yes — fix patterns' : 'no — write one',
                $row->first_seen_at,
                $row->last_seen_at,
                $row->triaged_at ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function markTriaged(string $key): int
    {
        $updated = DB::connection('pgsql')->table('catalog.unmatched_domains')
            ->where('registrable_key', $key)
            ->update(['triaged_at' => now()]);

        if ($updated === 0) {
            // A typo here would otherwise report success while leaving the
            // domain in the queue.
            $this->error("Not in the queue: {$key}.");

            return self::FAILURE;
        }

        $this->info("Marked {$key} triaged.");

        return self::SUCCESS;
    }
}
