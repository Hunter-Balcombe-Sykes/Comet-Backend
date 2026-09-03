# Setup suggestions — running task list

Started 2026-09-03 from the `elephantcafe` business signup
(`team@partna.tech`, workplace Elephant Cafe Flemington). Add to this as we
go; delete the file when everything in it has shipped.

Status: `OPEN` ready to build · `HELD` needs an owner decision first ·
`DONE` shipped (leave the entry until the whole file goes).

**Nothing in here is being executed yet** — the owner is still adding items.
Do not start until they say go.

**Standing rule for every task in this file (owner, 2026-09-03):** each fix
ships with a regression test built from the REAL data that exposed it — the
actual URLs, payloads and values observed on the `elephantcafe` signup — so
we can prove the fix would have produced the right result had it been in
place. A test written from an invented fixture does not count; that is
exactly how the `sources` `string[]` fixture and the "wire always derives a
venue name" comment both came to assert behaviour the backend never had.

---

## ⚠ RESEARCH CORRECTIONS (18-agent map, 2026-09-03) — read before planning

**C1. "Nothing auto-connects" is FALSE in the code.** `PlacementPolicy.php:152`
— on any INDIRECT (non-paste) origin, `confidence >= suggest` with
`margin >= 10` returns **`Verdict::Place`**, which creates a connection. So
for every post-claim harvest lane (`website_import`, `link_in_bio`,
`bio_harvest`, `google_business`, `commerce_probe`) the *suggest* threshold
IS the auto-connect threshold; the only remaining guard is the margin.
**Consequence: lowering the suggest threshold to widen suggestions would
directly widen AUTO-CONNECT on those lanes** — the opposite of decision 2.
Additionally `band='auto'` pre-ticks a setup row and a pre-ticked row becomes
a real connection on Continue (`SetupPayload.php:274`, `selection.ts:73`,
`SetupBatchApplier.php:67`), so pre-ticking is one-click auto-connect, not a
visual hint. Signup builds cannot reach Place directly, but CAN via
`SourceReconciler.php:134` (a Choose is upgraded to Place when
ConnectionIdentity matches an existing row), so "signup connects nothing"
holds only for NEW connections.

**C2. The fleet cannot validate its own work.** `RoutingCorpusCommand`
generates the positive corpus BY PARSING THE PATTERN and emitting a string
that satisfies it — so a fabricated pattern generates URLs that match itself
and passes. Nothing in the repo can distinguish a correct pattern from a
plausible wrong one. Measured: **3 of 8 pilot patterns fail the repo's own
toolchain, and Mindbody's does not even compile.** A verification harness
built on REAL URLs must exist BEFORE the fleet runs, or we will ship 59
confidently-wrong patterns.

**C3. Dead brands are `lifecycle=active` in our catalog.** Menulog ceased AU
operations 2025-11-26; Quandoo is mid-withdrawal; several Deliveroo markets
are dead. A lifecycle sweep must run FIRST — writing patterns for dead
brands is pure waste.

**C4. ~10 of the queue are tenant-per-SUBDOMAIN, not path-shaped** (cliniko,
jane_app, mangomint …). The tenant is already sitting unused in
`$iri->subdomain`; these need `subdomain_pattern` + capture, not a path.
Whitelabel/own-domain surfaces (woocommerce.store, squarespace.store) can
have no host pattern at all — they are the storefront-probe arm. So the real
path queue is smaller than 59.

**C5. Adding `identifier_capture` is a silent IDENTITY MIGRATION, not an
additive improvement.** `SourceReconciler.php:106` keys on
`$placement->identifier ?? $iri->canonical`. Giving a surface an identifier
CHANGES the key for rows already stored under their canonical URL, which can
orphan or duplicate existing connections. Needs a backfill/re-key decision
before any pattern ships.

**C6. Two existing tools nobody used.** `catalog.unmatched_domains` (written
by `LinkObserver.php:32` on every no-match — a ready-made, traffic-ranked
triage queue) and `catalog.detector_suspensions` + `php artisan
catalog:suspend-detector` (a per-detector kill switch, 720h ceiling — the
rollback lane for this rollout). Prioritise by observed traffic from
`routing.link_observations`, not by catalog order.

**C7. The duplication is far worse than "two classifiers".** 15 `classify()`
call sites (not the 13 the seam doc claims); `harvestHtml()` is a SECOND
consumer of the same tables with the OPPOSITE precedence order
(RESERVATION→ORDERING→BOOKING vs BOOKING→RESERVATION→ORDERING — dormant only
because no host is in two tables); a SEVENTH undeclared table of 10 event
brands hardcoded as inline `preg_match` arms; and **eight more
classifiers/normalisers** beyond the two named. 97 host rows across 6
constant tables. `PlacementPolicy::decide()` has only 4 call sites versus
classify()'s 15 — the hand-table engine is the DOMINANT one, not the
secondary one.

