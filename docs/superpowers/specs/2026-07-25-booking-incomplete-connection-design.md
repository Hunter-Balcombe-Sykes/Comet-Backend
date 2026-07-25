# Incomplete booking connections — surfacing the pending selection

**Date:** 2026-07-25
**Status:** Design, awaiting approval
**Scope:** Backend only. Supplies the API surface a dashboard prompt consumes; the prompt itself is frontend work tracked separately.

## Problem

An auto-harvested Fresha connection is *connected but empty*, and nothing in the system says so.

Observed on `jhuntertheking@gmail.com` (handle `simondoyle`, user `019f987e-3cc1-7038-b4f6-77b52140226f`, built 2026-07-25 08:57 UTC):

```
site.platform_connections  platform=fresha  is_active=true  last_refresh_status='ok'
payload = { "url": "https://www.fresha.com/book-now/anseo-studio-v0v92jna/…",
            "source": "instagram",
            "selection": null }
site.services rows: 0
```

`InstagramAutoSync::resolveWrite` (`app/Services/Platforms/InstagramAutoSync.php:325-341`) harvested the Fresha link from the Instagram bio and wrote `{url, selection: null, source: 'instagram'}`. It saves the link; it never scrapes the venue's service menu. `GoogleBusinessAutoSync::resolveBookingWrite` (`:296-320`) writes the identical shape with `source: 'google-business'` — its own docblock calls the result *"a PENDING Fresha (url only, 'Finish setup')"*.

The row then cannot self-heal. `FreshaFetch` (`app/Services/Platforms/Strategies/Fetch/FreshaFetch.php:36-39`) opens with:

```php
if (! $url || ! is_array($selection)) {
    throw new FetchNotModifiedException('fresha');
}
```

so every scheduled refresh 304s. That guard is correct and must stay — it stops a transient scrape failure from wiping a real selection — but it means a selection-less row remains selection-less forever.

Three downstream consequences, all confirmed against the live dev API:

1. **The public sitepage ships an empty page.** `SitepageDataResolverService::presentPageIds` (`app/Services/PublicSite/SitepageDataResolverService.php:194-249`) marks a page present from connection existence alone, so `services` enters `page_order` and `ranked_actions` gains a `booking-services` page action — pointing at a page with no menu and no services.

   ```
   GET /api/public/profiles/simondoyle
     pageOrder:     ["home","watch","services","events","contact"]
     rankedActions: [{"id":"booking-services","kind":"page","pageId":"services"}]
   GET /api/public/profiles/simondoyle/integrations
     fresha: [{"payload":{"url":"…","selection":null}}]
   ```

2. **The dashboard reports success.** `BookingController::statusFor` (`app/Http/Controllers/Api/Platforms/BookingController.php:140-175`) returns `connected: true` off row existence. It has no notion of incomplete. The one surface that reveals the state is `/account/booking/platform`, which is not in the sidebar.

3. **The owner is never asked.** Nothing prompts them to complete the selection.

This is the same class of defect FOUND-25/W9 fixed for `shop`, whose gate now sits at `SitepageDataResolverService.php:234-248` with the comment *"An active connection alone isn't real content"* and *"without this exclusion, page-presence says 'shop' is present while filterPayload() rejects the same pending brand, shipping an empty Shop page to the CDN."* Fresha has no equivalent.

## Design decision 1 — two kinds of pending, deliberately not merged

The codebase already has a pending state for connections: `last_refresh_status='pending'` plus `payload.connectPendingAt` / `connectMode` / `teamMenu`, written by `FreshaController::connectDeferred` (`:226-265`) and resolved by `ConnectFetchJob` → `FreshaConnectFetch`.

**It must not be reused here.** That state means *a job is in flight; expect an answer in seconds*, and it is policed by `StrandedPendingWindow::MINUTES = 5`. That class documents why the value is a const rather than config:

> A published frontend-contract fact ("a pending row untouched for more than 5 minutes reports failed" — `docs/frontend-contracts/2026-07-23-platform-connect-async.md`), so a const and not a `config/partna.php` knob: an env-tunable value would let one environment silently contradict the documented API.

