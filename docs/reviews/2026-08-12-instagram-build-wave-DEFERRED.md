# Instagram build wave — deferred findings, triaged 2026-08-12

Findings from `docs/reviews/2026-08-11-instagram-build-wave-RESULTS.md` that the content-pool
programme (`docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`) will **not**
resolve. Checked slice-by-slice against §7 and §4.1 of that spec on 2026-08-12.

Pick these up once the pool slices are done. Each entry states what it is, the evidence, why the
pooling work doesn't cover it, and what a fix looks like — so none of it has to be re-derived.

**Already closed, for the record:** N1 (catalog now backstops the hand-typed host tables,
`5c2572c10`, live on dev) and N4 (probe starvation — resolved as a consequence of N1). **F8 was
never open** — it was fixed at `751277dd9`, an ancestor of the tested commit; the RESULTS report
carried it forward in error.

---

- [ ] **N2** · `linkin.bio` bio pages unroll to **zero** links · **the one I'd do first**

  **Plain English.** A user whose Instagram bio link is a `linkin.bio` page gets a site with
  nothing on it but their Instagram. Every link they actually put in their bio is lost. It fails
  silently — no error, no failed job, and the scan reports success.

  **Technical.** `LinkInBioDetector.php:19` matches `linkin.bio`, so `LinkInBioScanJob` is
  dispatched correctly (the 2026-07-23 host-list fix works — that part is confirmed). But the page
  is a client-rendered SPA. `SafeUrlFetcher::tryFetch()` does one plain HTTP GET, and
  `WebsiteLinkHarvester::allOutboundLinks()` parses `<a href>` only, so it returns an empty set and
  the job logs `links_seen: 0, outcomes: []` and exits clean.

  **Evidence (2026-08-11, `supernormal_180`, 83K followers).** `payload.website` =
  `https://linkin.bio/supernormal_180`. Fetched independently: HTTP **200**, **6,441 bytes**,
  `<title>Linkin.bio</title>`, **0 `<a href>` anchors**; the strings `opentable`, `sevenrooms` and
  `ubereats` appear nowhere in the delivered HTML. Result: 0 custom links, 0 platform connections,
  `pageOrder: ["home"]`, one ranked action.

  **Why the pooling work misses it.** This is link *routing*, not content storage. No slice touches
  `LinkInBioScanJob`, `LinkInBioDetector` or `WebsiteLinkHarvester`. `linkin.bio` appears in zero
  plans and zero specs.

  **Worth noting it regressed sideways.** The 07-23 note says the fix stopped this account's bio
  link "landing as one inert custom link instead of unrolling". It now lands as **zero** links —
  strictly worse than one inert card, because the URL is no longer preserved anywhere on the site.

  **Shape of a fix**, cheapest first — needs a decision, not just a patch:
  1. **Floor, ~1h.** When a matched bio host yields 0 anchors, seed the bio URL itself as a custom
     link. Restores the pre-07-23 behaviour as a fallback so nothing vanishes. Do this regardless
     of which option below is chosen.
  2. **Cheap win, small.** Several of these hosts expose the links in embedded JSON
     (`__NEXT_DATA__` / JSON-LD) in the same response. Worth checking `linkin.bio` specifically
     before reaching for a browser.
  3. **Real fix, L.** Headless rendering for this host class. New infra, new cost, new SSRF surface
     — its own decision, not a bug fix.

  `links_seen: 0` on a *matched* bio host is already logged and is an unambiguous signal. Nothing
  alerts on it today; that alone is worth wiring up.

