# Edge Worker Audit — 2026-06-13

**Branch:** development
**Lens:** Edge worker: Cloudflare routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- cloudflare-worker/src/index.js
- cloudflare-worker/wrangler.toml
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Observers/Core/SiteObserver.php
- app/Observers/User/UserObserver.php
- app/Jobs/Moderation/SuspendSiteJob.php
- app/Jobs/Moderation/SuspendUserJob.php
- app/Jobs/Moderation/PurgeModerationCacheJob.php
- app/Services/Moderation/ModerationActionDispatcher.php
- config/partna.php

## Progress

- P0 Blockers: 0 of 3 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 3 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#EDGE-1** · P0 — `Set-Cookie` headers from the Astro origin are stored in the edge cache and replayed to every visitor
    - **Where:** cloudflare-worker/src/index.js:79–101 (`withCacheTtl`) and 153–163 (`fetchAndCache`)
    - **Affects:** Every visitor to any cached sitepage — if the Astro origin ever emits a `Set-Cookie` (session fixation token, analytics cookie, accidental debug cookie during a deploy), every subsequent visitor to that URL receives the same cookie, enabling session takeover or tracking contamination across users.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `withCacheTtl`, after building `const headers = new Headers(response.headers)`, add `headers.delete("Set-Cookie")` before constructing the `new Response(...)` returned to `cache.put`.
        - Add the same deletion on both the primary and stale-shadow put paths.
        - As belt-and-suspenders, add a guard before both `cache.put` calls: if the origin response includes a `Set-Cookie` header, skip caching and return the origin response directly with `Cache-Control: no-store` merged in.
    - **Technical:** `withCacheTtl` clones the entire `response.headers` object into a new `Headers` instance and only modifies `Cache-Control` (to inject `s-maxage` and `public`). All other origin headers — including `Set-Cookie` — pass through unmodified into the `new Response(body, { headers })` that is then written to `caches.default.put()`. Cloudflare's Workers Cache API stores whatever headers it is given; it does not automatically strip `Set-Cookie` on `cache.put` the way the CDN does on origin-forwarded responses. The resulting cached response is served verbatim to the next visitor whose request URL matches — cookie and all.
    - **Plain English:** The Worker stores a snapshot of the page in a fast cache to serve future visitors quickly. Right now that snapshot includes everything the server sent — including any "sticky labels" (cookies) it might have accidentally attached. If the server ever sends a cookie (even temporarily, even during a failed deploy), the photocopier stores it and hands the same label to every person who asks for that page next. One visitor's private credential ends up in every subsequent visitor's browser.
    - **Evidence:**
        ```js
        async function withCacheTtl(response, ttlSeconds) {
          const body = await response.clone().arrayBuffer();
          const headers = new Headers(response.headers);
          // Only Cache-Control is touched — Set-Cookie survives unmodified
          const original = headers.get("Cache-Control") ?? "";
          const directives = original
            .split(",")
            .map((s) => s.trim())
            .filter((s) => s.length > 0 && !s.toLowerCase().startsWith("s-maxage="));
          directives.push(`s-maxage=${ttlSeconds}`);
          if (!directives.some((d) => d.toLowerCase() === "public")) {
            directives.unshift("public");
          }
          headers.set("Cache-Control", directives.join(", "));

          return new Response(body, {
            status: response.status,
            statusText: response.statusText,
            headers,
          });
        }
        ```
        ```js
        if (fresh.ok && request.method === "GET") {
          // Primary cache — short-ish, push-purged on edits.
          ctx.waitUntil(
            cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
          );
          // Stale shadow — long-lived.
          ctx.waitUntil(
            cache.put(staleShadowKey(request), await withCacheTtl(fresh, STALE_SHADOW_TTL_S)),
          );
        }
        ```