Expressing *waiting on a human* as `status='pending'` would make every harvested connection report `failed` five minutes later. Widening the window to accommodate it would break the documented async-connect contract for all six bespoke platforms.

So:

| | machine-pending | human-pending (this spec) |
|---|---|---|
| meaning | job in flight | awaiting owner input |
| duration | seconds | days |
| marker | `last_refresh_status='pending'` + `connectPendingAt` | `last_refresh_status='ok'` + `selection === null` + `payload.source` |
| timeout | 5 min → `failed` | none |

No migration. No new column. The distinguishing data is already written on every auto-harvest path — `payload.source` is `'instagram'` or `'google-business'`, and absent on every user-initiated write. It is currently written by two services and read by none.

## Design decision 2 — declare completeness once, on the descriptor

Approach considered and rejected: add a bespoke `setup` check to `BookingController::statusFor` and a bespoke fresha branch to `presentPageIds`. That works, but it makes three hand-rolled answers to one question (shop's column, fresha's implicit null, plus the new branches) — and the shop gate already carries a *"the two predicates MUST stay in lockstep"* warning precisely because that coupling is manual.

Instead, add one declarative seam to `PlatformDescriptor`, modelled directly on `requiresCapability()` / `availableFor()` (`app/Services/Platforms/Registry/PlatformDescriptor.php:513-550`) — an opt-in closure predicate that defaults to always-true with a single documented reader.

```php
/**
 * Declare when a connection of this platform carries real, publishable content.
 * Default (no call) = an active row is always complete, which is true for every
 * url-only platform (square, the reservations family) and every platform whose
 * connect writes its payload in full (eventbrite, humanitix, the social family).
 *
 * Opt in only where connect can legitimately leave a row half-built — today
 * fresha (auto-harvest saves a url with no selection) and shop (a brand can
 * exist with zero chosen products).
 *
 * @param  Closure(IntegrationConnection): bool  $predicate
 */
public function complete(Closure $predicate): self;

public function isComplete(IntegrationConnection $connection): bool
{
    return $this->completenessGate === null || ($this->completenessGate)($connection);
}
```

Registered in `PlatformRegistryServiceProvider` beside the existing fresha lines (`:349-374`):

```php
$r->get('fresha')->complete(fn (IntegrationConnection $c): bool =>
    is_array($c->payload['selection'] ?? null));
```

A future booking platform with a picker becomes one line, matching the registry's stated principle that adding a platform is one descriptor.

**Shop is folded in but not rewritten.** Shop's existing `ShopProduct::exists()` predicate moves verbatim into a `complete()` closure so there is one call site, but its semantics — including the `connect_status != 'pending'` handling and the `whereNull()->orWhere()` NULL-safety noted in that block's comment — are preserved exactly. This is a call-site consolidation, not a behavioural change to shop. `ArchitectureSystemConstraintsTest`-style pinning is added to prove the shop predicate still evaluates identically before and after.

## Changes

### 1. `PlatformDescriptor::complete()` / `isComplete()`

New seam as above. `app/Services/Platforms/Registry/PlatformDescriptor.php`.

### 2. `GET /api/platforms/booking/status` — expose the state

`BookingController::statusFor` (`:140-175`) gains a `setup` block:

```json
{ "connected": true, "provider": "fresha", "name": null,
  "url": "https://www.fresha.com/a/anseo-studio-v0v92jna",
  "setup": { "complete": false,
             "reason": "awaiting_selection",
             "seededFrom": "instagram",
             "seededAt": "2026-07-25T08:57:43+00:00" } }
```

- `complete` — `$descriptor->isComplete($row)`.
- `reason` — `null` when complete, else `"awaiting_selection"`. A string, not a bool, so a future second incompleteness cause is additive.
- `seededFrom` — `payload.source`, one of `instagram` / `google-business` / `null` (`null` = user-initiated). This is what lets the prompt say *"we found this on your Instagram"* rather than a generic nag.
- `seededAt` — `created_at`.

This is the endpoint the Booking page already calls on mount (`useCategoryStatus`), so the prompt needs no extra request.

