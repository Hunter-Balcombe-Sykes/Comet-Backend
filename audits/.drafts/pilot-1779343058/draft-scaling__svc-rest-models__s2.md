- [ ] **CACHE-1** · P2 — EmailSubscription observer dispatches one job per save, risking queue amplification during bulk imports
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php (booted() method)
    - **Affects:** Queue workers and dashboard responsiveness when professionals bulk-import marketing consent lists.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace per-row job dispatch with a debounced or batch approach (e.g., collect changed subscription IDs into a set and dispatch a single job to re-sync all affected customers).
        - Alternatively, use a scheduled command that periodically reconciles the Customer `marketing_opt_in_cached` column against `EmailSubscription` status.
    - **Technical:** The `saved` observer fires on every insert/update of an `EmailSubscription` row. A bulk import of 1,000 subscriptions would dispatch 1,000 `SyncCustomerMarketingOptInJob` jobs instantly, each performing a `Customer` lookup and update. This is job-level write amplification that can saturate the queue and cause back-pressure. The canonical replacement from the commerce rebuild is a single debounced job or a trigger‑maintained cache, rather than N individual jobs.
    - **Plain English:** Whenever someone uploads a spreadsheet of email subscribers, the system currently kicks off a separate background task for each email address. If the list has a thousand entries, that’s a thousand tasks all competing for attention at once — like shouting 1,000 reminder messages into a single room. It’s far more efficient to run one task that handles the whole batch, or to do the sync overnight when things are quiet.
    - **Evidence:**
        ```php
        protected static function booted(): void
        {
            static::saved(function (self $subscription) {
                if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                    $professionalId = (string) $subscription->professional_id;
                    $email = (string) $subscription->email;
                    $isSubscribed = $subscription->status === 'subscribed';

                    DB::afterCommit(function () use ($professionalId, $email, $isSubscribed) {
                        \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                            $professionalId,
                            $email,
                            $isSubscribed,
                        );
                    });
                }
            });
        }
        ```
    - `[DRAFT, confidence: 0.7]`
