# Instagram (`partna`) build wave — verification report, 2026-08-11

Second run of `docs/reviews/2026-08-10-instagram-build-wave-PROMPT.md`, this time **all six handles**.
Dev only (`https://dev-api.partna.au`, Supabase `glncumufgaqcmqhzwrxm`).
Deployed commit: **`75b8f631f`** — identical to `origin/development` HEAD, so every fix landed since the
08-10 run is live here (verified via `cloud deployment:list development`).

Run windows: **batch A 10:47:55–10:50:26Z**, **batch B 11:00:00–11:02:24Z**.
Six builds attempted, six reached `ready`, none retried, none failed. No code, config, migration or data
was changed during the run; every job referenced below ran on its own.

**Deviation from the prompt, at Josh's explicit instruction (2026-08-11).** The prompt forbids deleting
anything and requires stopping between batches to ask. Josh pre-authorised the whole sequence: purge the
08-10 accounts, run batch A, purge batch A to free the cap, run batch B, then purge batch B once this
report was written. All batch A evidence below was captured *before* its purge — see §7 for the audit of
what was deleted and when. Nothing else in the prompt was varied.

**Preconditions.** Egress IP `116.91.223.191` → `sha256 = a33251168bee8ca86598ec83c084a3367bbac2e4219144e048587a11375912a0`.
This is **not** the hash in the prompt (`4147c0d0…`) nor the one in the 08-10 report (`28a2b71d…`) — the
hash is unsalted `sha256(ip)` (`PreAccountBuildController.php:39`), which I verified by reproducing
`sha256("150.228.243.132")` = `28a2b71d…` exactly. It follows the *network*, not the machine. That bucket
held **0** live builds before batch A and **0** again before batch B.

---

## 1. Summary

| # | batch | `source_ref` | handle | subdomain | display_name | sector / source | links in | seeded | custom | conflict | probes spent/denied |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | A | `simondoylehair` | `simondoylehair` | `simondoylehair` | SIMON DOYLE \| Barber & Educator | `hair-salon` / `instagram` | 3 | 3 | 0 | 0 | 0/0 |
| 2 | A | `jess.hair.stylist` | `jesshairstylist` | `jesshairstylist` | Prahran Hairdresser | – | 3 | 2 | 0 | 1 | 0/0 |
| 3 | A | `crucibletattooco` | `crucibletattooco` | `crucibletattooco` | Crucible Tattoo Co. | – | 11 | 1 | 8 | 1 | 3/0 |
| 4 | B | `kimcosmik` | `kimcosmik` | `kimcosmik` | kimcosmik | `musician` / `instagram` | 15 | 3 | 11 | 1 | **6/1** |
| 5 | B | `themilleraffect` | `themilleraffect` | `themilleraffect` | Amanda Miller Pollard | `content-creator` / `instagram` | 4 | 0 | 4 | 0 | 1/0 |
| 6 | B | `supernormal_180` | `supernormal-180` | `supernormal-180` | Supernormal | `restaurant` / `instagram` | **0** | 0 | 0 | 0 | 0/0 |

Account 3 also produced **1 shop product** (see §3, ledger 3) — that is its eleventh input.

| # | build_id | user_id | state | wall clock | `GeneratePreAccountSiteJob` |
|---|---|---|---|---|---|
| 1 | `019ff06f-a18e-709f-a9bb-81ab5df359f9` | `019ff06f-a12a-71b2-a20b-1e453f68b281` | ready | 38s | 32s DONE |
| 2 | `019ff070-4589-71d2-b270-c7b0126dc1f9` | `019ff070-4574-70bf-afef-ebab56326234` | ready | 54s | 48s DONE |
| 3 | `019ff071-32ff-7086-9d24-df2b68a9c36a` | `019ff071-32ea-722d-9f05-c85582b181f5` | ready | 49s | 43s DONE |
| 4 | `019ff07a-b242-70d0-aa82-5c9d35e16c45` | `019ff07a-b201-7387-9ebd-4809809a4e2e` | ready | 22s | 19s DONE |
| 5 | `019ff07b-1621-72a7-b980-2cd40194df6d` | `019ff07b-15e1-7169-98b7-cd7eabd3c87a` | ready | 59s | 29s DONE |
| 6 | `019ff07c-1023-712c-b24c-870f2f7a1d1a` | `019ff07c-1012-704a-adef-1465e0558942` | ready | 55s | 52s DONE |

All six POSTs returned **202**. `failure_code` NULL on all six; `thin_scrape_at` NULL on all six (no
thin-scrape retry fired — every scrape returned 12 posts first time).

### What changed since 2026-08-10

| 08-10 finding | 08-11 status |
|---|---|
| **F1** `syncFindings` written back after the PRIV-2 strip | **CLOSED.** Absent from all six payloads |
| **F3** probe budget silently dropped 3 links | **CLOSED as silent.** `probes_denied` now logged; it read `1` on account 4 |
| **F4** `crucibletattooco` scraped 0 posts | **Did not reproduce.** 12 posts, `postsCount` 4164 |
| **F5** sector landed 1 of 3 | **6 of 6 accounts that had a category resolved it**; `sector_source='instagram'` on 4 of 6 |
| **F7** auto-routed connections are terminal | **Unchanged — still true** |
| **F8** event seeds cannot emit a finding | **Unchanged — still true** |
| §3.5 storefront probe | **First real case in the wave** (account 3) |
| §3.4 product → item | **Answered, and the prompt names the wrong tables** |

Three *new* findings this run: N1 (catalog ≠ auto-route classifier), N2 (`linkin.bio` unrolls to nothing),
N3 (the generic shop probe published a `Private:` WordPress draft). See §4.

---

## 2. Per-account link ledger

### How the input list was established

`InstagramSourceGenerator.php:91` strips `bioLinks` / `syncFindings` / `unmatched` (PRIV-2), so the
bio-level input list is not recoverable from stored state. What *is* recorded is `payload.website`, and in
all six cases it is a link-in-bio page. Each matched `LinkInBioDetector` and dispatched `LinkInBioScanJob`.

