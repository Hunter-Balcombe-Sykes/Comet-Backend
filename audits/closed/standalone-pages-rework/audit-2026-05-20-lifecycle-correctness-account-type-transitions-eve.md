# Lifecycle Correctness — Account Type Transitions, Events & Listeners Audit — 2026-05-20

**Branch:** development
**Lens:** lifecycle correctness account type transitions events listeners
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Accounts/AccountTypeTransitionService.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Listeners/Accounts/InvalidateProfessionalCacheOnTransition.php
- app/Listeners/Accounts/LogAccountTypeTransition.php
- app/Listeners/Accounts/SetTransitionBannerOnTransition.php
- app/Listeners/Accounts/SyncNotificationPreferencesOnTransition.php
- app/Listeners/Accounts/ToggleStripeRequirementBannerOnTransition.php
- app/Events/Accounts/AccountTypeTransitionEvent.php
- app/Observers/Core/BrandPartnerLinkObserver.php
- app/Observers/Professional/ProfessionalObserver.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#ACCT-1** · P1 — Post-commit dispatches fire unconditionally when the inner lock re-check finds a race already resolved
    - **Where:** app/Services/Accounts/AccountTypeTransitionService.php:88–109
    - **Affects:** Any account where two concurrent transition requests arrive simultaneously (e.g., a user double-tapping a button, or a staff action racing an invite-accept webhook). The losing request fires `SyncSubdomainToKvJob`, `CloudflareCachePurgeJob`, and `AccountTypeTransitionEvent` with a `$from` value that was snapshotted before the lock — so listeners receive a transition that did not happen.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the `DB::transaction` closure return type from `void` to `bool`; return `true` when a save occurred, `false` when the re-check bail-out fired.
        - Capture the return value: `$mutated = DB::transaction(...)`.
        - Wrap the three post-commit dispatches in `if ($mutated)`.
        - On the bail-out path, still call `$pro->setRawAttributes($locked->getAttributes(), true)` + `syncOriginal()` so the caller's in-memory model reflects the actual DB state after the race.
    - **Technical:** `DB::transaction` accepts a closure that returns `void`. The bail-out path (`if ($currentType === $to) { return; }`) escapes the closure but leaves `$mutated` indeterminate — Laravel propagates the closure's return value, so returning `false` from the closure is safe and detectable. The three dispatches below the transaction block are unconditional, so the second concurrent request dispatches `SyncSubdomainToKvJob` (idempotent but wasteful), `CloudflareCachePurgeJob` (unnecessary Cloudflare API call), and — most importantly — `AccountTypeTransitionEvent::dispatch($pro, $from, $to)` where `$from` is the pre-lock snapshot, not the value that was actually in the database when the lock was acquired. Listeners such as `SyncNotificationPreferencesOnTransition` and `ToggleStripeRequirementBannerOnTransition` will process a phantom transition. The docblock comment on the bail-out branch ("callers do not see the after-commit dispatch in this branch") is factually incorrect in the current implementation.
    - **Plain English:** Imagine two requests both try to promote the same account to Partner at the same instant. The first one wins, makes the change, and fires off all the right follow-up work. The second one peeks inside the database, sees the work is already done, and correctly backs out — but then still sends the "account type changed" signal, clears the Cloudflare edge cache, and queues up a KV sync job, all for a change that never happened in this request. The notification preferences get re-pruned, the Stripe banner gets re-toggled, and unnecessary API calls go out to Cloudflare — all ghost work triggered by a non-event. The fix is one boolean check around those three dispatches.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($pro, $to): void {
            $locked = Professional::query()
                ->whereKey($pro->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentType = $locked->account_type;
            if ($currentType === $to) {
                // Already at the desired state — bail out of the transaction.
                // The outer $from variable retains the pre-lock value; callers
                // do not see the after-commit dispatch in this branch.
                return;
            }

            $locked->account_type = $to;
            $locked->save();

            $pro->setRawAttributes($locked->getAttributes(), true);
            $pro->syncOriginal();
        });

        // ----------------------------------------------------------------
        // Post-commit dispatches — NEVER move these inside DB::transaction.
        // ----------------------------------------------------------------

        SyncSubdomainToKvJob::dispatch((string) $pro->id);

        $handle = strtolower(trim((string) ($pro->handle ?? '')));
        if ($handle !== '') {
            CloudflareCachePurgeJob::dispatch($handle);
        }

        AccountTypeTransitionEvent::dispatch($pro, $from, $to);
        ```

---

## P2 — Should fix

- [ ] **#ACCT-2** · P2 — `AccountCapabilities::for()` WeakMap cache serves stale capabilities to listeners after `setRawAttributes` mutates the Professional in-place
    - **Where:** app/Services/Accounts/AccountCapabilities.php:44–62; app/Services/Accounts/AccountTypeTransitionService.php:97–100
    - **Affects:** Synchronous listeners on `AccountTypeTransitionEvent` that call `AccountCapabilities::for($event->professional)` — specifically `SyncNotificationPreferencesOnTransition` (may prune or retain preference rows using the wrong capability set) and `ToggleStripeRequirementBannerOnTransition` (may set or clear the Stripe banner using the old account type's `requires_stripe_connect` value).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `AccountCapabilities::flushCache()` in `AccountTypeTransitionService` immediately after `$pro->setRawAttributes(...); $pro->syncOriginal();` — before the post-commit dispatches fire. The `flushCache()` method already exists for exactly this purpose (it is documented as "Tests call this when reassigning fields on a memoized Professional").
        - No other callers are affected: `flushCache()` resets a `static` WeakMap, and the map is per-process, so flushing here only impacts subsequent calls in the same request lifecycle.
    - **Technical:** `AccountCapabilities::for()` memoizes the computed `AccountCapabilitySet` in a `static WeakMap` keyed by PHP object identity. `AccountTypeTransitionService` mutates the same `$pro` object in-place via `setRawAttributes` after the transaction closes. Because the object reference is unchanged, `isset(self::$cache[$pro])` returns `true` on the next call, and the WeakMap returns the stale set built from the pre-transition `account_type`. For an individual→partner transition, `SyncNotificationPreferencesOnTransition` would compute `notification_categories` as `'profile,platform,invites'` (individual) instead of `'full'` (partner) and delete rows that the partner is now entitled to receive — those rows are then missing until the next explicit preference write. `ToggleStripeRequirementBannerOnTransition` would evaluate `requires_stripe_connect: false` (individual) instead of `true` (partner) and clear the Stripe setup banner rather than setting it. The `flushCache()` method already exists and is the correct fix; the fix is a single line in the service.
    - **Plain English:** After the system changes someone from Individual to Partner, it updates their ID card in memory. But there's a sticky-note that was made before the change that says "Individual — no Stripe needed." Any code running later in the same web request that reads the sticky-note instead of the ID card will act on the wrong account type — so the "please connect Stripe" prompt won't appear, and the notification preference cleanup might delete categories that the person now has access to as a Partner. The sticky-note clearing method already exists in the codebase — it just needs to be called at the right moment.
    - **Evidence:**
        ```php
        // AccountCapabilities::for() — memoizes by object identity:
        public static function for(Professional $pro): AccountCapabilitySet
        {
            self::$cache ??= new \WeakMap;
            if (isset(self::$cache[$pro])) {
                return self::$cache[$pro];   // ← returns stale set after setRawAttributes
            }
            // ...
            self::$cache[$pro] = $set;
            return $set;
        }

        /** Flush the per-instance cache. Tests call this when reassigning fields on a memoized Professional. */
        public static function flushCache(): void
        {
            self::$cache = null;
        }
        ```
        ```php
        // AccountTypeTransitionService — same object mutated in-place, no flush:
        $pro->setRawAttributes($locked->getAttributes(), true);
        $pro->syncOriginal();
        // flushCache() not called — WeakMap still holds pre-transition set
        ```
        ```php
        // SyncNotificationPreferencesOnTransition — reads stale capabilities:
        $allowed = AccountCapabilities::for($pro)->notification_categories;
        ```

---

## P3 — Nice to have

- [ ] **#ACCT-3** · P3 — Unused `BrandPartnerLinkService` injection and `$context['brand_id']` parameter with misleading docblock
    - **Where:** app/Services/Accounts/AccountTypeTransitionService.php:30, 50–53
    - **Affects:** Code maintainability. The docblock states `brand_id` is "required when transitioning TO partner" but neither `$context` nor `$this->brandPartnerLinks` is read anywhere in the method. Future developers may pass a `brand_id` expecting the service to create a brand link, and be silently surprised when it does not.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If brand-link creation on partner transition is intentionally deferred (links flow through the invite-acceptance path), remove the `BrandPartnerLinkService` constructor parameter and `$context` parameter, and update the docblock to remove the `brand_id` reference.
        - If brand-link creation during direct transitions is eventually planned, add a `// TODO: §<section>` inline comment citing the tracking section rather than leaving a misleading docblock.
    - **Technical:** `AccountCapabilities::partnerCapabilities()` grants `shows_commissions_dashboard: true` and other partner surfaces unconditionally based on account type, not on the existence of an active `BrandPartnerLink`. The claim in DeepSeek's draft that "a Partner Professional without a brand link cannot see affiliate dashboard surfaces" is incorrect — capabilities are not gated per-link in the current implementation. The finding is therefore a documentation/dead-code issue, not a functional correctness bug.
    - **Plain English:** The service's instruction manual says "you must provide a sponsoring brand when promoting someone to Partner." But the code ignores that field entirely and never creates the brand connection. Since the actual brand connections are managed through a separate invite system, this is more of a confusing comment than a real bug — but it should be cleaned up so the next developer doesn't trust the manual and get confused when nothing happens.
    - **Evidence:**
        ```php
        // Constructor — dependency injected but never called:
        public function __construct(private readonly BrandPartnerLinkService $brandPartnerLinks) {}

        /**
         * @param  array{brand_id?: string}  $context  Optional context; `brand_id` is
         *                                             required when transitioning TO partner.
         */
        public function transition(Professional $pro, AccountType $to, array $context = []): void
        {
            // ... $context['brand_id'] is never accessed
            // ... $this->brandPartnerLinks is never called
        }
        ```

