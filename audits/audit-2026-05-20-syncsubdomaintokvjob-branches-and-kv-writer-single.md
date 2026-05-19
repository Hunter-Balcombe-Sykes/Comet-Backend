Evidence confirmed. Drafts SYNC-1 and SYNC-2 are hallucinated — the real file already implements `ShouldBeUnique` with a 45s uniqueness window, and there is no `delete()` call anywhere in this job (that's `RetireSubdomainFromKvJob`'s responsibility). SYNC-3's evidence is verbatim-correct. The TTL-is-silently-dropped cross-file bug is a real P1 DeepSeek missed entirely.

`★ Insight ─────────────────────────────────────`
PHP silently accepts extra function arguments beyond a method's declared parameter list — `put($key, $value, $ttl)` won't throw even though `put()` only declares two parameters. This makes the bug invisible to static analysis unless you have strict call-signature checking enabled (e.g. PHPStan level 6+). It's a common source of "I was sure I was passing a TTL" bugs in PHP codebases.
`─────────────────────────────────────────────────`

# SyncSubdomainToKv — KV Writer Single-Writer Rule Integrity Audit — 2026-05-20

**Branch:** feat/28-6-sync-subdomain-kv-individual-branch
**Lens:** SyncSubdomainToKvJob branches and KV writer single-writer rule integrity
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Services/Cloudflare/CloudflareKvService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#SYNC-1** · P1 — Alias TTL silently dropped; all handle aliases become permanent in Cloudflare KV
    - **Where:** app/Services/Cloudflare/CloudflareKvService.php:36 / app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:124
    - **Affects:** All professionals who rename their handle — old aliases are supposed to auto-expire at the Cloudflare edge after `expires_at` but instead live forever, allowing stale routing entries to accumulate and potentially misroute requests long after the alias window closes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an optional `?int $expirationTtl` parameter to `CloudflareKvService::put()`.
        - When non-null, append `?expiration_ttl={$expirationTtl}` to the Cloudflare KV REST URL before the `Http::put()` call (Cloudflare KV accepts per-write TTL via query param).
        - Update all three canonical `$kv->put($current, [...], null)` call sites in `SyncSubdomainToKvJob::handle()` — the `null` already being passed will now be correctly interpreted as "no TTL" once the parameter is declared.
        - The alias call site at line 124 (`$kv->put($handle, [...], $ttl)`) already computes the correct TTL; it will work as soon as the service accepts the parameter.
    - **Technical:** `CloudflareKvService::put()` declares two parameters (`string $key`, `array $value`). Every call site in `SyncSubdomainToKvJob` passes three arguments, including a TTL value (`null` for canonical entries, a computed `$ttl` for alias entries). PHP silently discards the extra argument at runtime — no error, no warning. The Cloudflare KV API supports per-key TTL via the `expiration_ttl` query parameter on the PUT call; without it, every written key is permanent. The alias-entry path at line 100–124 explicitly computes `max(60, diffInSeconds(expires_at))` and even guards against sub-minimum TTLs, but the computed value is thrown away before the HTTP call is made. The job comment ("CF KV auto-evicts when the alias expires") documents the intended contract — the contract is simply not enforced. The `handles:prune-expired-aliases` command provides a backend sweep, but the edge cache bypass window between alias expiry and the next prune run means stale routing can persist.
    - **Plain English:** When someone renames their profile link, the old address is kept alive for a limited time (like a mail forwarding service). After that time the old address is supposed to quietly stop working on its own. Right now the system tries to set that expiry time but accidentally throws it away before sending the instruction to Cloudflare — so every old address lives forever until someone manually cleans it up. The fix is a one-line change to pass that expiry time through to Cloudflare so the edge cache cleans itself up automatically, as designed.
    - **Evidence:**
        ```php
        // CloudflareKvService::put — only accepts two parameters; TTL is unreachable
        public function put(string $key, array $value): void
        {
            // ...
            Http::withToken($this->apiToken)
                ->withBody((string) json_encode($value), 'text/plain')
                ->put($this->url($key))   // no ?expiration_ttl appended
                ->throw();
        }

        // SyncSubdomainToKvJob::writeAliasEntries — computes TTL then passes it nowhere
        $ttl = $alias->expires_at
            ? max(60, (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false))
            : null;

        $kv->put($handle, ['type' => 'alias', 'target' => $current], $ttl); // $ttl silently dropped
        ```

---

## P2 — Should fix

- [ ] **#SYNC-2** · P2 — Affiliate redirect non-deterministic when two brand links share the same `slot` value
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:69–72
    - **Affects:** Partners affiliated with multiple brands where two links have an identical `slot` — the KV redirect target may vary between job runs, causing the affiliate's subdomain to flap between brand storefronts when any observer-triggered sync fires.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->orderBy('created_at')` as a secondary sort after `->orderBy('slot')` on the `BrandPartnerLink` query.
        - Document the canonical tie-break rule in the inline comment: lowest slot wins; equal slots resolved by earliest `created_at`.
    - **Technical:** The query selects the `site_url` of the `BrandPartnerLink` with the smallest `slot` value. PostgreSQL does not guarantee row order for ties, and the order can differ between connections, vacuums, or plan changes. Because `ShouldBeUnique` deduplicates to one job per 45s window, a burst of observer events produces one KV write — but if that write fires shortly after a slot-collision is introduced (e.g. a manual re-ordering), successive windows may resolve to different rows and produce unnecessary KV churn. Adding a stable secondary sort (`created_at`) eliminates the non-determinism at negligible query cost.
    - **Plain English:** If two brand partnerships are at the same priority level, the system has no rule for which one wins, so the database is free to return either one depending on how it feels that day. Usually this won't matter, but when the affiliate's settings are synced it might flip between two brand redirect destinations for no visible reason. Adding a simple "if equal priority, pick the older one" rule makes the choice stable and predictable.
    - **Evidence:**
        ```php
        $siteUrl = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $pro->id)
            ->whereNotNull('site_url')
            ->orderBy('slot')          // no secondary sort; ties are non-deterministic
            ->value('site_url');
        ```