**Bug fixed in the same pass:** `statusFor` uses `->first()` with no `is_active` filter, so a staff-disabled Fresha row currently reports `connected: true` on the dashboard while being invisible publicly. Add the `active()` scope.

`setup` is additive; existing keys are unchanged, so no frontend breaks on deploy.

### 3. `GET /api/platforms/fresha/team` — read-through cache

Already the correct data source for "who are you": it needs only the stored URL, no prior selection, and returns `{employeeId, displayName, jobTitle, avatarUrl, rating}[]` via `FreshaScraper::extractTeam` (`app/Services/Platforms/FreshaScraper.php:113-131`).

But `FreshaController::team()` (`:332-347`) live-rescrapes on **every** call. That is fine behind a deliberate connect click and wrong behind a prompt that fires each session. Make it a read-through cache into `payload.teamMenu` with a TTL (`config('partna.platforms.fresha.team_cache_seconds')`, default 24h), scrape only on miss or `?refresh=1`.

This also closes an acknowledged TODO — `FreshaConnectFetch.php:46-50` records *"team() currently live-rescrapes on every call"*, dropped from W8 and still open. The write reuses `writeConnection(mergePayload: true)` so `url` and `selection` are untouched.

No new endpoint is needed for the picker: `POST /platforms/fresha/selection {employeeId}` already resolves and projects on submit.

### 4. Public sitepage — a working Book-now instead of an empty page

Two edits, both reading `isComplete()`:

**`SitepageDataResolverService::presentPageIds`** (`:205-249`) — replace the inline `if ($platform === 'shop' && …)` with a descriptor lookup applied to every platform, so an incomplete connection no longer marks its page present. Shop's behaviour is unchanged; fresha gains the gate.

**`SiteActionsService::pool`** (`app/Services/PublicSite/SiteActionsService.php:138-146`) — the existing `elseif` already emits `booking-services` as an *external* action when a live booking envelope has a URL. Extend its fallback to the harvested Fresha URL when the connection is incomplete:

```php
if (isset($present['services'])) {                    // unchanged — real content
    $out[] = $this->entry('booking-services', 'page', …, pageId: 'services');
} elseif (($booking['state'] ?? null) === 'live') {    // unchanged — owner's booking block
    …
} elseif ($url = $this->incompleteBookingUrl($userId)) {
    $out[] = $this->entry('booking-services', 'external', …, url: $url);
}
```

Net effect for `simondoyle`: `services` leaves `page_order`; `ranked_actions` keeps a `booking-services` entry that opens the real salon booking page. No empty page reaches the CDN, and no traffic is lost while the owner takes days to respond.

The harvested URL is already public — `PublicIntegrationConnectionResource::filterPayload` allowlists `'fresha' => ['url','selection']` (`:116`) — so this exposes nothing new. `payload.source` stays excluded, as that file's comment at `:250` requires.

**Cache invalidation — confirmed covered.** Completing a selection changes `page_order`, so the sitepage cache must be purged on the `saveSelection` write. `IntegrationConnectionObserver::saved` (`app/Observers/Core/IntegrationConnectionObserver.php:43-53`) already calls `IntegrationConnectionCacheRefresher::refresh()` on `wasRecentlyCreated || wasChanged('payload') || wasChanged('display_settings') || wasChanged('is_active')`, and `saveSelection` writes the payload via `updateOrCreate`. No new invalidation is needed; the plan pins it with a test rather than trusting it.

The same observer is why the team-roster cache (change 3) writes with `saveQuietly()` — a payload change would otherwise purge the CDN for a private dashboard roster that appears nowhere in the public payload.

### 5. `booking:reconcile-incomplete` — backfill command

New Artisan command. Finds every live, active Fresha connection where `isComplete()` is false and, per row: reports it, warms `payload.teamMenu`, and (with `--invalidate`) re-renders the sitepage cache so the page-presence gate takes effect on already-published sites.

`--dry-run` is the default. Needed because `simondoyle` and any other already-built account will not otherwise pick up the change — the refresh cron skips these rows by design (`FreshaFetch` 304).

## Out of scope