Unlike the 08-10 run, **the job now logs its own input count**. `platforms.link_in_bio_scan.completed`
emits `links_seen`, `own_host_skipped`, `outcomes` and the full `RouteContext::summary()`:

| # | bio page (`payload.website`) | links_seen | own_host_skipped | outcomes | budget | spent | **denied** | sites_deduped | ineligible |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `https://linktr.ee/simondoylehair` | 113 | 110 | seeded 3 | 6 | 0 | 0 | 0 | 0 |
| 2 | `https://linktr.ee/jess.hairstylist` | 113 | 110 | seeded 2, conflict 1 | 6 | 0 | 0 | 0 | 0 |
| 3 | `http://linktr.ee/crucibletattooco/` | 121 | 110 | pending 3, custom 6, conflict 1, seeded 1 | 6 | 3 | 0 | 5 | 0 |
| 4 | `https://linktr.ee/kimcosmik?utm_source=…` | 125 | 110 | pending 6, custom 5, seeded 3, conflict 1 | 6 | **6** | **1** | 1 | 0 |
| 5 | `https://linktr.ee/themilleraffect` | 113 | 109 | pending 1, custom 3 | 6 | 1 | 0 | 0 | 0 |
| 6 | `https://linkin.bio/supernormal_180` | **0** | 0 | `[]` | 6 | 0 | 0 | 0 | 0 |

I independently reproduced the extraction (parse `<a href>` only, absolutize, drop non-`http(s)`, drop
same-host, dedupe — matching `WebsiteLinkHarvester.php:423-451` and `LinkInBioScanJob.php:96`). My counts
match the job's own `links_seen` **exactly** on all six: 113 / 113 / 121 / 125 / 113 / 0 total unique http
anchors, and 3 / 3 / 11 / 15 / 4 / 0 off-host. That correspondence is now established against the job's
logged figure, not merely inferred from row agreement as it was on 08-10.

`platforms.instagram.bio_links_routed` reports `links_seen: 1` on every account — the bio link itself —
with `findings 0, unmatched 0, probes 0`.

### Account 1 — `simondoylehair` (3 in)

| # | input URL | outcome | proof |
|---|---|---|---|
| 1 | `eventbrite.com.au/e/hobart-mens-hair-workshop-…-1993984195405?aff=oddtdtcreator` | **seeded** | `platform=eventbrite`, `resource_id=event-ba7bc4f70f505571` |
| 2 | `fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260` | **seeded** | `platform=fresha`, payload `{url: …/a/anseo-studio-v0v92jna, source: instagram, selection: null}` |
| 3 | `youtube.com/@dvlpmnttv?si=y6yPIR5r27b_P9C1` | **seeded** | `platform=youtube`, `surface_key=youtube.channel`, `username: ""` |

**3 in = 3 seeded. Balances.** 0 custom, 0 probes. Identical to 08-10.

### Account 2 — `jesshairstylist` (3 in)

| # | input URL | outcome | proof |
|---|---|---|---|
| 1 | `fresha.com/book-now/jess-hairstylist-v8ct52bl/…` | **seeded** | `platform=fresha`, `selection: null` |
| 2 | `tiktok.com/@jess.hairstylist?_t=…&_r=1` | **seeded** | `platform=tiktok`, `username: jess.hairstylist` |
| 3 | `instagram.com/jess.hair.stylist?igsh=…&utm_source=qr` | **conflict** | no row — the `instagram` slot is held by the source connection |

**3 in = 2 seeded + 1 conflict. Balances.** Identical to 08-10.

### Account 3 — `crucibletattooco` (11 in)

| # | input URL | outcome | probe? | proof |
|---|---|---|---|---|
| 1 | `www.crucibletattooco.com.au/` | custom | ✔ (resolved=false) | `link-984c89fffb758fbd` @10:50:27 |
| 2 | `…/appointment.html` | custom | site-deduped | `link-1fe180a294772f04` @10:50:24 |
| 3 | `…/artists.html` | custom | site-deduped | `link-8b18600dc8df3e1b` @10:50:24 |
| 4 | `…/aftercare.html` | custom | site-deduped | `link-a840f3244206d032` @10:50:24 |
| 5 | `…/accessibility.html` | custom | site-deduped | `link-e86926704b067d55` @10:50:24 |
| 6 | `…/feedback.html` | custom | site-deduped | `link-2ec055add9ec9969` @10:50:24 |
| 7 | `paytherent.net.au/` | **SHOP PRODUCT** | ✔ (resolved=**true**) | `site.shop_products` `019ff072-01dd-…` @10:50:31 |
| 8 | `bsky.app/profile/crucibletattooco.bsky.social` | custom | ✔ (resolved=false, 7s) | `link-6e3ad4eb3390f71b` @10:50:39 |
| 9 | `instagram.com/crucibletattooco/` | **conflict** | n/a | no row |
| 10 | `au.pinterest.com/crucibletattooco_/` | custom | ✘ tombstoned | `link-1bfb8576a64061f7` @10:50:24 |
| 11 | `tiktok.com/@crucibletattooco` | **seeded** | n/a | `platform=tiktok`, `surface_key=tiktok.profile` |

**11 in = 1 seeded + 1 conflict + 8 custom + 1 shop product. Balances. Nothing unaccounted for.**

The 6 in-scan customs (@10:50:24) are the scan's `custom: 6`; the two later ones (@10:50:27, @10:50:39)
are the unresolved probes falling back through `CommerceProbeJob` → `seedCustom`. DB `custom` count = 8.

**This is where the probe-budget fairness fix shows.** On 08-10 the same page spent all 6 probes and denied
3 links. Today `sites_deduped: 5` — the five `crucibletattooco.com.au` sub-pages collapsed onto the one
site probe — so the page needed only **3 probes** and denied **none**. Same page, same budget, 3 links
better off.

### Account 4 — `kimcosmik` (15 in) — the dedupe and budget case

