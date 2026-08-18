<?php

namespace App\Jobs\Content;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Re-project a handful of ingest sources off their landed record versions
 * (`ingest:project --source=`), the same replay the CLI runs — used after an
 * owner identity decision so the resolver re-binds with the new verdicts.
 */
class ReprojectSourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    /** @param list<string> $sourceIds */
    public function __construct(public string $userId, public array $sourceIds)
    {
        $this->onQueue('ingest');
    }

    public function handle(): void
    {
        foreach ($this->sourceIds as $sourceId) {
            Artisan::call('ingest:project', ['--source' => $sourceId]);
        }
    }
}
