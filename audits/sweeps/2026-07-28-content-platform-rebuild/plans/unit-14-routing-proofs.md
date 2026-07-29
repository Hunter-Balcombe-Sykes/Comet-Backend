# Unit 14 Plan — #TEST-13, #TEST-12, #TEST-2 (routing security proofs)

Produced 2026-07-29 by the audit-fix run. Persisted into the repo because the
session scratchpad does not survive. Not yet implemented.

## Headline

Two of the three findings are **stale**, one is **partially stale**. The real work left is
roughly 20% of what the audit describes — plus **one genuine security weakness the audit did not
find**.

| Finding | Verdict | Residue |
|---|---|---|
| **#TEST-13** PSL untested | **STALE — refute and tick** | Optional: 3 hardening cases + 1 algorithm deviation noted |
| **#TEST-12** TLD allowlists unlocked | **PARTIALLY STALE** | Google Business genuinely uncovered; TLD-set drift genuinely unlocked |
| **#TEST-2** `store()` branches | **PARTIALLY STALE** | 3 of 8 branches uncovered; 1 unreachable from this controller |

## STEP 1 — Premise verification

### #TEST-13 — STALE

`tests/Unit/Routing/PublicSuffixListTest.php` **exists**, added in `710db104` — the same commit
that added the class. It has never been absent. It covers exactly what the finding asks for, plus
two extras: normal rule, wildcard rule (`foo.bar.ck`), exception rule (`www.ck` overriding
`*.ck`), unknown-TLD implicit-`*` fallback, host-IS-a-public-suffix → `null`, private-section
boundary (`acme.github.io`), and the spoof class itself (`opentable.evil.com` → `evil.com`).
Confirmed green: 10 passed, 13 assertions. Same failure mode as #TEST-16. **Mark STALE.**

### #TEST-12 — PARTIALLY STALE

`tests/Unit/Security/HostSpoofingHotfixTest.php` (added `446d6d27`, the P0 hotfix commit that
closed the suffixes) already pins **both directions** for OpenTable and Eventbrite at three
layers: `WebsiteLinkHarvester::classify()` (8 spoof hosts → null, 8 real → correct platform),
`OpenTableService::isOpenTableUrl`/`parseRid`, and `ProviderDetector::detectFor()`. Plus
`RoutingEndpointTest`'s "never connects a spoofed brand host" end-to-end.

**Genuinely absent:**
1. **Google Business has no host test at all.**
2. **No test locks the closed sets against drift** — and drift is live because the lists are
   **duplicated**: Eventbrite's 25 TLDs exist in **three** independent copies
   (`EventbriteScraper.php:20` regex string, `Catalog/Definitions/Eventbrite.php:30` array,
   `Ingest/Connectors/EventbriteConnector.php:40` array); OpenTable's 16 in **two**
   (`OpenTableService.php:19`, `Catalog/Definitions/Opentable.php:31`). All agree today; nothing
   enforces it. The `Definitions` copy feeds the PSL-backed routing path, the `Services` copy the
   legacy connect path — widening one silently reopens spoofing on whichever lags.

### #TEST-2 — PARTIALLY STALE

`RoutingEndpointTest.php` already forces, per-branch through the real HTTP endpoint with no
mocking: `connected` (+ full decision record), idempotency, `note`→`link` (+ dedupe),
`cap_full`→422 `link_cap_reached`, `review` via `choose`, `reject`/`own-infra`, gate-demotes-to-Note,
spoofed host, tombstone supersession, validation 422s, 401, and both Unit-4 redaction paths. That
is 5 of the 8 outcomes — including `link_cap_reached`, which the finding explicitly claims no test
forces.

---

## STEP 2 — Plan

### Part A — #TEST-13: refute, tick, add three hardening cases

`app/Routing/PublicSuffixList.php` is a **real PSL implementation over the real bundled list**,
not a heuristic: `resources/psl/public_suffix_list.dat`, 16,409 lines,
`// VERSION: 2026-07-25_14-20-03_UTC`, vendored from publicsuffix.org, read once at `base_path()`
and process-memoised. **No remote fetch.** All three rule types, longest-match-wins, implicit `*`
fallback, ASCII/punycode normalisation. Both ICANN and PRIVATE sections parse (section markers are
`//` comments, so private rules like `github.io` are live).

Mark STALE with provenance. Add three cases that are security-relevant rather than decorative:
1. `registrableDomain('a.b.c.d.opentable.evil.com')` → `evil.com` — deep nesting must not let an
   attacker-controlled label become the registrable domain. Currently tested only at depth 1–2.
2. Trailing-dot + uppercase equivalence: `'WWW.OpenTable.Com.AU.'` → `opentable.com.au`.
   `publicSuffix()`/`registrableDomain()` each do their own normalisation; nothing pins that they agree.
3. Wildcard-under-wildcard: `registrableDomain('a.b.ck')` → `b.ck` not `a.b.ck` — proves the
   wildcard consumes exactly one label.

