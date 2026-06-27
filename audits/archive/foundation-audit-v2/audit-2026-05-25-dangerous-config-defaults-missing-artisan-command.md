Good. Key findings confirmed:
- `EnvCheckService::RECOMMENDED` covers `services.resend.key` / `mail.from.*` but **not** `mail.default` — CONF-1 claim verified
- `AppServiceProvider::boot()` has a production guard for `SUPABASE_EMAIL_HOOK_SECRET` but nothing for the `log` mailer fallback
- `BackfillSocialLinksCommand` has zero `withoutEvents()` calls — CASC-1 cascade confirmed

`★ Insight ─────────────────────────────────────`
Three architectural patterns intersect here worth understanding:
1. **Config defaults as silent failure modes** — Laravel's `env('KEY', 'fallback')` pattern means any missing env var silently activates the fallback. The right defense is runtime assertions at `AppServiceProvider::boot()` for production-unsafe defaults, not just documentation.
2. **Observer cascade amplification** — `$afterCommit = true` on observers means a chunk transaction commit fires N × observer chains in sequence. One-shot maintenance commands are the most common place this explodes unexpectedly because they're rarely tested at production data volume.
3. **`Cache::forever()` in health-check listeners** — an unbounded write pattern in a path that runs every scheduler tick. The correct pattern is a long-but-finite TTL that naturally expires orphaned state.
`─────────────────────────────────────────────────`

Now adjudicating and emitting the final audit:

---

# Config, Listener, and Flag Safety Audit — 2026-05-25

**Branch:** development
**Lens:** dangerous config defaults, missing artisan command authz, listener cascade bugs, feature-flag determinism, diagnostic info leakage
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- config/mail.php
- config/app.php
- config/cache.php
- config/partna.php
- app/Services/Diagnostics/EnvCheckService.php
- app/Providers/AppServiceProvider.php (via Grep)
- app/Console/Commands/BackfillSocialLinksCommand.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/SiteObserver.php
- app/Listeners/RecordScheduledTaskHeartbeat.php
- app/Console/Commands/CacheStats.php
- app/Services/FeatureFlags/FeatureFlagService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#CONF-1** · P1 — `MAIL_MAILER` defaults to `log`, silently discarding all production email
    - **Where:** config/mail.php:21 · app/Services/Diagnostics/EnvCheckService.php:79–83
    - **Affects:** Every outbound transactional email — handle expiry notices, `HandleAliasExpiringMail`, any future notification email dispatches. If `MAIL_MAILER` is absent from the production environment, every `Mail::queue()` / `Mail::to()->send()` call silently succeeds (no exception, no bounce, no queue failure) while writing to a log file that is not monitored for mail content.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a production boot guard in `AppServiceProvider::boot()` that throws if `config('mail.default') === 'log'` and `app()->isProduction()`. Pattern already exists for `SUPABASE_EMAIL_HOOK_SECRET` at line 130.
        - Add `'mail.default' => 'MAIL_MAILER'` to `EnvCheckService::RECOMMENDED` under the `'Mail'` group. The key belongs in RECOMMENDED (not REQUIRED) because `log` is deliberately correct for local dev; the pre-flight check's purpose is to catch prod deploys where the env var was forgotten.
        - Verify `MAIL_MAILER` is set in both the `development` and `production` Laravel Cloud environments.
    - **Technical:** `config/mail.php` resolves `'default' => env('MAIL_MAILER', 'log')`. The `log` transport implements `TransportInterface` and returns success on every send, so the mail driver stack reports zero errors. `EnvCheckService::RECOMMENDED` (the single source of truth for the `env:check` command and `/api/internal/env-check` endpoint) covers `services.resend.key`, `mail.from.address`, and `mail.from.name` but not `mail.default`, meaning a pre-deploy env-check passes clean even with `MAIL_MAILER` unset. `AppServiceProvider::boot()` already enforces a hard production boot failure for the analogous Supabase hook secret; the same pattern applied to the mail transport catches the gap at deploy time rather than at incident time.
    - **Plain English:** Your business sends important emails — notifications that a user's old web address is about to be released, plus any future automated emails. There's a master switch called `MAIL_MAILER` that decides where those emails go. If that switch is missing from the production settings, every email quietly gets filed away in an internal log instead of being sent. Nobody gets an alert. The system reports everything worked fine. The pre-launch checklist the team runs before going live doesn't flag the missing switch. One forgotten setting on launch day means zero customer emails go out — silently, indefinitely.
    - **Evidence:**
        ```php
        // config/mail.php:21
        'default' => env('MAIL_MAILER', 'log'),
        ```
        ```php
        // app/Services/Diagnostics/EnvCheckService.php:79-83
        // Mail section in RECOMMENDED — mail.default is absent
        'Mail' => [
            'services.resend.key' => 'RESEND_API_KEY',
            'mail.from.address' => 'MAIL_FROM_ADDRESS',
            'mail.from.name' => 'MAIL_FROM_NAME',
        ],
        ```
        ```php
        // app/Providers/AppServiceProvider.php — existing pattern to follow
        if (app()->isProduction() && empty(config('services.supabase.email_hook_secret'))) {
            throw new \RuntimeException('SUPABASE_EMAIL_HOOK_SECRET must be configured in production (auth email hook fails closed without it).');
        ```

---

## P2 — Should fix

