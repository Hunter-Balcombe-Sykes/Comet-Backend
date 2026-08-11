# Auto-routed Fresha connections: name-match selection with storewide fallback

**Date:** 2026-08-10
**Status:** Design agreed, revised after independent review, not yet implemented
**Origin:** Finding F7 of `docs/reviews/2026-08-10-instagram-build-wave-RESULTS.md`
**Revision:** v3 — ready for an implementation plan.
v2 corrected three implementation-blocking errors and several factual overstatements found by three
independent reviews (premise correctness, over-engineering, scale/ops). v3 adds the one component v2
omitted (`FreshaAutoSelector`) and closes every remaining open decision. Corrections are marked **[v2]**
/ **[v3]** where a reader of an earlier revision would otherwise be misled.

**All decisions are closed. Nothing in this spec requires a judgement call at implementation time.**

## Problem

A Fresha link found in an Instagram bio is auto-routed by `LinkRouter::seedBooking`, which writes a
connection row and stops. The payload is `{url, selection: null, source: "instagram"}`. No service menu
is ever fetched.

This is not a missing function call — it is a **state collision**. `selection: null` encodes "a human
still has to choose whose menu this is", because a Fresha URL points at a salon, not a person.
Team-mode dashboard connects sit in that state legitimately: they carry a `teamMenu` snapshot and a
picker aimed at them, so the choice gets made. The auto-routed row lands in the *same* state with no
`teamMenu` and no picker — waiting for a human who was never asked.

**[v2] What the user actually loses.** v1 claimed "a booking link with no prices, durations or service
list". That overstated it:

- Prices reach the public page through `payload.selection` via
  `PublicIntegrationConnectionResource`'s `'fresha' => ['url','selection']` allowlist (`:135`) — **not**
  through `site.services`. `SitepageDataResolverService` filters `whereNull('source')` at `:286` and
  `:925-927` (*"Fresha projections belong to the booking surface, never the services section"*). So
  writing the **selection** is the win; `projector->sync()` is the dashboard-side effect that lets the
  owner manage and hide services.
- The Services page is withheld deliberately (`presentPageIds()`, `:225-240`) and
  `SiteActionsService::incompleteBookingUrl()` (`:300-330`) keeps a working Book-now href by design.

**This design is an improvement, not the repair of a broken page.** Scope it accordingly.

Two consequences are real and verified:

1. `FreshaFetch::fetch()` (`:37-39`) throws `FetchNotModifiedException` whenever `payload.selection` is
   not an array. The hourly `integrations:refresh` picks these rows up on the 2-day
   `refresh.intervals.fresha` TTL and 304s every time. **No automatic path can populate services.**
2. The stored URL is the wrong shape. Working rows are `fresha.com/a/<slug>`; auto-routed rows are
   `fresha.com/book-now/<slug>/all-offer?…` because that is what people put in their bio.
   `FreshaScraper::slugFromUrl()` is `#/a/([a-z0-9-]+)#i` (`:53-56`) and returns null for that shape.

**[v2] v1's third consequence was wrong.** It claimed "nothing surfaces it". A completeness gate already
exists — `->complete(fn ($c) => is_array($c->payload['selection'] ?? null))`
(`PlatformRegistryServiceProvider.php:359-365`, whose comment names this exact auto-harvested row) — and
`ReconcileIncompleteBookingCommand` exists purely to report these rows, printing `seeded=<payload.source>`.
These rows are already visible to anyone who looks.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Populate `core.users.last_name` from the Instagram `fullName`; **add no tier restriction** to `FreshaStaffMatcher` | Owner's call, made with the false-match risk stated (see Risks). **[v2]** v1 said "allow all tiers", which is a no-op — `match()` has no tier gate today (`:87-96`). The decision is to *not add one* |
| D2 | Reuse `ConnectFetchJob` rather than writing a parallel job | Calling the fetch strategy is a handful of its 314 lines; the bulk is generic hardening a new job would re-earn |
| D3 | Suppress the success notification via a `systemInitiated` flag | Pre-account users have no email and never asked for this connection |
| D4 | Scope: Instagram-origin Fresha only, new builds. No backfill, no Google Business | Keeps blast radius contained |
| **D5** | **[v2]** Gate the dispatch on an explicit origin flag carried in `RouteContext`, not on the call site | `seedBooking` cannot tell who called it — see below. Without this, D4 is asserted rather than real |
| **D6** | **[v2]** Make the scheduled refresh a self-heal backstop for failed auto-connects | A failed job otherwise launders itself back to `ok` and is never repaired — see Error handling |

