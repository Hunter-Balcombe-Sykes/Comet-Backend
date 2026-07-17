# Scaling Antipatterns Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching — per-event fan-out that scales with data cardinality instead of request rate, aggregate rebuilds on single writes, and caches lacking single-flight/jitter/push-invalidation.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/Analytics/AnalyticsDedupGuard.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/Ingestors/QueuedIngestor.php
- app/Services/Analytics/RankedActionsComputer.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/UserCacheService.php
- app/Services/Notifications/Dispatchers/AchievementNotifier.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Segments/SegmentResolver.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **CACHE-1** · P2 — `PostgresEventWriter::writeMany()` loops session-ping upserts one row at a time — latent because the only caller dispatches one event per job
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php:75-77
    - **Affects:** Analytics ingest throughput under burst ingest. Not active today: `QueuedIngestor::ingest()` (app/Services/Analytics/Ingestors/QueuedIngestor.php) dispatches exactly one `RecordAnalyticsEventJob` per HTTP ping, and `RecordAnalyticsEventJob::handle()` calls `$writer->write($event)`, which is `writeMany([$event])` — so `$sessionEvents` here never holds more than one item under the current architecture.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Collect all `TYPE_SESSION_PING` events in a batch and issue a single multi-row `INSERT ... ON CONFLICT (id, site_id) DO UPDATE ... GREATEST(...)` statement, mirroring the driver-portability handling already in `upsertSession()`.
        - Preserve the existing first-write-wins origin-field semantics and the SQLite-vs-Postgres `MAX`/`GREATEST` branch.
        - No urgency ahead of a batching ingestor landing — track alongside that work (`docs/superpowers/specs/2026-05-30-async-analytics-ingest-design.md` documents a future `BufferedIngestor`) rather than shipping standalone.
    - **Technical:** Category (2) write amplification. The `visitRows`/`clickRows`/`sectionRows`/`itemRows` arrays in the same method already use bulk `insertOrIgnore()`; only the session-ping path stayed a per-event loop because a single-row `ON CONFLICT ... WHERE`-guarded upsert with `GREATEST()` doesn't trivially generalize to a multi-row `VALUES` statement. Per the P1/P2 calibration anchor, a pattern whose bad behavior "only manifests under a scenario that isn't documented or expected" (a batching ingestor that doesn't exist yet) re-tiers to P2 hardening rather than P1. Canonical replacement: collapse the loop into one multi-row upsert so a future high-cardinality `writeMany()` call doesn't silently degrade back to N round-trips.
    - **Plain English:** Right now, every visitor's "still on the page" heartbeat is saved one at a time — but that's harmless today because the system only ever hands this code one heartbeat per database trip anyway. There's a loop that looks ready to handle many heartbeats at once but doesn't actually get used that way yet. If a future change starts grouping many heartbeats together before saving (to go faster under traffic spikes), this loop would quietly cancel that grouping out. Worth fixing so it's ready when that day comes — nothing is at risk right now.
    - **Evidence:**
        ```php
        foreach ($sessionEvents as $event) {
            $this->upsertSession($event);
        }
        ```

- [ ] **CACHE-2** · P2 — `NotificationPublisher::publishMany()` fans out one email job per recipient with no batching — currently unreachable (zero callers)
    - **Where:** app/Services/Notifications/NotificationPublisher.php:273-281
    - **Affects:** Any future caller of `publishMany()` for bulk in-app + email delivery (staff broadcasts, segment-targeted announcements). As of this audit `publishMany()` has zero callers anywhere in `app/` or `tests/` (verified by repo-wide grep) — the fan-out risk described here does not manifest in production today.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Before wiring any caller to `publishMany()`, replace the per-ID `SendTransactionalNotificationEmailJob::dispatch()` loop with a `Bus::batch()` + `array_chunk()` (chunk size from config) pattern, so bulk notification delivery shares one Redis-pipelining convention with the rest of the fan-out surfaces in this codebase rather than reintroducing a naive per-item dispatch loop.
        - Alternatively, if the intended caller only ever targets a small bounded audience (e.g. a staff per-professional batch under ~50), document that bound explicitly in the docblock so a future large-audience caller doesn't inherit the eager per-ID dispatch by copy-paste.
    - **Technical:** Category (2) write amplification. Structurally identical root cause to the pre-fix pattern other broadcast fan-out paths in this codebase have already moved away from (one queue job dispatched per recipient at fan-out time instead of chunked). Because there is currently no call site — the code was added as part of the OV-H critical-email-path work (commit `48d5f9fb`) but nothing invokes `publishMany` yet — this is pure latent risk rather than an active P1 per the calibration anchor ("requires a code path that doesn't currently exist" re-tiers P1→P2). Flagging it now, before a caller lands, is cheaper than fixing it after a broadcast feature ships on top of the naive loop.
    - **Plain English:** There's a "send this notification to a big list of people" function in the code that isn't wired up to anything yet — but if someone connects it later (e.g. for a staff broadcast to thousands of users), it would hand out one email task per person, one at a time, instead of bundling them. That's like hiring a separate courier for every single letter instead of loading one courier's van with a full batch. Nothing is broken today because nothing calls this function, but it's a trap waiting for whoever wires it up next — cheap to fix now, before that happens.
    - **Evidence:**
        ```php
        foreach ($insertedIds as $id) {
            [$category, $userId, $critical] = $idToCategoryAndPro[$id];
            // Only critical notifications escalate to email (OV-H) — matches publish().
            if (! $critical) {
                continue;
            }
            SendTransactionalNotificationEmailJob::dispatch($id, $category, $userId)
                ->onQueue('mail');
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Dormant fan-out/write-amplification hardening:** CACHE-1, CACHE-2
    - **Why grouped:** Same root-cause pattern (per-event/per-recipient DB or queue round-trip in a `foreach`, currently latent/unreachable in production) across the two write-heavy surfaces named in the lens (analytics ingest, notification fan-out). Neither is urgent; both are mechanical batching fixes that converge on the same chunking idiom.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet — no escalation needed, both are mechanical batching changes with existing in-repo reference implementations (bulk `insertOrIgnore()` for CACHE-1's sibling arrays; chunked dispatch patterns elsewhere in the notification fan-out surface for CACHE-2).

## Standalone — do NOT bundle

None — neither finding touches auth/authorization, money, or a DB migration/schema change, and both are M-effort.
