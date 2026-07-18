# Edge Worker Audit — 2026-07-09

**Branch:** development
**Lens:** Edge worker: Cloudflare routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `cloudflare-worker/src/index.js`
- `cloudflare-worker/wrangler.toml`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Cloudflare/CloudflareKvService.php`
- `app/Http/Middleware/SecureHeaders.php`
- `config/partna.php`

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#EDGE-1** · P0 — Custom-domain KV routing entry is never retired on deletion/suspension/moderation-hide — a taken-down user's custom domain keeps serving their live page indefinitely
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:151-160` (`retire()`), `:129-134` (the `domain:<host>` write this never undoes)
    - **Affects:** Every professional with an **active Cloudflare-for-SaaS custom domain** (e.g. `tuesdae.co`) who is later account-deleted, suspended, or moderation-hidden — CSAM auto-suspends included, per `PurgeModerationCacheJob`'s own comment. Their custom domain keeps resolving to their content with no time bound.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - In `SyncSubdomainToKvJob::retire()`, resolve the (possibly-trashed) user's site — `$pro?->site` inside the same try/catch pattern already used in `handle()` — and, when `custom_domain_status === 'active'` and `custom_domain` is set, also call `$kv->delete("domain:{$customDomain}")` alongside the existing `$kv->delete($handle)`.
        - Add a regression test that suspends/soft-deletes/moderation-hides a user with an active custom domain and asserts BOTH the `handle` and `domain:<host>` KV keys are gone.
        - Note this same gap is inherited by the weekly `partna:backfill-subdomain-kv` resync (`app/Console/Commands/BackfillSubdomainKvCommand.php`), since it re-dispatches this same job — fixing `retire()` closes both paths at once.
    - **Technical:** `retire()` is the single cleanup path called for a trashed user, a suspended/inactive user (`!$pro->isActive()`), and a moderation-hidden site (`moderation_state === 'hidden'`) — confirmed via `ModerationActionDispatcher`'s `suspend_site` → `SuspendSiteJob` (sets `moderation_state='hidden'` only, never touches `custom_domain_status`) and `sync_subdomain_kv`/`purge_cloudflare_cache` → `PurgeModerationCacheJob` (calls `SyncSubdomainToKvJob::dispatch($ownerId)`, which routes to `retire()`). But `retire()` only calls `$kv->delete($handle)` — it never inspects `$pro->site->custom_domain`. The `domain:<host>` entry is written by the active-user branch of `handle()` and is otherwise only ever cleared via the separate `$retireCustomDomain` constructor argument, which `CustomDomainController` passes exclusively when a user voluntarily disconnects their own domain — never on a takedown. Consequence at the edge: the Worker's custom-domain branch (`env.SUBDOMAIN_KV.get('domain:'+hostname)`) still resolves `{type:'individual', handle}`, so `serveIndividual()` keeps running for that host. `PurgeModerationCacheJob` does correctly pass the custom domain to `CloudflareCachePurgeJob::dispatch()`, so the *cached* copy is busted at the moment of takedown — but because the KV pointer survives, the very next request is a cold miss that calls `env.PARTNA_PAGES.fetch()` and, on any `fresh.ok` response, re-populates both the primary and 7-day shadow cache with the still-fully-live content. The purge doesn't make the takedown stick; it just resets the clock.
    - **Plain English:** When someone connects their own web address (like `tuesdae.co`) to their Partna page, we keep a note in our routing system saying "this address belongs to this person." When we suspend, delete, or take down that person's account, we correctly clear the note for their `partna.au` address — but we forget to clear the note for their custom address. So their own domain keeps pointing straight at their (supposedly taken-down) page, forever, until someone notices and fixes it by hand. It's like confiscating someone's shop sign at one entrance but leaving a second, unlocked door around back with no sign removed at all.
    - **Evidence:**
        ```php
        // SyncSubdomainToKvJob.php:151-160 — retire() deletes only the handle key
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
        // SyncSubdomainToKvJob.php:129-134 — the domain:<host> entry retire() never cleans up
        if ($site) {
            $customDomain = strtolower(trim((string) ($site->custom_domain ?? '')));
            if ($customDomain !== '' && ($site->custom_domain_status ?? null) === 'active') {
                $kv->put("domain:{$customDomain}", ['type' => 'individual', 'handle' => $current], null);
            }
        }
        ```

