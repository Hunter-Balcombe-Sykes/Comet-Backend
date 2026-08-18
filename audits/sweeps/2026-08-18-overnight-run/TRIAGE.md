# TRIAGE — 2026-08-18 overnight-run sweep

**Verified 2026-08-19 against `deaba1a2b`** (branch `fix/r7-junk-jsonld-product-2026-08-18`).
Companion to `CONSOLIDATED.md`; that file remains the source of truth for finding
detail and checkbox state. This file records three things the CONSOLIDATED cannot:
**which findings are already fixed**, **which finding IDs are the same defect**, and
**an execution order that does not double-book a file**.

Re-verify before acting. A live reading goes stale on the next write, and this one
is a day old the moment it is committed.

---

## 1. Status

| | Count |
|---|---|
| Findings | 101 |
| **Fixed** | **4 IDs = 2 defects** |
| Open | 97 (P1 **15**, P2 52, P3 30) |

**Fixed:** `SCALE-6` ≡ `MIG-2` (`routing.link_observations_source_check`) and
`SCALE-22` ≡ `MIG-1` (`content.item_media_role_check`). Both migrations now carry the
CONVENTIONS §2 two-transaction `NOT VALID` + `VALIDATE` form.

Provenance matters: `git log --follow` puts that change in **`ee059f68a`
"fix(ci): green the three gates development has been red on since the W6–W10 run"**.
CI went red, someone fixed the lock behaviour, and the audit finding was collateral.
No audit unit has been executed. No `audit-fix/*` branch exists.

**Why nothing else moved.** 38 commits landed since the audit pin `49f02e231`, but
they belong to three *other* documents with their own ID namespaces:
`R1–R8` (`docs/reviews/2026-08-18-instagram-build-wave-RESULTS-RUN2.md`) and
`F*/N*/W*/X*` (`docs/overnight-2026-08-18/LOG.md` + the run plan). Real work, none of
it this sweep.

### Verification method

Two passes, so the result is evidence and not a guess:

1. **59 findings** cite files with **zero commits since `49f02e231`**
   (`git diff --name-only 49f02e231 HEAD`). They cannot have been fixed. Proof by
   absence of opportunity.
2. **42 findings** cite files touched since the pin. Each was read against the
   evidence block quoted in CONSOLIDATED.md. Only the 4 above had changed.

---

## 2. Duplicate map — 101 IDs are 89 defects

Lenses scan independently, so the same line gets reported under several IDs. Fix the
defect once and tick every ID in its row.

| Defect | IDs | Tier |
|---|---|---|
| `buildPools()` swallows `QueryException`, blanks all 7 pools, caches empty for full TTL | `LIFE-6` + `CCH-3` + `API-3` | P1 |
| `recordCandidates` inserts one row per candidate in a loop | `SCALE-11` + `CACHE-1` | P2 |
| Singleton facet writes are one UPSERT per facet per record | `SCALE-5` + `CACHE-3` | P1 |
| `Resolver` evidential pass is O(n²) per shared key, uncapped | `SCALE-10` + `CACHE-6` | P2 |
| `refreshItemCaches` mints slugs one item at a time | `SCALE-9` + `CACHE-7` | P2 |
| MediaMirror fetches to the 80 MB video cap before the 15 MB image cap | `SCALE-15` + `LIFE-19` | P2 |
| `platforms:enrich-pending-cards` scheduler entry incomplete | `LIFE-16` + `SCALE-20` | P2 |
| `content:refresh-item-caches` scheduler entry incomplete | `LIFE-17` + `SCALE-21` | P2 |
| `content.storefronts.user_id` nullable in the SQLite stand-in | `TEST-18` + `PARITY-1` | P3 |
| ✅ `link_observations` CHECK without `NOT VALID` | `SCALE-6` + `MIG-2` | fixed |
| ✅ `item_media` CHECK without `NOT VALID` | `SCALE-22` + `MIG-1` | fixed |

**Near-duplicate:** `SCALE-4` (anchor read per group) and `CACHE-5` (anchor insert per
coord) are different lines of the same `bindGroup()` — one session, not two.

**Deliberate double-listing:** `SCALE-11` appears in db-and-queue Bundle 1 *and*
Bundle 3. The audit flags this itself: *"implementer's choice which session picks it
up, not both."*

---

## 3. Why the lens bundles need re-cutting

`CONSOLIDATED.md` proposes **47 units — 34 bundles + 13 standalone** — covering 98 of
101 findings. They are sound *within* a lens and unusable *across* lenses, because
each lens is bundled by a scan that never sees the other twelve. The result:

| File | Units that schedule it |
|---|---|
| `app/Ingest/Projection/ProjectionWriter.php` | **6** |
| `app/Site/Pools/PoolResolver.php` | **5** |
| `app/Observers/Core/IntegrationConnectionObserver.php` | **5** |
| `MediaMirror.php`, `RoutingController.php`, `routes/console.php`, `GoogleBusinessAutoSync.php`, `IndividualProfilePayloadBuilder.php`, `Lander.php`, and the two migrations | 3 each |
| 6 further files | 2 each |