- [ ] **N3** · the generic shop probe publishes junk products, publicly

  **Plain English.** We scraped a site, decided a page was a product, and put it on someone's
  public page as a Shop tab — where the "product" was an unfinished draft with no price, no image
  and no description.

  **Technical.** `CommerceProbeJob::probe()` → `GenericShopScraper::readProductPage()` returns
  `OUTCOME_PRODUCT` → `ShopProductSeeder::seed()` writes `site.shop_products` and mints a
  `partna.storefront` connection. The guard at `GenericShopScraper.php:183` accepts a page if
  `og:type` contains `product` **OR** a price meta exists — so an `og:type=product` page with no
  price, no image and a placeholder title passes. Nothing inspects the title.

  **Evidence (2026-08-11, `crucibletattooco`).** Input `https://paytherent.net.au/` — an Indigenous
  solidarity rent-payment site that genuinely runs WooCommerce, so "there is a store here" was
  *correct*. What landed:

  ```
  site.shop_products.data = {"url":"https://paytherent.net.au/","title":"Private: Demo",
    "price":null,"image":null,"images":[],"vendor":null,"currency":null,
    "description":null,"available":true}
  ```

  `Private:` is WordPress's prefix for a **non-published** post. Publicly surfaced: that account's
  `pageOrder` became `["home","shop","links"]` with `rankedActions[0] = ('page','Shop')`.

  **Why the pooling work misses it.** Slice 5 decomposes `shop_products.data` into
  `items`/`offers`/`item_variants`/`item_media`. That is a **storage migration, not a quality
  gate** — a junk product migrates into a junk pool item, landing somewhere more permanent and
  better-rendered than before. Do the filter *before* slice 5 runs, or it carries the mess forward.

  **Shape of a fix, S.** Two independent guards in `productFromOpenGraph()`:
  - reject placeholder titles — `Private:`, `Protected:`, `Draft`, empty-after-strip (mirror the
    existing placeholder-filter thinking in `categoryOrNull()`, which already solved the same class
    of problem for Instagram's literal `"None"`);
  - require at least one of price **or** image before treating a read as a product. Title alone is
    not a product.

  A page that fails both should still resolve as a **storefront** (`seedStore`) or fall through to
  a custom link — not vanish.

- [ ] **F9** · expired unclaimed builds still hold a per-IP signup slot · **already triaged, no scheduled work**

  Unchanged at `PreAccountBuild.php:102-105` — `scopeLive()` is `whereNull('claimed_at')` only.
  Ceiling is ~28h, not "forever" (`builds:prune-expired` runs `dailyAt('03:40')`). Failure mode is
  a clear 4xx, no data loss, self-heals overnight.

  **Not in the pooling programme at all** — pre-account signup, nothing to do with `content.*`.

  **The repair is booby-trapped and the trap is the load-bearing part.** Do **not** add `expires_at`
  to `scopeLive()`: `findLive()` dedupes through that same scope and deliberately mirrors the
  `pre_account_builds_live_source_unique` partial index. Desyncing them turns a 28h cap inflation
  into a 28h *outage* on that source ref, and the default test lane cannot catch it (the SQLite
  stand-in has no unique index). Full reasoning, including the correct shape
  (`scopeCountsTowardIpQuota()`), is in the 08-10 report's F9 entry — read it before touching this.

  Standing disposition: **OPPORTUNISTIC**. Fix in passing the next time `PreAccountBuild.php` or
  `PreAccountBuildService.php` is open for real work. Do not schedule it.

---

## F7 — CLOSED. Fixed 2026-08-11, before this document was written

**F7 was "an auto-routed Fresha connection can never acquire services by any automatic path".** It
was fixed at `2ca21904e` ("fix(fresha): self-heal failed auto connects on scheduled refresh"), an
ancestor of `development`. `FreshaFetch.php:51-68` now detects `payload.connectMode === 'auto'`,
scrapes the menu and runs `FreshaAutoSelector` to mint a selection, so the state F7 describes
repairs itself on the next scheduled sweep. Dev carries **zero** connections in it.

This is the same error this document already records for F8: a finding carried forward from the
RESULTS report after the code had been fixed. Verify a finding against the current tree before
handing it on.

**Two premises in the original entry were also wrong**, recorded so they are not reused:

- The state is keyed on `payload.connectMode`, not `payload.source`. On dev `source` holds routing
  provenance (`instagram`, `showcase`, NULL) and is never `"auto"`.
- `FreshaFetch`'s guard is `is_array($selection)`, which a decoded JSON **object** satisfies. SQL
  testing `jsonb_typeof(...) <> 'array'` reports live selections as blocked.

### What F7 was mistaken for — slice 3's real blocker, verified 2026-08-12

`FreshaServiceProjector` has landed zero `content.*` records (convergence spec §1.6), and F7 is
**not** the cause. There are two classes of that name and they sit on different lanes:

| Class | Fed by | Writes | State |
|---|---|---|---|
| `App\Services\Platforms\FreshaServiceProjector` | `FreshaFetch` | `site.services` | working — 59 live rows, refreshed 2026-08-12 |
| `App\Ingest\Projection\FreshaServiceProjector` | `FreshaConnector::pull()` | `content.*` | 0 records — the §1.6 figure |

`ProjectorRegistry.php:28` maps `fresha/services` to the ingest class. `FreshaConnector` never reads
`payload.selection`; it takes a slug and fires a pinned GraphQL persisted query. `selection: null`
therefore cannot explain the zero, and fixing F7 lands no `content.source_items`.

`ingest.runs` shows `services` `unavailable` on all four sources on every run since 2026-07-28 — not
a 304. The cause is one hardcoded variable: `FreshaConnector.php:239` sends
`shouldShowAllEmployees: true`, which returns the employee-picker screen with an empty
`screenServices`. Verified live against three real dev slugs on 2026-08-12:

```
allEmployees=true   -> screen=BookingFlowScreenAllEmployees  screenServices={}  categories=None
allEmployees=false  -> screen=BookingFlowScreenServices      categories=5/12/7  services=25/40/22
```

The pinned hash is **valid** — every call returned HTTP 200 with a well-formed
`bookingFlowInitialize` and no `errors`. The connector's `Unavailable` message blames a rotated
persisted-query hash and sends the reader to a re-pin runbook for a hash that is fine; correcting
that message is part of the fix.

---

## Closed by the pooling work, listed so nobody re-raises it

**§2.4 — no Instagram post media reaches `site.site_media`** (0 rows on all six accounts; media
rides as URLs inside `platform_connections.payload`). Slice 1 resolves this **by routing around
it**: media lands in `content.items WHERE kind='media'`, not `site_media`.

Consequence worth recording: the `core.enforce_site_gallery_max6` trigger and the `webp`-variant
rule, which the RESULTS report marks UNTESTED/VACUOUS, are **legacy** on this path and slice 7
drops those tables. They were never going to be exercised by the Instagram pipeline. Do not open
work to "fix" their coverage.