## P2 — Should fix

- [ ] **#EDGE-2** · P2 — Reserved-subdomain list is a manual mirror between the Worker and `config/partna.php` with no automated drift guard
    - **Where:** `cloudflare-worker/src/index.js:44-110` (`RESERVED` set); `config/partna.php:65-142` (`reserved_subdomains` array)
    - **Affects:** Platform infrastructure surfaces and user handle claims. Verified: the two lists are currently identical token-for-token, but nothing enforces that going forward. A subdomain reserved in config but missing from the Worker's `RESERVED` set is sent to KV and 404s instead of passing through to the apex origin; a subdomain reserved in the Worker but missing from config lets a user claim it as a handle, which the Worker then permanently pass-throughs instead of serving.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a PHPUnit test (in the style of `tests/Feature/Architecture/AuditPipelineIntegrityTest.php` / `PolicyCoverageTest.php`) that reads `cloudflare-worker/src/index.js`, extracts the `RESERVED` set, and diffs it token-by-token against `config('partna.reserved_subdomains')`, failing CI on any mismatch.
        - The Worker's own header comment already says "KEEP IN SYNC" — extend that comment with a pointer to the new test so both sides name the guard.
    - **Technical:** Confirmed by direct comparison: both lists currently contain the exact same ~180 tokens in the same grouped order. The Worker comment (`// Mirrors reserved_subdomains in config/partna.php ... KEEP IN SYNC`) documents the intent but nothing mechanically enforces it — a search of `tests/` turns up no test that diffs the two. This repo already has precedent for exactly this class of guard (`PolicyCoverageTest`, `AuditPipelineIntegrityTest`, `JobHygienePolicyTest`), so the fix is a small, idiomatic addition rather than new infrastructure.
    - **Plain English:** There are two copies of the "names nobody is allowed to claim" list — one read by the server, one read by the front-door router — and they currently match, but only because someone was careful. There's no automatic check that would catch the day they stop matching. A simple test that compares the two lists on every code change would catch that the moment it happens, instead of relying on human memory.
    - **Evidence:**
        ```javascript
        // cloudflare-worker/src/index.js:44-47
        // Mirrors `reserved_subdomains` in config/partna.php (EDGE-6/EDGE-11). KEEP IN
        // SYNC: a subdomain missing here is sent to KV and 404s instead of passing
        // through to the apex origin. This is a manual mirror — when config changes,
        // update this set (or wire a build step that generates it from the PHP config).
        ```
        ```php
        // config/partna.php:65-70
        // Handles/subdomains a user can never claim. Exact-match, case-insensitive.
        // Substring matching is deliberately avoided (Scunthorpe problem) — every
        // entry here must satisfy the DNS-safe regex used by the subdomain validator:
        // ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ (no dots, no underscores, no leading/trailing dash).
        'reserved_subdomains' => [
        ```

- [ ] **#EDGE-3** · P2 — Worker's `applySecurityHeaders` doesn't mirror the backend's `Permissions-Policy` header
    - **Where:** `cloudflare-worker/src/index.js:249-262` (`applySecurityHeaders`); `app/Http/Middleware/SecureHeaders.php:49` (`Permissions-Policy` set on every backend response)
    - **Affects:** Every visitor to every `<handle>.partna.au` / custom-domain sitepage — 100% of public sitepage traffic never receives a `Permissions-Policy` header, unlike every backend API response.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `if (!headers.has("Permissions-Policy")) headers.set("Permissions-Policy", "camera=(), microphone=(), geolocation=()");` to `applySecurityHeaders`, matching the backend value verbatim.
        - Add a one-line comment on both sides ("mirrors SecureHeaders.php" / "mirrors applySecurityHeaders in cloudflare-worker/src/index.js") so future header additions get made in both places together.
    - **Technical:** `app/Http/Middleware/SecureHeaders::apply()` sets `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`, and `Strict-Transport-Security` on every backend response. The Worker's `applySecurityHeaders()` — which the file's own docblock says "Mirrors the backend SecureHeaders middleware" — covers HSTS, nosniff, Referrer-Policy, and X-Frame-Options, but has no `Permissions-Policy` guard at all. (The CSP and X-Frame-Options gaps are intentionally different by design — sitepages need `frame-ancestors` to allow the `app.partna.au` design-preview iframe, which `finalize()` already handles — but `Permissions-Policy` has no such product reason to differ.) Since sitepages can embed third-party links/media blocks, closing camera/mic/geolocation access at the header level is a real, zero-cost hardening step that's already the policy everywhere else.
    - **Plain English:** The main server tells every visitor's browser "this page is not allowed to ask for your camera, microphone, or location" — but the fast edge server that actually serves public profile pages forgets to say that. It's a locked-door instruction that's posted everywhere in the building except the one entrance most visitors actually use. Adding the same line to the edge closes that gap for free.
    - **Evidence:**
        ```javascript
        // cloudflare-worker/src/index.js:249-262 — no Permissions-Policy branch
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
        }
        ```
        ```php
        // SecureHeaders.php:49 — the backend sets this on every response
        $set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        ```

