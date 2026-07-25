# Link classification consolidation

**Status:** Ready to execute. B1–B5 resolved; one open decision remains (platform key granularity, blocks Phase 2 only)
**Date:** 2026-07-25
**Verified against:** working tree at `5a524c27`

## What this plan actually does, in one paragraph

Today a URL is treated three different ways depending on where it entered the system, and only 6 platforms ever become a real connection. This plan collapses that into one function — `LinkRouter::route($user, $url)` — that every entry point calls. It decides "is this a known platform, and is this account allowed to have that kind of connection", then either creates the typed connection (social / booking / event / shop / reservation / online-ordering) or writes a plain custom link. Three behavioural changes ride along, and they are the risky part, not the refactor: (a) unclaimed pre-account users start getting real auto-created connections (removing the DISC-7 consent gate), (b) the platform vocabulary grows from ~7 recognised hosts to ~70, and (c) manually pasted links stop being dumb custom links. The refactor itself is mostly deletion — `ProbeCommerceLinksJob`, `InstagramAutoSync::ACTIONABLE`, `autoSaveUnmatchedLinks`' re-classify branch, and `LinkInBioScanJob`'s inline loop all collapse into the router.

## Blockers and their fixes

These are defects in **this plan**, not pre-existing bugs in the codebase. The original draft would have produced broken code in five places. All five are now resolved and folded into the phases — no separate remediation work.

| # | Blocker | Fix | Status |
|---|---|---|---|
| B1 | Mutual recursion: `seed()` → `LinkRouter` → `seed()` | Split `CustomLinkSeeder` into `seed()` (gateway) + `seedCustom()` (raw write). Every fallback calls `seedCustom()` | ✅ Resolved — Phase 5 Step 0 |
| B2 | `integration.custom` gate would block ALL routing | Move `integration.custom`, previous-website skip and `MAX_LINKS` into `seedCustom()`. Only `isPendingDeletion()` stays in `seed()` | ✅ Resolved — Phase 5 |
| B3 | Four named seeders do not exist | **Option (b) chosen:** `LinkRouter use BuildsAutoSyncFindings`, calls existing helpers. No new seeder classes | ✅ Resolved — Phase 3, new Phase 2.5 |
| B4 | LIFE-106 booking XOR lock silently dropped | Inherited via the trait under B3(b). Contention → `outcome:'custom'` | ✅ Resolved — free consequence of B3 |
| B5 | Social routing for partna repeals RULING 1 | **Option (a) chosen:** socials route for all account types. See Decision 8 | ✅ Resolved — Decision 8 |

### B1 — mutual recursion

**Defect.** `CustomLinkSeeder::seed()` calls `LinkRouter::route()`; `route()` falls back to `CustomLinkSeeder::seed()` on gate-denial, seeder failure and probe-miss. Unbounded loop on the first partna account that pastes an OpenTable link. `ProbeCommerceLinksJob` compounds it: its `ShouldBeUnique` lock releases on job **completion**, so its own `$links->seed()` fallback re-dispatches the same probe forever.

**Fix.** Split the class:
- `seed()` — gateway. Calls the router, delegates, falls through to `seedCustom()` only on `outcome === 'custom'`.
- `seedCustom()` — today's body verbatim. Never routes. Every fallback path calls this.

Rule: **only an entry point may call `seed()`; everything downstream calls `seedCustom()`.** A `LinkRouter`-owned reentrancy guard (static per-request URL set) is cheap insurance.

**Where:** Target architecture diagram, Issue H, Phase 5 Step 0.

### B2 — `integration.custom` gate blocks everything

**Defect.** `CustomLinkSeeder.php:36` returns `null` on `! FeatureAvailability::for($user)->allows('integration.custom')` before anything else runs. Promote `seed()` to universal gateway without moving it and a user with custom links disabled silently loses social, booking, event, shop, reservation and ordering routing — returning `null`, no log line. `MAX_LINKS = 20` and the previous-website skip have the same problem: a Fresha booking link must not be dropped because the user already has 20 custom links.

**Fix.** Gate placement:

| Check | Lives in | Why |
|---|---|---|
| `isPendingDeletion()` | `seed()` | Applies to every outcome |
| `integration.custom` | `seedCustom()` | Custom-link feature, not a routing gate |
| previous-website skip | `seedCustom()` | Custom-link policy |
| `MAX_LINKS = 20` | `seedCustom()` | Counts custom rows only |

`LinkRouter` applies each category's own feature key where one exists — verify actual key names against `FeatureAvailability`, do not invent them.

**Where:** Issue I, Phase 5.

### B3 — four seeders do not exist ✅ resolved: option (b)

**Defect.** The architecture diagram dispatches to `SocialConnectionSeeder`, `BookingConnectionSeeder`, `ReservationSeeder`, `OnlineOrderingSeeder`. None exist; the only seeders in `app/Services/Platforms/` are `CustomLinkSeeder`, `EventsSeeder`, `InstagramConnectionSeeder`, `ShopBrandSeeder`, `ShopProductSeeder`. The real logic is private methods on `InstagramAutoSync` (`resolveWrite()`, `resolveBookingLink()`) plus the shared `BuildsAutoSyncFindings` trait that `GoogleBusinessAutoSync` also uses.

**Decision: option (b).** `LinkRouter` does `use BuildsAutoSyncFindings` and calls the existing helpers. No new seeder classes. Zero new files, `GoogleBusinessAutoSync` untouched, and `withBookingXorLock()` is inherited so B4 resolves as a side effect.

**⚠ Prerequisite — this is not free.** `resolveWrite()` and `resolveBookingLink()` are currently **private methods on `InstagramAutoSync`**, not on the trait. They must move onto `BuildsAutoSyncFindings` before `LinkRouter` can reach them. That is now **Phase 2.5**, sequenced before Phase 3, and it must land as a pure move with a green suite — no behaviour change in the same commit.

Strike the four seeder names from the architecture diagram (already done) and from Files-in-scope → New (already done).

**Where:** Issue J, Phase 2.5, Phase 3.

### B4 — LIFE-106 booking XOR lock

**Defect.** `fresha` / `square` / `booking` are mutually exclusive per user, and the invariant spans three platforms, so a per-platform lock cannot serialize it. The whole check-then-write span runs inside `BuildsAutoSyncFindings::withBookingXorLock()`, shared with `GoogleBusinessAutoSync::seedBooking` (`:270`) and the findings-apply paths (`BuildsAutoSyncFindings.php:207, 221`). The plan never mentions it, and Phase 7 deletes `handleClassifiedLink()` — its only `InstagramAutoSync` caller (`:242`).

**Fix.** Inherited via the trait under B3(b) — reimplementation avoided. `LinkRouter`'s booking branch runs the conflicting-provider query, existing-row lookup, tombstone check and write inside `withBookingXorLock()`, preserving on-contention behaviour (route to custom link, never drop — today's `unmatched`, the router's `outcome:'custom'`).

**Still verify during Phase 3:** `GoogleBusinessAutoSync.php:263` documents that the `has()` check MUST re-run **inside** the closure. Inheriting the trait gives you the lock, not automatic correctness of what you put inside it.

**Where:** Issue K, Phase 3 step 3, Phase 7.

### B5 — social routing repeals RULING 1 ✅ resolved: option (a)

**Defect.** The routing matrix says Social → Route for all three account shapes. Today that is false: `InstagramAutoSync.php:174` gates social auto-sync on `google_business_full_sync`, which is `$isBusiness` (`AccountCapabilities.php:60`). A **partna** account's scraped socials currently fall to `unmatched` as custom-link suggestions. The code calls this RULING 1 and a passing test covers it.

**Decision: option (a) — socials route for everyone.** Recorded as Decision 8. The matrix is correct as drawn; what was missing was the acknowledgement that it changes live behaviour for the majority of accounts.