**Not adopted:** a parallel job (D2); gating the notifier on `status='unclaimed'` (wider blast radius);
restricting auto-selection to the exact match tier (rejected under D1).

## [v2] The origin problem — why the dispatch cannot live where v1 put it

`LinkRouter::route()` has **four** callers, and `seedBooking` cannot distinguish them:

```
app/Http/Controllers/Api/Platforms/CustomLinksController.php:89   ← logged-in user pasting a URL
app/Jobs/Platforms/LinkInBioScanJob.php:112                       ← Instagram origin (Linktree unroll)
app/Services/Platforms/InstagramAutoSync.php:115                  ← Instagram origin (direct bio link)
app/Services/Platforms/CustomLinkSeeder.php:55                    ← re-entry, origin varies
```

`CommerceProbeJob:53-56` states it outright: *"LinkRouter::route() carries no origin parameter, so which
of those it was is genuinely not known here."* v1 dispatched unconditionally from `seedBooking`, which
would fire for a logged-in user pasting a Fresha URL — exactly the case where D3's rationale ("no email,
never asked") is false.

**Nor can origin be inferred from the payload.** `resolveWrite()` hardcodes `'source' => 'instagram'` for
every platform and every caller (`BuildsAutoSyncFindings.php:511, :516, :521`). A manually pasted Fresha
link already claims Instagram provenance. **That key cannot be used to bound scope.** (It does still
distinguish *router-written* from *dashboard-written* — dashboard connects write no `source` key at all,
verified across all nine Fresha rows on dev — which is why the audit trail below relies on that weaker
distinction and nothing more.)

**Both Instagram origins must dispatch.** The motivating cases came from a **Linktree**, not the direct
bio: `simondoylehair` and `jesshairstylist` both have `payload.website = linktr.ee/…`, and their Fresha
links were routed inside `LinkInBioScanJob`. Dispatching only from `InstagramAutoSync` would miss every
real case observed so far.

**D5:** add `public readonly bool $autoConnectBooking = false` to `RouteContext`. `seedBooking` consults
the flag. `RouteContext` is the natural home — it already carries per-run state (`seenPlatforms`, the
probe budget), and it is constructed once per run at the origin-aware seam.

**The default is false, which makes this fail safe:** any construction site not explicitly marked
Instagram-origin simply never auto-connects. There are exactly four sites:

| construction site | origin | `autoConnectBooking` |
|---|---|---|
| `InstagramAutoSync.php:78` | Instagram direct bio link | **true** |
| `LinkInBioScanJob.php:85` | Instagram → link-in-bio unroll | **true** |
| `InstagramConnectionSeeder.php:262` (`autoSaveUnmatchedLinks`) | Instagram, re-routing unmatched links | **true** |
| `CustomLinksController.php:89` | **dashboard paste by a logged-in user** | **false** |

`CustomLinkSeeder::seed()` takes `$ctx ?? new RouteContext`, so a null context also defaults to false —
covering `CommerceProbeJob` and `RoutingController`, both of which should not auto-connect.

The third row is easy to miss and worth stating: `autoSaveUnmatchedLinks` builds a **fresh** context, so
without an explicit `true` an Instagram-origin link re-routed through that path would silently skip
auto-connect. Harmless today (a Fresha URL classifies as `booking` and so never reaches the unmatched
list), but it becomes a silent hole the moment classification changes.

> **Sequencing hazard.** `RouteContext` is being modified concurrently by the work in
> `2026-08-10-link-probe-host-dedupe-design.md` (adding `probesDenied`). These two changes touch the same
> file and the same class. **Land one before starting the other**, or use separate worktrees.

## [v2] Resolving the connection id

`ConnectFetchJob` needs a connection id. The write path exposes none: `BuildsAutoSyncFindings::write()`
is `void` (`:105-118`), `resolveBookingLink()` returns `{findings, unmatched, consumed}` (`:537-591`),
and `RouteResult::seeded()` carries no id (`RouteResult.php:46-49`). v1's `dispatch($row->id, …)` referred
to a `$row` that does not exist.

