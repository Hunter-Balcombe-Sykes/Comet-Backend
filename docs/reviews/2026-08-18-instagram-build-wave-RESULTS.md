# Instagram (`partna`) build wave — verification report, 2026-08-18

Third full run of `docs/reviews/2026-08-10-instagram-build-wave-PROMPT.md`, **all six handles**, dev only
(`https://dev-api.partna.au`, Supabase `glncumufgaqcmqhzwrxm`).

Deployed commit **`4f1280e8e0`** (deploy `depl-a287224a`, finished 2026-08-18T00:27:42Z) — i.e. the whole
overnight **W2–W10** wave is live. My local checkout (`49f02e231`) is an *ancestor* of it, so every code
citation below was read out of the deployed tree via `git show 4f1280e8e0:<path>`, not the working copy.

Run windows: **batch A 00:48:56–00:51:25Z**, **batch B 01:00:18–01:02:09Z**.
Six builds attempted, **six reached `ready`**, none retried, none failed. No code, config, migration or
data was changed during the run.

**Why re-run at all.** Batch B last ran 2026-08-17 12:16Z
(`2026-08-10-instagram-build-wave-RESULTS-BATCH-B.md`); the overnight wave landed *after* it and touches
exactly what this prompt measures — `bfcb087b9` (link-in-bio unroll everywhere), `c526bcf5e` (W6 Fresha
eager run on connect), `c6031ca0b` / `5c1da8c25` (media pool opt-in, `InstagramMediaProjector`),
`20260819001000` (X3, `commerce_probe` observations).

**Deviation from the prompt, at Josh's explicit instruction (2026-08-18, mid-run).** Prompt rule 4
forbids deleting anything and requires stopping to ask before freeing cap slots. Josh pre-authorised it
("Free slots yourself giving permission"). Batch A was therefore purged by me at 00:59:55Z, *after* all
its evidence was captured, to free the three slots batch B needs. §7 audits exactly what was deleted.
Nothing else in the prompt was varied.

**Preconditions, re-measured.** Egress IP `116.91.223.240` →
`sha256 = 8bc2a9c338954a7d27a90ec4421633cd26e3fae20ac09901a93096930282a473`. That hash was **absent from
`core.pre_account_builds` entirely** (I read the whole table grouped by `created_ip_hash`, not a filtered
count) — 0 live, cap (3, `config/partna.php:1155`) clear. It is the same origin that ran batch B on
08-17, so those three builds have since been torn down. Cap value and expiry re-read from config:
`max_unclaimed_per_ip = 3`, `expiry_days = 30`.

---

## 1. Summary

| # | batch | `source_ref` | handle | subdomain | display_name | sector / source | inputs | seeded | custom | skipped | product |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | A | `simondoylehair` | `simondoylehair` | `simondoylehair` | SIMON DOYLE \| Barber & Educator | `hair-salon` / `instagram` | 3 | 3 | 0 | 0 | 0 |
| 2 | A | `jess.hair.stylist` | `jesshairstylist` | `jesshairstylist` | Prahran Hairdresser | `hair-salon` / `instagram` | 3 | 2 | 0 | 1 | 0 |
| 3 | A | `crucibletattooco` | `crucibletattooco` | `crucibletattooco` | Crucible Tattoo Co. | `tattoo-artist` / `instagram` | 11 | 1 | 8 | 1 | 1 |
| 4 | B | `kimcosmik` | `kimcosmik` | `kimcosmik` | kimcosmik | `musician` / `instagram` | 15 | 3 | 11 | 1 | 0 |
| 5 | B | `themilleraffect` | `themilleraffect` | `themilleraffect` | Amanda Miller Pollard | `content-creator` / `instagram` | 5 | 0 | 5 | 0 | 0 |
| 6 | B | `supernormal_180` | `supernormal-180` | `supernormal-180` | Supernormal | `restaurant` / `instagram` | **0** | 0 | **0** | 0 | 0 |

"seeded" excludes the `instagram` source connection every account gets.

