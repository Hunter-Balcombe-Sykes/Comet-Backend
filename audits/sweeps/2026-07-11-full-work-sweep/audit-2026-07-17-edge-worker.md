# Edge Worker Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Edge worker: Cloudflare routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- cloudflare-worker/src/index.js
- cloudflare-worker/wrangler.toml
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Moderation/SuspendSiteJob.php
- app/Jobs/Moderation/PurgeModerationCacheJob.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Moderation/ModerationActionDispatcher.php
- app/Observers/User/UserObserver.php
- app/Observers/Core/SiteObserver.php
- app/Services/User/AccountDeletionService.php
- app/Services/Site/RenameSubdomainAction.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- config/partna.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## Note on this adjudication

DeepSeek's draft was scanned against only three files (`SyncSubdomainToKvJob.php`, `CloudflareCustomHostnameService.php`, `CloudflarePurgeService.php`) — it did not have `cloudflare-worker/src/index.js`, the model observers, `AccountDeletionService`, or the moderation dispatcher in scope, and its own EDGE-7 draft admits this outright. With the full picture, five of its seven findings (its EDGE-1 through EDGE-6, minus EDGE-5) turned out to be **false premises**: the takedown/purge chain it says is missing already exists, just in files it didn't see —

- `SiteObserver::saved()`/`::deleted()` dispatch `CloudflareCachePurgeJob` (with custom domain) on every site save/delete/unpublish, including the account-deletion `is_published=false` transition in `AccountDeletionService::executeConfirmation()`.
- Moderation takedowns (`hide_site`, `suspend_user`, `ban_user`, `csam_auto_suspend`) all pair `suspend_site` with `sync_subdomain_kv` in `ModerationActionDispatcher::ACTIONS_BY_DECISION`, and `sync_subdomain_kv` maps to `PurgeModerationCacheJob`, which retires the KV entry **and** unconditionally dispatches `CloudflareCachePurgeJob` — confirmed by `ModerationActionDispatcherTest`.
- The Worker's alias-redirect branch already validates `entry.redirect` is an `https://*.partna.au` URL before trusting it (comment cites `SEC-5`), failing closed to a 404 otherwise — the "poisoned KV → open redirect" claim doesn't hold against the current source.
- The Worker's `RESERVED` set and `config('partna.reserved_subdomains')` are byte-for-byte identical (diffed both files in full) — no drift.
- The Worker's KV-type check runs **before** any cache lookup, so once a renamed handle's KV entry flips to `{type:"alias"}`, its old cache entries become structurally unreachable — there's no window where stale content under the old handle is served to a visitor (rename correctness holds; only a sub-second-to-KV-propagation race exists, and it would serve identical content from the same owner, not stale/wrong content).

These are dropped below rather than re-tiered, since the underlying claim — not just the fix — is what's wrong. Two of DeepSeek's findings (custom-hostname delete, product-purge cap) verified true and are kept. Three genuinely new findings the draft's narrow scope missed are added.

## P2 — Should fix

