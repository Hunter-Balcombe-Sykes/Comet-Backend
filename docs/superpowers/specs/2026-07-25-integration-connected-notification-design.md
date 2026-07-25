# Integration-connected notification — design

**Date:** 2026-07-25
**Status:** approved, not yet implemented

## Goal

When a user connects an integration from the dashboard, raise an **in-app bell
notification** naming the integration. No email.

## Why this is small

The notification engine already does the work. `NotificationPublisher::publish()`
inserts the in-app row; email is a *separate escalation* that fires only when
`critical: true`. So "notification, not an email" is `critical: false` — enforced
by the engine, not by a caller remembering. Two dispatchers already model both
ends of this: `AchievementNotifier` (all non-critical) and `PlatformHealthNotifier`
(critical → reconnect email).

No migration. The `notifications.notifications` table and the category registry in
`config/partna.php` already absorb this.

## Scope decisions

| Question | Decision |
|---|---|
| Which creates notify? | User-initiated real integrations only |
| Deferred (async) connects | On confirmed success only, never at `pending` |
| Reconnect after disconnect | Notifies again — once per connect action |

### Excluded, deliberately

- **Auto-seeded rows** (pre-account builds, `GoogleBusinessAutoSync`,
  `InstagramSourceGenerator`, `CustomLinkSeeder`, `EventsSeeder`,
  `ShopBrandSeeder`). The user did not do these; notifying would greet a
  claiming user with a pile of rows for work we did on their behalf.
- **Per-link and per-event rows.** A user adding eight custom links should not
  get eight notifications.
- **Staff toggles** via `StaffIntegrationManagementController`. Out of scope for
  "user-initiated"; a separate emit point if we ever want it.

## Three properties that make this cheap

These are load-bearing. Each removes code that would otherwise have to exist.

1. **The trait is already the "user did this" boundary.**
   Every seeder calls `IntegrationConnection::updateOrCreate` *directly* — none
   can use a controller trait. Hooking
   `ManagesIntegrationConnection::upsertConnection()` therefore excludes
   auto-seeded rows with no conditional at all.

2. **The row UUID is already a connect-episode marker.**
   `idx_platform_connections_unique_active` is **partial** —
   `ON (user_id, platform, resource_id) WHERE deleted_at IS NULL`
   (`supabase/migrations/20260602150238_create_platform_connections.sql:31`).
   Disconnect soft-deletes, so a reconnect mints a **new row with a new UUID**.
   `dedupe_key = "integration_connected:{connection_id}"` therefore delivers
   re-notify-on-reconnect with no timestamp, no episode column, no extra state.

3. **`resource_kind` already discriminates links and events.**
   Stamped `'link'` at `CustomLinksController.php:66` and `'event'` at
   `EventsPlatformController.php:243`, and nowhere else. Excluding link/event
   spam is one clause against a column that exists.

## Components

### 1. Category registration

`config/partna.php`:

- `notifications.mailables`: add `'integration_connected' => null` — in-app only,
  following the `achievement` / `content_scrape` precedent.
- `notification_retention_days`: add `'integration_connected' => 30`.

Not reusing the existing `platform_connection` category: that one is mapped to
`CriticalNotificationMail` and means "your connection broke, reconnect it". A
celebratory connect notice sharing its key would conflate the two in the bell and
in the preference UI.

`CAPABILITY_GATE_MAP` in `SendTransactionalNotificationEmailJob` is currently
empty, so no capability entry is needed. Connecting an integration is not
account-type-restricted; `FeatureAvailability` already gates the connect itself
upstream via `assertPlatformAvailable()`.

### 2. `app/Services/Notifications/Dispatchers/IntegrationNotifier.php`

Sibling to `AchievementNotifier`, same shape: constructor-injected
`NotificationPublisher`, one public method, a private `safePublish` wrapping the
call in try/catch with `report()` so a notification failure can never break a
connect.

Not a method on `PlatformHealthNotifier` — that class is explicitly scoped to
warnings and errors.

```
connected(IntegrationConnection $connection): void

  guard     return early unless last_refresh_status === 'ok'
  guard     return early if resource_kind is 'event' or 'link'

  label     PlatformRegistry::get($platform)?->getLabel() ?? Str::headline($platform)
  title     "{Label} connected"
  body      "Your {Label} connection is live and will now show on your Partna page."
  type      Success
  category  integration_connected
  dedupeKey integration_connected:{connection->id}
  ctaUrl    /account/integrations
  critical  false
```

