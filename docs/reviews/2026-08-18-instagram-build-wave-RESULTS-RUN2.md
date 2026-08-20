# Instagram (`partna`) build wave — verification report, 2026-08-18 (run 2)

Fourth full run of `docs/reviews/2026-08-10-instagram-build-wave-PROMPT.md`, **all six handles**, dev only
(`https://dev-api.partna.au`, Supabase `glncumufgaqcmqhzwrxm`).

Deployed commit **`0cbe8330e`** (deploy `depl-a2877957`, finished 2026-08-18T04:32:22Z). The 11:08 report
(`2026-08-18-instagram-build-wave-RESULTS.md`) tested `4f1280e8e0`; **21 commits** have landed since,
including exactly the ones that answer its findings — `bd593dfdf` (N2 floor, N-C, N-E), `3fa749257`
(harvest origins auto-apply), `a440fd589` + `e0820b8e2` (the P8 `LinkInBioImporter` migration),
`134f55853` (Mixcloud/Tidal/Gumroad connect). That is why this re-run was worth six scrapes.

Run windows: **batch A 06:11:46–06:13:32Z**, **batch B 06:20:01–06:22:01Z**.
Six builds attempted, **six reached `ready`**, none retried, none failed, no `thin_scrape_at`.
No code, config, migration or data was changed during the run.

**Deviation from the prompt, on Josh's explicit answer before the run started.** Rule 4 forbids deleting
and requires asking before freeing cap slots. I asked; Josh chose "Full six (delete the 3)". §7 audits
exactly what was deleted and when. Nothing else was varied.

**Preconditions, re-measured at 06:10Z.** Egress IP `150.228.151.63` →
`sha256 = 7f00cf75c82864091745c9df22dda4cef09afaa3ab6df586255267d92de21430`. That hash was **absent from
`core.pre_account_builds` entirely** — I read the whole table grouped by `created_ip_hash`, not a filtered
count. Cap (`max_unclaimed_per_ip = 3`) clear. The three 11:08 builds were still live under the *previous*
origin `8bc2a9c33…`, holding the handles `kimcosmik` / `themilleraffect` / `supernormal-180`; they were
purged first so the re-run could reuse the real handles rather than land as `kimcosmik2`.

**Log capture method changed, and it matters.** The 11:08 run's **N-F** recorded that `cloud env:logs`
returns at most 100 records, so the async evidence ages out in ~2 minutes. `--live` is not a stream when
stdout is not a TTY — it prints a backlog array and exits. I instead polled `--minutes 2 --json` every 15s
into a file and de-duplicated on `(loggedAt, message)`, capturing **951 unique records** across the whole
wave. Every log citation below comes from that capture.

---

## 1. Summary

| # | batch | `source_ref` | handle | subdomain | display_name | sector / source | inputs | seeded | custom | vanished |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | A | `simondoylehair` | `simondoylehair` | `simondoylehair` | SIMON DOYLE \| Barber & Educator | `hair-salon` / `instagram` | 3 | 2 | 1 | 0 |
| 2 | A | `jess.hair.stylist` | `jesshairstylist` | `jesshairstylist` | Prahran Hairdresser | `hair-salon` / `instagram` | 3 | 2 | 1 | 0 |
| 3 | A | `crucibletattooco` | `crucibletattooco` | `crucibletattooco` | Crucible Tattoo Co. | `tattoo-artist` / `instagram` | 11 | 2 | 8 | 0 |
| 4 | B | `kimcosmik` | `kimcosmik` | `kimcosmik` | kimcosmik | `musician` / `instagram` | 15 | **9** | 6 | 0 |
| 5 | B | `themilleraffect` | `themilleraffect` | `themilleraffect` | Amanda Miller Pollard | `content-creator` / `instagram` | 5 | 0 | 4 | **1** |
| 6 | B | `supernormal_180` | `supernormal-180` | `supernormal-180` | Supernormal | `restaurant` / `instagram` | 1 | 0 | **1** | 0 |

"seeded" excludes the `instagram` source connection every account gets. #3 also produced one **withheld**
`product` item (see **R7**). #6's single custom link is the **N2 floor firing** — the headline result.

| # | build_id | user_id | state | wall clock |
|---|---|---|---|---|
| 1 | `01a0137f-55c4-7029-80be-d262aca02fe3` | `01a0137f-5531-7203-8335-8a24c03b2fb0` | ready | **33s** |
| 2 | `01a0137f-e414-7249-80ef-23c6c0c8b7c8` | `01a0137f-e3db-71a7-8fd0-35f7027608a7` | ready | **44s** |
| 3 | `01a01380-9e43-7338-b9dd-a1cd09706d98` | `01a01380-9e1e-72ca-a964-19be7d0f8d3b` | ready | **22s** |
| 4 | `01a01386-e286-718a-bdb7-af5b5b478a86` | `01a01386-e21e-720b-9b80-cad6e25e5843` | ready | **22s** |
| 5 | `01a01387-45f2-7374-bbd9-d4f42e8c19db` | `01a01387-45c8-724b-b808-98ef2919458b` | ready | **22s** |
| 6 | `01a01387-a6f3-734e-b43b-69b33af0e662` | `01a01387-a6cb-7279-81db-69cf204b1bf8` | ready | **70s** |