Consequences to carry through:
- `LinkRouter`'s social gate is unconditional. Do **not** consult `google_business_full_sync`.
- **RULING 1 comments become lies.** `InstagramAutoSync.php:34-42` and the `handleClassifiedLink` branch at `:209-215` both document the social capability split as deliberate. Remove them with the code in Phase 7 rather than leaving them to mislead.
- `GoogleBusinessAutoSync::seedSocials` still gates on `google_business_full_sync` (`:99`) and is **out of scope**. That is a deliberate asymmetry after this change — Google Business socials stay business-only, Instagram/pasted socials go to everyone. Note it in the commit message so it doesn't read as an oversight later.
- Test rewrites are deliberate, not accommodations: `InstagramAutoSyncTest.php:365-382` plus the RULING 1 capability-gate assertions.

**Where:** Decision 8, Issue L, routing gate matrix, Phase 3 step 2, Phase 7.

### Open decision — platform key granularity

**Platform key granularity (Issue A).** Do the ~55 new booking/reservation/ordering brands get their own platform key each (rich per-brand cards, ~55 descriptors + icons) or ride as a `provider` string on the already-registered shared keys (generic card, near-zero registry work)? Roughly a week of difference. **This plan assumes the shared key** — stated here because the original never said so out loud. Blocks Phase 2, not Phase 1.

## Goal

Every link entering the system — pasted manually, scraped from an Instagram bio, or unrolled from a link-in-bio page — goes through a single classification+routing gateway. If the URL is a known platform, it becomes the right kind of connection (social, booking, event, store, reservation, online-ordering). If routing fails for any reason or the account type doesn't qualify, it falls back to a custom link. Works identically before and after the user claims their account.

## Decisions

| # | Decision |
|---|---|
| 1 | Fresha staff: fuzzy/close name match (`first_name + last_name` vs `displayName`). No match → leave unselected, user picks manually in booking/services |
| 2 | Sync routing for fast classifications (no HTTP needed). Async probe only for commerce (store/product detection needs HTTP). Manual add returns immediate result for fast paths, 202 + status URL for commerce probes |
| 3 | Cleaner migration: `CustomLinkSeeder::seed()` internally calls `LinkRouter`. All existing callers get routing for free |
| 4 | Social platforms: include EVERYTHING in `config/partna.php` `social_platforms` plus Apple Music, Apple Podcasts, and others. All get auto-connected with minimal rows (URL + extracted handle) |
| 5 | Platform lists: research top ~20 in booking, events, reservations, online-ordering. URL patterns only — no scrapers |
| 6 | Routing gates are SEPARATE from capabilities. `can_use_reservations` stays `$isBusiness ? $isFood : true` (unchanged). LinkRouter applies its own stricter gate: reservations + online-ordering route ONLY for business food accounts. Booking routes for partna + business non-food only. Social + events + shop route for everyone |
| 7 | Pre-account: remove `isUnclaimed()` gate. No re-trigger on claim needed |
| 8 | **Social auto-sync is no longer a Business-Partna convenience.** All account types get scraped socials auto-connected — RULING 1's `google_business_full_sync` gate is repealed for `LinkRouter`. `GoogleBusinessAutoSync::seedSocials` keeps its own gate and is out of scope, so the asymmetry is deliberate. (Resolves B5) |
| 9 | **No new seeder classes.** `LinkRouter` uses `BuildsAutoSyncFindings` and the existing helpers; `resolveWrite()`/`resolveBookingLink()` move onto the trait in Phase 2.5 first. (Resolves B3, and B4 by inheritance) |

## Current architecture (problems)

Three separate routing paths with different behaviours:

| Path | Entry point | What happens |
|---|---|---|
| Instagram bio links | `InstagramAutoSync::seed()` → `handleClassifiedLink()` | Only 6 platforms actionable. Everything else → unmatched → `autoSaveUnmatchedLinks()` → probe or custom link |
| Link-in-bio scrape | `LinkInBioScanJob` → `handleClassifiedLink()` | Same gating. Unmatched → `CustomLinkSeeder::seed()` directly (no commerce probe) |
| Manual add | `CustomLinksController::addLink()` | Never runs classification. Always writes a custom link row |

Problems:
- `InstagramAutoSync::ACTIONABLE` hardcodes only 6 platforms (facebook, tiktok, x, linkedin, fresha, square). YouTube and Pinterest classified but never auto-synced
- `can_autosync_scraped_connections = !isUnclaimed()` blocks ALL auto-sync during pre-account build
- `ProbeCommerceLinksJob` is a separate async hop with its own gating partially duplicating `handleClassifiedLink`
- `autoSaveUnmatchedLinks()` has its own re-classify+probe logic that differs from `LinkInBioScanJob`'s fallback
- `config/partna.php` `social_platforms` has 25+ platforms; only 7 are in `WebsiteLinkHarvester::classify()`

## Target architecture

### Single gateway: `LinkRouter`

`CustomLinkSeeder::seed()` internally calls `LinkRouter::route()` before writing. Every existing caller of `CustomLinkSeeder` gets routing for free. `CustomLinksController::addLink()` also calls `LinkRouter` first.

**⚠ CORRECTED — the original diagram was recursive.** `LinkRouter` must NEVER call `CustomLinkSeeder::seed()`, because `seed()` calls `LinkRouter`. `CustomLinkSeeder` is split in two:

- `seed(User, string): ?IntegrationConnection` — the **gateway**. Calls `LinkRouter::route()`, delegates, and only falls through to `seedCustom()` when the router says `outcome === 'custom'`.
- `seedCustom(User, string): ?IntegrationConnection` — the **raw write**. Today's body verbatim (previous-website skip, `integration.custom` gate, MAX_LINKS, lock, `EnrichLinkCardJob`). Never routes. This is what every fallback path calls.

```
LinkRouter::route(User $user, string $url, RouteContext $ctx): RouteResult
                                           ^^^^^^^^^^^^^^^^^ see Issue M

  1. WebsiteLinkHarvester::classify($url) → {platform, category, label} | null

  2. If classified — check routing gate (separate from AccountCapabilities):
     ┌─ social           → BuildsAutoSyncFindings social write   [see Issue L]
     ├─ booking          → withBookingXorLock + booking write    [partna + business non-food]
     ├─ event            → EventsSeeder::seedStandalone()        [everyone]
     ├─ event-organiser  → EventsSeeder::seedAccount()           [everyone]
     ├─ shop             → ShopProviderDetector → ShopBrandSeeder [everyone]
     ├─ reservations     → reservations write                    [business food ONLY]
     ├─ online-ordering  → online-ordering write                 [business food ONLY]
     └─ gate denied      → RouteResult{outcome:'custom'}   ← returns, does NOT call the seeder

  3. If unclassified (null) — async commerce probe (dispatch, return 'pending'):
     ┌─ Product page? → seedStore() for origin → works? seedProduct()
     │                                     → fails? seedProduct() standalone
     ├─ Storefront?   → seedStore()
     └─ Neither       → CustomLinkSeeder::seedCustom()   ← raw write, from inside the job

  4. Any seeder failure → RouteResult{outcome:'custom'} (sync path)
                        → seedCustom() (async path, from inside the job)
```

Rule to hold: **the only code allowed to call `seed()` is an entry point. Everything downstream calls `seedCustom()`.** A `LinkRouter`-owned reentrancy guard (a static per-request URL set) is cheap insurance if this is ever violated.

### Routing gate matrix (separate from AccountCapabilities)

These are `LinkRouter`-internal gates. Capability formulas in `AccountCapabilities` stay unchanged.

