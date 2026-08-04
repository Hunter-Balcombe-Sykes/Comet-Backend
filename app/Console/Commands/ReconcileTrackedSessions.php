<?php

namespace App\Console\Commands;

use App\Services\Auth\TokenRevocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Scheduled backstop for the Active Sessions list. listSessionsForUser()
 * reconciles on read, but that only helps users who open the page — this sweep
 * self-heals the tracked sets for everyone else, dropping session_ids Supabase
 * no longer considers live (the residue of client-only logouts that never call
 * /api/sessions/logout).
 */
class ReconcileTrackedSessions extends Command
{
    protected $signature = 'sessions:reconcile-tracked';

    protected $description = 'Drop tracked "active" sessions Supabase no longer considers live (backstop for the Active Sessions list).';

    // The literal that separates the Laravel Redis key prefix from the per-user
    // id in `…auth:user-sessions:<uid>`. Matching/splitting on this marker keeps
    // the SCAN agnostic to the key prefix, which differs across environments.
    private const MARKER = 'auth:user-sessions:';

    public function handle(TokenRevocationService $revocation): int
    {
        // Drive the native phpredis client directly. The Laravel connection's
        // own scan() (options-array form) is shadowed for static analysis by its
        // `@mixin \Redis`, which resolves to the native scan(&$it, $pattern,
        // $count) signature — so we use that signature against the real client.
        // The app runs phpredis everywhere; on any other client there is nothing
        // to reconcile, so skip.
        //
        // `app`, not the bare facade default: this SCANs the same auth:user-sessions:*
        // keyspace TokenRevocationService writes, on DB 0, but a console command
        // is not a queue worker, so it takes the request-appropriate 3.0s bound
        // rather than `default`'s 15.0s (reserved for BLPOP). See drill 03 (2026-08-05).
        $client = Redis::connection('app')->client();
        if (! $client instanceof \Redis) {
            return self::SUCCESS;
        }

        $usersReconciled = 0;   // live lookup succeeded (pruned 0+ members)
        $usersUnavailable = 0;  // live lookup failed open — could not reconcile
        $membersDropped = 0;

        // SCAN (never KEYS) so we don't block Redis on a large keyspace. The
        // leading/trailing wildcards match the marker with or without the key
        // prefix; strpos()/substr() then recover the logical uid either way.
        //
        // The iterator MUST start at null (phpredis's "begin iteration"
        // sentinel); it returns to 0 only when iteration is complete. Under the
        // default SCAN_NORETRY option a batch can come back empty (false) while
        // more keys remain, so we keep looping until the iterator hits 0 rather
        // than breaking on an empty batch.
        $iterator = null;
        do {
            $keys = $client->scan($iterator, '*'.self::MARKER.'*', 500);
            if ($keys === false) {
                continue; // empty batch — keep scanning until the iterator is 0
            }

            foreach ($keys as $key) {
                $pos = strpos((string) $key, self::MARKER);
                if ($pos === false) {
                    continue;
                }
                $userId = substr((string) $key, $pos + strlen(self::MARKER));
                if ($userId === '') {
                    continue;
                }

                try {
                    // No keepSessionId here (unlike the read path): the sweep has
                    // no "current request". Untracking a live session that races
                    // Supabase's state is self-correcting — untrack() does not
                    // revoke(), so the user's token stays valid and the JWT
                    // middleware re-adds the session on its next request. A null
                    // return means the live lookup failed open for this user.
                    $dropped = $revocation->reconcileTrackedForUser($userId);
                    if ($dropped === null) {
                        $usersUnavailable++;
                    } else {
                        $usersReconciled++;
                        $membersDropped += $dropped;
                    }
                } catch (\Throwable $e) {
                    // Defence-in-depth: reconcileTrackedForUser fails open rather
                    // than throwing, but one bad user must never halt the sweep.
                    $usersUnavailable++;
                    Log::warning('sessions.reconcile.user_failed', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } while ((int) $iterator !== 0);

        Log::info('sessions.reconcile.completed', [
            'users_reconciled' => $usersReconciled,
            'users_unavailable' => $usersUnavailable,
            'members_dropped' => $membersDropped,
        ]);

        // Fail-open is silent per user, but a run that reached users yet
        // reconciled NONE of them means the live-session lookup is systemically
        // down (broken grant/owner, RPC gone) — the backstop is dead. Surface
        // that to Nightwatch via report(); a bare Log line would not alert.
        if ($usersUnavailable > 0 && $usersReconciled === 0) {
            report(new \RuntimeException(
                "sessions:reconcile-tracked: live-session lookup unavailable for all {$usersUnavailable} scanned user(s) — reconciliation is not running."
            ));
        }

        $this->info(sprintf(
            'Reconciled %d user set(s): dropped %d dead session(s), %d unavailable.',
            $usersReconciled,
            $membersDropped,
            $usersUnavailable,
        ));

        return self::SUCCESS;
    }
}
