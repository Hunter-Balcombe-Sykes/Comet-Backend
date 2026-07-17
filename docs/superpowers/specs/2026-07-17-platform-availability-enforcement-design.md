# Platform Availability Enforcement (staff kill-switch) — Design

**Date:** 2026-07-17
**Status:** Approved design, pending implementation plan
**Author:** Josh (+ Claude)

## 1. Problem

Staff can already author feature/integration availability rules
(`core.feature_availability`, keys `integration.<platform>`) — global or
segment-scoped, `enabled`/`disabled` — via `PUT /staff/feature-availability`.
But today those rules are **advisory only**: the sole runtime consumer is
`IntegrationsMetaController` (`GET /platforms/meta`), which returns an
`availability` map the dashboard uses to grey out cards. Nothing enforces them:

- The connect endpoints (`SkoolController::connect`, the converged
  `GenericPlatformController`, every bespoke platform controller) never consult
  availability, so a direct API call can still connect a "disabled" platform.
- Existing connections keep rendering on the public sitepage regardless of a
  disable rule.

For scraper-backed integrations (e.g. Skool via `SkoolScraper`, which sits on
the "red path" of the platform-integrations legal review) a real off-switch —
not just a hidden button — is the point of the feature.

The **per-professional** kill-switch already works and is genuinely enforced:
`StaffIntegrationManagementController` flips `is_active` on one user's
connections, and the public payload filters through
`IntegrationConnection::scopeActive()` (`WHERE is_active = true`). Its docblock
already frames it as *"the staff kill-switch for a broken/abusive integration
without deleting user data."* This design generalises that same, trusted
`is_active` mechanism to **global** and **segment** scope, and adds the missing
**connect-time** enforcement.

## 2. Goals / Non-goals

**Goals**
- A global disable rule for `integration.<platform>` blocks new connections AND
  removes existing content from live sites.
- A segment-scoped disable rule does the same, limited to users in that segment.
- Zero data loss: takedown flips `is_active` only — no row/`payload` deletion,
  no `deleted_at` soft-delete.
- Single connect-enforcement point (no per-controller sprawl).

**Non-goals (explicitly out of scope)**
- **No auto-reactivation on re-enable.** Removing/enabling a rule lifts the
  connect-block only; taken-down connections stay off until the user reconnects.
  This is a deliberate UX choice and the reason **no schema change is needed**.
- **No new segment criteria.** Expanding the `SegmentResolver` filter engine
  (location, IG-follower count, relative tenure, analytics-based segments) is a
  separate spec. This work consumes `SegmentResolver` output unchanged.
- **No read-time payload enforcement.** We do NOT thread `FeatureAvailability`
  into the public sitepage payload build (hot-path cost + edge-cache
  invalidation). Enforcement is via the existing `is_active` filter instead.
- **No gating of background auto-sync direct-writes** (Instagram/Google
  `*AutoSync` services that call `IntegrationConnection::create` directly). Noted
  as a possible hardening follow-up; not v1.

## 3. Design overview

Three additive pieces, no migration:

1. **Connect guard** — one helper in the shared `ManagesIntegrationConnection`
   trait blocks writes when the platform is unavailable for the acting user.
2. **Takedown job** — `ReconcilePlatformTakedownJob(platform, segmentId?)` flips
   `is_active=false` in bulk (global) or for segment members.
3. **Triggers** — the staff availability upsert (and, for completeness, segment
   member-adds) dispatch the takedown job.

## 4. Component 1 — Connect guard (two layers)

Enforcement is two layers: a **universal persistence net** (the guarantee) and a
**before-scrape middleware** (so a disabled platform isn't even contacted on the
primary connect flow). Both check the same predicate:
`FeatureAvailability::for($user)->allows('integration.'.$platform)` and, on
failure, return **503** (matching the existing `FeatureGate` convention).
`FeatureAvailability::for()` already resolves global AND segment rules per-user,
so a segment disable is enforced for free.

### 4a. Persistence net — trait guard (the guarantee)

Every write that could persist or reactivate a connection funnels through the
shared `ManagesIntegrationConnection` trait. Two methods issue the actual DB
write: `writeConnection()` and `writePendingLinkCard()` (async pending-card
path). `writeAccountConnection()` delegates to `writeConnection()`, so it's
covered transitively. This holds for both the bespoke controllers and the
converged `GenericPlatformController`.

