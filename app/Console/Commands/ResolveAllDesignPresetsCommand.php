<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Design\Presets\DesignPresetResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Backfill sweep (spec §1c): re-resolve every site with ≥1 active integration
// connection through DesignPresetResolver::resolveForUser, so newly-added factors
// and launch recipes reach EXISTING users — the engine otherwise only re-resolves
// on a connection change. Idempotent (resolveForUser is), chunked, with:
//   --dry-run     report what WOULD change, write nothing (resolve in a rolled-back
//                 transaction so the "changed" delta is real but never persisted).
//   --user=<id>   limit to a single user (by id, or handle as a fallback).
// Progress + a final tally go to stdout. One-shot contributions stay frozen exactly
// as in the live path — this never re-stamps a resolved archetype.
class ResolveAllDesignPresetsCommand extends Command
{
    protected $signature = 'design:resolve-all
        {--dry-run : Report what would change without writing anything}
        {--user= : Limit to a single user (id, or handle if no id matches)}';

    protected $description = 'Re-resolve design-kit preset contributions for every site with an active connection (backfills existing users after adding factors/recipes)';

    public function handle(DesignPresetResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userOption = $this->option('user');

        $userIds = $this->targetUserIds($userOption);
        if ($userIds === null) {
            $this->error("No user found for [{$userOption}].");

            return self::FAILURE;
        }

        if ($userIds->isEmpty()) {
            $this->info('No sites with an active connection to resolve.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%sResolving %d site(s) with active connections…',
            $dryRun ? '[dry-run] ' : '',
            $userIds->count(),
        ));

        $processed = 0;
        $changed = 0;
        $failed = 0;

        foreach ($userIds->chunk(100) as $chunk) {
            $users = User::query()->whereIn('id', $chunk)->get();
            foreach ($users as $user) {
                $processed++;
                try {
                    $didChange = $dryRun
                        ? $this->resolveDryRun($resolver, $user)
                        : $resolver->resolveForUser($user);
                } catch (\Throwable $e) {
                    report($e);
                    $failed++;
                    $this->warn("  ! {$user->handle}: resolve failed — {$e->getMessage()}");

                    continue;
                }

                if ($didChange) {
                    $changed++;
                    $verb = $dryRun ? 'would change' : 'updated';
                    $this->line("  · {$user->handle}: {$verb}");
                }

                // On a real run, mirror the job's cache roll so the public payload
                // reflects the new contributions. Only when something moved.
                if (! $dryRun && $didChange) {
                    $user->site()->first()?->touch();
                }
            }
        }

        $this->info(sprintf(
            '%sDone. %d processed, %d %s, %d failed.',
            $dryRun ? '[dry-run] ' : '',
            $processed,
            $changed,
            $dryRun ? 'would change' : 'changed',
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolve inside a transaction that is ALWAYS rolled back, so we learn whether
     * the resolve WOULD change anything without persisting a single row. Uses a
     * sentinel exception to unwind so the outer state is untouched even though
     * resolveForUser itself opens a (now-nested/savepoint) transaction.
     */
    private function resolveDryRun(DesignPresetResolver $resolver, User $user): bool
    {
        $changed = false;
        try {
            DB::connection('pgsql')->transaction(function () use ($resolver, $user, &$changed): void {
                $changed = $resolver->resolveForUser($user);

                // Abort the write — we only wanted the delta.
                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            // Expected — the transaction rolled back cleanly.
        }

        return $changed;
    }

    /**
     * The distinct user ids that own a site with ≥1 active connection, optionally
     * narrowed to one user. Returns null when a --user filter matches nobody.
     *
     * @return Collection<int, string>|null
     */
    private function targetUserIds(?string $userOption): ?Collection
    {
        $filterUserId = null;
        if ($userOption !== null && trim($userOption) !== '') {
            $needle = trim($userOption);
            // Prefer an exact id match; fall back to handle for convenience.
            $user = User::query()->whereKey($needle)->first()
                ?? User::query()->where('handle_lc', strtolower($needle))->first();
            if ($user === null) {
                return null;
            }
            $filterUserId = (string) $user->id;
        }

        // Distinct owners of an active connection — pushed to Postgres so we never
        // hydrate a connection model just to read its user_id.
        return IntegrationConnection::query()
            ->active()
            ->when($filterUserId, fn ($q) => $q->where('user_id', $filterUserId))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): string => (string) $id)
            ->values();
    }
}

/** Internal sentinel to unwind the dry-run transaction. */
class DryRunRollback extends \RuntimeException {}