All six POSTs returned **202**, all `failure_code` NULL. Six Apify scrapes billed, one per handle
(`ingest.runs.cost_claimed = 50` on the first Instagram run of each account; the duplicate second run is
`cost_claimed = 0` — see **R4**).

### What changed since the 11:08 run

| prior finding | status now |
|---|---|
| **N2** `linkin.bio` unrolls to zero links, site left empty | **RESOLVED at the floor.** `bio_url_seeded: true`; the bio URL is on the public wire |
| **N-B** defined music platforms classify but seed nothing | **CLOSED.** `kimcosmik` seeded 9 connections incl. Mixcloud, Bandcamp ×2, YouTube ×2, Discord |
| **N-E** observations still zero | **HALF CLOSED.** Observations now written (20+ rows this run); the *matching* probe still writes none — **R6** |
| **N-A** Fresha cascade dead-ends on `selection_ref` | **SUPERSEDED.** There is no Fresha connection at all now — **R1** |
| **N-C** junk product created but withheld | **UNCHANGED for creation.** `Private: Demo` still created, still withheld — **R7** |
| **N-D** `utm_*` rewritten to `[redacted]` | **UNCHANGED.** Still on the public wire |
| **N-F** log cap makes async half unverifiable | **ADDRESSED by method**, not by code — 951 records captured |
| **N-G** unmirrored Instagram CDN tail | **PERSISTS**, and is mostly silent — **R8** |
| **F5** sector resolution | **STAYS CLOSED.** 6 of 6, `sector_source = 'instagram'` |
| **§4 cascade** — never once completed in any prior run | **NOW COMPLETES.** A harvested Bandcamp connection fetched 3 releases and populated the public `listen` pool — **R5** |
| *(new)* Fresha booking | **REGRESSED.** `book-now` share URLs no longer classify — **R1** |
| *(new)* link loss | **NEW.** `canva.link` rejected and never carded — §3.6 breaks — **R2** |
| *(new)* erasure | **NEW.** Purge orphans `routing.link_observations` — **R3** |

---

## 2. Per-account link ledger

### How the input list was established

`InstagramSourceGenerator.php:91` strips `bioLinks` / `syncFindings` / `unmatched` (PRIV-2) — verified
absent from all six payloads — so the input list is not recoverable from stored state. Three independent
sources were combined instead:

1. `payload.website` (the Instagram bio link) — stored, and read per account.
2. `platforms.instagram.bio_links_routed` log line — gives `links_seen` at the **Instagram bio** level.
3. The bio page itself, **fetched independently by me**, counting unique external `<a href>` anchors.
4. `routing.link_observations` — new this run, and the first time the router's own decisions are
   readable from the database rather than inferred.

Where (3) and (4) agree exactly, the ledger is closed. They agreed on `kimcosmik` (15 = 15).

### Ledger 1 — `simondoylehair` (3 in, 3 accounted)

Bio: `https://linktr.ee/simondoylehair`. Scan: `observations: 3, connected: 2, noted: 1, probed: 0,
skipped_chrome: 110`.

| input | outcome | proof |
|---|---|---|
| `youtube.com/@dvlpmnttv?si=…` | **seeded** `youtube.channel` (`dvlpmnttv`) | observation `verdict=place, confidence=75` + connection row |
| `eventbrite.com.au/e/hobart-mens-hair-workshop-…` | **seeded** `eventbrite.organiser` + `event` item | connection `event-ba7bc4f70f505571`; `content.items` kind `event` |
| `fresha.com/book-now/anseo-studio-v0v92jna/all-offer` | **custom link** | observation `verdict=note, block_reason=no-rule-matched`; `content.items` kind `link` |

**3 = 2 seeded + 1 custom. Balances.** Note the Eventbrite row: its observation is *also*
`note / no-rule-matched`, yet a connection exists — it was seeded by the importer's **inline EventsSeeder
arm** (`e0820b8e2`), which runs outside the detector verdict. **The observation verdict is therefore not a
complete record of the outcome**; do not read `note` as "was not connected".

### Ledger 2 — `jess.hair.stylist` (3 in, 3 accounted)

Bio: `https://linktr.ee/jess.hairstylist`. Scan: `observations: 3, connected: 2, noted: 1`.

| input | outcome | proof |
|---|---|---|
| `instagram.com/jess.hair.stylist?igsh=…` | **seeded** a *second* `instagram.profile` | connection `resource_id=jess.hair.stylist`, `payload.source=link_in_bio` — **R4** |
| `tiktok.com/@jess.hairstylist?_t=…` | **seeded** `tiktok.profile` | observation `place`, conf 75 |
| `fresha.com/book-now/jess-hairstylist-v8ct52bl/all-offer` | **custom link** | observation `note / no-rule-matched` — **R1** |

**3 = 2 seeded + 1 custom. Balances.**

### Ledger 3 — `crucibletattooco` (11 in, 11 accounted)

Bio: `http://linktr.ee/crucibletattooco/`. Scan: `observations: 11, connected: 2, noted: 5, probed: 4`.
Plus **3 `commerce_probe` observations**.

