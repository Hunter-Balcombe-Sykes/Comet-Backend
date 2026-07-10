# OV-H — Automatic notification dispatchers (Comet-Backend)

Branch: `tobias/ov-h-notifications` (off `development`, OV-A merged; `notifications.notifications.critical` exists).

## Goal
Wire automatic notification dispatchers at real emit points, with a severity/expiry
model (critical → in-app + email via the OTP template family; non-critical → in-app
only with `ends_at` auto-clean) and a scheduled prune that removes expired non-critical
rows while preserving critical.

## Existing system (as found)
- `NotificationPublisher::publish()` / `publishMany()` — the in-app insert chokepoint.
  Sets `ends_at = now + retention_days` (per-category, `config('partna.notification_retention_days')`).
  Only caller today: `InAppEnquiryNotificationAdapter` (category `inbox`). Already dispatches
  `SendTransactionalNotificationEmailJob` when `email_enabled` — but did NOT read `critical`.
- `SendTransactionalNotificationEmailJob` — resolves a per-category Mailable from
  `config('partna.notifications.mailables')`; null → no email. Respects user prefs + capability gate.
- OTP email = `App\Mail\Auth\EmailConfirmMail` → `emails.auth.email-confirm` → layout `mail.layouts.partna`,
  base `App\Mail\BaseTransactionalMail`. Notification emails (`IncidentMail`, …) share this family via
  `emails.notifications._partial-content` (renders a `Notification`'s title/body/cta).
- `PruneNotifications` (`partna:prune-notifications`) already exists + is scheduled daily 03:25
  (`routes/console.php:50`). It deleted by `ends_at` **without** a critical guard.
- `critical` (OV-A migration 000400) = delivery escalation switch, INDEPENDENT of display `severity`.
  true → in-app + email; false → in-app only. Model has it fillable + bool-cast.

## Design decisions
1. **`critical` is the email switch.** `publish()`/`publishMany()` gain `bool $critical=false`,
   store it, and dispatch `SendTransactionalNotificationEmailJob` ONLY when `critical && email_enabled`.
   Safe: the sole `publish()` caller (enquiry `inbox`) is non-critical + has no mailable. The staff
   broadcast path dispatches email independently (unchanged).
2. **Expiry.** Non-critical → `ends_at = now + retention` (existing, auto-cleaned). Critical →
   `ends_at = null` (persists until resolved/dismissed; always visible via `scopeVisibleTo`).
3. **Prune.** Add `->where('critical', false)` to `PruneNotifications` so critical is never pruned
   even if it somehow carries an `ends_at`. Schedule unchanged (already daily 03:25).
4. **Email path reuses the OTP family.** New `App\Mail\Notifications\CriticalNotificationMail`
   (extends `BaseTransactionalMail`) + `emails/notifications/critical.blade.php` (extends
   `mail.layouts.partna`, includes the shared `_partial-content`). No new layout. Registered for
   critical categories; `SendTransactionalNotificationEmailJob` also falls back to it for ANY
   `critical` notification whose category has no explicit mailable (hard guarantee: critical → email).
5. **Dispatcher services** wrap the publisher with copy + category + critical + dedupe. Best-effort
   (try/catch + report) so a notification failure never breaks the host flow.

## Categories added (`config('partna.notifications.mailables')`)
- `achievement` → null (in-app only; Success)
- `platform_connection` → `CriticalNotificationMail` (critical connection failures → email; Warning display)
- `content_scrape` → null (non-critical scrape/menu warnings; Warning)
- `analytics_weekly` → null (weekly summary stub; Info)

## Dispatchers wired (real emit points)
| Event | Where | Category | Type | critical | Expiry |
|---|---|---|---|---|---|
| First enquiry ever | `DispatchEnquiryNotificationsJob::handle()` (count==1) | achievement | Success | no | yes |
| Platform connection refresh failing (circuit-breaker cross) | `PlatformRefresher::recordFailure()` (consecutive_failures == max) | platform_connection | Warning | **yes → email** | no (persists) |
| Menu scrape terminal failure | `MenuFetchJob::failed()` | content_scrape | Warning | no | yes |
| Weekly summary (stub) | `partna:notify-weekly-summary` command (scheduled) | analytics_weekly | Info | no | yes |

## Tail'd (honest — not wired tonight)
- **First N visitors / first item click / popularity milestone** — analytics stores raw event
  rows only (`analytics.site_visits/link_clicks/item_views`); no per-site counter to threshold and
  no milestone-fired state. Clean wiring needs either hot-path `COUNT(*)` in
  `PostgresEventWriter::writeMany()` (perf) or a new `analytics.site_milestones` table + scheduled
  sweep. Too invasive for a clean tonight-hook.
- **"You connected X" achievement** — `IntegrationConnectionObserver::saved()` has a meaningful-change
  gate + pending-first async pattern; firing cleanly (connected vs first-content-ready) needs care.
- **Instagram connect-failure** (`InstagramConnectJob::markFailed()`, separate path bypassing
  PlatformRefresher) — user is mid-connect with UI feedback; low notification value.
- **Staff multi-row critical fan-out email** — `StaffNotificationController::fanOut()` bulk-inserts
  rows (no dedupe key, bypasses publisher); OV-A left email for OV-H. Routing it through
  `publishMany(critical:true)` needs per-row dedupe design → follow-up.

(Appended to dashboard-batch `## Tail`.)

## Tests
Pest: each wired dispatcher fires on its event and not otherwise; critical → email job queued +
in-app row; non-critical → in-app only + gets an `ends_at`; prune removes expired non-critical and
leaves critical + unexpired. Plus publisher `critical` unit coverage + CriticalNotificationMail render.
Test-schema helpers gain the `critical` column where they lacked it.

## Files
New: `app/Mail/Notifications/CriticalNotificationMail.php`,
`resources/views/emails/notifications/critical.blade.php`,
`app/Services/Notifications/Dispatchers/{AchievementNotifier,PlatformHealthNotifier}.php`,
`app/Console/Commands/NotifyWeeklySummary.php`, + 8 test files.
Changed: `NotificationPublisher` (critical param + email gate + null-expiry),
`SendTransactionalNotificationEmailJob` (critical fallback mailable), `PruneNotifications`
(critical guard), `DispatchEnquiryNotificationsJob` (first-enquiry), `PlatformRefresher`
(circuit-breaker notify), `MenuFetchJob::failed` (scrape notify), `config/partna.php`
(4 categories + retention), `routes/console.php` (weekly schedule), 3 test-schema helpers (+`critical`).

## Status log
- Implemented all 4 dispatchers + email path + prune guard + expiry model.
- `composer test`: 3503 passed, 0 failed (2 deprecated / 1 warning / 1 risky / 119 skipped — all pre-existing). `pint` clean.
- Rebased onto origin/development (OV-G-BE #256); no conflicts. PR opened.