- [ ] **#EDGE-4** · P2 — Origin's browser `max-age` passes through the edge unmodified, extending how long a taken-down page can survive in a visitor's own browser cache
    - **Where:** `cloudflare-worker/src/index.js:214-232` (`withCacheTtl`)
    - **Affects:** Visitors who viewed a sitepage shortly before it was moderation-hidden, unpublished, or the account was deleted. The edge purge (when dispatched) clears the CDN copy; it has no reach into a visitor's own browser cache.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `withCacheTtl`, cap the origin's `max-age` (if present) to a short ceiling — e.g. 60s — instead of passing it through unmodified, so the browser-cache window can't meaningfully outlive a takedown. The edge's own `s-maxage` overlay already governs CDN freshness independently.
        - Document the resulting browser-cache contract with a short comment so the number is deliberate, not incidental.
    - **Technical:** `withCacheTtl` strips `Set-Cookie` and filters out only the `s-maxage=` directive from the origin's `Cache-Control` before overlaying its own `s-maxage=${ttlSeconds}` — every other directive from the origin (including `max-age`, which governs browser-side caching) passes through untouched. If the Astro origin ever sets a non-trivial `max-age`, a browser that cached the page shortly before a takedown would keep rendering it locally for that whole window, independent of anything the edge purge or KV retire accomplishes — those only affect what NEW requests receive.
    - **Plain English:** Your browser keeps its own private copy of pages you've visited recently, separate from Partna's servers. If a page gets taken down, the platform stops handing out new copies immediately — but your browser might still be showing you the one it already saved, for as long as the page told it to. The fix is telling browsers not to hold onto sitepages for long, so a takedown actually reaches everyone quickly, not just new visitors.
    - **Evidence:**
        ```javascript
        // cloudflare-worker/src/index.js:221-226
        const original = headers.get("Cache-Control") ?? "";
        const directives = original
          .split(",")
          .map((s) => s.trim())
          .filter((s) => s.length > 0 && !s.toLowerCase().startsWith("s-maxage="));
        directives.push(`s-maxage=${ttlSeconds}`);
        ```

## P3 — Nice to have