**Both guards live in the notifier, not at the call sites.** This is deliberate.
`EventsController:48` dispatches `ConnectFetchJob` for a deferred *organiser*
connect — an account row, legitimately notifiable — but keeping the link/event
guard at the call sites would mean proving that no `event-`/`link-` row can ever
reach that job, for every present *and future* dispatcher of it. Owning the rule
in one class makes that unprovable negative irrelevant: any emit point added
later inherits the guard instead of having to remember it.

The label comes from the registry descriptor rather than a hand-maintained map,
so `youtube-music` reads "YouTube Music" instead of `Str::headline`'s
"Youtube Music". `PlatformRegistry` is a container singleton
(`PlatformRegistryServiceProvider:123`).

### 3. Emit points

Both sites call `IntegrationNotifier::connected($connection)` unconditionally
except for the one condition the notifier cannot see for itself.

**a. `ManagesIntegrationConnection::upsertConnection()`** — after the
`updateOrCreate`, call when `$connection->wasRecentlyCreated`.

`wasRecentlyCreated` must be checked here rather than inside the notifier: it is
a per-instance flag on the model object, true only for the instance that
performed the insert. At (b) the row was created in an earlier request, so the
job's freshly-loaded instance always reports false. The status guard inside the
notifier does the rest — a deferred connect's row is `pending` at this point and
returns early.

**b. `ConnectFetchJob`** — on both success paths:

- the locked `$connection->update([... 'last_refresh_status' => 'ok' ...])`
- the `markOk()` call in the `FetchNotModifiedException` catch

The 304 path is easy to miss and must not be. `FetchNotModifiedException` is a
*successful* connect where the vendor reported nothing changed; skipping it would
silently drop notifications for Bandcamp/Spotify-style reconnects.

The status guard is what implements "confirmed success only": a deferred connect
creates its row as `pending` and is skipped at (a), then notifies from (b) once
the job flips it to `ok`. A terminal failure lands `error` or `unavailable` and
never notifies.

`markOk()` and `markTerminal()` use `saveQuietly()`, so no model event fires on
those paths. That rules out an observer-based implementation — `saveQuietly` on
the 304 path would make it invisible — and is why both emit points are explicit
calls.

## Resulting behaviour

| Scenario | Outcome |
|---|---|
| Dashboard connect (sync) | One notification, named |
| Deferred connect, job succeeds | One notification, at job success |
| Deferred connect, job fails terminally | None |
| Disconnect → reconnect | Notifies again (new row UUID) |
| Reconnect in place, no disconnect first | Deduped, silent |
| Second account on a multi-account platform | Separate row → separate notification |
| Custom link / individual event added | None |
| Pre-account or auto-sync seeded row | None |
| Staff toggle | None |

"Reconnect in place → silent" is correct on its own terms — that is changing a
connection, not adding one — and it also makes the sync and async paths agree
without a special case, since (b) publishes under the same dedupe key the
original connect already used.

## Testing

Pest feature tests:

1. A dashboard connect writes exactly one notification whose title contains the
   platform's display label.
2. A deferred connect writes none while `pending`, and exactly one once
   `ConnectFetchJob` succeeds.
3. A terminally-failed `ConnectFetchJob` writes none.
4. A custom-link write and an event write produce none.
5. A seeder-created connection produces none.
6. `IntegrationNotifier::connected()` called *directly* with an `event`/`link`
   row, or with a non-`ok` row, publishes nothing — the guards are tested at the
   notifier, not only through the call sites, since their whole purpose is to
   hold for callers that don't exist yet.
7. A deferred events-organiser connect (`EventsController::add` → 202 →
   `ConnectFetchJob`) *does* notify — the guard must not over-reach and swallow
   the account row this path legitimately creates.
8. No `SendTransactionalNotificationEmailJob` is ever queued by any of the above
   (`Bus::fake()` / `Queue::fake()` assertion) — the "not an email" requirement,
   asserted rather than assumed.

Note: tests run SQLite while production is Postgres. Nothing here is
constraint-bound (no new columns, no CHECK), so the usual drift risk does not
apply.