- **Square.** `SquareController::connect` (`:59-63`) writes `['url' => $url]` and stops — no team, no selection, no projection. A Square row is complete the moment it has a URL, so there is nothing to prompt for. It simply never calls `complete()`.
- **The frontend prompt.** Modal, dismissal, re-fire-per-session, and the inline card are frontend work. This spec's contract is `setup.complete` / `setup.reason` / `setup.seededFrom` plus the existing `/team` and `POST /selection`.
- **The deferred-connect state machine.** `connectDeferred`, `ConnectFetchJob`, `FreshaConnectFetch`, `/connect/status`, and `StrandedPendingWindow` are untouched.
- **`WebsiteLinkHarvester`.** It writes nothing — it is a pure classifier (`app/Services/Platforms/WebsiteLinkHarvester.php:249-251`) whose output `GoogleBusinessAutoSync` consumes. Covering the Google Business path covers it.
- **`FreshaFetch`'s 304 guard.** Correct as written; left alone.

## Testing

Tests run SQLite while production is Postgres, so any constraint-bound write is verified against `supabase/migrations/` DDL as well as a green suite. No DDL changes here, which limits that exposure.

- **Unit** — `isComplete()` default-true for a descriptor with no `complete()` call; fresha true/false across `selection: null`, `selection: []`, a full blob; shop predicate evaluates identically pre- and post-consolidation, including a `connect_status='pending'` brand with a saved product selection.
- **Feature, route-level** (never direct controller calls — that antipattern has hidden live bugs here before):
  - `GET /platforms/booking/status` returns `setup.complete=false`, `reason='awaiting_selection'`, `seededFrom='instagram'` for a harvested row; `complete=true`, `reason=null`, `seededFrom=null` after `POST /selection`.
  - A staff-disabled (`is_active=false`) Fresha row now reports `connected: false`.
  - `GET /public/profiles/{handle}` omits `services` from `pageOrder` for an incomplete fresha row and emits `booking-services` as `kind: "external"` with the harvested URL; includes the page once a selection exists.
  - Shop page-presence is byte-identical before and after, including the pending-brand case.
  - `GET /platforms/fresha/team` scrapes once, serves the second call from `payload.teamMenu`, and leaves `url` / `selection` untouched.
- **Command** — `booking:reconcile-incomplete --dry-run` writes nothing; without it, warms `teamMenu` only.
- **Pipeline** — no new directory under `app/Services/`, `app/Http/Controllers/Api/`, `app/Jobs/`, or `tests/Feature/`, so `AuditPipelineIntegrityTest` needs no rewiring. Confirm before merge.

## Risks

| Risk | Mitigation |
|---|---|
| Consolidating shop's gate regresses a scarred code path | Move the predicate verbatim; pin with a before/after equivalence test incl. the pending-brand case. If review is uneasy, ship fresha through the seam first and consolidate shop in a follow-up. |
| Removing `services` from `page_order` looks like a regression on already-published sites | It is the intended fix — the page was empty. The Book-now external action preserves the click. Called out in the changelog. |
| Cached sitepages keep the stale `page_order` | `--invalidate` on the backfill command; verify the invalidation path fires on `saveSelection`. |
| `teamMenu` cache serves a stale roster after the owner leaves the venue | 24h TTL plus `?refresh=1`; `POST /selection` re-scrapes server-side regardless, so a stale pick still 404s correctly (`FreshaController::saveSelection:375-386`). |
| `payload.source` becomes load-bearing having never been read | Only ever informational (`seededFrom`); `isComplete()` keys on `selection`, not `source`, so a missing `source` degrades the prompt's copy and nothing else. |

## Decided: `/integrations` does not consult the seam

`PublicIntegrationController` keeps emitting incomplete connections. Deliberate, and the one place where completeness must **not** gate output: the Book-now action carries `url` in `ranked_actions`, but the sitepage renderer reads the same URL from `/integrations`, and dropping the row would strip it. `page_order` and `/integrations` are allowed to disagree here precisely because the public payload has no service menu to show either way — the row's only public content *is* the URL.

Recorded explicitly so a later reader doesn't "fix" the inconsistency by wiring the seam into `filterPayload`, which would break the Book-now button.
