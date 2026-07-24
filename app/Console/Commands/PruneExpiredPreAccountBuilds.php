<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\User\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Daily teardown of expired (never-claimed) pre-account builds and stale failed
// builds. Ordering mirrors the two other forceDelete-based teardown paths —
// AccountDeletionService::purge() and StaffUserController::forceDestroy() —
// capture-before-cascade discipline: snapshot handle + active custom domain
// before the row disappears (EDGE-1), clean up R2/observers via Eloquent (not
// DB cascade, which never touches storage or fires model events), THEN
// forceDelete. No Supabase-auth step (provisional users have no auth user —
// auth_user_id is NULL, unlike purge()'s Step 1). The claim/prune race is
// settled by FOR UPDATE SKIP LOCKED: a user row locked by an in-flight claim
// transaction (ClaimSiteService::claim, which holds lockForUpdate() for the
// whole claim) is skipped this run and re-evaluated next run; a COMMITTED
// claim flips the row out of the live() predicate entirely (claimed_at set),
// so it's never selected as a candidate again.
class PruneExpiredPreAccountBuilds extends Command
{
    protected $signature = 'builds:prune-expired {--dry-run : Report counts without deleting}';

    protected $description = 'Hard-delete expired unclaimed pre-account builds (site teardown before row cascade).';