**C8. Manual-connect exposure is larger than the 66.** 83 of 85
BrandLinkConnect surfaces accept a garbage deep path on the right host and
write a connection row; only bluesky and gumroad reject. **23 of those 83
already HAVE a path pattern in the catalog that the connect path simply
ignores.** Nothing downstream ever re-validates a stored URL, and a
connection born from accept is stamped `last_refresh_status='ok'` without
anything being fetched (218 such rows observed on dev).

## ⚠ VALIDATION RESEARCH (14-agent lane, 2026-09-03) — C9–C16

**C9. The "if the path fails, test the connection" fallback is IMPOSSIBLE for
four brands.** Live-tested 2026-09-03, one egress IP:

| Brand | Plain GET, real vs fake venue | Usable? |
|---|---|---|
| **Quandoo** | 200 vs hard **404**. Bare `/place/{id}` with the slug DROPPED 301s for real ids, 404s for fake. og:title + JSON-LD name server-rendered. No WAF (nginx/AWS). | **YES — definitive** |
| **Booksy** | 200 vs **301 → `/en-us/s/…?do=showBusinessDeletedModal`**. Name in title/og/JSON-LD/`__NUXT__`. No WAF (GCP/Envoy). Real id + garbage slug 301s to the CANONICAL url. | **YES — definitive** |
| **Resy** | Identical empty SPA shell (byte-for-byte, 5043 b) for real, fake AND the homepage. Imperva, but never challenged. **A Googlebot UA triggers Resy's own prerender.io → clean 200/404 + og:title.** | Only via crawler UA |
| **Uber Eats** | **403 `cf-mitigated: challenge` for everything.** Real 404-vs-200 exists but only past the JS challenge. | **NO** |
| **DoorDash** | **403 for everything — including robots.txt.** No daylight between real, fake and a crawler-open path. | **NO** |
| **Deliveroo AU** | **Whole zone 301s to deliveroo.co.uk.** Real slug, fake slug, robots.txt, root — byte-identical. | **NO — brand is dead** |

Also from the status sweep: **Etsy** is DataDome, 403 to everything (real
shop, fake shop, any UA, GET or HEAD) — and the existing 403-retry heuristic
in `SafeUrlFetcher` cannot help, the block is TLS-fingerprint-based.
**OpenTable** rejects at the transport layer for every request (HTTP/2 stream
reset / timeout) — no status is ever produced. **X, YouTube, GitHub, Spotify,
Calendly** give clean 200/404. **LinkedIn** signals via HTTP 999 vs 200 but
rejects HEAD with 405 and 999 is its generic bot-block. **Threads** and
**Snapchat** only signal after following a mandatory redirect hop.

**C10. Class C — the highest-volume surfaces certify fakes.** Instagram,
Facebook, TikTok, Pinterest, Reddit and Google Maps place return **200 for
every handle, real or fake**, with identical headers. Any adapter defaulting
to "200 = pass" would stamp nonexistent accounts as *verified*, which is
worse than no check because a false verified provenance suppresses the
confirm. Facebook additionally returns **400** to a realistic Chrome UA while
succeeding with `SafeUrlFetcher`'s own non-Chrome UA — the inverse of the
403-retry assumption.

**C11. Deliveroo AU is a live bug on customer sitepages RIGHT NOW.** Not a
future risk. Deliveroo exited Australia Nov 2022; every `deliveroo.com.au`
link already on a live sitepage silently sends visitors to a UK homepage and
will never 404 or error. `lifecycle=active` in our catalog. Same class as
Menulog (ceased AU 2025-11-26) and Quandoo (mid-withdrawal).

**C12. A confirm modal would make C11 PERMANENT.** `deliveroo.order` is
host-only → weak L1 → routes to a confirm that says "open the link and check
it's yours". It loads perfectly (it's the UK homepage). The user confirms,
and a confirmed link would be immune to later auto-removal. **A page loading
is not evidence the CTA is right** — never use "open it and confirm" as the
ownership test for a brand whose failure mode is a redirect.

**C13. The gate must sit on the WRITE, not on accept.** Five writers create
connections: `SuggestionApplier::apply` (accept only — 3 callers),
`SourceReconciler::applyIntent:652` (on any `Verdict::Place`),
`BuildsAutoSyncFindings::write:140` (sets `is_active=true` AND
`last_refresh_status='ok'`), plus the manual connect strategies. Gating at
apply time also breaks "an L1 failure is never offered as a suggestion" — by
apply time the suggestion has already been rendered and ticked, and
`SuggestionsController::accept:375` returns the connection unconditionally
with no error path. **L1 is pure string work and belongs in the projector**
(promote identifier capture to mandatory); only the network layer belongs
later. And there is no `pending-verification` state — `source_intents` has
only proposed/applied — so "the suggestion waits" has nowhere to wait.