| input | outcome | proof |
|---|---|---|
| `crucibletattooco.com.au/` + 5 sub-pages | **custom link** ×6 | 6 `link` items; observations `note / unknown-domain` |
| `au.pinterest.com/crucibletattooco_/` | **custom link** | correct by decision (retired 2026-07-28) |
| `bsky.app/profile/crucibletattooco.bsky.social` | **custom link** | no catalog definition |
| `paytherent.net.au/` | **`product` item** `Private: Demo`, **withheld** | `content.items` kind `product`; no `shop` pool on the wire — **R7** |
| `tiktok.com/@crucibletattooco` | **seeded** `tiktok.profile` | observation `place`, conf 75 |
| `instagram.com/crucibletattooco/` | **seeded** a *second* `instagram.profile` | **R4** — this was `skipped` at 11:08 |

**11 = 8 custom + 1 product + 2 seeded. Balances.** Public wire: `custom_links: 8`, no `shop` pool.

### Ledger 4 — `kimcosmik` (15 in, 15 accounted) — the dedupe test

Bio: `https://linktr.ee/kimcosmik?utm_source=…`. I fetched the Linktree: **126 anchors, 15 unique
external links**. The router recorded **exactly 15 observations**. Scan: `connected: 9, noted: 4,
probed: 2`.

> The prompt's description of this account (~19 links, **two SoundCloud**, **three Juno**) is **stale** —
> the live Linktree has one Juno link and no SoundCloud at all. The dedupe test still works, via
> Bandcamp ×2, YouTube ×2 and Facebook ×3.

| input | outcome | proof (`routing.link_observations`) |
|---|---|---|
| `kimcosmik.bandcamp.com/` | **seeded** `bandcamp.artist` (`kimcosmik`) | `place`, conf 60 |
| `cybersoul.bandcamp.com/` | **seeded** `bandcamp.artist` (`cybersoul`) | `place`, conf 60 — **second Bandcamp, NOT skipped** |
| `mixcloud.com/KimCosmik/` | **seeded** `mixcloud.player` | `place`, conf 75 — was a custom link at 11:08 (**N-B**) |
| `youtube.com/channel/UCCY6-AIHHvrmZW5J8IAjk-A` | **seeded** `youtube.channel` | `place`, conf 75 |
| `youtube.com/@cybersoul9038` | **seeded** `youtube.channel` (`cybersoul9038`) | `place`, conf 75 — **second YouTube, NOT skipped** |
| `facebook.com/kimcosmik/` | **seeded** `facebook.profile` | `place`, conf 75 |
| `facebook.com/hybridrave` | **seeded** `facebook.profile` (`hybridrave`) | `place`, conf 75 — **second Facebook, NOT skipped** |
| `facebook.com/groups/3004349706304446/` | **custom link** | `note / no-rule-matched` — the reserved-path blind spot is now handled correctly: it is *not* mis-seeded as a profile |
| `instagram.com/kimcosmik/` | **seeded** second `instagram.profile` | **R4** |
| `discord.com/invite/q3FvffbQ` | **seeded** `discord.server` | `place`, conf 79 |
| `ra.co/dj/kimcosmik` | **custom link** | `note`, surface `resident_advisor.tickets`, **conf 28, `below_threshold`** |
| `kimcosmik.bandcamp.com/album/star-glider` | **custom link** | `note`, surface `bandcamp.artist`, **conf 52, `below_threshold`** |
| `obskurmusic.bandcamp.com/track/carissa-illy-…` | **custom link** | `note`, conf 52, `below_threshold` |
| `discogs.com/search?q=kim+cosmik` | **custom link** | `note / unknown-domain` — no catalog definition |
| `juno.co.uk/products/…-vinyl/952291-01/` | **custom link** | `note / unknown-domain` — no catalog definition |

**15 = 9 seeded + 6 custom. Balances exactly against the fetched page.**

Three things worth naming here:

- **First-link-per-platform is gone, deliberately.** `e0820b8e2` states the new rule: *"first surface +
  identifier wins the connection; a later distinct URL for the same one is carded"*. So a second Bandcamp
  **artist** is a second connection, not a skip. This directly contradicts the prompt's §3.7 expectation
  ("exactly ONE row per platform"), and it is the **better** behaviour — this person genuinely runs two
  Bandcamp pages, two YouTube channels and two Facebook pages, and at 11:08 the second of each was thrown
  away. §3.7 is obsolete as written, not failing.
- **`resident_advisor.tickets` matched but scored 28** against the threshold. RA is "defined and must
  therefore classify" per the prompt — it *does* classify, it just does not clear the confidence bar. That
  is a scoring outcome, not a catalog gap, and is only visible because observations now persist.
- **Bandcamp album/track pages score 52 and card.** Only the artist root connects. Sensible.

### Ledger 5 — `themilleraffect` (5 in, **4 accounted — one vanished**)

Instagram bio carried **2** links (`links_seen: 2`, `unmatched: 1`, `probes_spent: 1`): the Linktree and a
bare `att.com` affiliate URL. The Linktree itself carries **4** unique external links (fetched and
counted). Total input **5**.

