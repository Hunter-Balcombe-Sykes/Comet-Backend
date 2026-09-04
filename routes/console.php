<?php

use App\Jobs\Cache\AggregateCacheMetricsJob;
use App\Jobs\Streaming\CheckStreamingLiveStatusJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduler conventions — every entry below MUST honour these:
 *   • ->onOneServer()           — elects one host per tick on multi-server deploys
 *                                  (Redis atomic lock; no-op on a single host).
 *   • ->withoutOverlapping(N)   — prefer an explicit lock TTL in minutes. A bare
 *                                  withoutOverlapping() defaults to 1440 (24h), which
 *                                  is rarely the right ceiling. N must exceed the
 *                                  task's expected runtime AND, for sub-hourly
 *                                  cadences, must also exceed the cadence itself —
 *                                  otherwise a slow run races the next tick on the
 *                                  instant the lock TTL expires.
 *   • ->onFailure(...)          — surfaces silent maintenance failures to Nightwatch.
 *   • ->runInBackground()       — for daily/cron-scale tasks that shouldn't block
 *                                  the per-minute scheduler tick.
 */

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared onFailure handler — report() is what reaches Nightwatch (a Log::error alone
// does not raise an alert). Each scheduled task passes its own label.
$reportScheduledFailure = fn (string $task) => function (?Throwable $e = null) use ($task): void {
    if ($e !== null) {
        report($e);
    }
    Log::error("Scheduled task failed: {$task}", [
        'exception' => $e ? get_class($e) : null,
        'message' => $e?->getMessage(),
    ]);
};

// Catalog convergence net: sync is digest-gated (no-op when already landed),
// so the hour cadence costs one SELECT — its job is making sure a deploy that
// forgot the manual `catalog:sync` step still converges within the hour.
Schedule::command('catalog:sync')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('catalog-sync'));

Schedule::command('partna:purge-soft-deletes')
    ->dailyAt('03:20')
    ->onOneServer()
    ->withoutOverlapping(600) // 10h lock — historical purges on large tables can run long.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('purge-soft-deletes'));

// --days is a GRACE PERIOD AFTER EXPIRY, not a retention window. Per-category retention is
// already applied at publish time by NotificationPublisher, which stamps
// ends_at = now + partna.notification_retention_days.<category>. This command deletes on
// ends_at < cutoff, so a policy_update (365d) goes at day 395, not day 30.
Schedule::command('partna:prune-notifications', ['--days' => 30])
    ->dailyAt('03:25')
    ->onOneServer()
    ->withoutOverlapping(120) // 2h lock — bounded by retention-window batch size.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-notifications'));

// OV-H: weekly in-app "your week on Partna" summary (non-critical, Info). Dedupe-per-week
// makes a re-run idempotent. Monday 08:00 UTC — start-of-week nudge, off the daily 03:xx sweeps.
Schedule::command('partna:notify-weekly-summary')
    ->weeklyOn(1, '08:00')
    ->onOneServer()
    ->withoutOverlapping(120) // 2h lock — bounded by the active-users fan-out size.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('notify-weekly-summary'));

// Unread-enquiry nudges — hourly; the 48h window + per-enquiry dedupe make
// cadence a non-issue (each enquiry can only ever produce one reminder).
// hourlyAt(37) not bare hourly() per the RV-5 note above (the :00 slot is
// crowded); 37 dodges every everyTwoMinutes/everyFiveMinutes/everyFifteenMinutes
// entry in this file.
Schedule::command('partna:notify-unanswered-enquiries')
    ->hourlyAt(37)
    ->onOneServer()
    ->withoutOverlapping(50)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('notify-unanswered-enquiries'));

Schedule::command('partna:analytics:purge-raw-events')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — partition-scoped DELETE; daily cadence.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('purge-raw-events'));

// #PRIV-3: daily hard-delete of third-party reviewer PII (author_name,
// author_photo_url, verbatim text) on content.f_review rows whose review is
// no longer carried by any LIVE content.source_items row — i.e. the reviewer
// deleted it upstream, or the record was tombstoned. Account-close erasure is
// already handled by the item_id/source_id CASCADE chain to core.users; this
// is the live-account path that cascade never reaches.
// Grace window (default 14d) absorbs a transient zero-record ingest run.
// 03:05 UTC — between the raw-events purge (03:00) and sessions:reconcile (03:10).
Schedule::command('content:prune-orphaned-review-pii')
    ->dailyAt('03:05')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('content:prune-orphaned-review-pii'));