- [ ] **#ACCT-4** · P3 — Case-sensitive `stripe_connect_status` comparison may miss valid capitalised status values from Stripe webhooks
    - **Where:** app/Listeners/Accounts/ToggleStripeRequirementBannerOnTransition.php:53
    - **Affects:** Professionals who have already connected Stripe but whose `stripe_connect_status` column holds a non-lowercase value (e.g. `'Active'` instead of `'active'`). The Stripe setup banner would remain visible incorrectly, nagging an already-connected user.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either normalise at the comparison site: `in_array(strtolower((string) ($pro->stripe_connect_status ?? '')), ['active', 'enabled'], true)`.
        - Or (preferred) normalise at write time in the Stripe webhook handler so `stripe_connect_status` is always stored lowercase, making the read side self-evidently correct.
    - **Technical:** The `in_array` call uses strict type comparison (`true` as third argument) against a fixed lowercase set. This is correct for type safety but case-sensitive. If Stripe's webhook payload writes `'Active'` or the column was seeded with a capitalised value, `in_array` returns `false`, `! $hasConnected` evaluates to `true`, and the banner key is set even though Stripe is already active. The consequence is cosmetic (unnecessary banner) rather than a security or data-loss issue.
    - **Plain English:** The code checks whether Stripe is connected by looking for the word "active" — but only recognises it in all-lowercase. If the record says "Active" with a capital A (which Stripe's API sometimes returns), the system treats it as "not connected" and keeps showing the setup prompt to someone who already finished setup. It's a small fix, but it would cause unnecessary confusion for a user who completed onboarding.
    - **Evidence:**
        ```php
        $hasConnected = in_array((string) ($pro->stripe_connect_status ?? ''), ['active', 'enabled'], true);

        if ($caps->requires_stripe_connect && ! $hasConnected) {
            Cache::put($key, true, self::TTL_SECONDS);
        } else {
            Cache::forget($key);
        }
        ```