| input | outcome | proof |
|---|---|---|
| `att.com/internet/fiber/?source=EMC980676826&wtExtndSource=_inflnc_+themilleraffect` | **custom link** | `link` item + wire; `commerce_probe` observation `reject / no_probe_matched` |
| `shopltk.com/explore/themilleraffect?utm_*` | **custom link** | `note / unknown-domain` + wire |
| `shopltk.com/explore/themilleraffect/collections/11ecafbde…?utm_*` | **custom link** | `note / unknown-domain` + wire |
| `poshmark.com/closet/themaffect?utm_*` | **custom link** | `note / unknown-domain` + wire |
| `canva.link/hxwh4ybxzn38wkg?utm_*` | **VANISHED** | observation `verdict=reject, block_reason=public-suffix-host`; **no `content.items` row, absent from the public wire** — **R2** |

**5 ≠ 4 + 0. §3.6 does not balance for this account.** This is the single most important negative result
of the run.

Probe budget: `probed: 2` of 6. **The budget was never exhausted on any account this wave** (max was 4,
on `crucibletattooco`), so §3.9's silent-truncation scenario did not arise and remains untested. The
importer's budget is now "one probe per unknown **host** per run", which is why a shop-dense account spends
so few.

### Ledger 6 — `supernormal_180` (1 in, 1 accounted) — the N2 floor

Bio: `https://linkin.bio/supernormal_180`. Scan: `observations: 0, pages: 1, pages_unavailable: 0,
connected: 0, noted: 0, probed: 0, skipped_chrome: 0`, **`bio_url_seeded: true`**.

I re-fetched the page independently: HTTP 301 → 200, **6,441 bytes**, `<title>Linkin.bio</title>`,
**0 `<a href>` anchors**; `opentable`, `sevenrooms`, `ubereats`, `menulog` all absent from the delivered
HTML. Byte-identical in size to the 2026-08-11 measurement — the SPA premise is unchanged.

| input | outcome | proof |
|---|---|---|
| `linkin.bio/supernormal_180` | **custom link** "My Link in Bio Page" | `content.items` kind `link`, `f_link.url = https://linkin.bio/supernormal_180`; public wire `custom_links: 1` |

**1 = 1. Balances. N2's floor works end to end** — the URL is no longer lost, and the site is no longer
empty of everything but Instagram.

**§3.8 remains untested for a third consecutive run.** The reservations / online-ordering gate denial can
only be exercised if those links reach the router, and they are behind the SPA. Nothing about the gate was
proven or disproven here.

---

## 3. Section-by-section

### §1 — Identity and handle

| # | check | verdict | evidence |
|---|---|---|---|
| 1.1 | IG username → suggested handle | **PASS** ×6 | `simondoylehair`, `jesshairstylist`, `crucibletattooco`, `kimcosmik`, `themilleraffect`, `supernormal-180` |
| 1.2 | `handle` == `subdomain` | **PASS** ×6 | including `jess.hair.stylist` → **both** `jesshairstylist`. **SIGNUP-1 stays fixed** |
| 1.3 | `display_name` is a real name | **PASS ×5, N/A ×1** | "SIMON DOYLE \| Barber & Educator", "Prahran Hairdresser", "Crucible Tattoo Co.", "Amanda Miller Pollard", "Supernormal". `kimcosmik` → `display_name = "kimcosmik"` because payload `fullName` is the **empty string**, not null — SIGNUP-2's fix covers null; there is nothing else to fall back to |
| 1.4 | `first_name` sensible | **PASS with a caveat** | naive split on the space: `Crucible` / `Co.`, `Prahran` / `Hairdresser`, `Amanda` / `Pollard`. Fine for a person, poor for a business name — unchanged from prior runs |
| 1.5 | IG category → sector | **PASS ×6** | `sector_source = 'instagram'` on all six; `sector.transition` logged `outcome: applied` for each. `crucibletattooco` resolved `tattoo-artist` with `businessCategory` **NULL**, so the classifier is not merely copying the IG category |
| 1.6 | Contact fields folded | **FAIL ×6, and now explained** | **zero** `site.workplaces` rows for any account. `InstagramIdentitySync::applyContactFields` reads `businessEmail` / `businessPhoneNumber`; both are **NULL in all six payloads**. There is no input, so this cannot succeed via Instagram regardless of the fold logic |

### §2 — The scrape

| # | check | verdict | evidence |
|---|---|---|---|
| 2.1 | Profile fields captured | **PASS** | followers/posts: 11099/366, 4164/112, 30041/4169, 8467/826, 336198/5169, 83779/2321 |
| 2.2 | `biography` present | **CONFIRMED ABSENT** ×6 | as documented; not investigated further per the prompt |
| 2.3 | Profile picture mirrored | **PASS** | `payload.images` length **1** on all six = the profile pic only |
| 2.4 | Post media mirrored | **PASS** | `content.media_assets`: 55 / 33 / 61 / 88 / 47 / 44 |
| 2.5 | Every media row has a variant | **OBSOLETE** | `site.site_media` / `site.media_variants` are retired for this lane; media lives in `content.media_assets` + `content.item_media` |
| 2.6 | Gallery ≤ 6 | **N/A** | the `core.enforce_site_gallery_max6` trigger guards `site.site_media`, which this lane no longer writes. The public cap is now the W2 "N newest per source" rule — **5** items on the wire per account, from 12 stored |

The 11:08 run's question is answered again and identically: `images` holds **only** the profile picture;
real post media lands in `content.media_assets`. It is not under-filling.

### §3 — Link routing