// #PRIV-4: weekly thinning of site.site_documents. Each build inserts a full
// page snapshot (display name, handle, bio, social links, addresses) as JSONB;
// the CAS hash gate suppresses byte-identical rebuilds but not distinct ones,
// and site:build-documents --stale runs every five minutes. Only the newest
// version per (site_id, channel) is ever read (DocumentBuilder::build()) —
// older versions have no consumer at all, so keeping 3 is rollback headroom.
// Sunday 05:20 UTC — 10 min clear of media:gc-orphaned-platform-media (05:10).
Schedule::command('site:prune-document-versions')
    ->weeklyOn(0, '05:20')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('site:prune-document-versions'));

// Recompute content popularity scores from raw events (pages + scored items).
// Reads only section_views / link_clicks / item_views.
//
// CADENCE (2026-07-09): every 15 min while validating the ONE theme, so page +
// item scores reflect real browsing without a manual trigger. Was:
// ->dailyAt('02:40'). The daily 03:00 purge still bounds the retained window
// this reads.
//
// SCALE-3 (2026-07-20): the "full-sweeps EVERY published site each run" half of
// the earlier REVISIT is fixed — ComputeContentPopularityScores now scopes the
// no-(--site) sweep to sites with a raw event in the last
// RECENT_EVENTS_WINDOW_MINUTES.
//
// Both ⚠️ warnings that stood here CLOSED 2026-08-27 (smart-scoring plan):
// the fixed 0.7/0.3 blend became cadence-aware (cadenceBlendPrev — the
// previous weight compounds 0.3^(Δt/1day), so the 15-min cadence smooths as
// intended), and the missed-tick gap got its proper fix — the persisted
// last-successful-run watermark (analytics.scoring_watermarks, migration
// 20260827070000) replaces the fixed lookback, so a scheduler outage of any
// length no longer loses the gap. `--site` remains the manual escape hatch.
Schedule::command('analytics:compute-popularity')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(16) // 16min lock (cadence + 1) — a run whose actual runtime exceeds 15min doesn't have its lock expire mid-flight and race the next tick.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('compute-popularity'));

// Dispatcher: fan out a queued RefreshConnectionJob per due connection. Hourly so
// connections are picked up close to their TTL (the heavy work is on the queue, not
// here). Lock < cadence so a slow run can't overlap the next tick.
// RV-5: pinned off minute :00 — ->hourly() collided with 10 other scheduled entries
// every hour (11 at :00 itself, 7 of them Postgres) on the same 1 GiB container.
// :23 is odd, not divisible by 5 or 15, so it dodges every everyTwoMinutes /
// everyFiveMinutes / everyFifteenMinutes / hourly entry in this file bar the
// everyMinute keep-alive ping.
Schedule::command('integrations:refresh')
    ->hourlyAt(23)
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(50)
    ->onFailure($reportScheduledFailure('integrations:refresh'));

// Staleness alarm: page when too many connections fall overdue (SCALE-1).
Schedule::command('integrations:refresh-backlog')
    ->hourly()
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(50)
    ->onFailure($reportScheduledFailure('integrations:refresh-backlog'));

Schedule::command('queue:prune-failed --hours=72')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — proportional to failed_jobs table size.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-failed-jobs'));

// Reads the previous hour's cache hit/miss Redis counters, logs structured metrics,
// and reports SLO violations (hot prefixes below 90% hit rate) to Nightwatch.
Schedule::job(new AggregateCacheMetricsJob)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10) // 10min lock — read-only Redis aggregation, completes in seconds.
    ->onFailure($reportScheduledFailure('aggregate-cache-metrics'));

// Snapshots queue throughput / runtime metrics into Redis so the Horizon
// Metrics tab has data to render.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10) // 10min lock — 2x everyFiveMinutes cadence safety ceiling.
    ->onFailure($reportScheduledFailure('horizon-snapshot'));

