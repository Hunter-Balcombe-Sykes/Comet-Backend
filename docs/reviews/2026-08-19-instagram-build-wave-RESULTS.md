# Instagram (`partna`) build wave — verification report, 2026-08-19 (run 5)

Fifth full run of `docs/reviews/2026-08-10-instagram-build-wave-PROMPT.md`, all six handles attempted,
dev only (`https://dev-api.partna.au`, Supabase `glncumufgaqcmqhzwrxm`).

**Six builds attempted, five reached `ready`, one was killed mid-flight by a deploy** (build 2,
`jess.hair.stylist`). No build was retried. No code, config, migration or data was changed during the
run. Batch A was purged mid-run on Josh's explicit pre-run answer (§7); batch B is left live and
unclaimed as rule 4 requires.

> ### ⚠️ This wave did NOT run on one commit
>
> Three different deploys were live across it. Any cross-build comparison must account for this:
>
> | build | handle | deployed commit | deploy |
> |---|---|---|---|
> | 1 | `simondoylehair` | `60e142011` | `depl-a28863f5`, 2026-08-18T15:28:14Z |
> | 2 | `jess.hair.stylist` | `60e142011` → **killed by `a8c825865`** | `depl-a28880c4`, 16:47:48Z |
> | 3 | `crucibletattooco` | `a8c825865` | as above |
> | 4–6 | batch B | `103321df3` | `depl-a288af8f`, 18:58:31Z |
>
> Batch A ran 16:47–16:55Z; batch B ran 00:05–00:09Z the following day, after a ~7h operator gap.

**Preconditions, re-measured immediately before the run.** Cap is
`partna.pre_account.max_unclaimed_per_ip = 3` (`config/partna.php:1168`, no env override on dev —
checked via `cloud environment:get development --json --fields=environmentVariables`). Waitlist is not
set, so `PreAccountBuildController` does not 403. I read the **whole** `core.pre_account_builds` table
grouped by `created_ip_hash` rather than a filtered count; the newest pre-existing row was
`tobiasbalcombe`, 2026-08-12, and none of the six target handles was taken. The RUN2 wave had been
fully purged (its §7, purge 3).

