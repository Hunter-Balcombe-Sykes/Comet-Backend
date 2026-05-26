- [ ] **#CACHE-1** · P2 — Storefront reachability cache lacks single-flight lock, TTL jitter, and SWR stale copy
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:275-295
    - **Affects:** Brand dashboard / onboarding readiness check for every brand with a deployed storefront. Cold cache after deploy or eviction causes concurrent HTTP requests to brand storefronts.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace raw `Cache::get` / `Cache::put` with `CacheLockService::rememberLocked` (provides single-flight lock + SWR stale-while-revalidate).
        - Add ±20% TTL jitter (e.g. `60 + random(0, 12)` for reachable, `15 + random(0, 3)` for unreachable) so multiple brands' cache entries don't expire in lockstep.
        - Add push-invalidation after storefront-deploy webhooks so a newly-deployed storefront flips the cache immediately instead of waiting up to 15s.
    - **Technical:** The current `Cache::get` / `Cache::put` pair has no lock guarding the HTTP call. On a cold cache, N concurrent requests to the same brand's dashboard each make an outbound HTTP request to the brand's storefront. Under the target scale (30 brands), this is a 30-request burst after deploy. Worse, the two-level TTL (60s reachable, 15s unreachable) is a primitive refresh-early pattern that serves stale data for 0s — it doesn't maintain a `:stale` copy, so every request during the HTTP call window blocks. `CacheLockService::rememberLocked` solves both: the lock serialises the HTTP call to one worker, and the SWR `:stale` copy serves last-good data to all other workers while the call is in flight.
    - **Plain English:** Imagine a hotel front desk where every guest who asks "is my room ready?" triggers the clerk to run upstairs and check — even if 10 guests ask the same question at once. The fix is to have a whiteboard behind the desk (the cache) with a single "checking now" note (the lock) so only one clerk goes upstairs, and the whiteboard keeps showing the last known answer until the new one is written down.
    - **Evidence:**
        ```php
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            $reachable = $response->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P2 — Per-order synchronous notification publish in commission void warning cron
    - **Where:** app/Services/Stripe/CommissionVoidService.php:436-475 (`sendPerCommissionWarnings`)
    - **Affects:** Every affiliate with pending commissions inside the 5-day void warning window. At target scale (~150K orders/year, ~410/day, 5-day window ≈ 2,050 orders), this cron run publishes 2,050 individual notifications serially.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-order `$this->publisher->publish(...)` call with a batch accumulated in-memory (group by `affiliate_professional_id`), then dispatched via `$this->publisher->publishMany($items)` after the chunk loop.
        - The `dedupeKey` stays per-order for correctness; `publishMany` handles the multi-insert internally without changing dedupe semantics.
    - **Technical:** `NotificationPublisher::publish()` does at minimum one DB insert per call. With 2,050 orders in the warning window, that's 2,050 individual INSERTs inside a chunk loop. The `publishMany()` method already exists and is used elsewhere (e.g. `BrandAffiliateInviteService::notifyExistingEmailRecipientsBatch`) — it accepts an array of notification payloads and processes them in a single DB round-trip. The per-commission dedupe key (`stripe_warning.commission.{$order->id}`) is deterministic and would produce the same key under either path. The batch approach cuts 2,050 round-trips down to roughly N_affiliates round-trips (at target scale, maybe 100-200 affiliates with pending commissions).
    - **Plain English:** Right now, when the nightly warning system runs, it sends a separate text message for every single order that's about to expire. If an affiliate has 50 pending orders, they get 50 separate pings. The system already has a "send many at once" function — it's used for invite notifications — but this code isn't using it. The fix is to collect all the warnings for each person and send them as one batch, like putting all the letters in one envelope instead of mailing each page separately.
    - **Evidence:**
        ```php
        Order::query()
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->whereBetween('occurred_at', [$windowStart, $windowEnd])
            ->whereIn('affiliate_professional_id', $inactiveAffiliateIds)
            ->chunkById(500, function ($orders) use (&$sent, $voidWindowDays) {
                foreach ($orders as $order) {
                    // ...
                    $this->publisher->publish(
                        professionalId: $order->affiliate_professional_id,
                        frontendType: 'Warning',
                        category: 'commissions',
                        title: 'Commission expiring soon',
                        body: sprintf(
                            'Connect Stripe within %d days or your %s commission from %s will be forfeited.',
                            $daysLeft,
                            Money::format((int) $order->commission_cents, $order->currency_code),
                            $order->occurred_at->format('M j'),
                        ),
                        dedupeKey: "stripe_warning.commission.{$order->id}",
                        ctaUrl: '/account/settings?section=stripe',
                        retentionConfigKey: 'commission',
                    );
                    $sent++;
                }
            });
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CACHE-3** · P2 — Per-payout synchronous notification publish in payout void warning cron
    - **Where:** app/Services/Stripe/CommissionVoidService.php:479-526 (`sendPerPayoutWarnings`)
    - **Affects:** Affiliates with pending payouts approaching their `void_at` deadline. Lower volume than per-commission warnings (payouts aggregate many orders), but same per-item publish antipattern.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as CACHE-2: accumulate warning payloads per affiliate during the `chunkById` loop, then call `$this->publisher->publishMany($items)` after the chunk completes.
    - **Technical:** This method mirrors `sendPerCommissionWarnings` but operates on `CommissionPayout` rows. Each iteration calls `$this->publisher->publish(...)` with a `dedupeKey` of `"stripe_warning.payout.{$key}.{$payout->id}"`. The `publishMany` batch path would produce identical final state at a fraction of the DB round-trips. At target scale, the number of pending payouts approaching expiry is small (tens per cron run), so the practical impact is lower than CACHE-2, but the architectural antipattern is identical and should be fixed for consistency.
    - **Plain English:** Same problem as the per-order warnings, but for payout batches instead of individual orders. Think of it as mailing each customer a separate envelope for each invoice, when you could put all their invoices in one envelope. The volume is lower here — maybe dozens instead of thousands — but the fix is the same one-line change.
    - **Evidence:**
        ```php
        CommissionPayout::query()
            ->where('status', 'pending')
            ->whereBetween('void_at', $window['range'])
            ->whereIn('affiliate_professional_id', $inactiveAffiliateIds)
            ->chunkById(200, function ($payouts) use (&$sent, $key, $window): void {
                foreach ($payouts as $payout) {
                    $this->publisher->publish(
                        professionalId: $payout->affiliate_professional_id,
                        frontendType: 'Warning',
                        category: 'commissions',
                        title: $window['title'],
                        body: sprintf($window['body'], Money::format($payout->net_payout_cents, $payout->currency_code)),
                        dedupeKey: "stripe_warning.payout.{$key}.{$payout->id}",
                        ctaUrl: '/account/settings?section=stripe',
                        retentionConfigKey: 'commission',
                    );
                    $sent++;
                }
            });
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CACHE-4** · P2 — Eager materialisation of all site media in account purge path
    - **Where:** app/Services/Professional/AccountDeletionService.php:330-348 (`purgeMediaArtifacts`)
    - **Affects:** The daily `PurgeSoftDeleted` command when processing a brand with a large media library. A brand with 5,000+ images loads all 5,000 `SiteMedia` Eloquent models into memory in one `->get()` before iterating.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()` with `->lazy()` or `->chunk(200, ...)` so the PDO cursor streams rows one at a time rather than hydrating the full result set into an Eloquent collection.
    - **Technical:** `SiteMedia::query()->withTrashed()->where('site_id', $site->id)->get()` materialises every row into an in-memory Eloquent Collection. For a brand with 5,000 images (each with potentially multiple variants), this is 5,000 hydrated models. The subsequent `foreach` processes them one at a time — videos dispatch async jobs, images call `ImageVariantService::deleteVariants`, documents call `Storage::delete`. At the target scale (30 brands), account purges are rare (one per brand ever), and only happen after the 30-day grace period. The memory spike is bounded by the largest brand's media count but is trivially fixed by switching to `->lazy()` which uses PDO's row-by-row cursor under the hood. Since the purge loop is not mutating the collection (no `filter`, no `map`), streaming is a drop-in replacement.
    - **Plain English:** When the system deletes a departed brand's account after the 30-day waiting period, it first gathers every single photo and video that brand ever uploaded — loading them all into memory at once before dealing with them. For a brand with thousands of images, this is like emptying a warehouse by first carrying every box to the front door before taking any of them outside. The fix is to carry one box at a time: grab a photo, delete it, grab the next.
    - **Evidence:**
        ```php
        $mediaItems = SiteMedia::query()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get();

        foreach ($mediaItems as $media) {
            try {
                match ($media->media_type) {
                    SiteMedia::MEDIA_TYPE_VIDEO => $this->purgeVideoArtifacts($media),
                    SiteMedia::MEDIA_TYPE_DOCUMENT => $this->purgeDocumentArtifact($media),
                    default => $this->purgeImageArtifacts($media),
                };
            } catch (\Throwable $e) {
                Log::warning('R2 artifact cleanup failed for media item during account purge', [
                    'professional_id' => $professional->id,
                    'media_id' => $media->id,
                    'media_type' => $media->media_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.90]`
