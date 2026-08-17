<?php

namespace App\Console\Commands;

use App\Ingest\Runtime\SourceScheduler;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Run ONE ingest source right now, regardless of auto_sync / next_attempt_at.
 *
 * `ingest:dispatch` only claims auto_sync=true rows that are due; paid
 * (Actor/Metered) sources are provisioned auto_sync=false, so until now the
 * only way to exercise them was a hand-written tinker claim. This is that
 * recipe as a command, with the same claim/release discipline RunSourceJob
 * expects (claimOne → RunSourceJob → release in its finally).
 *
 * Select by --source=<uuid>, or --user=<handle> [--key=<source_key>] to run
 * every matching source. --sync runs inline (default queues to 'ingest').
 * Billing still goes through EffectLedger/ApifyBudget as usual.
 */
class IngestRunCommand extends Command
{
    protected $signature = 'ingest:run
        {--source= : ingest.sources id}
        {--user= : user handle — run all of their sources}
        {--key= : restrict --user to one source_key (e.g. spotify)}
        {--sync : run inline instead of queueing}';

    protected $description = 'Run one or more ingest sources now (ignores auto_sync/next_attempt_at).';

    public function handle(SourceScheduler $scheduler): int
    {
        $q = DB::table('ingest.sources');
        if ($id = $this->option('source')) {
            $q->where('id', $id);
        } elseif ($handle = $this->option('user')) {
            $userId = DB::table('core.users')->where('handle', $handle)->value('id');
            if (! $userId) {
                $this->error("No user '{$handle}'.");

                return self::FAILURE;
            }
            $q->where('user_id', $userId);
            if ($key = $this->option('key')) {
                $q->where('source_key', $key);
            }
        } else {
            $this->error('Give --source=<id> or --user=<handle> [--key=].');

            return self::FAILURE;
        }

        $rows = $q->get(['id', 'source_key', 'identifier', 'auto_sync', 'in_flight_since']);
        if ($rows->isEmpty()) {
            $this->warn('No matching sources.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            if ($row->in_flight_since !== null) {
                $this->warn("{$row->source_key} {$row->id} already in flight since {$row->in_flight_since} — skipped");

                continue;
            }
            $tick = (string) Str::uuid();
            if (! $scheduler->claimOne($row->id, $tick)) {
                $this->warn("{$row->source_key} {$row->id} could not be claimed — skipped");

                continue;
            }
            $this->line("→ {$row->source_key} {$row->identifier} ({$row->id})");
            if ($this->option('sync')) {
                RunSourceJob::dispatchSync($row->id);
                $run = DB::table('ingest.runs')->where('source_id', $row->id)->orderByDesc('started_at')->first(['outcome', 'records_seen', 'records_changed', 'effects_count', 'error_class', 'detail']);
                $this->info('   '.json_encode($run));
            } else {
                RunSourceJob::dispatch($row->id);
                $this->info('   queued on ingest');
            }
        }

        return self::SUCCESS;
    }
}