⚠️ **The prompt's egress-hash recipe is a trap and I fell into it before catching it.** `hash('sha256',
$ip)` over my ipify-reported egress IP `14.1.95.13` gives
`cb92c24a179470043179628cc4ee2f8843445365438c987a93a69b24039cdef3`, and a filtered count on that hash
returned **zero rows even after three builds existed**. The hash actually stored is
`1044b245990012438fa6e55f3fda0323aba652fd6615327d3df0135fc9bf687e` — the IP Cloudflare sees for
`dev-api.partna.au` is not the IP ipify sees. Anyone gating on a self-derived hash will read "cap clear"
regardless of the truth. **Query by `build_id`, or read the whole table grouped by `created_ip_hash`.**

⚠️ **And the hash is not stable within a single session.** Batch A was recorded under `1044b245…`;
batch B, ~7h later from the same laptop, under `c3cd8d49a8250f257f252ab17c5d4a9c36a67d6a8d179c68c919055f846127e7`.
A residential IP rotates. Treat "how many slots do I have left" as a question that can only be answered
by attempting a build — see §7.

**Log capture method.** RUN2's **N-F** recorded that `cloud env:logs` returns at most 100 records and
that `--live` is not a stream. I polled `--minutes 2 --json` every 15s into timestamped files and
de-duplicated on `(loggedAt, message)`, capturing **4,517 unique records** spanning
16:45:03Z → 00:13:38Z. Every log citation below comes from that capture. **The poller died during the
operator gap** and was restarted before batch B; build 4's tail was recovered with a one-shot
`--minutes 5` pull immediately after it completed.

---

## 1. Summary

| # | `source_ref` | handle | subdomain | display_name | sector / source | state | wall clock |
|---|---|---|---|---|---|---|---|
| 1 | `simondoylehair` | `simondoylehair` | `simondoylehair` | SIMON DOYLE \| Barber & Educator | `hair-salon` / `instagram` | ready | **21s** |
| 2 | `jess.hair.stylist` | `jesshairstylist` | `jesshairstylist` | `jesshairstylist` *(placeholder)* | — / — | **KILLED** | n/a |
| 3 | `crucibletattooco` | `crucibletattooco` | `crucibletattooco` | Crucible Tattoo Co. | `tattoo-artist` / `instagram` | ready | **26s** |
| 4 | `kimcosmik` | `kimcosmik` | `kimcosmik` | kimcosmik | `musician` / `instagram` | ready | **27s** |
| 5 | `themilleraffect` | `themilleraffect` | `themilleraffect` | Amanda Miller Pollard | `content-creator` / `instagram` | ready | **32s** |
| 6 | `supernormal_180` | `supernormal-180` | `supernormal-180` | Supernormal | `restaurant` / `instagram` | ready | **74s** |

| # | build_id | user_id |
|---|---|---|
| 1 | `01a015c4-fa06-71f7-b948-f573e98b9088` | `01a015c4-f962-7266-8362-5a288e16f0a6` |
| 2 | `01a015c5-9574-73c6-9cfe-a91546cbf7ae` | `01a015c5-94cb-70aa-85f6-2d788d934408` |
| 3 | `01a015cb-b16e-720f-bf83-e1b9daf107e5` | `01a015cb-b08f-70a0-92e7-8171cf22d692` |
| 4 | `01a01756-79f0-7201-994e-23b99ae16a07` | `01a01756-7992-737f-9996-17de121bfaab` |
| 5 | `01a01757-f4ae-7263-8a88-91ddb27fce4a` | `01a01757-f3e2-70e9-9fea-e6b957ce6213` |
| 6 | `01a01758-8ff9-7224-8682-cdcb30f24666` | `01a01758-8fd6-7066-bd3a-0b6dd0eba2ef` |

All six POSTs returned **202** with `build_state: "pending"`. All `failure_code` NULL, all
`thin_scrape_at` NULL. `cost_claimed = 50` on the Instagram run of builds 3, 4, 5 and 6; build 1's
Instagram run recorded `cost_claimed = 0` (see **R9**); build 2 produced **no `ingest.runs` row at all**.

### Link ledger — every input accounted for

Balance is taken from `platforms.link_in_bio_scan.completed` (`observations = connected + noted +
probed`) cross-checked against `routing.link_observations` row counts and the public wire.

| # | handle | observations | connected | noted | probed | custom_links on wire | balances? |
|---|---|---|---|---|---|---|---|
| 1 | `simondoylehair` | 3 | 3 | 0 | 0 | 0 (+1 event item) | ✅ |
| 3 | `crucibletattooco` | 11 | 2 | 5 | 4 | 9 | ✅ |
| 4 | `kimcosmik` | 15 | 9 | 4 | 2 | 6 | ✅ |
| 5 | `themilleraffect` | 4 (+1 at IG level) | 0 | 1 | 3 | 5 | ✅ |
| 6 | `supernormal-180` | 0 | 0 | 0 | 0 | 1 (the bio URL itself) | ✅ |

**§3.6 balances on every build that ran.** Nothing vanished. This is the first run in the series where
that is true without qualification — RUN2's **R2** (`canva.link` rejected and never carded) is fixed:
canva.link now appears on `themilleraffect`'s wire as a custom link.

### What changed since RUN2 (2026-08-18 06:00Z, commit `0cbe8330e`)

| RUN2 finding | status now | evidence |
|---|---|---|
| **R1** Fresha `book-now` share URLs no longer classify | **FIXED** | build 1 seeded `fresha` / `fresha.book` / `routing_class: booking` / `is_primary: true` from `https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260`, confidence 75 |
| **R2** `canva.link` rejected, never carded, §3.6 breaks | **FIXED** | `canva.link/hxwh4ybxzn38wkg` present in `themilleraffect`'s `pools.custom_links` |
| **R3** purge orphans `routing.link_observations` | **FIXED** | post-purge verification returned `observations_left: 0` for all three batch-A users (`af144f035`) |
| **R6** the matching probe writes no observations | **FIXED** | 10 rows with `source = 'commerce_probe'` across builds 3, 4 and 5 (`a1daaea86`, PR #289) |
| **R7** junk name-only JSON-LD Product created | **NOT REPRODUCED** | zero `content.items` of `kind = 'product'` across all five builds; `3064f3644` + `a38800b63` are both in the deployed commit |
| **R8** unmirrored Instagram CDN tail is silent | **READABLE** | `content.media_assets.mirror_eligible` (`db0fbe554`) explains the whole tail — see §2 |
| **N2** `linkin.bio` unrolls to zero links | **FIXED after this review** — `37537a4a6` unrolls via Later's public API | **R11** (superseded, see the addendum) |
| **N-D** tracking params rewritten to `[redacted]` | **UNCHANGED** | still on the public wire — **R12** |
| **§4 cascade** | **COMPLETES, and wider than RUN2** | `kimcosmik` produced 11 `release` + 23 `video` items — **R10** |
| **F5** sector resolution | **STAYS CLOSED** | 5 of 5 built accounts, all `sector_source = 'instagram'` |

---

## 2. Section-by-section

### §1 — Identity and handle

| # | check | verdict | evidence |
|---|---|---|---|
| 1.1 | IG username became the suggested handle | **PASS** 5/5 | `supernormal_180` → `supernormal-180` (underscore → hyphen), rest verbatim |
| 1.2 | `handle` == `subdomain` | **PASS** 6/6 | identical on every row including the killed build 2 |
| 1.3 | `display_name` is the real name | **PASS** 3/5, **N/A** 2/5 | builds 1, 5, 6 got real names; `crucibletattooco` → "Crucible Tattoo Co." (business name, correct); `kimcosmik` → `kimcosmik` because the IG profile carries no `fullName` — not the SIGNUP-2 defect |
| 1.4 | `first_name` populated sensibly | **PASS** | "SIMON", "Amanda", "Crucible", "Supernormal" |
| 1.5 | IG category became sector | **PASS** 5/5 | `hair-salon`, `tattoo-artist`, `musician`, `content-creator`, `restaurant` — all `sector_source = 'instagram'` |
| 1.6 | Contact fields folded | **FAIL** 0/5 | zero `site.workplaces` rows for any of the five. `InstagramIdentitySync::applyContactFields` reads `businessEmail`/`businessPhoneNumber`; still never populated |

**1.2 — SIGNUP-1 is not reproducing.** `jess.hair.stylist` (two periods, the exact divergence trigger)
produced handle `jesshairstylist` **and** subdomain `jesshairstylist`. The row was written at build
creation, before the job died, so this holds despite build 2's failure.

### §2 — The scrape

| # | check | verdict | evidence |
|---|---|---|---|
| 2.1 | Profile fields captured | **PASS** | `fullName`/`businessCategory` drove 1.3 and 1.5 |
| 2.2 | `biography` present | **UNVERIFIED** | not re-investigated per the prompt's instruction |
| 2.3 | Profile picture mirrored to R2 | **PASS** | see asset table below |
| 2.4 | Post media mirrored | **PASS** | 12 `media` items per account; asset mirroring 75–90% |
| 2.5 | Every media row has a `webp` variant | **N/A** | `site.site_media` is empty for all five — the media pool is `content.media_assets` now, and `site.media_variants` is not on this path |
| 2.6 | Gallery ≤ 6 | **N/A** | `site.site_media` gallery pool = 0 rows on all five; the trigger was never exercised |

| handle | assets | mirrored | eligible | unmirrored | max `mirror_attempts` |
|---|---|---|---|---|---|
| `simondoylehair` | 56 | 53 | 53 | 3 | 0 |
| `crucibletattooco` | 62 | 52 | 52 | 10 | 0 |
| `kimcosmik` | 117 | 79 | 79 | 38 | 0 |
| `themilleraffect` | 48 | 42 | 42 | 6 | 0 |
| `supernormal-180` | 44 | 42 | 42 | 2 | 0 |

**`mirrored` equals `eligible` on all five, and `max(mirror_attempts) = 0` everywhere.** The unmirrored
tail is not failure — it is assets the pipeline deliberately declined to mirror (`mirror_eligible =
false`), matching the `media_mirror.dispatch … skipped: {not_owned: N}` log lines. RUN2's **R8** is now
diagnosable from the row rather than by inference. The retry cap is not being hit.

### §3 — Link routing

**Which router ran.** Both. `routing.link_observations` is **no longer empty** for these users — 43 rows
across the five builds, `source` in (`link_in_bio`, `commerce_probe`). The prompt's "expect it empty" is
stale; RUN2's **N-E** is now fully closed by **R6**'s fix.

#### §3d — outcome-type verification

| # | check | verdict | evidence |
|---|---|---|---|
| 3.1 | classified platform link → `platform_connections` row | **PASS** | 9 rows for `kimcosmik`, 3 for build 1, 2 for build 3 |
| 3.2 | unclassified link → custom link, nothing vanished | **PASS** | ledger above balances on all five |
| 3.3 | shop/unclassified → `CommerceProbeJob` within budget | **PASS** | 2/6, 3/6, 4/6 probes spent; never exceeded |
| 3.4 | probe resolving a product page → item | **FAIL (no-op)** | zero `kind='product'` items. `juno.co.uk` product page → `probe_unreachable`; see **R13** |
| 3.5 | probe resolving a storefront → shop connection | **UNVERIFIED** | no probe resolved anything this run — every `commerce_probe` observation is `reject`/`no_probe_matched` or `note`/`probe_unreachable` |
| 3.6 | inputs == seeded + custom + skipped + pending + denied | **PASS** | the headline result; see ledger |
| 3.7 | duplicate-platform links deduped | **PASS, but the prompt's rule is stale** | see below |
| 3.8 | gate-denied links became custom links | **UNVERIFIED — and R11 does not unblock it** | on a `partna` account only `ordering` can be denied; the unrolled page carries none — see **R11** |
| 3.9 | probe budget overflow counted | **N/A** | budget never exhausted; `themilleraffect`'s Linktree has shrunk to 4 links |

**3.7 — `kimcosmik` produced two `bandcamp`, two `facebook` and two `youtube` connections, and that is
correct.** The prompt demands "exactly ONE row per platform". That expectation predates
`ce5629190` ("one account, one connection — cross-scheme identity"). The pairs are genuinely different
accounts:

| platform | row A | row B |
|---|---|---|
| bandcamp | `username: kimcosmik` | `username: cybersoul` |
| facebook | `username: kimcosmik` | `username: hybridrave` |
| youtube | `username: UCCY6-AIHHvrmZW5J8IAjk-A` | `username: cybersoul9038` |

Deduping these to one row per platform would **lose the artist's second project**. Meanwhile true
same-account repeats *were* suppressed: the two deep Bandcamp links
(`kimcosmik.bandcamp.com/album/star-glider`, `obskurmusic.bandcamp.com/track/…`) landed as
`below_threshold` notes → custom links, not as duplicate connections. **The prompt should be amended.**

**`facebook.com/groups/<id>/` remains a blind spot, correctly.** It landed `note` /
`no-rule-matched` → custom link rather than being mistaken for a profile.

### §4 — Does the loop continue? (cascade)

**PASS, and this is the strongest result in the run.** `kimcosmik`'s auto-routed connections fetched
their own content without any dashboard involvement:

- 5 `ingest.sources`, 5 `ingest.runs` (only the Instagram one billed)
- **11 `content.items` of `kind='release'`** — Bandcamp
- **23 of `kind='video'`** — YouTube
- public wire carries `listen` (2) and `watch` (2) pools built from them

Build 1's Fresha connection also produced an `ingest.sources` row (`fresha.book`,
`identifier: anseo-studio-v0v92jna`, `auto_sync: true`, `health: ok`) and a run — but
`records_seen: 0`. See **R14**.

### §5 — Auto-signup rules

| # | check | verdict |
|---|---|---|
| 5.1 | IG auto-media rule enabled on create | **PASS** — `instagram.latest_media` logged with `pickedPhoto`/`pickedVideo` true |
| 5.2 | `is_published` false | **PASS** 6/6 |
| 5.3 | site publicly reachable | **PASS** — 200 on all six `<handle>.partna.au` |
| 5.4 | KV entry written | **PASS** — `SyncSubdomainToKvJob … DONE` in logs; 5.3 could not pass otherwise |
| 5.5 | `GET /api/public/profiles/<handle>` returns built content | **PARTIAL** — content yes, `designKit`/`architectureId` **absent**; see **R15** |
| 5.6 | `status = 'unclaimed'` | **PASS** 6/6 |

### §6 — Errors and noise

From the 4,517-record capture. Nothing in the build windows except the build-2 kill.

| time | record | assessment |
|---|---|---|
| 16:47:40–16:47:52Z | `[Deploy: 2366] App cluster starting` → `[Deploy: 2365] Worker Cluster cluster shut down` | **the build-2 kill — R16** |
| 16:58:52Z | `App\Jobs\PreAccount\GeneratePreAccountSiteJob has been attempted too many times.` | build 2's terminal outcome, 11 minutes after dispatch |
| 18:22:52Z | `read error on connection to tls://cache-….caches.laravel.cloud:6379` | one-off Valkey read error, outside any build window |
| 17:30, 20:15, 22:00Z | `error waiting on adopted process` ×3 | platform noise, outside build windows |
| 18:43Z | `JWT JWKS verification failed … Expired token` ×2 → `GET /api/content/pools/services 401` | a dashboard session expiring; not this run |
| throughout | `slow_public_profile` 1.0–2.5s (`broken-oven`, `gsnwilliams`, `ra33rty`, and all three batch-B handles) | pre-existing; the new handles are at the fast end (1.1–1.7s) |