- [ ] **#CASC-1** · P2 — `BackfillSocialLinksCommand` triggers full observer cascade on every block save
    - **Where:** app/Console/Commands/BackfillSocialLinksCommand.php (per-block `$block->save()` inside `chunkById` transaction) · app/Observers/Core/BlockObserver.php:38–59 · app/Observers/Core/SiteObserver.php
    - **Affects:** Cloudflare API rate limits, Redis queue depth, and database load during any backfill run. Each `$block->save()` fires `BlockObserver::onBlockMutated` → `$site->touch()` → `SiteObserver::saved` → one `CloudflareCachePurgeJob` + one `WarmPublicSiteCacheJob` per block. Many blocks share the same site, so a 10,000-block backfill can enqueue 20,000+ jobs and issue hundreds of redundant Cloudflare purge API calls for the same zones — well above Cloudflare's per-zone rate cap on most plans.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the per-chunk block writes with `Block::withoutEvents(fn () => $block->save())` so the observer cascade is suppressed during the bulk write.
        - After all chunks complete, collect distinct `site_id` values from the processed blocks and touch each site exactly once. This fires `SiteObserver::saved` once per site instead of once per block.
        - Add a `--skip-cache-invalidation` flag (or make this the default for the command) since backfilling tag metadata is a background operation that doesn't require a live cache purge.
    - **Technical:** `BlockObserver` sets `$afterCommit = true`, so each `$block->save()` within the `DB::transaction()` chunk defers its cascade until the transaction commits — then fires once per block in the chunk, not per-transaction. `SiteObserver::saved` unconditionally dispatches `CloudflareCachePurgeJob` and (when `$site->is_published`) `WarmPublicSiteCacheJob`. Multiple blocks belonging to the same site each independently touch that site, causing the purge and warm jobs to be dispatched N times for the same zone per chunk. `WarmPublicSiteCacheJob` performs DB reads and Redis writes; at 200 blocks/chunk with even 10 sites, this is 20 redundant warm jobs per chunk commit. The fix is `Model::withoutEvents()` for the bulk path, with a single deduplicated site-touch pass at the end.
    - **Plain English:** Imagine a maintenance worker relabeling 10,000 items in a warehouse. Each time they relabel a single item, the system automatically sends a crew to repave the entire loading dock and reorganise all the shelves — even when 500 of those items are on the same shelf. This backfill command does the equivalent: every link block it updates triggers a full cache rebuild and a request to Cloudflare to refresh the public website, even when a hundred blocks belong to the same site. At scale, this floods the job queue and can hit service limits with Cloudflare before the backfill finishes.
    - **Evidence:**
        ```php
        // BackfillSocialLinksCommand.php — per-block save inside chunk transaction
        if (! $dryRun) {
            $block->settings = $settings;
            $block->save();
        }
        ```
        ```php
        // BlockObserver.php — every mutation touches the parent site
        private function onBlockMutated(Block $block, string $action): void
        {
            if (! $block->site) {
                return;
            }
            try {
                $block->site->touch();
            } catch (\Throwable $e) { /* ... */ }
        }
        ```
        ```php
        // SiteObserver.php — site touch dispatches CF purge + cache warm per site
        CloudflareCachePurgeJob::dispatch($handle)->afterCommit();
        // ...
        WarmPublicSiteCacheJob::dispatch(strtolower($site->subdomain))->afterCommit();
        ```

---

## P3 — Nice to have

- [ ] **#CONF-2** · P3 — `RecordScheduledTaskHeartbeat` stores keys with `Cache::forever()`, no cleanup path
    - **Where:** app/Listeners/RecordScheduledTaskHeartbeat.php:23
    - **Affects:** Redis memory on the cache store; the `all()` method on the scheduler health endpoint. When scheduled tasks are renamed or removed, their `scheduler:last_run:{taskKey}` keys persist in Redis indefinitely. Note: `RecordCacheMetrics` already skips the `scheduler:` prefix via `SKIP_PREFIXES`, so these orphaned keys don't pollute cache hit-rate metrics — the impact is limited to memory and health endpoint iteration.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::forever()` with `Cache::put(..., now()->addDays(90))`. A 90-day TTL covers all realistic cron schedules (the slowest Partna task is weekly) while naturally expiring orphaned keys from removed tasks.
        - No migration of existing keys is needed — they'll expire on their own if the scheduler runs normally, or can be manually flushed once.
    - **Technical:** `Cache::forever()` stores the key with no Redis TTL (raw `SET` without `EX`). Redis retains it until explicit deletion or a full flush. At a handful of scheduled tasks, this is trivially small in absolute terms, but the accumulation is unbounded as tasks are refactored over years. A 90-day TTL guarantees every key is automatically evicted after three months of inactivity, which is a reasonable signal that the task has been removed. The change is a one-liner with no correctness risk — the heartbeat value is always overwritten on the next run.
    - **Plain English:** Every scheduled task in the system posts a "last ran at X" sticky note to a shared board. When you retire a task, nobody takes its sticky note down — it stays on the board forever. In practice the board is small (a few dozen notes at most) so it doesn't hurt today, but a simple fix makes old notes automatically fall off after three months, keeping the board clean without any manual housekeeping.
    - **Evidence:**
        ```php
        public function handle(ScheduledTaskStarting $event): void
        {
            $key = self::taskKey($event->task);
            Cache::forever(self::CACHE_PREFIX.$key, now()->toIso8601String());
        }
        ```
