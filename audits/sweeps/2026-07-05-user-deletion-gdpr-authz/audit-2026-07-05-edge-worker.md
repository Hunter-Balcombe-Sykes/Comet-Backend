# Edge Worker Audit — 2026-07-05

**Branch:** development
**Lens:** Edge worker — Cloudflare routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `cloudflare-worker/src/index.js`
- `cloudflare-worker/wrangler.toml`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Jobs/Moderation/PurgeModerationCacheJob.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Cloudflare/CloudflareKvService.php`
- `app/Observers/Core/SiteObserver.php`
- `app/Observers/User/UserObserver.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`
- `app/Services/PublicSite/PublicSiteResolver.php`
- `app/Console/Commands/BackfillSubdomainKvCommand.php`
- `routes/api.php`, `routes/console.php`
- `config/partna.php`

## Progress

- P0 Blockers: 0 of 2 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#EDGE-1** · P0 — Origin profile endpoint never checks account/site status — takedowns are not durable even when KV + edge purge fire correctly
    - **Where:** `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:74-78`, `app/Services/PublicSite/IndividualProfilePayloadBuilder.php:72-94`, `routes/api.php:119-123`
    - **Affects:** Every suspended, moderation-hidden, or unpublished individual reachable via a Cloudflare-for-SaaS custom domain, or via any future cache-miss on `<handle>.partna.au` (e.g. after the weekly KV backstop resyncs an account back to active, or a handle-reuse edge case). Legal/moderation exposure (CSAM auto-suspends included, per `PurgeModerationCacheJob`'s own comment).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add the same gate `PublicSiteResolver::resolvePublishedSite()` already applies — `user.status === 'active'` and `site.is_published === true` — to `IndividualProfileController::show()` before the resolve/payload cache lookups, and additionally check `site.moderation_state !== 'hidden'`.
        - Return 404 (anti-enumeration, per the codebase's public-endpoint standard) instead of the payload when the gate fails, mirroring the `not_found` sentinel already used for missing handles.
        - Add a regression test asserting `GET /api/public/profiles/{handle}` 404s for a suspended user, a moderation-hidden site, and an unpublished site — this is the origin the Astro Worker subrequests, so this single gate is the actual backstop for every routing path (handle, alias, custom domain).
    - **Technical:** `IndividualProfileController::show()` resolves the profile with `User::query()->where('handle_lc', $handleLc)->first()` — no `status` filter — and `IndividualProfilePayloadBuilder::build()` performs no active/moderation/publish check either. Contrast with `PublicSiteResolver::resolvePublishedSite()`, used elsewhere on the public read path, which explicitly requires `is_published = true` and `whereHas('user', fn ($q) => $q->where('status', 'active'))`. This endpoint is exactly the Astro Worker's subrequest target (`env.PARTNA_PAGES.fetch()` → Astro → this API) for both the `<handle>.partna.au` path and the Cloudflare-for-SaaS custom-domain path. `SyncSubdomainToKvJob`/`PurgeModerationCacheJob` correctly retire the KV routing entry and purge the edge cache on a moderation hide — but KV retirement and cache purging only *stop new requests from reaching the origin the same way*; they cannot make the origin itself refuse to render the content. Any subsequent cache-miss — a fresh custom-domain hit (whose `domain:<host>` KV entry is never retired, see EDGE-3), the weekly `partna:backfill-subdomain-kv` resync re-establishing routing, or simply the primary cache's natural 24h expiry before a purge lands — causes `env.PARTNA_PAGES.fetch()` to call this endpoint, get a 200 with full data, and get happily re-cached at the edge (`fresh.ok` is true, so both `withCacheTtl` cache.put calls fire) for another 24h/7d. The takedown is therefore never actually durable; it only survives as long as nobody's request happens to miss the current cache state.
    - **Plain English:** When we take down someone's page — because they broke the rules, deleted their account, or got suspended — the system that actually builds the page has no idea any of that happened. It just checks "does this handle exist?" and hands over the content regardless. So even when we correctly wipe the cached copies and routing entries (which we do, in the moderation flow), the very next time anything asks the origin for that page fresh, it gets served again and gets cached again — the takedown silently undoes itself. It's like confiscating someone's shop sign but leaving the shop unlocked and fully stocked: anyone who walks past on the right day just walks in.
    - **Evidence:**
        ```php
        // IndividualProfileController.php — no status/moderation/publish gate:
        $pro = User::query()->where('handle_lc', $handleLc)->first();
        if (! $pro) {
            return ['not_found' => true];
        }
        $site = Site::query()->where('user_id', $pro->id)->first();
        ```
        ```php
        // PublicSiteResolver.php — the gate that DOES exist, elsewhere:
        $siteQuery = Site::query()
            ->where('is_published', true)
            ->with('user')
            ->whereHas('user', function ($q) {
                $q->where('status', 'active');
            });
        ```

- [ ] **#EDGE-2** · P0 — Staff account suspension (single + bulk) never dispatches KV retirement or edge purge
    - **Where:** `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:101-122,133-163`; `app/Observers/User/UserObserver.php:36-41`
    - **Affects:** Every account a staff member suspends via `PATCH /api/staff/professionals/{professional}/status` or `POST /api/staff/professionals/bulk-status` — the compliance sweep tool explicitly built to "suspend or reactivate a wave of accounts." None of them get their edge routing or cache touched by this action.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `status` to `UserObserver::PUBLIC_PROFILE_USER_FIELDS`-equivalent trigger (a new check) so `updated()` dispatches `SyncSubdomainToKvJob::dispatch($professional->id)` whenever `wasChanged('status')`, whether or not one of the four public-payload fields also changed.
        - In `StaffUserController::bulkUpdateStatus()`, replace the raw `User::query()->whereIn('id', $existing)->update(['status' => $status])` mass update with an iteration that saves each model individually (or explicitly dispatch `SyncSubdomainToKvJob::dispatch($id)` per row inside the existing `foreach ($updated as $id)` loop) — mass `update()` never fires Eloquent events, so no observer runs regardless of the fix above.
        - Combine with EDGE-1: even with dispatch wired up, this only closes the gap for the handle-keyed KV entry — pair the fix with EDGE-3's custom-domain retirement.
    - **Technical:** `StaffUserController::updateStatus()` sets `$professional->status` and calls `->save()`, which correctly fires `UserObserver::updated()` — but that observer only checks `wasChanged(self::PUBLIC_PROFILE_USER_FIELDS)` (`handle`, `display_name`, `first_name`, `last_name`) to decide whether to touch the parent site, and only checks `wasChanged('handle')` to dispatch `SyncSubdomainToKvJob`. `status` triggers neither. `bulkUpdateStatus()` is worse: it uses `User::query()->whereIn('id', $existing)->update(['status' => $status])`, a query-builder mass update that bypasses Eloquent model events entirely — no observer fires for any of the (up to 100) rows, ever. The only backstop is the weekly `partna:backfill-subdomain-kv --all --queue` cron (Sunday 04:00 UTC, `routes/console.php:161-166`), which re-evaluates every user's `isActive()` state and would eventually retire a suspended user's *handle* KV entry — but only after up to 7 days, and it still never dispatches a `CloudflareCachePurgeJob`, so the primary/shadow edge cache for that handle (and any custom domain) is never proactively evicted; the page stays fully live and cache-warm for the entire window.
    - **Plain English:** Staff have a button that's supposed to suspend a problem account — but flipping that switch currently does nothing to the public page. The page keeps showing up, fully cached, exactly as before, for up to a week (until an unrelated weekly cleanup job happens to notice), and the bulk version of this tool — built specifically for suspending groups of accounts at once — does nothing at all until that same weekly job runs. It's like a "deactivate" button on a keycard system that updates the database but never actually tells the door locks.
    - **Evidence:**
        ```php
        // UserObserver.php — status is not in the trigger list:
        private const PUBLIC_PROFILE_USER_FIELDS = [
            'handle',
            'display_name',
            'first_name',
            'last_name',
        ];
        ```
        ```php
        // StaffUserController.php — single suspend: save() fires the observer above, which ignores status:
        $professional->status = $data['status'];
        $professional->save();
        ```
        ```php
        // StaffUserController.php — bulk suspend: bypasses Eloquent events entirely:
        if (! empty($existing)) {
            User::query()
                ->whereIn('id', $existing)
                ->update(['status' => $status]);
            $updated = $existing;
        }
        ```

## P1 — Fix before pilot launch

- [ ] **#EDGE-3** · P1 — Custom-domain KV entry (`domain:<host>`) is never retired on suspension/moderation-hide, and the weekly backstop doesn't touch it either
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:151-160` (`retire()`), `:129-134` (custom-domain write); `app/Console/Commands/BackfillSubdomainKvCommand.php:22-72`
    - **Affects:** Any suspended/moderation-hidden professional with an active Cloudflare-for-SaaS custom domain (e.g. `tuesdae.co`) — the domain keeps routing to their content indefinitely, bounded only by whatever purge is dispatched alongside the takedown (and EDGE-1 means even a purge doesn't make it stick).
    - **Effort:** S (~1–2h)
    - **What to do:**
        - In `SyncSubdomainToKvJob::retire()`, look up the (possibly-trashed) user's site for an active `custom_domain` before returning, and delete `domain:{$customDomain}` alongside the handle key.
        - Since `retire()` is reached via `withTrashed()->find($this->userId)`, the site relation is still resolvable for a soft-deleted or suspended owner — guard with the same `try/catch` pattern already used in `handle()` for the site lookup.
    - **Technical:** `retire()` only calls `$kv->delete($handle)` — it never inspects `$pro->site->custom_domain`. The `domain:<host>` entry is written by the *active*-user branch of `handle()` (`$kv->put("domain:{$customDomain}", ['type' => 'individual', 'handle' => $current], null)`) but is only ever deleted via the separate `$this->retireCustomDomain` constructor argument, which is exclusively passed by `CustomDomainController` when a user changes/disconnects their own domain — never by a suspension or moderation path. The weekly `partna:backfill-subdomain-kv --all` resync (the only backstop for stale handle entries) iterates `User::query()->whereNotNull('handle')` and re-dispatches `SyncSubdomainToKvJob`, which reaches the same `retire()` method — so it inherits the identical gap and never cleans up `domain:<host>` either. This is a genuine second KV writer's worth of drift risk: the entry silently outlives the condition that justified writing it.
    - **Plain English:** When we suspend someone, we remove the sign at their `.partna.au` address, but if they've connected their own custom web address (like `tuesdae.co`), that address's routing entry is never removed — not even by the safety-net job that runs every week to catch things like this. The custom address keeps pointing at their content forever unless someone manually notices and fixes it.
    - **Evidence:**
        ```php
        // SyncSubdomainToKvJob.php — retire() deletes only the handle key:
        private function retire(CloudflareKvService $kv, ?User $pro): void
        {
            $handle = strtolower(trim((string) ($pro?->handle ?: $this->capturedHandle)));

            if ($handle === '') {
                return;
            }

            $kv->delete($handle);
        }
        ```
        ```php
        // SyncSubdomainToKvJob.php — the domain:<host> entry this never cleans up:
        if ($site) {
            $customDomain = strtolower(trim((string) ($site->custom_domain ?? '')));
            if ($customDomain !== '' && ($site->custom_domain_status ?? null) === 'active') {
                $kv->put("domain:{$customDomain}", ['type' => 'individual', 'handle' => $current], null);
            }
        }
        ```

## P2 — Should fix

- [ ] **#EDGE-4** · P2 — Alias TTL below Cloudflare KV's 60s minimum not clamped
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:writeAliasEntries():202-215`
    - **Affects:** Handle-alias expiry precision in the final ~59 seconds before `expires_at` — narrow timing window, low real-world frequency.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Clamp with `$ttl = max(60, $ttl)` before adding the entry, with a one-line comment citing Cloudflare KV's documented 60s floor.
    - **Technical:** `writeAliasEntries()` computes `$ttl = now()->diffInSeconds(Carbon::parse($alias->expires_at), false)` and only guards `$ttl <= 0` (added for a prior audit's "expired alias resurrection" fix — the comment references it directly). Cloudflare KV silently floors any `expiration_ttl` below 60s to 60s, so an alias with 1–59 seconds left in the DB lives up to 60s in KV — during which the reclaimed-but-not-yet-expired old handle can still 301 a visitor to the previous owner's page for a handful of extra seconds past the DB's own expiry.
    - **Plain English:** We tell Cloudflare "stop redirecting this old address in 30 seconds," but Cloudflare's minimum timer is 60 seconds, so it quietly redirects for twice as long as we asked. It's a small, narrow timing gap, not a security hole — just worth tightening so the two clocks agree.
    - **Evidence:**
        ```php
        $ttl = $alias->expires_at
            ? (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false)
            : null;
        ```
        ```php
        if ($ttl !== null && $ttl <= 0) {
            continue;
        }
        ```

- [ ] **#EDGE-5** · P2 — Worker hardcodes `partna.au`; backend `public_domain` is env-configurable with no documented sync point
    - **Where:** `cloudflare-worker/src/index.js:42` (`const PARTNA_DOMAIN = "partna.au"`); `config/partna.php:58-64`
    - **Affects:** Any future non-production domain (staging with a distinct TLD, or a domain migration) — the Worker would silently misclassify `<handle>.<newdomain>` hosts as unknown/custom-domain traffic instead of routed subdomains.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a one-line comment on both sides naming the mirror explicitly (the Worker header already documents the `RESERVED` mirror this way — extend the same convention to `PARTNA_DOMAIN`).
        - Longer-term: bind `PARTNA_DOMAIN` as a `[vars]` entry in `wrangler.toml` instead of a JS constant, so an environment override doesn't require an `index.js` edit.
    - **Technical:** The Worker's hostname parsing, subdomain extraction, and alias-redirect host validation all key off the hardcoded `PARTNA_DOMAIN` constant. `config('partna.public_domain')` — which drives `CloudflarePurgeService`'s purge URLs — resolves from `PARTNA_PUBLIC_DOMAIN`/`SIDEST_PUBLIC_DOMAIN` env vars. Today both point at `partna.au` in every environment that matters, so there's no live bug — but nothing documents that they must be changed together, and `wrangler.toml`'s `zone_name = "partna.au"` pins the Worker's routes to that literal zone regardless, compounding the drift risk on a real domain change.
    - **Plain English:** The Worker has "partna.au" hardcoded as the only address it recognizes, while the backend reads its domain from a configurable setting. Today they match, so nothing's broken — but if we ever needed a different domain for staging or a rebrand, the backend would update automatically and the Worker wouldn't, and nothing today would warn the next person that they need to also edit the Worker's code.
    - **Evidence:**
        ```javascript
        const PARTNA_DOMAIN = "partna.au";
        ```
        ```php
        'public_domain' => env(
            'PARTNA_PUBLIC_DOMAIN',
            env(
                'SIDEST_PUBLIC_DOMAIN',
                parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'
            )
        ),
        ```

- [ ] **#EDGE-6** · P2 — Background SWR refresh failures produce no operational signal
    - **Where:** `cloudflare-worker/src/index.js:serveIndividual():344-349`, `fetchAndCache():267-288`
    - **Affects:** Operations visibility during a `PARTNA_PAGES` origin outage — the Worker silently serves the 7-day-old shadow with no alert while the underlying origin is unreachable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inside `fetchAndCache`, add a `console.error` (with URL + status) when `!fresh.ok`, mirroring the existing `.catch` logging pattern on the `cache.put` calls.
        - The background refresh is invoked via `ctx.waitUntil(fetchAndCache(...))` with no `.catch` on the outer call — a thrown `env.PARTNA_PAGES.fetch()` (network failure/timeout) propagates into an unhandled rejection inside `waitUntil`. Add a `.catch(err => console.error(...))` to that call site too.
    - **Technical:** `fetchAndCache()` only logs `cache.put` failures (`.catch((err) => console.error("primary cache.put failed", ...))` / `"shadow cache.put failed"`); the `if (fresh.ok && ...)` guard means a non-OK or thrown origin fetch produces zero log output anywhere in the call chain. Since `serveIndividual()`'s stale-shadow branch fires this as a detached `ctx.waitUntil` with no caller awaiting the result, an origin outage is invisible until someone notices the primary cache never refreshes.
    - **Plain English:** When the main server is down, the Worker quietly falls back to a week-old backup copy of the page — which keeps the site looking fine to visitors — but nobody gets notified that the backup kicked in. We want at least a log line so an ops sweep of Cloudflare's logs would catch a multi-day outage instead of it going unnoticed until the 7-day shadow itself expires.
    - **Evidence:**
        ```javascript
        if (shadow) {
            ctx.waitUntil(fetchAndCache(env, ctx, cacheKey, cache, originRequest));
            return finalize(shadow, {cacheStatus: "stale", sitepage: true});
        }
        ```
        ```javascript
        async function fetchAndCache(env, ctx, cacheKey, cache, originRequest) {
          const fresh = await env.PARTNA_PAGES.fetch(originRequest);

          if (fresh.ok && originRequest.method === "GET") {
            ctx.waitUntil(
              withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)
                .then((r) => cache.put(cacheKey, r))
                .catch((err) => console.error("primary cache.put failed", {url: cacheKey.url, err: String(err)})),
            );
            ctx.waitUntil(
              withCacheTtl(fresh, STALE_SHADOW_TTL_S)
                .then((r) => cache.put(staleShadowKey(cacheKey), r))
                .catch((err) => console.error("shadow cache.put failed", {url: cacheKey.url, err: String(err)})),
            );
          }

          return fresh;
        }
        ```

## P3 — Nice to have

- [ ] **#EDGE-7** · P3 — Staging KV namespace unconfigured in `wrangler.toml` (placeholder ID, already TODO'd)
    - **Where:** `cloudflare-worker/wrangler.toml:42-53`
    - **Affects:** A future `--env staging` Worker deploy only — production is unaffected, and the gap is self-documenting.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `wrangler kv namespace create SUBDOMAIN_KV_STAGING` and `... --preview`, paste both IDs in, and point the staging Laravel env's `CLOUDFLARE_KV_NAMESPACE_ID` at the same namespace — exactly as the in-file TODO already specifies.
    - **Technical:** The `[env.staging.kv_namespaces]` block carries literal placeholder strings (`REPLACE_WITH_STAGING_KV_NAMESPACE_ID`/`_PREVIEW_ID`). A `wrangler deploy --env staging` today would bind to a non-existent namespace; every KV read would throw and fail open to `passThrough`, disabling subdomain routing for that environment. This is pre-flagged in-repo (comment references a prior audit's finding number) with the exact remediation steps already written — it is a known, tracked gap, not a discovered one, and doesn't touch the production path.
    - **Plain English:** The staging environment's routing table has a placeholder where a real ID should go. If anyone deploys the Worker to staging today, none of the site addresses would route correctly. It's already flagged in the file with clear next steps — just needs someone to run the two commands and paste the results in.
    - **Evidence:**
        ```toml
        [[env.staging.kv_namespaces]]
        binding = "SUBDOMAIN_KV"
        id = "REPLACE_WITH_STAGING_KV_NAMESPACE_ID"
        preview_id = "REPLACE_WITH_STAGING_KV_PREVIEW_ID"
        ```

## Suggested Bundled Sessions

- **Bundle 1 — KV entry lifecycle hygiene:** #EDGE-3, #EDGE-4
    - **Why grouped:** Both are correctness gaps inside `SyncSubdomainToKvJob` around KV entry lifetime (custom-domain retirement, alias TTL flooring) — same file, same "entry outlives its justification" root cause.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Worker config & observability hygiene:** #EDGE-5, #EDGE-6, #EDGE-7
    - **Why grouped:** All three are low-risk, self-contained hygiene items in the Worker/`wrangler.toml` (sync-point documentation, failure logging, staging namespace provisioning) with no shared code path but similar low effort and no user-facing risk today.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#EDGE-1 — Origin profile endpoint never checks account/site status** · P0, and the fix changes the public API contract consumed by the Astro Worker — requires its own plan + sign-off.
- **#EDGE-2 — Staff suspension never dispatches KV/edge invalidation** · P0, touches an authorization-adjacent staff action (account suspension) and observer/event wiring — requires its own plan + sign-off.
