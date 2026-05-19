<?php

namespace App\Services\Accounts;

// DB::transaction is scoped to Eloquent mutations only.
// Jobs (KV sync, cache purge, event dispatch) MUST be dispatched AFTER the
// transaction closes. Do NOT use ::dispatchSync() inside the DB::transaction()
// closure — Cloudflare HTTP I/O under a row lock starves the connection pool.

use App\Enums\AccountType;
use App\Events\Accounts\AccountTypeTransitionEvent;
use App\Exceptions\InvalidAccountTypeTransition;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\Brand\BrandPartnerLinkService;
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
    public function __construct(private readonly BrandPartnerLinkService $brandPartnerLinks) {}

    /**
     * Transition a professional's account type.
     *
     * @param  array{brand_id?: string}  $context  Optional context; `brand_id` is
     *                                             required when transitioning TO partner.
     *
     * @throws InvalidAccountTypeTransition if the transition is forbidden.
     */
    public function transition(Professional $pro, AccountType $to, array $context = []): void
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
        // concurrent transitions racing on the same professional.
        DB::transaction(function () use ($pro, $to): void {
            /** @var Professional $locked */
            $locked = Professional::query()
                ->whereKey($pro->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check from inside the lock in case another request raced.
            $currentType = $locked->account_type;
            if ($currentType === $to) {
                // Already at the desired state — bail out of the transaction.
                // The outer $from variable retains the pre-lock value; callers
                // do not see the after-commit dispatch in this branch.
                return;
            }

            // Flip account_type. The DB trigger dual-writes professional_type
            // (§28.13) so we only need to set account_type here.
            $locked->account_type = $to;
            $locked->save();

            // Refresh $pro so post-commit callers see the new state.
            $pro->setRawAttributes($locked->getAttributes(), true);
            $pro->syncOriginal();
        });

        // ----------------------------------------------------------------
        // Post-commit dispatches — NEVER move these inside DB::transaction.
        // ----------------------------------------------------------------

        // Sync new account_type routing entry to Cloudflare KV.
        SyncSubdomainToKvJob::dispatch((string) $pro->id);

        // TODO §28.7: dispatch CloudflareCachePurgeJob($pro->handle) here once the job class lands.

        // Notify listeners (cache invalidation, audit log, future toggles).
        AccountTypeTransitionEvent::dispatch($pro, $from, $to);
    }
}