**C14. My host-only census over-counted.** OpenTable's 48 detectors: 16 with
a path capture and **32 with an empty path_pattern but
`query_requires=['restRef']` + `identifier_source=query`** — those are NOT
host-only; `opentable.com.au/` does not match without the query. Any L1
defined as "host + path grammar" would *fail every valid ccTLD OpenTable URL*
while counting a surface that already has a predicate as ungrammared. **Define
L1 as "a detector matched AND its capture is non-empty"** — the existing
contract (registrable_key + subdomain_pattern + path_pattern + query_requires
+ reject_patterns) with capture promoted to mandatory. Verified counts:
182 surfaces, **122 connectable (all lifecycle-active)**, 326 detectors on
them, 221 with empty path_pattern, **82 surfaces with ≥1 host-only detector**.
`identifier_kind` across the 122: url 81, handle 29, numeric_id 4, slug 5,
composite 2, domain 1 — so a "handles are weak" rule would push every
Instagram/TikTok/Facebook suggestion onto the confirm modal.

**C15. Pre-scrape is billing on the wrong signal.** `isSignupBuild()`
(`RoutingContext.php:73-76`) is misnamed — it returns true for ANY unclaimed
non-paste user, so **staff/ManyChat outreach builds** (which the code's own
comments say "may sit unclaimed for weeks with nobody to ask") are billed
identically to someone seconds from the setup dialog. **15 of 32 connectors
are `CostClass::Actor`** (Apify-billed, budgetWeight 50); 13 of those are
`eagerOnConnect`. Key on `built_via = signup`. Bug-shaped regardless of the
rest of the decision.

**C16. Your GB/IG rules are violated in code today.**
`GoogleBusinessAutoSync::seed()`'s socials branch (incl. `seedInstagram`)
runs **unconditionally for every account type** outside the signup lane — so
a partna account connecting Google Business gets Instagram suggested from the
listing. `GoogleBusinessController::connect()` has **no AccountCapabilities
gate at all**. And `InstagramAutoSync` routes any instagram.com URL found in
a partna user's own bio through the generic `LinkRouter` with no exclusion,
producing a "Change to" suggestion offering to **replace their real
Instagram**. Separately: **menu and services auto-publish everything** —
their section rule is a bare `kind_is` with no `latest_n` gate, unlike every
other pool, so every scraped dish/service goes live the instant it lands.
That is the menu/services difference you flagged, confirmed — and it means an
unverified Uber Eats connection publishes a whole menu.

## ✅ OWNER DECISIONS 9–12 (2026-09-03, second round)

