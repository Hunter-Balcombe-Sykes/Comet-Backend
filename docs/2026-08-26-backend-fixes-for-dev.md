# Backend fixes — 2026-08-26 (handoff)

Running list for the backend dev. Item 1 is fully diagnosed with evidence;
add further items below it.

---

## 1. Menu re-fetch cannot revive dishes retired by reconciliation

**Severity:** real user-facing bug — any owner who disconnects and reconnects
an ordering platform (or Google Business, which seeds one) ends up with a
permanently empty menu. Not an artifact of dev tooling; reproduced end-to-end
on the dev env with `ollies` on 2026-08-26.

### The failure sequence (all timestamps from dev logs / DB that day)

1. `02:41:39` — Google Business connect seeds an `uber_eats` ordering
   connection; `MenuFetchJob` scrapes Uber Eats and writes **66 dishes**.
2. `02:42:31` — owner disconnects Google Business → cascade soft-deletes the
   seeded `uber_eats` connection.
3. `02:44:11` — the next `MenuFetchJob` run sees zero ordering links and
   correctly retires all 66 dishes: `retireAbsentDishes` →
   `ManualMenuWriter::markRemoved()` → sets `content.items.removed_at`.
4. Owner reconnects Uber Eats. `MenuFetchJob` re-scrapes; every dish
   re-emits under its **identical deterministic coord**
   (`manual:menu:{menu_id}:…`), so the writes resolve onto the 66 retired
   rows (`updated_at` bumped, facets refreshed) — but the items **stay
   removed forever**.

### Root cause

`items.removed_at` is one-way **by design**: it is the owner-delete marker,
and `ProjectionWriter::upsertSourceItem()` deliberately clears only
`source_items.removed_at` on reappearance (see the comment at
`app/Ingest/Projection/ProjectionWriter.php:518` — "The user-level delete
lives on items.removed_at and is never touched here"). That rule exists so a
re-appearing scrape can never resurrect a dish the owner deleted.

But `MenuFetchJob`'s reconciliation (`absentDishIds` + `retire`, around
`app/Jobs/Platforms/MenuFetchJob.php:701` and `:743`) retires
scraper-owned dishes **through the same field**. Retire-by-reconciliation is
therefore indistinguishable from owner-delete, and no code path ever calls
the existing revive helper (`ManualPoolWriter::clearRemoved()`,
`app/Services/Content/ManualPoolWriter.php:116`) from the fetch side — its
spec (§3.5) currently allows it only from the explicit restore endpoint.

### The fix

In `MenuFetchJob::persist()`'s write loop: when a dish this scrape emits
resolves to an existing item whose `removed_at` is set AND the dish is
**scraper-owned** (same `order_platform`-membership test `absentDishIds`
already uses via `scraperOwnedItemIds()`), call
`ManualMenuWriter::clearRemoved($itemId)` before/with the facet write.

Guardrails that must hold (all machinery already exists in `absentDishIds`):

- **Never revive an owner-deleted dish.** Skip revive for any dish whose
  normalized name is in `menus.suppressed_items` (the owner's delete/detach
  intent) — same exemption the retire pass applies.
- **Never clobber owner edits.** A coord carrying a `content.manual_overrides`
  row (`ownerLockedCoords`) keeps its current live/removed state.
- Non-scraper dishes (hand-added, photo-scan — no `order_platform`
  membership) are never candidates, same as in the retire pass.