**Algorithm deviation to note, not fix:** `publicSuffix()` walks candidates shortest→longest and
returns on the *first* exception match; the PSL spec's prevailing rule is the *longest* matching
exception. The bundled list contains no nested exception rules, so this is unreachable. One-line
comment in the class; no code change, no test.

### Part B — #TEST-12: the real residue

**B1. Decide the Google contract before testing it (§9 gate).**

`GoogleBusinessService.php:82`:
```php
if (! preg_match('~(^|\.)google\.(com(\.[a-z]{2})?|co\.[a-z]{2}|[a-z]{2})$~', $host)) {
```
Unlike its two siblings this is **not a closed enumeration — it is an open family**, admitting
`google.<any two ASCII letters>`, `google.co.<any two>`, `google.com.<any two>`: ~17,500
admissible hosts, of which Google owns a few hundred. Concretely it admits `google.tk`,
`google.ml`, `google.ga`, `google.cf`, `google.gq` (ex-Freenom free-registration ccTLDs),
`google.cm` (historical typosquat), and every `google.com.zz`/`google.co.zz` shape where
`com.zz`/`co.zz` is not even a real public suffix. An accepted host flows out of `parsePlaceUrl()`
as the stored Google Business listing link on a public sitepage. The same open family appears
again at `:66`, scanning a fetched interstitial body — attacker-influenced input.

**Asserting `google.tk` is accepted would certify the defect.** Recommendation: close the set as
the siblings are closed (a `private const`), and additionally require via `PublicSuffixList` that
`registrableDomain($host) === 'google.'.<enumerated suffix>` — the primitive the rest of the
platform already uses, which kills the `com.zz`/`co.zz` shapes for free. Keep the `(^|\.)`
subdomain allowance (`maps.google.com`, `www.google.com` are real).

**This is a code change — flag to the run owner before implementing.** Narrowing an accept-list
can break real user pastes, so the enumeration must cover at minimum the AU/UK/NZ/US ccTLDs. If
declined, the test asserts only the undisputed half (`google.evil.com` rejected;
`google.com`/`google.com.au`/`google.co.uk`/`google.de`/`maps.google.com` accepted) and the open
2-letter family is recorded as an accepted risk.

**B2. Extend `tests/Unit/Security/HostSpoofingHotfixTest.php`** (already the home for this proof class):
- `it('rejects spoofed Google Maps hosts')` — `google.evil.com`, `evil.com/maps/place/x`,
  `googlemaps.evil.io`, and (if B1 lands) `google.tk` → `resolve()` returns `null`.
- `it('still resolves real Google Maps hosts')` — `www.google.com/maps/place/…`,
  `www.google.com.au/…`, `www.google.co.uk/…`, `maps.google.com/?q=…` → non-null with expected
  `name`/`lat`/`lng`.
- **Seam note:** `resolve()` short-circuits to `followShortLink()` for five exact short hosts,
  which calls `SafeUrlFetcher`. All the above are long-form, so no fetcher is touched. Construct
  `new GoogleBusinessService($fetcher, new PlacesBudget)` per the `bookingFetcherWith([])` pattern
  in `tests/Unit/Platforms/BookingPlatformsTest.php:54`. "Rejected" means **`resolve()` returns
  `null`** — assert that, not a proxy.

**B3. New `tests/Unit/Security/BrandSuffixSetDriftTest.php`** — the actual "lock the set shut" test:
- Assert `Definitions\Eventbrite::TLDS` is set-equal to `EventbriteConnector::TLDS` and to the
  alternation parsed out of `EventbriteScraper::TLDS`. Same for the two OpenTable copies.
- Assert every entry is a **real public suffix** per
  `PublicSuffixList::instance()->publicSuffix("x.{$tld}") === $tld`. **This is the assertion that
  makes widening safe by construction** — it makes `evil.com` un-addable as a "TLD", stating the
  host-spoofing defence as an invariant rather than a list of examples.
- Data-provider positives: for each TLD, `OpenTableService::isOpenTableUrl("https://www.opentable.{$tld}/r/x")`
  true and the spoof twin `opentable.{$tld}.evil.com` false; same shape for Eventbrite via
  `ProviderDetector::detectFor('events', …)`. 16 + 25 pairs, cheap, and the exhaustive positive
  coverage #TEST-12 names.

### Part C — #TEST-2: three uncovered branches, one unreachable

Branches in `RoutingController::store()`, enumerated from the current post-Unit-4 code:

| # | Branch | Status |
|---|---|---|
| 1 | `connected`, 202/`ok` | covered |
| 2 | `review` via `choose`, 202/`pending` | covered |
| 3 | `review` via `hold` | **unreachable from this controller — see below** |
| 4 | `link`, 202/`pending` | covered |
| 5 | 422 `link_cap_reached` | covered |
| 6 | 423 busy | **gap** |
| 7 | 503 unavailable | **gap** |
| 8 | `outcome=null`, 202/`pending` | reject-half covered; **invalid-half gap** |