**The premise of this section is now obsolete and must be rewritten before the next run.** The prompt says
the Instagram path uses the legacy `LinkRouter`, which writes no observations, so an empty
`routing.link_observations` is expected. As of `e0820b8e2` the link-in-bio path runs on
`LinkInBioImporter`, which **does** write observations. This run produced **20 observation rows for batch A
and 22 for batch B**, and they are now the primary evidence trail for routing decisions.

| # | check | verdict | evidence |
|---|---|---|---|
| 3.1 | Classified link → connection row | **PASS** | 15 seeded connections across the wave, each with a matching `place` observation |
| 3.2 | Unclassified link → custom link, nothing vanishes | **FAIL** | `canva.link` on `themilleraffect` — **R2** |
| 3.3 | Shop/unclassified dispatched `CommerceProbeJob` within budget | **PASS** | 8 probes across the wave, budget 6/run never reached |
| 3.4 | Probe resolving a product page → item | **PASS (undesirably)** | `paytherent.net.au` → `product` item — **R7** |
| 3.5 | Probe resolving a storefront → shop connection | **UNVERIFIED** | no probe resolved a storefront this run |
| 3.6 | Inputs == seeded + custom + skipped + pending + denied | **FAIL on 1 of 6** | balances on accounts 1,2,3,4,6; **breaks on 5** |
| 3.7 | One row per platform, later duplicates skipped | **OBSOLETE** | rule changed to first *surface + identifier*; see Ledger 4 |
| 3.8 | Gate-denied links become custom links | **UNTESTED** | third run in a row — links unreachable behind the `linkin.bio` SPA |
| 3.9 | Count of links past the probe cap | **N/A** | cap never reached (max 4 of 6) |

### §4 — Does the loop continue? (cascade)

The shape of this question has changed completely, because the accounts that used to produce a Fresha
connection no longer produce one at all (**R1**), while `kimcosmik` now produces nine connections that did
not exist before.

| # | check | verdict | evidence |
|---|---|---|---|
| 4.1 | Seeded connection dispatches a fetch | **PASS, delayed** | 3 of `kimcosmik`'s 9 connections provisioned an `ingest.sources` row; all 3 ran on the 06:30 `ingest:dispatch` tick, ~10 min after connect — **R5** |
| 4.2 | Seeded **Fresha** connection fetches its menu | **CANNOT ARISE** | there is no Fresha connection on either hairdresser this run — **R1** supersedes **N-A** |
| 4.3 / 4.4 | Services / categories projected | **N/A — prompt is stale** | `site.services` and `site.service_categories` were **dropped** by the content-pool convergence; the SQL in this section no longer runs |
| 4.5 | URLs inside a connection payload get routed | **PASS** | the Bandcamp fetch produced 3 `release` items and populated the public `listen` pool — the first time this prompt has ever recorded a completed cascade |

### §5 — Auto-signup rules

| # | check | verdict | evidence |
|---|---|---|---|
| 5.1 | Instagram auto-media rule enabled on create | **PASS** | `AutoSyncSetting` is sparse — *absent means ON* (`app/Site/Pools/AutoSyncSetting.php` docblock). Effect confirmed on the wire: 5 media items published per account without any explicit setting row |
| 5.2 | `is_published` is `false` | **PASS ×6** | `site.sites.is_published = false` on all six |
| 5.3 | Site nonetheless publicly reachable | **PASS ×6** | `https://<handle>.partna.au/` → **200** for all six |
| 5.4 | KV entry written | **PASS (indirect)** | 5.3's 200 is only reachable through the Worker's `SUBDOMAIN_KV` lookup, so a 200 proves the KV write. No direct KV read was performed |
| 5.5 | `GET /api/public/profiles/<handle>` returns built content | **PASS ×6** | `architectureId: staple`, `designKit` present, pools populated |
| 5.6 | `status = 'unclaimed'` | **PASS ×6** | `core.users.status` |

5.2 + 5.3 together remain **correct, decided behaviour** (SIGNUP-3).

### §6 — Errors and noise

From 951 unique captured log records spanning 06:11–06:26Z:

- **0 exceptions, 0 failed jobs, 0 5xx.**
- **7 warnings total**, and only two kinds:
  - `media_mirror.failed` × **1** — `fetch_failed`, host `instagram.fmel5-1.fna.fbcdn.net`.
  - `slow_public_profile` × **6** — all `outcome: 200`, 1082–1778 ms. Five are my own verification
    requests against a cold cache; one is an unrelated account (`ollies`).

The single logged mirror failure does **not** account for the unmirrored asset tail (see **R8**).

---

## 4. Findings

### R1 · Fresha's own share URL no longer connects — booking regression from the P8 migration

*Plain English.* A hairdresser puts their Fresha booking link in their bio — the link Fresha's own app
gives them to share. We used to recognise it and connect their booking page. We now treat it as an
anonymous web link and put it on their site as a plain card. Both hairdressers in this wave hit it. For a
platform whose first two pilot users are hairdressers, this is the booking surface not working.

*Technical.* Both Fresha URLs in this wave are of the share shape
`https://www.fresha.com/book-now/<slug>/all-offer?share=true&pId=…`. Both produced
`routing.link_observations.verdict = 'note'`, `block_reason = 'no-rule-matched'`, **zero
`site.platform_connections` rows** and zero `ingest.sources` rows, and landed as `content.items` kind
`link`.

