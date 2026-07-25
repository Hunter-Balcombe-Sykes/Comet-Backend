# Link classification consolidation

**Status:** Ready for review
**Date:** 2026-07-25

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

```
LinkRouter::route(User $user, string $url): RouteResult

  1. WebsiteLinkHarvester::classify($url) → {platform, category, label} | null

  2. If classified — check routing gate (separate from AccountCapabilities):
     ┌─ social           → SocialConnectionSeeder    [everyone]
     ├─ booking          → BookingConnectionSeeder   [partna + business non-food]
     ├─ event            → EventsSeeder::seedStandalone()  [everyone]
     ├─ event-organiser  → EventsSeeder::seedAccount()     [everyone]
     ├─ shop             → ShopBrandSeeder           [everyone]
     ├─ reservations     → ReservationSeeder         [business food ONLY]
     ├─ online-ordering  → OnlineOrderingSeeder      [business food ONLY]
     └─ gate denied      → CustomLinkSeeder

  3. If unclassified (null) — async commerce probe:
     ┌─ Product page? → seedStore() for origin → works? seedProduct()
     │                                     → fails? seedProduct() standalone
     ├─ Storefront?   → seedStore()
     └─ Neither       → CustomLinkSeeder

  4. Any seeder failure → CustomLinkSeeder
```

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

### Issue A: 40+ new platforms need PlatformRegistry registration

**Severity: BLOCKING.** The `IntegrationConnection` model's `saving` event validates that every `platform` value is registered in `PlatformRegistry`. Attempting to create a connection for an unregistered platform throws `ValidationException`.

Currently registered platforms (~40 total): instagram, facebook, tiktok, x, linkedin, threads, reddit, snapchat, discord, telegram, kick, medium, skool, strava, youtube, vimeo, twitch, pinterest, apple-podcast, spotify, soundcloud, youtube-music, bandcamp, apple-music, mixcloud, tidal, eventbrite, humanitix, events-custom, fresha, square, opentable, resdiary, nowbookit, shop, custom, booking, reservations, online-ordering, google-business.

Platforms needed for the plan that are NOT yet registered: **Luma, Partiful, Ticketmaster, Meetup, Billetto, Ticket Tailor, Splash, Tito, Showpass, Eventcreate, TicketSource, TryBooking, EventZilla, Brown Paper Tickets, AllEvents.in, Universe, Ticketbuddy, Bookwhen, Booksy, Timely, Calendly, Vagaro, Schedulicity, Mindbody, Acuity, Setmore, SimplyBook.me, 10to8, Genbook, Appointy, Boulevard, Mangomint, GlossGenius, Treatwell, Ovatu, Rosy, Salon Iris, Bookedin, Noterro, SevenRooms, Tock, Yelp Reservations, Tablein, Eat App, TheFork, Quandoo, Chope, TableAgent, Resy, Wisely, Hostme, TableCheck, Restoran, Zenchef, Eveve, Reserve, SkipTheDishes, Just Eat, Grubhub, Waitr, Slice, ChowNow, Toast Takeout, Foodhub, Ritual, Zomato, Swiggy, Talabat, Glovo, Wolt, Patreon, Ko-fi, Buy Me a Coffee, Substack, GitHub, GitLab, CodePen, Dribbble, Behance, Twitch, Bandcamp, Apple Music, Apple Podcasts**.

Every one of these needs a `PlatformDescriptor::make(...)` call in `PlatformRegistryServiceProvider`. No DB migration needed (the CHECK constraint was dropped in migration `20260629120000`). Each descriptor needs: platform key, category, connect strategy (at minimum `NullConnect` for URL-only platforms), and display metadata.

### Issue B: CustomLinkSeeder hardcodes `platform = 'custom'` for EnrichLinkCardJob

**Severity: BLOCKING.** `CustomLinkSeeder::seed()` currently dispatches `EnrichLinkCardJob` with platform hardcoded to `'custom'` (line 91). If a link gets routed to `'booking'`, `'youtube'`, `'reservations'`, or any other platform, the enrichment job will query for a row under `platform = 'custom'` with the wrong `resourceId` and silently find nothing. The routed platform must be threaded through so `EnrichLinkCardJob` can find the correct row.

Fix: pass the actual stored platform to `EnrichLinkCardJob` instead of `'custom'`. `EnrichLinkCardJob` is already platform-agnostic — it works identically for any platform key.

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

**Severity: CHECK.** `InstagramSourceGenerator.php:91` strips `syncFindings` and `unmatched` from the connection payload before persisting for pre-claim users. After removing the unclaimed gate, links will be auto-synced (real IntegrationConnection rows created) rather than stored as `unmatched` suggestions. The strip is a data-minimization measure. Verify this doesn't accidentally strip synced connection data.

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

**Tests to update:**
- `AccountCapabilitiesTest.php:190-196` — "withholds auto-sync consent from unclaimed" → remove or update to "grants auto-sync consent to unclaimed"
- `ProbeCommerceLinksJobTest.php:115-122` — "downgrades to custom link for unclaimed" → remove (ProbeCommerceLinksJob is being removed in Phase 9)
- `InstagramAutoSyncTest.php:365-382` — "does not auto-create for unclaimed" → update to "DOES auto-create for unclaimed"
- `InstagramAutoSyncTest.php:277-293` — commerce link routing for unclaimed → update expectation