**Branch 3 — `hold`.** `SourceReconciler` produces `Verdict::Hold` two ways. The first
(booking-class XOR conflict, `:63`) is guarded by `! $context->isDirectRequest()`, and `store()`
always passes `origin: 'paste'`, for which `isDirectRequest()` is `true`. **That branch is
unreachable from this controller — a finding, not a test.** Either the guard is intentional (a
direct paste may replace an incumbent) and the code should say so, or it is a latent hole. It
reaches production only via importer/scan origins, and `SyncFindingsFoldTest.php` already covers
the Hold conflict shape from those. **Do not write a store()-level XOR-conflict test.**

The second Hold — per-surface `max_accounts` cap (`:70`, default `maxAccounts: 1`) — **is**
reachable and needs no mocking: POST `instagram.com/someone` (connects), then
`instagram.com/someoneelse` → same `surface_key`, different `resource_id` → `capReached` → Hold.
Assert `outcome === 'review'`, `verdict === 'hold'`, `status === 'pending'`, exactly one
`IntegrationConnection`, second intent's `block_reason === 'cap_reached'`.

**Branch 7 — 503.** Also needs no mocking: `CustomLinkSeeder::addManual` returns `unavailable`
when `$user->isPendingDeletion()` or `FeatureAvailability::for($user)->allows('integration.custom')`
is false. Create a pending-deletion tenant, POST an unrecognised URL, assert 503 and that no
custom card was written. The pending-deletion path is the more valuable half — it also proves the
deletion gate reaches the newest write path.

**Branch 6 — 423 busy.** Requires a held `Cache::lock` on
`CacheKeyGenerator::platformConnectionLock('custom', $user->id)` for >5 s, or a
`LockTimeoutException`. The only branch where mocking is justified: bind a `CustomLinkSeeder`
partial returning `['status' => 'busy', 'row' => null]`, assert 423 + retry message. Check
`ProbeBudgetConcurrencyTest`'s lock idiom first and prefer a real held lock if it transfers, since
that also proves the key.

**Branch 8, invalid-half.** `addManual` returns `status => 'invalid'` when `normalizeUrl()` returns
null. The controller has **no `if` for it**, so it falls through to 202 `pending` with
`outcome: null` and nothing written — the user pastes a URL, gets a success-shaped 202, and no
link appears. **That is exactly the failure mode the `note` branch was added to fix, reintroduced
through a different door.** Before writing a test, determine whether `invalid` is reachable given
`RouteLinkRequest` validation plus canonicalisation. If reachable, this is a **bug to fix** (422
with a clear message), not a branch to certify. If unreachable, note it and skip.

Add to the existing `RoutingEndpointTest.php` under a new `── store: the remaining outcome
branches` section — a second file would fragment the one place a reader looks. **Do not mock
`LinkRoutingService::route()`** as the finding suggests; the existing tests drive real URLs
through the real pipeline, which is strictly better evidence.

### Part D — files and lanes

- `tests/Unit/Routing/PublicSuffixListTest.php` — +3 cases
- `tests/Unit/Security/HostSpoofingHotfixTest.php` — +2 Google blocks
- `tests/Unit/Security/BrandSuffixSetDriftTest.php` — **new**
- `tests/Feature/Routing/RoutingEndpointTest.php` — +3–4 blocks
- `app/Services/Platforms/GoogleBusinessService.php` — closed-set change, **owner sign-off first**
- `app/Routing/PublicSuffixList.php` — one comment on exception ordering
- `app/Http/Controllers/Api/Routing/RoutingController.php` — only if branch-8 `invalid` is reachable
- the test-coverage audit file — stale/partial annotations for all three findings

**Lanes: all default SQLite (unit + feature). Nothing here needs Postgres — do not add anything to
`tests/Postgres/`.** No CHECK/NOT NULL constraint is exercised. No migrations, no SQL.

### Part E — risks

1. **B1 is a behaviour change on a live paste path.** Narrowing the Google host set can reject
   URLs real users paste today. Land B2's positive test *first* so the accept side is proven
   before the reject side tightens. This is the only item that can break a user — and the only one
   that closes a hole.
2. **`RoutingCorpusTest.php` (267 cases) is the over-stripping tripwire.** Nothing here touches
   `IriCanonicalizer`, `LinkProjector`, `Rulepack` or the catalog detectors — only the
   `Services/Platforms` Google regex and additive test files. B3 reads `Definitions::TLDS`
   reflectively; it does not mutate them. The corpus must stay green and **must not be
   regenerated**. Run it explicitly as a gate.
3. **`PublicSuffixList` is stronger than the audit implies; the weakness is elsewhere.** The
   PSL-backed routing path is sound. The soft spot is the *legacy* `Services/Platforms` regex layer
   that bypasses PSL entirely — now four hand-maintained brand lists. The durable fix for the whole
   #TEST-12 class is for every brand host check to go through `registrableDomain()` against a brand
   key, deleting the regexes. Out of scope; worth a finding.
4. **The vendored PSL has no freshness check.** `resources/psl/public_suffix_list.dat` is pinned at
   2026-07-25 with no update job and no staleness test. A new upstream public suffix silently
   mis-computes registrable domains until someone re-vendors. Worth a finding; not this unit.
5. **Branch 3's unreachability may be intentional.** Do not "fix" it inside a test-coverage unit —
   raise it and get a ruling.
