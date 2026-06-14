<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\User\User;
use Illuminate\Console\Command;

// Re-syncs the Cloudflare KV subdomain routing table for one professional
// (by id) or every professional (--all). Useful after raw-SQL data fixes
// that bypass Eloquent observers, or after rolling out alias support so
// historical handles get their KV entries populated.
class BackfillSubdomainKvCommand extends Command
{
    protected $signature = 'partna:backfill-subdomain-kv
                            {user_id? : Single professional UUID to resync. Omit with --all to do every professional.}
                            {--all : Resync every professional with a handle. Mutually exclusive with user_id.}
                            {--queue : Dispatch via the queue (default: synchronous).}';

    protected $description = 'Resyncs Cloudflare KV subdomain routing entries (handle + aliases) for one or all professionals.';

    public function handle(): int
    {
        $proId = $this->argument('user_id');
        $all = (bool) $this->option('all');
        $useQueue = (bool) $this->option('queue');

        if ($proId && $all) {
            $this->error('Pass either a user_id OR --all, not both.');

            return self::FAILURE;
        }

        if (! $proId && ! $all) {
            $this->error('Pass a user_id or --all.');

            return self::FAILURE;
        }

        $dispatch = function (string $id) use ($useQueue): void {
            if ($useQueue) {
                SyncSubdomainToKvJob::dispatch($id);
                $this->line("  queued: {$id}");
            } else {
                SyncSubdomainToKvJob::dispatchSync($id);
                $this->line("  done:   {$id}");
            }
        };

        if ($all) {
            // SCALE-2: stream every professional with chunkById instead of plucking
            // all ids into memory — bounded memory at 10k+ users.
            $query = User::query()
                ->whereNotNull('handle')
                ->where('handle', '!=', '');

            $this->info('Resyncing KV for '.$query->count().' professional(s) (mode: '.($useQueue ? 'queue' : 'sync').').');

            $query->select('id')->chunkById(500, function ($users) use ($dispatch): void {
                foreach ($users as $user) {
                    $dispatch((string) $user->id);
                }
            });
        } else {
            $this->info('Resyncing KV for 1 professional(s) (mode: '.($useQueue ? 'queue' : 'sync').').');
            $dispatch((string) $proId);
        }

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
