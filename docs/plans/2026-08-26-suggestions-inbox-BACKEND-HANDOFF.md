# Backend handoff — suggestions inbox, accept flow, favicons, Uber Eats

**Date:** 2026-08-26. **Audience:** backend developer. Self-contained — all
file:line references are to this repo (`Comet-Backend`) unless prefixed.
The dashboard-side counterpart plan is
`partna-monorepo/apps/dashboard/docs/2026-08-26-suggestions-favicons-ubereats-plan.md`;
nothing in this file depends on it shipping first.

User-reported symptoms this addresses:

1. Suggestions inbox offers accounts that are already connected (seen with a
   TikTok account).
2. A Shopify suggestion reads "Shopify store 23504463" instead of the store's
   name — this was fixed once but the fix never shipped (see §A).
3. Clicking **Add** on a store suggestion says "Adding…" but the store never
   connects.
4. Uber Eats never appears in the connect sheet, even for food-sector
   accounts (café/restaurant).

Live-log evidence (Laravel Cloud, dev, 4h window on 2026-08-26): 6×
`GET /api/routing/suggestions 200`, zero accept POSTs, zero 5xx — consistent
with accepts that 202 and then die in the queue, and with the user's click
predating the window.

---

## §0 — The stranded branch (read this first)

The store-name + self-swap fix from 2026-08-24 (plan:
`docs/plans/2026-08-24-suggestions-inbox-alias-and-label-fixes.md`) is
stranded:

- It lives ONLY on the local, **unpushed** branch
  `fix/suggestions-inbox-and-opentable-routing` — 2 commits ahead
  (`44b589f37` "Name a suggested store, and stop offering a swap against the
  user's own account", `312bda304` deep-link routing), **133 commits behind**
  `development`. It never deployed; that's why the user still sees the raw ID.
- Meanwhile on `development`, the `routing.source_intents.identifier_label`
  column was **dropped from the dev DB** on 08-25 (`98156f483`, migration
  `20260825200000_drop_source_intents_identifier_label.sql`) because it was
  sitting valueless with no code referencing it — the code half was on this
  unmerged branch the whole time.

**Task 0:** rebase `fix/suggestions-inbox-and-opentable-routing` onto
`development` (133 commits of drift — expect conflicts in
`SuggestionsController.php`, which `development` touched in `8604e44ec` and
`660be6ec8`), and add a **new** migration re-adding the column:

```sql
-- new file, timestamp AFTER 20260825200000
ALTER TABLE routing.source_intents
    ADD COLUMN IF NOT EXISTS identifier_label text;
```

Do NOT edit or delete `20260824120000_..._identifier_label.sql` (ADD) or
`20260825200000_drop_...` (DROP) — both versions are recorded in
`supabase_migrations.schema_migrations`; the drop migration's header explains
why removing either re-breaks `supabase db push`. The replay order
ADD → DROP → re-ADD is correct from zero. Push via `supabase db push` on the
dev ref, per repo convention.

No backfill needed: all 67 existing intents had NULL labels at drop time; new
probe writes populate it (`SourceReconciler.php:355-362, 383` on the branch).

---

## §A — Store name on the suggestion card

After §0 lands, the name flows: `ShopifyStorefrontProbe.php:56-63`
(`shop_name` from `/meta.json`) → `ProbeOutcome::toProjection()`
(`ProbeOutcome.php:82-86`) → `Placement::$identifierLabel`
(`PlacementPolicy.php:127-149`) → `SourceReconciler` persists →
`SuggestionsController.php:101` serves
`'identifier' => $intent->identifier_label ?? $intent->identifier`.

Two refinements while you're in there:

1. **The card's `displayName` is still the catalog surface label** —
   literally `"Shopify store"` (`app/Catalog/Definitions/Shopify.php:41`),
   with the store's own name relegated to the `identifier` slot. Consider a
   dedicated wire field (e.g. `accountName`) in `index()`
   (`SuggestionsController.php:83-108`) so the frontend doesn't have to
   guess whether `identifier` is a name or an ID. Additive, non-breaking.
2. **The `myshopify.com` host-detector lane carries no name by design**
   (`Shopify.php:47-53` is regex-only) — label stays NULL there and the
   frontend falls back to the URL host. No action needed; just don't "fix"
   it by blocking NULL labels.

Existing tests on the branch: `tests/Feature/Routing/SourceIntentLabelTest.php`,
`SuggestionsInboxTest.php` (the ST. ALi / 23504463 fixtures).

---

## §B — Accept ("Add") silently fails for probed stores

`SuggestionsController::accept()` store lane (`SuggestionsController.php:240-249`):
dispatches `CommerceProbeJob::dispatch($userId, $intent->canonical_url, 'shop')`,
returns `202 { connectionId: null, status: 'pending' }`, and **does not settle
the intent** — everything rides on the job. Failure modes found, in order of
confidence:

1. **Deep-path URLs re-file the suggestion instead of connecting.**
   `CommerceProbeJob.php:116-117`: any path on the URL sets `$deepPage`, and
   `seedStore()` (`:248-256`) passes `suggestOnly: $this->suggestOnly || $deepPage`
   — so the accepted probe runs suggest-only, `StoreBrandSeeder::seed()`
   downgrades Place→Choose (`StoreBrandSeeder.php:90-99`), and the reconciler
   re-writes the intent as `proposed`. The card comes back; no connection is
   ever made. **Fix:** the accept path is an explicit user answer — dispatch
   with an explicit `suggestOnly: false` override (and probe the storefront
   ROOT of `canonical_url`), or thread the accepted intent id through the job
   so it settles rather than re-proposes.
2. **ProbeGate refusals strand the intent.** ≥2 path segments →
   `not_a_storefront_root` (`ProbeGate.php:108-111`); probe budget exhaustion
   (`ProbeGate.php:62-64`, `ProbeBudget`, `per_run_cap` 6). Either way
   `seedStore()` returns false and `CommerceProbeJob.php:126-128` writes a
   plain custom-link card — the suggestion is never settled and the user
   never learns. **Fix:** on the accept lane, a failed seed should flip the
   intent to `blocked`/`unservable` (visible, honest) instead of leaving it
   `proposed`.
3. **Brand-cap mismatch.** `StoreBrandSeeder::MAX_BRANDS = 5`
   (`StoreBrandSeeder.php:53`) vs the Shopify surface's `multiAccount(10)`
   (`Shopify.php:38`). On the 6th store the seeder returns `capped`
   (`:168-174`), `seedStore()` returns false, and the accept degrades to a
   custom-link card. Align the caps (the comment at `:49-51` already claims
   parity that doesn't hold).
4. **`ShouldBeUnique` swallows accepts.** `CommerceProbeJob.php:46,56,84-87`
   — `uniqueId = userId:sha1(url)`, `uniqueFor = 300`. An accept fired while
   any probe of the same URL is in flight (e.g. the suggest-only probe that
   created the card) is dropped; the 202 still returns. Exempt the accept
   lane from uniqueness or key it differently.
5. **No status handle for the client.** The 202 response gives the frontend
   nothing to poll. Add a `statusUrl` to the accept response, mirroring what
   `connectStore` already returns (the dashboard's `pollConnectStatus`
   machinery exists and is unused here). Also confirm the `scraping` queue
   (`config('partna.queues.scraping')`) is worked in dev — the whole lane is
   inert without it.

Suggested repro/test: extend
`tests/Feature/Routing/SuggestionsInboxTest.php`'s end-to-end store-accept
test with a `canonical_url` that carries a path — it should connect, not
re-propose.

---

## §C — Dedup: already-connected accounts keep getting suggested

`SuggestionsController::settleIfAlreadyConnected()`
(`SuggestionsController.php:449-475`) **early-returns unless
`block_reason === 'cap_reached'`** (`:451-453`). A `proposed` /
`below_threshold` intent recorded *before* the user connected the same
account by another route (OAuth, connect sheet, …) is never re-checked and
sits in the inbox forever — the reported TikTok case.

**Fix:** run the already-connected settle for `proposed` intents too (match
via `ConnectionIdentity::matchExisting()`, `ConnectionIdentity.php:76-146` —
note `tiktok.profile` is in the case-fold allowlist at `:53`, so the check
handles case drift). Settle as `superseded`/`applied` with the found
`connection_id`.

Secondary, same shape: folded `sync:` rows
(`SyncFindingsBridge::payloadSuggestions()`, `SyncFindingsBridge.php:76-114`)
are deduped only against intents by `surfaceKey`
(`SuggestionsController.php:126-133`) — never against
`site.platform_connections`. Add the same connected check before emitting
them.

(For completeness: the record-time alias upgrade — aliased Choose→Place —
already exists, `SourceReconciler.php:112-118`; the read-time gap above is
what's left.)

---

## §D — Favicon on the suggestion wire (and better capture)

Goal: the dashboard shows the store's real favicon on suggestion cards and
Platforms-table store rows. It will interim-fallback to a by-host favicon
service, so this is an upgrade, not a blocker.

Current state:

- The suggestion wire shape (`SuggestionsController.php:83-108`) has **no
  favicon field**; `brandKey` is the only icon hint.
- Capture exists but is patchy: `PlatformScraper::favicon()`
  (`PlatformScraper.php:42-95`) is used by the Generic/Woo/Squarespace
  probes (`GenericStorefrontProbe.php:60`, `WooCommerceStorefrontProbe.php:58`,
  `SquarespaceStorefrontProbe.php:64`) but **not** by
  `ShopifyStorefrontProbe` (its `probeMeta()` lane skips it —
  `ShopifyScraper.php:100` only runs favicon in `fetchBrand()`) nor
  `BigCartelStorefrontProbe` (`:69-71`).
- Post-acceptance storage already exists: `content.storefronts.favicon_url`
  (`20260813100000_create_content_storefronts.sql:21`), written
  coalesce-only by `StoreBrandSeeder::upsertBrand()`
  (`StoreBrandSeeder.php:258-259, 283-284`), and already served to the
  dashboard via `ShopBrandResource.php:36`.

**Tasks:**

1. Capture a favicon in the Shopify and BigCartel probe lanes (same
   `PlatformScraper::favicon()` call the other three probes make).
2. Persist it on the intent — additive nullable `favicon_url text` on
   `routing.source_intents`, same pattern (and same migration cautions) as
   `identifier_label` in §0. Thread it Probe → Projection → Placement →
   reconciler exactly as the label travels (§A chain).
3. Expose `favicon` on the suggestion wire in `index()`. NULL is fine — the
   frontend ladders down to a by-host service, then the platform glyph.
4. No new scraping infra needed; do NOT add favicon guessing beyond
   `PlatformScraper`'s deliberate no-`/favicon.ico` policy
   (`PlatformScraper.php:94-95`).

---

## §E — Uber Eats absent from the connect sheet

Root cause is a **frontend key mismatch** (kit slug `uber-eats` vs derived
registry key `uber_eats`) and is fixed dashboard-side. Backend involvement is
verification plus one FYI:

1. **Verify the derived connect route registers** — the catalog surface
   `uber_eats.order` (`app/Catalog/Definitions/UberEats.php:42`) has no
   hand-written descriptor in `PlatformRegistryServiceProvider.php`, so it
   relies on `DerivedDescriptorFactory` + the `LegacyPlatformMap` brand-prefix
   fallback (`LegacyPlatformMap.php:92-96`, `legacy_platform` is NULL in
   `bootstrap/catalog/compiled.php:4641-4674`). Run:

   ```
   php artisan route:list --path=platforms | grep uber
   ```

   Expected: `POST api/platforms/uber_eats/connect` (plus status route).
   Also confirm `GET /api/platforms/meta` emits `availability.uber_eats`
   for a food-sector business account (`can_use_online_ordering` requires
   business + food sector, `AccountCapabilities.php:69`; `cafe` IS in
   `SectorTaxonomy::FOOD_SECTORS`, so ollies qualifies). If either is
   missing, the gap is in `DerivedDescriptorFactory::candidates()` — report
   back before the frontend rekeys anything.
2. **FYI, latent inconsistency:** the menu-scraper config keys Uber Eats
   hyphenated (`config/partna.php:969` → `'uber-eats'`) while the catalog
   and `ConnectorRegistry.php:58` use `uber_eats`. Worth a look when
   touching the connector — ingest may never find its scraper config.
   (Adjacent, out of scope: DoorDash renders in the sheet but has no
   frontend roster descriptor.)

---

## Suggested order & verification

1. §0 rebase + re-ADD migration (unblocks §A; everything else stacks on the
   rebased branch or on `development` directly — your call, but §0's rebase
   conflicts get worse the longer it waits).
2. §B accept-lane fixes (user-facing breakage, highest pain).
3. §C dedup settle.
4. §D favicon capture + wire field.
5. §E verification (5 minutes; do first if convenient — the frontend's
   Uber Eats work gates on it).

Per-change verification: full Pest suite (the 08-24 branch shipped with 8801
passing; `test:pg` lane runs the hand-built DDL mirrors in
`tests/Pest.php:3452` and `tests/Postgres/*` — the re-ADD in §0 must keep
those mirrors in sync), `supabase db push --dry-run` before the real push,
and `cloud env:logs partna development --minutes 10` after deploying while
accepting a store suggestion end-to-end (expect the accept POST, the
CommerceProbeJob run, and an intent settled to `applied`).
