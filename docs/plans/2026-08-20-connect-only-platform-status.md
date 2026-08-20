# Connect-only platform status — plan (growing list)

**Status:** draft — scoped, not started. Owner will keep adding items; nothing
below has been built yet.

## The problem (diagnosed live, 2026-08-20, gsnwilliams/natalieannehair)

Connecting Instagram natalieannehair auto-placed TikTok + Facebook via the
router. Both connections work fine but sit at `last_refresh_status: 'pending'`
forever, which the dashboard renders as a permanent "syncing" badge.

Root cause: **there is no first-class concept of "this surface is
connect-only — nothing to sync, that's fine."** 17 catalog surfaces declare
`capabilities.connect` with no `capabilities.fetch`:

```
bandcamp.store   discord.server   facebook.profile   kick.channel
linkedin.profile medium.profile   nowbookit.reserve  opentable.reserve
reddit.profile   resdiary.reserve snapchat.profile   strava.club
telegram.channel threads.profile  tiktok.profile     twitch.channel
x.profile
```

For these, no fetch strategy exists to ever enrich the row — and the three
places that create/refresh a connection each guess differently about what
that means, so the SAME underlying situation (a healthy connect-only
connection) renders as three different — all wrong — states depending on
which lane touched it last:

| Write site | What it does today | Resulting status | Dashboard shows |
|---|---|---|---|
| `SourceReconciler::reconcile()` (router auto-place — `app/Routing/SourceReconciler.php:436`) and `SuggestionApplier::apply()`/`applyDirect()` (accept-a-suggestion) | Stamps `'pending'` unconditionally at create. Nothing ever dispatches a fetch (correctly, per F9/F14's `routing_class==='content' && fetch capability` gate) — but nothing writes a terminal status either. | **stuck `pending` forever** | "Syncing…" forever |
| `GenericPlatformController::connect()` → `ConnectFetchJob` (manual connect via the sheet) | Dispatches `ConnectFetchJob` unconditionally. The job resolves `connectFetchStrategy()`, finds none, calls `markTerminal($connection, 'error', 'unsupported_platform')`. | `'error'` | "Attention" — a **false alarm**, nothing is actually broken |
| `RefreshController::refresh()` (manual "Refresh" click) → `RefreshConnectionJob` → `PlatformRefresher::refresh()` | Same shape: `$strategy === null` → `recordFailure($connection, 'unsupported_platform', 'error')`. | `'error'` | Same false "Attention" |

Also relevant: `IntegrationConnection::scopeDueForRefresh()` already excludes
`'pending'` rows on the theory that pending means "some other job owns this
row right now" — true for fetch-capable surfaces, false for these 17, so the
stuck rows are invisible to the hourly refresh cron AND (per its own
docblock) to `CheckPlatformRefreshBacklogCommand`'s stuck-pending alarm,
which only *alerts*, never remediates.

There's already a precedent for "pending as an intentional resting state" in
the codebase — custom link-card rows are explicitly carved out of the
backlog alarm for exactly that reason (`IntegrationConnection.php:383`). The
fix is to extend that same recognition to connect-only catalog surfaces,
consistently, everywhere a connection can be created or refreshed.

## Task list

- [ ] **T1 — One source of truth for "connect-only."** A single helper (e.g.
  `CompiledCatalog::surface($key)['capabilities']['fetch']` presence, or a
  small `PlatformFetchability` service) that every write site below calls
  instead of re-deriving the answer three different ways.

- [ ] **T2 — Fix the three write sites to agree on a terminal, honest
  status** for a connect-only surface:
  - `SourceReconciler::reconcile()` / `SuggestionApplier::apply()` /
    `applyDirect()`: never stamp `'pending'` for a connect-only surface —
    write whatever status makes the dashboard read "connected" immediately
    (likely: leave `last_refresh_status` null, matching how a never-synced-
    but-fine row already renders).
  - `ConnectFetchJob::handle()`: the `$fetch === null` branch currently
    always calls `markTerminal(..., 'error', 'unsupported_platform')` — this
    conflates two genuinely different cases: (a) a platform key the registry
    doesn't know at all (real error) vs (b) a known, connect-only platform
    with no fetch strategy by design (not an error). Split them.
  - `PlatformRefresher::refresh()`: same split for its `$strategy === null`
    branch.

- [ ] **T3 — Backfill existing rows.** Every connect-only connection created
  before this fix — across the whole account base, not just test accounts —
  is sitting at either a stuck `'pending'` or a false `'error'`. Needs a
  one-off command (`partna:reset-test-user`-style, but a genuine fix not a
  wipe) that re-stamps every connection on the 17 affected surfaces to the
  new honest status. Scope: count live rows per surface on dev + prod before
  writing the command so the blast radius is known up front.

- [ ] **T4 — Dashboard: verify the badge is actually right once the backend
  is fixed.** `platforms.ts`'s `status` derivation already falls through to
  `"connected"` for anything that isn't `pending`/`action_needed`/error'd —
  confirm live once T2 ships, don't assume.

- [ ] **T5 — Dashboard: "last synced" text.** `lastSync` reads
  `health?.lastRefreshedAt`, which will be permanently null for these
  surfaces (there's nothing to sync). Decide the right copy for that state —
  blank, or something like "Connect-only — nothing to sync" — rather than
  leaving it ambiguous.

- [ ] **T6 — Audit the manual "Refresh" affordance.** Didn't find a live
  dashboard control wired to `RefreshController::refresh()` in the blocks
  swept so far — confirm whether it's reachable anywhere in the current UI,
  and if so, hide/disable it for connect-only surfaces (there's nothing to
  refresh) rather than let it produce another false error.

- [ ] **T7 — Tests.** Pin the new behavior per write site: a connect-only
  surface auto-placed by the router settles to the honest status, not
  `pending`; a manual connect to one settles the same way, not `error`; a
  fetch-capable surface's behavior is provably unchanged (regression guard
  on F9/F14's existing coverage).

- [ ] (space for more — owner adding items)

## Open questions (owner to decide before T2 starts)

- Exact terminal value: leave `last_refresh_status` **null**, or add a new
  explicit enum value (e.g. `'not_applicable'`) so "never synced because
  nothing to sync" is distinguishable in the data from "never synced yet,
  first refresh hasn't run"? Null is the smaller change; an explicit value
  is more honest at the schema level. Enum values live in code today
  (`"ok" | "unavailable" | "error" | "pending" | "action_needed" | null` in
  `platforms.ts:177`), not a DB CHECK constraint, so either is a same-size
  migration cost.
- Does `IntegrationNotifier`/`PlatformHealthNotifier` need a matching
  exclusion, so a connect-only connection never generates a "your connection
  needs attention" email/notification? Not yet checked — needs its own pass.
