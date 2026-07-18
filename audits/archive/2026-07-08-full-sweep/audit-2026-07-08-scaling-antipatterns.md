# Scaling Antipatterns Audit — 2026-07-08

**Branch:** audit-fix/middleware-2026-07-06
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching — analytics v2 ingest, notification fan-out, and observer-triggered write multiplication
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Analytics/Ingestors/QueuedIngestor.php
- app/Services/Analytics/Ingestors/SyncIngestor.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/Analytics/AnalyticsQueryService.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Notifications/NotificationListingService.php
- app/Services/Notifications/EnquiryNotificationDispatcher.php
- app/Services/Notifications/Adapters/EmailEnquiryNotificationAdapter.php
- app/Services/Notifications/Adapters/InAppEnquiryNotificationAdapter.php
- app/Jobs/Notifications/*.php (all 9 files)
- app/Observers/Core/*.php, app/Observers/User/UserObserver.php (all 9 files)
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/UserCacheService.php
- app/Services/Cache/SiteCacheInvalidator.php
- app/Services/Cache/CacheKeyGenerator.php
- config/cache.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#CACHE-1** · P2 — `PostgresEventWriter::writeMany()` session-ping path issues one DB round-trip per event instead of a batch, latent until a batching ingestor lands
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php:67-69 (`writeMany` foreach) and `upsertSession()` at lines 239-274
    - **Affects:** Analytics ingest throughput — currently dormant. `QueuedIngestor::ingest()` dispatches exactly one `RecordAnalyticsEventJob` per HTTP event (`app/Http/Controllers/Api/PublicSite/AnalyticsController::ping`), and `RecordAnalyticsEventJob::handle()` calls `$writer->write($event)`, which is `writeMany([$event])` — so `$sessionEvents` in `writeMany()` never holds more than one item in the current architecture. This becomes a real burst-ingest cost only if/when a `BufferedIngestor` (documented as a future drop-in in `docs/superpowers/specs/2026-05-30-async-analytics-ingest-design.md`) starts batching multiple pings into one `writeMany()` call.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Collect all `TYPE_SESSION_PING` events in the batch and issue a single multi-row `INSERT ... ON CONFLICT (id) DO UPDATE ... WHERE site_sessions.site_id = EXCLUDED.site_id` statement with `GREATEST()`/`MAX()` merge logic, mirroring the driver-portability handling already in `upsertSession()`.
        - Preserve the existing origin-field first-write-wins semantics and the SQLite-vs-Postgres `GREATEST`/`MAX` branch.
        - No urgency to ship ahead of a batching ingestor landing — track alongside that work rather than as a standalone fix.
    - **Technical:** Category (2) write amplification. This exact finding was raised and tiered P2 in the archived 2026-06-13 sweep (`audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-scaling-antipatterns.md`) with the same "latent because QueuedIngestor dispatches one event per job" reasoning, and that reasoning still holds today — no caller has changed. The `visitRows`/`clickRows`/`sectionRows` arrays in the same method already use bulk `insertOrIgnore()`; only the session-ping path stayed a per-event loop because a single-row `ON CONFLICT ... WHERE` upsert with `GREATEST()` doesn't trivially generalize to a multi-row `VALUES` statement without care around the WHERE-guard-per-row semantics. Canonical replacement: batch the upsert into one multi-row statement so a future high-cardinality `writeMany()` call collapses N round-trips into one, consistent with the append-only/mutable-projection pattern the rest of the writer already follows.
    - **Plain English:** Right now, every visitor's "still on the page" heartbeat is saved to the database one at a time — but that's fine today because the system currently only ever saves one heartbeat per database trip anyway. The code has a loop that pretends to handle many heartbeats at once but doesn't actually get used that way yet. If a future change starts grouping many heartbeats together before saving (to make things faster), this loop would quietly defeat that grouping and go back to one-at-a-time saves under a traffic spike. Worth fixing so it's ready when that day comes, but nothing is at risk right now.
    - **Evidence:**
        ```php
        foreach ($sessionEvents as $event) {
            $this->upsertSession($event);
        }
        ```

- [ ] **#CACHE-2** · P2 — `NotificationPublisher::publishMany()` fans out one email job per recipient with no batching, currently unreachable (zero callers)
    - **Where:** app/Services/Notifications/NotificationPublisher.php:264-268
    - **Affects:** Any future caller of `publishMany()` for bulk in-app + email notification delivery. As of this audit, `publishMany()` has **no callers anywhere in `app/` or `tests/`** (verified via repo-wide grep) — the fan-out risk described here does not manifest today.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Before wiring any caller to `publishMany()`, replace the per-ID `SendTransactionalNotificationEmailJob::dispatch()` loop with the same `Bus::batch()` + `array_chunk()` + `allowFailures()` pattern `SendStaffBroadcastEmailsJob::handle()` already uses (chunk size from `config('partna.notifications.batch_chunk_size')`), so the two fan-out code paths in this codebase share one Redis-pipelining convention instead of diverging.
        - Alternatively, if a caller only ever needs to notify a small, bounded set (e.g. staff-initiated per-professional batches under ~50), document that bound explicitly in the docblock so a future large-audience caller doesn't inherit the eager-dispatch pattern by copy-paste.
    - **Technical:** Category (2) write amplification — structurally identical root cause to `SendStaffBroadcastEmailsJob`'s pre-fix pattern (that job's docblock explicitly calls out "one Redis pipeline write per batch vs. one per job if dispatched individually" as "the urgent batch fix" it already shipped). `publishMany()` still does the naive per-ID `dispatch()` loop that job moved away from. Because there is currently no call site, this is pure latent risk rather than an active P1 — per the P1/P2 calibration anchor, a write-amplification pattern that "requires... a code path that doesn't currently exist" re-tiers to P2 hardening. Flagging it now (rather than waiting for a caller to be added) prevents the same anti-pattern from shipping a second time in this codebase.
    - **Plain English:** There's a "send this notification to a big list of people" function in the code that currently isn't used by anything — but if someone wires it up later (e.g. for a staff broadcast), it would hand out one email job per person one at a time instead of bundling them, which is exactly the slow, clog-prone pattern this codebase already fixed once for its other bulk-broadcast feature. Since nothing calls this function today, there's no live problem — but it's a trap waiting for whoever uses it next, so it's worth fixing now while it's cheap and matching it to the batching pattern already proven elsewhere in the codebase.
    - **Evidence:**
        ```php
        foreach ($insertedIds as $id) {
            [$category, $userId] = $idToCategoryAndPro[$id];
            SendTransactionalNotificationEmailJob::dispatch($id, $category, $userId)
                ->onQueue('mail');
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Analytics/notification fan-out hardening:** #CACHE-1, #CACHE-2
    - **Why grouped:** Same root-cause pattern (per-event/per-recipient DB round-trip in a `foreach`, currently latent/unreachable in production) across the two highest-risk write-heavy surfaces named in the lens (analytics ingest, notification fan-out). Neither is urgent; both are cheap to fix together and both should converge on the batching idiom `SendStaffBroadcastEmailsJob` already established in this codebase.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet — no escalation needed, both are mechanical batching changes with existing in-repo reference implementations.

## Standalone — do NOT bundle

None.