| Category | Partna | Business (non-food) | Business (food) |
|---|---|---|---|
| Social | Route | Route | Route |
| Booking | Route | Route | Custom link |
| Events | Route | Route | Route |
| Shop | Route | Route | Route |
| Reservations | Custom link | Custom link | Route |
| Online ordering | Custom link | Custom link | Route |

Key: "Custom link" = gate denied, falls through to `CustomLinkSeeder::seed()`. Never silently dropped.

### Routing speed tiers

| Classification | Sync/Async | Why |
|---|---|---|
| Social, booking, reservations, events, online-ordering | **Sync** | No HTTP needed — URL pattern match + write row |
| Shop (decisive hosts: myshopify.com, bigcartel.com) | **Sync** | Host match is decisive |
| Shop (generic — own domain) | **Async** | Needs ShopProviderDetector probe chain (~5 HTTP round-trips) |
| Unclassified (product page? storefront?) | **Async** | Needs GenericShopScraper HTTP fetch |

For manual add via `CustomLinksController`: sync classifications return immediately with the routed result. Async commerce probes return 202 with a status URL (same pattern `EnrichLinkCardJob` uses).

## Issues found during deep audit

Issues A–G are from the original audit, re-verified (two were wrong — see the Appendix). Issues H–N were found during code verification; H, I, J, K and L are the detailed diagnoses behind blockers B1–B5 above, with file and line references. The Blockers section carries the fixes; these sections carry the evidence.

### Issue A: new platform KEYS need PlatformRegistry registration (~15, not ~70)

**Severity: BLOCKING (mechanism) / OVERSTATED (scope).**

Mechanism — **verified correct**. `IntegrationConnection::booted()` (line 122, guard at line 144) runs `app(PlatformRegistry::class)->has($platform)` on `saving` and throws for an unregistered key. No DB migration needed: the CHECK constraint was dropped in `20260629120000_drop_platform_connections_check.sql`. ✅

Scope — **the original list of ~70 was wrong, and contradicted this plan's own "Platform lists" section.** A registry entry is needed per **platform key**, not per **brand**. The plan itself says non-Fresha/Square booking is stored as `Platform::Booking`, reservations as `Platform::Reservations`, ordering as `Platform::OnlineOrdering` — all three keys are already registered, and `WebsiteLinkHarvester::classify()` already collapses every ordering host to the single platform `'online-ordering'` (line 263). So Vagaro, Mindbody, Resy, Wolt, SkipTheDishes etc. need **zero** registry work — they are a `provider` string inside an existing platform's payload.

Actually needs a new registry key:

| Group | Keys | Why |
|---|---|---|
| New socials | substack, patreon, ko-fi, buymeacoffee, github, gitlab, codepen, dribbble, behance, whatsapp, gumroad | Each is its own connection identity on the sitepage |
| Events | Only if a per-brand key is wanted. Otherwise reuse `events-custom` (registered) | See below |

Already registered — **no change needed**: instagram, facebook, tiktok, x, linkedin, threads, reddit, snapchat, discord, telegram, kick, medium, skool, strava, youtube, vimeo, twitch, pinterest, apple-podcast, spotify, soundcloud, youtube-music, bandcamp, apple-music, mixcloud, tidal, eventbrite, humanitix, events-custom, fresha, square, opentable, resdiary, nowbookit, shop, custom, booking, reservations, online-ordering, google-business.

Note the plan listed Twitch, Bandcamp, Apple Music and Apple Podcasts as "not yet registered" — **all four are already registered** (`PlatformRegistryServiceProvider.php:256, 282, 296, 315`).

**Decision needed before Phase 2:** do the ~55 new booking/reservation/ordering brands get their own platform key (rich per-brand cards, 55 descriptors, 55 icons) or a `provider` field on a shared key (cheap, generic card)? This plan assumes the shared key. Say so once, explicitly — the two readings differ by roughly a week of work.

### Issue B: ~~CustomLinkSeeder hardcodes `platform = 'custom'`~~ — NOT A BUG

**Severity: WITHDRAWN (was: BLOCKING).**

The premise doesn't hold in either direction:

- **Today** it is correct. `CustomLinkSeeder::seed()` writes the row with `'platform' => 'custom'` (lines 66, 69, 76) and then dispatches `EnrichLinkCardJob(..., 'custom', $rid, ...)` at line 91. The literal matches the row it just wrote. There is no live bug.
- **After the refactor** it is still correct, and the proposed fix is dead code. Under the corrected split (see Target architecture), line 91 only runs inside `seedCustom()`, which is only reached when the router already returned `outcome === 'custom'`. The platform is `'custom'` by construction.

Only true part worth keeping: `EnrichLinkCardJob` **is** platform-agnostic — it takes `public string $platform` and uses it for the unique key, the row lookup, and the connection lock (`EnrichLinkCardJob.php:42, 51, 58, 79, 83`). If a *future* typed seeder wants card enrichment it can dispatch the job with its own key. Nothing to do in this plan.

**Delete Phase 5 step 5.**

### Issue C: `can_use_reservations` gives partna `true` — routing gate must be separate

**Severity: DESIGN.** `AccountCapabilities.php:67`: `can_use_reservations: $isBusiness ? $isFood : true`. This means partna accounts always have `can_use_reservations = true`. The plan says partna should NOT get reservations auto-routed. The capability formula is intentionally NOT being changed (it controls dashboard visibility and API access, which are correct as-is).

Fix: `LinkRouter` applies its own routing gate that is stricter than the capability. Partna + business non-food → reservations gate = denied → custom link. Only business food passes. This is documented in the routing matrix above. `AccountCapabilities` stays unchanged.

### Issue D: `can_autosync_scraped_connections` test impact

**Severity: TEST.** Changing from `!isUnclaimed()` to always `true` breaks:

| Test file | Lines | What breaks |
|---|---|---|
| `AccountCapabilitiesTest.php` | 190-196 | Expects `false` for unclaimed — will get `true` |
| `ProbeCommerceLinksJobTest.php` | 115-122 | Expects probe skip for unclaimed — will run full probe |
| `InstagramAutoSyncTest.php` | 365-382 | Expects social+booking links in `unmatched` for unclaimed — will auto-sync them |
| `InstagramAutoSyncTest.php` | 277-293 | Expects commerce link in `unmatched` for unclaimed — will dispatch probe |

These tests must be updated to reflect the new behavior: unclaimed users get the same auto-sync as claimed users. Also `InstagramSourceGenerator` strips `syncFindings`/`unmatched` from pre-claim payloads (line 91) — verify this contract still holds.

### Issue E: `GoogleBusinessAutoSync` never checked consent gate

**Severity: INFO.** `GoogleBusinessAutoSync::seed()` checks `can_use_booking`, `google_business_full_sync`, and `can_use_reservations`, but never `can_autosync_scraped_connections`. Currently this means Google Business enrichment for unclaimed users would auto-create connections (bypassing the DISC-7 gate). Since we're removing the gate entirely, this gap closes itself — no action needed, just noting for awareness.

### Issue F: All callers discard `CustomLinkSeeder::seed()` return value

**Severity: INFO.** Every caller (`ProbeCommerceLinksJob`, `LinkInBioScanJob`, `autoSaveUnmatchedLinks`) calls `$seeder->seed($user, $url)` without capturing the return value. When `seed()` internally routes to a non-custom platform and returns `null` (because no custom link was created), no caller breaks — they were already ignoring the return. Safe to change.

### Issue G: `InstagramSourceGenerator` strips findings/unmatched from pre-claim payloads

**Severity: CHECK.** `InstagramSourceGenerator.php:90-92` strips **three** keys — `bioLinks`, `syncFindings`, `unmatched` (the plan said two) — from the connection payload before persisting for pre-claim users. The rationale is labelled PRIV-2 data minimisation, and the write is `saveQuietly()` specifically so it does not re-fire the connect-time observer. After removing the unclaimed gate, links will be auto-synced (real IntegrationConnection rows created) rather than stored as `unmatched` suggestions. The strip is a data-minimization measure. Verify this doesn't accidentally strip synced connection data.