```php
// ManagesIntegrationConnection — called at the very top of writeConnection()
// and writePendingLinkCard(), before the updateOrCreate.
private function assertPlatformAvailable(User $user): void
{
    if (! FeatureAvailability::for($user)->allows('integration.'.$this->platform())) {
        abort(503, 'This integration is currently unavailable.');
    }
}
```

Because `writeConnection()` hard-sets `is_active => true` and is also used by
reconnects and refresh/highlights saves, this guard alone prevents a disabled
platform from persisting a new connection AND prevents a background refresh (or a
reconnect while still disabled) from **resurrecting** a taken-down connection.
This is the universal guarantee — it covers every platform and every mutating
verb (connect, add-brand, detect, refresh, …), present and future.

**Rejected alternative:** a model-level `creating` guard on
`IntegrationConnection`. It would also catch background auto-sync inserts, but it
cannot prevent refresh-resurrection (an `update`, not an `insert`), needs awkward
user-resolution in job context, and risks blocking the takedown's own writes.
Trait-level is more precise.

### 4b. Before-scrape guard — `EnsurePlatformAvailable` route middleware

Josh's decision: a disabled platform must not be *contacted* at all, not merely
blocked at persistence. A new route middleware (`platform.available`, alias in
`bootstrap/app.php`) runs before the controller (hence before any scrape). It
reads the platform from an explicit middleware parameter or the route's
`->defaults('platform', …)`, resolves the acting user from the `professional`
request attribute (set by `LoadCurrentUser` in the `user.api` group, which
precedes it), and 503s when disabled.

It is applied to the **primary `/connect` family** — a bounded, enumerable set
that includes essentially every platform's connect action:

- Bespoke: `fresha`, `square`, `instagram` (needs `:instagram` param — its route
  sets no default), `apple/music`, `apple/podcast`.
- The events loop (`eventbrite`, `humanitix`).
- The registry-driven generic loop (`GenericPlatformController::connect`) — this
  covers Skool and every other simple/single-selection platform, since their
  `/connect` is emitted here.

**Deliberate v1 limitation (documented, not a bug):** the before-scrape guard
covers the `/connect` flow only. Other scraping verbs that are NOT `/connect` —
Shop `/brands` + `/products`, Booking/Reservations `/detect`, OnlineOrdering
`/entries`, CustomLinks `/links`, Menu `/refresh` — are still fully covered by
the 4a persistence net (nothing persists/shows), but a disabled platform may
still be scraped once on those secondary flows. Extending the middleware to those
verbs is a noted follow-up; kept out of v1 to avoid a sprawling, fragile change
across ~15 heterogeneous endpoints.

## 5. Component 2 — Takedown job

`App\Jobs\Platforms\ReconcilePlatformTakedownJob` — `ShouldQueue`, declares
`$backoff` (required by `JobHygienePolicyTest`), `->afterCommit()` on dispatch
(never a typed `public bool $afterCommit` property — trait conflict).

Signature: `__construct(string $platform, ?string $segmentId = null)`

- **Global** (`segmentId === null`): chunk
  `IntegrationConnection::where('platform', $platform)->active()`, set
  `is_active = false`, and per-model `->save()` so the existing
  `IntegrationConnectionObserver` fires each affected site's cache-bust. We reuse
  the proven per-user-toggle path rather than a bulk `UPDATE` that would need the
  observer's cache-invalidation logic re-implemented.
- **Segment** (`segmentId` set): resolve members via
  `SegmentResolver->userIds($segment)`, then apply the same
  `platform + active + whereIn(user_id, …)` flip, chunked.

**Scale note:** on the Cloud dev env `QUEUE_CONNECTION=sync`, so this runs inline
on the staff request. Acceptable at pre-beta volume; a bulk-update + batched
cache-purge optimisation is a documented follow-up if a platform ever holds many
thousands of connections.

## 6. Component 3 — Triggers

**6a. Availability upsert (required).**
In `StaffFeatureAvailabilityController::upsert`, after the rule is saved and
`FeatureAvailability::flush()` is called: if `mode === 'disabled'` AND
`feature_key` matches `^integration\.(.+)$` AND the captured platform is a real
`PlatformRegistry` entry → dispatch `ReconcilePlatformTakedownJob($platform,
$rule->segment_id)`. `enabled` or `delete` → dispatch nothing (no
auto-reactivate, per §2).