17 files are double-booked. `fix-flow.md` runs units sequentially on a branch, and
CLAUDE.md forbids touching a file another session owns — so the schedule as written
either serialises into one long queue or collides.

The audit's own P1 execute prompt already worked around this by hand, merging
`SEC-1` + `SEC-5` + `LIFE-19` across three lenses into one MediaMirror unit.
§4 does that systematically.

### Unassigned findings

- **`PARITY-1`** — deliberate. Its lens says *"not worth a dedicated session. Fold it
  into any future session that already has `content.storefronts` open."* That is the
  CLAUDE.md opportunistic policy; leave it there. (Its twin `TEST-18` sits in
  test-coverage Bundle 6, same file.)
- **`LIFE-10`** (FreshaConnector discards the real GraphQL error) and **`LIFE-12`**
  (`MediaMirror::fail()` has no aggregate escalation) — these fell through. Neither is
  in any bundle or standalone list. `LIFE-10` survives only in a garbled
  cross-reference at CONSOLIDATED.md:583 (*"see #LIFE-10 in the media-jobs section…
  i.e. #LIFE-… below"*), where the adjudicator lost the thread. §4 rehomes both.

---

## 4. Execution units, cut by file

37 groups instead of 47. Ordered by P1 density. Every open finding appears exactly
once; the lens bundle each came from is noted so nothing is lost.

### Tier 1 — carries P1s

| # | File(s) | Findings | P1s |
|---|---|---|---|
| **U1** | `Site/Pools/PoolResolver.php` (+ `ItemLinkRules.php`) | `LIFE-2` `LIFE-3` `LIFE-4` `API-1` `LIFE-8` · riders `LIFE-15` `SCALE-13` `SCALE-14` `TEST-1` | 4 |
| **U2** | `Ingest/Projection/ProjectionWriter.php` | `LIFE-1` `SCALE-4` `SCALE-5` · riders `CACHE-1/2/3/4/5/7` `SCALE-7/8/9/11/12` | 3 |
| **U3** | `Site/Sections/SectionCandidates.php` | `SCALE-1` `SCALE-2` | 2 |
| **U4** | `Services/Media/MediaMirror.php` + `WebpEncoder.php` | `SEC-1` `SCALE-3` · riders `SEC-5` `LIFE-12`* `SCALE-15`≡`LIFE-19` | 2 |
| **U5** | `Observers/Core/IntegrationConnectionObserver.php` | `LIFE-5` · riders `LIFE-13` `CCH-1` `LIFE-18` `CACHE-8` | 1 |
| **U6** | `Services/PublicSite/IndividualProfilePayloadBuilder.php` | `LIFE-6`≡`CCH-3`≡`API-3` · rider `API-2` | 1 |
| **U7** | `Ingest/Landing/Lander.php` | `WHK-1` · riders `SCALE-23` `SCALE-24` `CFG-5` | 1 |
| **U8** | `Ingest/Connectors/GoogleBusinessConnector.php` | `SEC-2` | 1 |

\* `LIFE-12` rehomed here from nowhere.

### Tier 2 — P2-led

| # | File | Findings |
|---|---|---|
| U9 | `routes/console.php` | `LIFE-7` `LIFE-16`≡`SCALE-20` `LIFE-17`≡`SCALE-21` `SCALE-29` |
| U10 | `Services/Platforms/GoogleBusinessAutoSync.php` | `LIFE-11` `SCALE-25/26/27/28` `CFG-4` |
| U11 | `Http/Controllers/Api/Routing/RoutingController.php` | `SEC-7` `TEST-7` `TEST-8` `API-4` |
| U12 | `Services/Http/SafeUrlFetcher.php` | `SEC-4` `SEC-6` |
| U13 | `Content/Identity/Resolver.php` | `SCALE-10`≡`CACHE-6` |
| U14 | `Console/Commands/ReshapePoolSectionsCommand.php` | `SCALE-18` `SCALE-19` |
| U15 | `Http/Controllers/Api/Platforms/FreshaController.php` | `CCH-2` `TEST-13` |
| U16 | `Ingest/SourceProvisioner.php` | `LIFE-14` `CFG-1` |
| U17 | `Ingest/Connectors/FreshaConnector.php` | `LIFE-10`* |
| U18 | `Jobs/Platforms/ScanPreviousWebsiteContentJob.php` | `LIFE-9` |
| U19 | `Console/Commands/EnrichPendingCardsCommand.php` | `SCALE-16` |
| U20 | `Console/Commands/RefreshItemCachesCommand.php` | `SCALE-17` |
| U21 | `Site/Documents/DocumentBuilder.php` | `TEST-3` |
| U22 | `config/partna.php` | `SEC-3` |
| U23 | `Http/Controllers/Api/Platforms/DisplaySettingsController.php` | `SEC-8` `API-5` `API-6` |

\* `LIFE-10` rehomed here from nowhere.

### Tier 3 — test-lane and config, absorb opportunistically

`U24` `tests/Feature/Site/DocumentBuilderRuleOpsTest.php` — `TEST-2` `TEST-9` `TEST-19` ·
`U25` `tests/Feature/Content/PoolLaneTest.php` — `TEST-6` `TEST-12` ·
`U26` `tests/Feature/Content/MediaSectionReshapeTest.php` — `TEST-5` `TEST-14` ·
`U27` `tests/Pest.php` — `TEST-18`≡`PARITY-1` ·
`U28` `tests/Feature/Ingest/LanderTest.php` — `TEST-4` ·
`U29` `tests/Unit/Ingest/ProjectionTest.php` — `TEST-17` ·
`U30` `tests/Feature/Content/PoolBorrowedMediaPinTest.php` — `TEST-15` ·
`U31` `Site/Pools/BorrowedMedia.php` — `TEST-16` ·
`U32`/`U33` the two `supabase/migrations/2026081900*.sql` schema tests — `TEST-10` `TEST-11` ·
`U34` `Ingest/Projection/IdentityKeyDeriver.php` — `CFG-2` ·
`U35` `Ingest/Connectors/AppleMusicConnector.php` — `CFG-3`

**Riders** are same-file findings of a lower tier. Absorbing them while the file is
open is the CLAUDE.md opportunistic policy — but the bounds still apply: nothing
listed *Standalone — do NOT bundle* in its own lens, nothing touching auth, money, a
migration or the public wire, and nothing another worktree owns. `U1`, `U6` and
`U8` are all on the public wire or PII; ride nothing into those without saying so.

---

## 5. The 15 open P1s

| ID | Defect | Unit |
|---|---|---|
| `SEC-1` | Mirrored image bytes decoded with no pixel guard — decompression-bomb DoS on the ingest queue | U4 |
| `SEC-2` | Google photo-attribution PII survives `when_unclaimed` redaction | U8 |
| `LIFE-1` | `resolveItems`/`bindGroup` identity resolution: no lock, no transaction | U2 |
| `LIFE-2` | `statsFor()` republishes a disconnected listing's rating | U1 |
| `LIFE-3` | Item's public `platform`/`url` can come from a deactivated connection | U1 |
| `LIFE-4` | Pool `links` can surface a link from a disconnected source | U1 |
| `LIFE-5` | Eager ingest source stranded forever if first dispatch is lost | U5 |
| `LIFE-6` | One pool query failure blanks all seven, cached full TTL | U6 |
| `SCALE-1` | Correlated scalar subquery per candidate row on the public path | U3 |
| `SCALE-2` | Correlated COUNT over the whole item table for auto-selection | U3 |
| `SCALE-3` | Whole media bodies held in PHP memory | U4 |
| `SCALE-4` | One `item_anchors` query per identity group per projection run | U2 |
| `SCALE-5` | One upsert per facet per item per projection run | U2 |
| `WHK-1` | Per-record fallback has no per-record try/catch — one poison record aborts the stream | U7 |
| `API-1` | `publishedAt`/`firstSeenAt`/`startsAt` still naive local time on the public wire | U1 |

Four of the fifteen are in `PoolResolver.php`, three in `ProjectionWriter.php`.

**Urgency, honestly stated:** there are **no P0s**. Production carries none of the
`content` / `ingest` / `routing` / `catalog` schemas and zero customers
(`core.users` = 0). Nothing here is live exposure today. This is pilot-readiness
work, and `SEC-2` is the only item with a data-remediation tail.

---

## 6. Known defects in the audit file itself

Carry these forward; they cost a session each if rediscovered.

- **`SEC-1` cites a method that does not exist.** It says to reuse
  `ImageVariantService::assertWithinPixelBudget()`. There is no such method. The real
  guard is inline and private in `ImageVariantService::loadImage()` (:467-490), and it
  is **path**-based (`getimagesize()` takes a filename) while `MediaMirror` holds
  bytes. Use `getimagesizefromstring()` — the pattern already in
  `GalleryAutoGrabber.php:130` and `LogoAutoGrabber.php:419`.
  `config('partna.image_max_pixels')` is real (`config/partna.php:1561`).
- **`LIFE-3` embeds a product ruling, not a bug.** It asserts `is_active = false` (a
  *paused* connection) should hide content publicly. A paused connection arguably
  should keep publishing what it already landed and merely stop syncing. Get the
  ruling. `LIFE-2` and `LIFE-4` (disconnected/deleted) are not in doubt.
- **The P1 execute prompt says "NINE findings" and lists ten** (`LIFE-2, 3, 4, 8`,
  `SEC-2`, `SEC-1`, `SEC-5`, `LIFE-19`, `LIFE-6`, `WHK-1`).
- **`WHK-1` cannot be reproduced on SQLite.** The scenario is a NUL byte rejected by
  `jsonb` with 22P05 — Postgres-only. A green `composer test` proves nothing; the
  regression test belongs in `tests/Postgres/` (`composer test:pg`).
- Scope headers: this sweep audited `b9e90e8d3..49f02e231` = 107 files under
  `app/ config/ routes/ database/ supabase/ tests/`. The superseded
  `overnight-core` folder mis-stated the end ref as `fe8c7f253`.