**Resolution:** after the XOR lock releases and the outcome is `seeded`, resolve the row with a single
query — `IntegrationConnection::where('user_id', $userId)->where('platform', 'fresha')->first()`. One
query, no new return plumbing through a trait shared with `GoogleBusinessAutoSync`. Threading an id back
through `write()`/`resolveBookingLink()`/`RouteResult` would widen exactly the blast radius D4 exists to
contain.

## Architecture

```
InstagramSourceGenerator::generate()
  ├─ [MOVED] fold name onto user  ← PersonNameParser  (MUST precede seed(); see Ordering)
  ├─ seeder->seed()
  │    └─ InstagramAutoSync / LinkInBioScanJob  (RouteContext: autoConnectBooking = true)
  │         └─ LinkRouter::seedBooking (fresha branch)
  │              ├─ FreshaScraper::canonicalUrl() applied inside resolveWrite()
  │              ├─ write payload {url, selection:null, source:"instagram"}
  │              └─ if seeded AND ctx->autoConnectBooking AND kill-switch off:
  │                   resolve row → stamp connectMode:"auto" → ConnectFetchJob::dispatch(id,'fresha',systemInitiated:true)
  └─ strip payload (PRIV-2)

ConnectFetchJob → FreshaConnectFetch::fetch()  [connectMode:"auto"] → fetchStorewide($auto = true)
   fetchMenu(url)  ── ONE scrape ──▶ { storeName, team, services }
        ├─ matchWithTier(user, team)
        ├─ matched ──▶ fetchEmployeeServices(slugFromUrl(url), employeeId)
        │                ├─ non-empty ─▶ mode:'employee' selection ─▶ projector->sync()
        │                └─ empty ─────▶ storewide
        └─ no match ─▶ mode:'storewide' selection ─▶ projector->sync()

BACKSTOP (D6): FreshaFetch::fetch() — when selection is null AND connectMode === 'auto',
               route to the same auto-selection path instead of throwing FetchNotModified.
```

The load-bearing property: **one scrape yields everything the storewide branch needs**, so every failure
after that point degrades to a working storewide selection rather than an error. The employee GraphQL
call happens only when a match lands.

## Components

### New: `App\Services\Profile\PersonNameParser`

Pure, dependency-free. `parse(string $fullName): array{displayName, firstName, lastName}`. Strips the
tagline after the first `|`, `–`, `—` or `•`; splits the remainder; first token → `firstName`, last token
→ `lastName` when ≥2 tokens remain, else `lastName = null`.

**[v2]** v1 justified this as "shared infrastructure beside `SectorTaxonomy`". That was wrong —
`SectorTaxonomy` has many call sites; this has one (`InstagramSourceGenerator`). It earns its own class
only because the separator rules carry six-plus unit cases worth isolating, not because it is shared. A
private method would be defensible. `app/Services/Profile/` already exists, so no new directory and no
audit-pipeline wiring is needed.

### [v2] URL canonicalisation — `FreshaScraper::canonicalUrl()`, called from `resolveWrite()`

`string → string`: rewrites `fresha.com/book-now/<slug>/…` → `https://www.fresha.com/a/<slug>`, returns
the input unchanged otherwise. Placed on `FreshaScraper` beside its existing sibling `stripLocale()`
(`:47-50`) — same signature, same regex-or-passthrough job. An **instance** method, matching that sibling,
resolved in `resolveWrite()` via `app(FreshaScraper::class)` — the same mechanism `socialUsername()` uses
for `FacebookNormalizer`, and for the same stated reason (the trait is shared by three classes with
unrelated constructors, so constructor injection is not available).

**Two placement corrections from v1:**

- **Not** in `app/Services/Platforms/Normalizers/`. Every member there implements the
  `array{username,url}|null` contract and is wired into `_capabilities.php` as `new UrlConnect(new
  XNormalizer)`. A `string → string` class in that folder reads as wired-up when it is not.
- **Call it from `resolveWrite()`, not `LinkRouter::seedBooking`.** That trait already carries the
  precedent: `socialUsername()` (`:565-583`) resolves `FacebookNormalizer` via `app()` *specifically
  because* the trait is shared by three classes with unrelated constructors — and the comment records
  that a per-class override is what once made `LinkRouter` silently write `username: ''` for every
  Facebook link. Normalising in `resolveWrite` canonicalises the URL for all three trait users, which is
  a pure improvement even where D4 says not to dispatch.