// withoutOverlapping(5) gives a 2.5x ceiling over the everyTwoMinutes cadence. The
// prior value (2) equalled the cadence, creating a same-tick race: lock TTL expiry
// and the next dispatch happen at the same instant, so a slow run could collide
// with itself. N must exceed the cadence for high-frequency tasks.
//
// ⚠️ Since 2026-09-04 nothing reads what this job writes (LiveStatusInjector,
// the sole reader of the `streaming:live:*` keys, went with the legacy public-site
// payload lane) — it stays scheduled pending an owner decision. It bills nothing
// today only because no block has live_check_enabled=true (0 on dev and prod,
// 2026-09-04); the first one that does starts paid per-handle vendor calls whose
// results nothing reads. See the ORPHANED READER note in
// app/Services/Streaming/LiveStatusPoller.php.
Schedule::job(new CheckStreamingLiveStatusJob)
    ->everyTwoMinutes()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->onFailure($reportScheduledFailure('check-streaming-live-status'));

// Handle/subdomain alias lifecycle: hard-deletes expired alias rows daily.
Schedule::command('handles:prune-expired-aliases')
    ->dailyAt('03:15')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-expired-aliases'));

// Notifies alias holders of upcoming expiry (T-3/T-1 day warnings).
Schedule::command('handles:notify-expiry')
    ->dailyAt('09:00')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — closes a race between application-level whereNull guards on the notified_t* stamp columns.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('handles-notify-expiry'));

// 271-PRIV-1: item-URL slug lifecycle. Retired slugs (site.item_slugs,
// is_current = false) serve as 301 aliases for
// config('partna.item_slugs.retirement_days') days, then this hard-deletes them.
// 03:35 -- the daily maintenance band is 03:00/03:15/03:20/03:25/03:55; 03:35 is free.
Schedule::command('slugs:prune-retired')
    ->dailyAt('03:35')
    ->onOneServer()
    ->withoutOverlapping(120) // 2h lock -- matches the sibling alias prune; bounded delete.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('slugs-prune-retired'));

// PRIV-2: enforces config('partna.handle.audit_retention_years') (default 7y) on
// the append-only audit.handle_change_log table via the SECURITY DEFINER
// audit.prune_handle_change_log() RPC (app_backend cannot DELETE directly).
Schedule::command('handles:prune-audit-logs')
    ->dailyAt('03:25')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('handles-prune-audit-logs'));

// B8 / models-data PRIV-2 + PRIV-3: enforces config('partna.audit.pii_retention_years')
// (default 7y) on the two sibling audit tables (audit.user_deletion_audit,
// audit.data_export_audit) via the SECURITY DEFINER audit.prune_user_deletion_audit() /
// audit.prune_data_export_audit() RPCs (app_backend cannot UPDATE the audit schema
// directly). Anonymise-in-place — keeps the compliance/fraud event row, redacts the PII.
// Daily like the sibling handle-audit prune; the sentinel guard makes a re-run a cheap
// no-op, so the 7y window doesn't need a rarer cadence.
Schedule::command('audit:prune-pii-snapshots')
    ->dailyAt('03:55')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('audit-prune-pii-snapshots'));

Schedule::command('feature-flags:prune-expired')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->onFailure($reportScheduledFailure('feature-flags:prune-expired'));

// Active Sessions backstop: drop tracked session_ids Supabase no longer holds
// (client-only logouts never call /api/sessions/logout, so dead entries pile up
// for users who never open the sessions page). listSessionsForUser reconciles
// on read; this sweeps everyone else. Fail-open per user.
Schedule::command('sessions:reconcile-tracked')
    ->dailyAt('03:10')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('sessions:reconcile-tracked'));

// Pre-account sites: hard-deletes expired unclaimed builds (+ stale failed
// builds past failed_prune_hours). Teardown-ordering mirrors
// AccountDeletionService::purge() — see PruneExpiredPreAccountBuilds.
Schedule::command('builds:prune-expired')
    ->dailyAt('03:40')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-expired-pre-account-builds'));

