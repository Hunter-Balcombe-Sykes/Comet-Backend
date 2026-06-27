Now I have what I need. Let me assess:

- **SUBKV-1**: The observer shows `RetireSubdomainFromKvJob` is **not dispatched** on handle rename — it was removed and replaced with `SyncSubdomainToKvJob` only. The race condition premise is false. **Drop.**
- **SUBKV-2**: `ProfessionalObserver::deleted()` only invalidates cache — no KV cleanup. `SyncSubdomainToKvJob` silently exits if user not found. Gap is real and verified.
- **SUBKV-3**: `writeAliasEntries()` only writes, never deletes stale KV entries. Verified in source.

`★ Insight ─────────────────────────────────────`
- The observer comment in `ProfessionalObserver` reveals a deliberate design shift: `RetireSubdomainFromKvJob` was removed from the handle-rename path because `SyncSubdomainToKvJob` now writes alias entries in-band. DeepSeek missed this by reading the two job files in isolation without checking who dispatches them.
- KV lifecycle management splits across three jobs in this codebase but only two dispatch paths (observer on update, nothing on delete) — the deletion gap is a classic "happy path was wired, unhappy path was forgotten" pattern.
`─────────────────────────────────────────────────`

# SUBDOMAIN_KV Write Paths, Single-Writer Invariant & Sync Job Idempotency Audit — 2026-05-24

**Branch:** development
**Lens:** SUBDOMAIN_KV write paths, single-writer invariant violations, KV/DB drift, sync job idempotency
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Observers/Professional/ProfessionalObserver.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SUBKV-1** · P1 — Professional soft-deletion leaves subdomain permanently live in Cloudflare KV
    - **Where:** app/Observers/Professional/ProfessionalObserver.php:51–61
    - **Affects:** Every user who deletes their account. Their `<handle>.partna.au` subdomain continues to resolve and serve their (now-deleted) public profile page indefinitely. If the handle is eventually reclaimed by a new user after the 30-day soft-delete window, the old KV entry will be overwritten by their first sync — but during the gap the dead profile is publicly reachable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `ProfessionalObserver::deleted()`, dispatch `RetireSubdomainFromKvJob::dispatch($professional->handle)` after cache invalidation, guarded by `if ($professional->handle)`.
        - Verify the import for `RetireSubdomainFromKvJob` is added at the top of the observer.
        - Add a corresponding test in `tests/Feature/Professional/ProfessionalObserverHandleChangeTest.php` asserting the retire job is pushed on soft-delete.
    - **Technical:** `ProfessionalObserver::deleted()` fires on soft-delete and only calls `$this->professionalCache->invalidateProfessional($professional)`. No KV cleanup job is dispatched. `SyncSubdomainToKvJob::handle()` early-exits (`if (! $pro || ! $pro->handle) { return; }`) when the user row is soft-deleted and `User::query()->find()` returns null — meaning a re-sync will never clean up the stale entry. `RetireSubdomainFromKvJob::handle()` is the correct tool: it calls `$kv->delete($this->handle)` unconditionally and has the `HasCloudflareRetryPolicy` retry/backoff trait wired. The handle is available on the model at `deleted` observer fire time (the row is soft-deleted, not dropped), so `$professional->handle` is safe to read.
    - **Plain English:** When a customer cancels their account, we close their database record but forget to take down the sign on their public-facing web page. The address (`theirname.partna.au`) stays live on Cloudflare's global network, and anyone who visits it will still see their old profile — even though the account is gone. We need to actively remove the Cloudflare entry at the moment of cancellation, the same way you'd call the phone company to disconnect a cancelled line.
    - **Evidence:**
        ```php
        public function deleted(User $professional): void
        {
            try {
                $this->professionalCache->invalidateProfessional($professional);
            } catch (\Throwable $e) {
                Log::warning('Professional cache invalidation failed on delete', $this->logContext(__METHOD__, [
                    'professional_id' => $professional->id,
                    'message' => $e->getMessage(),
                ]));
            }
        }
        ```

---

## P2 — Should fix

- [ ] **#SUBKV-2** · P2 — Alias DB row deletion does not remove the corresponding KV entry (permanent aliases never self-evict)
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php — `writeAliasEntries()` method
    - **Affects:** Users whose old handle aliases are removed from the database (e.g. by the `handles:prune-expired-aliases` command or an admin action) before their Cloudflare TTL fires. For aliases written with a non-null `expires_at`, the KV TTL will eventually evict the entry — but aliases written with `expires_at = null` (legacy/permanent aliases) have no TTL in KV and will persist indefinitely after their DB row is deleted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `handles:prune-expired-aliases` (or wherever alias rows are hard-deleted), dispatch `RetireSubdomainFromKvJob::dispatch($alias->handle)` for each deleted alias row before the row is removed.
        - Alternatively, add a model observer on the alias table's `deleted` event that dispatches the retire job — this covers all deletion paths (admin, prune sweep, etc.) automatically.
        - No changes needed to `SyncSubdomainToKvJob` — its read-only sweep of current aliases is correct by design.
    - **Technical:** `writeAliasEntries()` queries `site.professional_handle_aliases` for non-expired rows and writes KV entries for each. It has no mechanism to detect that a previously-written KV key now has no corresponding DB row (Cloudflare KV provides no list-and-diff API that would make this cheap). When an alias row is deleted from Postgres, the KV entry silently persists. Aliases written with a TTL (non-null `expires_at`) will self-evict when the TTL fires — the window is bounded by the original alias lifetime. Aliases written without a TTL (null `expires_at`, permanent) will never self-evict and constitute a permanent ghost entry that redirects traffic to whatever `target` was last written.
    - **Plain English:** Think of each alias as a forwarding rule at the post office. When we remove the rule from our records, we forget to also call the post office and cancel it. For rules that were set up with an expiry date, the post office will eventually stop forwarding on their own. But for rules with no expiry ("permanent forwarding"), the post office will keep forwarding mail forever — even though we no longer want them to. We need to explicitly cancel each rule when we remove it from our records.
    - **Evidence:**
        ```php
        private function writeAliasEntries(CloudflareKvService $kv, string $proId, string $current): void
        {
            $aliases = DB::table('site.professional_handle_aliases')
                ->where('professional_id', $proId)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->get();

            foreach ($aliases as $alias) {
                // ...
                $ttl = $alias->expires_at
                    ? max(60, (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false))
                    : null;

                $kv->put($handle, ['type' => 'alias', 'target' => $current], $ttl);
            }
            // No corresponding $kv->delete() for aliases that disappeared from DB.
        }
        ```