Root cause is a narrowing, and it is exact:

- The **legacy** path matched Fresha by **host alone** —
  `app/Services/Platforms/WebsiteLinkHarvester.php:146` `'Fresha' => '~(^|\.)fresha\.com$~'`, and
  `app/Services/Platforms/Strategies/Detect/HostMatch.php` names fresha among the host-match platforms.
  Any path under `fresha.com` classified.
- The **catalog** detector that `LinkInBioImporter` now uses is path-strict —
  `app/Catalog/Definitions/Fresha.php`: `Detector::url('fresha.com')->path('#^/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/(?<slug>[a-z0-9-]+)/?$#i')`.
  It matches **only** `/a/<slug>`. `/book-now/<slug>/all-offer` does not match.

The codebase already knows this shape is the real-world one — three other places handle it explicitly:
`app/Ingest/SourceProvisioner.php:460-474` (`(?:a|book-now)`, with the comment *"`book-now` is the share
URL Fresha's own app hands out"*), `app/Services/Platforms/FreshaScraper.php:56-65`, and
`app/Services/Platforms/GoogleBusinessAutoSync.php:362`. Only the **catalog detector** — the one the new
router consults — was never widened.

**This supersedes N-A.** At 11:08 both accounts had a `fresha.book` connection that provisioned a source,
ran eagerly and dead-ended on a NULL `selection_ref`. Now there is no connection to dead-end. The
user-visible outcome is the same (no services), one step further upstream, and the diagnosis is different.

### R2 · A bio link silently vanishes — `canva.link` is rejected and never carded

*Plain English.* One of this influencer's four bio links is a Canva page. It does not appear on her site,
and it does not appear as a plain link either. It is simply gone, with no error anywhere.

*Technical.* `https://canva.link/hxwh4ybxzn38wkg?utm_*` is on `themilleraffect`'s Linktree (verified by
fetching the page: 4 unique external anchors, this is one of them). It produced
`routing.link_observations.verdict = 'reject'`, `block_reason = 'public-suffix-host'` — `canva.link` is a
public-suffix entry, so the registrable-domain check rejects it. But **reject has no card path**: there is
no `content.items` row for the URL and it is absent from `GET /api/public/profiles/themilleraffect`
(`custom_links` = 4, not 5).

This is a direct **§3.6 failure** — the prompt's most important check — and it is the same class of bug as
N2: a link the user deliberately published disappears without trace. N2 got a floor; the `reject` path did
not. The observation row is the only evidence it ever existed, and that row is itself deleted-user-orphaned
(**R3**).

Note the asymmetry: `note` verdicts card, `reject` verdicts do not. `public-suffix-host` is a *structural*
rejection (we cannot derive a registrable key), not a judgement that the link is bad — so dropping it is
not obviously the intent.

### R3 · Account purge orphans `routing.link_observations` — an erasure gap on a table of user URLs

*Plain English.* When an account is deleted, the record of every link we scraped from that person's bio
stays in the database, still stamped with their user id. Everything else about them is removed.

*Technical.* After `AccountDeletionService::purge()` returned `true` for all three batch-A users, verified
independently:

```
users_left 0 | builds_left 0 | items_left 0 | ingest_sources_left 0 | link_observations_left 20
```

The 20 rows retain the **deleted** `user_id` (a `SELECT` joining `core.users` returns `user_exists = 0`)
and carry `raw_url` — the person's real bio links, including their Instagram, TikTok and website URLs.

Cause: `routing.link_observations` **has no foreign key to `core.users` at all**. Its sibling tables in the
same schema all do, and all CASCADE:

| constraint | delete rule |
|---|---|
| `source_intents_user_id_fkey` | CASCADE |
| `import_runs_user_id_fkey` | CASCADE |
| `item_tombstones_user_id_fkey` | CASCADE |
| *(none for `link_observations`)* | — |

This was latent until this week: before the P8 migration the table was empty for this lane (the 11:08 run
recorded zero observations for all six accounts, and the builds I purged at 06:11 left **no** orphans
behind because they predate the change). P8 started populating it, which is what turned a dormant schema
gap into a live erasure gap. Relevant to GDPR deletion and to the existing append-only/set-null tension.

### R4 · A self-referential Instagram bio link seeds a duplicate `instagram.profile` connection

*Plain English.* Most people link their own Instagram from their Linktree. We now treat that as a second,
separate Instagram account and connect it again — so the person gets two Instagram connections and two
recurring sync jobs for one account.