Canonicalising at **write** time matters beyond our own fetch: `GET /platforms/fresha/team` re-scrapes
from `payload.url` (`FreshaController.php:350, :362`), so the user's own recovery path needs a usable URL
too.

### Changed: `InstagramSourceGenerator`

The existing block (four lines, setting `display_name` **and** `first_name`) becomes a `PersonNameParser`
call that also sets `last_name`, **and moves above the `seed()` call**. See Ordering.

### Changed: `RouteContext` and the two Instagram-origin callers

Add `autoConnectBooking` (D5). `InstagramAutoSync::seed()` and `LinkInBioScanJob::handle()` construct
their `RouteContext` with it true. Mind the sequencing hazard above.

### Changed: `LinkRouter::seedBooking`

On a `seeded` Fresha outcome, when `ctx->autoConnectBooking` is true and the kill switch is off: resolve
the row, stamp `connectMode => 'auto'` on the payload, dispatch
`ConnectFetchJob::dispatch($id, 'fresha', systemInitiated: true)->afterCommit()`. A conflict, gate denial
or lock contention must not dispatch.

`connectMode` is stamped here rather than in `resolveWrite()` precisely because `resolveWrite` is shared
with non-Instagram callers.

Dispatching from the router is consistent with what it already does — `routeUnclassified` (`:121`) and
`seedShop` (`:236`) both dispatch `CommerceProbeJob`. The "no vendor call, no dispatch, DB only" rule is
from `BuildsAutoSyncFindings`' findings-*apply* path, not the router.

### Changed: `ConnectFetchJob`

Third constructor arg `public readonly bool $systemInitiated = false`, guarding the two
`$notifier->connected()` calls (`:144`, `:220`). The default keeps all three existing call sites
byte-identical. Under `systemInitiated`, `markTerminal` uses the neutral wording — its connect-flow copy
can surface to a user who claims the site weeks later.

Its docblock justifies the retry/dedupe tuning with *"a human is watching the modal, not a cron"* (`:35`,
`:55`, `:66`). That is no longer true of this caller, which has **no interactive retry path at all** —
which is precisely what makes the kill switch and D6 matter. Update it.

### [v2] Changed: `FreshaConnectFetch` — parameterise, do not fork

v1 proposed a third `fetchAuto()` method plus extracting the shared locked block. That contradicted D2's
own reasoning one level down. `fetchStorewide()` (`:160-250`) already receives `($connection, $url,
$payload)` and already does the availability check, scrape, XOR-locked projection, both lock-timeout
catches, and the selection compose. The auto path differs by roughly fifteen lines.

**Add `'auto'` to the mode whitelist (`:73-78`) as a discriminator, and route it to `fetchStorewide()`
with a flag.** No second copy means no extraction, and no drift.

Three shape requirements v1 left unstated:

- **Strip `connectMode` on success.** Both existing branches drop it (R3, `:128-130`, `:242-247`).
  Leaving `connectMode: 'auto'` in a completed payload strands a pending-window marker forever. Safe to
  strip, because on success `selection` is non-null and D6's backstop no longer needs the marker; on
  failure `markTerminal` never touches `payload` (`:293-297`), so it survives exactly where it is needed.
- **The employee selection must be nested.** `FreshaFetch` reads `$selection['employee']['employeeId']`
  and gates on `$selection['mode'] === 'employee'` (`:41-44`). A flat `employee` value would make every
  later refresh silently degrade to the whole-location menu with no error.
- **Guard `slugFromUrl() === null`** before the employee call — `fetchEmployeeServices(null, …)` is a
  type error.

Storewide selection shape, already established (`:233-240`):

```php
['url', 'storeName', 'mode' => 'storewide', 'employee' => null, 'services', 'hiddenServiceIds']
```

### [v3] New: `App\Services\Platforms\FreshaAutoSelector`

The auto-selection sequence — match, conditionally fetch the employee menu, compose the selection,
project — is needed in **two** strategies:

- `FreshaConnectFetch` (`connectMode: 'auto'`) — the immediate path.
- `FreshaFetch` — D6's self-heal backstop.

v2 described D6 as "~3 lines". **That was wrong, and repeated from review without checking.** The two
strategies have different dependencies:

```php
FreshaConnectFetch: scraper, projector, staffMatcher   // has the matcher
FreshaFetch:        scraper, projector                 // does not
```

Making `FreshaFetch` the backstop therefore means injecting `FreshaStaffMatcher` into it (and updating the
registry closure at `PlatformRegistryServiceProvider:333-336` that constructs it), **plus** getting the
compose-and-project sequence into a second class — i.e. duplication, the exact drift risk that cutting
`fetchAuto()` was meant to avoid.

**Extract it instead.** `FreshaAutoSelector` takes `(User $user, array $menu, string $url)` and returns
the composed selection plus the chosen `matchTier`, projecting via `FreshaServiceProjector`. Both
strategies call it. Dependencies: `FreshaScraper` (for `slugFromUrl` + `fetchEmployeeServices`),
`FreshaStaffMatcher`, `FreshaServiceProjector`. One home for the logic, unit-testable without either
strategy, and no second copy to drift.

This is the one component v2 omitted, and the reason a plan written against v2 would have guessed.

### Changed: `FreshaStaffMatcher` — `matchWithTier()`

`match()` returns `?string` (`:41`) and cannot report which tier fired. Add
`matchWithTier(): array{employeeId: ?string, tier: ?string}` with `match()` delegating to it.

**[v2]** v1 justified this as "`fetchTeam()` must stay untouched" — asserted, not argued; there are only
two callers (`FreshaController.php:379`, `FreshaConnectFetch.php:123`). The real justification is that D1
widened exposure against a stated false-match risk, and **the tier distribution is the only measurement
that lets D1 be revisited on evidence.** If that is not wanted, cut `matchTier` and `matchWithTier`
together.

### [v2] Audit trail — one key, not two

Write **`matchTier`** (`'exact'` | `'both-tokens'` | `'last-only'` | `null`) top-level in the payload,
filtered private by the `'fresha' => ['url','selection']` allowlist.

v1 also proposed `selectionSource`. **Cut it — it is derivable and lossier.** `selection.mode` is already
`'storewide' | 'employee'`, and the absence of a `source` key already marks a dashboard connect. Better,
dropping it makes the trail *more* informative: `mode: 'storewide'` + `matchTier: 'exact'` reads
unambiguously as "we matched but the per-employee fetch came back empty" — a state `selectionSource`
would have collapsed.

**[v3]** The backstop path (D6) writes the same `matchTier` key by the same rule — `FreshaAutoSelector`
owns it, so both callers get it for free. A repaired row is still distinguishable from a first-pass one
by `last_refresh_error` being non-null from the earlier failure.

Also emit one `Log::info` recording the chosen branch and tier. The review argued to cut this as
redundant with the payload (the row is written synchronously and is queryable a second later, and log
retention is days while the payload is permanent). **Kept on the owner's explicit request** — "a log says
what it picked so we can see". The payload remains the durable record.

## Ordering — the trap this design exists to avoid

`InstagramSourceGenerator::generate()` runs `seed()` (`:75`) **before** folding the name onto the user row
(`:94-100`), and inside `seed()` the routing (`InstagramConnectionSeeder:202`) runs before
`identitySync->applyIdentity()` (`:211`). So routing dispatches before `first_name`/`last_name` exist.

There is no escape via `display_name` either: `PreAccountBuildService` seeds it with the *handle* for
public signups, so it holds the handle at routing time too.

Under a real queue that is a race. Under `QUEUE_CONNECTION=sync` (`phpunit.xml:48`) it is deterministic
and always wrong — `FreshaStaffMatcher` reads null names every time and falls through to storewide.
**The feature would look implemented and do nothing.**

Moving the name-fold above `seed()` is a pure reordering: `$profile` is in scope, and
`IdentitySync::applyDisplayName` is fill-if-blank (`:57-63`) and never touches `first_name`/`last_name`.