// Setup-settled email timing (2026-09-03): nothing announces the moment a
// build finishes filling in, so this asks. Every minute, bounded to a
// 30-minute creation window -- cost scales with builds in flight, not table
// size, and every build predating the feature is far older than the window,
// which is what made the cutover safe with no backfill.
Schedule::command('builds:settle-sweep')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('builds-settle-sweep'));

// Slice 1b D4: collect borrowed (Google Places) media assets past the ~30-day
// photo-url expiry that no live item references. Not housekeeping — the Places
// terms grant photos no caching exemption, so this is how borrowed content
// stops being retained. 03:40 is builds:prune-expired; 03:50 avoids it.
Schedule::command('content:prune-borrowed-assets')
    ->dailyAt('03:50')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('prune-borrowed-media-assets'));

// LIFE-4: hourly watchdog for pre_account_builds stuck in pending/building past
// the SLA (worker crash mid-scrape never calls failed()). Mirrors
// media:cleanup-stuck-processing's design — mark the stuck state honest
// (-> failed); re-dispatch is reserve()'s job, not the sweep's.
Schedule::command('builds:reconcile-stuck')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — Postgres lookup + updates, typically seconds.
    ->onFailure($reportScheduledFailure('builds:reconcile-stuck'));

// Keep Laravel Cloud warm. Fires a 3-second HTTP request to the local /up
// health endpoint every minute so the autoscaler doesn't park the web
// pod between visitor bursts. Cold starts (when the pod has been parked)
// can run 5-10s on Laravel Cloud and were tripping the Astro Worker's
// fetchProfile timeout — manifesting as random "profile not found" for
// the visitor. The keep-alive eliminates those blips at the cost of
// ~1440 lightweight requests per day.
Schedule::call(function () {
    try {
        $url = rtrim((string) config('app.url'), '/').'/up';
        Http::timeout(3)->retry(1, 200)->get($url);
    } catch (Throwable $e) {
        // Silent — keep-alive failures aren't actionable.
    }
})
    // description() / name() must precede onOneServer() — Laravel uses the
    // string as the cluster-wide mutex key for the closure event.
    ->name('keep-alive-ping')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

// Cloudflare KV subdomain routing backstop.
Schedule::command('partna:backfill-subdomain-kv', ['--all', '--queue'])
    ->weeklyOn(0, '04:00') // Sunday 04:00 UTC — off-peak for AU/NZ
    ->onOneServer()
    ->withoutOverlapping(120)
    ->description('Weekly resync of Cloudflare KV subdomain routing entries')
    ->onFailure($reportScheduledFailure('backfill-subdomain-kv'));

// P2-14: daily watchdog for ExportUserDataJob rows orphaned in PROCESSING by SIGKILL.
// failed() only fires on retry exhaustion, never on a hard kill, so a worker death
// between markProcessing() and completion leaves the audit row stuck forever.
Schedule::command('gdpr:sweep-stale-exports')
    ->dailyAt('03:35')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — export audit table is tiny; completes in seconds.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('gdpr:sweep-stale-exports'));

// QUEUE-5: hourly watchdog for SiteMedia rows orphaned in PROCESSING.
Schedule::command('media:cleanup-stuck-processing')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — Postgres lookup + queue dispatch, typically seconds.
    ->onFailure($reportScheduledFailure('media:cleanup-stuck-processing'));

// P1-08 (ledger sweep): daily recovery for video R2 artifacts orphaned when a
// DeleteMediaArtifactsJob exhausted its retries during an R2 outage and the owning
// account was then hard-deleted. Reads paths from EVENT_PURGED audit rows — cheap,
// precise, GDPR-timely. Daily so erasure completes within the next-day window.
Schedule::command('gdpr:sweep-purged-video-artifacts')
    ->dailyAt('03:45')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('gdpr:sweep-purged-video-artifacts'));

// PRIV-8: weekly hard-delete of early_access_signups rows from non-converting applicants
// older than the retention window (default 730d). Staggered Sunday 04:30 UTC — last of the
// weekly Sunday sweeps, after the 04:10 unsubscribed-subscriptions prune and 04:20 video GC.
Schedule::command('early-access:prune-old-signups')
    ->weeklyOn(0, '04:30')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — single bulk delete; completes in seconds.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('early-access:prune-old-signups'));

