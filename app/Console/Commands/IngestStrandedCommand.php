<?php

namespace App\Console\Commands;

use App\Ingest\Runtime\SourceScheduler;
use Illuminate\Console\Command;

// Watchdog: releases any ingest.sources claim that outlived a plausible run (a
// worker died mid-flight) so the source is eligible again, and reports what it
// found — a stranded claim is a real signal, not noise to sweep quietly.
class IngestStrandedCommand extends Command
{
    protected $signature = 'ingest:stranded';

    protected $description = 'Release stranded ingest source claims and report them.';

    public function handle(SourceScheduler $scheduler): int
    {
        $released = $scheduler->releaseStranded();

        if ($released === []) {
            $this->info('Ingest stranded sweep: nothing to release.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Ingest stranded sweep: released %d source(s): %s',
            count($released),
            implode(', ', $released)
        ));

        return self::SUCCESS;
    }
}