### Issue H: mutual recursion between `CustomLinkSeeder` and `LinkRouter` — B1

**Severity: BLOCKING.** As originally drawn: `seed()` calls `route()`; `route()` falls back to `seed()` on gate-denial, seeder failure, and probe-miss. That is an unbounded loop on the very first partna account that pastes an OpenTable link.

`ProbeCommerceLinksJob` makes it worse, not better: its `ShouldBeUnique` lock (`uniqueFor = 300`) is released when the job **completes**, so the job's own `$links->seed($user, $this->url)` fallback (lines 90, 105) would dispatch a fresh probe of the same URL every run, forever.

**Fix:** the `seed()` / `seedCustom()` split described in Target architecture. Every fallback in `LinkRouter`, in the probe job, and in `InstagramConnectionSeeder` calls `seedCustom()`.

**Sensitive:** the existing `seed()` body must move to `seedCustom()` **byte-for-byte**. It carries four behaviours that are easy to lose in a re-type: `isPendingDeletion()`, the previous-website host skip (with its documented equality-not-containment rule), `MAX_LINKS = 20` counted **inside** the lock, and the `LockTimeoutException` → `null` contract.

### Issue I: the `integration.custom` gate would block all routing — B2

**Severity: BLOCKING.** `CustomLinkSeeder.php:36`:

```php
if (! FeatureAvailability::for($user)->allows('integration.custom')) {
    return null;
}
```

This runs before anything else. Promote `seed()` to the universal gateway without moving this and a user with custom links disabled loses **social, booking, event, shop, reservation and ordering routing too** — silently, returning `null`, with no log line.

**Fix:** `integration.custom` belongs in `seedCustom()` only. `LinkRouter` applies each category's own feature key (`integration.booking`, `integration.reservations`, …) where one exists. Verify the actual key names against `FeatureAvailability` before writing the gate table — do not invent them.

**Also check the same class:** the previous-website skip and `MAX_LINKS` are custom-link-specific policies. A Fresha booking link must not be dropped because the user already has 20 custom links.

### Issue J: four named seeders do not exist — B3

**Severity: BLOCKING.** The architecture diagram dispatches to `SocialConnectionSeeder`, `BookingConnectionSeeder`, `ReservationSeeder` and `OnlineOrderingSeeder`. None exist in `app/Services/Platforms/` — the only seeders are `CustomLinkSeeder`, `EventsSeeder`, `InstagramConnectionSeeder`, `ShopBrandSeeder`, `ShopProductSeeder`. They are also absent from "Files in scope → New".

The real code lives as private methods on `InstagramAutoSync` (`resolveWrite()`, `resolveBookingLink()`) plus the shared `BuildsAutoSyncFindings` trait, which `GoogleBusinessAutoSync` also uses.

**Fix — pick one and write it down:**
- **(a) Extract** the four seeders from `InstagramAutoSync` + `BuildsAutoSyncFindings`, add them to New files, and keep `GoogleBusinessAutoSync` on the same code path. Cleaner, larger, and touches a second live auto-sync surface.
- **(b) Have `LinkRouter` `use BuildsAutoSyncFindings`** and call the existing helpers. Smaller diff, keeps the XOR lock for free (Issue K), leaves the trait as the shared home. **Recommended.**

### Issue K: LIFE-106 booking XOR lock is silently dropped — B4

**Severity: BLOCKING.** Booking is not a simple write. `fresha` / `square` / `booking` are mutually exclusive per user, and the invariant spans three platforms, so a per-platform lock cannot serialize it. The whole check-then-write span runs inside `BuildsAutoSyncFindings::withBookingXorLock()` — shared with `GoogleBusinessAutoSync::seedBooking` (`GoogleBusinessAutoSync.php:270`) and the findings-apply paths (`BuildsAutoSyncFindings.php:207, 221`).

The plan never mentions it, and Phase 7 deletes `handleClassifiedLink()` — the only `InstagramAutoSync` caller (`InstagramAutoSync.php:242`).

**Fix:** `LinkRouter`'s booking branch runs the conflicting-provider query, existing-row lookup, tombstone check and write inside `withBookingXorLock()`, with the same on-contention behaviour (route to custom link, never drop). On lock contention today the link goes to `unmatched` — the router's equivalent is `outcome: 'custom'`.

**Sensitive:** `GoogleBusinessAutoSync.php:263` documents that the `has()` check MUST re-run **inside** the closure. Any extraction must preserve that.

### Issue L: social routing for partna accounts is an undeclared behaviour change — B5

**Severity: HIGH (product decision, not a refactor).**

The routing matrix says Social → Route for Partna, Business non-food, and Business food alike. Today that is false: `InstagramAutoSync.php:174` gates social auto-sync on `google_business_full_sync`, which is `$isBusiness` (`AccountCapabilities.php:60`). A **partna** account's scraped Instagram/Facebook/TikTok links currently fall to `unmatched` as custom-link suggestions. The code calls this "RULING 1" and it is covered by a passing test.

So the matrix as written repeals RULING 1 for every partna account — the majority of the user base.

**Resolved: Decision 8** — socials route for all account types; the repeal is intentional. `GoogleBusinessAutoSync::seedSocials` keeps its own `google_business_full_sync` gate and is out of scope, so after this change the two social paths gate differently on purpose. See B5 for the comment and test consequences.

### Issue M: per-run "first link per platform wins" dedupe is lost

**Severity: HIGH.** `InstagramAutoSync` threads `array &$seenPlatforms` through the link loop so the first bio link per platform wins and later ones are skipped (`InstagramAutoSync.php:191, 226, 259`). There is a test named exactly `first bio link per platform wins when two links classify to the same platform`.

`LinkRouter::route()` as specified is stateless and per-URL, so a bio with three Fresha links would attempt three booking writes. The XOR lock serializes them, but the *last* one wins instead of the first — an inverted, and race-dependent, contract.

**Fix:** add a `RouteContext` object (or `array &$seen`) passed through `route()`, owned by the calling loop. `CustomLinksController::addLink()` passes a fresh one (single URL, no dedupe needed). Reflected in the corrected signature above.

### Issue N: `harvestHtml()` output is a shared contract with GoogleBusinessAutoSync

**Severity: MEDIUM.** `SOCIAL_HOSTS` is not private to `classify()`. `harvestHtml()` (line 161) builds the `socials` map from the same constant, and the class docblock states the output is shaped **exactly** like `GoogleBusinessApifyScraper::map()`'s subset so `GoogleBusinessAutoSync::seed()` consumes either source unchanged.

Adding ~20 hosts to `SOCIAL_HOSTS` therefore changes what `GoogleBusinessAutoSync::seedSocials` receives — keys it has never seen (`substack`, `behance`, `github`). Before Phase 4, confirm it iterates a known allowlist and ignores unknown keys rather than writing blind. If it writes blind, an unregistered platform key reaches `IntegrationConnection::saving` and throws (Issue A).

Also note `SOCIAL_HOSTS` keys are not platform slugs — `'twitter'` maps to platform `'x'` via `SOCIAL_PLATFORM` (line 88). New entries need both tables, and the docblock at line 239 explicitly relies on the two staying key-identical.

## Platform lists

### Social platforms (all from config + additions)

Already in `WebsiteLinkHarvester::SOCIAL_HOSTS`: Instagram, Facebook, TikTok, X/Twitter, LinkedIn, YouTube, Pinterest.

Already in config, add to harvester: Spotify, SoundCloud, Snapchat, Threads, Discord, Reddit, Telegram, WhatsApp.