---

## 3. Findings

### R11 — `linkin.bio` still does not unroll; the floor is the only thing saving it
`supernormal_180`'s bio link is `https://linkin.bio/supernormal_180`. The scan **fetched it
successfully** and got nothing:

```
platforms.link_in_bio_scan.completed
{"bio_page_url":"https://linkin.bio/supernormal_180","outcome":"ok","observations":0,
 "pages":1,"pages_unavailable":0,"bio_url_seeded":true,"connected":0,"noted":0,"probed":0,
 "dropped":0,"skipped_chrome":0}
```

`pages: 1, pages_unavailable: 0` means the fetch worked. `skipped_chrome: 0` against **110** for every
Linktree in this wave means the harvester saw **no anchors at all** — the page is client-rendered. The
`bio_url_seeded: true` floor turns it into one inert "My Link in Bio Page" card, which is why the site
is not empty. This is the exact account that motivated adding `linkin.bio` to `LinkInBioDetector`'s host
list on 2026-07-23; recognising the host has never been the missing piece.

**Consequence beyond the account itself: §3.8 cannot be tested.** The gate-denial check exists to prove
that a restaurant's OpenTable/UberEats links become custom links on a `partna` account. Those links live
*inside* the linkin.bio page. Because it never unrolls, **no gate-denied link has been observed in any
run of this wave** — the check has been UNVERIFIED five times for the same root cause.