- [ ] **#EDGE-2** · P0 — Cache purge covers only the root URL; deep-linked paths and their stale shadows are never cleared
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:100–109 (`purgeHandle`) vs cloudflare-worker/src/index.js:153–162 (`fetchAndCache`)
    - **Affects:** Every visitor arriving via a direct link to a sub-path (`/gallery`, `/services`, `/about`, etc.) after any content mutation — including profile edits, media updates, and moderation actions where a purge _does_ fire. The 7-day stale shadow means the worst-case staleness window for a deep link is seven days.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Switch `purgeUrls` from the `files` payload (exact-URL match) to `prefixes` (`{"prefixes":["https://{handle}.{baseDomain}/"]}`) so one API call clears every cached path under the subdomain. Cloudflare's cache-purge API supports prefix purging; verify your zone plan tier supports it.
        - Include `https://{handle}.{baseDomain}/_swr-shadow/` as a second prefix entry to cover the shadow key space (shadow paths are `/_swr-shadow{pathname}`, so the prefix `/_swr-shadow/` covers all of them).
        - If prefix purge is unavailable on the current plan, maintain an enumerated set of known sitepage paths in `partna.php` and purge all of them explicitly on each job run.
        - Add a mirror comment to `purgeHandle` noting that the path list must stay in sync with any new routes added to the Astro sitepage.
    - **Technical:** The Worker caches each distinct URL via `caches.default.put(request, ...)` where `request` is the full visitor URL — so `https://handle.partna.au/gallery` and `https://handle.partna.au/services` each have their own primary entry and a `/_swr-shadow/gallery` / `/_swr-shadow/services` shadow entry. `CloudflarePurgeService::purgeHandle` sends `{"files": [...]}` which Cloudflare matches by exact URL only. The URL list contains only three entries: the root with slash, the root without slash, and `/_swr-shadow/`. No deep-link path is ever purged. The docblock claims "Purge the full cache chain" — that comment is wrong. For normal edits the staleness on deep links is up to 24 h (primary TTL). For moderation actions that DO fire a purge (the `hide_content` path — though see EDGE-3 for why that path is also broken), the shadow extends the window to seven days.
    - **Plain English:** When someone updates their "Services" page, the system clears the cached copy of the homepage — but the cached copy of the Services page itself is untouched. Anyone with a direct link (from a Google search result, a share, a bookmark) keeps seeing the old version for up to 24 hours — or seven days if they're hitting the backup copy. For a page that gets taken down by moderation, anyone who bookmarked the direct link can still access it for a week.
    - **Evidence:**
        ```php
        // CloudflarePurgeService::purgeHandle — only root-path variants
        $urls = [
            // Page URL — root path and slash-less variant. Cloudflare treats
            // these as distinct cache keys, so list both.
            "https://{$h}.{$baseDomain}/",
            "https://{$h}.{$baseDomain}",
            // SWR stale shadow — the router Worker's second cache layer,
            // 7-day TTL. Without purging this, post-mutation refreshes serve
            // pre-mutation content from the shadow.
            "https://{$h}.{$baseDomain}/_swr-shadow/",
        ];
        // ... dispatched as {"files": $urls} — exact URL match only
        ```
        ```js
        // Worker caches every distinct visitor URL — /gallery, /services, etc.
        ctx.waitUntil(
          cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
        );
        ctx.waitUntil(
          cache.put(staleShadowKey(request), await withCacheTtl(fresh, STALE_SHADOW_TTL_S)),
        );
        ```