    public function handle(SiteCacheService $siteCache, AccountDeletionService $deletion): int
    {
        $cutoff = now();
        $failedCutoff = now()->subHours((int) config('partna.pre_account.failed_prune_hours', 24));

        // live() = claimed_at IS NULL (the same partial-unique-index predicate
        // the claim flow relies on) — a claimed build is never a candidate,
        // independent of the SKIP LOCKED race guard below.
        // NULL expires_at = unapproved early-access build → never-expire (spec §3.5).
        $query = PreAccountBuild::live()
            ->where(fn ($q) => $q
                ->where(fn ($qq) => $qq->whereNotNull('expires_at')->where('expires_at', '<', $cutoff))
                ->orWhere(fn ($qq) => $qq->where('build_state', PreAccountBuild::STATE_FAILED)->where('updated_at', '<', $failedCutoff)));

        if ($this->option('dry-run')) {
            $this->info("Would prune {$query->count()} build(s).");

            return self::SUCCESS;
        }

        // R2-SCHED-3: stream candidate ids via cursor() instead of pluck()'s materialized
        // Collection — the loop body already re-queries each build fresh inside its own
        // transaction, so nothing depends on the full id list being in memory at once. A
        // separate count() (below/at completion) covers the logging that used to read
        // $candidates->count(). Mirrors PruneCompletedExportsCommand / SweepStaleExportsCommand.
        $totalCandidates = $query->count();

        $pruned = 0;
        $failed = 0;
        foreach ($query->select('id')->cursor() as $build) {
            $buildId = $build->id;
            // Fault isolation per candidate — mirrors PurgeSoftDeleted's per-item
            // catch-report-continue pattern. A transient cache/Redis fault (e.g.
            // invalidateSite() below) must not abort the whole nightly sweep; the
            // transaction has already rolled back by the time the exception
            // reaches here, so this candidate is simply skipped and re-evaluated
            // next run — the transaction's own rollback semantics are untouched.
            try {
                $pruned += (int) DB::connection('pgsql')->transaction(function () use ($buildId, $siteCache, $deletion) {
                    $build = PreAccountBuild::query()->whereKey($buildId)->first();
                    if (! $build || $build->claimed_at !== null) {
                        return false; // claimed since candidate selection
                    }

                    // SKIP LOCKED: a claim transaction holds this row's lock — skip,
                    // re-evaluate next run. (SQLite ignores the lock clause; the
                    // Postgres behavior — never block, never steal a mid-claim row —
                    // is the contract this test suite can't directly exercise.)
                    $user = User::query()->whereKey($build->user_id)
                        ->lock('for update skip locked')->first();
                    if (! $user) {
                        return false;
                    }

                    // Snapshot everything the post-delete steps need — mirrors
                    // purge()/forceDestroy()'s capture-before-cascade discipline.
                    $site = $user->site;
                    $wasPublished = (bool) $site?->is_published;
                    $handle = $user->handle;
                    // EDGE-1: once the site row is gone, $user->site can't resolve
                    // the custom-domain KV pointer for retirement — capture it now
                    // so it can be explicitly retired after forceDelete (mirrors
                    // purge() Step 3a / StaffUserController::forceDestroy()). In
                    // practice a provisional (unclaimed, unauthenticated) owner
                    // can't reach the custom-domain connect flow, so this is
                    // defensive, not a currently-reachable path.
                    $retireCustomDomain = ($site && ($site->custom_domain_status ?? null) === 'active')
                        ? $site->custom_domain
                        : null;

                    // 1. Connection rows via Eloquent (NOT cascade) so the observer
                    //    reclaims mirrored R2 folders (DeleteMirroredMediaJob).
                    IntegrationConnection::query()->where('user_id', $user->id)->get()
                        ->each->delete();

                    // 2. R2 artifacts for any site_media before the row cascade
                    //    (DB cascades never touch storage) — reuses purge()'s seam.
                    $deletion->purgeMediaArtifacts($user);

                    // 3. App-cache bust (direct call — teardown must not depend on
                    //    observer ordering) + edge purge for a site that was live.
                    if ($site) {
                        $siteCache->invalidateSite($site);
                        if ($wasPublished && $handle) {
                            CloudflareCachePurgeJob::dispatch($handle)->afterCommit();
                        }
                    }

                    // 4. Delete the build audit row itself — this is the row the
                    //    command is named after, so its removal must not depend on
                    //    the core.pre_account_builds.user_id ON DELETE CASCADE (the
                    //    brief's own rule: never rely on DB cascade for anything the
                    //    command itself must guarantee). No observer is registered
                    //    for PreAccountBuild, so there's no ordering concern; a plain
                    //    delete() is already a hard delete (no SoftDeletes trait).
                    $build->delete();

                    // 5. Hard delete the professional row. UserObserver::deleted
                    //    (afterCommit) dispatches the KV handle retire with the
                    //    captured handle — the single KV-writer path (a hard-deleted
                    //    row can't be looked up, so the observer passes the
                    //    in-memory handle it still holds). On Postgres, site.sites /
                    //    site.platform_connections both cascade ON DELETE CASCADE
                    //    from core.users — the explicit deletes above exist only to
                    //    fire observers/storage cleanup before rows disappear, not to
                    //    do the deleting themselves; see PruneExpiredBuildsTest for
                    //    the SQLite-safe assertions (no FK enforcement there).
                    //
                    // Pre-null the two append-only audit links first (mirrors
                    // AccountDeletionService::purge()) — forceDelete's ON DELETE SET
                    // NULL cascade into audit.staff_audit_log / handle_change_log
                    // would otherwise trip their reject-mutation triggers (#308).
                    // Deliberately unwrapped: the per-candidate try/catch around this
                    // whole transaction already reports and continues on any failure,
                    // and the transaction has already rolled back by the time it's
                    // caught — an inner catch here would only duplicate that.
                    if (DB::connection('pgsql')->getDriverName() === 'pgsql') {
                        DB::connection('pgsql')->select('SELECT audit.null_user_audit_links(?)', [$user->id]);
                    }
                    $user->forceDelete();

                    // EDGE-1: retire the now-orphaned custom-domain KV pointer.
                    // afterCommit so it fires once this build's transaction commits,
                    // alongside the observer's handle retire above.
                    if ($retireCustomDomain) {
                        SyncSubdomainToKvJob::dispatch((string) $user->id, $handle, $retireCustomDomain)->afterCommit();
                    }

                    return true;
                });
            } catch (\Throwable $e) {
                // The transaction has already rolled back by this point — report
                // and move on so one candidate's transient fault (e.g. a Redis
                // outage inside invalidateSite()) can't abort the whole sweep.
                // This build is simply re-evaluated next run. build_id logged
                // alongside report() (mirrors purge()'s catch-context discipline)
                // so a Nightwatch alert points straight at the stuck candidate.
                $failed++;
                Log::warning('pre_account.prune.candidate_failed', ['build_id' => $buildId, 'error' => $e->getMessage()]);
                report($e);
            }
        }

        Log::info('pre_account.prune.completed', [
            'candidates' => $totalCandidates,
            'pruned' => $pruned,
            'failed' => $failed,
        ]);
        $this->info("Pruned {$pruned} of {$totalCandidates} candidate build(s)".($failed > 0 ? " ({$failed} failed, will retry next run)." : '.'));

        return self::SUCCESS;
    }
}