- [ ] **#EDGE-1** · P2 — `CloudflareCustomHostnameService::delete()` silently swallows Cloudflare API failures its own caller already expects it to throw
    - **Where:** app/Services/Cloudflare/CloudflareCustomHostnameService.php:91-98
    - **Affects:** Cloudflare for SaaS zone hygiene — users disconnecting or replacing a custom domain during a token expiry, rate limit, or transient 5xx.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->throw()` to the HTTP call in `delete()`, matching `create()`/`get()` in the same class.
        - No caller change needed — `CustomDomainController` already wraps every `$this->cf->delete(...)` call in `try { ... } catch (Throwable $e) { report($e); }`; this fix makes that existing handling actually fire on a real Cloudflare failure instead of being dead code for the HTTP-error case.
    - **Technical:** `create()` and `get()` both chain `->throw()`, converting a non-2xx response into an exception. `delete()` fires the DELETE and returns void unconditionally — it cannot surface an HTTP-level failure. `CustomDomainController::store()` (domain-change teardown, line 61; TOCTOU cleanup, line 92) and `::destroy()` (line 172) all wrap the call in try/catch + `report($e)`, which only proves the caller was written expecting `delete()` to be able to throw. Because it silently swallows 401/429/5xx internally, those catch blocks never fire for the most likely failure mode, and Cloudflare retains a custom hostname that Partna's DB/KV routing table consider gone — an invisible zone-hygiene leak.
    - **Plain English:** When a user disconnects or changes their custom domain, the app tells Cloudflare "remove this domain registration." The code that calls this is already written to catch and report a failure if one happens — but the piece that actually talks to Cloudflare never raises an alarm when the request fails, so that safety net never triggers. The domain stops working for visitors (routing is removed elsewhere), but it silently stays registered on Cloudflare's side, slowly piling up ghost entries with nobody notified.
    - **Evidence:**
        ```php
        /** Delete a custom hostname (best-effort — a missing id is a no-op). */
        public function delete(string $id): void
        {
            if (! $this->configured || $id === '') {
                return;
            }

            Http::withToken($this->apiToken)->timeout(5)->delete($this->base()."/{$id}");
        }
        ```
        ```php
        if ($site->custom_domain_cf_id) {
            try {
                $this->cf->delete($site->custom_domain_cf_id);
            } catch (Throwable $e) {
                report($e);
            }
        }
        ```

- [ ] **#EDGE-2** · P2 — Cloudflare Worker `staging` environment KV namespace is an unresolved placeholder
    - **Where:** cloudflare-worker/wrangler.toml:42-53
    - **Affects:** Any future `wrangler deploy --env staging`; the prod-poisoning failure mode the file's own comment describes, if this override is ever removed or misapplied without the placeholder being noticed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Provision the real staging KV namespace (`wrangler kv namespace create SUBDOMAIN_KV_STAGING` + `--preview`) and paste the IDs in, wiring the staging Laravel env's `CLOUDFLARE_KV_NAMESPACE_ID` to match, per the TODO already written in the file — **or**, since CLAUDE.md's environment table documents only `development`/`production` with no Cloudflare "staging" tier, delete the `[env.staging]` block entirely.
        - Either way, resolve the TODO rather than leaving `REPLACE_WITH_...` placeholders sitting in a deployable config file.
    - **Technical:** The default (production) block's `SUBDOMAIN_KV` binding points at a real namespace id. The `[env.staging]` override still carries literal placeholder strings. Today a `--env staging` deploy fails at Cloudflare's API validation rather than silently sharing the production namespace, so the specific risk the comment warns about isn't live — but it's a dangling, unfinished safety mechanism with no tracking beyond an inline TODO, in a repo whose documented environment model doesn't otherwise mention a Cloudflare staging tier at all.
    - **Plain English:** There's a "fill this in later" placeholder in the Cloudflare configuration for a staging environment that doesn't appear to be used anywhere else in the project. Right now trying to deploy to it would just fail outright — but a half-finished safety mechanism sitting in a config file is a trap for whoever touches it next, assuming it's live and correct when it isn't. It should be finished or removed.
    - **Evidence:**
        ```toml
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

- [ ] **#EDGE-3** · P2 — No structural guard ties `suspend_site` to a KV/cache-retirement action in `ModerationActionDispatcher`
    - **Where:** app/Services/Moderation/ModerationActionDispatcher.php:26-44 (`ACTIONS_BY_DECISION`) + app/Jobs/Moderation/SuspendSiteJob.php:52-57
    - **Affects:** Any future moderation decision type added to `ACTIONS_BY_DECISION` that hides a site — the highest-stakes category of this lens (takedown correctness).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an architecture-style test (same pattern as `PolicyCoverageTest`'s allowlist sweep) asserting: for every entry in `ACTIONS_BY_DECISION` containing `'suspend_site'`, the same entry also contains `'sync_subdomain_kv'` or `'purge_cloudflare_cache'`.
        - Optionally collapse the two into one action type so the pairing can't be expressed incorrectly at the source.
    - **Technical:** `SuspendSiteJob::handle()` hides a site via `Site::query()->where('id', $siteId)->update(['moderation_state' => 'hidden'])` — a query-builder mass update, which does **not** fire Eloquent model events, so `SiteObserver::saved()` (and its `CloudflareCachePurgeJob` dispatch) never runs for this write. The system correctly compensates today: every decision type carrying `'suspend_site'` (`hide_site`, `suspend_user`, `ban_user`, `csam_auto_suspend`) also carries `'sync_subdomain_kv'`, which `ModerationActionDispatcher::dispatchJob()` maps to `PurgeModerationCacheJob` — the job that retires the KV entry and unconditionally dispatches `CloudflareCachePurgeJob`. `ModerationActionDispatcherTest` verifies this pairing for all four existing decision types, but only by hand-written per-type assertions — there is no general invariant test across the whole map. A future decision type that hides a site and forgets to pair `sync_subdomain_kv`/`purge_cloudflare_cache` would flip the DB flag while leaving both the KV routing entry and the edge cache fully live — a silent P0-class regression with nothing in CI to catch it before ship.
    - **Plain English:** Hiding a moderated site currently works because two separate steps always happen together: one flips a database flag, the other clears the CDN cache and routing table. Nothing in the code actually *forces* those two steps to travel together — they just currently do, by careful hand-authored configuration. If someone adds a new kind of moderation action later and only remembers the database flag, the offending page would keep being served from cache with nothing failing to warn them. A simple automated check — "any action that hides a site must always also clear the cache" — would catch that mistake before it ships instead of relying on someone remembering.
    - **Evidence:**
        ```php
        private const ACTIONS_BY_DECISION = [
            'hide_content' => ['notify_reported_user', 'purge_cloudflare_cache'],
            'hide_site' => ['suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
            'suspend_user' => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
            'ban_user' => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
            'csam_auto_suspend' => ['quarantine_media', 'suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_oncall_staff'],
            ...
        ];
        ```
        ```php
        $siteId = $this->resolveSiteId($case);
        if ($siteId !== null) {
            Site::query()
                ->where('id', $siteId)
                ->update(['moderation_state' => 'hidden']);
        }
        ```

## P3 — Nice to have

- [ ] **#EDGE-4** · P3 — `cloudflare-worker` router has zero automated test coverage
    - **Where:** cloudflare-worker/ (no test directory alongside `src/index.js`, `wrangler.toml`, `package.json`)
    - **Affects:** Every future change to `index.js` — routing, cache-key logic, alias-redirect validation, security headers — ships with no regression check.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a minimal Miniflare/Vitest smoke suite covering: reserved-subdomain passthrough, KV-miss branded-404, alias redirect (valid target + poisoned-target-rejected-to-404), individual-entry cache hit/miss/stale-shadow paths, and the HTTP→HTTPS redirect.
        - Wire it into CI alongside `composer test` so a Worker change can't silently regress protections that took multiple prior audit rounds to land (the file's own comments cite `EDGE-1/7/8/9/12/13, SEC-5` as previously-fixed findings).
    - **Technical:** `cloudflare-worker/` contains only `src/index.js`, `wrangler.toml`, `package.json`/`package-lock.json`, and a README — no test directory, no Miniflare/Vitest harness. This is the only non-PHP runtime in the repo and fronts 100% of public sitepage traffic, including several security-sensitive branches (alias-redirect host validation, Set-Cookie stripping, query-string-stripped cache keys) that this and prior audit rounds have had to re-verify by hand-reading the source each time. A regression in any of these ships with zero CI signal.
    - **Plain English:** This is the one piece of the whole backend written in a different programming language, and it decides how every visitor's request gets routed and cached — yet nothing automatically re-checks it when it changes. Every other part of the codebase has an automated test suite that runs before code ships; this critical piece relies entirely on a human re-reading the whole file during periodic audits. A small automated test suite would catch an accidental regression immediately instead of it needing to be rediscovered by hand at the next audit.
    - **Evidence:**
        ```
        cloudflare-worker/.gitignore
        cloudflare-worker/README.md
        cloudflare-worker/package-lock.json
        cloudflare-worker/package.json
        cloudflare-worker/src/index.js
        cloudflare-worker/wrangler.toml
        ```