> #### ⚠️ Superseded 2026-08-19 — the unroll is fixed, but §3.8 is *not* unblocked
>
> **The finding above is now historical.** `37537a4a6` (N2/R11) added
> `App\Services\Platforms\LinkInBioApiUnroller`: the Ember shell names its own backend in a
> `<meta name="linkinbio/config/environment">` tag, and that backend answers
> `GET https://api-prod.linkin.bio/api/v2/pages?nickname=<slug>` publicly and unauthenticated. No
> browser needed. Re-verified live against this exact account on 2026-08-19 (`nickname=supernormal_180`,
> HTTP 200, 5.5 KB): the `button_list` block carries **8 buttons, 6 of them `enabled: true`** —
> SevenRooms reservations, gift vouchers, menu, the cookbook, a news post and events. The two disabled
> ones (Brisbane reservations, contact) are correctly skipped. The zero-yield floor is no longer what
> saves this account.
>
> **But the second half of the finding above is wrong, and it was wrong in the original framing too.**
> §3.8 does not become testable, and the reason is the capability matrix, not the unroll:
>
> ```
> AccountCapabilities::individualCapabilities()      // $isBusiness === false for every IG build
>     can_use_reservations:    $isBusiness ? $isFood : true    ->  TRUE
>     can_use_booking:         $isBusiness ? ! $isFood : true  ->  TRUE
>     can_use_online_ordering: $isBusiness && $isFood          ->  FALSE
> ```
>
> `config('partna.pre_account.sources')` pairs `partna => ['instagram']` and
> `business => ['google_business']`, so **an Instagram build is always `account_type = 'partna'`** —
> confirmed on dev: `supernormal-180` is `partna` / `restaurant` / `unclaimed`. On a partna account
> `RoutingCapabilityGate` can therefore deny exactly one class: **`ordering`**. The SevenRooms link is
> `reservations`, which is *allowed*, so it places rather than denies. The sentence above naming
> "OpenTable/UberEats" conflated the two: only the UberEats half could ever have denied.
>
> **§3.8 needs an ordering link** (UberEats / Deliveroo / Menulog / `square.order` — anything mapping to
> `ordering` in `LegacyPlatformMap`) on an Instagram-sourced fixture. `supernormal_180` has no such link
> on its page today. The check stays **UNVERIFIED**, and no linkin.bio account can close it by accident;
> it needs a deliberately chosen fixture. This is the sixth run it has been open.