**[v2] Confirmed: there is no wrapping transaction.** `GeneratePreAccountSiteJob::handle()` calls
`generate()` at `:92` with no `DB::transaction` anywhere in the file, and `->afterCommit()` dispatches
**immediately** when no transaction is open. So under the sync driver the entire Fresha scrape + employee
GraphQL + projection runs **inline inside `InstagramConnectionSeeder::seed()` at `:202`** — before
`identitySync->applyIdentity()` at `:211` and before the seeder's own locked payload write at `:220-229`.

Two consequences for implementation: the name-fold reorder is mandatory (above), and every test reaching
this path executes a full Fresha fetch unless faked.

## Error handling

Every fallback lands on storewide; none produces an error row.

| condition | behaviour |
|---|---|
| No match, or ambiguous tier | storewide |
| `slugFromUrl()` returns null | storewide — **must guard**, `fetchEmployeeServices(null, …)` is a type error |
| `fetchEmployeeServices` returns null/empty | storewide |
| `FetchBudget` exhausted before the second call | storewide — the first scrape already returned whole-location services |
| `fetchMenu()` fails or returns no services | `FetchUnavailableException` → terminal row → **see D6** |

### [v2] D6 — the terminal row launders itself back to `ok`

v1's table ended failures at "terminal row" as if that were durable. It is not. Verified chain:

1. `fetchMenu()` fails → `FetchUnavailableException` → `ConnectFetchJob:162` `markTerminal('unavailable')`.
   **`last_refreshed_at` is not bumped** (`:293-297`).
2. `scopeDueForRefresh` matches on `whereNull('last_refreshed_at')`
   (`IntegrationConnection.php:284`) — the row is immediately due, and `consecutive_failures` = 1 is far
   under `max_consecutive_failures` = 10.
3. `FreshaFetch:37-39` sees `selection === null` → `FetchNotModifiedException`.
4. `PlatformRefresher::recordNotModified` (`:113-118`) writes `last_refresh_status => 'ok'`,
   `consecutive_failures => 0`.

Within one refresh cycle the row reports **`ok`, serviceless, never re-fetched** — the original bug,
reintroduced on the failure branch, with backfill out of scope so nothing repairs it.

**Fix (~3 lines):** in `FreshaFetch::fetch()`, when `selection` is null **and**
`payload.connectMode === 'auto'`, route to the auto-selection path instead of throwing
`FetchNotModified`. The scheduled refresh becomes the backstop and the whole failure class closes. Keep
the router dispatch for latency — a new row is due immediately, but ≤60 minutes is too slow for someone
watching their site build.

Noted, not solved: `uniqueFor = 120` could swallow a manual connect landing within two minutes of an
auto-dispatch (near-impossible pre-claim); an employee leaving the salon self-heals via `FreshaFetch`'s
existing employee→storewide degrade (`:49-59`); booking XOR is covered by `seedBooking`'s lock plus the
projection's re-assert.

## [v2] Operational safeguards

**The volume question is largely defused upstream, and that fact is load-bearing.**
`GeneratePreAccountSiteJob` runs on `scraping` → `supervisor-long`, `maxProcesses => 1` in **both** envs
(`config/horizon.php:293, :396, :404`). Builds run one at a time, and `LinkRouter` consumes the platform
slot per run (`:103-105`), so there is **≤1 `ConnectFetchJob` in flight regardless of signup volume**. A
marketing push backs up on `scraping`, not on `platform_connect`. **Any future change raising
`supervisor-long` above 1 invalidates this and must revisit everything below.**

Required additions:

1. **Kill switch — a NEW flag, not `connect.deferred`.** `partna.connect.deferred`
   (`config/partna.php:1701`) means *"this platform uses the deferred connect flow"*; overloading it to
   also mean *"auto-connect is enabled"* conflates two independent things and would surprise the next
   reader. Add `partna.connect.auto_booking.enabled` (env-backed, default true), checked in `seedBooking`
   before dispatching. **[v3] Decided.**
2. **Global daily ceiling — 500/day.** No limiter, backoff or breaker on this path.
   `ConnectFetchJob::middleware()` returning `[]` (`:84-93`) stays correctly reasoned — that limiter is
   Apify-actor-keyed and coupling free scrapes to a paid budget would be wrong — but the actual ceiling
   is incidental. Mirror `partna.routing.probe`'s global-daily shape (`:1859-1870`), which was introduced
   with the note that an unbounded outbound request made on a user's say-so is *"an amplification vector
   aimed at someone else"*. 500 is generous headroom given builds are serialised at one concurrent, while
   still being a real ceiling. **[v3] Decided.**