- [ ] **#EDGE-5** · P3 — `CloudflarePurgeService` docblock cites a stale 10-second edge TTL; actual Worker TTLs are 24h / 7d
    - **Where:** `app/Services/Cloudflare/CloudflarePurgeService.php:70` (docblock); `cloudflare-worker/src/index.js:113,118` (actual constants)
    - **Affects:** Engineers reading the purge service while debugging a stale-content report.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the `purgeHandle` docblock to reference the Worker's real constants (`PRIMARY_CACHE_TTL_S = 86_400`, `STALE_SHADOW_TTL_S = 7 * 86_400`) instead of `s-maxage=10`.
    - **Technical:** The docblock says the page URL is "Cached by the router Worker via `caches.default.put` with `s-maxage=10`" — a leftover from an earlier iteration. The Worker itself defines `PRIMARY_CACHE_TTL_S = 86_400` and `STALE_SHADOW_TTL_S = 7 * 86_400`, and its own inline comments are accurate and current, so this is unlikely to mislead anyone who reads the Worker source directly — but it's a real, verified inaccuracy in the one place a backend engineer is most likely to look first.
    - **Plain English:** A comment in the cache-purge code says pages expire in 10 seconds; they actually take up to 24 hours (or 7 days for the backup copy). It's a leftover note from an earlier version that nobody updated — worth a one-line fix so it doesn't send someone down the wrong path during an incident.
    - **Evidence:**
        ```php
        // CloudflarePurgeService.php:69-70
         *   1. Page URL (`https://<handle>.partna.au/`) — what visitors hit. Cached by
         *      the router Worker via `caches.default.put` with `s-maxage=10`.
        ```
        ```javascript
        // cloudflare-worker/src/index.js:112-118
        const PRIMARY_CACHE_TTL_S = 86_400;
        const STALE_SHADOW_TTL_S = 7 * 86_400;
        ```

- [ ] **#EDGE-6** · P3 — Staging KV namespace is still a literal placeholder in `wrangler.toml` (already self-flagged with a TODO)
    - **Where:** `cloudflare-worker/wrangler.toml:42-53`
    - **Affects:** A future `--env staging` Worker deploy only. No production path is affected — the gap is already documented in-repo with exact next steps.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Run `wrangler kv namespace create SUBDOMAIN_KV_STAGING` and `... --preview`, paste both resulting IDs into the `env.staging.kv_namespaces` block, and point the staging Laravel env's `CLOUDFLARE_KV_NAMESPACE_ID` at the same namespace — exactly as the in-file TODO already specifies.
    - **Technical:** `[[env.staging.kv_namespaces]]` carries literal placeholder strings (`REPLACE_WITH_STAGING_KV_NAMESPACE_ID` / `_PREVIEW_ID`). A `wrangler deploy --env staging` today would bind to a non-existent namespace; KV reads would fail and fall open to `passThrough` (per the Worker's existing fail-open behavior on KV errors), not silently write into production — the file's own comment overstates the "poison prod" risk of the placeholder itself, since the Laravel backend's KV writes go through a wholly separate REST call keyed by `config('services.cloudflare.kv_namespace_id')`, not through this wrangler binding. The real, narrower risk is simply that a staging Worker deploy doesn't work yet. There is no evidence in this repo of an actively-deployed Cloudflare Worker staging environment today (CLAUDE.md's environment table lists only `production`/`development` Laravel Cloud targets), so this is a known, tracked, not-yet-actioned gap rather than a live one.
    - **Plain English:** The staging environment's routing-table setting is still a placeholder — "put the real ID here later." If anyone deploys the router Worker to staging today, it simply won't route correctly (it fails safe, it doesn't corrupt anything live). It's already flagged in the file with the exact commands to fix it; it just needs someone to run them.
    - **Evidence:**
        ```toml
        # cloudflare-worker/wrangler.toml:42-53
        # EDGE-10: staging MUST NOT share the production SUBDOMAIN_KV — without this
        # override a `--env staging` deploy (or a staging backend KV backfill) would
        # write into the production routing table and poison prod. Give staging its own
        # namespace. TODO(josh): create it and paste the id below —
        #   wrangler kv namespace create SUBDOMAIN_KV_STAGING
        #   wrangler kv namespace create SUBDOMAIN_KV_STAGING --preview
        # then point the STAGING Laravel env's CLOUDFLARE_KV_NAMESPACE_ID at the same id
        # so SyncSubdomainToKvJob writes to the staging namespace, not prod.
        [[env.staging.kv_namespaces]]
        binding = "SUBDOMAIN_KV"
        id = "REPLACE_WITH_STAGING_KV_NAMESPACE_ID"
        preview_id = "REPLACE_WITH_STAGING_KV_PREVIEW_ID"
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Worker header & cache-control hygiene:** #EDGE-3, #EDGE-4
    - **Why grouped:** Both are small, same-file (`cloudflare-worker/src/index.js`) header/`Cache-Control` hardening items with no shared code path but the same low risk and effort.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Config & deploy-doc hygiene:** #EDGE-2, #EDGE-5, #EDGE-6
    - **Why grouped:** All three are self-contained hygiene items (CI drift guard, stale docblock, staging namespace provisioning) across `wrangler.toml` / `CloudflarePurgeService` / `config/partna.php` with no shared code path, low effort, and no user-facing risk today.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#EDGE-1 — Custom-domain KV entry never retired on takedown** · P0, and the fix changes the single canonical writer to `SUBDOMAIN_KV` (`SyncSubdomainToKvJob`) — data-integrity-adjacent to the platform's routing table; requires its own plan + sign-off.
