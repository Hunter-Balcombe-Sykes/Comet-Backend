# Instagram (`partna`) build wave — batch B verification report

**Run date 2026-08-17**, window **12:16:59Z – 12:19:38Z**, dev only (`https://dev-api.partna.au`,
Supabase `glncumufgaqcmqhzwrxm`). Filename keeps the `2026-08-10` prefix the execute prompt specified;
the prompt is dated 08-10, this run is not.

Three builds attempted, **three succeeded**, none retried, none deleted. No code, config, migration or
data was changed. Batch A's report (`2026-08-10-instagram-build-wave-RESULTS.md`) is cross-referenced,
not restated.

---

## 0. Preconditions — re-measured, and they did not match the prompt

The prompt's warning to re-derive the IP hash was load-bearing. **My egress IP is
`116.91.223.240`, not the `150.228.243.132` recorded on 08-10.**

| Precondition | Measured | Proof |
|---|---|---|
| Cap value | **3** | `config/partna.php:1144` |
| Hash algorithm | unsalted `hash('sha256', CF-Connecting-IP)` | `PreAccountBuildController.php:31,39` |
| Prompt's hash `28a2b71d…` | **arithmetically correct** for `150.228.243.132` | reproduced locally with `shasum -a 256` |
| My hash | `8bc2a9c338954a7d27a90ec4421633cd26e3fae20ac09901a93096930282a473` | same method |
| My live unclaimed builds | **0** — hash absent from the table entirely | grouped query over all of `core.pre_account_builds` |
| Waitlist / bot gate | `PARTNA_WAITLIST_*` and `BOT_PROTECTION_*` unset on dev → `enabled=false`, `mode='off'` | `cloud environment:get`, `config/partna.php:1025` |
| Actor | `apify~instagram-profile-scraper` (`PARTNA_INSTAGRAM_ACTOR` unset) | `config/partna.php:406` |

I read the whole table grouped by `created_ip_hash` rather than filtering on an assumed hash, exactly
as the prompt's fallback instructs. Cap was clear: **3 free slots, batch B needs 3.**

### P1 — batch A's three builds no longer exist (finding, not an obstacle)

`core.pre_account_builds` holds **zero** rows for `simondoylehair`, `jess.hair.stylist` or
`crucibletattooco`, and **zero** rows under hash `28a2b71d…`. Their `core.users` rows are gone too
(a `UNION` over builds and users returned `[]`), and all three subdomains now return **404**.

Batch A §7 states it left the cap at "3 of 3". It is now 0 of 3 for that origin.

**This was not automatic expiry.** `expiry_days` = 30 (`config/partna.php:1142`), so builds created
2026-08-10 expire 2026-09-09; `builds:prune-expired` (daily 03:40, `routes/console.php:289`) could not
have taken them. They were deliberately torn down — which is precisely the "releasing cap slots is
Josh's call" action the prompt reserves. Recorded so the next reader does not treat batch A's
"state left behind" section as still true.

**Consequence to note:** batch B's rows carry `created_ip_hash = 8bc2a9c3…`, a different origin from
batch A's. Verified `true` on all three rows.

---

## 1. Summary

| # | `source_ref` | handle | subdomain | display_name | sector / source | routable links | seeded | items | conflict |
|---|---|---|---|---|---|---|---|---|---|
| 4 | `kimcosmik` | `kimcosmik` | `kimcosmik` | `kimcosmik` | `musician` / `instagram` | 15 | 3 | 11 | 1 |
| 5 | `themilleraffect` | `themilleraffect` | `themilleraffect` | `Amanda Miller Pollard` | `content-creator` / `instagram` | 5 | 0 | 5 | 0 |
| 6 | `supernormal_180` | `supernormal-180` | `supernormal-180` | `Supernormal` | `restaurant` / `instagram` | **0** | 0 | **0** | 0 |

| # | build_id | user_id | state | failure | wall clock | job |
|---|---|---|---|---|---|---|
| 4 | `01a00fa7-5603-7144-b0c5-e80e12bd234d` | `01a00fa7-5595-7398-83fb-4a1f2965d997` | `ready` | – | **40s** | 34s DONE |
| 5 | `01a00fa8-01f2-71d2-8bf1-1cdae0b576b7` | `01a00fa8-01c2-7159-9d32-2632b530e238` | `ready` | – | **48s** | 40s DONE |
| 6 | `01a00fa8-e713-702e-a336-48d00d90f91c` | `01a00fa8-e6ea-70c2-aa9d-66dd875b8414` | `ready` | – | **56s** | 33s DONE |