### R13 — a real product page is unreachable to the probe, so §3.4 has never been exercised
`https://www.juno.co.uk/products/kim-cosmik-arsonist-recorder-hybrid-collective-vol-1-vinyl/952291-01/`
is an individual vinyl product page — the cleanest available "does a product page become an item?" test.
It produced `verdict: note`, `block_reason: probe_unreachable`. `discogs.com` likewise. Both fell back
to custom links, so nothing was lost, but **`content.items` gained zero `kind='product'` rows in the
entire wave** and §3.4/§3.5 remain unverified. `probe_unreachable` is distinct from
`no_probe_matched` (which is what `att.com`, `canva.link`, `shopltk` and `poshmark` returned) — it
suggests the fetch itself failed rather than the matcher declining.

### R14 — an auto-routed Fresha connection fetches an empty service list
Build 1's Fresha connection is no longer the bare `{url, provider, source:"auto"}` row RUN2 described —
it now provisions a real `ingest.sources` row and runs. But the run returned
`records_seen: 0, records_changed: 0, effects_count: 0` in 0 seconds. The venue
(`anseo-studio-v0v92jna`) is a live Fresha page with services. So the cascade *starts* on the auto-route
path and yields nothing, where the dashboard connect path yields a full projection. This is RUN2's
**N-A** in a new shape — no longer a dead end at `selection_ref`, now an empty fetch.

> #### ⚠️ Root-caused 2026-08-19 — and the framing above is wrong in two ways
>
> **It is not an empty fetch. No HTTP request was made at all** — that is what the "0 seconds" was
> telling us. `FreshaConnector::pull()` short-circuits when `selection_ref` is null, *before*
> `fetchBookingFlow()`, and yields `Note('no_selection')`. A Fresha URL names a salon, not a person, so
> a null selection encodes "a human still has to choose whose menu this is". The connector was working
> exactly as designed.
>
> **The connector said so, and this review read past it.** `RunExecutor:237` writes notes into
> `ingest.runs.detail->notes`. Build 1's row carried
> `{"code":"no_selection","message":"No Fresha team member or storewide menu has been chosen…"}`. This
> review checked `records_seen` / `records_changed` / `effects_count` and not `detail` — which is the
> one column the `Note` message type exists to populate. `outcome: 'ok'` is honest here: the run did
> what it was asked. **Zero records + `ok` is a legitimate state; `detail` is not optional reading.**
>
> Verified on dev across ten Fresha sources, no exceptions: every `selection_ref IS NULL` row has
> `records_seen: 0` and a `no_selection` note; every row with a `selection_ref` (`5182247`,
> `storewide`, `4891132`) has records. The null ones are all `payload.source = 'link_in_bio'` /
> `'instagram'`; the populated ones are all dashboard connects.
>
> **Why the selection was null.** `GeneratePreAccountSiteJob` gated auto-selection on
> `built_by_staff_id !== null`, so a public site-first signup never got it. That gate contradicts the
> feature's own design doc, whose construction-site table marks every Instagram-origin site `true`
> (`specs/2026-08-10-fresha-auto-route-selection-design.md:103-108`) — the two shipped in the same
> commit and have disagreed since 2026-08-11. **This is therefore not a new defect: it is F7 of the
> 2026-08-10 build wave, never actually fixed for self-serve signups.** A second road to the same
> symptom opened on 2026-08-18, when the aggregator lane migrated to `LinkRoutingService` and
> `LinkInBioScanJob::$autoConnectBooking` went vestigial.
>
> **Fixed** on `audit-fix/fresha-auto-selection-preaccount-2026-08-19` (owner ruling: name-match wins,
> storewide fallback, prompt to narrow at claim): every pre-account build auto-selects; the routing
> lane dispatches for unclaimed users; `payload.autoSelected` marks the guess so the dashboard can ask
> for confirmation. The spec carries a `[v4]` note recording the divergence.
>
> **VERIFIED on dev, 2026-08-19 06:46 UTC — R14 is closed.** Merge `d39cc6e61` deployed
> (`depl-a289a7bc`, 06:32:34Z); a real signup build of `simondoylehair`
> (`01a018c4-2afe-7295-9971-6cf4a6d74ed7`) reached `ready` and every check passed:
>
> | Check | Before | After |
> |---|---|---|
> | `ingest.sources.selection_ref` | `NULL` | **`storewide`** |
> | run `records_seen` / `changed` | 0 / 0 | **6 / 6** |
> | run `detail.notes` | `no_selection` | **`[]`** |
> | `last_refresh_status` | `action_needed` | **`ok`** |
> | `payload.autoSelected` / `matchTier` | absent | **`true` / `first-exact`** |
> | `pools.services` on the public wire | empty | **6 items, `origin: auto`, priced** |
>
> ⚠️ **Read the timing before trusting a re-check.** The first verification pass, run 56 seconds after
> the build reported `ready`, showed the OLD failing state and I briefly called the fix broken.
> `ConnectFetchJob` had not run yet — it completed at 06:46:33, the ingest run at 06:46:35.
> **`build_state: ready` does NOT mean the booking cascade has finished.** Poll the connection, not the
> build.
>
> ⚠️ **The storewide fallback fired on a MATCHED user, first time out.** `matchTier: 'first-exact'` —
> the matcher positively identified Simon Doyle — but `mode: 'storewide'`. The employee leg failed:
> `fresha.employee_services.failed`, "Fresha employee menu unavailable for
> `anseo-studio-v0v92jna/5182247`: no_categories", alongside a `fresha.slug_rotated` log. So the
> accepted price-understatement risk is **live on this public unclaimed page right now**, in the case
> the design treated as the safe one (we knew who the person was). Employee `5182247` is the same id
> the dashboard path projected 6 records from earlier in the week, so this is a fresh vendor-side
> failure, not a matcher defect. **Root-caused and fixed — see R17 below.**
>
> Ancillary correction: **RUN2's N-A was right and this entry demoted it.** "A dead end at
> `selection_ref`" was the accurate diagnosis; "now an empty fetch" replaced a correct finding with a
> wrong one.