3. **Cache the canonical `/a/<slug>` scrape for 1 hour.** Two people at one salon signing up otherwise
   scrape the same page twice. One hour dedupes a marketing-push burst while keeping prices fresh;
   deliberately shorter than `team_cache_seconds` = 86400 (`:1834`), because this menu feeds displayed
   pricing rather than a picker roster. **[v3] Decided.**
4. **`$timeout = 45` stays. [v3] Decided by the owner.** The budget maths is *correct*:
   `fetchEmployeeServices` consults the shared scoped `FetchBudget` directly (`FreshaScraper.php:206-225`),
   returns null when exhausted, clamps to `ceil(min(12, remaining))`, and deliberately does not nest
   (nesting fails open, `FetchBudget.php:44-50`). The worst case on paper — `20 (budget) + 5 (XOR) +
   5 (advisory) + up to 30 (projection statement_timeout) + 5 (connection lock)` — exceeds 45s, but
   raising the timeout is the worse trade: this job holds one of only **two** worker processes on
   `supervisor-1`, which also serves `SyncSubdomainToKvJob` — the job that makes a sitepage reachable at
   all. A longer timeout means a longer head-of-line stall on the queue that publishes sites. Accepting a
   killed job in the rare worst case is preferable to slowing publication under load; the D6 backstop
   repairs anything killed mid-flight. **This trade depends on `supervisor-long` staying at
   `maxProcesses => 1`** — revisit if that changes.
5. **Log when a projection exceeds the listing cap.** `services_max` = 500 (`:370`) caps *listing only*
   (`UserServiceController.php:68`; `FreshaController.php:676` says so outright). Past 500 the dashboard
   truncates and the owner cannot reach the tail to delete it. This matters more here than for manual
   connects because, per Risks, the *common* outcome for non-person handles is the **storewide** branch —
   projecting an entire salon's menu onto one individual's page with no human in the loop.

Verified non-issues: all three locks are per-user (`CacheKeyGenerator:345-348`, `:358-361`;
`FreshaServiceProjector:144`), so distinct signups never contend. Retry amplification is essentially nil
— every vendor failure is converted to `FetchUnavailableException` (`FreshaConnectFetch:180-188`) and
**caught** (`ConnectFetchJob:156-165`), so a Fresha outage produces one request per connection, not
three; `tries`/`backoff` fire only on unexpected throwables, capped by `maxExceptions = 2` (`:70`).
`sync()` is idempotent on `external_id`, so no unbounded row growth.

## Testing

**Unit.** `PersonNameParser`: the three real cases (`SIMON DOYLE | Barber & Educator` → Simon/Doyle;
`Prahran Hairdresser` → Prahran/Hairdresser; `Crucible Tattoo Co.` → Crucible/Co.), single token
(`lastName` null), empty string, each separator variant, and a separator with no surrounding spaces.
`FreshaScraper::canonicalUrl()`: `/book-now/` → `/a/`; canonical `/a/` unchanged; non-Fresha unchanged;
and an explicit pass-through assertion for `fresha.com/providers/<slug>` (a shape that exists on dev but
is out of scope).

**The ordering guard — the most valuable test here.** Fake the scrape to return a team containing
"Simon Doyle", run a pre-account build whose `fullName` is `SIMON DOYLE | Barber & Educator`, assert the
payload carries `selection.mode === 'employee'` and `matchTier === 'exact'`. If the name-fold moves back,
`last_name` is null at routing time and this flips to storewide. A structural assertion cannot catch it.

**[v2] Origin gating.** `Queue::assertPushed` for a Fresha link routed via `InstagramAutoSync` **and** via
`LinkInBioScanJob`; `assertNotPushed` for the same link routed via `CustomLinksController`. This is the
test that makes D4 real — without it, D5 is decoration.

**[v3] Branch coverage lives on `FreshaAutoSelector`, not on the strategies** — that is the point of
extracting it. Unit-test it directly: match → employee; no match → storewide; ambiguous tier → storewide;
`slugFromUrl` null → storewide; employee fetch empty → storewide; budget exhausted → storewide. All
assert a working storewide selection rather than an error. The two strategies then need only one
integration test each proving they call it, instead of six branch tests apiece.