| # | build_id | user_id | state | wall clock |
|---|---|---|---|---|
| 1 | `01a01257-c413-707b-b160-62842a86b954` | `01a01257-c395-70f2-9af3-5b717598d460` | ready | **44s** |
| 2 | `01a01258-8e2c-718f-9074-bd4af98e6b7d` | `01a01258-8deb-728b-a7f4-29511b831c0f` | ready | **54s** |
| 3 | `01a01259-77e1-7392-864f-0194aa0b409b` | `01a01259-779d-726d-b6a8-9943c380e4cd` | ready | **37s** |
| 4 | `01a01262-2c22-739f-aa49-6dcc0fff8905` | `01a01262-2bda-7377-9d17-c0a11c23cc2d` | ready | **22s** |
| 5 | `01a01262-91e9-72c9-ad48-928e6d251bf2` | `01a01262-9180-713c-93ef-5090ce9d2d7c` | ready | **28s** |
| 6 | `01a01263-0a97-71c2-b63b-ebe32f8e66de` | `01a01263-0a29-7041-83f2-9e99c3cb7762` | ready | **54s** |

All six POSTs returned **202**. `failure_code` NULL and `thin_scrape_at` NULL on all six (no thin-scrape
retry; every scrape returned 12 posts first time). Six Apify scrapes billed, one per handle.

### What changed since the 2026-08-11 / 2026-08-17 runs

| prior finding | 2026-08-18 status |
|---|---|
| **F5** sector resolved on some accounts only | **CLOSED.** 6 of 6 resolved, `sector_source = 'instagram'` on **all six** (08-11 managed 4 of 6) |
| **F7** auto-routed connections are terminal — no fetch, no services | **CHANGED SHAPE, still open.** The Fresha source is now provisioned and *does* run eagerly, but returns 0 records with `no_selection`. See §4 / **N-A** |
| **N2** `linkin.bio` unrolls to zero links | **UNCHANGED.** Reproduced byte-for-byte on `supernormal_180` |
| **N3** generic shop probe publishes junk products | **PARTIALLY MITIGATED.** The junk product item is still created, but no longer reaches the public wire. See **N-C** |
| media never reaches the pool | **CLOSED.** 12 media items per account, 5 published per account |
| §2 media checks target `site.site_media` | **OBSOLETE.** That table is 0 on all six; media moved to `content.media_assets` / `content.item_media` |
| §3 "`routing.link_observations` empty is expected" | **PREMISE NOW STALE** but outcome unchanged — still 0. See **N-E** |

---

## 2. Per-account link ledger

### How the input list was established

`InstagramSourceGenerator.php:91` strips `bioLinks` / `syncFindings` / `unmatched` (PRIV-2) — verified
absent from all six payloads — so the bio-level input list is not recoverable from stored state. What is
recorded is `payload.website`, which is the bio link. I fetched each bio page independently and extracted
its off-host `<a href>` anchors, matching `WebsiteLinkHarvester::allOutboundLinks()`.

| # | `payload.website` | bio host | anchors found |
|---|---|---|---|
| 1 | `https://linktr.ee/simondoylehair` | linktr.ee | 3 |
| 2 | `https://linktr.ee/jess.hairstylist` | linktr.ee | 3 |
| 3 | `http://linktr.ee/crucibletattooco/` | linktr.ee | 11 |
| 4 | `https://linktr.ee/kimcosmik?utm_source=…` | linktr.ee | 15 |
| 5 | `https://linktr.ee/themilleraffect` | linktr.ee | 4 (see caveat) |
| 6 | `https://linkin.bio/supernormal_180` | linkin.bio | **0** |

> **Caveat, stated rather than glossed.** My fetches ran 4–10 minutes after each scrape. On **#5** the
> pipeline routed **5** links; my capture returned **4** — `https://www.att.com/internet/fiber/?source=…`
> is present in the seeded rows and provably **absent** from my saved HTML (`grep -c 'att\.com'` = 0).
> Linktree serves varying content per request. The ledger below uses the **routed** set as truth for #5
> and flags the one link I could not independently witness.

> **Linktree's own affiliate inventory is correctly ignored.** Each page's `__NEXT_DATA__` blob carries
> 35–43 further URLs (`armra.com`, `babbel.sjv.io`, `thanks.is`, `click.linksynergy.com`, `pxf.io`,
> `sjv.io` …) — **identical across all six pages**, so they are Linktree's ads, not the user's links.
> `WebsiteLinkHarvester` parses `<a href>` only and routed none of them. This is correct-by-design and
> would be a serious defect if it changed.