### R17 — the auto lane fires the employee menu at a slug Fresha has retired

Found by R14's live verification, not by the wave itself. **Fixed** on `development` (`9f83de53a`),
deployed to dev `2026-08-19T07:53:53Z`.

Fresha rotates venue slugs. `anseo-studio-v0v92jna` is now
`anseo-studio-melbourne-140a-chapel-street-w8ajp04r`. `FreshaScraper::fetchLocation()` absorbs this
transparently — 404 → `resolveCurrentSlug()` → retry — and reports what it landed on via
`lastResolvedSlug()`. `FreshaController::team()` has always written that back for the dashboard lane
(`:420`, it also sets `canonical_key`). **The auto lane never did**, so `FreshaAutoSelector` took
`slugFromUrl()` off the *stored* url, fired the employee leg at the dead slug, got `no_categories`, and
degraded to storewide — silently, because degrading is its documented contract for any post-`fetchMenu`
failure. The stale slug then stayed on the connection, so every later refresh repeated it.

Measured live on dev, read-only, `2026-08-19 08:01Z`:

| | Result |
|---|---|
| `fetchEmployeeServices(stored_slug, 5182247)` | **`NULL`** — the defect |
| `fetchEmployeeServices(resolved_slug, 5182247)` | **6 services** |

**Fix:** the auto branch persists the resolved slug into `payload.url`; `FreshaAutoSelector` re-resolves
and retries the employee leg **once** when it returns empty, then still degrades if that also fails.
The retry is deliberately independent of `lastResolvedSlug()` — that is per-instance state the selector
does not share (no singleton binding), and the auto path's menu cache means `fetchMenu` may not have run
at all in a given request, which is precisely when persistence cannot help.

⚠️ **`matchTier: 'first-exact'` + `mode: 'storewide'` is this defect's signature.** Any future wave
showing that pair should check the slug before blaming the matcher.

**Verification status — deliberately partial, owner call 2026-08-19.** The mechanism is proven against
live Fresha (above) and the wiring is unit-covered (3 new tests pinning the signature and both guards).
The full end-to-end — build → auto-connect → `mode: 'employee'` on the wire — is **not** observed: a
second verification build was refused `IP_BUILD_CAP` (429) because the egress IP had rotated back into
the bucket holding batch B's three live builds, and batch B was not purged to make room. **The next
build wave exercises this path for free; it should show `mode: 'employee'`. If it shows `storewide`
with a `first-exact` tier, the fix is wrong.**

⚠️ **The price premise did not reproduce at this venue.** Storewide vs Simon's own menu, fetched live
and compared item by item: `Haircut from A$90 / from $90`, `Haircut & Beard trim from A$120 / from $120`,
`hot towel A$150 / $150`, `REFRESH A$80 / $80`, `VIP A$200 / $200`, `CONSULT A$0 / free` — **identical**.
The "22 of 23 understated" figure behind the storewide-is-risky decision came from a different salon, so
the risk is **venue-dependent, not general**. One data point, not a refutation: a multi-stylist venue
with varied rates would still diverge. But the fallback is not automatically wrong, and the accepted
risk is smaller than the design assumed.

