<?php

namespace App\Services\Accounts;

// DB::transaction is scoped to Eloquent mutations only.
// Jobs (KV sync, cache purge, event dispatch) MUST be dispatched AFTER the
// transaction closes. Do NOT use ::dispatchSync() inside the DB::transaction()
// closure — Cloudflare HTTP I/O under a row lock starves the connection pool.

use App\Enums\AccountType;
use App\Events\Accounts\AccountTypeTransitionEvent;
use App\Exceptions\InvalidAccountTypeTransition;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;

/**
 * Canonical gateway for all post-signup `account_type` mutations.
 *
 * Enforces non-negotiable transition rules (§50 #1, #5, #12):
 *   - Brand is terminal: no transition FROM brand, no transition TO brand.
 *   - Allowed: individual ↔ partner (both directions).
 *   - Same-state no-ops return early without any DB write or job dispatch.
 *
 * Transaction discipline (audit SCALE-2):
 *   The DB::transaction wraps ONLY Eloquent mutations (lockForUpdate + save).
 *   Job dispatches (SyncSubdomainToKvJob, AccountTypeTransitionEvent) happen
 *   outside the transaction via DB::afterCommit(). See class-level comment.
 *
 * @see docs/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-2.md §28.4
 */
class AccountTypeTransitionService
{
    /**
     * Transition a professional's account type.
     *
     * The BrandPartnerLink (if transitioning TO partner) is created by the
     * caller before this method runs — this service only flips account_type.
     *
     * @throws InvalidAccountTypeTransition if the transition is forbidden.
     */
    public function transition(Professional $pro, AccountType $to): void
    {
        $from = $pro->account_type;

        // account_type must always be set at this point (post-backfill).
        // Guard defensively so the error message is clear.
        if (! $from instanceof AccountType) {
            throw new InvalidAccountTypeTransition(
                "Professional {$pro->id} has no account_type set — cannot transition."
            );
        }

        // Brand is terminal in both directions.
        if ($from === AccountType::Brand) {
            throw new InvalidAccountTypeTransition(
                'Cannot transition FROM brand — brand account type is terminal.'
            );
        }

        if ($to === AccountType::Brand) {
            throw new InvalidAccountTypeTransition(
                'Cannot transition TO brand — brand is set at signup only.'
            );
        }

        // Same-state no-op: no mutations, no job dispatches.
        if ($from === $to) {
            return;
        }

        // Perform the mutation inside a transaction — row-locked to prevent
        // concurrent transitions racing on the same professional. The closure
        // returns true only if account_type was actually flipped; false when a
        // concurrent request already won the race (audit ACCT-1) — the
        // post-commit dispatches MUST be skipped in that branch so a phantom
        // AccountTypeTransitionEvent never fires for a transition that the
        // losing request did not perform.
        $mutated = DB::transaction(function () use ($pro, $to): bool {
            /** @var Professional $locked */
            $locked = Professional::query()
                ->whereKey($pro->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check from inside the lock in case another request raced.
            if ($locked->account_type === $to) {
                // Already at the desired state — a concurrent request committed
                // this transition first. Bail out so the caller skips the
                // post-commit dispatches.
                return false;
            }

            // Flip account_type. The DB trigger dual-writes professional_type
            // (§28.13) so we only need to set account_type here.
            $locked->account_type = $to;
            $locked->save();

            // Refresh $pro so post-commit callers see the new state.
            $pro->setRawAttributes($locked->getAttributes(), true);
            $pro->syncOriginal();

            // setRawAttributes mutates $pro in place, so AccountCapabilities'
            // WeakMap memo (keyed by object identity) still holds the pre-
            // transition capability set. Drop it (audit ACCT-2) so the next
            // AccountCapabilities::for($pro) rebuilds against the new type.
            AccountCapabilities::flushCache();

            return true;
        });

        // A concurrent request already performed this transition — nothing to
        // dispatch.
        if (! $mutated) {
            return;
        }

        // ----------------------------------------------------------------
        // Post-commit dispatches — NEVER move these inside DB::transaction.
        // ----------------------------------------------------------------

        // Sync new account_type routing entry to Cloudflare KV.
        SyncSubdomainToKvJob::dispatch((string) $pro->id);

        // Purge edge cache for the pro's public profile so the next request
        // observes the new account_type routing (§28.7). Handle is empty for
        // ex-staff / freshly-created pros that haven't claimed a handle yet —
        // job is a no-op in that case (defensive guard at the dispatch site).
        $handle = strtolower(trim((string) ($pro->handle ?? '')));
        if ($handle !== '') {
            CloudflareCachePurgeJob::dispatch($handle);
        }

        // Notify listeners (cache invalidation, audit log, future toggles).
        AccountTypeTransitionEvent::dispatch($pro, $from, $to);
    }
}