All three POSTs returned **202** + `build_state: "pending"`. Three Apify scrapes billed, one per handle,
**no build retried**. Probe budget: **never exhausted on any account** (`probes_denied: 0` everywhere).

**Sector landed on all three, `sector_source='instagram'`** — batch A managed 1 of 3. `"Musician/band"`
→ `musician`, `"Blogger"` → `content-creator`, `"Restaurant"` → `restaurant`, each with a
`sector.transition … outcome:"applied"` log line. This is batch A's F5 repair (the `category_name`
third candidate and the three-pass `fromInstagramCategory()`) working on live data.

---

## 2. Per-account link ledger

### Where the input list came from — better evidence than batch A had

Batch A reconstructed its ledger by re-fetching the Linktree and arguing the lists agreed. That
inference is unnecessary: **`LinkInBioScanJob` logs its own accounting** —
`platforms.link_in_bio_scan.completed` carries `links_seen`, `own_host_skipped`, an `outcomes` map and
`RouteContext::summary()` (`LinkInBioScanJob.php:157-165`, `RouteContext.php:295-304`). The direct bio
pass logs `platforms.instagram.bio_links_routed` likewise. Every count below is the job's own, not mine.

`InstagramSourceGenerator.php:91` still strips `bioLinks`/`syncFindings`/`unmatched` (PRIV-2), so the
payload cannot enumerate inputs — the logs can.

### Account 4 — `kimcosmik`

Bio pass: `links_seen 1, findings 0, unmatched 0, probes_spent 0` — the sole bio link is the Linktree,
consumed by the detector.
Scan of `linktr.ee/kimcosmik`: **`links_seen 125, own_host_skipped 110` → 15 off-host**,
`outcomes {custom:9, seeded:3, conflict:1, pending:2}`, `probe_budget 6, probes_spent 2, probes_denied 0`.

| # | input URL | outcome | proof |
|---|---|---|---|
| 1 | `discord.com/invite/q3FvffbQ` | **seeded** | `platform=discord`, 12:17:42 |
| 2 | `facebook.com/kimcosmik/` | **seeded** | `platform=facebook`, 12:17:40 |
| 3 | `youtube.com/channel/UCCY6-AIHHvrmZW5J8IAjk-A` | **seeded** | `platform=youtube`, 12:17:40 |
| 4 | the account's own `instagram.com` link | **conflict** | `outcomes.conflict=1`; no row (slot held by source) |
| 5 | `obskurmusic.bandcamp.com/track/carissa-illy-…` | custom | `content.items` + `f_link`, served on wire |
| 6 | `kimcosmik.bandcamp.com/album/star-glider` | custom | ″ |
| 7 | `kimcosmik.bandcamp.com/` | custom | ″ |
| 8 | `cybersoul.bandcamp.com/` | custom | ″ |
| 9 | `www.mixcloud.com/KimCosmik/` | custom | ″ |
| 10 | `ra.co/dj/kimcosmik` | custom | ″ |
| 11 | `youtube.com/@cybersoul9038` | custom (**2nd YouTube**) | ″ — dedupe, see §3.7 |
| 12 | `facebook.com/groups/3004349706304446/` | custom (**2nd Facebook**) | ″ |
| 13 | `facebook.com/hybridrave` | custom (**3rd Facebook**) | ″ |
| 14 | `discogs.com/search?q=kim+cosmik&type=all` | pending → custom | probe 1 of 2, `resolved:false` |
| 15 | `juno.co.uk/products/kim-cosmik-arsonist-…` | pending → custom | probe 2 of 2, `resolved:false` |

**15 in = 3 seeded + 1 conflict + 9 custom + 2 pending. Balances exactly.** 11 items, 11 `f_link` rows,
11 URLs on the public wire.

*The prompt's description of this page is inaccurate in detail:* it predicted ~19 links, "two Bandcamp,
two SoundCloud and two YouTube" and "three Juno Records vinyl product pages". Actual: **15 off-host,
four Bandcamp, zero SoundCloud, two YouTube, three Facebook, one Juno.** The dedupe test still ran
(YouTube ×2, Facebook ×3); the SoundCloud half of it did not exist to test.

