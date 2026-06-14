- [ ] **#LIFE-1** · P1 — InstagramConnectJob missing ShouldBeUnique allows duplicate costly Apify scrapes
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:32 (class declaration)
    - **Affects:** Billed Apify usage; duplicate R2 writes; user sees a stale "pending" status while two jobs race
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ShouldBeUnique` to the `implements` clause.
        - Add `uniqueId()` returning `$this->connectionId` so a second dispatch for the same connection coalesces within the window.
        - Add `public int $uniqueFor = 300;` to match the existing `DeleteMirroredMediaJob` pattern.
    - **Technical:** The existing `DeleteMirroredMediaJob` already implements `ShouldBeUnique` with a coalesce window, proving the team knows this pattern. `InstagramConnectJob` performs a billed Apify call (up to 110s) followed by R2 writes — two concurrent dispatches of the same job mean two billed scrapes and two parallel R2 writes racing to update the same connection row. A `uniqueFor` window matching the job's max duration (~150s) prevents the second dispatch entirely if the first is still running.
    - **Plain English:** Imagine ordering the same Uber twice because the app didn't show the first one was already on the way. You'd pay for two cars. That's what happens if the connect job gets queued twice — two paid Apify API calls fire, both upload photos to storage, and the second one overwrites the first's work. The fix is a simple "don't send a second one while the first is still in progress" guard that already exists on a nearby cleanup job.
    - **Evidence:**
        ```php
        class InstagramConnectJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            // Apify can take up to 110s; allow headroom for image mirroring on top.
            public int $timeout = 150;

            public int $tries = 2;
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-2** · P2 — GoogleBusinessService::streetViewPano swallows all exceptions silently
    - **Where:** app/Services/Platforms/GoogleBusinessService.php:287-294
    - **Affects:** Nightwatch alerting; operators debugging Google API key misconfiguration
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Log::warning` call with `placeId`, `lat`, `lng`, and the exception message before returning null.
        - Alternatively, let the exception propagate and handle it in the caller (`fetchPlaceDetails`) which already has a catch block.
    - **Technical:** The catch block is `catch (\Throwable) { return null; }` — no log, no `$this->fail()`, no re-throw. If Google's Street View metadata endpoint starts returning errors (billing disabled, API key revoked, per-IP quota exhaustion), this path is completely invisible to Nightwatch. The caller (`fetchPlaceDetails`) treats a null return as "no Street View pano available at this pin" and continues silently. A persistent API failure will never alert. Per the observability doctrine, path-constructed vendor URLs like `maps.googleapis.com/maps/api/streetview/metadata` are not user-supplied, so `SafeUrlFetcher` is not required, but the failure must still be observable.
    - **Plain English:** There's a free "check if Street View exists at this location" probe. If Google's server is down or the API key is broken, the code silently says "nope, no Street View here" instead of "something's wrong." Over time, a broken API key could go unnoticed because the failure looks identical to a location that genuinely lacks Street View coverage. One log line fixes it.
    - **Evidence:**
        ```php
        try {
            $res = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/streetview/metadata', [
                'location' => $lat.','.$lng,
                'radius' => 100,
                'source' => 'outdoor',
                'key' => $key,
            ]);
        } catch (\Throwable) {
            return null;
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-3** · P2 — GoogleBusinessService::resolvePhotoUrls logs warning without placeId context
    - **Where:** app/Services/Platforms/GoogleBusinessService.php:254-258
    - **Affects:** Nightwatch correlation; operators debugging photo resolution failures
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'placeId'` to the log context array. The caller (`fetchPlaceDetails`) has `$placeId` in scope — pass it through or derive it from the photo refs.
    - **Technical:** Per the `Log-with-context` pattern, every `Log::warning` must carry enough correlation keys (`user_id`, `request_id`, operation name) so Nightwatch can group and attribute. The exception catch here logs `'google_business.photo_resolve_failed'` with only `'message' => $e->getMessage()` — no `placeId`. When this fires at scale with thousands of places being refreshed, all failures are indistinguishable. The `fetchPlaceDetails` caller has `$placeId` but `resolvePhotoUrls` is a private method that doesn't receive it, so the context is lost at the boundary.
    - **Plain English:** If photo downloads start failing for a particular business listing, the error log says "photo download failed" but doesn't say WHICH business. You'd have to guess. Adding the place ID takes one line and lets you instantly find the problem listing.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('google_business.photo_resolve_failed', ['message' => $e->getMessage()]);
            return $photos;
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#LIFE-4** · P3 — PlatformRefresher read-modify-write race on consecutive_failures counter
    - **Where:** app/Services/Platforms/PlatformRefresher.php:76-78 and :86-88
    - **Affects:** Accuracy of the `consecutive_failures` monitoring counter under concurrent refresh
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use `$connection->increment('consecutive_failures')` instead of `(int) $connection->consecutive_failures + 1` followed by `saveQuietly()`.
        - Alternatively, wrap the read + write in `lockForUpdate` at the point the model is fetched.
    - **Technical:** The failure path reads the model's `consecutive_failures` (loaded at the top of `refresh()`) and writes `value + 1`. With two concurrent refresh calls for the same connection (e.g., manual "Refresh now" overlapping the daily cron), both read the same stale value and both write the same incremented result, losing one increment. An `increment()` call is atomic in Postgres and avoids the race entirely. The impact is minor — this is an observability counter, not a correctness field — but at thousands of users the cumulative masking of persistent failures could delay operator response.
    - **Plain English:** Two people hit "refresh" at the same time on the same connection. The failure counter should go up by 2 if both fail. Instead it goes up by 1 because both read "0" before either writes. It's like two people each depositing $1 into a bank account at the same time but the balance only goes up by $1. For a counter that helps detect "this connection has been failing for 5 days straight," missing one increment is minor but easy to fix with an atomic increment.
    - **Evidence:**
        ```php
        $connection->forceFill([
            'last_refresh_status' => $status,
            'last_refresh_error' => $error,
            'consecutive_failures' => (int) $connection->consecutive_failures + 1,
        ])->saveQuietly();
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#LIFE-5** · P3 — SmartLinkRefresher read-modify-write race on consecutive_failures counter
    - **Where:** app/Services/SmartLinks/SmartLinkRefresher.php:31-35
    - **Affects:** Same as LIFE-4 but for SmartLink refresh path
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use `$link->increment('consecutive_failures')` on the failure path.
    - **Technical:** Same pattern as PlatformRefresher: the failure path reads `$link->consecutive_failures` (loaded before the re-resolve) and writes `+1` via `forceFill()->save()`. Manual "Refresh now" and the `smartlinks:refresh` cron can race. In practice, manual refreshes from the dashboard are rare and the consequence (lost increment) is minor, but the fix is trivial.
    - **Plain English:** Same bank-account analogy as the platform refresher — two simultaneous refresh attempts on the same smart link both read the old failure count and both write the same "old + 1" value, losing one increment. Low impact, high fix simplicity.
    - **Evidence:**
        ```php
        $link->forceFill([
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'unavailable',
            'consecutive_failures' => (int) $link->consecutive_failures + 1,
        ])->save();
        ```
    - `[DRAFT, confidence: 0.75]`
