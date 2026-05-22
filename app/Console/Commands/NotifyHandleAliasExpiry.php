<?php

namespace App\Console\Commands;

use App\Mail\HandleAliasExpiringMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Emails professionals when their old handle alias is about to be released from the redirect pool.
 * Runs at T-3 days and T-1 day before expiry, stamping notified_t3_at / notified_t1_at to prevent
 * duplicate sends across runs.
 */
class NotifyHandleAliasExpiry extends Command
{
    protected $signature = 'handles:notify-expiry';
    protected $description = 'Email pros when their old handle aliases are about to be released.';

    public function handle(): int
    {
        $this->dispatchBucket('notified_t3_at', now()->addDays(3), 't3');
        $this->dispatchBucket('notified_t1_at', now()->addDay(),   't1');

        return self::SUCCESS;
    }

    private function dispatchBucket(string $stampColumn, \DateTimeInterface $window, string $bucket): void
    {
        DB::connection('pgsql')
            ->table('site.professional_handle_aliases')
            ->whereNull($stampColumn)
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $window)
            ->chunkById(200, function ($aliases) use ($stampColumn, $bucket) {
                foreach ($aliases as $alias) {
                    $email = DB::connection('pgsql')
                        ->table('core.users')
                        ->where('id', $alias->professional_id)
                        ->value('primary_email');

                    if ($email) {
                        Mail::to($email)->queue(new HandleAliasExpiringMail($alias, $bucket));
                    }

                    DB::connection('pgsql')
                        ->table('site.professional_handle_aliases')
                        ->where('id', $alias->id)
                        ->update([$stampColumn => now()->toDateTimeString()]);
                }
            });
    }
}