*Technical.* On `jess.hair.stylist`, `crucibletattooco` and `kimcosmik`, the bio page's link back to the
account's own Instagram produced a **second** `site.platform_connections` row (`surface_key
instagram.profile`, `payload.source = link_in_bio`) alongside the build's own source connection, plus a
**second `ingest.sources` row** and a second `ingest.runs` row. At 11:08 the same link on
`crucibletattooco` was recorded as **`skipped`** (prior report, Ledger 3).

The cause is the deliberate rule change in `e0820b8e2` — dedupe moved from *first surface wins* to *first
surface **+ identifier** wins*. That change is right for two genuinely different Bandcamp artists
(Ledger 4). It is wrong here, because the two rows are the **same** Instagram account: the build's
connection carries `resource_id = 'instagram'` (a literal marker) while the harvested one carries
`resource_id = 'crucibletattooco'` (the real handle), so the identifier comparison cannot see that they
match.

**Cost impact is limited but not zero.** The duplicate run recorded `cost_claimed = 0` and
`effects_count = 0` — the profile fetch was reused from the cache added in `228833cf9`, so no second Apify
scrape was billed *this run*. But the duplicate source is a real recurring row with
`next_attempt_at = 2026-08-25` and `cost_units = 50`, so the reuse window will have long expired by the
time both fire.

### R5 · The cascade now completes — with up to 15 minutes of latency, and one dead surface

*Plain English.* This is the good news, and it answers Josh's standing question directly. Once we connect a
DJ's Bandcamp from their bio, something downstream **does** now fetch their music, and their releases appear
on their site. It just does not happen at build time — it happens on the next quarter-hour sweep, so a
person watching their site immediately after signup sees nothing for up to 15 minutes.

*Technical.* `kimcosmik`'s 9 connections provisioned **3** `ingest.sources` rows (Bandcamp ×2, YouTube ×1;
Mixcloud, Discord and both Facebook connections provisioned none). All three were created `auto_sync = true`,
`cost_units = 1`, `next_attempt_at` immediate (06:20:20–06:20:21), and at 06:29:52 all three still read
`runs = 0`, `last_run_at = NULL`, `in_flight_since = NULL` — nine minutes past due. The eager-run-on-connect
added for Fresha in `c526bcf5e` does not cover these surfaces.

The **06:30 `ingest:dispatch` tick** (`routes/console.php:490`, `->everyFifteenMinutes()`) then ran all
three. Read at 06:31:13:

| source | outcome | records | detail |
|---|---|---|---|
| `bandcamp.artist` `kimcosmik.bandcamp.com` | **ok** | **3** | `streams: {releases: ok}` |
| `bandcamp.artist` `cybersoul.bandcamp.com` | ok | 0 | note `empty_discography` — *"No releases parsed from the music page"* |
| `youtube.channel` `UCCY6-AIHHvrmZW5J8IAjk-A` | **unavailable** | 0 | `streams: {watch: unavailable}`, `health = 'unavailable'`, retry 2026-08-19 |

And the records reached the public site. Three `content.items` of kind `release` were created at
**06:30:09** — `Star Glider`, `Unknown Territory`, `VIS329 - Drifting` — and
`GET /api/public/profiles/kimcosmik` now carries a **`listen` pool with 1 item** (the newest-per-source cap).

So **§4.1 and §4.5 are PASS for Bandcamp**, on a path that produced nothing at all in every prior run of
this prompt. Three qualifications, none of which undo that:

1. **≤15-minute latency**, invisible to the person who just signed up.
2. **YouTube returns `unavailable`** on a channel that exists and is public — its own finding, unexplored
   here per the prompt's no-fixing rule.
3. **The same album is now on the site twice, in two pools.**
   `kimcosmik.bandcamp.com/album/star-glider` is a `custom_links` card (carded from the Linktree at
   confidence 52, `below_threshold`) *and* a `release` item in `listen` (fetched from the connection). The
   two paths do not reconcile against each other.

### R6 · The commerce probe records its misses but not its match

*Plain English.* A fix went in so shop-probe results are recorded. The probes that find nothing are now
recorded. The one probe that actually found something is not.

*Technical.* `bd593dfdf` moved the observation write in `StoreBrandSeeder` **before** the `!isMatch()`
return, so a miss lands in `routing.link_observations`. That half works: this wave produced 6
`commerce_probe` observations, all `verdict = 'reject'`, `block_reason = 'no_probe_matched'`
(`crucibletattooco.com.au`, `bsky.app`, `au.pinterest.com`, `att.com`, `shopltk.com`, `poshmark.com`).

But `paytherent.net.au` — the one probe that **resolved** (`commerce_probe.resolved` logged with
`resolved: true` at 06:13:48, and it produced a `product` item and a `partna.manual_product` connection) —
has **no `commerce_probe` observation at all**. Its only observation is the `link_in_bio` `note` row.

So the ledger is inverted relative to its stated purpose: the class's contract comment says the point is to
make *"why isn't it on my page?"* answerable, and the case where something **did** appear on the page is
the one with no record. **N-E is half closed, not closed.**

### R7 · The junk product is still manufactured — `paytherent.net.au` → "Private: Demo"

*Plain English.* We still decide that a rent-payment website is a shop and invent a product called
"Private: Demo" from its unfinished homepage draft. It is still kept off the public site.

*Technical.* Unchanged from the 11:08 run: `content.items` kind `product`, `headline_cache =
'Private: Demo'`, `f_link.url = https://paytherent.net.au/`, plus a `partna.manual_product` connection with
`routing_class = shop`. The public wire shows **no `shop` pool** for `crucibletattooco`.

`bd593dfdf`'s N-C fix makes `GenericShopScraper` treat a storefront root carrying exactly one Product node
as a *store page* — but it is explicitly conditioned on `looksLikeStorefront()`, and `paytherent.net.au`'s
root does not look like a storefront, so it still reads as a product. The fix is narrower than the finding.