### R15 — `designKit` and `architectureId` are absent from the public profile wire, platform-wide
`GET /api/public/profiles/{handle}` returns `document: null` and no `designKit` or `architectureId` key.
`CLAUDE.md` documents both as part of that contract (`architectureId` "always `staple`",
`skeletonId` as a transitional alias).

**This is not a pre-account defect.** I checked three established, unrelated handles — `gsnwilliams`,
`tobiasbalcombe`, `roberthuntercuts` — and all three return the same shape. Either the contract in
`CLAUDE.md` is stale (plausible: `e482045e3` trimmed several legacy keys from this wire), or a
regression landed platform-wide. The trim commit's message does **not** list `designKit` or
`architectureId` among what it removed, so I cannot close this from the commit log. **Flagged, not
diagnosed** — it is outside this wave's blast radius and rule 5 forbids me investigating further by
changing anything.

### R16 — a deploy silently destroys an in-flight pre-account build, and the visitor sees `building` forever
Build 2 was POSTed at 16:47:45Z. `GeneratePreAccountSiteJob` went RUNNING at 16:47:45. `[Deploy: 2365]
Worker Cluster cluster shut down` at 16:47:52 — **7 seconds in**. The job surfaced
`has been attempted too many times` at 16:58:52Z, 11 minutes later.

Meanwhile the public endpoint returned `build_state: "building"` continuously for the **6m20s** I polled
it, and the DB row still read `build_state: 'building'`, `failure_code: NULL`, `updated_at ==
created_at` when I queried at ~16:58Z. The user row was created with placeholder identity
(`display_name = first_name = 'jesshairstylist'`, `sector` NULL) and **zero** downstream state: no
`ingest.sources`, no `ingest.runs`, no `platform_connections`, no `content.items`, no observations.

This is a **known and handled** condition — `ReconcileStuckPreAccountBuilds` (LIFE-4) exists for exactly
it, and its docblock names the mechanism: *"killed mid-run (worker OOM/SIGKILL) without ever calling
`failed()` — its `ShouldBeUnique` lock (`uniqueFor=600s`) also blocks a same-build retry for up to 10
minutes."* That 600s lock is why my 6m20s of polling never saw a restart. The build would have been
marked `failed` by the scheduled sweep at `stuck_build_sla_minutes = 30`.

**What is worth recording is the visitor-facing shape, not the job death.** For up to 30 minutes a
signing-up visitor sees an indefinitely "building" site with no error and no progress, and the operator
sees a row that looks in-flight. The gap between the job's real death (16:58:52Z) and the row telling
the truth (30-minute SLA) is ~19 minutes of silent divergence.

**UNVERIFIED:** whether `failed()` ever fired and set `build_state='failed'`. My last read of that row
was at ~16:58Z, immediately *before* the 16:58:52Z exhaustion, and the row was purged at 00:04:59Z
before I thought to re-read it. I am not going to claim either way.

### R9 — `cost_claimed` disagrees with itself across identical runs
Build 1's Instagram run recorded `cost_claimed: 0`; builds 3, 4, 5 and 6 recorded `50` for the same
actor on the same code path. All five scraped successfully and returned 12 records. This matches the
standing note that `ingest.runs.cost_claimed` is not reliably written. **Do not use it to reconcile
Apify spend.** Five scrapes were billed by observation (five successful actor runs); the ledger claims
four.

### R12 — tracking parameters are rewritten to `[redacted]` on the public wire
Unchanged from RUN2's **N-D**. `themilleraffect`'s wire carries
`https://www.shopltk.com/explore/themilleraffect?utm_medium=[redacted]&utm_source=[redacted]&utm_campaign=[redacted]`,
and build 1's YouTube observation stored `?si=[redacted]`. The literal string `[redacted]` is served to
the visitor and would be sent to the destination if clicked. Recorded, not re-litigated.

### R10 — the cascade now works, and it is worth stating as a positive finding
This is the first run in the series where an auto-routed connection produced substantial downstream
content with no dashboard action: `kimcosmik` gained 11 Bandcamp releases and 23 YouTube videos, both
surfacing on the public wire as `listen` and `watch` pools. RUN2 saw this for a single Bandcamp
connection (3 releases); it now spans two platforms and two accounts each.

---

## 4. Explicitly correct-by-design — do not re-raise these

- **Two connections for one platform** when they are two different accounts (`kimcosmik` + `cybersoul`
  on Bandcamp/YouTube/Facebook). Per `ce5629190`. The prompt's §3.7 wording is out of date.
- **Pinterest → custom link.** Retired by owner decision 2026-07-28. `crucibletattooco`'s Pinterest link
  landed `unknown-domain` → custom link. Correct.
- **`bsky.app`, `paytherent.net.au`, `discogs.com`, `juno.co.uk`, `shopltk.com`, `poshmark.com`,
  `canva.link` → custom links.** No catalog definitions. Correct.
- **`facebook.com/groups/<id>/` not seeded as a profile.** Correct.
- **Bandcamp album/track deep links → custom links** while the artist root seeds a connection. Detector
  confidence 52 vs 60 against threshold. Correct.