### Ledger 1 — `simondoylehair` (3 in, 3 accounted)

| input | outcome | proof |
|---|---|---|
| `eventbrite.com.au/e/hobart-mens-hair-workshop-…` | **seeded** `eventbrite.organiser` (events) + **event item** | connection row; `content.items` kind `event`, headline `HOBART MENS HAIR WORKSHOP / @SIMONDOYLEHAIR @DEVEL…` |
| `fresha.com/book-now/anseo-studio-v0v92jna/all-offer` | **seeded** `fresha.book` (booking) | connection payload `url` |
| `youtube.com/@dvlpmnttv?si=…` | **seeded** `youtube.channel` (content) | connection payload `url` |

**3 = 3 seeded + 0 custom + 0 skipped. Balances.**

### Ledger 2 — `jess.hair.stylist` (3 in, 3 accounted)

| input | outcome | proof |
|---|---|---|
| `fresha.com/book-now/jess-hairstylist-v8ct52bl/all-offer` | **seeded** `fresha.book` | connection row |
| `tiktok.com/@jess.hairstylist?_t=…` | **seeded** `tiktok.profile` | connection row |
| `instagram.com/jess.hair.stylist?igsh=…` | **skipped** — Instagram already seeded as the source | only one `instagram.profile` row exists |

**3 = 2 seeded + 1 skipped. Balances.**

### Ledger 3 — `crucibletattooco` (11 in, 11 accounted)

| input | outcome | proof (`content.f_link.url`) |
|---|---|---|
| `crucibletattooco.com.au/` | custom link | item `d6877a34…` |
| `crucibletattooco.com.au/appointment.html` | custom link | item `581622a9…` |
| `crucibletattooco.com.au/artists.html` | custom link | item `84b265ed…` |
| `crucibletattooco.com.au/aftercare.html` | custom link | item `3bc20f4b…` |
| `crucibletattooco.com.au/accessibility.html` | custom link | item `e3db33ee…` |
| `crucibletattooco.com.au/feedback.html` | custom link | item `1469c683…` |
| `au.pinterest.com/crucibletattooco_/` | custom link | item `2187aa23…` — **correct by decision** (Pinterest retired 2026-07-28) |
| `bsky.app/profile/crucibletattooco.bsky.social` | custom link | item `73140917…` — no catalog definition, as expected |
| `paytherent.net.au/` | **product item** `Private: Demo` | `content.items` kind `product`, `f_link.url = https://paytherent.net.au/`, `partna.manual_product` connection |
| `tiktok.com/@crucibletattooco` | **seeded** `tiktok.profile` | connection row |
| `instagram.com/crucibletattooco/` | **skipped** | one `instagram.profile` row |

**11 = 8 custom + 1 product + 1 seeded + 1 skipped. Balances.**

### Ledger 4 — `kimcosmik` (15 in, 15 accounted) — the dedupe test

| input | outcome |
|---|---|
| `youtube.com/channel/UCCY6-AIHHvrmZW5J8IAjk-A` | **seeded** `youtube.channel` |
| `youtube.com/@cybersoul9038` | **custom link** — 2nd YouTube, correctly demoted |
| `facebook.com/kimcosmik/` | **seeded** `facebook.profile` |
| `facebook.com/groups/3004349706304446/` | **custom link** — 2nd Facebook |
| `facebook.com/hybridrave` | **custom link** — 3rd Facebook |
| `discord.com/invite/q3FvffbQ` | **seeded** `discord.server` |
| `obskurmusic.bandcamp.com/track/carissa-illy-…` | custom link |
| `kimcosmik.bandcamp.com/album/star-glider` | custom link |
| `kimcosmik.bandcamp.com/` | custom link |
| `cybersoul.bandcamp.com/` | custom link |
| `mixcloud.com/KimCosmik/` | custom link |
| `ra.co/dj/kimcosmik` | custom link |
| `discogs.com/search?q=kim+cosmik&type=all` | custom link — no catalog definition, expected |
| `juno.co.uk/products/kim-cosmik-…-vinyl/952291-01/` | custom link — no catalog definition, expected |
| `instagram.com/kimcosmik/` | **skipped** |