- [ ] **#EDGE-3** · P0 — Moderation enforcement bypasses edge cache purge entirely; taken-down content survives in the stale shadow for up to seven days
    - **Where:** app/Services/Moderation/ModerationActionDispatcher.php:26–44 · app/Jobs/Moderation/PurgeModerationCacheJob.php:39–50 · app/Jobs/Moderation/SuspendSiteJob.php:56–58 · app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:96–100
    - **Affects:** Every visitor to a moderation-taken-down page (`hide_site`, `suspend_user`, `ban_user`, `csam_auto_suspend`) — including direct deep links and the root URL — for up to 24 hours (primary cache) or seven days (stale shadow). The CSAM auto-suspend path in particular means illegal content can remain edge-cached and publicly reachable for a week after a moderation action.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `ModerationActionDispatcher::ACTIONS_BY_DECISION`, replace `sync_subdomain_kv` with both `sync_subdomain_kv` AND `purge_cloudflare_cache` on every decision that hides or suspends a site/user (`hide_site`, `suspend_user`, `ban_user`, `csam_auto_suspend`).
        - Rewrite `PurgeModerationCacheJob::handle()` to dispatch `CloudflareCachePurgeJob` for the case owner's handle (look up via `$case->reportable_owner_user_id`) in addition to (not instead of) the KV sync. The purge job already has the correct retry/backoff policy.
        - For `SuspendUserJob` and `SuspendSiteJob`, which use mass updates bypassing Eloquent observers, the dispatcher-level fix is the only reliable hook — do not rely on the observer chain.
        - Confirm the handle lookup path in `PurgeModerationCacheJob` uses `User::withTrashed()` so a simultaneously-soft-deleted user's handle is still resolvable.
    - **Technical:** Three independent bugs combine to create this gap. First, `SuspendSiteJob` and `SuspendUserJob` both use Eloquent mass updates (`Site::query()->where(...)->update([...])`) that intentionally bypass the Observer pattern — so `SiteObserver::saved` never fires and `CloudflareCachePurgeJob` is never dispatched from those paths. Second, `ModerationActionDispatcher` maps `'sync_subdomain_kv'` and `'purge_cloudflare_cache'` to the same job — `PurgeModerationCacheJob` — whose `handle()` only dispatches `SyncSubdomainToKvJob`. Third, `SyncSubdomainToKvJob` checks `$pro->trashed()` to decide whether to remove the KV entry; a suspended or hidden user is NOT trashed, so the job upserts `{type: "individual"}` — leaving the KV entry live and the Worker routing traffic to `serveIndividual`, where the edge cache serves the pre-suspension content. No `CloudflareCachePurgeJob` exists anywhere in the moderation pipeline.
    - **Plain English:** When your trust-and-safety team takes down a page — whether for abuse, illegal content, or CSAM — three things need to happen: the database is updated (working), the routing table is updated (broken — suspended accounts aren't removed from KV), and the edge cache is cleared (broken — never happens). Right now your moderation "purge" job doesn't actually purge the edge cache at all. It just calls the routing-sync job, which re-confirms the page is live (because the user isn't deleted, just suspended). The result: the taken-down page continues to be served to anyone with a link for up to a week. This is both a user-safety failure and a potential legal-compliance failure for the CSAM enforcement path.
    - **Evidence:**
        ```php
        // ModerationActionDispatcher — no CloudflareCachePurgeJob in any enforcement path:
        private const ACTIONS_BY_DECISION = [
            'hide_content' => ['notify_reported_user', 'purge_cloudflare_cache'],
            'hide_site' => ['suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
            'suspend_user' => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
            'ban_user' => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
            'csam_auto_suspend' => ['quarantine_media', 'suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_oncall_staff'],
        ```
        ```php
        // dispatchJob — both 'sync_subdomain_kv' and 'purge_cloudflare_cache' resolve to the same job:
        'sync_subdomain_kv' => PurgeModerationCacheJob::dispatch($actionLogId, $caseId),
        'purge_cloudflare_cache' => PurgeModerationCacheJob::dispatch($actionLogId, $caseId),
        ```
        ```php
        // PurgeModerationCacheJob::handle — dispatches KV sync, NOT a cache purge:
        if ($case->reportable_owner_user_id !== null) {
            SyncSubdomainToKvJob::dispatch($case->reportable_owner_user_id);
        }
        ```
        ```php
        // SyncSubdomainToKvJob — suspended user is not trashed, so KV entry is upserted live:
        if (! $pro || $pro->trashed() || ! $pro->handle) {
            $this->retire($kv, $pro);
            return;
        }
        // … for a suspended (non-trashed) user, this still runs:
        $kv->put($current, ['type' => 'individual'], null);
        ```
        ```php
        // SuspendSiteJob — mass update bypasses SiteObserver entirely:
        Site::query()
            ->where('id', $siteId)
            ->update(['moderation_state' => 'hidden']);
        ```

---

## P1 — Fix before pilot launch

- [ ] **#EDGE-4** · P1 — `SiteObserver::deleted` invalidates the Redis cache but never purges the Cloudflare edge cache
    - **Where:** app/Observers/Core/SiteObserver.php:83–95 (`deleted`) vs 38–51 (`saved`)
    - **Affects:** Any user who soft-deletes their site (or whose site is hard-deleted by the account-deletion pipeline) — the site's content remains edge-cached and publicly accessible for up to 24 hours (primary) or seven days (stale shadow). The Worker KV entry is also not removed on site-only deletion (only on user deletion), so traffic is still routed to the cached page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CloudflareCachePurgeJob::dispatch($site->subdomain)` inside `SiteObserver::deleted`, mirroring the pattern already used in `SiteObserver::saved`. Because `SiteObserver` declares `public bool $afterCommit = true`, no explicit `->afterCommit()` chain call is needed.
        - Also confirm whether `SiteObserver::deleted` should dispatch `SyncSubdomainToKvJob` to remove the KV routing entry; currently only `UserObserver::deleted` removes the KV entry, so a site-level soft-delete leaves the subdomain routing alive.
    - **Technical:** `SiteObserver::saved` dispatches `CloudflareCachePurgeJob::dispatch($handle)->afterCommit()` (line 42). `SiteObserver::deleted` (lines 83–95) calls only `$this->siteCache->invalidateSite($site)` — which clears the Redis public-payload cache — and returns. There is no edge purge dispatch. Because the Worker's cache lookup (`serveIndividual → cache.match(request)`) runs before any origin hit, the cached 200 response continues to be served for up to 24 h from primary cache, and up to 7 d from the stale shadow. The observer itself runs after commit (`public bool $afterCommit = true`), so a dispatch added here would be correctly deferred past the transaction boundary.
    - **Plain English:** When someone deletes their site, the system clears the fast in-memory copy — but forgets to clear the copy stored at the edge of the internet (the Cloudflare cache). Anyone visiting the site's URL after deletion still sees the old page for up to 24 hours from the main cache, or seven days from the backup copy. The code already does this correctly when a site is *edited* (it clears both); it just forgot to do the same on *delete*.
    - **Evidence:**
        ```php
        // SiteObserver::saved — dispatches edge purge correctly:
        $handle = strtolower(trim((string) ($site->subdomain ?? '')));
        if ($handle !== '') {
            try {
                CloudflareCachePurgeJob::dispatch($handle)->afterCommit();
            } catch (\Throwable $e) {
                // ...
            }
        }

        // SiteObserver::deleted — Redis only, no edge purge:
        public function deleted(Site $site): void
        {
            try {
                $this->siteCache->invalidateSite($site);
            } catch (\Throwable $e) {
                Log::warning('Site cache invalidation failed on delete', $this->logContext(__METHOD__, [
                    'site_id' => $site->id,
                    'user_id' => $site->user_id,
                    'subdomain' => $site->subdomain,
                    'message' => $e->getMessage(),
                ]));
            }
        }
        ```

- [ ] **#EDGE-5** · P1 — Custom domain cache is never purged after site mutations
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:100–109 (`purgeHandle`) vs cloudflare-worker/src/index.js:264–276 (custom domain `serveIndividual` path)
    - **Affects:** Any user who has connected a custom domain (e.g. `tuesdae.co`) — every site edit, media update, and integration change is reflected immediately at `handle.partna.au` (purged) but remains stale at the custom domain for up to 24 hours (primary) or seven days (shadow). Feature added in commit `c7a016f4` but purge coverage was not updated.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `CloudflarePurgeService::purgeHandle`, look up the site's active custom domain (via `site.sites.custom_domain` where `custom_domain_status = 'active'`) and append its URL variants to the `$urls` array alongside the `*.partna.au` entries.
        - Include the `/_swr-shadow/` prefix for the custom domain as well.
        - Alternatively, pass the custom domain as a parameter to `purgeHandle` from the dispatch sites (observers already have `$site` available) to avoid the extra DB read inside the service.
    - **Technical:** The custom domain path in the Worker (`!hostname.endsWith("." + PARTNA_DOMAIN)`) calls `serveIndividual(env, ctx, request, custom.handle)` where `request.url` is the custom domain URL (`https://tuesdae.co/gallery`). `serveIndividual` caches via `cache.put(request, ...)` — the cache key is the custom domain URL. `CloudflarePurgeService::purgeHandle` builds only `https://{handle}.{baseDomain}/...` URLs; `custom_domain` is not referenced anywhere in the purge service. As a result, `CloudflareCachePurgeJob` fires, purges the `handle.partna.au` edge entries, but leaves `tuesdae.co/*` cache entries untouched for the full 24 h primary / 7 d shadow TTL.
    - **Plain English:** If a user connects their own domain (like `tuesdae.co`) to their Partna page, edits they make show up immediately when you visit their `handle.partna.au` address — but if you visit `tuesdae.co`, you'll see the old version for up to 24 hours (or a week from the backup). The cache-clearing system was built before custom domains existed and was never taught about the new address. This is a straightforward omission from the feature that added custom domain support.
    - **Evidence:**
        ```php
        // CloudflarePurgeService::purgeHandle — only *.partna.au URLs, no custom domain:
        $baseDomain = config('partna.public_domain');

        $urls = [
            "https://{$h}.{$baseDomain}/",
            "https://{$h}.{$baseDomain}",
            "https://{$h}.{$baseDomain}/_swr-shadow/",
        ];
        // custom_domain never referenced
        $this->purgeUrls($urls);
        ```
        ```js
        // Worker — caches using the custom domain URL as the cache key:
        if (!hostname.endsWith("." + PARTNA_DOMAIN)) {
          // ...
          if (custom && custom.type === "individual" && typeof custom.handle === "string") {
            return serveIndividual(env, ctx, request, custom.handle);
          }
        }
        // serveIndividual does: cache.put(request, ...) where request.url = "https://tuesdae.co/..."
        ```

- [ ] **#EDGE-6** · P1 — Worker `RESERVED` set (18 entries) diverges from `config('partna.reserved_subdomains')` (~200 entries); infrastructure subdomains missing from the Worker resolve to 404 instead of passing through
    - **Where:** cloudflare-worker/src/index.js:36–55 (`RESERVED`) vs config/partna.php:53–125 (`reserved_subdomains`)
    - **Affects:** Any subdomain that is in the config list but not the Worker's `RESERVED` set and that has a live DNS record (e.g. `apps`, `mail`, `staging`, `beta`) — the Worker does a KV lookup, finds no entry, and returns 404 instead of passing through to the real service. The Worker's own comment claims the set "Mirrors `reserved_subdomains` in config/partna.php" — that comment is false.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Audit which of the ~200 config reserved subdomains have actual DNS records / live services in the `partna.au` zone. Those (and only those) need to be in the Worker's `RESERVED` set so they pass through to DNS resolution rather than hitting KV.
        - The brand-impersonation and profanity entries in config exist purely to block handle registration — they don't need to be in `RESERVED` (they have no live services, so a KV miss → 404 is acceptable).
        - Add a bidirectional sync comment on both sides: the Worker's `RESERVED` comment should say "subset of `reserved_subdomains` — only entries with live DNS services; see docs/reservations.md", and `reserved_subdomains` in config should note "Worker RESERVED set is a subset; check both when adding infra subdomains".
        - Consider generating the Worker `RESERVED` set from a canonical source at build time to prevent future drift.
    - **Technical:** `RESERVED` controls whether the Worker calls `return fetch(request)` (pass-through, allows DNS resolution) before any KV lookup. With only 18 entries, any reserved subdomain that is not in this set — including potentially `apps`, `mail`, `email`, `staging`, `dev`, `beta`, `login`, `signup`, `register` — hits the KV path, gets a miss, and returns a `404 Not Found` response with `Cache-Control: no-store`. If those subdomains have live DNS records (A/CNAME) pointing to real services, traffic to them is silently broken. The inverse concern (an entry in `RESERVED` but not in config could be claimed as a user handle) is not present — all 18 Worker entries appear in the config list.
    - **Plain English:** The Worker has a short allowlist of ~18 "well-known" names that get passed through to their real services. The backend has a much longer list of ~200 names that users aren't allowed to claim as their handle. These two lists should overlap, but they don't. Any of the 200 backend-reserved names that also has a real service behind it (like a login page, a mail server, or a staging environment) would silently return "Not Found" to anyone who visits it — because the Worker doesn't know to let it through. The Worker's own comment says the lists match, but they don't.
    - **Evidence:**
        ```js
        // Worker RESERVED — 18 entries (comment claims it mirrors config):
        // Mirrors `reserved_subdomains` in config/partna.php — these never go to KV.
        const RESERVED = new Set([
          "www", "api", "admin", "app", "staff", "dashboard",
          "support", "help", "billing", "static", "cdn", "assets",
          "auth", "docs", "status", "comet", "sidest", "partna",
        ]);
        ```
        ```php
        // config/partna.php — ~200 entries across 10 categories:
        'reserved_subdomains' => [
            'www', 'api', 'admin', 'app', 'apps', 'staff', 'dashboard',
            'support', 'help', 'helpdesk', 'billing', 'static', 'cdn', 'assets',
            'auth', 'docs', 'status', 'comet', 'sidest', 'partna',
            'mail', 'email', 'smtp', 'imap', 'pop', 'pop3', 'webmail',
            // … ~180 more entries: dev, staging, login, signup, google,
            //   stripe, facebook, fuck, cunt, … through 'nsfw'
        ],
        ```

---

## P2 — Should fix

- [ ] **#EDGE-7** · P2 — Security headers absent on `?skeleton=` preview, non-GET, 503, and all pass-through response paths
    - **Where:** cloudflare-worker/src/index.js:207–215 (preview/non-GET returns), 194–199 (503 response), 257–259 / 282–284 / 270–272 / 330–331 (pass-through returns)
    - **Affects:** Visitors reaching the skeleton preview mode, any non-GET request forwarded to `partna-pages`, the 503 error page, and any request routing through a pass-through branch — all receive origin responses without HSTS, `X-Frame-Options`, `X-Content-Type-Options`, or `Referrer-Policy`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For `?skeleton=` and non-GET, wrap the origin response through `tagResponse(…, "preview")` / `tagResponse(…, "passthrough")` before returning.
        - For the 503 response, inline `applySecurityHeaders(h)` before constructing the `new Response`.
        - Pass-through paths (`return fetch(request)`) that proxy to real origin services should wrap the awaited response: `return tagResponse(await fetch(request), "passthrough")`. Apex and reserved pass-throughs serve the marketing site, which benefits from HSTS enforcement.
    - **Technical:** `tagResponse` calls `applySecurityHeaders` on every response it wraps. It is called on: the cache-hit path (`cached`), the stale-shadow path (`shadow`), the cold-origin path (`fresh`), the 404 not-found path, and the alias 301 path. The following paths skip it: (1) `?skeleton=` preview — returns raw `env.PARTNA_PAGES.fetch(originRequest)` (line 208); (2) non-GET method — same raw return (line 215); (3) 503 missing-binding — `new Response("Service Unavailable", ...)` with no security headers (line 196–199); (4) all `return fetch(request)` pass-through branches (apex, reserved, multi-level, KV failure, custom-domain unknown, unknown entry type). The `?skeleton=` and non-GET paths are the highest-priority fixes because they return sitepage content directly from `partna-pages` without the Worker's security header layer.
    - **Plain English:** The Worker applies a standard set of browser safety instructions (like "always use HTTPS" and "don't let other sites embed this page") to most responses. But several side-door paths — the skeleton preview mode, non-GET requests, the "service unavailable" error page, and the main `partna.au` homepage — skip those instructions. Most of these are low-traffic edge cases, but the skeleton preview is user-facing and currently ships without the browser safety headers the rest of the site gets.
    - **Evidence:**
        ```js
        // ?skeleton= preview — raw origin response, no tagResponse:
        if (new URL(request.url).searchParams.has("skeleton")) {
          return env.PARTNA_PAGES.fetch(originRequest);
        }

        // Non-GET — same raw return:
        if (request.method !== "GET") {
          return env.PARTNA_PAGES.fetch(originRequest);
        }

        // 503 — no applySecurityHeaders:
        return new Response("Service Unavailable", {
          status: 503,
          headers: {"Content-Type": "text/plain", "Cache-Control": "no-store"},
        });

        // Apex pass-through — no applySecurityHeaders:
        if (hostname === PARTNA_DOMAIN) {
          return fetch(request);
        }
        ```

- [ ] **#EDGE-8** · P2 — No `Content-Security-Policy` header; Worker lags behind stated security posture
    - **Where:** cloudflare-worker/src/index.js:118–146 (`applySecurityHeaders`); the gap is acknowledged in the function's own JSDoc comment
    - **Affects:** All sitepage visitors — no CSP means no defence against XSS injection via user-controlled content in the Astro render, and `X-Frame-Options: SAMEORIGIN` is the legacy clickjacking defence (CSP `frame-ancestors` supersedes it and is universally supported).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `headers.set("Content-Security-Policy", "frame-ancestors 'self'")` to `applySecurityHeaders` as a minimum — this replaces `X-Frame-Options` with the modern equivalent.
        - Coordinate with the Astro team on `default-src`/`script-src`/`style-src` directives to cover the sitepage's asset sources; add them to the same header once the allowed origins are known.
        - Keep `X-Frame-Options` as belt-and-suspenders for older browsers.
    - **Technical:** `applySecurityHeaders` sets HSTS, `X-Content-Type-Options`, `Referrer-Policy`, and `X-Frame-Options: SAMEORIGIN`. The function's JSDoc explicitly acknowledges: "CSP frame-ancestors is the modern equivalent; the sitepage doesn't ship a CSP yet." Because the Worker is the single chokepoint for all sitepage responses (every visitor-facing return path calls `tagResponse` which calls `applySecurityHeaders`), adding CSP here is a one-shot fix covering 100% of sitepage traffic without any changes to the Astro app. `frame-ancestors 'self'` is safe immediately; broader `script-src` / `style-src` requires an asset-origin audit with the Astro team first.
    - **Plain English:** The Worker applies several browser safety instructions to every sitepage response — but it's missing the modern "don't let other sites embed this page in an iframe" instruction (called a Content Security Policy). The code even has a comment saying this is missing. This is a browser-level protection that costs one line to add. The existing `X-Frame-Options` header does the same thing for older browsers, but the modern version is more powerful and supported everywhere.
    - **Evidence:**
        ```js
        /** Apply the standard set of security headers in place on a Headers
         * instance. Mirrors the backend SecureHeaders middleware …
         *
         * - X-Frame-Options: SAMEORIGIN — defence-in-depth against clickjacking
         *   for older browsers (CSP frame-ancestors is the modern equivalent;
         *   the sitepage doesn't ship a CSP yet). */
        function applySecurityHeaders(headers) {
          if (!headers.has("Strict-Transport-Security")) {
            headers.set("Strict-Transport-Security", "max-age=31536000; includeSubDomains");
          }
          if (!headers.has("X-Content-Type-Options")) {
            headers.set("X-Content-Type-Options", "nosniff");
          }
          if (!headers.has("Referrer-Policy")) {
            headers.set("Referrer-Policy", "strict-origin-when-cross-origin");
          }
          if (!headers.has("X-Frame-Options")) {
            headers.set("X-Frame-Options", "SAMEORIGIN");
          }
          // No Content-Security-Policy set
        }
        ```

- [ ] **#EDGE-9** · P2 — Query-string variants mint unlimited distinct cache entries; no normalisation or allowlist
    - **Where:** cloudflare-worker/src/index.js:221 (`cache.match(request)`) and 155–157 (`cache.put(request, ...)`)
    - **Affects:** Cache efficiency and zone storage quota — any visitor or crawler appending arbitrary query parameters (`?utm_source=x`, `?cachebust=1`, `?cachebust=2`, …) creates a new primary and shadow cache entry per variant. A motivated actor can trigger cache evictions for legitimate content.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before the `cache.match(request)` call in `serveIndividual`, build a normalised cache-key URL that strips ignored query parameters (all `utm_*`, `fbclid`, `gclid`, `ref`, `source`) and sorts the remaining parameters alphabetically.
        - Construct a `new Request(normalizedUrl, request)` to use as the cache key for both `cache.match` and `cache.put` while keeping the original `request` for the upstream `env.PARTNA_PAGES.fetch` call (the Astro app may legitimately read some query params).
        - Note: the existing `?skeleton=` bypass (commit `6d12abea`) correctly bypasses caching for that param — the normalised key should also drop `skeleton` from the cache key (it is already not cached, so this is belt-and-suspenders).
    - **Technical:** Cloudflare's Workers Cache API (`caches.default`) keys on the full request URL including the raw query string. `cache.match(request)` and `cache.put(request, ...)` both use `request` directly, which carries the visitor's unmodified URL. Sitepage content is identical regardless of tracking query parameters — `?utm_campaign=summer` and `?utm_campaign=winter` render the same HTML — but the Cache API stores them as separate entries. Each entry gets a primary (24 h) and shadow (7 d) copy. While Cloudflare's per-zone storage is large, a sustained scan with incrementing query params can evict valid cache entries and increase origin load.
    - **Plain English:** The caching system treats `yoursite.partna.au/?from=instagram` and `yoursite.partna.au/?from=twitter` as two completely different pages, even though they show identical content. This means the cache fills up with thousands of copies of the same page — one for every marketing link or URL variation anyone ever clicked. Each copy takes up space and could push a real page out of cache. Normalising the URL before caching it collapses all these variations into one copy.
    - **Evidence:**
        ```js
        const cache = caches.default;

        // 1) Primary cache HIT — fastest path.
        const cached = await cache.match(request);  // full URL including raw query string
        if (cached) {
          return tagResponse(cached, "hit");
        }
        // …
        ctx.waitUntil(
          cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
        );
        ```

- [ ] **#EDGE-10** · P2 — Staging Worker shares the production `SUBDOMAIN_KV` namespace; a staging backend deploy can poison production routing
    - **Where:** cloudflare-worker/wrangler.toml:20–23 (`[[kv_namespaces]]`) and 36–39 (`[env.staging]`)
    - **Affects:** Production routing table — any `SyncSubdomainToKvJob` that runs in a staging environment configured with the production `kv_namespace_id` (whether by accident or during an integration test) writes to the same KV namespace that production Workers read. A staging handle rename or delete could 404 real users on production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a separate KV namespace for staging: `wrangler kv:namespace create SUBDOMAIN_KV --env staging`.
        - Add a `[[env.staging.kv_namespaces]]` block in `wrangler.toml` binding `SUBDOMAIN_KV` to the staging namespace ID.
        - Ensure `CLOUDFLARE_KV_NAMESPACE_ID` in the staging Laravel environment points to the same staging namespace.
        - Add a comment in `wrangler.toml` noting that the `preview_id` (already present) covers `wrangler dev` local sessions; staging Workers in Cloudflare's infra need a distinct deployment-target namespace.
    - **Technical:** `wrangler.toml` defines `[[kv_namespaces]]` with `id = "ce726607804d41a296d6da150b0c537f"` at the top level, which Wrangler applies to all environments including `staging`. The `[env.staging]` override only rebinds `PARTNA_PAGES` (the service binding) — it does not override `kv_namespaces`. If `wrangler deploy --env staging` is executed, the deployed Worker reads from the production KV namespace. If the Laravel staging environment also uses the production `kv_namespace_id` config value, `SyncSubdomainToKvJob` writes from staging to the production routing table. The `preview_id` covers only local `wrangler dev` sessions.
    - **Plain English:** The staging and production Workers share the same "phone book" (the KV routing table) that tells the Worker which website belongs to which domain name. If someone deploys a test version of the Worker to Cloudflare's staging environment, or if the staging backend writes to the wrong phone book, it can accidentally change or delete entries in the production routing table — meaning real visitors get 404s or are redirected to the wrong site. Staging should have its own phone book.
    - **Evidence:**
        ```toml
        # Top-level KV binding — applies to ALL environments including staging:
        [[kv_namespaces]]
        binding = "SUBDOMAIN_KV"
        id = "ce726607804d41a296d6da150b0c537f"
        preview_id = "e6a8eecd305148f9a75b879aa6faf790"

        # Staging override — only rebinds the Astro service, not the KV namespace:
        [env.staging]
        [[env.staging.services]]
        binding = "PARTNA_PAGES"
        service = "partna-pages-staging"
        # Missing: [[env.staging.kv_namespaces]] binding for a staging-only namespace
        ```

---

## P3 — Nice to have

- [ ] **#EDGE-11** · P3 — Hardcoded Worker constants lack cross-reference comments to their backend mirrors
    - **Where:** cloudflare-worker/src/index.js:33–63 (constants) vs app/Services/Cloudflare/CloudflarePurgeService.php:98 and config/partna.php
    - **Affects:** Any engineer who changes a TTL or domain in one place without realising the other exists — silent staleness or broken routing in the subsequent deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - On `PRIMARY_CACHE_TTL_S`, add: `// CloudflarePurgeService::purgeHandle assumes this window for push-freshness guarantee`.
        - On `STALE_SHADOW_TTL_S`, add: `// purge must clear /_swr-shadow/* to close the 7-day window; see CloudflarePurgeService::purgeHandle`.
        - On `PARTNA_DOMAIN`, add: `// Mirrors config('partna.public_domain') — update wrangler.toml routes AND the backend config together`.
        - In `CloudflarePurgeService::purgeHandle`, add: `// PRIMARY_CACHE_TTL_S=86400 and STALE_SHADOW_TTL_S=7*86400 in cloudflare-worker/src/index.js must match these purge assumptions`.
    - **Technical:** `PRIMARY_CACHE_TTL_S = 86_400` and `STALE_SHADOW_TTL_S = 7 * 86_400` are JavaScript constants with no reference to the backend purge service that must respect the same windows. `PARTNA_DOMAIN = "partna.au"` is hardcoded while the backend derives the public domain from `config('partna.public_domain')`. The purge service does reference the Worker in its docblock for the shadow copy (line 71–81), so one direction of the cross-reference already exists; the Worker side is missing the reverse pointer.
    - **Plain English:** The Worker has numbers baked in — "cache for 24 hours," "keep a backup for 7 days" — and the backend has code that assumes exactly those numbers. Neither file has a note pointing to the other. It's like two people each having half a shared recipe with no indication the halves belong together. A future engineer changing one without knowing about the other would silently break the freshness guarantee.
    - **Evidence:**
        ```js
        /** Primary cache TTL in seconds — 24 h, push-purged on mutation. */
        const PRIMARY_CACHE_TTL_S = 86_400;

        /** Stale-shadow TTL — 7 d. Wide window so even multi-day backend outages
         * serve the last good render. SWR refresh re-extends the shadow each
         * successful origin hit. */
        const STALE_SHADOW_TTL_S = 7 * 86_400;

        const PARTNA_DOMAIN = "partna.au";
        ```
        ```php
        // CloudflarePurgeService — derives domain from config but has no Worker TTL comment:
        $baseDomain = config('partna.public_domain');
        ```

- [ ] **#EDGE-12** · P3 — Non-OK origin responses pass through with their original `Cache-Control`; a misconfigured error handler could cause browsers to cache error pages
    - **Where:** cloudflare-worker/src/index.js:148–166 (`fetchAndCache`) and 235 (`serveIndividual` return)
    - **Affects:** Visitors who hit the site during an Astro-origin error — if the origin returns a 5xx with a permissive `Cache-Control` header, the visitor's browser (and potentially downstream proxies) cache the error page. Rare in practice since most frameworks default to `no-store` on errors, but the Worker has no defensive override.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `serveIndividual`, when `!fresh.ok`, wrap the response before `tagResponse` to inject `Cache-Control: no-store` if it's missing: `if (!fresh.ok) { const h = new Headers(fresh.headers); h.set("Cache-Control", "no-store"); fresh = new Response(fresh.body, { ...fresh, headers: h }); }`
    - **Technical:** `fetchAndCache` correctly skips `cache.put` for non-OK responses. However, `return fresh` passes the raw origin response back to `serveIndividual`, which wraps it with `tagResponse(fresh, "origin-error")`. `tagResponse` adds `X-Partna-Cache` and security headers but does not inspect `fresh.ok` to add `Cache-Control: no-store`. Whatever `Cache-Control` the Astro origin sent on the error response (e.g. `max-age=300` from a misconfigured catch-all) is forwarded to the visitor's browser and any intermediate proxy.
    - **Plain English:** When the website's server has a problem and returns an error page, the Worker correctly avoids saving the error in its own cache. But it passes the error straight to the visitor without adding the instruction "don't save this in your browser either." If the server accidentally sent a "save this for five minutes" instruction on the error page, the visitor's browser would show the error page repeatedly even after the server recovers.
    - **Evidence:**
        ```js
        async function fetchAndCache(env, ctx, request, cache, originRequest) {
          const fresh = await env.PARTNA_PAGES.fetch(originRequest ?? request);

          if (fresh.ok && request.method === "GET") {
            ctx.waitUntil(
              cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
            );
            ctx.waitUntil(
              cache.put(staleShadowKey(request), await withCacheTtl(fresh, STALE_SHADOW_TTL_S)),
            );
          }

          return fresh;  // non-OK: passes through with whatever Cache-Control origin sent
        }

        // In serveIndividual:
        const fresh = await fetchAndCache(env, ctx, request, cache, originRequest);
        return tagResponse(fresh, fresh.ok ? "origin" : "origin-error");
        // tagResponse does not add no-store on non-OK
        ```

- [ ] **#EDGE-13** · P3 — The two `ctx.waitUntil(cache.put(...))` calls run independently; a failure on either is invisible
    - **Where:** cloudflare-worker/src/index.js:154–162 (`fetchAndCache`)
    - **Affects:** Edge cache consistency after a transient Cache API error — if the primary put succeeds and the shadow put fails (or vice versa), the two copies diverge with no log signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Combine both `cache.put` calls into a single `ctx.waitUntil(Promise.all([...]).catch(err => console.error("cache.put failed", err)))` so both writes are atomic from a monitoring perspective and any failure produces a visible `console.error`.
        - Alternatively, wrap in a sequential try/catch and log the subdomain and which copy (primary vs shadow) failed.
    - **Technical:** The two `ctx.waitUntil(cache.put(...))` calls are independent promises dispatched into the background. A failure on the second (shadow) `cache.put` while the first (primary) `cache.put` succeeds leaves the shadow pointing to whatever stale content it previously held. On the next primary-cache miss, `serveIndividual` would serve the stale shadow and kick off a background refresh — which is the intended SWR behaviour, just with unintended content. The worst-case outcome is a single visitor receiving slightly stale content while the refresh re-establishes both copies; this is self-correcting within one request cycle and fails safe (does not serve wrong content permanently). The absence of any `catch` means transient Cache API errors produce no log line, so the incident is invisible unless Cloudflare's own telemetry surfaces it.
    - **Plain English:** When the Worker saves a fresh copy of a page, it saves two copies — a short-term one and a seven-day backup — in two separate operations. If saving the backup glitches, the short-term copy is saved fine but the backup is outdated, with no log or alert about the failure. The next visitor after the short-term copy expires would see the slightly older backup instead of the latest version. This self-corrects after one more request, so the impact is minimal — but the silent failure makes debugging harder.
    - **Evidence:**
        ```js
        if (fresh.ok && request.method === "GET") {
          // Primary cache — short-ish, push-purged on edits.
          ctx.waitUntil(
            cache.put(request, await withCacheTtl(fresh, PRIMARY_CACHE_TTL_S)),
          );
          // Stale shadow — long-lived.
          ctx.waitUntil(
            cache.put(staleShadowKey(request), await withCacheTtl(fresh, STALE_SHADOW_TTL_S)),
          );
          // No .catch() on either — a failure on either put is completely invisible
        }
        ```