// DINT-1 / PRIV-7 Gap 2: weekly hard-delete of unsubscribed email_subscriptions older than
// the retention window (default 365d). The whole row is deleted — email and email_lc are both
// PII and NOT NULL, so there is no skeleton worth keeping; child broadcast_email_receipts
// cascade via the DINT-2 FK. Cadence: Sunday 04:10 UTC (off-peak for AU/NZ, after the daily
// gdpr:* sweeps). withoutOverlapping(60) — a single bulk delete finishes in seconds; the 60min
// ceiling is headroom for future growth.
Schedule::command('notifications:prune-unsubscribed-subscriptions')
    ->weeklyOn(0, '04:10')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('notifications:prune-unsubscribed-subscriptions'));

// PRIV-2: enforce the 30-day GDPR export file retention window. Deletes R2 ZIP
// artifacts and nulls the file columns on audit.data_export_audit rows whose
// created_at exceeds the retention window. The audit row itself is KEPT —
// GDPR requires the record that an export happened. Runs after the other
// gdpr:* sweeps (03:35 stale, 03:45 video) so they all finish before the
// log rotation window closes.
Schedule::command('gdpr:prune-completed-exports')
    ->dailyAt('03:50')
    ->onOneServer()
    ->withoutOverlapping(60) // 60min lock — export table is tiny; completes in seconds.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('gdpr:prune-completed-exports'));

// P1-08 (prefix GC backstop): weekly garbage collection of video R2 objects with
// no backing site_media row, from ANY cause (failed upload, crashed transcode,
// pre-ledger orphans). Heavier — a full videos/ prefix LIST — so it runs weekly,
// off-peak, age-guarded against in-flight uploads.
Schedule::command('media:gc-orphaned-video-artifacts')
    ->weeklyOn(0, '04:20') // Sunday 04:20 UTC — off-peak for AU/NZ.
    ->onOneServer()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('media:gc-orphaned-video-artifacts'));

// DINT-6: weekly erasure of non-account reporter PII (reporter_email, reason_details,
// signal_data) on case_signals whose parent case resolved more than 90 days ago.
// Account-reporter PII is handled at deletion time by AccountDeletionService::purgeCaseSignalPii().
// Sunday 04:40 UTC — after the other Sunday weekly sweeps (04:00 KV, 04:10 subs, 04:20 video GC, 04:30 early access).
// withoutOverlapping(60) — single bulk update on a small T&S table; completes in seconds.
Schedule::command('moderation:prune-resolved-signal-pii')
    ->weeklyOn(0, '04:40')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('moderation:prune-resolved-signal-pii'));

// PRIV-8: weekly hard-delete of core.feedback submissions older than the retention
// window (default 365d, any triage status) — nothing else ages this table out.
// Sunday 04:50 UTC — last of the Sunday weekly sweeps. withoutOverlapping(60) —
// batched delete on a small T&S-adjacent table; expected to complete in seconds.
Schedule::command('feedback:prune-old-submissions')
    ->weeklyOn(0, '04:50')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('feedback:prune-old-submissions'));

// RV-11: weekly garbage collection of orphaned Instagram mirror folders under the
// platforms/ R2 prefix. DeleteMirroredMediaJob::failed() only reports and logs, and
// AccountDeletionService::purge() cascades platform_connections away without ever
// firing the observer — so nothing else in the system reclaims these. Detector only:
// it dispatches the existing DeleteMirroredMediaJob rather than deleting inline.
// Sunday 05:10 UTC — 20 min clear of the last weekly sweep (04:50) and off the
// crowded 03:xx daily block; a second full R2 prefix LIST never overlaps the 04:20
// videos/ GC. withoutOverlapping(180) — ~6x the pathological run time (a full
// platforms/ LIST plus <=200 dispatches), well under the weekly cadence so an
// abandoned lock still clears days before the next tick (cf. R2-SCHED-1).
Schedule::command('media:gc-orphaned-platform-media')
    ->weeklyOn(0, '05:10')
    ->onOneServer()
    ->withoutOverlapping(180)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('media:gc-orphaned-platform-media'));

