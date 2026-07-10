# Semantic Correctness Audit — 2026-07-09

**Branch:** development
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (categories: real-method-wrong-contract, config/flag misuse, plausible-but-wrong magic values, logic contradicting intent, codebase-idiom drift)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User
- app/Services/Site
- app/Services/PublicSite
- app/Services/Cache
- app/Services/Accounts
- app/Services/Auth
- app/Services/FeatureFlags
- app/Support
- app/Contracts
- app/helpers.php
- app/Jobs
- app/Http/Controllers/Api/User
- app/Policies
- app/DTOs

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 4 complete

---

## P3 — Nice to have

- [ ] **#SEM-1** · P3 — `EnrichLinkCardJob` reads `partna.queues.scraping` without the fallback-default second argument used by every sibling job
    - **Where:** app/Jobs/Platforms/EnrichLinkCardJob.php:40
    - **Affects:** Operations only — no behavioural difference today (the key always resolves via `config/partna.php`'s own `env()` default).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change to `config('partna.queues.scraping', 'scraping')`, matching `InstagramConnectJob`, `MenuFetchJob`, `GoogleBusinessEnrichJob`, `DeleteMirroredMediaJob`, `AnalyzeConnectionWebsitesJob`, and `AnalyzePreviousWebsiteJob` (all confirmed in `app/Jobs/Platforms/` and `app/Jobs/Design/`).
    - **Technical:** `config/partna.php:1249` already defines `'scraping' => env('PARTNA_QUEUE_SCRAPING', 'scraping')`, so `config('partna.queues.scraping')` cannot return `null` today under any env-var state — the PHP-level default in the config file guarantees a string. The missing call-site fallback therefore has zero effect on current behaviour; it only diverges from every sibling job's defensive style and would only matter if a future edit to `config/partna.php` removed the array key entirely (at which point this job alone would silently fall onto the `default` queue while its siblings would still resolve to `'scraping'`). Re-tiered from DeepSeek's P2 to P3 per the "harmless-today, breaks only under a plausible future change" anchor — the config file, not the env, is the actual backstop, and that backstop only fails if someone edits it.
    - **Plain English:** Six near-identical delivery jobs all write down a backup address in case their main instructions go missing; this one doesn't. Today it makes zero difference because the master instruction sheet already has a default baked in — but if someone ever restructures that sheet, this is the one job that would quietly end up in the wrong queue while its five siblings wouldn't.
    - **Evidence:**
        ```php
        // EnrichLinkCardJob — no fallback
        $this->onQueue(config('partna.queues.scraping'));
        ```
        ```php
        // InstagramConnectJob (sibling) — has fallback
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
        ```

- [ ] **#SEM-2** · P3 — `RefreshConnectionJob` reads `partna.queues.platform_refresh` without the fallback-default second argument
    - **Where:** app/Jobs/Platforms/RefreshConnectionJob.php:53
    - **Affects:** Operations only — no behavioural difference today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change to `config('partna.queues.platform_refresh', 'platform_refresh')`, matching the project-wide `config('partna.queues.X', 'name')` convention.
    - **Technical:** Same root cause as SEM-1: `config/partna.php:1251` defines `'platform_refresh' => env('PARTNA_QUEUE_PLATFORM_REFRESH', 'platform_refresh')`, so the value is guaranteed non-null today. There is no sibling job on this exact queue to compare against, but the project-wide pattern (verified across 15+ jobs) always supplies the second argument. Tiered P3, matching SEM-1's reasoning — same root cause, same tier.
    - **Plain English:** Same situation as the link-card job above: this job's backup address is blank, but the master sheet already has a default written in, so nothing breaks today. It only becomes a real problem if that master default is ever removed.
    - **Evidence:**
        ```php
        // RefreshConnectionJob — no fallback
        $this->onQueue(config('partna.queues.platform_refresh'));
        ```

- [ ] **#SEM-3** · P3 — `SendEnquiryNotificationJob` hardcodes the queue name literal instead of reading `partna.queues.notifications` like its sibling
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:44
    - **Affects:** Operations only — the literal `'notifications'` matches the config default exactly, so today's behaviour is identical either way.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->onQueue('notifications');` with `$this->onQueue(config('partna.queues.notifications', 'notifications'));`, matching `SendEnquiryConfirmationJob` (same directory) and every other notification job (`SendSubscriptionConfirmationJob`, `SendFeedbackEmailJob`, `SendAccountDeletionRequestMailJob`, `SyncCustomerMarketingOptInJob`, `SendStaffBroadcastEmailsJob`, `SendTransactionalNotificationEmailJob`).
    - **Technical:** `config/partna.php:1236` defines `'notifications' => env('PARTNA_QUEUE_NOTIFICATIONS', 'notifications')` and every other job in `app/Jobs/Notifications/` reads through that key. `SendEnquiryNotificationJob` alone bypasses config entirely with a hardcoded string literal, so an operator setting `PARTNA_QUEUE_NOTIFICATIONS` to reroute notification traffic (e.g. during an incident, to isolate a noisy queue) would silently strand this one job on the old lane while every sibling moves. Same root cause as SEM-1/SEM-2 (queue routing not uniformly config-driven), so tiered identically at P3 rather than DeepSeek's original P3 label kept.
    - **Plain English:** Every enquiry-related email job reads its delivery lane from a central settings sheet — except this one, which has the lane name permanently written in marker. If ops ever needs to redirect notification traffic during an incident, this job is the one piece of mail that won't follow.
    - **Evidence:**
        ```php
        // SendEnquiryNotificationJob — hardcoded
        $this->onQueue('notifications');
        ```
        ```php
        // SendEnquiryConfirmationJob — config-driven (sibling, same directory)
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
        ```

- [ ] **#SEM-4** · P3 — `ExportUserDataJob` reads `partna.gdpr.queue` without the fallback-default second argument
    - **Where:** app/Jobs/Gdpr/ExportUserDataJob.php:33
    - **Affects:** Operations only — no behavioural difference today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change to `config('partna.gdpr.queue', 'gdpr')` for consistency with the rest of the job corpus.
    - **Technical:** `config/partna.php:1466` defines `'queue' => env('PARTNA_GDPR_QUEUE', env('GDPR_QUEUE', 'gdpr'))`, so `config('partna.gdpr.queue')` is guaranteed non-null today regardless of env state — same structural guarantee as SEM-1/SEM-2. DeepSeek tiered this P2 on the theory that a missing key would strand a 600s export job on the `default` queue's shorter supervisor timeout; that scenario requires editing `config/partna.php` itself (not just an env misconfiguration), which is the same "future code change" precondition as the other three findings in this cluster. Re-tiered to P3 for consistency — same root cause as SEM-1–3, same tier.
    - **Plain English:** Same pattern once more: this GDPR export job's backup lane is blank, but the master settings sheet already guarantees a default, so nothing breaks today. Grouping it with the other three below since it's the exact same fix applied to a fourth file.
    - **Evidence:**
        ```php
        // ExportUserDataJob — no fallback
        $this->onQueue(config('partna.gdpr.queue'));
        ```
        ```php
        // WarmPublicSiteCacheJob — typical project pattern (has fallback)
        $this->onQueue(config('partna.queues.cache_warm', 'cache-warm'));
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Queue-routing config consistency:** #SEM-1, #SEM-2, #SEM-3, #SEM-4
    - **Why grouped:** identical root cause (queue-name resolution not uniformly config-driven-with-fallback across the Jobs corpus) and identical one-line mechanical fix in each file; no shared file but trivially reviewable as a single pass.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). Given four S-effort one-line changes, combine plan+implement in a single Sonnet pass — no Opus escalation warranted.

## Standalone — do NOT bundle

None.