### Phase 2: Register new platforms in PlatformRegistry

**File:** `app/Providers/PlatformRegistryServiceProvider.php`

Add `PlatformDescriptor::make(...)` for every new platform listed above (~70 total). Each gets:
- Platform key (string, matching the hostname-based slug)
- Category (from `PlatformCategory` enum)
- `NullConnect` strategy (URL-only, no fetch/refresh)
- Display name + icon key
- `isPublic(false)` unless it should appear on the sitepage

Platforms already registered and needing no change: youtube, spotify, soundcloud, apple-music, apple-podcast, bandcamp, vimeo, twitch, pinterest, tiktok, facebook, x, linkedin, threads, reddit, snapchat, discord, telegram, kick, medium, eventbrite, humanitix, events-custom, fresha, square, opentable, resdiary, nowbookit, shop, custom, booking, reservations, online-ordering, google-business, instagram.

### Phase 3: New `LinkRouter` service

**New files:**
- `app/Services/Platforms/LinkRouter.php` — single entry point
- `app/Support/RouteResult.php` — value object `{outcome: 'seeded'|'custom'|'pending'|'skipped', platform: string, resourceId: string, category: string}`

`LinkRouter::route(User $user, string $url): RouteResult`:
1. `WebsiteLinkHarvester::classify($url)` → `{platform, category, label}` | null
2. If classified, check routing gate (NOT AccountCapabilities — the separate matrix above):
   - Social → always pass
   - Booking → `! $isFood` (partna passes because `$isBusiness` is false → `! false` = true; business non-food passes; business food fails)
   - Events → always pass
   - Shop → always pass (ShopProviderDetector handles provider detection)
   - Reservations → `$isBusiness && $isFood` (business food only)
   - Online-ordering → `$isBusiness && $isFood` (business food only)
3. Dispatch to appropriate seeder. Every call wrapped in try/catch
4. Sync path returns immediately. Async path (commerce probe) returns `RouteResult` with `outcome: 'pending'`

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

### Phase 5: Wire `CustomLinkSeeder` through `LinkRouter`

**File:** `app/Services/Platforms/CustomLinkSeeder.php`

`seed()` method changes:
1. Calls `LinkRouter::route($user, $url)`
2. If `RouteResult.outcome === 'seeded'` and platform is not `'custom'` → return `null` (link was routed elsewhere)
3. If `RouteResult.outcome === 'pending'` → return `null` (async probe in flight, will resolve separately)
4. If `RouteResult.outcome === 'custom'` → proceed with existing custom link write
5. When dispatching `EnrichLinkCardJob`, pass the actual platform from `RouteResult` instead of hardcoded `'custom'` (fixes Issue B)

All existing callers (`InstagramAutoSync`, `LinkInBioScanJob`, `autoSaveUnmatchedLinks`) now get routing for free through their existing `CustomLinkSeeder::seed()` calls. No caller changes needed (all discard the return value — Issue F).

### Phase 6: Wire `CustomLinksController` through `LinkRouter`

**File:** `app/Http/Controllers/Api/Platforms/CustomLinksController.php`

`addLink()` method:
1. Calls `LinkRouter::route($user, $url)`
2. If sync-seeded with non-custom platform → return success with the routed connection details in the response
3. If pending (async commerce probe) → return 202 with a status URL for polling
4. If custom → proceed with current custom-link write + enrichment dispatch

### Phase 7: Simplify `InstagramAutoSync`

**File:** `app/Services/Platforms/InstagramAutoSync.php`

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

Note: `ShopBrand` currently has `discount_code` (validated) but no `referral_code` column. This phase needs either a new column or storing referral in the brand's JSON payload. Check `supabase/migrations/` for the `site.shop_brands` schema before deciding.

### Phase 13: Verify `InstagramSourceGenerator` pre-claim strip

**File:** `app/Services/PreAccount/Generators/InstagramSourceGenerator.php`

Line 91: `Arr::except($payload, ['syncFindings', 'unmatched'])` strips these keys from pre-claim connection payloads. After this refactor, links are auto-synced as real `IntegrationConnection` rows rather than stored as `unmatched` suggestions in the payload. Verify this strip doesn't accidentally remove data that should persist. The strip is data minimization for unclaimed users — should still be safe since synced connections are separate DB rows.

## Files in scope

**New:**
- `app/Services/Platforms/LinkRouter.php`
- `app/Support/RouteResult.php`

**Modified:**
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
- `composer typecheck` — no new PHPStan errors
- Manual: sign up with `simondoylehair` Instagram → sector auto-detected, Fresha → booking, YouTube → social, Eventbrite → event
- Manual: paste a Fresha URL as custom link → routes to booking instead of custom link
- Manual: paste a Booksy/Calendly URL → routes to booking connection
- Manual: pre-account build → links auto-sync before claiming (no more unclaimed gate)
- Manual: partna account pastes OpenTable URL → stays as custom link (reservations gate denied)
- Manual: business food account pastes OpenTable URL → routes to reservations
- Manual: business food account pastes Uber Eats URL → routes to online-ordering
- Manual: partna account pastes Uber Eats URL → stays as custom link (online-ordering gate denied)
