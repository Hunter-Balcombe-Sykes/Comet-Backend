# Link probe host dedupe

**Date:** 2026-08-10
**Status:** Design approved (rev 3, post independent review), not implemented
**Scope:** `RouteContext` + `LinkRouter`. Two files, one existing test extended, one new test. No migration, no public wire change, no auth or money path — outside the blocker gate.
**Reviewed by:** three independent reviewers (premise check, simplicity/YAGNI, scalability/failure-mode). Rev 1 additionally specified an origin-scoped probe cache; it was found unshippable and is now [deferred](#deferred--origin-burst-memo) with its findings recorded.

## Problem

Observed live 2026-08-10 scanning `crucibletattooco`'s link-in-bio page. The page carried 9 unclassified links, six of which were pages of the **same** website:

```
1. crucibletattooco.com.au/                   → probe 1
2. crucibletattooco.com.au/appointment.html   → probe 2
3. crucibletattooco.com.au/artists.html       → probe 3
4. crucibletattooco.com.au/aftercare.html     → probe 4
5. crucibletattooco.com.au/accessibility.html → probe 5
6. crucibletattooco.com.au/feedback.html      → probe 6   ← budget spent
7. paytherent.net.au                          → never examined
8. bsky.app/profile/…                         → never examined
9. au.pinterest.com/…                         → never examined
```

`RouteContext::DEFAULT_MAX_PROBES` is 6 per run (`RouteContext.php:24`). `LinkRouter` has a first-link-per-platform dedupe for *classified* links (`RouteContext::$seenPlatforms`, `LinkRouter.php:79`) but no host dedupe for unclassified ones — `routeUnclassified()` consumes a probe and dispatches without asking whether this host was already probed (`LinkRouter.php:117`).

Links 8 and 9 genuinely fall through: `bsky.app` and `pinterest.com` are absent from `WebsiteLinkHarvester::SOCIAL_HOSTS`, so they reach `routeUnclassified` like any unknown URL.

Two consequences:

**Starvation.** The links most likely to be redundant crowded out the ones that were not. Nothing recorded it: a starved link returns `RouteResult::custom()`, byte-identical to the `custom` a gate denial or a probe miss returns.

**Amplification.** A "probe" is not one request. Per unclassified URL, on a **full miss** (the crucible case — nav pages with no storefront markers resolve `OUTCOME_NO_PRODUCT`):

| Stage | Requests | Scope |
|---|---|---|
| `GenericShopScraper::readProductPage()` (`CommerceProbeJob.php:121`) | 1 | path |
| BigCartel probe — pure regex, no fetcher (`BigCartelScraper.php:21-29`) | 0 | — |
| Shopify probe → `origin/meta.json` (`ShopifyScraper.php:36`) | 1 | origin |
| WooCommerce probe → both Store API URL forms (`WooCommerceScraper.php:33-42`) | 2 | origin |
| Squarespace probe → pasted URL, then `origin/shop`, `/store`, `/products` (`SquarespaceScraper.php:31-34`) | 4 | 1 path + 3 origin |
| Generic probe → reads the page again (`GenericStorefrontProbe.php:48`) | 1 | path |

**9 is the full-miss upper bound, not a constant.** An unreachable host costs 1 (`CommerceProbeJob.php:135`); a page yielding a product costs 1. For the crucible URLs the full 9 applies. Six of the nine are byte-identical across every path on the host, so six URLs ≈ 54 requests, plus one `EnrichLinkCardJob` fetch per created card (`CustomLinkSeeder.php:161`) ≈ **60 requests to one small studio's website** in a few minutes.

`ProbeBudget`'s per-run dimension did not bite: it is bound `scoped` (`AppServiceProvider.php:141`), so each `CommerceProbeJob` is its own run and resets it. Since a job probes exactly once, that cap is structurally incapable of firing in this flow. Only user-daily (40) and global-daily (2000) applied.

### Already shipped (2026-08-10, same day)

`platforms.link_in_bio_scan.completed` on `LinkInBioScanJob` (`:139-146`), reporting `links_seen`, `own_host_skipped`, `outcomes`, `probe_budget`, `probes_spent`, `probes_denied`. `probes_denied > 0` is the only signal separating a fully-scanned page from a starved one. `RouteContext::probesDenied()` and `summary()` feed it.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | **Keep all six link cards.** Dedupe changes probing only. | "Nothing vanishes" is load-bearing. A repeat-host link keeps returning `custom()`, so `LinkInBioScanJob` writes its card as today. See Out of scope for the rejected alternative. |
| 2 | **Dedupe key = lowercased host, one leading `www.` stripped, scheme and port ignored.** | The question is *"have I already asked about this website?"*, so coarse is right. `shop.site.com` stays distinct from `www.site.com` — a shop subdomain is often a separate storefront. Registrable-domain keying was rejected for collapsing those two. |
| 3 | **The shop-hint's second probe is charged to the same budget of 6.** | The hint changes *which* links get probed, never *how many*. A soft ceiling is the property that made this bug invisible. |
| 4 | **Dedupe lives inside `RouteContext::consumeProbeFor()`, not at the two `LinkRouter` call sites.** | Makes "check both arms" structural rather than a discipline rule — there is then exactly one place in the codebase that can spend a probe. |
| 5 | **No shared `SiteKey` class; a private method on `RouteContext`.** | The repo already contains ~10 private `www.`-stripping helpers (`ItemLinkRules.php:79-83`, `CustomLinkSeeder.php:188-192`, `GoogleBusinessApifyScraper.php:280-292`, and others). A new class would be the eleventh while retiring none, and with the origin memo deferred it would have a single caller. |
| 6 | **Origin probe memo deferred**, not built. | Its correctness fix (Squarespace is path-scoped) cut its benefit from 7 requests saved to 3. See [Deferred](#deferred--origin-burst-memo). |

## Design

Both probe-spending arms already funnel through `consumeProbe()` (`LinkRouter.php:117` and `:232`). The dedupe goes there, so it cannot be forgotten at a call site.

`RouteContext` gains private state and one public method:

```php
/** @var array<string, true> "<siteKey>:<shape>" => true */
private array $seenSites = [];

private int $sitesDeduped = 0;

/**
 * Claim a probe for $url — at most one per website per shape per run. Six nav
 * links of one host spent the entire budget live 2026-08-10 and starved the
 * three links behind them.
 */
public function consumeProbeFor(string $url): bool;

/** Lowercased host, one leading `www.` stripped. Null = unparseable → never dedupe. */
private function siteKey(string $url): ?string;
```

**Shape** is `product` when the path contains `/product/`, `/products/` or `/item/`, else `plain`.

`/shop` and `/store` are excluded — `SquarespaceScraper::discoverProductsUrl()` already walks them off the origin (`SquarespaceScraper.php:14,31-34`), so probing any URL on the host already covers them. `/p/` is excluded as too generic. The trailing slashes are load-bearing: `/products/` matches a specific item, not the `/products` collection root that scraper already reaches.

> **Corrected 2026-08-10 during implementation.** This paragraph previously also excluded `/collections/`, on the grounds that the canonical Shopify form `/collections/x/products/y` carries 3 internal slashes and `ProbeGate::lexicalRefusal()` refuses ≥2 as `not_a_storefront_root` (`ProbeGate.php:112`), so the hint would spend a budget slot on a URL the gate then refuses. That reasoning was wrong twice over.
>
> First, it never worked: `/collections/x/products/y` **contains** `/products/`, so omitting `/collections/` from the list changes nothing — the URL matches a hint regardless.
>
> Second, and more seriously, it **contradicts "Gating the stage-1 fetch"** under Out of scope below. `ProbeGate` binds only the shop arm (`seedShop()` → `StoreBrandSeeder`). The unclassified arm — every custom-domain store, and the arm the 2026-08-10 incident actually hit — runs `CommerceProbeJob::probe()` → `readProductPage()` on the pasted URL with no gate at all, and that method is explicitly documented to keep deep URLs rather than false-block them (`GenericShopScraper.php:107-109`). Filtering the hint list on path depth would therefore suppress product detection precisely where it works, and would drop deep URLs into the `:plain` bucket — where, depending only on DOM order, one could evict the homepage's probe and be reported as a healthy `sites_deduped` rather than the miss it is. That is the same silent-miss class this whole change exists to remove.
>
> **There is therefore no depth filter.** Cost: at most one wasted probe per deep product link on a `*.myshopify.com` / `*.bigcartel.com` host, which is a narrow and bounded set. Pinned by `LinkRouterHostDedupeTest`'s `grants a deep collections url the product slot rather than the homepage slot` and `still probes the homepage when a deep product url is seen first`.

Flow inside `consumeProbeFor()`:

1. `$site = siteKey($url)`. Null → skip dedupe, fall through to `consumeProbe()`.
2. `isset($seenSites["$site:$shape"])` → increment `sitesDeduped`, return `false`. **No probe consumed.**
3. Otherwise `consumeProbe()`. On success record the key and return `true`.

`sitesDeduped` is a **separate counter from `probesDenied`** and must stay separate: `probes_denied > 0` means starvation (bad), `sites_deduped > 0` means the guard working (good). Collapsing them destroys the signal the shipped log line exists to carry.

`LinkRouter` changes are two call sites: `consumeProbe()` → `consumeProbeFor($url)` at `:117` and `:232`.

Ceiling: **two probes per website** (one `plain`, one `product`), both charged to the run's 6. Symmetric — a product link appearing first makes the homepage the second probe.

`RouteContext::summary()` gains `sites_deduped`; its `@return` annotation must be updated with it (PHPStan-visible), along with the log assertion in `LinkInBioScanJobTest`.

`$seenSites` is bounded by the caller: `WebsiteLinkHarvester::extractLinks()` caps at ≤500 unique hrefs (`WebsiteLinkHarvester.php:422,444`), so ≤500 entries, ~20 KB. `RouteContext` imposes no cap of its own — a docblock should point at the harvester's, so a future caller is not the first unbounded one.

### Why the `seedShop` arm genuinely needs this

Not a defensive no-op. `seedShop()` returns `RouteResult::pending('shop','shop','shop')` (`LinkRouter.php:238`), and `pending()` is the one factory that does not pass `handled: true`. `routeClassified()` sets `seenPlatforms` **only when `$result->handled`** (`LinkRouter.php:103-105`), so a shop route never consumes its platform slot — a second shop link spends a second probe today, in production.

Worse, every shop-classified link carries the literal slug `'shop'`, because `SHOP_HOSTS` is only `*.myshopify.com` and `*.bigcartel.com`. So even if `pending` were `handled: true`, `seenPlatforms['shop']` would wrongly collapse **two different merchants' stores** into one probe. Host keying does not have that flaw — decision 2 keeps `alice.myshopify.com` distinct from `bob.myshopify.com`.

## Walkthrough — the tattoo studio

**Today:**

```
crucible ×6  →  6 jobs × ~9 requests  = ~54
             +  6 enrich fetches      = ~60 requests to one studio
paytherent, bsky, pinterest           → starved, never examined
9 cards written
```

**After:**

```
crucible     →  1 job × ~9 requests   = ~9
             +  6 enrich fetches      = ~15 requests   (was ~60)
paytherent, bsky, pinterest           → 3 probes, 2 still in reserve
9 cards written                       ← unchanged
```

## Edge cases

| Case | Behaviour |
|---|---|
| Unparseable host | `siteKey()` → null → probe normally, never dedupe |
| Same site, both shapes | Two probes, then dedupe. Ceiling 2 per site |
| Product link first | Symmetric; homepage becomes the second probe |
| Two different merchants on `*.myshopify.com` | Distinct hosts → distinct keys → both probed |
| Manual paste (`CustomLinksController.php:89`) | Unaffected — `maxProbes: 0`, never probes |
| Two separate runs on one host (bio scan + link-in-bio scan) | Not deduped — `RouteContext` is per-run. Costs one extra cascade; see Deferred |

## Testing

| Test | Asserts |
|---|---|
| `tests/Feature/Platforms/LinkRouterHostDedupeTest.php` (new) | Six same-site links spend one probe; a `/products/` link spends a second; a seventh spends none; total never exceeds `maxProbes`; `sites_deduped` counts separately from `probes_denied`; `www.site.com` and `site.com` dedupe together; `shop.site.com` does not; two different `*.myshopify.com` hosts each get a probe |
| `tests/Feature/Platforms/LinkInBioScanJobTest.php` (extend) | The shipped completion-log test gains a same-host case asserting `sites_deduped` |

No `SiteKeyTest` — decision 5 removes the class; the normalisation is covered through the feature test.

Tests run SQLite while production is Postgres; nothing here is constraint-bound.

## Deferred — origin burst memo

Rev 1 and rev 2 specified a second piece: cache "no origin-scoped storefront at this host" so a later URL on the same host could skip probes. **Not built.** Recorded here so re-deciding starts from evidence.

**What it would have done.** `LinkProbeWorker` splits its probe list; a miss by the origin-only probes is memoed by host; a later probe on that host skips them. It contributes nothing to starvation — `ProbeGate::allows()` claims budget (`ProbeGate.php:62`) before the cascade is reached, so a memoed site still spends a `RouteContext` slot, a user-daily unit, a global-daily unit and the un-budgeted stage-1 fetch. Its only value is the cross-run burst: one Instagram connect scans the bio (`InstagramAutoSync.php:78`) and the link-in-bio page (`LinkInBioScanJob.php:85`) as two jobs seconds apart, and this dedupe cannot see across them.

**Why it was cut.** The benefit did not survive its own correctness fix.

`SquarespaceStorefrontProbe` passes `$iri->canonical` — path included — into `discoverProductsUrl()` (`SquarespaceStorefrontProbe.php:48`), whose first candidate is the pasted URL (`SquarespaceScraper.php:31`). It is path-scoped and cannot be memoed by host: doing so would answer "no shop" for a store at `site.com/boutique` because `site.com/about` was probed first. All three reviewers caught this; rev 1 had it wrong.

With Squarespace correctly excluded, the memo skips only Shopify (1 request) and WooCommerce (2) — **3 of 9 requests saved**, inside a short window. Rev 1's headline "~9 → ~2" depended on the misclassification.

**Everything else it would need**, if revisited:

- Key on the literal `scheme://host` the probes fetch (`ShopifyStorefrontProbe.php:74`), **not** the dedupe's `siteKey`. They answer different questions; `http://site.com`, `https://site.com` and `https://www.site.com` are three probe targets.
- Its own short TTL (~45 min), not the 12h `cooldown_minutes`. At 12h it becomes a cross-user negative classification cache with no invalidation path — cache invalidation here is Eloquent-observer-driven and no model write means "this third-party site now has a store".
- A distinct `ProbeOutcome::miss('origin_memo')`, excluded from `LinkProbeWorker::probe()`'s `remember()` alongside `CUT_SHORT` (`:104-113`) — otherwise a 45-minute answer is re-remembered under the URL as a 12-hour one.
- Keep the `fetchBudget->exhausted()` check in front of the path probes. "Always run path probes" means *regardless of the memo*, never *regardless of the budget*; the 15s ceiling is load-bearing on a lane tuned to `retry_after=660` (`config/horizon.php:289-303`).
- Accept that the memo key is partly third-party controlled: on `OUTCOME_STORE_PAGE`, `CommerceProbeJob.php:128` passes `$read['storeUrl']`, derived from `$res['finalUrl']` (`GenericShopScraper.php:123`), and `SafeUrlFetcher` follows up to 5 redirects.
- Accept that transient failures (5xx, WAF challenge, 429, connect timeout) and a temporarily non-servable catalog surface (`LinkProbeWorker.php:168-171`) memoise as "not a shop".
- Document the dependency on `supervisor-long` running `['scraping','gdpr']` at `maxProcesses => 1` (`config/horizon.php:289-293,396,404`). The memo has no single-flight lock; raising that for throughput degrades it to a no-op with no test failing.

**Trigger to revisit:** `sites_deduped` and `probes_denied` in the shipped log line. If cross-run duplicate cascades show up as a real cost, the ~3-request saving can be re-weighed — and a `discoverProductsUrl()` origin-only mode would recover the other 3.

## Out of scope

**Gating the stage-1 fetch.** `CommerceProbeJob::probe()` calls `readProductPage()` before anything reaches `ProbeGate` (`CommerceProbeJob.php:121`). It claims no budget, checks no cooldown, is never remembered. It stays un-budgeted: one request per dispatched job, which after this change is one per website rather than six.

**Collapsing same-site cards.** Returning `RouteResult::skipped()` for a deduped link would leave one card per website instead of six. Rejected — "nothing vanishes" wins. Precedent exists (`LinkInBioScanJob`'s own-host filter drops the bio platform's chrome), but a user's deliberate second link to their own site is not chrome.

**The 2000/day global probe ceiling.** `global_daily_cap` 2000 ÷ `user_daily_cap` 40 = ~50 users' worth of full onboarding per day, platform-wide (`config/partna.php:1864-1865`). A global refusal returns `custom()`, indistinguishable from "not a shop", and `probes_denied` counts only the `RouteContext` dimension. This change neither raises that ceiling nor makes it visible. Worth addressing before pilot.

**`www.shop.example.com` subdomain derivation.** `IriCanonicalizer.php:184-190` documents a known bug yielding subdomain `www.shop` rather than `shop`. `siteKey()` sidesteps it by working on the host directly.

**Retiring the ~10 private `www.`-strippers.** A dedicated refactor, not this bugfix.

## Risks

- **The `product` hint list is a guess** and nobody has observed the case it serves. It is one const; `sites_deduped` will show if it is over-aggressive. Cheapest possible deferral if it proves noise.
- **Two probes per site is a policy choice, not a derived number.** A site with a homepage and a product page costs double a site with one link. Bounded, visible in the log line.
- **`RouteContext` is per-run**, so two jobs in one Instagram connect still cascade the same host twice. Accepted; see Deferred.

## Review outcomes

Rev 1 was reviewed by three independent agents. Findings and resolutions:

| Finding | Reviewers | Resolution |
|---|---|---|
| Squarespace wrongly treated as origin-scoped — silent regression | 3 of 3 | Root cause of cutting the memo entirely (decision 6) |
| Memo key coarser than probe target (scheme/`www.`/port) | 1 | Recorded in Deferred |
| 12h exposure launders to ~24h via the URL cache | 1 | Recorded in Deferred |
| "Always run path probes" drops the `exhausted()` check | 1 | Recorded in Deferred |
| No invalidation path for a 12h negative classification | 1 | Recorded in Deferred |
| `seenPlatforms` never set for shop routes, so rev 1's rationale for checking that arm was false | 1 | Rewritten as "Why the `seedShop` arm genuinely needs this" |
| `SiteKey` would be the 11th private www-stripper | 1 | Decision 5 |
| `/collections/` hint is gate-refused after burning a slot | 2 | Removed from the hint list |
| Dedupe belongs in `consumeProbe`, not at both call sites | 1 | Decision 4 |
| Test set disproportionate (5 files) | 1 | Reduced to 2, one an edit |
| Depends on `supervisor-long maxProcesses: 1` | 1 | Recorded in Deferred |
| `~9 requests` is an upper bound, not a constant | 1 | Problem section |
| Six line citations drifted 1–6 lines | 1 | Corrected throughout |

Checked and **not** a defect: `ProbeGate`'s deep-path refusal is `>= 2` internal slashes, i.e. three-or-more segments (`ProbeGate.php:112`). One-segment paths like `/appointment.html` pass, so all six crucible URLs did run full cascades and the incident arithmetic stands.

Also surfaced, unrelated, worth fixing opportunistically since `RouteContext` is open: `RouteContext.php:23` cites a `LinkRouterProbeCapTest` that does not exist. The value is actually pinned by `LinkInBioScanJobTest.php:209,225` and `CustomLinkSeederTest.php:151,154`.
