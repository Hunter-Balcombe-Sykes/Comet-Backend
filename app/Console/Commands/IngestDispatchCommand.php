<?php

namespace App\Console\Commands;

use App\Ingest\Runtime\SourceScheduler;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Dispatcher (not a worker): claims up to --limit due ingest.sources rows via
// SourceScheduler::claimDue() — the conditional-UPDATE claim IS the concurrency
// guard, so this command can run frequently and overlap-safely elsewhere without
// ever double-claiming a source — and fans out one RunSourceJob per claim onto
// the 'ingest' queue. --sync runs them inline instead, for local/testing.
class IngestDispatchCommand extends Command
{
    protected $signature = 'ingest:dispatch
        {--limit=25 : Max sources to claim this tick}
        {--sync : Run claimed sources inline instead of queueing them}';

    protected $description = 'Claim due ingest sources and dispatch a RunSourceJob for each.';

    public function handle(SourceScheduler $scheduler): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sync = (bool) $this->option('sync');

        // One id per dispatch tick, not per source: SourceScheduler stamps every
        // claim this tick wins with it purely as a diagnostic "which tick grabbed
        // this" trail — release() never reads it back, so it need not be unique
        // per source.
        $tickId = (string) Str::uuid();

        $claimed = $scheduler->claimDue($limit, $tickId);

        foreach ($claimed as $source) {
            try {
                if ($sync) {
                    RunSourceJob::dispatchSync($source['id']);
                } else {
                    RunSourceJob::dispatch($source['id']);
                }
            } catch (Throwable $e) {
                // A --sync run executes inline, so a bad row can throw here.
                // One failure must not abort the rest of the tick's batch —
                // RunSourceJob's own finally already released this row's claim.
                report($e);
                Log::error('ingest.dispatch.source_failed', [
                    'source_id' => $source['id'],
                    'sync' => $sync,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Ingest dispatch: claimed %d source(s), %s.',
            count($claimed),
            $sync ? 'ran inline' : "queued to 'ingest'"
        ));

        return self::SUCCESS;
    }
}