// Scan open/triaged/under_review moderation cases and log warnings for any approaching
// their SLA deadline. Threshold defaults to 120 min; configurable via
// partna.moderation.sla.breach_warning_min. withoutOverlapping(30) gives a 2x ceiling
// over the 15-min cadence with headroom for a slow Postgres scan.
Schedule::command('moderation:sla-scan')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — 2x the 15min cadence to prevent same-tick races.
    ->onFailure($reportScheduledFailure('moderation:sla-scan'));

// Self-heal transient menu scrapes: re-dispatch a forced MenuFetchJob for menus
// whose Uber Eats / DoorDash scrape came back 'unavailable' (flaky bot-block),
// bounded to a recent window so a dead store isn't retried forever. Every 15 min
// gives a transient block several chances to clear within the window.
// SCALE-4: budget-paced (stops when ApifyBudget::remaining('menu') = 0) and
// staggered (6s/job) to avoid bursting the Apify queue; default limit is 50.
Schedule::command('menu:retry-unavailable')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — 2x the 15min cadence to prevent same-tick races.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('menu:retry-unavailable'));

// Ingest dispatcher (plan §4/P3): fans out a queued RunSourceJob per due ingest.sources
// row via SourceScheduler::claimDue(). 15-min cadence so a source's own measured cadence
// (min_interval_secs can be as low as an hour) is picked up promptly; the heavy fetch work
// runs on the 'ingest' queue, not here. withoutOverlapping(20) exceeds the 15min cadence so
// a slow tick's lock can't expire mid-flight and race the next one.
Schedule::command('ingest:dispatch')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(20)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('ingest:dispatch'));

// Safety net for link cards that never got their enrichment (F4 lineage):
// idempotent, unique per card, so daily is plenty.
Schedule::command('platforms:enrich-pending-cards --older-than=30')
    ->dailyAt('03:20')
    ->onOneServer()
    // 30min lock, NOT the bare default: a bare withoutOverlapping() holds for
    // 1440min, so one crashed run silently stopped this safety net for 24h
    // (#LIFE-16 / #SCALE-20). 30 matches the daily-sweep precedent above
    // (purge-raw-events); compute-popularity's 16 is cadence+1 for a
    // FIFTEEN-MINUTE job and would expire mid-run here.
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('enrich-pending-cards'));

// Safety net for item caches that missed their projection refresh (X4):
// stale-only by default, so a healthy database is a no-op read.
Schedule::command('content:refresh-item-caches')
    ->dailyAt('03:25')
    ->onOneServer()
    // 30min lock — see enrich-pending-cards above (#LIFE-17 / #SCALE-21).
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('refresh-item-caches'));

// Stranded-claim watchdog: releases an ingest.sources claim that outlived any plausible
// run (a worker died mid-flight) and files the anomaly. Hourly is ample slack — a claim
// only qualifies past SourceScheduler::STRANDED_AFTER_SECONDS (2h), so an hourly cadence
// still catches one within the same hour it goes stale.
Schedule::command('ingest:stranded')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10)
    ->onFailure($reportScheduledFailure('ingest:stranded'));

// Abandoned-claim watchdog (LIFE-18): sweeps ingest.effects claims stuck past
// EffectLedger::ABANDON_AFTER_SECONDS (900s) to 'abandoned', filing a critical
// ingest.anomalies row + Nightwatch report per NEW transition — makes a dead billed
// claim visible without waiting for once() to trip over it lazily on a later call.
// hourlyAt(47) not bare hourly() per the RV-5 note above (the :00 slot is crowded);
// 47 dodges every everyTwoMinutes/everyFiveMinutes/everyFifteenMinutes entry here.
// withoutOverlapping(10) matches ingest:stranded's sibling watchdog.
Schedule::command('ingest:effects', ['--resolve'])
    ->hourlyAt(47)
    ->onOneServer()
    ->withoutOverlapping(10)
    ->onFailure($reportScheduledFailure('ingest:effects'));