Add to both config + harvester + PlatformRegistry: Apple Music, Apple Podcasts, Bandcamp, Twitch, Substack, Medium, Patreon, Ko-fi, Buy Me a Coffee, GitHub, GitLab, CodePen, Dribbble, Behance, Vimeo.

Each gets: host pattern in `WebsiteLinkHarvester`, handle extraction from URL (via config's `url_path_extractor`), `PlatformDescriptor` in registry, and a minimal `IntegrationConnection` row with `{username, url, source: 'auto'}`.

### Booking platforms (~20)

Already have: Fresha, Square.
Config already has: Booksy, Timely, Calendly.
Add: Vagaro, Schedulicity, Mindbody, Acuity, Setmore, SimplyBook.me, 10to8, Genbook, Appointy, Boulevard, Mangomint, GlossGenius, Treatwell, Ovatu, Rosy, Salon Iris, Bookedin, Noterro.

All non-Fresha/Square stored as `Platform::Booking` connection with `{url, provider: '<name>'}`. Fresha + Square keep existing rich flows.

### Event platforms (~20)

Already have scrapers: Eventbrite (org + event), Humanitix (org + event).
Config already lists: Luma, Partiful, Ticketmaster.
Add: Meetup, Billetto, Ticket Tailor, Splash, Tito, Showpass, Eventcreate, TicketSource, TryBooking, EventZilla, Brown Paper Tickets, AllEvents.in, Universe, Ticketbuddy, Bookwhen.

Eventbrite + Humanitix keep existing scrapers + org/event discrimination. New platforms: detect org vs event from URL structure where possible. Where not possible, store as standalone event under `Platform::EventsCustom` (already registered).

### Reservation platforms (~20)

Already have: OpenTable, ResDiary, NowBookit.
Add: SevenRooms, Tock, Yelp Reservations, Tablein, Eat App, TheFork, Quandoo, Chope, TableAgent, Resy, Wisely, Hostme, TableCheck, Restoran, Zenchef, Eveve, Reserve.

All stored as `Platform::Reservations` connection with `{url, provider: '<name>'}`. OpenTable/ResDiary/NowBookit keep existing keyless widget flows.

### Online ordering platforms (~20)

Already have: Uber Eats, DoorDash, Menulog, Deliveroo, Order Online, OrderMate.
Add: SkipTheDishes, Just Eat, Grubhub, Waitr, Slice, ChowNow, Toast Takeout, Foodhub, Ritual, Zomato, Swiggy, Talabat, Glovo, Wolt.

All stored as `Platform::OnlineOrdering` connection with `{url, provider: '<name>'}`.

## Phases

### Phase 1: Remove unclaimed gate

**File:** `app/Services/Accounts/AccountCapabilities.php`
- `can_autosync_scraped_connections` → always `true` (remove `! $pro->isUnclaimed()`)

**⚠ This is a consent decision, not a refactor step.** DISC-7 exists because an unclaimed provisional subject is a real person who has not agreed to us creating platform connections from data we scraped about them. That reasoning is written into the code in four places and will not be true after this change. Josh has made the call (Decision 7); this note is so the implementer doesn't treat it as a tidy-up.

Consequences to handle in the same commit:
- **Keep the capability field**, set to `true`. Do not delete `can_autosync_scraped_connections` from `AccountCapabilitySet` (line 98) — as a live always-true flag it remains a one-line kill switch if the consent question comes back before pilot. Deleting it means re-threading the concept through four files to restore it.
- **Stale comments become lies.** `ProbeCommerceLinksJob.php:38-43, 84-86`, `EventsSeeder.php:25`, `InstagramAutoSync.php:170-173` and `AccountCapabilities.php:70-73` all document DISC-7 as an active gate. Update or remove them; a comment asserting a gate that no longer exists is worse than none.
- **Two other plans reference DISC-7 as mandatory**: `docs/superpowers/plans/2026-07-23-signup-testing-repairs.md:1335` and `2026-07-23-signup-v2-execution.md:260` (C3.2, "all mandatory, fail-closed"). Add a superseded-by note to both so a future reader doesn't re-add the gate.
- **Issue E is verified correct** — `GoogleBusinessAutoSync::seed()` checks `can_use_booking` (line 89), `google_business_full_sync` (99), `can_use_reservations` (103) and `can_use_online_ordering` (106), but never `can_autosync_scraped_connections`. So GB enrichment has been bypassing DISC-7 all along. The gap does close itself here, but it is worth one line in the commit message: this was a live consent hole, not just an inconsistency.

**Tests to update:**
- `AccountCapabilitiesTest.php:190-196` — "withholds auto-sync consent from unclaimed" → remove or update to "grants auto-sync consent to unclaimed"
- `ProbeCommerceLinksJobTest.php:115-122` — "downgrades to custom link for unclaimed" → remove (ProbeCommerceLinksJob is being removed in Phase 10, not 9)
- `InstagramAutoSyncTest.php:365-382` — "does not auto-create for unclaimed" → update to "DOES auto-create for unclaimed"
- `InstagramAutoSyncTest.php:277-293` — commerce link routing for unclaimed → update expectation

### Phase 2: Register new platforms in PlatformRegistry

**File:** `app/Providers/PlatformRegistryServiceProvider.php`

Register the **~15 genuinely new platform keys** from the corrected Issue A — not ~70.

**⚠ The API in the original plan was invented.** Verified against `PlatformDescriptor.php` and the provider:

| Original plan said | Reality |
|---|---|
| `PlatformDescriptor::make(...)` for each | ✅ exists (aliased `PD::make`), but see below |
| `NullConnect` strategy | ❌ no such class. `app/Services/Platforms/Strategies/Connect/` has `UrlConnect` |
| `isPublic(false)` | ❌ no such method on `PlatformDescriptor` |

For URL-only platforms the right tool is the existing archetype, already used at `PlatformRegistryServiceProvider.php:131`:

```php
$r->register(PD::linkOnly($key, $label, LinkConnectionResource::class));
```

Read `PD::linkOnly()`'s body before assuming what it sets — it is the pattern the current link-only platforms use, and copying a neighbouring registration is safer than composing a fresh builder chain. Category comes from `PlatformCategory` (11 cases: Social, Content, Streaming, Music, Events, Booking, Reservations, OnlineOrdering, Shop, Education, Business) — no new case is needed.

Icon-key note: the config registry uses `apple_podcasts` (underscore) while the platform registry uses `apple-podcast` (hyphen, singular). Two separate namespaces. Do not "fix" either; just don't assume a key from one works in the other.

### Phase 2.5: Move the write helpers onto `BuildsAutoSyncFindings`

**Prerequisite for Phase 3 — added by Decision 9 (B3 option b).**

**Files:** `app/Services/Platforms/InstagramAutoSync.php` → `app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php`

`resolveWrite()` and `resolveBookingLink()` are private methods on `InstagramAutoSync`. `LinkRouter` cannot reach them from a trait it merely `use`s. Move them onto `BuildsAutoSyncFindings`, which already owns `withBookingXorLock()` and is already shared with `GoogleBusinessAutoSync`.

**Ship this as a pure move, in its own commit, with a green suite.** No behaviour change, no signature change, no "while I'm here". The whole point is that Phase 3's risky commit reviews as a clean diff.

- `InstagramAutoSync` keeps working unchanged — it already uses the trait.
- Check for anything the moved methods reach that is still private on `InstagramAutoSync` (constructor-injected normalizers, `BOOKING_PLATFORMS`). Those move too, or become trait-abstract.
- `GoogleBusinessAutoSync` also uses this trait: adding methods to it widens that class's surface. Confirm no name collision with its own private methods before moving.

### Phase 3: New `LinkRouter` service

**New files:**
- `app/Services/Platforms/LinkRouter.php` — single entry point; `use BuildsAutoSyncFindings` (Decision 9)
- `app/Services/Platforms/RouteResult.php` — value object `{outcome: 'seeded'|'custom'|'pending'|'skipped', platform: string, resourceId: string, category: string}`
- `app/Services/Platforms/RouteContext.php` — per-run `seenPlatforms` + probe cap (Issue M)

`LinkRouter::route(User $user, string $url, RouteContext $ctx): RouteResult`:
1. `WebsiteLinkHarvester::classify($url)` → `{platform, category, label}` | null
2. If classified, check routing gate (NOT AccountCapabilities — the separate matrix above):
   - Social → **always pass, unconditionally** (Decision 8). Do not consult `google_business_full_sync`
   - Booking → `! $isFood`. **Careful:** the plan's parenthetical is right by accident. `SectorTaxonomy::isFood($pro->sector)` is evaluated for partna too and a partna account CAN have a food sector, so `! $isFood` alone would wrongly deny a partna hairdresser-turned-cafe. Mirror the capability's own shape: `$isBusiness ? ! $isFood : true`. `AccountCapabilities.php:47-50` is explicit that sector is irrelevant for partna — the router must honour that, not re-derive it.
   - Events → always pass
   - Shop → always pass (ShopProviderDetector handles provider detection)
   - Reservations → `$isBusiness && $isFood` (business food only)
   - Online-ordering → `$isBusiness && $isFood` (business food only)
3. Dispatch to appropriate seeder. Every call wrapped in try/catch. **Booking runs inside `withBookingXorLock()` (Issue K).**
4. Sync path returns immediately. Async path (commerce probe) returns `RouteResult` with `outcome: 'pending'`
5. `$ctx` carries the per-run `seenPlatforms` map (Issue M) and is passed by the calling loop.

**`RouteResult` placement:** the plan puts it in `app/Support/`. Every other platform value object lives under `app/Services/Platforms/` (`ConnectOutcome.php`, `PlatformInput.php`, `Payloads/`). Put it there instead — `app/Services/Platforms/RouteResult.php` — and note that a new directory under `app/Services/` would need wiring into the audit pipeline's `codebase_chunks()` per CLAUDE.md. `app/Services/Platforms/` already exists, so this avoids that entirely.

### Phase 4: Expand `WebsiteLinkHarvester` platform lists

**File:** `app/Services/Platforms/WebsiteLinkHarvester.php`

Expand all host pattern constants to the ~20 platforms per category listed above:
- `SOCIAL_HOSTS`: add Spotify, SoundCloud, Snapchat, Threads, Discord, Reddit, Telegram, WhatsApp, Apple Music, Apple Podcasts, Bandcamp, Twitch, Substack, Medium, Patreon, Ko-fi, Buy Me a Coffee, GitHub, GitLab, CodePen, Dribbble, Behance, Vimeo
- `BOOKING_HOSTS`: expand from 2 to ~20 (list above)
- `RESERVATION_HOSTS`: expand from 3 to ~20 (list above)
- `ORDERING_HOSTS`: expand from 6 to ~20 (list above)
- Event detection in `classify()`: add Luma, Partiful, Ticketmaster, Meetup org/event URL patterns
- `SHOP_HOSTS`: consider expanding (Shopify, Big Cartel currently)

Each new entry needs: label → host regex pattern, and a platform slug mapping for `classify()` to return.

**Do not hand-write ~70 regexes.** `config('partna.social_platforms')` already holds ~34 platforms with a `host_allowlist` array and a `url_path_extractor` per entry — including Booksy, Timely, Calendly, Luma, Partiful, Ticketmaster, WhatsApp, Substack, Patreon, Gumroad. Derive `SOCIAL_HOSTS` / `BOOKING_HOSTS` from that config rather than maintaining a second, drifting table. `WebsiteLinkHarvester`'s own docblock (line 221) claims to be "the one host→platform mapping in the codebase" — that claim is already false, and this phase is the moment to make it true rather than doubly false.

`url_path_extractor` also solves handle extraction for free, which the plan's Social section assumes exists.

**Read Issue N before touching `SOCIAL_HOSTS`** — it is shared with `harvestHtml()`'s Google Business contract, and its keys are not platform slugs.

**Deliberate exclusion to preserve:** `SHOP_HOSTS`' docblock (lines 68-73) explains that `squareup.com` / `square.site` stay classified `booking` even though Square Online stores share those hosts — flipping it regresses booking. Any Shopify/Big Cartel expansion must leave that alone.

### Phase 5: Wire `CustomLinkSeeder` through `LinkRouter`

**File:** `app/Services/Platforms/CustomLinkSeeder.php`

**Step 0 — split the class first, as its own commit, with no behaviour change:**
1. Rename today's `seed()` body verbatim to `seedCustom()`.
2. Add a new `seed()` that is, at this point, `return $this->seedCustom($user, $url);`.
3. Run the suite. It must be green before any routing lands. This makes the risky commit reviewable as a pure diff.

**Then** `seed()` becomes the gateway:
1. Calls `LinkRouter::route($user, $url, $ctx)`
2. `outcome === 'seeded'` → return `null` (link was routed elsewhere)
3. `outcome === 'pending'` → return `null` (async probe in flight, resolves separately)
4. `outcome === 'custom'` → `return $this->seedCustom($user, $url)`
5. ~~thread platform to EnrichLinkCardJob~~ — **removed, see Issue B (withdrawn)**

**Gate placement (Issue I):** `isPendingDeletion()` stays in `seed()` (applies to everything). `integration.custom`, the previous-website skip, and `MAX_LINKS` move to `seedCustom()` — they are custom-link policies and must not gate a booking connection.

All existing callers (`InstagramAutoSync`, `LinkInBioScanJob`, `autoSaveUnmatchedLinks`) get routing for free through their existing `CustomLinkSeeder::seed()` calls. Issue F is **verified correct** — all three discard the return value, so `null` breaks nothing.

**Fallback callers must switch to `seedCustom()`**, not `seed()`: the probe job's two fallbacks (`ProbeCommerceLinksJob.php:90, 105`) and anything `LinkRouter` calls on failure. Otherwise Issue H reopens.

### Phase 6: Wire `CustomLinksController` through `LinkRouter`

**File:** `app/Http/Controllers/Api/Platforms/CustomLinksController.php`

`addLink()` method:
1. Calls `LinkRouter::route($user, $url, new RouteContext)`
2. If sync-seeded with non-custom platform → return success with the routed connection details in the response
3. If pending (async commerce probe) → return 202 with a status URL for polling
4. If custom → proceed with current custom-link write + enrichment dispatch

**⚠ This is a breaking API-contract change, and "Frontend changes" is currently listed as out of scope — a contradiction to resolve before starting.**

`addLink()` today has exactly one response shape on success: **HTTP 202** with `{status:'pending', link:{…}, statusUrl:'/api/platforms/custom/links/{rid}/status'}` (`CustomLinksController.php:69-73`). The dashboard polls `statusUrl`. Step 2 introduces a second, differently-shaped success response the frontend has never seen, and there is no `statusUrl` for a routed booking connection to poll.

Options, cheapest first:
- **(a)** Keep returning 202 with the existing envelope always, adding an optional `routedTo: {platform, category}` field. Frontend keeps working untouched; the dashboard can adopt the field later. **Recommended** — preserves "frontend out of scope" honestly.
- **(b)** Ship the new shape and pull the frontend into scope.

Also confirm `withConnectionLock($user, …)` still wraps only the custom-link write. Routing must happen **outside** that lock — a `LinkRouter` call inside it would nest the booking XOR lock inside the custom-platform lock and invite a deadlock. `CustomLinkSeeder`'s own comment (lines 55-61) already makes this rule explicit for its dispatch: never hold a lock across an inline dispatch.

**Sensitive:** step 4's "proceed with current write" duplicates `seedCustom()`. The class docblock on `CustomLinkSeeder` (lines 14-24) states deliberately that the controller was NOT wired through the seeder. Either honour that and leave the controller's write alone, or unify them — but say which, because that docblock will otherwise become a lie.

### Phase 7: Simplify `InstagramAutoSync`

**File:** `app/Services/Platforms/InstagramAutoSync.php`

**⚠ Highest-risk phase in the plan.** `handleClassifiedLink()` is 100+ lines of accumulated invariants, each with a comment explaining a past bug. Before deleting anything, list what it does and confirm `LinkRouter` reproduces each: DISC-7 gating (removed in Phase 1), the social capability gate (Issue L), the sector booking gate, `seenPlatforms` first-wins (Issue M), the LIFE-106 XOR lock and its route-to-custom-on-contention behaviour (Issue K), per-link fault isolation, and the "never silently dropped" contract that every branch upholds.

Treat the existing test file as the specification. Do not rewrite an assertion to match new behaviour without first deciding, deliberately, that the old behaviour was wrong.

**Two rewrites are already sanctioned** — DISC-7 (Decision 7) and the RULING 1 social gate (Decision 8). Anything beyond those two is a red flag: stop and re-read rather than adjusting the test.

**Delete the stale RULING 1 comments** at `InstagramAutoSync.php:34-42` (class docblock capability-split paragraph) and `:209-215` (the social gate branch). They describe a rule that no longer exists.

- Remove `ACTIONABLE` constant (no longer needed — `LinkRouter` handles classification → routing)
- `seed()`: each bio link → `CustomLinkSeeder::seed($user, $url)` instead of inline classify + handleClassifiedLink. Translate back to findings/unmatched shape for the `syncFindings` contract
- `handleClassifiedLink()`: reduce to a thin wrapper or remove entirely. `LinkInBioScanJob` will also use `CustomLinkSeeder::seed()` directly

### Phase 8: Simplify `LinkInBioScanJob`

**File:** `app/Jobs/Platforms/LinkInBioScanJob.php`

- Remove inline `WebsiteLinkHarvester::classify()` + `handleClassifiedLink()` + unmatched fallback loop
- Each outbound link: call `$seeder->seed($user, $url)` (CustomLinkSeeder internally routes via LinkRouter)
- Same-host chrome link skip stays
- Commerce probe cap moves to LinkRouter
- `mergeFindingsBack()` stays (it persists findings from LinkRouter back into Instagram payload for the synced modal)

### Phase 9: Simplify `autoSaveUnmatchedLinks`

**File:** `app/Services/Platforms/InstagramConnectionSeeder.php`

- Remove re-classify + `ProbeCommerceLinksJob` dispatch + custom-link fallback
- Each unmatched entry → `$this->linkSeeder->seed($user, $url)` (CustomLinkSeeder internally routes via LinkRouter)
- The re-classify logic (was checking `$this->harvester->classify($url) === null` to decide probe vs custom) is now handled inside `LinkRouter::route()`

### Phase 10: Remove `ProbeCommerceLinksJob`

**File:** `app/Jobs/Platforms/ProbeCommerceLinksJob.php` — **deleted**.

Its resolution pipeline (event → EventsSeeder, shop → ShopBrandSeeder, product page → ShopProductSeeder, DISC-7 gating) moves into `LinkRouter`'s async commerce probe path. A new internal `CommerceProbeJob` may be created for the async dispatch, or the logic lives inline in `LinkRouter` with a dispatch to a generic probe job.

**Preserve these four properties — they are load-bearing, and this plan increases the load:**

| Property | Value | Why it matters more after this plan |
|---|---|---|
| `ShouldBeUnique` + `uniqueFor = 300`, `uniqueId = userId:sha1(url)` | coalesces duplicate probes | Every link now routes, so duplicate-probe volume goes up, not down |
| `onQueue(config('partna.queues.scraping'))` | dedicated scraping queue | Probes must not land on the default queue and starve it |
| `timeout = 90`, `tries = 2`, `backoff = [30]` | bounded ~5 HTTP round-trips | Unchanged work, unchanged budget |
| Runs detached from the scan | never inside `InstagramConnectJob`'s 150s budget | The docblock is explicit: Apify's 110s scrape already eats most of it |

**Also:** the dispatch sites currently cap how many links per scan get a probe (excess goes straight to a custom link). Phase 8 says "commerce probe cap moves to LinkRouter" — the router is per-URL and stateless, so the cap must live on `RouteContext` (Issue M), not as a router constant. Losing the cap turns one link-in-bio page into an unbounded probe fan-out.

**Cost note:** removing the DISC-7 gate means every unclaimed pre-account build now probes its bio links too. Pair this with the cap decision and re-check against the scraping-queue budget before deploy.

### Phase 11: Fresha auto staff selection

**Files:** `app/Services/Platforms/Strategies/Fetch/FreshaConnectFetch.php` or `FreshaController.php`

After team is scraped via `FreshaScraper::extractTeam()`:
1. Build user's full name: `trim("{$user->first_name} {$user->last_name}")`. Skip if blank
2. For each team member, compute a match score against `strtolower($employee['displayName'])`:
   - **Exact full-name match**: highest confidence → auto-select
   - **Both first-name AND last-name tokens present** (any order): high confidence → auto-select
   - **Last-name token match**: medium confidence → auto-select only if exactly one match
   - **First-name token match**: low confidence → do NOT auto-select (too ambiguous)
   - **No token match**: skip
3. If exactly one employee at medium confidence or above → auto-select `employeeId`
4. If zero or multiple matches → leave `employee` null. User picks manually when they visit booking/services in the dashboard

### Phase 12: Discount/referral URL param extraction

**File:** `app/Services/Platforms/LinkRouter.php` (or a helper)

When routing a shop/product link, parse query parameters:
- Discount params checked: `discount`, `code`, `coupon`, `promo`, `voucher`
- Referral params checked: `ref`, `referral`, `referrer`, `affiliate`, `partner`

Attach found values to `ShopBrand` row:
- Discount param → `discount_code` field (only set on new brands; never overwrite existing)
- Referral param → new `referral_code` field or store in payload (only set on new; never overwrite existing)

**Verified:** `ShopBrand` has `discount_code` (`ShopBrand.php:22, 61, 142`; DDL in `20260704160000_shop_brands_products.sql:26`). There is no `referral_code` column anywhere. ✅

**⚠ Adding a column to `site.shop_brands` is not just an ALTER.** `20260704160000_shop_brands_products.sql:66` defines a view that **enumerates shop_brands columns explicitly** (`favicon, logo, discount_code, fetch_mode, is_individual, position, …`) and line 119 builds a JSON object from them. Postgres will not let you widen a column list in place — the view has to be dropped and recreated in the same migration. This exact class of bug has bitten this repo before (menu/services multi-category, caught only on Postgres because SQLite tests didn't exercise the view).

If this phase ships:
- Migration in `supabase/migrations/` as raw SQL — **never** a Laravel migration (composer guard rejects them).
- Drop + recreate the view in the same file.
- Run `composer test:pg`, not just `composer test` — the SQLite lane will not catch a broken view.
- Cheaper alternative worth considering first: store `referralCode` in the brand's JSON payload, no DDL, no view churn. Given "no customers yet" this is reversible either way, but the payload route is one commit instead of three.

**Never overwrite an existing code.** The plan says this for both fields; hold to it. A user who set a discount code by hand must not have it clobbered by a re-scrape.

### Phase 13: Verify `InstagramSourceGenerator` pre-claim strip

**File:** `app/Services/PreAccount/Generators/InstagramSourceGenerator.php`

Line 91: `Arr::except($payload, ['syncFindings', 'unmatched'])` strips these keys from pre-claim connection payloads. After this refactor, links are auto-synced as real `IntegrationConnection` rows rather than stored as `unmatched` suggestions in the payload. Verify this strip doesn't accidentally remove data that should persist. The strip is data minimization for unclaimed users — should still be safe since synced connections are separate DB rows.

## Files in scope

**New:**
- `app/Services/Platforms/LinkRouter.php`
- `app/Services/Platforms/RouteResult.php` — moved from `app/Support/` (see Phase 3 note)
- `app/Services/Platforms/RouteContext.php` — per-run `seenPlatforms` + probe cap (Issue M)
- `app/Jobs/Platforms/CommerceProbeJob.php` — if Phase 10 replaces rather than inlines

**No new seeder classes** (Decision 9). `SocialConnectionSeeder` / `BookingConnectionSeeder` / `ReservationSeeder` / `OnlineOrderingSeeder` are NOT being created.

**Modified:**
- `app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php` — receives `resolveWrite()` + `resolveBookingLink()` (Phase 2.5)
- `app/Services/Accounts/AccountCapabilities.php` — remove `isUnclaimed()` from `can_autosync_scraped_connections`
- `app/Providers/PlatformRegistryServiceProvider.php` — register ~70 new platforms
- `app/Services/Platforms/WebsiteLinkHarvester.php` — expand all platform host lists (~70 new entries)
- `app/Services/Platforms/CustomLinkSeeder.php` — call `LinkRouter::route()` before writing; thread platform to EnrichLinkCardJob
- `app/Services/Platforms/InstagramAutoSync.php` — remove ACTIONABLE, simplify seed(), reduce handleClassifiedLink
- `app/Services/Platforms/InstagramConnectionSeeder.php` — simplify `autoSaveUnmatchedLinks`
- `app/Jobs/Platforms/LinkInBioScanJob.php` — simplified loop, calls CustomLinkSeeder
- `app/Http/Controllers/Api/Platforms/CustomLinksController.php` — `addLink()` runs LinkRouter first
- `app/Services/Platforms/Strategies/Fetch/FreshaConnectFetch.php` — auto staff selection
- `app/Services/PreAccount/Generators/InstagramSourceGenerator.php` — verify pre-claim strip
- `config/partna.php` — add missing social platforms

**Removed:**
- `app/Jobs/Platforms/ProbeCommerceLinksJob.php`

**Tests to update:**
- `tests/Feature/Account/AccountCapabilitiesTest.php` — remove/update DISC-7 consent tests
- `tests/Feature/Platforms/ProbeCommerceLinksJobTest.php` — removed with the job
- `tests/Feature/Platforms/InstagramAutoSyncTest.php` — update unclaimed expectation tests
- `tests/Feature/Platforms/CustomLinkSeederTest.php` — update platform='custom' assertions if routing changes outcome

## Out of scope

- Google Business link harvesting (separate flow — inherits `LinkRouter` for free via `CustomLinkSeeder`)
- Frontend changes (dashboard surfaces new auto-created connections via existing API responses)
- Building scrapers/card-builders for new platforms (URL storage only — no fetch/refresh)
- Changing `AccountCapabilities` formulas (capabilities stay as-is; routing gates are separate)
- DB migrations (CHECK constraint on `platform_connections.platform` was already dropped; new `social_platforms` config entries don't need migrations)

## Verification

- `composer test` — all updated tests pass
- ~~`composer typecheck`~~ — **no such script.** The PHPStan script is `composer analyse`. (Note: `analyse` has been observed exiting 0 on dev even with errors — read the output, don't trust the exit code.)
- `composer test:pg` — **required, not optional.** This plan writes `IntegrationConnection` rows on paths the SQLite lane covers thinly, and Phase 12 may touch a Postgres view. CHECK/NOT NULL drift between the two lanes is a known recurring failure mode here.
- `php artisan pint`
- Manual: sign up with `simondoylehair` Instagram → sector auto-detected, Fresha → booking, YouTube → social, Eventbrite → event
- Manual: paste a Fresha URL as custom link → routes to booking instead of custom link
- Manual: paste a Booksy/Calendly URL → routes to booking connection
- Manual: pre-account build → links auto-sync before claiming (no more unclaimed gate)
- Manual: partna account pastes OpenTable URL → stays as custom link (reservations gate denied)
- Manual: business food account pastes OpenTable URL → routes to reservations
- Manual: business food account pastes Uber Eats URL → routes to online-ordering
- Manual: partna account pastes Uber Eats URL → stays as custom link (online-ordering gate denied)
- Manual: a user with `integration.custom` **disabled** pastes a Fresha URL → still routes to booking (Issue I)
- Manual: a user already at 20 custom links pastes a Fresha URL → still routes to booking (Issue I)
- Manual: a bio with two Fresha links → exactly one booking connection, the **first** (Issue M)
- Regression: an unresolvable link routes to a custom link exactly once, with no repeat probe dispatch (Issue H)

## Appendix — verification verdicts

Every factual claim in the original plan, checked against the working tree.

| Claim | Verdict |
|---|---|
| `IntegrationConnection` validates platform against `PlatformRegistry` on save | ✅ `IntegrationConnection.php:122, 144` |
| CHECK constraint dropped in `20260629120000` | ✅ file exists, named `drop_platform_connections_check` |
| `EnrichLinkCardJob` is platform-agnostic | ✅ `:42, 51, 58, 79, 83` |
| `CustomLinkSeeder` hardcodes `'custom'` at line 91 | ✅ literally true, ❌ not a bug — see Issue B |
| `can_use_reservations: $isBusiness ? $isFood : true` | ✅ `AccountCapabilities.php:67` |
| `can_autosync_scraped_connections = ! isUnclaimed()` | ✅ `:74` |
| `InstagramAutoSync::ACTIONABLE` = 6 platforms | ✅ facebook, tiktok, x, linkedin, fresha, square |
| YouTube/Pinterest classified but never auto-synced | ✅ classify() returns them; ACTIONABLE excludes them |
| `GoogleBusinessAutoSync` never checks the autosync gate (Issue E) | ✅ confirmed |
| All `CustomLinkSeeder::seed()` callers discard the return (Issue F) | ✅ confirmed |
| `EventsSeeder::seedStandalone()` / `seedAccount()` exist | ✅ `:107` / `:45` |
| `ShopBrand` has `discount_code`, no `referral_code` | ✅ confirmed |
| `AccountCapabilitiesTest.php:190-196` | ✅ ~`:191-196`, title matches |
| `ProbeCommerceLinksJobTest.php:115-122` | ✅ exact |
| `config` `social_platforms` has 25+, harvester has 7 | ✅ ~34 vs 7 |
| `InstagramSourceGenerator` strips 2 keys at line 91 | ⚠️ strips **3** (`bioLinks` too), at `:90-92` |
| `PlatformDescriptor::make(...)` | ✅ exists as `PD::make` |
| `NullConnect` strategy | ❌ does not exist — it's `UrlConnect`; use `PD::linkOnly()` |
| `isPublic(false)` on the descriptor | ❌ no such method |
| `Platform::EventsCustom` enum case | ❌ enum has 11 cases, not this one — `events-custom` is a registry string |
| `SocialConnectionSeeder` / `BookingConnectionSeeder` / `ReservationSeeder` / `OnlineOrderingSeeder` | ❌ none exist — Issue J |
| `composer typecheck` | ❌ no such script — it's `composer analyse` |
| ~70 platforms need registry entries | ❌ ~15 — Issue A |
| Twitch / Bandcamp / Apple Music / Apple Podcasts unregistered | ❌ all four already registered |
| Routing gates are cleanly separate from capabilities | ⚠️ true as designed; the Social row repeals RULING 1 — now explicit as Decision 8 |