**15 = 3 seeded + 11 custom + 1 skipped. Balances.**

**3.7 dedupe: PASS.** YouTube 2→1 connection, Facebook 3→1 connection. Exactly one row per platform,
every later duplicate demoted to a custom link — nothing dropped, nothing duplicated. The three Facebook
shapes (profile, `/groups/<id>/`, page) all resolved to the same platform, so the documented
reserved-path blind spot did **not** produce a spurious second connection.

**But see finding N-B**: Bandcamp ×4, Mixcloud and Resident Advisor are all *defined* in the catalog and
seeded nothing.

*Page drift vs the prompt:* the prompt describes ~19 links including two SoundCloud and three Juno
product pages. The live page now carries 15 anchors, **no SoundCloud**, and **one** Juno link. The
"does a product page become an item?" test is therefore weaker than intended — the single Juno product
URL became a plain custom link, not a product item.

### Ledger 5 — `themilleraffect` (5 routed, 5 accounted)

| input | outcome | independently witnessed? |
|---|---|---|
| `shopltk.com/explore/themilleraffect?utm_…` | custom link | yes |
| `shopltk.com/explore/themilleraffect/collections/11ecafbde…` | custom link | yes |
| `poshmark.com/closet/themaffect?utm_…` | custom link | yes |
| `canva.link/hxwh4ybxzn38wkg?utm_…` | custom link (headline `Client Challenge` — a bot-wall title) | yes |
| `att.com/internet/fiber/?source=EMC980676826&wtExtndSource=_inflnc_+themilleraffect` | custom link | **no** — absent from my capture |

**5 = 5 custom. Balances.** Zero platform connections beyond Instagram.

**3.9 probe budget: NOT EXERCISED, contrary to the prompt's expectation.** The prompt expects this
account to exhaust `RouteContext::DEFAULT_MAX_PROBES = 6`. It presented **5** routable links, so the
budget was never reached and nothing fell past the cap. No Amazon, no Pinterest, no TikTok, no Facebook
on the page any more. **The silent-truncation behaviour remains unverified by this run** — recorded as
untested rather than passed.

### Ledger 6 — `supernormal_180` (0 in, 0 accounted)

`payload.website = https://linkin.bio/supernormal_180`. Fetched independently: **HTTP 200, 6,441 bytes,
0 `<a href>` anchors** — byte-for-byte identical to the 2026-08-11 measurement. Result: 0 custom links,
0 platform connections beyond Instagram, `pageOrder ['home']`, **1 ranked action**.

**Consequence: §3.8 (gate denial) could not be measured at all.** The test requires OpenTable /
SevenRooms / UberEats / Menulog links to be *extracted first* and then denied by `gateAllows()`. Nothing
was extracted, so nothing reached the gate. The gate-denial path is **untested by this run**, and was
untested on 08-11 for the same reason. This is not evidence the gate works.

---

## 3. Section-by-section

### §1 — Identity and handle

| # | check | verdict | evidence |
|---|---|---|---|
| 1.1 | IG username → suggested handle | **PASS** ×6 | `jess.hair.stylist`→`jesshairstylist`, `supernormal_180`→`supernormal-180`, other four verbatim |
| 1.2 | `handle` == `subdomain` | **PASS** ×6 | identical on all six rows — **SIGNUP-1 stays closed**, including the two-period handle and the underscore handle |
| 1.3 | `display_name` is a real name | **PASS ×5, N/A ×1** | 5 real names; `kimcosmik` has `fullName = ""` upstream so the handle is the only available fallback — **SIGNUP-2 stays closed** |
| 1.4 | `first_name` sensible | **PARTIAL** | `SIMON`, `Amanda` good; `Prahran`, `Crucible`, `Supernormal`, `kimcosmik` are first-token-of-display-name, not people |
| 1.5 | IG category → sector | **PASS ×6** | all six resolved with `sector_source = 'instagram'` — best result yet (08-11: 4 of 6) |
| 1.6 | Contact fields folded | **VACUOUS PASS** | `site.workplaces` = 0 on all six, but `businessEmail` / `businessPhoneNumber` are **NULL in every payload**, so there was nothing to fold. The fold path remains unexercised |