**One evidence correction.** The 11:08 report cited `eligible_cache = []` on the product item as proof it
was withheld. That inference is unsafe: `eligible_cache` is `[]` on **every** item this run, including the
media items that *are* published on the wire. The sound evidence for withholding is the absence of a `shop`
pool in `GET /api/public/profiles/crucibletattooco`, not the cache column.

### R8 · The unmirrored media tail is mostly silent

*Plain English.* Some Instagram images never get copied to our storage, and almost none of those failures
produce a log line.

*Technical.* Assets vs mirrored: 55/53, 33/31, 61/**51**, 88/84, 47/42, 44/**31**. That is 32 unmirrored
assets across the wave. The 951-record capture contains exactly **one** `media_mirror.failed` warning. So
either the remaining jobs were still queued at read time or they fail without a warning line; the gap
between 44 and 31 on `supernormal_180` is too large to be explained by the single logged failure. **N-G
persists**, and its observability is worse than the prior run assumed.

---

## 5. Explicitly correct-by-design — do not re-raise

- **Two Bandcamp / two YouTube / two Facebook connections on `kimcosmik`** — the dedupe rule is now *first
  surface + identifier*, and these are genuinely different accounts. §3.7 of the prompt is obsolete.
- **Reservations / online-ordering denied to a `partna` account** — correct; still untested (§3.8).
- **Pinterest as a custom link** — deliberately retired 2026-07-28, owner decision.
- **Bluesky, Discogs, Juno, LTK, Poshmark as custom links** — zero catalog definitions.
- **`facebook.com/groups/<id>/` as a custom link** — `no-rule-matched`. The reserved-path segment is
  correctly *not* mis-read as a profile handle.
- **Resident Advisor and Bandcamp album pages as custom links** — they classify, then score
  `below_threshold` (28 and 52). A scoring outcome, not a catalog gap.
- **`bioLinks` / `syncFindings` / `unmatched` absent from payloads** — PRIV-2 strip, verified ×6.
- **`biography` absent from payloads** — known, both actors.
- **`payload.images` length 1** — profile picture only; post media is in `content.media_assets`.
- **`is_published = false` while the site serves 200** — SIGNUP-3.
- **`site.site_media` / `site.media_variants` empty** — retired for this lane.
- **Media pool shows 5 of 12 stored items** — W2 "N newest per source" cap.
- **`display_name = "kimcosmik"`** — payload `fullName` is the empty string; not a SIGNUP-2 regression.
- **An observation `verdict` of `note` alongside an existing connection** (Eventbrite) — the inline
  EventsSeeder arm seeds outside the detector verdict.

---

## 6. State left behind

**Nothing. All six accounts were purged** — batch A at 06:19Z (to free cap slots mid-run) and batch B at
06:36:24Z on Josh's instruction once the report was written. The per-IP cap for origin `7f00cf75…` is back
to **0 of 3**.

Verified after the final purge:

```
my_live_slots 0 | users_left 0 | items_left 0 | sources_left 0 | conns_left 0 | observations_left 22
```

**That last number is R3 reproducing itself.** Deleting batch B orphaned **22 more**
`routing.link_observations` rows. Dev now holds **42 orphaned observation rows** in total — every one
stamped with a `user_id` that no longer resolves, every one carrying the account's real bio URLs. I left
them in place: rule 5 forbids changing anything, and they are the evidence for the finding.

## 7. Audit — what I deleted, and when

Two purges, both via `AccountDeletionService::purge()` (`cloud tinker development --code=…`) — the full
teardown path: Supabase auth user, object-storage artifacts, cache invalidation, PII erasure, audit-link
pre-null, then `forceDelete` with `UserObserver::deleted` retiring the handle in KV.

**Purge 1 — 06:11:06–06:11:11Z, before the run.** The three builds left live by the 11:08 report, deleted
on Josh's pre-run answer so batch B could reuse the real handles:
`kimcosmik` (`01a01262-2bda-…`), `themilleraffect` (`01a01262-9180-…`), `supernormal-180` (`01a01263-0a29-…`).
All returned `true`. Verified independently: `old_ip_builds 0 | handles_taken 0 | handle_aliases 0`.

**Purge 2 — 06:19:12–06:19:16Z, after all batch-A evidence in §1–§5 was captured**, to free the three cap
slots batch B needed: `simondoylehair` (`01a0137f-5531-…`), `jesshairstylist` (`01a0137f-e3db-…`),
`crucibletattooco` (`01a01380-9e1e-…`). All returned `true`. Verified independently rather than trusting
the command's own report (`command:run` can pair `success` with a non-zero exit code):

```
my_live 0 | users_left 0 | sources_left 0 | items_left 0 | obs_left 20
```

That trailing `obs_left 20` is **R3**.

**Purge 3 — 06:36:24–06:36:29Z, after this report was written**, on Josh's instruction to clear the wave:
`kimcosmik` (`01a01386-e21e-…`), `themilleraffect` (`01a01387-45c8-…`), `supernormal-180`
(`01a01387-a6cb-…`). All returned `true`, all state verified gone — and 22 further observation rows
orphaned, taking dev's total to 42.

**Six Apify scrapes billed, one per handle. No build was retried. No code, config, migration or data was
changed.**