### Account 5 — `themilleraffect`

Bio pass: **`links_seen 2`**, `unmatched 1`, `probes_spent 1` — the Linktree plus one directly-probed link.
Scan of `linktr.ee/themilleraffect`: `links_seen 113, own_host_skipped 109` → **4 off-host**,
`outcomes {pending:1, custom:3}`, `probes_spent 1, probes_denied 0`.

| # | input URL | outcome |
|---|---|---|
| 1 | `shopltk.com/explore/themilleraffect` | custom (LTK, link-only) |
| 2 | `shopltk.com/explore/themilleraffect/collections/11ecafbde…` | custom (2nd LTK) |
| 3 | `poshmark.com/closet/themaffect` | custom |
| 4 | `att.com/internet/fiber/?source=EMC980676826…` | custom (affiliate) |
| 5 | `canva.link/hxwh4ybxzn38wkg` | custom |

**5 routable in = 5 items out. Balances.** (1 bio link + 4 scan links; the Linktree URL itself is
consumed, not persisted.) **Zero platform connections** beyond the Instagram source row.

*The prompt's prediction fails here on the facts:* it expected Pinterest, TikTok, Facebook and
"several affiliate product links", and above all **probe-budget exhaustion**. The page carried four
off-host anchors and the run spent **2 probes of 6 across both passes**. See §3.9.

The public payload redacts `utm_medium`/`utm_source`/`utm_campaign` values to `[redacted]` while keeping
the URL — observed, not investigated.

### Account 6 — `supernormal_180`

Bio pass: `links_seen 1, findings 0, unmatched 0, probes_spent 0`.
Scan of `https://linkin.bio/supernormal_180`: **`links_seen 0, own_host_skipped 0, outcomes []`**.

**0 routable in = 0 out.** No connections beyond the Instagram source row, **no custom links, no items,
no pools on the public profile at all.** See B1 — this is the report's principal finding.

---

## 3. Section-by-section

### §1 — Identity and handle

| # | Check | Result | Evidence |
|---|---|---|---|
| 1.1 | IG username → suggested handle | **PASS** | `kimcosmik`→`kimcosmik`, `themilleraffect`→`themilleraffect`, `supernormal_180`→`supernormal-180` |
| 1.2 | `handle` == `subdomain` | **PASS** | `handle_lc = lower(subdomain)` computed in SQL: `true` ×3 |
| 1.3 | `display_name` is a real name | **PASS ×2, correct-by-design ×1** | `Amanda Miller Pollard`, `Supernormal`; `kimcosmik` = handle, see below |
| 1.4 | `first_name` sensible | **PARTIAL** | `Amanda` ✓ (+ `last_name` `Pollard`); `Supernormal`, `kimcosmik` are `Str::before()` on a non-person name |
| 1.5 | IG category → sector | **PASS — 3 of 3** | `musician`/`content-creator`/`restaurant`, all `sector_source='instagram'` |
| 1.6 | Contact fields folded | **0 rows** — correct-by-design | `site.workplaces` = 0 ×3; batch A F6 settled this: Instagram withholds business contacts from logged-out viewers |

**1.2 — SIGNUP-1 does not reproduce, and `supernormal_180` is a stronger test than batch A ran.**
The handle contains an **underscore**, a different divergence trigger from batch A's periods. The POST
response already showed `subdomain: "supernormal-180"`; the handle came back `supernormal-180` too.
The fix at `PreAccountBuildService.php:131-134` passes `$user->handle_lc` into `createSiteForHandle()`
rather than re-deriving, so the two **cannot** diverge for any input character. Structural, not a
character-class patch.

**1.3 — `kimcosmik`'s display_name is correct, not a SIGNUP-2 recurrence.** The payload carries
`fullName: ""` (empty string, not null). `stringOrNull()` maps `""` → `null`
(`InstagramIdentitySync.php:191-194`), so `applyDisplayName()` early-returns on
`$fullName === null` (`:116`) and the handle-derived seed stands. The account has no full name on
Instagram; the handle is the only available value. I checked this specifically because it presents
exactly as batch A's SIGNUP-2 symptom.

### §2 — The scrape