// LIFE-20: hourly alarm for `ingest.anomalies` rows that are severity='critical',
// still unresolved, and older than the age gate. The anomalies table is the
// system's explicit "a human must look at this" queue, and a DB queue reaches
// nobody on its own — report() is what pages (see CheckPlatformRefreshBacklogCommand).
// Alerts ONCE per row via a `detail.alerted_at` stamp, and skips rows whose
// detector already reported at detection time (EffectLedger::markAbandoned).
// hourlyAt(53) per the RV-5 note above: :00 is crowded, and 53 is odd and not
// divisible by 5, so it dodges every everyTwoMinutes / everyFiveMinutes /
// everyFifteenMinutes / hourly entry in this file, and does not collide with
// integrations:refresh (:23) or ingest:effects (:47).
// withoutOverlapping(10) matches its ingest:stranded / ingest:effects siblings.
Schedule::command('ingest:anomalies')
    ->hourlyAt(53)
    ->onOneServer()
    ->withoutOverlapping(10)
    ->onFailure($reportScheduledFailure('ingest:anomalies'));

// Close the ledger row for an account the user connected some OTHER way (the
// connect sheet, an OAuth return). SuggestionsController::index() hides those
// cards but cannot close them — SCALE-8 took every write off that GET — and
// nobody can answer a card they cannot see, so without this the rows accrue
// forever and the alarm below counts them for good.
// 06:10, TEN MINUTES AHEAD of routing:stuck-intents deliberately: the alarm
// should count a ledger this has already tidied, or it pages about rows that
// were about to be closed. Daily is enough — the inbox already hides them, so
// the only thing latency affects is that alarm's threshold.
Schedule::command('routing:settle-connected')
    ->dailyAt('06:10')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('routing:settle-connected'));

// LIFE-19: daily backlog alarm for routing.source_intents stuck proposed/blocked
// past the age gate (drives idx_source_intents_stuck, whose own comment names it
// the "staff stuck-intents view feed"). DAILY, not hourly: this alarm carries no
// per-row dedupe state, so while the condition persists it pages once per run —
// daily caps that at one page a day, where hourly would be 24. The 14-day age
// gate means a 24h detection latency is immaterial.
// 06:20 UTC: clear of the crowded 03:xx daily sweep block and the 04:xx Sunday
// weeklies. The RV-5 minute-dodge above is an HOURLY-cadence concern (11 entries
// colliding at :00 every hour); a once-a-day task can collide at most once a day.
Schedule::command('routing:stuck-intents')
    ->dailyAt('06:20')
    ->onOneServer()
    ->withoutOverlapping(30) // 30min lock — one indexed COUNT(*); completes in ms.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('routing:stuck-intents'));

// Document sweeper (plan §9): the NET under content_revision bump enforcement — any
// site whose content moved past its built document gets a queued rebuild within five
// minutes, whichever write path bumped it (observer, raw seam, projection). Builds are
// hash-gated + CAS'd, so sweeping a site that produces byte-identical output costs one
// SELECT and writes nothing.
Schedule::command('site:build-documents --stale')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('site:build-documents'));

// Drains enquiries whose notification dispatch failed during a Redis outage
// (PublicEnquiryController stamps notifications_pending_since). Every five
// minutes: the lead is already safe in Postgres, so this is recovery latency,
// not data safety. Drill 03 (2026-08-06).
Schedule::command('enquiries:reconcile-notifications')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure($reportScheduledFailure('enquiries:reconcile-notifications'));

// Estate invariants. Deliberately runs AFTER the nightly maintenance block
// (prune 03:25, purge-soft-deletes 03:20, builds:prune-expired 03:40) so it
// measures the estate those commands leave behind, not the one they inherited.
// --http is the point of the schedule rather than an extra: the publish gate and
// the edge TTL are the two checks no unit test can reach, because their failure
// lives in the serving plane and in a Cloudflare rule, not in this repo.
// A breach exits non-zero, which surfaces through onFailure into Nightwatch.
// NB: the flag goes in the command STRING, not the args array. Schedule::command
// renders ['--http' => true] as --http='1', and a value-less option rejects that
// with "The --http option does not accept a value" — a nightly task that fails on
// argument parsing before it ever reaches a check.
Schedule::command('fleet:assert --http')
    ->dailyAt('04:10')
    ->onOneServer()
    ->withoutOverlapping(60) // 1h lock — the run is ~24 bounded HTTP calls plus six counts.
    ->runInBackground()
    ->onFailure($reportScheduledFailure('fleet-assert'));