### §2 — The scrape

| # | check | verdict | evidence |
|---|---|---|---|
| 2.1 | Profile fields captured | **PASS** | `fullName` 5/6 non-empty, `businessCategory` 5/6 (`crucibletattooco` null), `followersCount` + `postsCount` 6/6 (e.g. 336,194 / 5,169 for #5) |
| 2.2 | `biography` present | **CONFIRMED ABSENT** ×6 | `payload ? 'biography'` false on all six; 13 payload keys |
| 2.3–2.5 | media mirrored to R2, webp variants | **OBSOLETE AS WRITTEN** | `site.site_media` = **0** and `site.media_variants` = **0** on all six. Equivalent on the live path below |
| 2.6 | Gallery ≤ 6 | **MOOT** | gallery pool = 0 rows; `core.enforce_site_gallery_max6` is not on this path any more |

**The prompt's §2 open question is now answered.** `payload.images` length is **1** on all six — it holds
the profile picture only. Real post media travels the ingest lane: `ingest.runs` shows
`records_seen: 12, records_changed: 12, streams {media: ok}` per account, landing 12 `media` items in
`content.items` each. Media capture is not under-filling; the payload was simply never the carrier.

Media on the live path (measured 01:05:28Z):

| # | `content.media_assets` | mirrored | unmirrored |
|---|---|---|---|
| 1 | 53 | 52 | 1 |
| 2 | 31 | **3** | **28** |
| 3 | 61 | 51 | 10 |
| 4 | 92 | 84 | 8 |
| 5 | 48 | 39 | 9 |
| 6 | 42 | **42** | 0 |

### §3 — Link routing

| # | check | verdict |
|---|---|---|
| 3.1 | classified platform link → connection row | **PASS with exception** — true for YouTube, Facebook, Discord, TikTok, Fresha, Eventbrite; **false for Bandcamp / Mixcloud / Resident Advisor** (N-B) |
| 3.2 | unclassified link → custom link, nothing vanished | **PASS** — every unclassified input is present as a `content.items` row |
| 3.3 | shop/unclassified → `CommerceProbeJob` in budget | **PASS (partial evidence)** — `paytherent.net.au` was probed and resolved; log window too small to count dispatches (N-F) |
| 3.4 | probe on a **product page** → item | **PASS** — `paytherent.net.au` → `content.items` kind `product` + `content.f_link` row. Note the URL lives in **`content.f_link`, not `content.item_links`** (0 rows for all six) |
| 3.5 | probe on a **storefront** → shop connection | **UNVERIFIED** — no storefront appeared in this wave |
| 3.6 | **inputs == seeded + custom + skipped + product** | **PASS on all six.** 3=3, 3=3, 11=11, 15=15, 5=5, 0=0 |
| 3.7 | duplicate-platform dedupe | **PASS** — see ledger 4 |
| 3.8 | gate-denied links become custom links | **UNTESTED** — no gate-eligible link was ever extracted (ledger 6) |
| 3.9 | probe-budget overflow counted | **UNTESTED** — budget never reached (ledger 5) |

### §4 — Does the loop continue? (cascade)

| # | check | verdict | evidence |
|---|---|---|---|
| 4.1 | seeded connection dispatches a fetch | **PASS — this is new** | Fresha ingest sources exist and ran: runs `06102047…` (00:49:52Z) and `1d92ef10…` (00:50:59Z), `trigger: schedule`, `outcome: ok`, `streams {services: ok}` |
| 4.2 | Fresha connection fetched its service menu | **FAIL** | both runs `records_seen: 0`, note `{"code":"no_selection","message":"No Fresha team member or storewide menu has been chosen for this connection"}` |
| 4.3 | services projected | **FAIL** | 0 service items; `profile.services` = 0 on all six |
| 4.4 | categories projected | **FAIL** | 0 |
| 4.5 | URLs inside a connection payload get routed | **UNVERIFIED** | no evidence either way in the captured window |

`ingest.sources` for the two Fresha connections: `source_key = fresha`, `cost_units = 1`,
`auto_sync = true`, **`selection_ref = NULL`**, `health = ok`, `next_attempt_at` 2026-08-23.

### §5 — Auto-signup rules

| # | check | verdict | evidence |
|---|---|---|---|
| 5.1 | auto-media rule on at create | **PASS** | `display_settings` NULL on all 16 connection rows; `AutoSyncSetting` docblock: "absent means ON, only an explicit false is off" |
| 5.2 | `is_published` false | **PASS** ×6 | `site.sites.is_published = false` |
| 5.3 | site publicly reachable anyway | **PASS** ×6 | all six `https://<handle>.partna.au/` → **200** |
| 5.4 | KV entry written | **PASS (inferred)** | the 200s in 5.3 are served through the Worker, which resolves only via `SUBDOMAIN_KV`; a missing KV entry would 404 |
| 5.5 | profile endpoint returns built content | **PASS** ×6 | `data.architectureId = 'staple'`, `data.designKit` populated. **Note both live at `data.*`, not `data.profile.*`** |
| 5.6 | `status = 'unclaimed'` | **PASS** ×6 | `core.users.status` |

Public wire per account:

| # | pools | pageOrder | rankedActions |
|---|---|---|---|
| 1 | media 5, events 1 | `[home, watch, events]` | 3 |
| 2 | media 5 | `[home]` | 3 |
| 3 | media 5, custom_links 8 | `[home]` | 10 |
| 4 | media 5, custom_links 11 | `[home, watch]` | 14 |
| 5 | media 5, custom_links 5 | `[home]` | 6 |
| 6 | media 5 | `[home]` | **1** |

### §6 — Errors and noise

**Coverage is structurally limited and I will not pretend otherwise.** `cloud env:logs partna
development` returns a hard maximum of **100 records** regardless of `--minutes 30` or `--tail 400`
(verified: both returned exactly 100, spanning 01:03:10–01:04:24Z). `MirrorMediaAssetJob` fired ~80 times
in that window and pushed the earlier routing lines out. So the `LinkInBioScanJob` / `CommerceProbeJob`
lines from 00:49–01:02 are **not recoverable** after the fact.

What the sampled windows did show:

- **No exceptions, no failed jobs, no 5xx.**
- `media_mirror.failed` ×4 — `reason: fetch_failed`, hosts `scontent-man2-1.cdninstagram.com`,
  `instagram.fgdl1-3.fna.fbcdn.net`, `instagram.fgdl1-4.fna.fbcdn.net`.
- `slow_public_profile` ×1 — `{"handle":"kimcosmik","outcome":"200","duration_ms":1128}`.
- **Not ours:** `ingest.runs` row `3337e504…` (source `79fa8c45…`, `apple_music.artist`, user
  `broken-oven`) started 00:52:21Z with `outcome` NULL and `finished_at` NULL, `in_flight_since`
  00:52:20Z. Different user, unrelated to this wave — recorded only because it sits inside the window.

---

## 4. Findings

**N-A · The Fresha cascade now fires and then dead-ends on `selection_ref`.** *(changes F7)*

*Plain English.* A hairdresser signs up, we correctly detect their Fresha booking page and connect it —
and their services still do not appear. The system now genuinely tries to fetch them, gets told "nobody
has picked which team member's menu to use", and gives up cleanly. It will keep doing this on schedule
forever, reporting success each time.

*Technical.* Both auto-routed `fresha.book` connections provisioned an `ingest.sources` row
(`auto_sync = true`, `cost_units = 1`) and ran eagerly within milliseconds of the connection being
created — a real change from 08-11, where no source existed at all. Both runs returned `outcome: ok`,
`records_seen: 0`, note `no_selection`. `selection_ref` is NULL on both and the auto-route path has no
mechanism to choose one; that choice is a dashboard action. `next_attempt_at` is 2026-08-23, so the run
repeats with the same result. Net effect for a pre-account site is identical to the old F7 (zero
services), but the failure is now *observable* and one field away from working. Note `outcome: ok` — a
run that produced nothing is not distinguishable from a healthy run by outcome alone.

**N-B · Defined music platforms classify but seed nothing.**

*Plain English.* A DJ's Bandcamp, Mixcloud and Resident Advisor links — all platforms we support — end up
as plain link cards instead of connected accounts, while their YouTube, Facebook and Discord connect
properly from the very same page.

*Technical.* On `kimcosmik`, 4 Bandcamp + 1 Mixcloud + 1 Resident Advisor links produced **zero**
`site.platform_connections` rows; all six became custom links. `app/Catalog/Definitions/Bandcamp.php`
defines `bandcamp.artist` with `RoutingClass::Content`, `->connect('connect.bandcamp.url.v1')`,
`->fetch('fetch.bandcamp.scrape.v1')` and a detector `Detector::url('bandcamp.com')->subdomain(
'#^(?<handle>[a-z0-9][a-z0-9-]*)$#i')` — which matches `kimcosmik.bandcamp.com` exactly.
`ResidentAdvisor.php` and a Mixcloud definition are likewise present. YouTube, also `RoutingClass::Content`,
seeded from the same page in the same run, so the class itself is not the blocker. **Cause unverified** —
I did not trace the legacy `LinkRouter` path far enough to say whether this is probe-budget ordering, a
gate, or a detector miss, and the prompt forbids fixing. The observation is solid; the explanation is not.

**N-C · The junk product is still created, but no longer published.** *(partially mitigates N3)*

*Plain English.* We still decide that `paytherent.net.au` is a shop and manufacture a product called
"Private: Demo" — an unfinished draft page — but it no longer shows up on the person's public site.

*Technical.* `crucibletattooco` produced `content.items` kind `product`, headline `Private: Demo`,
`f_link.url = https://paytherent.net.au/`, plus a `partna.manual_product` connection with
`routing_class = shop`. The public wire shows **no `shop` pool and `pageOrder ['home']`** — the item is
withheld. `eligible_cache` is `[]` on it. So the public-facing half of N3 is fixed; the "we invent
products from arbitrary websites" half is not, and the row is still there to be surfaced by any future
change to eligibility.

**N-D · `utm_*` redaction rewrites the link rather than stripping it.**

*Plain English.* Tracking parameters in a creator's shopping links get replaced with the literal text
`[redacted]`, so the published link carries `utm_medium=[redacted]`. Other tracking parameters are left
completely intact, so this does not actually stop tracking.

*Technical.* On the **public wire** (not a tooling artefact — confirmed via
`GET /api/public/profiles/themilleraffect`): `https://www.shopltk.com/explore/themilleraffect?utm_medium=[redacted]&utm_source=[redacted]&utm_campaign=[redacted]`.
The same account's `https://www.att.com/internet/fiber/?source=EMC980676826&wtExtndSource=_inflnc_+themilleraffect`
passes through untouched. Redaction is keyed on parameter name (`utm_*`) and substitutes rather than
removes, so the outbound URL is altered but the tracking surface is not meaningfully reduced. Affects
4 of 5 links on account 5.

**N-E · X3 widened the observations CHECK; observations are still zero.**

*Plain English.* A fix went in so that shop-probe results could be recorded. Nothing is being recorded.

*Technical.* `supabase/migrations/20260819001000_link_observations_allow_commerce_probe.sql` states the
problem it fixes — `commerce_probe` was admitted on `routing.source_intents` but never on
`routing.link_observations.source`, so "every `CommerceProbeJob` observation write failed the CHECK
(logged as `routing.observation.write_failed`, no crash — the observation was simply lost)" — and states
"**Dev already applied the original and holds an equivalent VALID constraint**". A probe demonstrably ran
this wave (it produced the `paytherent.net.au` product). Yet `routing.link_observations` = **0** for all
six users. Either the probe path still does not write, or the write still fails; the 100-record log cap
prevented me from catching a `routing.observation.write_failed` line either way. **The prompt's standing
advice that an empty `link_observations` is expected is no longer safe to rely on** — for
`commerce_probe` it is now supposed to be non-empty.

**N-F · The log cap makes the async half of this prompt unverifiable after the fact.**

`cloud env:logs` returns at most 100 records irrespective of `--minutes` / `--tail`. During a build wave
`MirrorMediaAssetJob` alone emits ~80 lines per minute, so the routing and probe evidence the prompt asks
for in §3c, §3.3 and §6 ages out within roughly two minutes. Any future run of this prompt must tail logs
**live during** the builds, not query afterwards.

**N-G · Instagram CDN mirror failures leave a persistent unmirrored tail.**

8–10 assets per account never mirrored (kimcosmik 8/92, themilleraffect 9/48, crucibletattooco 10/61),
against `media_mirror.failed` / `fetch_failed` on `*.cdninstagram.com` and `*.fna.fbcdn.net`. Accounts 1
and 6 finished at 52/53 and 42/42, so this is intermittent CDN rejection, not a systematic break.
**Account 2 is the outlier: 3 of 31 mirrored, unchanged across two readings ~2 minutes apart** while its
siblings progressed. I purged that account before it could be re-measured, so whether it would have
caught up is **unresolved** — flagged rather than concluded.

---

## 5. Explicitly correct-by-design — do not re-raise

- **`profile.services` = 0 alongside a Fresha connection** is not itself the bug; the bug is N-A.
- **Reservations / online-ordering denied to a `partna` account** — correct. (Untested this run; see §3.8.)
- **First-link-per-platform wins** — the 2nd YouTube and 2nd/3rd Facebook links on `kimcosmik` becoming
  custom links is `RouteContext::$seenPlatforms` working.
- **Pinterest as a custom link** — deliberately retired 2026-07-28, owner decision.
- **Bluesky, Discogs, Juno as custom links** — zero catalog definitions, confirmed by grep.
- **`bioLinks` / `syncFindings` / `unmatched` absent from payloads** — PRIV-2 strip, verified on all six.
- **`is_published = false` while the site serves 200** — SIGNUP-3, pre-account sites are public pre-claim.
- **Linktree's `__NEXT_DATA__` affiliate URLs not routed** — the harvester reads `<a href>` only.
- **`content.item_links` = 0** — link URLs live in `content.f_link`; `item_links` is a different concept.
- **`site.site_media` / `media_variants` = 0** — retired for this lane by the content-pool convergence.
- **Media pool shows 5 of 12 stored items** — W2 "N newest per source" opt-in cap.
- **`designKit` / `architectureId` at `data.*`, not `data.profile.*`** — my first probe read the wrong
  level and briefly looked like a failure; it is not.

---

## 6. State left behind

**Batch B is live and unclaimed — I did not delete it.** Josh's mid-run authorisation covered freeing
slots so batch B could run; leaving the finished builds up means the three sites can be inspected.

| handle | site | build |
|---|---|---|
| `kimcosmik` | https://kimcosmik.partna.au (200) | `01a01262-2c22-739f-aa49-6dcc0fff8905` |
| `themilleraffect` | https://themilleraffect.partna.au (200) | `01a01262-91e9-72c9-ad48-928e6d251bf2` |
| `supernormal-180` | https://supernormal-180.partna.au (200) | `01a01263-0a97-71c2-b63b-ebe32f8e66de` |

**The per-IP cap for origin `8bc2a9c3…` is now 3 of 3 — full.** A further build from this machine will
429 until these are purged.

## 7. Audit — what I deleted, and when

Batch A ×3 purged at **00:59:55Z**, after every figure in §1–§5 above had been captured, via
`AccountDeletionService::purge()` (`cloud tinker development --code=…`) — the same full-teardown path the
08-11 run used: Supabase auth user, object-storage artifacts, cache invalidation, PII erasure,
audit-link pre-null, then `forceDelete` with `UserObserver::deleted` retiring the handle in KV.

All three returned `true`. **Verified independently** rather than trusting the command's own report
(`command:run` can pair `success` with a non-zero exit code):

```
live_on_my_ip 0 | users_left 0 | builds_left 0 | items_left 0 | ingest_sources_left 0
```

Deleted: `simondoylehair` (`01a01257-c395-…`), `jesshairstylist` (`01a01258-8deb-…`),
`crucibletattooco` (`01a01259-779d-…`).

**Six Apify scrapes billed, one per handle. No build was retried.**