**6b. Segment member-add (completeness — cuttable).**
In `StaffSegmentController::addMembers`: if the target segment carries any
`disabled` `integration.*` rule, dispatch a takedown for the newly-added members
so joining a disabled segment later also takes their content down. Without this,
"full segment takedown" is only correct at rule-write time. This is a discrete
unit and may be deferred without affecting 6a.

## 7. Re-enable semantics

Re-enabling (setting a rule to `enabled` or deleting it) does **not** reactivate
anything. The connect guard stops blocking, so a user can reconnect — and
`writeConnection()` sets `is_active=true` again (user-driven reactivation). The
underlying rows were never deleted, so reactivation is always safe. No schema
column is needed to distinguish "staff-disabled" from "user-disabled" precisely
because we never auto-flip-back.

## 8. Interactions & edge cases

- **Per-professional staff toggle** (`StaffIntegrationManagementController`) sets
  `is_active` directly via `->save()`, NOT through `writeConnection()`, so it is
  **not** availability-gated. This is intentional: it stays an explicit staff
  override (staff can re-enable one user's connection even while a platform is
  globally disabled). Called out so it isn't mistaken for a gap.
- **Refresh scheduler** uses `dueForRefresh` (which is `->active()`), so
  taken-down (`is_active=false`) connections are not even due for refresh —
  belt-and-braces on top of the connect guard's resurrection prevention.
- **Cache freshness:** the upsert already calls `FeatureAvailability::flush()`
  (bumps the version key) before dispatch, so the connect guard sees the new rule
  with no 60s lag.
- **`is_active` observer guard (SEC-1):** the takedown's per-model save re-runs
  the model's `saving` guard (PlatformRegistry check). Platform is valid, so this
  passes; test authors must bind any platform mocks before touching connections
  (see the IntegrationConnection guard-test-timing note).

## 9. What does NOT change

- No migration / schema change.
- `GET /platforms/meta` already returns the `availability` map; the dashboard
  already greys unavailable cards. Untouched.
- `FeatureAvailability` read logic, `SegmentResolver`, and the public payload
  view are all unchanged.

## 10. Testing (Pest, Feature)

- **Before-scrape (middleware):** connect → **503** when the platform is globally
  disabled, asserting the platform scraper was NOT invoked (e.g. bind a spy for
  `SkoolScraper` and assert zero calls). Covers one generic-loop platform (Skool)
  and one bespoke (`fresha`).
- **Persistence net (trait):** a write path while disabled aborts 503 and creates
  no row — covers a non-`/connect` verb (e.g. Shop `addBrand`) to prove the net
  catches what the middleware doesn't.
- Connect → allowed when `enabled` / when no rule exists (absence = available).
- Connect → 503 for a user IN a disabled segment; allowed for a user NOT in it.
- Global takedown: flips `is_active=false` on all connections of the platform;
  asserts cache-bust fired; asserts `payload`/row/`deleted_at` untouched (no data
  loss).
- Segment takedown: flips only segment members' connections; non-members
  untouched.
- Re-enable does **not** reactivate; a subsequent user reconnect sets
  `is_active=true`.
- A refresh/`writeConnection` while still disabled does **not** resurrect a
  taken-down row.
- (If 6b kept) adding a member to a disabled segment takes down that member's
  existing content.

## 11. Follow-ups (not this spec)

- Segment-criteria expansion (location, relative tenure, IG-follower count,
  analytics-based) — separate brainstorm/spec.
- Extend the before-scrape middleware (4b) to the non-`/connect` scraping verbs
  (Shop `/brands`+`/products`, Booking/Reservations `/detect`, OnlineOrdering
  `/entries`, CustomLinks `/links`, Menu `/refresh`).
- Optional gating of background auto-sync direct-writes.
- Optional bulk-update + batched-purge optimisation of the takedown job at scale.

## 12. Rollout / verification

- Ship behind the normal branch → `development` deploy (serves both API domains).
- Verify against real Postgres, not just the SQLite test mirror (JSONB/CHECK
  drift): confirm a `disabled` rule 503s a live connect and drops content.
- No migration to apply.