- **`is_published: false` while the site serves 200.** SIGNUP-3, decided behaviour.
- **`site.site_media` empty / `site.media_variants` unused.** Media lives in `content.media_assets`.
- **`site.services` / `site.service_categories` absent from §4.** Those tables are dropped on dev; the
  prompt's §4 SQL would error. Services live in `content.*`.

## 5. Where the prompt is now stale

Five runs in, the prompt misdescribes current behaviour in four places. Recording them so run 6 does not
re-derive them:

1. **§3 "expect `routing.link_observations` empty"** — it is populated now (43 rows this run).
2. **§3.7 "exactly ONE row per platform"** — should be one row per *account*.
3. **§4's SQL** references `site.services`, `site.service_categories`,
   `site.service_category_assignments` — all dropped.
4. **Preconditions' egress-hash recipe** — produces a hash that matches nothing (see the banner).

Additionally, **`themilleraffect` is no longer a probe-exhaustion target.** Its Linktree now carries 4
links, not the ~10 the prompt describes. §3.9 cannot be tested with it. A denser account is needed.

---

## 6. Deliberate non-actions

Per rules 4 and 5: nothing was fixed, no job was re-run, no build was retried, no code, config or
migration was touched, and the branch `fix/r7-junk-jsonld-product-2026-08-18` was **not** merged (its
fix `deaba1a2b` is byte-identical to `3064f3644`, already on `development` and already deployed —
verified with `git log -S`). `jess.hair.stylist` was **not** re-run, on Josh's explicit answer.

## 7. Audit — what was deleted, and when

**One purge, 00:04:59–00:05:04Z**, via `AccountDeletionService::purge()` through
`cloud tinker development`, on Josh's pre-run answer ("Full six (delete the 3)") — the full teardown
path. Freeing the three cap slots batch B needed:

| handle | user_id | result |
|---|---|---|
| `simondoylehair` | `01a015c4-f962-7266-8362-5a288e16f0a6` | `true` |
| `jesshairstylist` | `01a015c5-94cb-70aa-85f6-2d788d934408` | `true` |
| `crucibletattooco` | `01a015cb-b08f-70a0-92e7-8171cf22d692` | `true` |

`exitCode: 0`. Verified independently rather than trusting the command's own report:

```
my_live_builds 0 | users_left 0 | handle_aliases 0 | items_left 0
sources_left 0 | observations_left 0 | media_assets_left 0
```

All batch-A evidence in §1–§6 was captured **before** the purge. `observations_left: 0` is the
positive confirmation of **R3**'s fix.

**Batch B is left LIVE and unclaimed**, as rule 4 requires: `kimcosmik`, `themilleraffect`,
`supernormal-180`.

### ⚠️ The purge turned out to be unnecessary, and that is a lesson for run 6

Batch B's three rows carry `created_ip_hash = c3cd8d49a8250f257f252ab17c5d4a9c36a67d6a8d179c68c919055f846127e7`
— **a different hash from batch A's `1044b245…`**. My egress IP rotated during the ~7h operator gap
between the batches. The cap is counted per hash, so batch B had **three free slots of its own** and
would have succeeded whether or not batch A was deleted.

I could not have known that at purge time, but I could have *found out for free*: POST build 4 first and
see whether it 429s. Purging pre-emptively cost three builds' worth of live evidence that could have
stayed on disk for comparison.

**For run 6: never purge to make room. Attempt the build, and only purge if it actually returns 429
`IP_BUILD_CAP`.** The cap is cheap to test and expensive to guess at — and on a residential connection
the hash is not stable across hours.

**Five Apify scrapes billed** (builds 1, 3, 4, 5, 6). Build 2 produced no `ingest.runs` row and appears
not to have reached the actor.

---

## 8. Batch B purged, 2026-08-19 09:43 UTC

Batch B was left live at §7 "as rule 4 requires". It was purged on Josh's explicit instruction to clear
`max_unclaimed_per_ip` so R17 could be verified with a fresh build. Final state captured immediately
before deletion, so every claim above stays checkable:

| `source_ref` | build_id | user_id | state | connections | `content.items` | observations | intents |
|---|---|---|---|---|---|---|---|
| `kimcosmik` | `01a01756-79f0-7201-994e-23b99ae16a07` | `01a01756-7992-737f-9996-17de121bfaab` | ready | 9 | 52 | 17 | 9 |
| `themilleraffect` | `01a01757-f4ae-7263-8a88-91ddb27fce4a` | `01a01757-f3e2-70e9-9fea-e6b957ce6213` | ready | 1 | 17 | 8 | 0 |
| `supernormal_180` | `01a01758-8ff9-7224-8682-cdcb30f24666` | `01a01758-8fd6-7066-bd3a-0b6dd0eba2ef` | ready | 1 | 13 | 0 | 0 |

All three created 2026-08-19 00:05–00:08Z, all expiring 2026-09-18. Purged via `builds:prune-expired`
(the sanctioned teardown — observer cascade, KV retire, cache purge) after setting `expires_at` into the
past. **The §4 cascade counts above (`kimcosmik`: 52 items across 9 connections) are the evidence for
R10 and are no longer re-derivable from the database.**