- [ ] **#EDGE-5** · P3 — Product detail page purge is capped at 100 products with no visibility when the cap is hit
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:132-146
    - **Affects:** Individual professionals with more than 100 shop products connected via a platform integration — product pages 101+ stay edge-cached for up to 24h primary / 7d shadow after any purge-triggering mutation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Raise the limit, or at minimum `Log::warning` when the returned row count equals the limit, so it's visible before a real professional's catalog silently exceeds it.
    - **Technical:** The product-handle query added by the recent product-detail-page purge fix (`7c753f7f fix(cache): purgeHandle also purges shop product detail pages`) caps at `->limit(100)` as a safety bound. For the current individual-only, pre-beta platform, 100+ products is very unlikely, but there is no signal today if it's ever exceeded.
    - **Plain English:** The system clears cached copies of a store's product pages when the site updates, but it only looks at the first 100 products. For a typical individual professional's catalog this is fine, but if someone ever has more, the rest would show stale info for up to a day with nobody told it happened.
    - **Evidence:**
        ```php
        $productHandles = DB::connection('pgsql')->table('site.shop_products as p')
            ->join('site.shop_brands as b', 'b.id', '=', 'p.brand_id')
            ->join('site.platform_connections as c', 'c.id', '=', 'b.connection_id')
            ->join('core.users as u', 'u.id', '=', 'c.user_id')
            ->where('u.handle_lc', $h)
            ->whereNull('c.deleted_at')
            ->whereRaw("p.data->>'handle' IS NOT NULL")
            ->selectRaw("DISTINCT p.data->>'handle' AS product_handle")
            ->limit(100)
            ->pluck('product_handle')
            ->all();
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Cloudflare service hygiene (PHP):** #EDGE-1, #EDGE-5
    - **Why grouped:** both are small, same-directory (`app/Services/Cloudflare/`) one-liners with no cross-file risk.
    - **Model:** Plan+Implement: Sonnet (S/S effort, combine per policy) · Review: Sonnet.

- **Bundle 2 — Cloudflare Worker repo hygiene (deploy config + tests):** #EDGE-2, #EDGE-4
    - **Why grouped:** both are `cloudflare-worker/` repo-hygiene items (deploy config completeness, test scaffolding) rather than application-logic bugs.
    - **Model:** Plan: Opus (EDGE-4 needs a scaffolding decision) · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Moderation purge invariant test:** #EDGE-3
    - **Why grouped:** standalone-scoped but S-effort — a single new architecture test, no production code change.
    - **Model:** Plan+Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