| # | input URL | outcome | why | proof |
|---|---|---|---|---|
| 1 | `obskurmusic.bandcamp.com/track/carissa-illy-…` | custom | probed, resolved=false | `link-d1a5f65a81b1238b` @11:00:32 |
| 2 | `kimcosmik.bandcamp.com/album/star-glider` | custom | probed, resolved=false | `link-2ebf51ff48c0c8f3` @11:00:39 |
| 3 | `kimcosmik.bandcamp.com/` | custom | **site-deduped** (same site as #2) | `link-762df396e8f30786` @11:00:23 |
| 4 | `www.mixcloud.com/KimCosmik/` | custom | probed, resolved=false | `link-e69abe79c9853b0f` @11:00:50 |
| 5 | `www.youtube.com/channel/UCCY6-AIHHvrmZW5J8IAjk-A` | **seeded** | first youtube | `platform=youtube`, `youtube.channel` |
| 6 | `ra.co/dj/kimcosmik` | custom | probed, resolved=false | `link-a3a0353f4ea498ee` @11:00:50 |
| 7 | `www.instagram.com/kimcosmik/` | **conflict** | IG slot held | no row |
| 8 | `www.facebook.com/kimcosmik/` | **seeded** | first facebook | `platform=facebook`, `facebook.profile` |
| 9 | `www.discogs.com/search?q=kim+cosmik&type=all` | custom | probed, resolved=false | `link-862db5d24ef8f6c3` @11:00:51 |
| 10 | `cybersoul.bandcamp.com/` | custom | probed, resolved=false | `link-dbb45df8314603eb` @11:00:51 |
| 11 | `www.youtube.com/@cybersoul9038` | custom | **duplicate platform** (youtube #2) | `link-26616a355319a3b5` @11:00:23 |
| 12 | `www.facebook.com/groups/3004349706304446/` | custom | **duplicate platform** (facebook #2) | `link-dd06e510efd1087f` @11:00:23 |
| 13 | `www.facebook.com/hybridrave` | custom | **duplicate platform** (facebook #3) | `link-7b957c45364f8da9` @11:00:24 |
| 14 | `www.juno.co.uk/products/kim-cosmik-arsonist-recorder-…/952291-01/` | custom | **PROBE BUDGET DENIED** | `link-befca34352b3269f` @11:00:24 |
| 15 | `discord.com/invite/q3FvffbQ` | **seeded** | first discord | `platform=discord`, `discord.server` |

**15 in = 3 seeded + 1 conflict + 11 custom. Balances.** DB `custom` count = 11.

The scan's `custom: 5` (in-scan, @11:00:23–24) decomposes exactly as: 3 duplicate-platform (#11, #12, #13)
+ 1 site-deduped (#3) + 1 budget-denied (#14). The 6 `pending` are #1, #2, #4, #6, #9, #10 — in page
order, the first six probe-eligible links, which is precisely where the budget of 6 runs out. #14 is the
only probe-eligible link *after* that point, which is why `probes_denied` reads exactly **1**.

**§3.7 — dedupe holds.** YouTube: 2 links → **1** connection + 1 custom. Facebook: 3 links → **1**
connection + 2 custom. Never two rows for one platform, never zero. Note the *outcome* has changed since
the prompt was written: a duplicate-platform link no longer returns `skipped` and get dropped — it now
returns `custom` and lands as a card (`LinkInBioScanJob.php:135-142`, commit `9fd682964`). The prompt's
"the second of each pair **must** return `skipped`" is out of date; the current contract is stronger,
because nothing is thrown away.

**The three Facebook shapes.** A profile (`/kimcosmik/`), a group (`/groups/<id>/`) and a page
(`/hybridrave`) were fed in. The profile seeded; the other two became custom links. That is the
first-link-per-platform rule firing, so the normalizer's reserved-path-segment blind spot the prompt wanted
probed **was not exercised** — the dedupe short-circuits before the normalizer is asked. UNVERIFIED, and
not testable through this path.

### Account 5 — `themilleraffect` (4 in)

| # | input URL | outcome | proof |
|---|---|---|---|
| 1 | `canva.link/hxwh4ybxzn38wkg?utm_…` | custom | probed, resolved=false → `link-3fcb4917750cabc6` @11:02:24 |
| 2 | `www.shopltk.com/explore/themilleraffect?utm_…` | custom | `link-98a8b511ba61ea89` @11:01:30 |
| 3 | `www.shopltk.com/explore/themilleraffect/collections/11ecafbde…?utm_…` | custom | `link-afaafa44e18d648c` @11:01:31 |
| 4 | `poshmark.com/closet/themaffect?utm_…` | custom | `link-fb3af68f1629237c` @11:01:31 |

**4 in = 4 custom. Balances.**

**The §3.9 target did not materialise, and I am recording that rather than papering over it.** The prompt
expected LTK ×2, Amazon, Poshmark, canva.link, Pinterest, TikTok, Facebook "plus several affiliate product
links", and budget exhaustion. The live page today carries **4** off-host anchors. No Amazon, no Pinterest,
no TikTok, no Facebook. One probe of six was spent. The page has been pruned since the prompt was written.
**Budget exhaustion was measured on account 4 instead**, where it happened for real.

### Account 6 — `supernormal_180` (0 in)

`payload.website` = `https://linkin.bio/supernormal_180` — still a `linkin.bio` page, exactly as
`LinkInBioDetector`'s comment records. **The detector recognised it and dispatched the scan** (the job log
names that URL), so the 2026-07-23 host-list fix is confirmed working on the account that motivated it.

**But the scan found nothing: `links_seen: 0`, `own_host_skipped: 0`, `outcomes: []`.**

I fetched the page myself: HTTP **200**, **6,441 bytes**, `<title>Linkin.bio</title>`, and **zero
`<a href>` anchors**. The strings `opentable`, `sevenrooms` and `ubereats` do not appear anywhere in the
delivered HTML. It is a JS-rendered shell — the links exist only after client-side hydration, and
`SafeUrlFetcher` does one plain fetch.

Consequence: this account ends with its `instagram` connection and **nothing else** — 0 custom links, 0
platform connections, `pageOrder: ["home"]`, one ranked action. See finding N2.

---

## 3. Section-by-section results

### §1 — Identity and handle

| # | Check | Result | Evidence |
|---|---|---|---|
| 1.1 | IG username → suggested handle | **PASS** | all six; `jess.hair.stylist`→`jesshairstylist`, `supernormal_180`→`supernormal-180` |
| 1.2 | `handle` == `subdomain` | **PASS ×6** | `handle_lc = subdomain` asserted in SQL, true on all six |
| 1.3 | `display_name` is a real name, not the handle | **PASS ×5, N/A ×1** | see below |
| 1.4 | `first_name` populated sensibly | **PARTIAL** | `SIMON`/`Amanda` ✓; `Prahran`, `Crucible`, `Supernormal`, `kimcosmik` are `Str::before($fullName,' ')` on non-person names |
| 1.5 | IG category → sector | **PASS — 4 of 6, and 6 of 6 where a category existed** | see below |
| 1.6 | Contact fields folded | **FAIL (correct-by-design)** | `site.workplaces` = 0 rows ×6 |

**1.2 — SIGNUP-1 holds on two different trigger characters.** `jess.hair.stylist` (two periods:
`Str::slug()` drops them, `subdomainBaseFromHandle()` hyphenates) → both `jesshairstylist`.
`supernormal_180` (underscore: same divergence class, a trigger the 08-10 run never exercised) → both
`supernormal-180`. The fix at `PreAccountBuildService.php:131-134` — passing `$user->handle_lc` to
`createSiteForHandle()` rather than re-deriving — holds on both. The POST response body already carries the
correct `subdomain`, so the divergence cannot even be observed at the API boundary.

**1.3 — SIGNUP-2 does not reproduce; the one handle-shaped name is a genuine empty.** `simondoylehair`
returns `display_name = "SIMON DOYLE | Barber & Educator"` (the 08-10 "before" was the literal string
`simondoylehair2`). Five of six carry a real name. The sixth, `kimcosmik`, has `display_name = "kimcosmik"`
— but its payload `fullName` is the **empty string**, i.e. Instagram genuinely publishes no full name for
that account. Falling back to the username is the only available behaviour, not the SIGNUP-2 regression
returning.

**1.5 — this is the biggest change since 08-10.** On 08-10 `sector_source='instagram'` had *just* appeared
for the first time and landed 1 of 3. Today:

| account | `businessCategory` | sector | source |
|---|---|---|---|
| `simondoylehair` | `Hair Stylist` | `hair-salon` | instagram |
| `jesshairstylist` | `Artist` | – | – |
| `crucibletattooco` | **null** | – | – |
| `kimcosmik` | `Musician/band` | `musician` | instagram |
| `themilleraffect` | `Blogger` | `content-creator` | instagram |
| `supernormal-180` | `Restaurant` | `restaurant` | instagram |

Both misses are **deliberate**, and neither is a taxonomy gap:
- `"Artist"` is left unmapped on purpose (`fix/sector-detection-repair`): sector is sticky
  (`IdentitySync::applySector:229` returns early once `sector_source` is set and isn't `google-business`),
  so an Instagram-stamped guess would permanently lock Google Business out of correcting it — and this
  account is a hairdresser.
- `crucibletattooco` returns **null**, where on 08-10 it returned the literal string `"None"`. That is the
  `categoryOrNull()` placeholder filter working: `"None"` is stripped rather than published verbatim as a
  business category. Nothing to map because Instagram supplies nothing.

`Blogger` and `Musician/band` are exact-match hits in `INSTAGRAM_CATEGORY_SECTORS`; `Restaurant` resolves
too. The Instagram-vocabulary pass added on `fix/category-compound-strip` is carrying real load here.

**1.5 has a visible downstream consequence worth recording.** `SiteResource:114` overlays
`ProfileDesignPresets::forUser()` — an industry-derived, read-time preset keyed on sector — over the stored
columns. All six `site.design_kits` rows are **entirely NULL** (auto-insert trigger fired, nothing written).
So the public `designKit` is populated **only** for accounts whose sector resolved:

| account | `designKit` served |
|---|---|
| `simondoylehair` | `{"colors":{"accent":"#b8375a"},"typography":{"fontFamily":"helvetica-neue"}}` |
| `jesshairstylist` | `{}` |
| `crucibletattooco` | `{}` |
| `kimcosmik` | `{"colors":{"accent":"#e11d48"},"typography":{"fontFamily":"monument-grotesk"}}` |
| `themilleraffect` | `{"colors":{"accent":"#db2777"},"typography":{"fontFamily":"helvetica-neue"}}` |
| `supernormal-180` | `{"colors":{"accent":"#e0491f"},"typography":{"fontFamily":"monument-grotesk"}}` |

`#b8375a` / `helvetica-neue` is `SectorStylePresets.php:88-91` BEAUTY_PERSONAL_CARE and `#e0491f` /
`monument-grotesk` is FOOD_DRINK, byte-for-byte. Sector detection is not a metadata nicety on this path —
it is what gives a pre-account site its visual identity, and an unresolved sector ships a bare page.

**1.6 — 0 workplace rows on all six. Correct and permanent**, per the 08-10 report's closed F6: Instagram
withholds business email/phone from logged-out viewers, so `applyContactFields` returns at its
`$email === null && $phone === null` guard. This run neither demonstrates nor refutes the fold; there was
nothing to fold. Not re-raised.

### §2 — The scrape itself

Full payload key set, **identical on all six — 13 keys**:
`_folder, _mediaDiagnostics, businessCategory, followersCount, fullName, images, mode, postsCount,
profilePicUrl, username, videoPoster, videoUrl, website`

**`syncFindings` is gone. F1 is closed.** On 08-10 all three payloads carried it, because
`LinkInBioScanJob::mergeFindingsBack` wrote it back *after* `InstagramSourceGenerator.php:91` had stripped
it. That method now returns early for an unclaimed user (`LinkInBioScanJob.php:193-200`), and the strip
holds on every one of the six.

As the 08-10 report established, this key set is `$selection` — a hand-built 12-key projection
(`InstagramConnectionSeeder.php:153-191`) — **not** what the actor returned. Absence of a key here is
evidence about the projection only. I have not re-derived conclusions about the raw item from it.

| # | Check | Result | Evidence |
|---|---|---|---|
| 2.1 | Profile fields captured | **PASS** | table below; all six have `fullName` (one empty), `followersCount`, `postsCount` |
| 2.2 | `biography` present | **N/A to the projection** | settled 2026-08-11 in the prior report: the raw item carries it, `$selection` does not copy it. Not re-investigated |
| 2.3 | Profile picture mirrored | **PASS ×6** | `profilePicUrl` on Laravel Cloud object storage, one folder per build |
| 2.4 | Post media mirrored | **FAIL (as rows) ×6** | `site.site_media` = **0** on all six |
| 2.5 | Every media row has a `webp` variant | **VACUOUS** | 0 media rows ⇒ 0 without a variant |
| 2.6 | Gallery ≤ 6 | **PASS (vacuously)** | gallery = 0 ×6 |

| field | 1 | 2 | 3 | 4 | 5 | 6 |
|---|---|---|---|---|---|---|
| `businessCategory` | Hair Stylist | Artist | **null** | Musician/band | Blogger | Restaurant |
| `fullName` | ✓ | ✓ | ✓ | **`""`** | ✓ | ✓ |
| `followersCount` | 11066 | 4161 | 30043 | 8461 | 336250 | 83684 |
| `postsCount` | 365 | 107 | **4164** | 826 | 5167 | 2320 |
| `images` length | 1 | 1 | 1 | 1 | 1 | 1 |
| `_mediaDiagnostics.posts` | 12 | 12 | **12** | 12 | 12 | 12 |
| videos available | 5 | 4 | 1 | 1 | 8 | 4 |

**F4 did not reproduce, which corroborates the 08-10 correction.** `crucibletattooco` — the account that
returned `posts: 0`, `postsCount: null`, `images: []` on 08-10 — returned 12 posts and `postsCount: 4164`
today. That matches the raw-run-history finding that the zero-post result was per-run actor flakiness, not
an account property. `thin_scrape_at` is NULL on all six, so the thin-scrape detector never fired and its
retry path is **untested by this wave** (there was no thin scrape to catch).

**§2.4 is unchanged and settled.** The actor returns 12 posts; the generator picks 1 photo + 1 reel; those
are mirrored to Laravel Cloud object storage and referenced only as URLs inside
`platform_connections.payload` and the public `designMedia` array. Nothing lands in `site.site_media`, so
`site.media_variants`, the `webp` variant rule and the `core.enforce_site_gallery_max6` trigger are all
untouched by this pipeline — there is nothing for them to act on. Public `profile.gallery` is `[]` on all
six.

### §3 — Link routing

**Which router ran: `app/Services/Platforms/LinkRouter` (legacy).** Confirmed by the call chain
`InstagramConnectionSeeder:201 → InstagramAutoSync::seed → LinkRouter::route` and `LinkInBioScanJob:105`.

`routing.link_observations` is **0 rows for all six users** — checked and **expected**, not a finding.
`LinkRouter` writes none; only `App\Routing\LinkRoutingService` does, reached from `RoutingController` and
the importers. (The whole table holds exactly one row on dev, a `paste` from 2026-07-28.) Evidence for
routing decisions here is the seeded rows plus the `scraping`-queue job logs — which, unlike 08-10, now
carry the full outcome and probe accounting.

| # | Check | Result | Evidence |
|---|---|---|---|
| 3.1 | Every classified platform link produced a connection row | **PASS** | eventbrite, fresha ×2, youtube ×2, tiktok ×2, facebook, discord all present; the 3 `instagram` self-links correctly produced conflicts, not rows |
| 3.2 | Every unclassified link became a custom link | **PASS** | 0/0/8/11/4/0 custom rows, each traceable to a named input |
| 3.3 | Shop/unclassified links dispatched `CommerceProbeJob` within budget | **PASS, and the cap is no longer silent** | 3 + 6 + 1 = 10 probes across the wave; `probes_denied` logged per scan |
| 3.4 | A probe that resolved a **product page** produced an **item** | **PASS — but not in `content.items`** | see below |
| 3.5 | A probe that resolved a **storefront** produced a shop connection | **PASS — first real case** | account 3, `platform=shop` @10:50:31 |
| 3.6 | Input == seeded + custom + skipped + pending + denied | **PASS — balances on all six** | 3=3, 3=3, 11=11, 15=15, 4=4, 0=0 |
| 3.7 | Duplicate-platform links: exactly ONE row per platform | **PASS** | account 4: youtube 2→1, facebook 3→1; every later duplicate landed as a custom card |
| 3.8 | Gate-denied links became custom links | **UNVERIFIED — the case never arose** | see below |
| 3.9 | Count links that fell past the 6-probe cap | **1** (account 4, the Juno product page) | `probes_denied: 1` |

**3.4 — the prompt names the wrong tables.** `content.items` and `content.item_links` are **0** for every
account in the wave, yet a product genuinely landed. `CommerceProbeJob::probe()` on `OUTCOME_PRODUCT` calls
`ShopProductSeeder::seed()`, whose storage plane is the **relational shop** — `site.platform_connections`
(`platform=shop`, payload `{"storage":"relational"}`), `site.shop_brands` and `site.shop_products` — not
the content plane. Anyone re-running this prompt should query `site.shop_products`, not `content.items`.
The probed product for account 3:

```
site.shop_products 019ff072-01dd-705d-b383-157f87bc748a
  product_id 8b84148b322966d2, position 0
  data {"url":"https://paytherent.net.au/","title":"Private: Demo","price":null,"image":null,
        "images":[],"vendor":null,"currency":null,"description":null,"available":true}
```

**3.5 — the storefront case, and the store verdict is correct.** `paytherent.net.au` genuinely runs
**WooCommerce** (I fetched it: nine `woocommerce` markers in the delivered HTML,
`<title>Pay The Rent | Saying Sorry Isn't Enough</title>`). So the probe was right that there is a store
there. The connection written is `platform=shop`, `resource_id=shop`, `surface_key=partna.storefront`,
`routing_class=shop`, with an `is_individual=true` brand row (`brand_id='individual'`,
`provider='generic'`, `url=''`, `source_url=''`) — the reserved individually-added-product bucket, exactly
as `ShopProductSeeder.php:65-79` writes it. What lands in it is the problem — see finding N3.

**3.8 — I could not test this, and will not claim otherwise.** `supernormal_180` was the designated
gate-denial case: its OpenTable/SevenRooms (`reservations`) and UberEats/Menulog (`online-ordering`) links
are denied to a `partna` account by `LinkRouter::gateAllows` (`:172-173`, `$isBusiness && $isFood`) and
should fall through to custom links. I verified the *mechanism* is in place — `OpenTable`, `SevenRooms`,
`UberEats` and `Menulog` are all present in `WebsiteLinkHarvester::RESERVATION_HOSTS` / the online-ordering
list, so they would classify — but **not one of those links ever reached the router**, because the
`linkin.bio` page yielded zero anchors (N2). The gate-denial path is UNVERIFIED for this wave.

**§3c — link-in-bio unroll.** `LinkInBioScanJob` was dispatched and ran for **all six** accounts (3s / 3s /
2s / 4s / 3s / 1s, all DONE), each immediately after its `GeneratePreAccountSiteJob`. Five of six unrolled
and every link inside got an outcome; the sixth returned an empty anchor set (N2).

Catalog-coverage predictions from the prompt, checked:
- `bluesky`/`bsky.app`, `discogs.com`, `juno.co.uk`, `shopltk.com`, `poshmark.com`, `canva.link` — all
  landed as custom links, as predicted. Confirmed, not re-reported as discoveries.
- **Pinterest → custom link. Correct by decision**, retired 2026-07-28 (`LegacyPlatformMap.php:117-121`).
- `bookwell.com.au` and Amazon storefronts did not appear on any of the six pages — untested.
- **The "platforms that ARE defined and must therefore classify" list is wrong.** See N1.

### §4 — Does the loop continue? (cascade)

| # | Check | Result | Evidence |
|---|---|---|---|
| 4.1 | Any seeded connection dispatched a `ConnectFetchJob`? | **NO** | zero `ConnectFetchJob` entries across both run windows |
| 4.2 | Seeded Fresha connection fetched its service menu? | **NO** | both `fresha` payloads are `{url, source:"instagram", selection:null}` |
| 4.3 | Services projected into `site.services`? | **NO** | 0 rows, all six |
| 4.4 | Categories / assignments projected? | **NO** | 0 / 0, all six |
| 4.5 | Any URL inside a connection's payload got routed? | **NO** | no second-order rows |

**F7 is unchanged and I re-verified rather than carrying it forward on trust.** The three `ConnectFetchJob`
dispatch sites (`GenericPlatformController.php:180`, `EventsController.php:48`,
`DefersBespokeConnect.php:97`) are all dashboard connect-flow controllers; nothing on the auto-route path
dispatches it, matching `BuildsAutoSyncFindings`' own "no vendor call, no dispatch, DB only" for the
booking slot. `FreshaServiceProjector::sync()` hangs off that fetch, so it never runs. Both auto-routed
Fresha rows are `selection: null`, and `FreshaFetch.php:36-39` throws `FetchNotModifiedException` whenever
`selection` is not an array — so the hourly `integrations:refresh` will 304 these rows forever. **An
auto-routed Fresha connection can never acquire services by any automatic path.** Unchanged from 08-10;
still not fixed; not fixed here.

The 08-10 quantification gap also stands: both Fresha booking pages are client-rendered, so how many
services the accounts actually list remains **UNVERIFIED**.

### §5 — Auto-signup rules

| # | Check | Result | Evidence |
|---|---|---|---|
| 5.1 | IG auto-media / latest-media rule enabled on create | **PASS** | `display_settings` NULL on every connection; `AutoSyncSetting::isOn` treats an absent `auto_sync_latest` key as ON (`AutoSyncSetting.php:41`) |
| 5.2 | `is_published` is `false` | **PASS ×6** | all six `site.sites` rows |
| 5.3 | Site nonetheless publicly reachable | **PASS ×6** | `https://<handle>.partna.au/` → **200** each |
| 5.4 | KV entry written | **PASS ×6** | `SyncSubdomainToKvJob` RUNNING→DONE once per build (900ms / 867ms / 867ms / 800ms / 945ms / ~950ms); the 200s in 5.3 are only reachable through a KV hit |
| 5.5 | `GET /api/public/profiles/<handle>` returns built content | **PASS ×6** | 200 each, `architectureId: "staple"` |
| 5.6 | `status = 'unclaimed'` | **PASS ×6** | all six |

5.2 + 5.3 are SIGNUP-3 behaving as decided. Confirmed, not flagged.

`pageOrder` per account: `["home","watch","events"]`, `["home"]`, `["home","shop","links"]`,
`["home","watch","links"]`, `["home","links"]`, `["home"]`. `profile.links` is `[]` on all six while
`rankedActions` carries the cards — custom links surface through `rankedActions`, not `profile.links`.

### §6 — Errors and noise

**Zero exceptions, zero failed jobs, zero 5xx across both run windows.** `public.failed_jobs` = **0 rows
total** (not just for this run). Every job logged `RUNNING → DONE`.

Job inventory across 10:47:30–11:14:00:

| job | runs | outcome |
|---|---|---|
| `GeneratePreAccountSiteJob` | 6 | DONE (32/48/43/19/29/52s) |
| `LinkInBioScanJob` | 6 | DONE (3/3/2/4/3/1s) |
| `CommerceProbeJob` | 10 | DONE (0.2s–10s) |
| `EnrichLinkCardJob` | 23 | DONE |
| `SyncSubdomainToKvJob` | 6 | DONE |
| `CloudflareCachePurgeJob` | ~35 | DONE |
| `AggregateCacheMetricsJob`, `CheckStreamingLiveStatusJob` | scheduled | DONE, unrelated |

**The one recurring warning is unrelated to this wave and predates it:** `POST /api/public/analytics/ping`
and `POST /api/public/analytics/pageviews` returning **404**, from a browser user-agent, several times a
minute. The same standing noise the 08-10 report recorded. Neither route exists.

**The 08-10 `error waiting on adopted process` pair did not recur.** Zero occurrences in this run's
windows.

**Log-tool caveat, re-confirmed:** `cloud env:logs` caps at **100 records** per call and truncates from
the *start* of the window. My first batch-B pull silently lost everything before 11:00:26; I re-pulled in
narrow `--from`/`--to` slices (35 / 54 / 76 records) and checked the first timestamp of each against the
requested start. Anyone re-running this should not trust a single wide `--minutes` call to be complete.

---

## 4. Findings

> **Triage, 2026-08-12 — read `docs/reviews/2026-08-12-instagram-build-wave-DEFERRED.md` before
> acting on anything below.** N1 and N4 are since **fixed** (`5c2572c10`, live on dev). **F8 was
> never open** — it was fixed at `751277dd9`, an ancestor of the commit tested here; it is carried
> below in error and should be ignored. N2, N3 and F9 are deferred with a shape-of-fix each; F7 is
> handed to pool slice 3.

**N1 — being defined in `app/Catalog/Definitions/` does not make a link classify on the Instagram
auto-route path. (New.)** `LinkRouter` classifies via `WebsiteLinkHarvester::classify()` (`:363`), which
walks **hand-maintained host constants** — `SOCIAL_HOSTS` (21 entries), `BOOKING_HOSTS` (18),
`RESERVATION_HOSTS` (12), `SHOP_HOSTS` (2) — with no reference to the catalog. Evidence from account 4:
`Bandcamp.php`, `Mixcloud.php` and `ResidentAdvisor.php` all exist as full catalog definitions with
detectors, canonical URLs, connect and fetch strategies — and all four Bandcamp links, the Mixcloud link
and the Resident Advisor link became **custom links**, after each burned a commerce probe. `Discord.php`
also exists *and* `discord` is in `SOCIAL_HOSTS`, so the Discord link seeded. That is the whole difference.
The prompt's assertion that "Platforms that ARE defined and must therefore classify: Resident Advisor,
Mixcloud, Bandcamp, SoundCloud, …" is not a property of the system. Cost is concrete: on this one account,
six probes were spent on hosts the catalog can already identify, which is exactly what starved the Juno
product page.

**N2 — `linkin.bio` is recognised but unrolls to nothing. (New.)** `LinkInBioDetector` matched
`https://linkin.bio/supernormal_180` and dispatched the scan — the 2026-07-23 host-list fix works. But the
page is a JS-rendered shell: HTTP 200, 6,441 bytes, `<title>Linkin.bio</title>`, **zero `<a href>`
anchors**, and no `opentable`/`sevenrooms`/`ubereats` string anywhere in the delivered HTML.
`WebsiteLinkHarvester::allOutboundLinks()` therefore returns an empty set and the job logs
`links_seen: 0, outcomes: []` and exits clean. The account ends with an Instagram connection and nothing
else. The 2026-07-23 note says the fix stopped the page "landing as one inert custom link instead of
unrolling" — it now lands as **zero** links, which is worse than one inert card: the URL is not preserved
anywhere on the site. `links_seen: 0` on a matched bio host is a precise, already-logged signal that
nothing distinguishes from an empty page today.

**N3 — the generic shop probe published a WordPress private draft as a product. (New.)** Account 3's
`paytherent.net.au` probe resolved `OUTCOME_PRODUCT` and seeded a `site.shop_products` row titled
**`"Private: Demo"`** — WordPress's `Private:` prefix for a non-published post — with `price: null`,
`image: null`, `images: []`, `description: null`, `vendor: null`, `currency: null`, `available: true`. The
site does run WooCommerce, so "there is a store here" was correct; what the scraper picked off it was a
demo/private item with no purchasable content. It is **publicly surfaced**: `pageOrder` for that account
is `["home","shop","links"]` and `rankedActions[0]` is `('page','Shop')`. So an Indigenous solidarity
rent-payment site became a Shop tab on a tattoo studio's page, fronted by an empty draft product. Two
separable defects: the scraper does not reject a `Private:`-prefixed title, and it does not require a price
or image before treating a read as a product.

**N4 — the probe budget starved the wave's clearest product-page test.** `probes_denied: 1` on account 4,
and the denied link is `www.juno.co.uk/products/kim-cosmik-arsonist-recorder-hybrid-collective-vol-1-vinyl/952291-01/`
— an individual vinyl product page, and the single best "does a product page become an item?" case the
prompt set up. It became a custom link without a probe. Nothing vanished, and unlike 08-10 the loss is now
**counted** rather than silent. But the six probes that consumed the budget went to Bandcamp ×3, Mixcloud,
Resident Advisor and Discogs — all N1 cases. Fixing N1 would have left the budget for Juno.

**N5 — `content.items` is the wrong plane for probe-seeded products.** §3.4 of the prompt directs the
reader to `content.items` / `content.item_links`; both are 0 across the whole wave while a product
demonstrably landed in `site.shop_products`. Recorded so the next reader does not conclude "no product
was ever created" from an empty content table — the same class of mistake the prompt itself warns about
for `routing.link_observations`.

**F7 (carried, unchanged) — auto-routed connections are terminal.** No `ConnectFetchJob`, no
`FreshaServiceProjector`, 0 services, 0 categories, 0 assignments across all six. All three dispatch sites
are dashboard controllers. `integrations:refresh` covers `fresha` on a 2-day TTL but
`FreshaFetch.php:36-39` 304s on `selection: null`, permanently. Re-verified this run, not assumed.

**F8 (carried, unchanged) — event seeds cannot emit a finding.** `LinkRouter::seedEvent` returns
`RouteResult::seeded(...)` and that `$findings` parameter defaults to `[]` (`RouteResult.php:47`). Account
1's `eventbrite` connection row exists, so nothing is lost; the synced-modal list under-reports events by
construction.

**F9 (carried) — `PreAccountBuild::scopeLive()` ignores `expires_at`.** Unchanged at
`PreAccountBuild.php:99-102`. Already triaged OPPORTUNISTIC in the 08-10 report, with the repair's
booby-trap documented there. Nothing new to add; not re-opened.

### Not findings — expectations in the prompt that the live pages no longer support

Stated so the next reader does not treat these as failures:

- **`themilleraffect` is no longer link-dense.** 4 off-host anchors, not the ~12 the prompt describes. No
  Amazon, Pinterest, TikTok or Facebook. Probe-budget exhaustion was measured on `kimcosmik` instead.
- **`kimcosmik` has no SoundCloud links** (the prompt expects two) and **one** Juno product page, not
  three. It does have four Bandcamp, two YouTube and three Facebook links, so the dedupe test stood up.
- **The Facebook reserved-path-segment blind spot was not probed.** The group and page URLs never reached
  the normalizer — first-link-per-platform short-circuits first.
- **`crucibletattooco`'s Facebook link** is still not an `<a href>` on its Linktree, only embedded JSON, so
  it was never an input. Same as 08-10.

---

## 5. Explicitly correct-by-design — do not re-raise

- **`routing.link_observations` empty (0 rows × 6).** `LinkRouter` writes none; only `LinkRoutingService`
  does, and the Instagram path never reaches it. Checked and expected.
- **`bioLinks` / `unmatched` / `syncFindings` absent from the payload.** PRIV-2 data minimisation
  (`InstagramSourceGenerator.php:91`). `syncFindings` coming back was F1 and is now **fixed** — its absence
  is the correct state.
- **Pinterest → custom link.** Deliberately retired 2026-07-28, `LegacyPlatformMap.php:117-121`.
- **The three `instagram` self-links → conflict with no row.** The `instagram` slot is held by the source
  connection; a conflict offers the swap and writes nothing.
- **Duplicate-platform links → custom cards, not `skipped`.** Changed deliberately in `9fd682964`;
  `skipped` now means a true no-op. One connection per platform, every later duplicate kept as a card.
- **`is_published = false` while the site returns 200.** SIGNUP-3. Pre-account sites are public pre-claim.
- **Empty `designKit` on accounts 2 and 3.** Sector-derived preset; no sector, no preset. The stored
  `design_kits` columns are NULL by design on create.
- **`"Artist"` not mapping to a sector.** Deliberate — sector is sticky, and a wrong Instagram guess would
  lock Google Business out of correcting it.
- **`display_name = "kimcosmik"`.** Instagram publishes `fullName: ""` for that account. Username fallback
  is the only available behaviour, not SIGNUP-2.
- **`site.workplaces` empty ×6.** Instagram does not disclose business email/phone to logged-out viewers.
  Closed as by-design 2026-08-11; see the 08-10 report §1.6.
- **Gallery-max-6 trigger and `webp` variant rule untested.** Vacuous, not failing — no media rows exist
  for them to constrain.

---

## 6. Async re-check

Batch A was re-queried at **11:05Z** (+15 min after build 3) and batch B at **11:14Z** (+12 min after
build 6). Every count was byte-identical to the first pass. Decisive evidence that no worker touched these
rows afterwards: `max(platform_connections.updated_at)` per user is

- 10:48:33 / 10:49:30 / 10:50:41 (batch A) — all inside the original run windows
- 11:01:27 / 11:02:32 / 11:02:23 (batch B) — likewise

`public.failed_jobs` = 0 rows total. The post-run log sweep (11:02:35→11:14:00, 76 records, 5 warnings, all
the unrelated analytics 404s) contains no pipeline-adjacent entry. So every FAIL and every UNVERIFIED above
is settled, not merely early.

---

## 7. State left behind

**None. Dev is clean.** Per Josh's instruction, all nine accounts touched by this exercise were purged
through `AccountDeletionService::purge()` — the full teardown: Supabase auth user, R2/object-storage
artifacts, site-cache invalidation, PII erasure across the surfaces the DB cascade does not reach, the
append-only audit-link pre-null, then `forceDelete` with `UserObserver::deleted` retiring the handle in KV.

| when | accounts | note |
|---|---|---|
| 10:46Z | `simondoylehair` (claimed 08-11 09:16, had Supabase auth user `b230fb1c-…`), `jesshairstylist`, `crucibletattooco` | the 2026-08-10 run's leftovers |
| 10:59Z | batch A ×3 | pre-authorised, to free the per-IP cap for batch B |
| 11:11Z | batch B ×3 (`kimcosmik`, `themilleraffect`, `supernormal-180`) | after this report was written |

All nine returned `purge=true`. Final verification, run after the last purge:

```
users_left 0 | builds_left 0 | sites_left 0 | live_on_my_ip 0 | failed_jobs 0
```

and all six handles return **404** on both `https://<handle>.partna.au/` and
`GET /api/public/profiles/<handle>`.

One caveat worth recording: immediately after the first purge `simondoylehair.partna.au` still returned
**200** for about a minute — the Cloudflare Cache API entry outliving the KV retire.
`CloudflareCachePurgeJob`'s follow-up schedule (`[120, 300, 900]s`) cleared it. If you purge and
immediately rebuild the same handle, expect a brief window where the edge still serves the old page.

**Six Apify scrapes billed, one per handle. No build was retried.**
