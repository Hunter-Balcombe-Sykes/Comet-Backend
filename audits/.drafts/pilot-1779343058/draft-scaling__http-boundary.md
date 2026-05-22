- [ ] **#CACHE-1** · P3 — `StorePlanSubscriptionRequest::freePlanId` uses `Cache::remember` without single‑flight lock or jitter  
    - **Where:** `app/Http/Requests/Api/Professional/StorePlanSubscriptionRequest.php:44`
    - **Affects:** `/api/professional/subscriptions` endpoint — stampede risk on cold cache after deploy or eviction, bounded by single global key and trivial query.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the lookup in `CacheLockService::rememberLocked` to guarantee single‑flight under cold‑cache spikes.
        - Add ±20 % jitter to the TTL so multiple pods don’t expire the same key simultaneously.
        - If the existing justification is accepted, document the decision in the code and close as intentional risk — but still add jitter.
    - **Technical:** `Cache::remember` without a lock allows every concurrent request to execute the callback when the cache is cold; in a multi‑pod Laravel Horizon worker pool this can still produce a small thundering herd. Although the key is global (no per‑tenant cardinality) and the DB lookup is fast (`Plan::where('plan_key','free')->value('id')`), the canonical `CacheLockService::rememberLocked` with jitter and SWR delivers the same result with zero stampede risk at negligible cost.
    - **Plain English:** Picture a notice board with one phone number pinned on it. If the notice falls down, everyone in the room tries to dial the company at once to get the number again — that’s a tiny scramble because the call is quick, but it’s still unnecessary noise. A lock means the first person holds the phone line, gets the number, and repins it before anyone else dials. The scramble is small here, but using the lock makes the system perfectly quiet for no extra effort.
    - **Evidence:**
        ```php
        private function freePlanId(): string
        {
            // Plain Cache::remember — single global key (no per-tenant cardinality),
            // tiny lookup, 1hr TTL. Stampede impact is bounded; the single-flight
            // CacheLockService machinery would be overkill here.
            return Cache::remember('billing.free_plan_id', 3600, function () {
                return Plan::where('plan_key', 'free')->value('id') ?? '';
            });
        }
        ```
    - `[DRAFT, confidence: 0.7]`
