<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Core\User\User;
use App\Services\Accounts\LifestyleConnectionCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-shot data remediation: soft-delete orphaned "lifestyle" platform
 * connections (Listen / Community / Other — the STANDARD_ONLY pages) for
 * Business Partna accounts.
 *
 * Business dashboards hide those integration groups, so a connection carried
 * over from when the account was `partna` is un-removable there. The switch is
 * now cleaned at the source (UserObserver), but accounts that switched BEFORE
 * that shipped still hold orphans — this brings existing data into compliance.
 *
 * Idempotent — already-soft-deleted connections are excluded (SoftDeletes
 * scope), so re-running is safe. Always preview with --dry-run first.
 */
class CleanupOrphanedLifestyleConnections extends Command
{
    protected $signature = 'connections:cleanup-lifestyle-orphans
        {--dry-run : Count what would be soft-deleted without writing}';

    protected $description = 'Soft-delete orphaned lifestyle (Listen/Community/Other) connections for Business accounts. Idempotent.';

    public function handle(LifestyleConnectionCleanup $cleanup): int
    {
        $dryRun = (bool) $this->option('dry-run');

        Log::info('cleanup-lifestyle-orphans started', [
            'operator' => get_current_user() ?: 'unknown',
            'dry_run' => $dryRun,
        ]);

        $usersAffected = 0;
        $connectionsRemoved = 0;

        User::query()
            ->where('account_type', AccountType::Business->value)
            ->chunkById(200, function ($users) use ($cleanup, $dryRun, &$usersAffected, &$connectionsRemoved): void {
                foreach ($users as $user) {
                    $count = $cleanup->forUser($user, $dryRun);
                    if ($count > 0) {
                        $usersAffected++;
                        $connectionsRemoved += $count;
                        $this->line(sprintf(
                            '%s %s (%s): %d connection(s)',
                            $dryRun ? 'would remove' : 'removed',
                            $user->handle ?? '(no handle)',
                            $user->id,
                            $count,
                        ));
                    }
                }
            });

        $verb = $dryRun ? 'Would remove' : 'Removed';
        $this->info(sprintf('%s %d lifestyle connection(s) across %d business account(s).', $verb, $connectionsRemoved, $usersAffected));

        Log::info('cleanup-lifestyle-orphans finished', [
            'dry_run' => $dryRun,
            'users_affected' => $usersAffected,
            'connections_removed' => $connectionsRemoved,
        ]);

        return self::SUCCESS;
    }
}