**[v3] Dashboard-paste negative test.** Route a Fresha URL through `CustomLinksController` and assert no
`ConnectFetchJob` is pushed and no selection is written — the explicit guarantee that this feature never
fires for a logged-in user pasting a link.

**[v2] D6 backstop.** Simulate a failed auto-connect (terminal row, `selection` null, `connectMode`
`'auto'`), run the refresh, assert it now populates a selection instead of 304ing — and assert a
non-auto null-selection row (a team-mode dashboard connect) still 304s untouched.

**`ConnectFetchJob::$systemInitiated`** — notifier not called when true, still called when false. The
second half proves the three existing call sites did not change.

**`seedBooking` dispatch** — `assertNotPushed` on conflict, gate denial and lock contention.

**Existing suite.** Audit every test reaching `seedBooking` and add `Http::fake()`. Under
`QUEUE_CONNECTION=sync` (`phpunit.xml:48`) the job runs inline. Acceptance criterion: **the suite makes
no outbound Fresha calls.**

**[v2] The advisory-lock shim is not automatic.** `shimPgAdvisoryLockForSqlite()` (`tests/Pest.php:1229-1239`)
is a helper each test must **call** — v1 wrongly described it as globally registered. It is invoked inside
`setupPreAccountBuildsTable()` (`:583`) and explicitly by e.g. `FreshaServiceProjectionTest.php:25`. New
tests touching `FreshaServiceProjector::sync()` must call it or blow up under SQLite.

**Deliberately not covered:** the advisory-lock timeout path (Postgres-only), and real Fresha markup —
every test fakes the scrape, so a Fresha structure change breaks production silently. Pre-existing across
the subsystem.

## Risks

**False employee match (accepted under D1).** Adding no tier restriction means a fabricated surname can
match the wrong person. `SCORE_LAST_ONLY` matches on substring containment of the last-name token alone,
and the Instagram `fullName` yields junk surnames for non-person accounts — `Prahran Hairdresser` →
"Hairdresser", `Crucible Tattoo Co.` → "Co.". The failure mode is another person's prices on a stranger's
public page, pre-claim (SIGNUP-3 makes pre-account sites public before claiming).

Bounded two ways. **Recoverable:** `GET /platforms/fresha/team` re-scrapes the roster live from
`payload.url` and `POST /selection` overwrites our pick, so the existing picker corrects us after claim.
**Measurable:** `matchTier` makes the real tier distribution queryable, so D1 can be revisited on
evidence.

**Junk surnames leak beyond Fresha.** `core.users.last_name` is read by `UserDashboardResource:92`,
`UserStaffResource:37`, and staff search (`StaffUserController:71`, which `ILIKE`s it). Writing "Co." and
"Hairdresser" there is wrong data everywhere, not just in the matcher.

**[v2] The parse and the persistence are separable, at low cost.** Feeding a parsed surname to the matcher
is necessary; writing it to the column is a separate act the matcher does not require. Passing the parsed
name into `matchWithTier()` — a signature already being changed — would keep the column clean. This is a
data-quality win only: it does not remove the ordering requirement. Flagged for the owner; D1 as decided
writes the column.

**Widened `ConnectFetchJob` contract.** Built for "a human is watching the modal", now also serving
system-initiated connects with no interactive retry path. Tunings remain appropriate; the documented
reasoning needs correcting.

## Out of scope

Backfilling existing serviceless Fresha rows — **[v2]** on dev that is two Instagram-routed rows from
2026-08-10 (`anseo-studio-v0v92jna`, `jess-hairstylist-v8ct52bl`; `crucibletattooco` has no Fresha link at
all), plus `kebab-acai-kingz-melbourne` from 2026-07-25, plus a `source:'showcase'` pending row and
`edward-scissorhands` (a `/a/` row with no selection). D6's backstop will repair auto rows that *fail*,
but will not reach rows created before `connectMode` is stamped.

Also out: `GoogleBusinessAutoSync::seedBooking` (same shape, same outcome); Square (a stored URL, no
scraping); the `fresha.com/providers/<slug>` URL shape; and fixing `resolveWrite()`'s hardcoded
`source: 'instagram'`, which already misattributes provenance for non-Instagram router callers.