Payload key set, identical on all three: `_folder, _mediaDiagnostics, businessCategory, followersCount,
fullName, images, mode, postsCount, profilePicUrl, username, videoPoster, videoUrl, website`.
**`syncFindings` is absent** — see B8, batch A's F1 is fixed.

| # | Check | Result | Evidence |
|---|---|---|---|
| 2.1 | Profile fields captured | **PASS — 3 of 3, no thin scrape** | `fullName` ✓✓(empty for #4) , categories all non-`None`, followers 8,467 / 336,205 / 83,771, posts 826 / 5,169 / 2,321 |
| 2.2 | `biography` present | **absent from `$selection`, present in raw** | key set above; batch A corrected this 08-11 — `$selection` is a hand-built projection, not the actor's item |
| 2.3 | Profile picture mirrored | **PASS** | all 3 `profilePicUrl` → **HTTP 206 `image/jpeg`** on range request |
| 2.4 | Post media mirrored to rows | **FAIL (as rows)** | `site.site_media` = **0 ×3**; files exist on Laravel Cloud storage, referenced only as payload URLs |
| 2.5 | Every media row has a `webp` variant | **VACUOUS** | 0 media rows |
| 2.6 | Gallery ≤ 6 | **PASS (vacuously)** | 0 gallery rows; `enforce_site_gallery_max6` untouched |

`_mediaDiagnostics.posts` = **12 on all three** with `pickedPhoto`/`pickedVideo` both true — the 1-of-12
selection batch A described, and **no repeat of batch A's F4 zero-post scrape**. `images` length 1 on
all three; `photo.jpg` verified 206.

### §3 — Link routing

**Which router ran: `app/Services/Platforms/LinkRouter` (legacy).** Chain
`InstagramConnectionSeeder → InstagramAutoSync::seed → LinkRouter::route` and
`LinkInBioScanJob:105 → $router->route(...)`.

**`routing.link_observations` = 0 for all three users — checked, and expected.** `LinkRouter` writes
none; only `App\Routing\LinkRoutingService` does, and this path never reaches it. Not a finding.

| # | Check | Result | Evidence |
|---|---|---|---|
| 3.1 | Classified link → connection row | **PASS, with B2** | discord/facebook/youtube seeded; Bandcamp/Mixcloud/RA did **not** — see B2 |
| 3.2 | Unclassified link → custom link | **PASS** | every non-seeded, non-conflict input has an item + `f_link` row + a URL on the wire |
| 3.3 | Shop/unclassified dispatched `CommerceProbeJob` within budget | **PASS** | 2 probes (#4), 2 (#5), 0 (#6); all `commerce_probe.resolved … resolved:false` |
| 3.4 | Probe resolving a product page → item | **UNVERIFIED — no such case arose** | all 4 probes returned `resolved:false`, incl. the Juno *product* URL |
| 3.5 | Probe resolving a storefront → shop connection | **UNVERIFIED — no such case arose** | no shop connection written |
| 3.6 | **Input == seeded + custom + skipped + pending + denied** | **PASS — balances on all three** | 15=15, 5=5, 0=0 |
| 3.7 | Duplicate-platform dedupe | **PASS** | exactly one YouTube and one Facebook connection; the 2nd YouTube and 2nd/3rd Facebook links became items. Nothing vanished |
| 3.8 | Gate-denied → custom | **UNTESTABLE this batch** | the restaurant's reservations/ordering links never reached the router — B1 |
| 3.9 | Probe budget overflow count | **PASS — never reached** | `probes_denied: 0` on every pass |

**§3.7 — the prompt's stated rule is stale, and the code says so explicitly.** §3b claims a second link
to a seeded platform "returns `skipped`". `LinkRouter.php:93` returns **`RouteResult::custom()`**, with
the comment: *"custom(), not skipped(): the link still needs somewhere to go … the second link of a
platform … was written NOWHERE."* The observed behaviour matches the code, not the prompt. Dedupe is
working **and** the duplicates survive as cards — a strictly better outcome than the prompt describes.

**§3c — link-in-bio unroll.** Dispatched and ran for all three (`LinkInBioScanJob` 6s / 3s / 692ms, all
DONE). Two Linktrees unrolled and every link inside got an outcome (ledgers above balance). The
`linkin.bio` page returned **zero** links — B1.

Catalog coverage re-checked by grepping `app/Catalog/Definitions/`, since the prompt's list is partly
wrong:

- **No definition** (prompt correct): `discogs.com`, `juno.co.uk`, `poshmark.com`, `canva.link`.
- **Not in `Definitions/` but recognised anyway** (prompt wrong): `shopltk.com` and `amazon.*` are
  `LINK_ONLY_PLATFORM` entries in `WebsiteLinkHarvester.php:226-247`, category `link`, deliberately
  never connected and **spending no probe**.
- **Defined, and still did not connect** (prompt wrong): `Bandcamp.php`, `Mixcloud.php`,
  `ResidentAdvisor.php`, `Soundcloud.php` all exist. See **B2**.
- **Pinterest** — retired by decision 2026-07-28; no Pinterest link appeared on either page this batch,
  so untested, not disproven.

### §4 — Does the loop continue? (cascade)

**The prompt's §4 SQL cannot be run as written.** `to_regclass` returns **NULL** for `site.services`,
`site.service_categories` and `site.service_category_assignments` — all three were dropped by the
services cutover (2026-08-17, spec §28). Services now live in `content.*`.

**And §4 is untestable in batch B regardless: no booking connection was seeded on any of the three
accounts.** No Fresha, no Booksy — the three seeded rows are discord, facebook and youtube.

| # | Check | Result |
|---|---|---|
| 4.1 | Seeded connection dispatched `ConnectFetchJob`? | **NO** — zero `ConnectFetchJob` entries in the window |
| 4.2–4.4 | Fresha fetch / services / categories projected? | **N/A** — no booking connection exists to test |
| 4.5 | URL inside a connection payload got routed? | **NO** — no second-order rows |

Batch A's **F7** (auto-routed connections are terminal; `FreshaFetch.php:36-39` 304s forever on
`selection: null`) stands unchallenged and unre-tested. This batch neither confirms nor refutes it.

### §5 — Auto-signup rules

| # | Check | Result | Evidence |
|---|---|---|---|
| 5.1 | Auto-media / latest-media rule on at create | **PASS** | `display_settings` NULL on all connections → `AutoSyncSetting::isOn` treats absent `auto_sync_latest` as ON |
| 5.2 | `is_published` false | **PASS** | `false` ×3 |
| 5.3 | Site nonetheless publicly reachable | **PASS** | `kimcosmik` / `themilleraffect` / `supernormal-180`.partna.au → **200** each |
| 5.4 | KV entry written | **PASS** | `SyncSubdomainToKvJob` DONE at 12:17:04 (943ms), 12:17:48 (1s), 12:18:44 (960ms) — one per build; the 200s above are only reachable through a KV hit |
| 5.5 | `GET /api/public/profiles/<handle>` returns built content | **PASS** | 200 ×3, `architectureId: "staple"`, `designKit` present ×3 |
| 5.6 | `status = 'unclaimed'` | **PASS** | ×3 |

5.2 + 5.3 are SIGNUP-3 behaving as decided. Confirmed, not flagged.

### §6 — Errors and noise

**Zero exceptions, zero failed jobs, zero 5xx attributable to this run.** `public.failed_jobs` = **0
rows, total**. Every pipeline job logged `RUNNING → DONE`.

Job inventory, 12:16:55–12:19:41: `GeneratePreAccountSiteJob` ×3 (34s/40s/33s), `LinkInBioScanJob` ×3
(6s/3s/692ms), `CommerceProbeJob` ×4, `EnrichPoolLinkJob` ×16, `SyncSubdomainToKvJob` ×3,
`CloudflareCachePurgeJob` (many), plus unrelated scheduled `CheckStreamingLiveStatusJob`,
`RefreshConnectionJob`, `GoogleMenuPhotoScanJob`, `BuildSiteDocumentJob`.

Three environmental notes, none caused by this wave:

- **A deploy landed mid-verification.** `[Deploy: 2314] App cluster starting` at 12:23:21, `2313` shut
  down 12:23:33, Horizon restarted 12:23:29. All of this batch's writes were complete by 12:19:36
  (`max(platform_connections.updated_at)`), so no build was affected — but the environment changed
  under the report.
- **A concurrent session was writing to dev** from `150.228.151.63` throughout
  (`POST /api/content/pools/shop/items` 422, 422, then 201). Not mine.
- **`slow_public_profile` warning** for `kimcosmik` (1,076ms) on the first uncached fetch — a warning,
  not an error. Batch A's `error waiting on adopted process` entries did **not** recur.

---

## 4. Findings

**B1 — `linkin.bio` unrolls to nothing, and the account is left with zero links.**
`supernormal_180`'s only bio link is `https://linkin.bio/supernormal_180`. The chain:

1. `LinkInBioDetector` **matches** — the 2026-07-23 host-list fix works. `LinkInBioScanJob` dispatched.
2. `InstagramAutoSync.php:116-125` dispatches and then `continue`s. Its own comment: *"Nothing about the
   bio-link URL itself is persisted."* So the URL is **not** seeded as a custom link.
3. The job fetched the page (no error, job DONE in 692ms) and logged
   `links_seen 0, own_host_skipped 0, outcomes []`.
4. Independently reproduced: the page is a **6,441-byte JavaScript shell containing zero `<a>` anchors**
   (`<title>Linkin.bio</title>`, 5 `<script>` tags, generic `og:url = later.com/link-in-bio/`). All
   content is client-rendered by Later.

Result: **0 connections, 0 custom links, 0 items, no pools on the public profile.** An 83,771-follower
restaurant produced a site with a name, a sector, a profile picture and nothing else.

The detector comment records that this account's link previously *"landed as one inert custom link
instead of unrolling"*. **The fix inverted which half works:** detection now succeeds, so the URL is
consumed as unrollable — but the unroll yields nothing, and the inert custom link that used to exist is
gone. For a JS-rendered link-in-bio host, matching the detector is **worse** than not matching it.
Verified against the account that motivated the fix, exactly as the prompt asked.

**Consequence:** §3.8, the gate-denial test (OpenTable/SevenRooms → `reservations`, UberEats/Menulog →
`online-ordering`, both denied to a `partna` account and expected to fall through to custom links) is
**untestable in this batch**. Those links live inside the linkin.bio page and never reached the router.
The prompt's premise — that these links would classify and then be demoted — was never exercised.

**B2 — a catalog-defined platform cannot auto-connect from a bio link unless it is also in the legacy
host constants.** `kimcosmik` has four Bandcamp links, one Mixcloud and one Resident Advisor. All six
became custom links. All three platforms have full definitions —
`app/Catalog/Definitions/Bandcamp.php` even carries a bespoke `connect('connect.bandcamp.url.v1')` and
`fetch('fetch.bandcamp.scrape.v1')`.

Mechanism, verified in code: `WebsiteLinkHarvester::classifyFromCatalog()` returns
**`'category' => 'link'` for every surface it matches** (declining only `shop`), and
`LinkRouter` maps `'link' => RouteResult::custom()`. So the catalog backstop can only ever produce a
custom link. The three that *did* seed — discord, facebook, youtube — are in the hand-maintained
constants that `classify()` consults first.

This is deliberate as written (the `'link'` branch's comment says "Recognised, deliberately not
connected — a marketplace or board (Amazon, LTK, Pinterest) … Spends no probe: that is the entire
reason these hosts are classified at all", i.e. the backstop was added for **probe conservation**,
N1/N4 2026-08-11). The effect is broader than the comment's framing: it silently covers Bandcamp,
Mixcloud, Resident Advisor, Soundcloud and Tidal — content platforms with real connect strategies, not
marketplaces. **A musician's entire music presence routes to custom links.** Reported as a behavioural
gap between the catalog and the router, not as a fix.

**B3 — the prompt's §3.4 proof surface is the wrong table.** `content.item_links` = **0 rows** for all
three accounts despite 16 items of kind `link`. The URLs are not lost: they live in **`content.f_link`**
(11 rows for `kimcosmik`, 5 for `themilleraffect` — exactly matching the item counts) and are served on
the public wire. §3.4/§3.5 name `content.item_links` as where a probe-resolved product page should
appear; on this path that table is simply not the storage location. Whether `item_links` is *supposed*
to be populated by any path is **unverified** — I established only where these URLs actually are.

**B4 — probe budget was never approached, so the prompt's `themilleraffect` prediction does not hold.**
Predicted: budget exhausted, links falling past the cap. Actual: `probes_denied: 0` on every pass;
2 probes of 6 for `kimcosmik`, 2 across both passes for `themilleraffect`, 0 for `supernormal_180`.
Two reasons, both structural: LTK/Amazon are `LINK_ONLY_PLATFORM` (no probe), and B2's catalog backstop
absorbs Bandcamp/Mixcloud/RA for free. Only genuinely unknown hosts (Discogs, Juno, AT&T, Canva) spend
a probe. **The silent-cap concern is real in the code but did not arise in this batch.**

**B5 — batch A's builds and users were hard-deleted; batch A §7 is now false.** See P1. Not caused by
this run; recorded because the prompt and batch A's report both assert those three builds are live.

**B6 — the prompt's §4 SQL targets three dropped tables.** `site.services`,
`site.service_categories`, `site.service_category_assignments` all return `NULL` from `to_regclass`.
Anyone re-running this prompt gets an error, not a result.

**B7 — a deploy and a concurrent writer overlapped the verification window.** Deploy 2314 at 12:23:21;
another session POSTing to `/api/content/pools/shop/items` throughout. Neither touched these three
users (`max(updated_at)` = 12:19:36, inside the run window), but a re-run should not assume a quiet env.

**B8 — batch A's F1 is FIXED.** F1 was: `LinkInBioScanJob::mergeFindingsBack` wrote `syncFindings` back
into the payload after `InstagramSourceGenerator` stripped it (PRIV-2). The method now opens with an
`if ($user->isUnclaimed())` early return citing PRIV-2 by name, and **`syncFindings` is absent from all
three payloads** in this batch — including two accounts with a link-in-bio page, the exact condition
that triggered F1. Closed by evidence, not assumed.

**B9 — batch A's F4 (zero-post scrape) did not recur.** All three accounts returned
`_mediaDiagnostics.posts: 12` with both a photo and a video picked. Batch A's conclusion that F4 was
per-run actor flakiness rather than account-specific is consistent with this batch. The underlying gap
it identified — nothing treats a zero-post result on a high-post account as suspect — is untested here
because no scrape came back thin.

---

## 5. Explicitly correct-by-design — do not re-raise

- **`routing.link_observations` empty (0 ×3).** `LinkRouter` writes none; only `LinkRoutingService`
  does, and this path never reaches it.
- **`bioLinks` / `unmatched` / `syncFindings` absent from the payload.** PRIV-2 strip
  (`InstagramSourceGenerator.php:91`) plus the `isUnclaimed()` guard in `mergeFindingsBack` (B8).
- **A duplicate-platform link becomes a `custom` card, not `skipped`.** `LinkRouter.php:93`. The prompt's
  §3b table says `skipped`; the code and the data say `custom`. The prompt is stale, the behaviour is
  correct and better.
- **`kimcosmik`'s `display_name` == handle.** `fullName` is `""`; `stringOrNull()` → `null` →
  `applyDisplayName()` early-returns. Not SIGNUP-2.
- **LTK / Amazon / Poshmark / Canva / Discogs / Juno → custom links.** Two are deliberate link-only
  hosts, four have no definition. All landed; none vanished.
- **`is_published = false` while the site returns 200.** SIGNUP-3.
- **`site.workplaces` = 0.** Batch A F6, settled: Instagram withholds business email/phone from
  logged-out viewers under both actors.
- **Gallery-max-6 trigger and `webp` variant rule untested.** Vacuous — no media rows exist to constrain.
- **The Instagram self-link → conflict with no row.** The `instagram` slot is held by the source
  connection.

---

## 6. State left behind

Three live, unclaimed, undeleted pre-account builds on dev, all reachable, none retried:

| handle | user_id | site | expires |
|---|---|---|---|
| `kimcosmik` | `01a00fa7-5595-7398-83fb-4a1f2965d997` | https://kimcosmik.partna.au | 2026-09-16 |
| `themilleraffect` | `01a00fa8-01c2-7159-9d32-2632b530e238` | https://themilleraffect.partna.au | 2026-09-16 |
| `supernormal-180` | `01a00fa8-e6ea-70c2-aa9d-66dd875b8414` | https://supernormal-180.partna.au | 2026-09-16 |

Three Apify scrapes billed. **Per-IP cap for `8bc2a9c3…` (egress `116.91.223.240`) is now 3 of 3** — the
next signup build from this origin will 429 with `IP_BUILD_CAP`. Batch A's origin `28a2b71d…` is at
**0 of 3** (P1). Nothing was deleted by this session.