- `clearRemoved()` re-mints the slug; note `freeSlug`/`remintSlug` collision
  behaviour if another dish took the name in between (the writer already
  handles suffixing — just don't assume the old slug returns).
- Update the §3.5 spec note on `clearRemoved()` ("legitimate ONLY from the
  explicit restore endpoint") to name this second legitimate caller.

### Tests to add

- Feature test: connect ordering platform → fetch (items live) → disconnect
  (items retired) → reconnect + fetch → **items live again**, same item ids
  (identity stable), slugs resolvable.
- Owner-delete stays sticky: owner deletes a dish (suppressed_items), then
  disconnect/reconnect cycle → that dish stays removed while its siblings
  revive.
- Owner-edited dish (manual_overrides) retains its state through the cycle.

### One-off data note (already handled, no action)

The 66 `ollies` dishes were revived manually on dev via `clearRemoved()`
(2026-08-26 ~03:45) after a forced cloud re-fetch refreshed their facets —
that account needs no migration/backfill from this fix.

---

## 2. Cross-source menu item matching misses obvious duplicates

**Severity:** quality bug — the same product renders as two menu cards when
it arrives from two sources (ordering-platform scrape vs website HTML scan
vs Google photo OCR). The current matcher is exact-normalized-name, and a
manual audit of `ollies` (89 live items, 2026-08-26) found five+ real
misses. No dedup ran on any of them.

### Confirmed misses from the audit (use as test fixtures)

| Website / scan item | Uber Eats item | Confidence |
|---|---|---|
| Orthodox Drip Coffee Bags ($21.00) | Orthodox Drip Coffee Bags **(7 Sachets)** ($21.00) | certain — identical price, name differs only by parenthetical |
| Italo Disco Espresso Concentrate 1.2L ($39) | **Cold Brew Bags. (**italo Concentrate 1.2l**)** ($27.30, in a promo category) | high |
| Orthodox House Espresso Blend ($25) | Orthodox Whole Beans ($23) | high |
| Italo Disco Italian Espresso Blend ($25) | Italo Disco Whole Beans ($23) | high |
| Wide Awake Cold Brew Concentrate 2L ($60) | Cold Brew Bags. (wide Awake) ($42) | medium |
| STRAWBERRY ICED MATCHA LATTE ($6.50) | Cold Brew Can. (strberry Matcha) ($4.55) | medium |

Also observed, related but different-shaped:

- Google OCR promotes **flavour lines to standalone dishes**: "Blueberry",
  "Strawberry", "Biscoff & Chocolate Syrup" are shake/pancake variants, not
  items (Uber carries the parent "Shakes" item).
- Vendor-side near-twin within ONE source: "Almighty" vs "Almighty Soda",
  same category, both $7.80 — probably two variant groups of one brand.

### Failure patterns the matcher should learn

1. **Parenthetical suffixes/infixes** — "(7 Sachets)", "(wide Awake)",
   "(italo Concentrate 1.2l)". Strip parentheticals for a secondary match
   pass, AND run the reverse: match on the parenthetical CONTENT when the
   outer name is a generic wrapper.
2. **Generic-wrapper prefixes** — Uber flattens variant groups into
   "Cold Brew Bags. (X)" / "Cold Brew Can. (Y)". When a name is
   `<generic>. (<specific>)`, the specific half is the identity.
3. **Retail vs marketplace naming of one product** — "…House Espresso
   Blend" vs "…Whole Beans". A brand/line token match ("Orthodox",
   "Italo Disco", "Wide Awake" + coffee-product context) should at least
   flag these as merge candidates.
4. **Price is NOT a reliable tiebreaker** — Uber applies markups and promo
   discounts ($39 → $27.30 in "Save on Select Items"), so equal-price can
   confirm a match but unequal-price must not veto one.
5. **Vendor typos/abbreviations** — "strberry". Exact tokens fail; a
   fuzzy pass (edit distance on normalized tokens) catches it.
6. **Trailing punctuation** — "Bourdain Roll.", "Cronut.", "Cookie
   (anzac)." — confirm `normalizeName` already strips these; add cases.
7. **Units/sizes normalization** — "1.2L"/"1.2l", "2L", "225g",
   "7 Sachets" should compare equal case-insensitively and only ever
   DISAMBIGUATE (two sizes of one product are two items), never block a
   match through formatting alone.

### Suggested shape

- Keep exact-normalized-name as pass 1. Add pass 2: normalized with
  parentheticals stripped + generic-wrapper unwrapping + unit
  normalization. Add pass 3 (flag-only or high-threshold merge): fuzzy
  token match scoped to the same menu, with brand/line token overlap.
- Merge direction per existing doctrine (`MenuSource`): ordering-platform
  content wins; the scan/website twin folds into it (its category
  membership can be kept as extra membership rather than a second card).
- **Over-merge guardrails:** never merge two items from the SAME source
  pass (the vendor listed them separately on purpose — "Almighty" vs
  "Almighty Soda" stays two unless a human says otherwise); never merge
  across genuinely different sizes ("Whole Beans 225g" vs a 1kg listing);
  flag-only below a confidence threshold.
- The OCR flavour-fragment problem (#above) is its own smaller item: a
  scan category whose members are all 1–2-word flavour names that all
  match one scraped item's variant list should attach as variants, not
  dishes — fine to split into a follow-up ticket.

### Tests to add

- One fixture per row of the table above (the certain + high rows assert
  merge; the medium rows assert "flagged candidate", not auto-merge).
- Same-source pair ("Almighty"/"Almighty Soda") asserts NO auto-merge.
- Unequal-price same-name still merges; equal-price different-product
  ("Matcha" $7.80 vs "Almighty" $7.80) never matches on price alone.

---

## 3. Scraped-name casing + menu category quality

Two related quality problems, both observed on `ollies` (2026-08-26).

### 3a. Case normalization for scraped/OCR names

Menu photo OCR, the old-website HTML scan, and the services scrapers emit
names in ALL CAPS ("EXPRESS LUNCH", "STRAWBERRY ICED MATCHA LATTE",
"FORCE DUALITY COFFEE COLLECTION") or all-lowercase. We want title case.

**Rule: only re-case a string when it is UNIFORMLY cased** (all-upper or
all-lower). Mixed case means the vendor typed it deliberately — leave it.
This one guard is what makes the transform safe to run blind.

Title-casing details:

- Capitalize each word; keep small connector words lowercase mid-name
  (of, and, the, with, on, in, a, &) — "Save on Select Items", never
  "Save On Select Items". First and last word always capitalize.
- Uppercase allowlist survives the transform: AU state abbreviations
  (WA, VIC, NSW, QLD, SA, TAS, NT, ACT), dietary marks (GF, DF, V, VG).
- Unit tokens pass through untouched (1.2L, 225g, 7pk, 2L).
- Applied at PROJECTION/WRITE time in the three producers (Google photo
  OCR scan, website menu HTML scan, services scrapers) — not a display
  hack, so slugs, the item-2 matcher, and the wire all see the clean
  name. Item names AND category names both go through it.
- Ordering-platform scrape names (Uber/DoorDash) are OUT of scope for now
  (marketplace names are usually deliberately cased); note as a possible
  later extension.

### 3b. Menu category quality

Current output carries categories that are marketplace merchandising or
scan artifacts, not menu taxonomy: "Menu" (OCR wrapper), "Featured items"
and "Save on Select Items" (Uber rails). We want real taxonomy ("Drinks",
"Pastry", …).

1. **Per-provider category denylist** (config/catalog DATA, not code):
   rail categories skipped at projection — "Featured items", "Save on
   Select Items", "Picked for you", plus generic wrappers from scans
   ("Menu", "All", "Home"). Safe because membership is additive: every
   rail item observed also holds a REAL category (e.g. Almond Croissant
   sits in featured + pastry), so dropping the rail loses nothing.
2. **Fallback category "More"**: any item left with zero categories
   (scraped uncategorized, or orphaned by rule 1) auto-files into one
   synthesized "More" category. It only materializes when at least one
   item needs it, sorts LAST, and an item leaves it automatically the
   moment a real category claims it — it never competes with actual
   taxonomy.
3. Never let a scan's own slug produce a display category named after the
   scan (`menu:scan:menu` → a category called "Menu" is how this got in).
4. Flag (log only, don't block) when a scan produces a category whose
   members ALL already live in another category — that is usually a
   wrapper the denylist should learn.

### Tests to add

- OCR "MENU" wrapper category disappears; its items re-home to their real
  categories.
- "Save on Select Items" dropped; its items remain, intact, in their real
  categories.
- Uncategorized item lands in "More"; migrates out (and "More"
  dematerializes) when a real category later claims it.
- "FORCE DUALITY COFFEE COLLECTION" → "Force Duality Coffee Collection";
  "'23 DEEP WOODS CHARDONNAY WA" keeps "WA"; "EXPRESS LUNCH" → "Express
  Lunch"; a mixed-case vendor name is untouched; "Save on Select Items"
  connector words stay lowercase.

---

## 4. Routing: deep links to recognised platforms fall into custom links

**Severity:** routing bug — a pasted deep link the engine RECOGNISES still
lands as a plain custom-link card. Seen with OpenTable; not
OpenTable-specific: any platform whose deep links carry the ID in query
params, or that has multiple detection rules for one surface, can hit it.

### Symptom (live case)

A pasted OpenTable booking link
(`opentable.com.au/booking/experiences-availability?experienceId=782864&restref=291533&rid=291533`)
became a custom-link card instead of routing to the OpenTable reservation
surface. The routing observation shows recognition succeeded —
`surface_key: opentable.reserve` — but scored **confidence 59 / margin 0 →
verdict `note`**. A `note` writes no source intent
(`Verdict::writesIntent()`), so the link fell through to the links pool.

### Root cause — two scoring defects in `app/Routing/LinkProjector.php`

1. **Margin measured the gap to the plain runner-up candidate.** OpenTable
   declares a `rid` query rule AND a `restRef` query rule; a URL carrying
   both params matches both rules at the same score for the SAME surface —
   two agreeing rules read as margin 0 (maximum ambiguity) when they are
   actually maximum agreement. Margin should be the gap to the best
   **different-surface** candidate.
2. **Query-captured identifiers scored +15 vs +35 for path-captured**,
   even though `?rid=291533` names the restaurant exactly as precisely as
   `/restaurant/profile/291533`, and the catalog declares both shapes at
   the same `EvidenceStrength`.

### The fix (already written — needs rebase + ship)

Commit `312bda304` ("Route a query-identified deep link to its surface
instead of the links pool") on the local, unpushed branch
**`fix/suggestions-inbox-and-opentable-routing`** (2 commits ahead, ~133
behind `development` — rebase before shipping). It:

- (a) makes margin scan the sorted candidate list for the first
  DIFFERENT-surface entry;
- (b) adds +20 to a captured query-sourced identifier for exact parity
  with the path form.

Result for this URL shape: 59→79 confidence, 0→79 margin — clears the
reservations suggest threshold (55) even after the 10-point indirect
penalty; the link becomes a real connection.

Comes with `tests/Feature/Routing/LinkProjectorSurfaceMarginTest.php`, and
was verified against `RoutingCorpusTest`: no positive changed surface, the
150+ negatives still never match, and the bonus can't rescue a below-FLOOR
detector (worst case 47 vs floor 25 — reasoning in the code comments).

**Optional post-deploy:** `php artisan routing:reproject --since=30d` to
re-route already-stranded links — but FIRST verify reproject actually
re-files `note`-verdict links (untested claim).

---

## 5. Routing: previous-website links get carded into custom links (race)

**Severity:** data-quality bug — after a Google Business claim, a user's
links pool filled with internal pages of their OWN previous website
(`stali.com.au/pages/rewards`, `/pages/st-ali-recipes`,
`/collections/new-arrivals-1`, … — 7 cards).

### What is supposed to prevent this — and does exist, in two layers

1. `WebsiteImporter` deliberately cards NOTHING from a website crawl
   (notes-only; carding site navigation is flooding). Verified live: the
   website scan noted 65 links, carded 0. This layer worked.
2. `CustomLinkSeeder::seedCustom()` (~line 98) host-matches every URL
   against `workplace.previous_website` (`matchesPreviousWebsite()`) and
   skips with a `platforms.custom_link_seeder.skipped_previous_website`
   log line. (Deliberate exception: `addManual()` bypasses it — an
   explicit user paste is intent.)

### What actually went wrong — a race, not a missing guard

The cards came from the **link-in-bio lane**: the user's Linktree links to
their own old-site pages, and `LinkInBioImporter` (correctly) cards
unrecognised bio links via `seedCustom()`. The guard ran on every URL —
but at that moment `workplace.previous_website` was still NULL, because
the Google Business flow only persisted it ~55 seconds later.

Verified timeline from the live run ledger: link-in-bio unroll cards
written 02:42:57–02:43:04; `previous_website` written 02:43:55
(`field_sources` provenance stamp). Two async lanes of one signup raced;
the carding lane won. Same bug class as the Instagram
"swap-with-yourself" race fixed on 08-24: a write-time check against
state another lane hasn't written yet.

### The fix — two halves; the second closes it

1. **Narrow the window:** persist `previous_website` as early as the
   Google Business flow knows the website URL — BEFORE any harvest/unroll
   jobs are dispatched — instead of in the later enrichment pass
   (`GoogleBusinessAutoSync::seedWorkplace()` currently lands it after
   the Instagram scrape → Linktree unroll chain has already run).
2. **Make it race-proof:** ordering between async lanes can't be
   guaranteed, so add the reconciliation half — when `previous_website`
   is set or changed (the workplace observer already watches this field
   to trigger the scan), sweep the `custom_links` pool and remove
   SCRAPE-SEEDED cards whose host matches the new value. Manual adds must
   survive the sweep, mirroring the `addManual()` exception — if card
   origin isn't currently stored, record it at write time. This turns the
   guard from "correct only if lanes finish in the right order" into
   "eventually correct regardless of order."

### Cleanup

The affected test account (`ollies`) has 7 stali.com.au cards plus the
stray OpenTable card to remove once the fixes land (or let the fix-2
sweep handle the stali ones).