**D9. The paid fetch we already run IS the verification — surface its
verdict.** (Owner: *"how do we sync the items from them now wouldn't however
we do that clearly point it out as if it's rejected or gets none it's
wrong"*.) Correct, and it invalidates the "unverifiable" verdict in C9, which
was scoped to FREE plain-HTTP fetches only. We already clear Cloudflare on
DoorDash and Uber Eats with Apify actors (`MenuApifyScraper`, actors
`memo23` for UE / `dz_omar` for DD, configured at
`partna.menu.platforms.*.actor`). The connectors already yield three distinct
outcomes — `Unavailable("…returned status X")` (infrastructure failed),
`Unavailable('returned no dataset item')` (**the store does not exist → the
link is wrong**), and `Note('empty_menu')` (store exists, no menu parsed).

**The bug is that all three collapse into one status.**
`MenuFetchJob.php:431` writes `status => $menus[$platform] !== null ? 'ok' :
'unavailable'`, and `MenuApifyScraper.php:37-39` documents that these actors
"intermittently return an empty result on a fraction of runs", so it retries.
Net effect: a genuinely wrong store URL is indistinguishable from a flaky
blocked run, `menu:retry-unavailable` retries it forever, and the user is
never told. **Split `unavailable` into `blocked/transient` (retry, never
demote) and `not_found` (the actor ran cleanly and the store isn't there →
demote the CTA and tell the user).** Same shape applies to any surface whose
real sync runs through a paid rendering actor.

**D10. Resy's crawler prerender is acceptable to use.** Owner: it is their
own public prerender integration, served to any crawler UA with no
reverse-DNS check. Gives Resy a definitive 200/404 + og:title. Cache the
verdict against an adapter-version key so a silent regression can't serve
stale passes forever.

**D11. Retire Deliveroo AU + Menulog AND sweep live links now.** Both are a
live customer-facing bug. Retire the registrable keys so nothing new is
suggested, and remove links already published on live sitepages. Run the same
lifecycle check across all 122 connectable surfaces so we fix the class, not
the two we happened to find.

**D12. Pre-scrape gates on OWNERSHIP, not on confidence.** Owner: *"have some
gate for like if we think it's probably theirs we then pre scrape so most
likely won't be wasting money as most time they will accept."* This is the
SAME predicate as the pre-ticking proposal already in this file — so build it
once and use it twice: **we observed the link somewhere the user controls
(their own website, their own bio, their own GB listing), AND it points at a
specific account rather than a brand in general.** Ownership-observed →
pre-tick AND pre-scrape. Not observed → suggest untick­ed, scrape on accept.
Plus the `built_via = signup` fix from C15 regardless (outreach builds must
never bill).

## ✅ OWNER DECISIONS 13–16 (2026-09-03, third round)

**D13. Accept BLOCKS until the paid actor answers.** A ticked
DoorDash/Uber Eats suggestion must not publish a CTA or a menu before the
store is confirmed to exist. This needs the `pending-verification` state from
P1/9 — the accept endpoint returns pending, the dialog shows the row as
checking, and the verdict arrives by poll. **Assumption stated, owner to
strike if wrong:** blocking applies to a CLEAN verdict only. If the actor
comes back blocked/transient after its retries, we save the connection and
flag it rather than stranding the user on our own infrastructure — the user
is never punished for a WAF or a flaky actor run. Only a clean `not_found`
refuses the save.

**D14. For Class C, path format is the ceiling and that is enough.** Instagram,
TikTok, Facebook, Pinterest, Reddit and Maps place cannot be existence-checked
by anything — they 200 on every handle. So "never save an invalid link" for
connectable+active means **never save one we can PROVE is invalid**. A
well-formed handle saves. A typo'd handle can be saved and will 404 for
visitors; that is accepted.

**D15. No backfill of existing rows — every current account is a test account
and is being deleted.** This also DOWNGRADES C11: Deliveroo AU is a catalog
bug, not a live customer-facing one, because there are no real customer pages
yet. I overstated that; the catalog fix still matters, the emergency sweep
does not.

**D16. P0 ships now as its own deployment; the redesign follows on a clean
base.**

---

## FIX LIST — everything found, in dependency order

Nothing here is scheduled yet; this is the full scope for the plan.

**P0 — independent bugs, ship first and separately (D16)**
1. Deliveroo AU + Menulog retired in the catalog (D11). No live-link sweep
   needed — every account is a test account being torn down (D15).
2. Lifecycle audit across all 122 connectable surfaces (D11).
3. `isSignupBuild()` bills outreach builds — key on `built_via = signup` (C15).
4. Partna accounts get Instagram suggested from a GB listing —
   `GoogleBusinessAutoSync::seed()`'s socials branch is ungated (C16).
5. `InstagramAutoSync` offers to REPLACE a partna user's real Instagram with
   one from their own bio (C16).
6. `GoogleBusinessController::connect()` has no `AccountCapabilities` gate (C16).

**P1 — must land before any threshold or pattern work**
7. Split band from verdict: `Verdict::Place` on the suggest band for indirect
   origins is auto-connect (C1). Until this is split, loosening suggestions
   loosens auto-connect.
8. Move the validity gate onto the WRITE, covering all five writers, not onto
   accept (C13). Exempt non-URL identity kinds (handle/place_id/domain) so the
   given IG and GB connections can't fail closed.
9. Add the missing `pending-verification` intent state — `source_intents` has
   only proposed/applied, so there is nowhere for a suggestion to wait (C13).
10. Real-URL verification harness BEFORE the pattern fleet runs — the current
    corpus is generated from the pattern, so it cannot fail a wrong one (C2).
    3 of 8 pilot patterns fail the toolchain today; Mindbody's won't compile.
11. Decide the `identifier_capture` re-key/backfill — `SourceReconciler:106`
    keys on `identifier ?? canonical`, so adding a capture silently migrates
    existing rows (C5).

**P2 — the validity model itself**
12. Define L1 as "a detector matched AND its capture is non-empty", using the
    existing contract incl. `query_requires` and `reject_patterns` — NOT a new
    host+path grammar, which breaks OpenTable's 32 query-based detectors (C14).
13. Never treat a WAF block as invalid. Class C brands (Instagram, Facebook,
    TikTok, Pinterest, Reddit, Maps place) return 200 for every handle — a
    "200 = pass" adapter would certify fakes as verified (C10).
14. Facebook returns 400 to a realistic Chrome UA and 200 to `SafeUrlFetcher`'s
    own UA — the inverse of the existing 403-retry assumption (C10).
15. Split `unavailable` into blocked/transient vs not_found across the paid
    fetch lanes, and demote + notify only on not_found (D9).
16. Delete the confidence system per decision 8, but ONLY after item 12 lands
    — 82 surfaces have ≥1 host-only detector today, so deleting confidence
    first makes DoorDash LOOSER, not tighter (nothing would stand between
    `doordash.com/promo` and a saved ordering connection).
17. Never use "open it and confirm it's yours" as an ownership test — a dead
    brand's redirect loads perfectly (C12).

**P3 — the duplication that caused the original bug**
18. Collapse the hand-maintained host tables into the catalog: 15 `classify()`
    call sites, `harvestHtml()` as a second consumer with the OPPOSITE
    precedence order, a seventh undeclared inline table of 10 event brands, and
    eight more classifiers/normalisers — 97 host rows across 6 constant tables
    (C7). `PlacementPolicy::decide()` has 4 call sites vs classify()'s 15, so
    the hand table is the DOMINANT engine.
19. Manual connect: 83 of 85 BrandLinkConnect surfaces accept a garbage deep
    path, and 23 of them already have a catalog path pattern that connect
    ignores (C8). 218 dev rows are stamped `last_refresh_status='ok'` with
    nothing fetched.
20. Two display-name resolvers that disagree — the T1 naming bug (C7/T1).
21. Subdomain-tenant cohort (~10 surfaces: cliniko, jane_app, mangomint) needs
    a subdomain capture, not a path pattern; the tenant already sits unused in
    `$iri->subdomain` (C4).
22. Wire `catalog.unmatched_domains` (already written on every no-match) as the
    traffic-ranked work queue, and `catalog:suspend-detector` as the rollout
    kill switch (C6).

**P4 — menu/services publishing**
23. Menu and services auto-publish EVERYTHING (bare `kind_is` rule, no
    `latest_n` gate, unlike every other pool), so an unverified ordering
    connection publishes a whole menu the instant it lands (C16).

---

## Decisions (owner, 2026-09-03) — these govern T1–T3

1. **One system for manual and auto.** There must be no separate
   classification path for a manual connect versus a harvested link. Today
   there are two (`LinkProjector` + catalog, vs
   `WebsiteLinkHarvester::classify()` + hardcoded host regexes) and that
   split is the root defect.
2. **There is no auto-connect.** Only auto-SUGGEST (plus some pre-scrape).
   Nothing self-connects, so the high auto thresholds and the margin gate are
   vestigial — do not "re-tune" them, remove the tier they serve.
3. **Path match is the gate for a suggestion.** A link is suggested when it
   matches a platform's path structure — so only *format-valid* links are
   suggested. It is fine and expected that a suggested link may not be the
   user's own account: the user unticks it. The tolerance is for OWNERSHIP
   uncertainty, never for malformed or non-venue URLs.
4. **Link-only surfaces match looser.** For a non-connectable surface it is
   enough that the START of the URL is right (host, and a path prefix where
   one exists). A wrong one costs little because nothing connects.
5. **Connectable + active surfaces must never save an invalid link** —
   manual or suggested. Path match saves. If the path fails, validate the
   link some other way and block the save when validation says it is not a
   real venue page. `OPEN QUESTION`: the owner asked whether there is a
   better validation method than a page fetch for brands behind bot
   protection (DoorDash, Deliveroo, Resy…) — being researched, see T3.
6. **Every platform needs its real path format(s), and all 175 get audited** —
   the 91 host-only surfaces need patterns written, AND the 84 existing
   patterns get re-verified (Uber Eats' hand-rolled locale hack suggests the
   existing ones are not uniformly right). Done by an agent fleet, with real
   verified examples per brand.
7. **URL noise must never defeat a path match.** Normalisation is the
   normaliser's job, not each pattern author's — no more per-brand locale
   hacks inside patterns.
8. **DELETE the confidence system; do not re-tune it.** (Owner, 2026-09-03:
   *"trying to tune something fundamentally not fit wont be good"*.) The
   0–100 score, the per-class auto/suggest thresholds, `INDIRECT_PENALTY`,
   `SIGNUP_FLOOR_DISCOUNT`, `MIN_MARGIN` and the strength delta all exist to
   decide how sure we must be before WRITING something unattended. Nothing
   is written unattended any more (decision 2), so the whole apparatus is
   answering a question we no longer ask. It is replaced by an explicit
   predicate — see "Replacement model" below.

### Replacement model (PROVISIONAL — pending the confidence blast-radius lane)

One predicate, evaluated identically for a harvested link and a manually
pasted one. No score, no thresholds.

```
canonicalise(url)                     # noise stripping is the normaliser's job
  → detectors whose registrable_key matches the host
    → surface is CONNECTABLE + active:
         path_pattern matches           → SUGGEST  (+ capture identifier)
         path fails, validation passes   → SUGGEST  (see T3)
         path fails, validation fails    → BLOCK on manual, plain link on harvest
    → surface is LINK-ONLY:
         host (+ path prefix if defined) → SUGGEST
  → NO catalog match at all  ← THE STOREFRONT ARM. DO NOT REGRESS THIS.
         → storefront probe (queued, budgeted) fingerprints the page
             hit  → SUGGEST "Is this your store?" (+ shop_name as the label)
             miss → plain link item
```

**The storefront arm is different by design and must survive the rewrite**
(owner, 2026-09-03: *"be conscious of how online store links work… they
don't have a path format"*). A Shopify/Woo/Squarespace/BigCartel store lives
on the MERCHANT'S OWN domain (`rootedco.shop`), so there is no
`registrable_key` to match and no path to pattern. `LinkRoutingService::
isStorefrontCandidate()` triggers on `verdict === Note && surfaceKey === null
&& ! $projection->matched()` — i.e. **the catalog placing nothing IS the
signal**, the exact inverse of every other arm. A naive "no match → plain
link" rewrite silently deletes online-store detection. It currently works and
has not been seen to fail; treat it as a regression target, not as scope.

Two things follow from looking at it properly:

- The catalog has a `fingerprint` field on detectors and it is used **zero**
  times (all 400 detectors are `evidence: url`). Storefront identification
  lives entirely in `app/Routing/Probes/`, not the catalog. So "fingerprint
  detection" is a probe concern and should stay one.
- **This lane is already the model the rest of the system should copy:**
  suggest-never-connect, validated by an actual probe, budgeted, and the only
  lane that produces a real venue name (`shop_name` → `withLabel`). T1, T3
  and the confidence deletion are all, in effect, "make everything else work
  the way storefronts already do".

What this deletes: the 0–100 scale, `RoutingPolicy::THRESHOLDS`,
`autoThreshold`, `suggestThreshold`, `signupSuggestFloor`, `MIN_MARGIN`,
`INDIRECT_PENALTY`, `SIGNUP_FLOOR_DISCOUNT`, the strength delta arithmetic,
the `-8` deep-path penalty, and the `+20` query-identifier parity patch —
each of which exists only to rank certainty for an unattended write.

What it keeps and makes load-bearing: `reject_patterns` (a pattern must be
able to exclude non-venue paths), `RoutingCapabilityGate` (account
capabilities still gate what a user may connect), and `identifier_capture`
(now required for connectable surfaces, because `ConnectionIdentity` cannot
dedupe without it).

### Signup-given connections vs suggestions (owner, 2026-09-03)

These are product rules, not scoring. They must survive the rewrite.

**The two GIVEN connections.** Google Business and Instagram are the only
things that connect automatically during signup, and only because the user
supplies them — they are *given*, not detected. This is not an exception to
"nothing auto-connects"; there is no inference involved.

- `business` accounts sign up with their Google Business listing →
  **never suggest a Google Business connection to a business account.** We
  already know theirs is right.
- `partna` accounts sign up with their Instagram →
  **never suggest an Instagram connection to a partna account.** Same reason.

**Google Business needs its own logic.** It does not behave like other
platforms for `partna`-type users, and must not be forced through the shared
predicate. Design it deliberately rather than inheriting the generic path.

**The bio-handle lane (partna accounts).** A partna user's Instagram bio may
carry other handles. Those ARE still scraped, but note carefully what is and
is not suggested as a result:

- we check every Instagram handle found in the bio;
- we **never** suggest those Instagrams as Instagram connections;
- any **online store** found through them → suggested as an online store;
- any **Google Business** we can resolve from one of those Instagram
  accounts → suggested.

So the bio is an input to finding stores and workplaces, never a source of
Instagram suggestions.

### Pre-scrape: keep, gate, or defer? `RESEARCH`

Owner question (2026-09-03): during signup we pre-scrape platforms in the
background even though they are only ever *suggested*. Do we still need to,
or should it be gated on something?

The tension is real and worth stating plainly:

- **For:** scraping takes time. Pre-scraping means that by the time the user
  reaches pool-item selection, their items are already there instead of the
  user waiting on a spinner.
- **Against:** nothing auto-connects any more. Every pre-scrape is speculative
  work — cost, rate limit and third-party load — for a platform the user may
  never tick. Widening suggestions (decisions 3–6) multiplies the number of
  candidate platforms, so speculative scraping scales with the widening.

**Accepted behaviour to preserve:** when a user accepts a suggestion, the
pre-scraped items appear in the **library, NOT selected**, for that pool —
with differences for **menu** and **services** that need documenting
precisely.

To answer: what `app/Routing/PreScrapeDispatcher.php` dispatches and when,
what it costs per platform, whether it is already gated, and what a
gate should key on (account type? capability? band/pre-tick? user reaching a
step? accept-time with a fast path?). Feeds the plan; do not assume.

### Pre-ticking without a score (PROPOSED — owner asked for something simpler)

`band` ('auto'|'suggest') is what makes a suggestion arrive pre-ticked, and
it is computed from the auto threshold we are deleting. Proposed replacement,
one sentence and no numbers:

> **Tick it when we OBSERVED the link somewhere the user controls, AND it
> points at a specific account rather than a brand in general.**

Both halves are already recorded in the data — no new fields:

- *"somewhere the user controls"* = `link_observations.source` /
  `source_intents.origin`: `google_business` (their own verified listing),
  `website_import` (their own site), `link_in_bio`, or a manual paste. All
  are things the user published; none is a guess by us.
- *"a specific account"* = an identifier was captured (a store id / slug),
  or a storefront probe returned a `shop_name`.

How it lands in practice:

| Case | Ticked? | Why |
|---|---|---|
| DoorDash store link on their own Google listing, path matched | **yes** | they published it; names one store |
| Storefront probe hit on their own domain | **yes** | their domain; probe confirmed a real store |
| `ubereats.com` homepage link in their site footer | no | no specific account — probably nav chrome |
| "Square Appointments / Fresha" from the sector `top` list | no | our guess, not an observation |

This separates *observed and specific* from *guessed or generic*, which is
the same distinction the thresholds were groping at — without a 0–100 scale.
Needs owner sign-off.

---

## T1 — Suggestion cards show no account name `OPEN`

**Symptom.** The platforms step renders `Uber Eats / ubereats.com`. There is
no venue name, so you cannot tell whether the listing is yours.

**Root cause — traced end to end, three layers.**

1. `Placement::$identifierLabel` (`app/Routing/Placement.php:31`) defaults to
   `null`.
2. The ONLY thing in the entire backend that ever sets it is
   `app/Services/Brand/StoreBrandSeeder.php:91`
   (`->withLabel($probe->evidence['shop_name'] ?? null)`), and that is reached
   only through the **storefront** probes — Shopify, Squarespace, WooCommerce,
   BigCartel, Generic (`app/Routing/Probes/`).
3. Every other routing class — **ordering, reservations, booking, social,
   content** — therefore leaves it null. `SourceReconciler.php:515,540` writes
   the null through, `SetupPayload.php:265` and
   `SuggestionsController.php:161` read it back as `accountName`, and the card
   falls back to the bare domain.

So the name path is real and correct — it is just wired to exactly one
platform family. `identifier_icon` has the same shape and the same gap
(column + migration landed 2026-09-03, `withIcon()` exists, one call site).

**Fix — layered, cheapest first. No new network call for the common case.**

- **L1 — slug capture → title case (free, no I/O).** `Projection::$captures`
  already carries the detector's named groups, and `UberEats.php:78-79`
  already captures `slug` beside the `id` it uses as the identifier. In
  `PlacementPolicy`, pass `identifierLabel:` derived from
  `captures['slug']` when one is present:
  `elephant-cafe-flemington` → `Elephant Cafe Flemington`. Needs one shared
  helper (strip a trailing numeric id, split on `-`/`_`, title-case). This
  alone fixes every detector that captures a slug.
- **L2 — origin fallback (free).** When L1 yields nothing and the intent's
  `origin` is `google_business`, the listing name is already known on the
  workplace — use it.
- **L3 — generalise the probe (network, existing machinery).** Lift the name
  extraction out of `GenericStorefrontProbe` into a shared page-name probe so
  any class worth paying a fetch for can use the `withLabel()` / `withIcon()`
  pair that already exists. Budget-gated through `ProbeBudget` / `ProbeGate`
  as the storefront probes already are.

Degrades safely at every tier: a null label still renders today's bare-domain
card, so a partial landing is not a regression.

**Same pass — fix the fixture that lies.**
`apps/dashboard/app/dev/pages/setup/page.tsx:142` asserts *"The wire always
derives a venue name now (owner, 2026-09-03); the bare-URL fallback renders
only when even that fails."* That is false — only storefronts derive one.
This is the same defect class as the `sources` `string[]` fixture that hid a
runtime crash behind the error boundary: a fixture describing an intended
wire rather than the real one.

**Verify.** Re-run a signup against a Google listing; assert
`routing.source_intents.identifier_label` is non-null for `uber_eats.order`;
confirm the card reads the venue name at 375px and desktop.

**Files.** `app/Routing/PlacementPolicy.php` (pass the label),
new slug-label helper, `app/Services/Brand/StoreBrandSeeder.php` (reference —
already correct), `apps/dashboard/app/dev/pages/setup/page.tsx` (fixture).

---

## T2 — DoorDash and Quandoo never become suggestions `OPEN`

Promoted from HELD by the owner, 2026-09-03. These two are exactly what we
want suggested; today they are silently dropped.

**Observed** (real rows, `elephantcafe`, all found by the Google scrape at
03:53:39 and all classified to the *correct* surface):

| Domain | Surface | Confidence | Verdict |
|---|---|---|---|
| ubereats.com | `uber_eats.order` | 79 | `choose` → suggestion |
| doordash.com | `doordash.order` | 32 | `note` — kept as a link |
| quandoo.com.au | `quandoo.reserve` | 32 | `note` — kept as a link |

**Cause.** `LinkProjector` scores `40` for a host match, `+35` for a matched
`path_pattern`, `−8` when a host-only rule meets a deep path, and
`+round((strength−50)/5)`.

- `Doordash.php:41` — `Detector::url('doordash.com')->strength(ProfileLink)`.
  Host-only, no capture (its own comment says so). `40 − 8 + 0 = 32`.
- `Quandoo.php:42` — `Detector::url("quandoo.{$tld}")->strength(ProfileLink)`
  across TLDs. Host-only. `40 − 8 + 0 = 32`.
- `UberEats.php:78-81` — has a `->path()` with a named capture and
  `DeepLinkWithSlug`. `40 + 35 + 4 = 79`.

Gate: `ordering` and `reservations` are `suggest: 55`, so
`signupSuggestFloor = 55 − 15 = 40`, and harvested links take
`INDIRECT_PENALTY = 10`. So `32 → 22` (dropped) versus `79 → 69` (shown,
band `suggest` because `69 < auto 80`).

**Uber Eats had this exact bug and it was fixed on 2026-08-14** by adding the
path pattern — `UberEats.php:19-30` documents the 32-point host-only score
and the fix in so many words. DoorDash and Quandoo were never given the same
treatment.

**Fix.** Mirror `UberEats.php:78-81` on both — a `->path()` with a named
slug capture, `IdentifierSource::Path`, and `DeepLinkWithSlug`:
`40 + 35 + 4 = 79` pre-penalty, `69` after, clearing the 40 floor as band
`suggest`. Feeds T1's L1 for free, since the slug becomes available.

Path shapes must tolerate what the real links actually carry:

- DoorDash `/store/elephant-cafe-flemington-31262963` — slug and numeric id
  are joined by a hyphen in ONE segment, unlike Uber Eats' two segments.
- Quandoo `/place/elephant-cafe-flemington-103730` AND the `/menu` suffix
  form, with `?aid=63` and a Google `rwg_token` query param attached. The
  path pattern must not be anchored to end-of-string, and query params must
  not defeat it. Check `SecretParams.php` before touching query handling —
  it treats query params as potentially load-bearing.

**Required test — the owner's explicit ask.** Ship a regression test that
feeds the EXACT URLs observed on this signup through the projector and
asserts the outcome, so we can prove the fix would have produced the
suggestions this time:

| URL | Expected surface | Expected confidence | Expected verdict |
|---|---|---|---|
| `https://www.ubereats.com/au/store/elephant-cafe-flemington/Ix8CHHp_SHCzXudTvt4BZQ` | `uber_eats.order` | 79 | `Choose`, band `suggest` (unchanged — regression guard) |
| `https://www.doordash.com/store/elephant-cafe-flemington-31262963` | `doordash.order` | 79 | `Choose`, band `suggest` |
| `https://www.quandoo.com.au/place/elephant-cafe-flemington-103730?aid=63` | `quandoo.reserve` | 79 | `Choose`, band `suggest` |
| `https://www.quandoo.com.au/place/elephant-cafe-flemington-103730/menu?aid=63&rwg_token=AE37R_h2lXN84ZTAtFYNBPdg6aa1NiDskmkGLyO4ROb4kurFC5lJh4v14Y33Hh4Ggm7pci84yEZV2BbiTJ3__hFeoH3nHAyvuWMS5P8RjFr6AntUriORag0=` | `quandoo.reserve` | 79 | `Choose`, band `suggest` |

Assert the captured slug too (it is what T1's L1 turns into "Elephant Cafe
Flemington"), and keep a negative case — a bare `doordash.com` or
`quandoo.com.au` homepage link must NOT be promoted, so the path pattern is
doing the work rather than a blanket score raise.

**Open question to answer while doing this** (not a blocker): how many other
brands are host-only detectors sitting at 32 and silently dropped from every
signup? Count them during the fix — if it is a long list, this becomes a
scoring-policy change rather than three patches, and that is worth raising
before shipping only these two.

---

## HELD FOR DISCUSSION — do not plan yet

*(empty — H1 was promoted to T2. Park anything here that needs an owner
decision before it becomes work.)*

---

## Notes established while investigating (facts, not tasks)

- Sign-up builds **connect nothing by themselves** — confirmed in code
  (`PlacementPolicy.php:130`, "Sign-up builds connect nothing by themselves
  (A.2)") and in data (the Uber Eats intent is `band: suggest`,
  `state: proposed`, `connection_id: NULL`). The single `platform_connections`
  row on this account is the `google-business` workplace listing itself,
  created when the workplace was picked.
- The **website** scrape contributed zero platform signals: at 03:53:47
  `website_import` logged only 5 internal `elephantcafeflemington.com` pages,
  all `unknown-domain`, no surface key. Every platform signal came from
  `google_business`. It found a `/reservation` page and did not follow it —
  which is presumably where the Quandoo link lives.
- The previous-website scrape is **event-driven, not scheduled**:
  `WorkplaceObserver.php:234` dispatches `ScanPreviousWebsiteContentJob` the
  moment `previous_website` is set or changed — i.e. at the get-started
  listing step, not at signup.
- `ScanPreviousWebsiteContentJob`'s header documents a consciously-accepted
  side effect: any account with a `previous_website` whose site links
  Instagram can trigger a **paid Apify Instagram scrape** via
  `GoogleBusinessAutoSync::seed()`. Budget-capped, and a `has()` guard stops a
  re-run double-charging.
