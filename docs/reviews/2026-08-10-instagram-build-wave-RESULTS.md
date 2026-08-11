# Instagram (`partna`) build wave — verification report, 2026-08-10

Run window **10:20:39Z – 10:22:52Z**, dev only (`https://dev-api.partna.au`, Supabase `glncumufgaqcmqhzwrxm`).
Three builds attempted, three succeeded, none retried, none deleted. No code, config, migration or data
was changed; every job referenced below ran on its own during the wave.

**Preconditions re-checked before build 1.** Egress IP `150.228.243.132` →
`sha256 = 28a2b71d0d4730e305ba75f39630fda357ccf9a970e3b8233c2fec63d12d5b8b`, which held **0** rows in
`core.pre_account_builds where claimed_at is null` — cap (3) clear. Note this is *not* the hash quoted in
the prompt (`4147c0d0…`); that hash holds 0 live builds too, it simply belongs to a different origin.
`BOT_PROTECTION_*` and `PARTNA_WAITLIST_*` are unset on dev, so `config/partna.php:2347` (`mode='off'`)
and `:951` (`enabled=false`) apply and the plain `curl` passes both gates.

---

## 1. Summary

| # | `source_ref` | handle | subdomain | display_name | sector | links in | seeded | custom | conflict |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `simondoylehair` | `simondoylehair` | `simondoylehair` | `SIMON DOYLE \| Barber & Educator` | `hair-salon` / `instagram` | 3 | 3 | 0 | 0 |
| 2 | `jess.hair.stylist` | `jesshairstylist` | `jesshairstylist` | `Prahran Hairdresser` | `null` | 3 | 2 | 0 | 1 |
| 3 | `crucibletattooco` | `crucibletattooco` | `crucibletattooco` | `Crucible Tattoo Co.` | `null` | 11 | 1 | 9 | 1 |

| # | build_id | user_id | state | failure | wall clock |
|---|---|---|---|---|---|
| 1 | `019feb30-4f58-7319-a7b7-dc4f82922639` | `019feb30-4ee8-7032-a553-5fea6a729f93` | `ready` | – | **11s** |
| 2 | `019feb30-b3c8-716c-bdc6-4cbcb3a1e65e` | `019feb30-b3b6-7387-8c06-ec36c11144f7` | `ready` | – | **49s** |
| 3 | `019feb31-a592-735b-98e4-ca5191c4ebdc` | `019feb31-a556-72ff-85ac-2a1728132305` | `ready` | – | **22s** |

All three POSTs returned **202**; all three `GET /api/public/signup/builds/{id}` polls reached `ready`.
Job durations from the `scraping` queue (`cloud env:logs partna development`): `GeneratePreAccountSiteJob`
16s / 51s / 21s DONE.

Platforms connected: **6 real platform connections** (`fresha`×2, `tiktok`×2, `youtube`, `eventbrite`)
+ **3 `instagram`** source rows + **9 `custom`** link rows.

---

## 2. Per-account link ledger

### How the input list was established

`InstagramSourceGenerator.php:91` strips `bioLinks` / `syncFindings` / `unmatched` from the provisional
payload (PRIV-2), so the **bio-level** input list is not recoverable from stored state. What *is* recorded
is `payload.website`, and in all three cases it is a Linktree URL:

| # | `payload.website` |
|---|---|
| 1 | `https://linktr.ee/simondoylehair` |
| 2 | `https://linktr.ee/jess.hairstylist` |
| 3 | `http://linktr.ee/crucibletattooco/` |

Each matched `LinkInBioDetector`, dispatched `LinkInBioScanJob`, and nothing about the bio URL itself was
persisted — as designed. The ledgers below therefore enumerate the **Linktree page's off-host anchors**,
which is exactly the list `WebsiteLinkHarvester::allOutboundLinks()` feeds to `LinkRouter`. I reproduced
that extraction independently: parse `<a href>` only, absolutize, drop non-`http(s)` schemes, drop
same-host (`linktr.ee`) anchors, dedupe — matching `WebsiteLinkHarvester.php:423-451` and
`LinkInBioScanJob.php:96`.

> **Caveat, stated rather than glossed:** my fetch is a separate request made ~6 minutes after
> `SafeUrlFetcher`'s, with a different user-agent. Every anchor I extracted has a matching DB row and
> every DB row has a matching anchor, in all three accounts, so the two lists demonstrably agree — but
> the correspondence is inferred from that agreement, not from the job's own logged input list (the job
> does not log one).

### Account 1 — `simondoylehair`

Linktree `https://linktr.ee/simondoylehair`: **113 anchors, 3 off-host.**

| # | input URL | outcome | proof |
|---|---|---|---|
| 1 | `https://www.eventbrite.com.au/e/hobart-mens-hair-workshop-…-1993984195405?aff=oddtdtcreator` | **seeded** | `site.platform_connections` `platform=eventbrite`, `resource_id=event-ba7bc4f70f505571`, created `10:20:56Z` |
| 2 | `https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260` | **seeded** | `platform=fresha`, `resource_id=fresha`, payload `{url, source:"instagram", selection:null}` |
| 3 | `https://youtube.com/@dvlpmnttv?si=y6yPIR5r27b_P9C1` | **seeded** | `platform=youtube`, `resource_id=youtube`, payload `{url, source:"instagram", username:""}` |

**3 in = 3 seeded + 0 custom + 0 skipped + 0 pending + 0 denied. Balances.**
`custom_links = 0`, and `CommerceProbeJob` never ran in this window — consistent with all three
classifying.

### Account 2 — `jesshairstylist`

Linktree `https://linktr.ee/jess.hairstylist`: **113 anchors, 3 off-host.**

| # | input URL | outcome | proof |
|---|---|---|---|
| 1 | `https://www.fresha.com/book-now/jess-hairstylist-v8ct52bl/all-offer?share=true&pId=2857759` | **seeded** | `platform=fresha`, payload `{url, source:"instagram", selection:null}` |
| 2 | `https://www.tiktok.com/@jess.hairstylist?_t=zs-8tzcgwneuew&_r=1` | **seeded** | `platform=tiktok`, payload `username:"jess.hairstylist"` |
| 3 | `https://www.instagram.com/jess.hair.stylist?igsh=…&utm_source=qr` | **conflict** | `payload.syncFindings[2].outcome = "conflict"`, `apply.remove:["instagram"]`. No row written — correct, the `instagram` slot is already the source connection |

**3 in = 2 seeded + 1 conflict. Balances.**

### Account 3 — `crucibletattooco`

Linktree `https://linktr.ee/crucibletattooco`: **121 anchors, 11 off-host.**

| # | input URL | outcome | probe? | proof (`resource_id`) |
|---|---|---|---|---|
| 1 | `https://www.crucibletattooco.com.au/` | custom | ✔ | `link-984c89fffb758fbd` |
| 2 | `https://www.crucibletattooco.com.au/appointment.html` | custom | ✔ | `link-1fe180a294772f04` |
| 3 | `https://www.crucibletattooco.com.au/artists.html` | custom | ✔ | `link-8b18600dc8df3e1b` |
| 4 | `https://www.crucibletattooco.com.au/aftercare.html` | custom | ✔ | `link-a840f3244206d032` |
| 5 | `https://www.crucibletattooco.com.au/accessibility.html` | custom | ✔ | `link-e86926704b067d55` |
| 6 | `https://www.crucibletattooco.com.au/feedback.html` | custom | ✔ | `link-2ec055add9ec9969` |
| 7 | `https://paytherent.net.au/` | custom | ✘ **budget spent** | `link-f8b4ad8f880c24b4` |
| 8 | `https://bsky.app/profile/crucibletattooco.bsky.social` | custom | ✘ **budget spent** | `link-6e3ad4eb3390f71b` |
| 9 | `https://www.instagram.com/crucibletattooco/` | **conflict** | n/a | `payload.syncFindings[0].outcome="conflict"`; no row |
| 10 | `https://au.pinterest.com/crucibletattooco_/` | custom | ✘ **budget spent** | `link-1bfb8576a64061f7` |
| 11 | `https://tiktok.com/@crucibletattooco` | **seeded** | n/a | `platform=tiktok`, `username:"crucibletattooco"` |

**11 in = 1 seeded + 1 conflict + 9 custom. Balances. Nothing unaccounted for.**

Probe accounting: 9 of the 11 links are unclassified. `RouteContext::DEFAULT_MAX_PROBES = 6`
(`RouteContext.php:24`), and the logs show **exactly 6 `CommerceProbeJob` runs**, all DONE, between
`10:22:31` and `10:22:33`. So **3 unclassified links (#7, #8, #10) were routed to custom with no commerce
probe.** They still became custom links — nothing vanished — but the cap is silent. Nine
`EnrichLinkCardJob` runs follow, one per custom link, which is why rows #7/#8/#10 still carry
`name`/`favicon`/`description`.

**Facebook is not an input.** `https://www.facebook.com/crucibletattooco` appears in the page's embedded
JSON but is **not an `<a href>`**, so `extractLinks()` never sees it. The prompt's expectation of a
Facebook link is not borne out; there is nothing missing here.

---

## 3. Section-by-section results

### §1 — Identity and handle

| # | Check | Result | Evidence |
|---|---|---|---|
| 1.1 | IG username → suggested handle | **PASS** | `simondoylehair`→`simondoylehair`; `jess.hair.stylist`→`jesshairstylist`; `crucibletattooco`→`crucibletattooco` |
| 1.2 | `handle` == `subdomain` | **PASS** | All three identical, incl. the two-period case: `core.users.handle_lc='jesshairstylist'` == `site.sites.subdomain='jesshairstylist'` |
| 1.3 | `display_name` is a real name, not the handle | **PASS** | `SIMON DOYLE \| Barber & Educator`, `Prahran Hairdresser`, `Crucible Tattoo Co.` — all the IG `fullName`, none the handle |
| 1.4 | `first_name` populated sensibly | **PARTIAL** | `SIMON` ✓; `Prahran`, `Crucible` are `Str::before($fullName,' ')` on non-person names |
| 1.5 | IG category → sector | **PARTIAL — 1 of 3** | `simondoylehair`: `sector='hair-salon'`, `sector_source='instagram'`. Other two: both `null` |
| 1.6 | Contact fields folded | **FAIL** | `site.workplaces` = **0 rows** for all three |

**1.2 — SIGNUP-1 is fixed.** `jess.hair.stylist` is the exact divergence trigger (two periods;
`Str::slug()` drops them, `subdomainBaseFromHandle()` replaces them with hyphens). The
expected-if-broken outcome was handle `jesshairstylist` vs subdomain `jess-hair-stylist`. Observed:
both `jesshairstylist`. The fix at `PreAccountBuildService.php:131-134` — passing `$user->handle_lc` to
`createSiteForHandle()` instead of re-deriving from the seed — is holding on the real trigger case.

**1.3 — SIGNUP-2 is fixed.** The 2026-08-05 "before" for `simondoylehair` was
`display_name = "simondoylehair2"` with `fullName: null`. The "after" is
`display_name = "SIMON DOYLE | Barber & Educator"` and `payload.fullName` populated on all three
accounts. The regression does not reproduce.

**1.5 — `sector_source = 'instagram'` has now succeeded on dev for the first time.** This was a genuinely
open question and it came out **positive, but only for 1 of 3**. `InstagramIdentitySync::applySector`
reads the raw node's `businessCategoryName` and runs it through
`SectorTaxonomy::fromInstagramCategory()`, an ordered substring match:

- `"Hair Stylist"` → keyword `'hair'` (`SectorTaxonomy.php:141`) → `hair-salon` ✓
- `"Artist"` → no match; the taxonomy has `'art gallery'` and `'gallery'` (`:155-156`) but no bare `'artist'` → `null`
- `"None"` → no match → `null`

Worth recording: `'tattoo' => 'tattoo-artist'` **does** exist (`:144`). `crucibletattooco` would have
mapped cleanly — the taxonomy is not the gap there, the scrape returning `businessCategory: "None"` is.

**1.6 — CLOSED 2026-08-11: empty by design, not unverified.** *(Superseded — the original text is kept
below the rule for the record.)*

`applyContactFields` never sees the stored payload. `InstagramConnectionSeeder.php:214` passes the **raw
Apify item** (`$profile`) into `applyIdentity()`; the `payload` column holds `$selection`, a 12-key
hand-built projection (`InstagramConnectionSeeder.php:153-191`). So the payload key set below could not
have shown these keys even if the actor had sent them — the original reasoning inspected the wrong
object. (The tell was in this same report: the payload carries `businessCategory`, but `applySector`
reads `businessCategoryName`, and sector sync worked.)

Re-run against the **raw dataset items of these actual runs**, pulled from Apify run history 2026-08-11:

- **apify actor** (live for this wave): no contact key of any spelling. 25 keys, none of them email/phone.
  The actor's published output schema has none either.
- **figue actor** (the previous default, last live run 08-10 08:24): the keys **are** present —
  `business_email`, `business_phone_number`, `business_contact_method`, `should_show_public_contacts` —
  and for `simondoylehair` came back `business_email: null`, `business_phone_number: null` while
  `should_show_public_contacts: true` and `business_contact_method: "TEXT"`.

Instagram is confirming the contacts exist and withholding the values, because both actors read the
**logged-out** endpoint. No actor swap changes this. Neither does the official API: Graph
`business_discovery` returns no email/phone for a third-party handle, and for an owner-authorised
account they live on the linked Facebook Page, not the IG node — and the pre-account flow scrapes handles
nobody has authorised.

**Disposition: contact details come from the person at signup/claim, and phone additionally from Google
Business (`IdentitySync.php:69`; email is manual-only from every source — `IdentitySync.php:61`).** No
fixture test was added: it would pin a fold that is already covered (`InstagramIdentitySyncTest.php:86-112`)
while implying the gap is closeable from a scrape. The finding is recorded in the code at
`InstagramIdentitySync::applyContactFields`.

> *Original text, 2026-08-10:* **1.6 — still never succeeded.** `applyContactFields`
> (`InstagramIdentitySync.php:83-95`) reads `businessEmail`/`business_email` and
> `businessPhoneNumber`/`business_phone_number`. **None of those four keys is present in any of the three
> payloads** (full key set below), so the method returns early at the `$email === null && $phone === null`
> guard. Zero workplace rows. The code's own comment already calls this path "DEFENSIVE, not a demonstrated
> fix" — this run neither demonstrates nor refutes it, because the actor supplied nothing to fold.

### §2 — The scrape itself

Full payload key set, identical on all three:
`_folder, _mediaDiagnostics, businessCategory, followersCount, fullName, images, mode, postsCount,
profilePicUrl, syncFindings, username, videoPoster, videoUrl, website`

> **READ THIS BEFORE DRAWING A CONCLUSION FROM THE KEY SET ABOVE (added 2026-08-11).** That is
> `$selection`, a hand-built 12-key projection (`InstagramConnectionSeeder.php:153-191`) — **not** what the
> actor returned. The raw item is passed separately to `applyIdentity()` and never persisted. Absence of a
> key here is evidence about the projection only. Two conclusions in the original report were drawn this
> way; §2.2 was wrong as a result. The raw items are recoverable for free from Apify run history
> (`/v2/acts/<actor>/runs` → `defaultDatasetId` → `/v2/datasets/<id>/items`), which is how this was settled.

| # | Check | Result | Evidence |
|---|---|---|---|
| 2.1 | Profile fields captured | **PARTIAL** | see table below |
| 2.2 | `biography` present | ~~CONFIRMED ABSENT~~ → **PRESENT (corrected 2026-08-11)** | The raw item carries `biography`, `externalUrl` AND `externalUrls`; none is copied into `$selection`, which is why it looked absent. `bioLinks()` is therefore live, not vacuous. |
| 2.3 | Profile picture mirrored | **PASS** | all 3 `profilePicUrl` return **HTTP 206 `image/jpeg`** on range request |
| 2.4 | Post media mirrored | **FAIL (as rows)** | `site.site_media` = **0 rows** for all three |
| 2.5 | Every media row has a `webp` variant | **VACUOUS** | 0 media rows ⇒ 0 rows without a variant |
| 2.6 | Gallery ≤ 6 | **PASS (vacuously)** | gallery = 0; `curatedGallery` = `[]` on all three public profiles |

| field | `simondoylehair` | `jesshairstylist` | `crucibletattooco` |
|---|---|---|---|
| `fullName` | ✓ | ✓ | ✓ |
| `businessCategory` | `Hair Stylist` | `Artist` | **`None`** |
| `followersCount` | 11066 | 4160 | 30042 |
| `postsCount` | 365 | 108 | **`null`** |
| `images` length | 1 | 1 | **0** |
| `videoUrl` | ✓ mp4 | ✓ mp4 | **`null`** |
| `_mediaDiagnostics.posts` | 12 | 12 | **0** |

**The §2.4 question — "does `images` only hold the profile pic, or is media capture under-filling?" —
answers as: neither, exactly.** `images` holds *picked post photos*, not the profile pic (which has its
own `profilePicUrl` key). `_mediaDiagnostics` shows the actor returned **12 posts** for accounts 1 and 2
and the generator picked **1 photo + 1 reel** from them — that is a deliberate 1-of-12 selection, not a
capture failure. What *is* a gap is that **none of it lands in `site.site_media`**: the files are mirrored
to Laravel Cloud object storage and referenced only as URLs inside `platform_connections.payload`. So
`site.site_media`, `site.media_variants`, the `webp` variant rule and the
`core.enforce_site_gallery_max6` trigger are all untested by this path — there is nothing for them to act
on.

Note on wording: the mirrored host is `fls-a1334790-8631-448b-9b55-dcbd64ec0c65.laravel.cloud`, i.e.
Laravel Cloud object storage. Whether that bucket is R2-backed is **unverified** — `AWS_ENDPOINT` /
`AWS_URL` are masked by the Cloud CLI and I did not reveal them.

`crucibletattooco` is a separate failure: the actor returned **0 posts** (`_mediaDiagnostics.posts: 0`,
`postsCount: null`, `images: []`, `videoUrl: null`) for a 30k-follower account that visibly has posts.
Only the profile picture was mirrored.

### §3 — Link routing

**Which router ran: `app/Services/Platforms/LinkRouter` (legacy).** Confirmed by the call chain
`InstagramConnectionSeeder:201 → InstagramAutoSync::seed → LinkRouter::route`, and by
`LinkInBioScanJob:105 → $router->route(...)`.

`routing.link_observations` is **0 rows for all three users** — checked and **expected**, not a finding.
`LinkRouter` writes no observation rows; only `App\Routing\LinkRoutingService` does, and it is reached
only from `RoutingController` and the importers. Evidence for routing decisions in this report is the
seeded rows themselves plus the `scraping`-queue job log.

| # | Check | Result | Evidence |
|---|---|---|---|
| 3.1 | Every classified platform link produced a connection row | **PASS** | eventbrite, fresha×2, youtube, tiktok×2 all present; the two `instagram` self-links correctly produced conflicts, not rows |
| 3.2 | Every unclassified link became a custom link | **PASS** | 9 unclassified for account 3 → 9 `platform='custom'` rows. Accounts 1–2 had none |
| 3.3 | Shop/unclassified links dispatched `CommerceProbeJob` within budget | **PASS, with a silent cap** | exactly 6 probes logged; 3 links past budget |
| 3.4 | A probe resolving a product page produced an item | **UNVERIFIED — no such case arose** | `content.items` = 0 for all three; all 6 probes returned in ~330ms with no item/link rows |
| 3.5 | A probe resolving a storefront produced a shop connection | **UNVERIFIED — no such case arose** | no `shop` connection written; none of the 6 probed URLs is a storefront |
| 3.6 | Input == seeded + custom + skipped + pending + denied | **PASS — balances for all three** | 3=3, 3=3, 11=11 (ledgers above) |

**§3c — link-in-bio unroll.** `LinkInBioScanJob` was dispatched and ran for **all three** accounts
(`10:20:56` 384ms DONE, `10:21:56` 3s DONE, `10:22:30` 1s DONE), each immediately after its
`GeneratePreAccountSiteJob`. Every link inside each Linktree got an outcome — see the ledgers; all three
balance.

Bluesky landed as a custom link, as predicted. Pinterest landed as a custom link — **correct by
decision**, the platform was retired 2026-07-28 (`LegacyPlatformMap.php:117-121`), not a gap. Bookwell
did not appear on any of the three pages, so that prediction was untested.

**§3d asymmetry worth recording (not a data loss):** the `eventbrite` connection row exists, but there is
**no corresponding entry in `payload.syncFindings`**, while fresha/youtube/tiktok all have one. Cause is
in the code, not the data: `LinkRouter::seedEvent` (`:210-223`) returns
`RouteResult::seeded($platform, $platform, $category)` — and `RouteResult::seeded`'s `$findings`
parameter defaults to `[]` (`RouteResult.php:47`). Events are structurally incapable of emitting a
finding. The connection is written either way, so nothing is lost; the synced-modal list simply
under-reports events.

### §4 — Does the loop continue? (cascade)

| # | Check | Result | Evidence |
|---|---|---|---|
| 4.1 | Any seeded connection dispatched a `ConnectFetchJob`? | **NO** | zero `ConnectFetchJob` entries across the whole 10:20:30–10:22:45 log window |
| 4.2 | Seeded Fresha connection fetched its service menu? | **NO** | both `fresha` payloads are bare: `{url, source:"instagram", selection:null}` |
| 4.3 | Services projected into `site.services`? | **NO** | 0 rows, all three users |
| 4.4 | Categories / assignments projected? | **NO** | 0 / 0, all three users |
| 4.5 | Any URL inside a connection's payload got routed? | **NO** | no second-order rows; `custom` rows all trace to Linktree anchors |

**The prompt's hypothesis is confirmed, and I verified it rather than assuming it.** The three dispatch
sites for `ConnectFetchJob` are `GenericPlatformController.php:180`, `EventsController.php:48` and
`DefersBespokeConnect.php:97` — all three are dashboard connect-flow controllers. Nothing on the
auto-route path dispatches it, matching `BuildsAutoSyncFindings`' own statement for the booking slot
("no vendor call, no dispatch, DB only"). `FreshaServiceProjector::sync()` hangs off that fetch, so it
never runs.

**Result: an auto-routed Fresha connection is a bare `{url, provider, source}` row with no services and
no categories.** So the loop does *not* continue: a connection seeded by the router is terminal.

The contrast is **not** simply "dashboard good, auto-route bad" — it is three-way, and only one of the
three projects services on its own (`FreshaConnectFetch.php:86-133`):

| how the row is born | writes `payload.selection`? | services projected? |
|---|---|---|
| dashboard connect, **storewide** mode | yes, composed from the scrape | **yes**, `fetchStorewide()` calls `projector->sync()` |
| dashboard connect, **team** mode (the default, `$payload['connectMode'] ?? 'team'`) | **no** — deliberately | no, not until the user picks a team member via `saveSelection()` |
| **auto-route** (`LinkRouter::seedBooking`) | no | no, and no picker is ever offered |

Team mode leaving `selection` null is intentional and documented at `FreshaConnectFetch.php:88-101`:
*"writing a real `selection` here would make FreshaFetch stop 304ing this row."* The 304 guard is
therefore a **correct** guard protecting a legitimate "waiting for the user to choose" state — not a bug
in itself.

**And the scheduled refresh cannot rescue it either.** `fresha` *is* registered `->refreshable()`
(`PlatformRegistryServiceProvider.php:330`) with a 2-day interval (`:337`,
`config('partna.refresh.intervals.fresha')`), and `integrations:refresh` runs `hourlyAt(23)`
(`routes/console.php:157`) — so these two rows **will** be picked up once they age past the TTL, and
`FreshaFetch` **does** hold the projector. But `FreshaFetch::fetch()` opens with:

```php
$selection = $payload->selection?->toArray();
if (! $url || ! is_array($selection)) {
    throw new FetchNotModifiedException('fresha');   // FreshaFetch.php:36-39
}
```

Both auto-routed payloads are `selection: null`, so every future refresh 304s at that guard before
reaching `FreshaServiceProjector::sync()`. `selection` is only ever written by the dashboard's
save-selection flow, and the descriptor's own completeness predicate agrees these rows are incomplete:
`complete(fn ($c) => is_array($c->payload['selection'] ?? null))` (`:365`).

So the correct statement is stronger than "no fetch at connect time": **an auto-routed Fresha connection
can never acquire services by any automatic path.** It will be re-selected by the refresher every two
days, 304, and stay empty until a human opens the dashboard and picks storewide-or-employee. Established
by reading the code, not by waiting out the 2-day TTL.

**The precise defect is a state collision, not a missing fetch.** `selection: null` is the encoding of
"a human still has to choose whose menu this is". Team-mode dashboard connects sit in that state
legitimately — but they also carry a `teamMenu` snapshot and a picker UI pointed at them, so the choice
actually gets made. The auto-routed row lands in the *same* state with **no `teamMenu` and no picker** —
its payload is only `{url, source, selection: null}`. It is parked in "waiting for a human" while no
human has been, or will be, asked. Nothing distinguishes it from a row that is merely mid-flow, so
nothing surfaces it.

**Quantification is UNVERIFIED.** I could not establish how many services Anseo Studio
(`anseo-studio-v0v92jna`) and `jess-hairstylist-v8ct52bl` actually list on Fresha. Both booking pages
return HTTP 200 but are entirely client-rendered — the initial HTML (36.8 KB / 37.6 KB) contains no
`serviceId`, no `services` key, no `itemListElement`, and no name/price pairs. Establishing the real
count needs either a headless browser or the Fresha fetch path itself, and running the latter would have
meant changing state, which this report is not permitted to do. The *gap* is confirmed; its *size* is not.

### §5 — Auto-signup rules

| # | Check | Result | Evidence |
|---|---|---|---|
| 5.1 | IG auto-media / latest-media rule enabled on create | **PASS** | `display_settings` is `NULL` on all 9 connections; `AutoSyncSetting::isOn` treats an absent `auto_sync_latest` key as ON (`AutoSyncSetting.php:41`) |
| 5.2 | `is_published` is `false` | **PASS** | `false` on all three `site.sites` rows |
| 5.3 | Site nonetheless publicly reachable | **PASS** | `https://{simondoylehair,jesshairstylist,crucibletattooco}.partna.au/` → **200** each |
| 5.4 | KV entry written | **PASS** | `SyncSubdomainToKvJob` RUNNING→DONE at `10:20:43` (959ms), `10:21:10` (767ms), `10:22:13` (928ms) — one per build; the 200s in 5.3 are only reachable through a KV hit |
| 5.5 | `GET /api/public/profiles/<handle>` returns built content | **PASS** | 200 each; `architectureId: "staple"`, `designKit` present (`colors`, `typography`), `rankedActions` populated |
| 5.6 | `status = 'unclaimed'` | **PASS** | all three |

5.2 + 5.3 together are SIGNUP-3 behaving as decided: pre-account sites are public pre-claim,
`is_published` is a dashboard flag. Confirmed, not flagged.

`rankedActions` renders correctly: `simondoylehair` shows *Booking and services* (external), *Events*
(page), *Instagram*; `crucibletattooco` shows its 9 custom links. Note `profile.links` is `[]` on both
while `rankedActions` carries the links — the custom links surface through `rankedActions`, not
`profile.links`.

### §6 — Errors and noise

**Zero exceptions, zero failed jobs, zero 5xx across the whole run window.** Every job logged
`RUNNING → DONE`. Job inventory for 10:20:30–10:22:45:

| job | runs | outcome |
|---|---|---|
| `GeneratePreAccountSiteJob` | 3 | DONE (16s / 51s / 21s) |
| `LinkInBioScanJob` | 3 | DONE (384ms / 3s / 1s) |
| `CommerceProbeJob` | 6 | DONE (~330ms each) |
| `EnrichLinkCardJob` | 9 | DONE |
| `SyncSubdomainToKvJob` | 3 | DONE |
| `CloudflareCachePurgeJob` | 14 | DONE |
| `CheckStreamingLiveStatusJob` | 2 | DONE (scheduled, unrelated) |
| `RefreshConnectionJob` | 3 | DONE (scheduled, unrelated) |

**One repeated warning, unrelated to this run:** `POST /api/public/analytics/ping 404` fires roughly every
25 seconds throughout the window from `150.228.243.132` with a Chrome 151 user-agent — a browser tab, not
the `curl/8.7.1` requests of this wave. The route does not exist. It predates and outlasts the run and is
pure log noise, but it is a standing 404 warning stream on dev.

**Two `error waiting on adopted process` entries at 10:30:04Z and 10:30:08Z** — `level: error`,
`type: system`, i.e. a Laravel Cloud process-supervisor message, not an application exception. They
immediately follow `analytics:compute-popularity completed` on the 10:30 scheduler tick. They are
**novel**: zero occurrences in the 50 minutes before the run (09:30→10:20:30 scanned in four windows),
and none since (10:31→11:21 clean). No stack trace, no failed job, no state change. Cause **unverified** —
`analytics:compute-popularity` runs every 15 minutes and produced no such error on the 10:00, 10:15 or
11:00 ticks. Recorded because it is unexplained and new, not because it is attributable to this wave;
7.5 minutes separate it from the last build job.

**Log-tool caveat:** `cloud env:logs --minutes N` caps at **100 records**, which silently truncated the
window to 10:22:34 onward on the first pull. The per-build traces above come from narrow
`--from`/`--to` windows (29 / 33 / 66 records each) to stay under the cap. Anyone re-running this should
not trust a single wide `--minutes` call to be complete.

---

## 4. Findings

**F1 — `syncFindings` is written back into the provisional payload after PRIV-2 strips it.**
`InstagramSourceGenerator.php:91` removes `bioLinks`, `syncFindings` and `unmatched`. But
`LinkInBioScanJob::mergeFindingsBack` (`:187`) then does
`forceFill(['payload' => [...$payload, 'syncFindings' => …]])->saveQuietly()` *after* the generator has
finished. All three accounts have a link-in-bio page, so **all three carry `syncFindings` in the pre-claim
payload**. Evidence: the payload key set contains `syncFindings` but not `bioLinks` or `unmatched` — the
strip demonstrably ran, and one of the three keys came back. For accounts 2 and 3 the restored data
includes the full `apply.write.payload` block with the account's own Instagram URL.

**F2 — no post media reaches `site.site_media`.** 0 rows for all three, so `site.media_variants`, the
`webp` variant rule and the `enforce_site_gallery_max6` trigger are all untouched by this pipeline.
Mirrored files exist and are served (HTTP 206) but only as URLs inside `platform_connections.payload`.
Public `profile.gallery` is `[]` on all three.

**F3 — the commerce-probe budget silently dropped 3 of `crucibletattooco`'s 9 unclassified links.**
6 probes ran (`DEFAULT_MAX_PROBES = 6`); `paytherent.net.au`, the Bluesky profile and the Pinterest
profile got none. All 9 still became custom links, so nothing vanished — but nothing records that three
links went unprobed. Stating it here because the prompt is right that it must not stay hidden.

**F4 — the `crucibletattooco` scrape returned zero posts.** `_mediaDiagnostics.posts: 0`,
`postsCount: null`, `images: []`, `videoUrl: null`, `businessCategory: "None"`, on a public account with
30,042 followers. Accounts 1 and 2 got 12 posts each from the same actor in the same wave. Only the
profile picture was captured. This one account got a materially thinner scrape than the other two.

> **CORRECTED 2026-08-11 — transient, not account-specific.** Raw Apify run history for the *same
> account*: **10:01 → `postsCount` 4164, 12 posts. 10:22 (the run this build persisted) → `postsCount`
> key absent, 0 posts. 08-11 01:56 → 4164, 12 posts.** Two of three runs in the same hour were fine. So
> it is per-run actor flakiness, and the build simply persisted the bad one. The real gap this exposes is
> that **nothing treats a zero-post result on a 4,164-post account as suspect** — there is no sanity check
> and no retry, so a flaky run is written as though it were the truth. That is worth a finding on its own;
> "this account scrapes thin" is not.

**F5 — sector landed for 1 of 3.** `hair-salon`/`instagram` for account 1 — the first time
`sector_source='instagram'` has appeared on dev. Accounts 2 and 3 are `null` because `"Artist"` and
`"None"` match no keyword. The taxonomy *does* carry `'tattoo' => 'tattoo-artist'`, so account 3's miss is
caused by F4's empty category, not by a taxonomy gap.

> **WHY IT HAD NEVER SUCCEEDED BEFORE — and a rollback hazard, found 2026-08-11. FIXED.** Not a
> coincidence of timing: the **figue** actor returns `business_category_name: null` and puts the value in
> **`category_name`**, which `applySector` did not read (its last live run, 08-10 08:24, `simondoylehair`:
> `business_category_name: null`, `category_name: "Hair Stylist"`). Sector sync was structurally
> impossible under figue and started working the moment the 08-10 swap to the apify actor landed.
> `PARTNA_INSTAGRAM_ACTOR` is documented as a **no-deploy env rollback**, so rolling back would have
> silently switched sector detection off again with a green suite.
> **Fixed (user row):** `category_name` added as a third candidate in `InstagramIdentitySync::applyIdentity()`.
> The chain takes the first candidate that **maps**, not the first that is non-null, because Instagram
> returns the literal string `"None"` (F4's `crucibletattooco`) which would otherwise win and then fail to
> map. Covered by `InstagramIdentitySyncTest.php` (3 cases) and end-to-end in `InstagramAsyncConnectTest.php`.
>
> **OUTSTANDING (stored payload):** `InstagramConnectionSeeder`'s `businessCategory` needs the same third
> candidate or the payload still blanks on rollback. **Not done here** — that expression is owned by the
> live `fix/sector-detection-repair` worktree, which has already wrapped it in a `categoryOrNull()`
> placeholder filter. It belongs inside that wrapper, on that branch:
> ```php
> 'businessCategory' => $this->categoryOrNull(
>     data_get($profile, 'businessCategoryName')
>         ?? data_get($profile, 'business_category_name')
>         ?? data_get($profile, 'category_name')   // ← add this line
> ),
> ```

**F6 — no contact fields, no workplace rows. CLOSED 2026-08-11 — not a gap.** The original wording
("absent from every payload") inspected `$selection`, not the raw actor item `applyContactFields`
actually reads. Settled against raw run history: Instagram withholds business email/phone from
logged-out viewers — the figue actor returns the keys as `null` while reporting
`should_show_public_contacts: true`, and the apify actor omits them entirely. No actor swap and no
official API closes it for an unauthorised third-party handle. Contact details come from the person at
signup/claim (phone also from Google Business). Full reasoning in §1.6; recorded in the code at
`InstagramIdentitySync::applyContactFields`. **Nothing to build, nothing to wait for.**

**F7 — auto-routed connections are terminal; the loop does not continue, and no scheduled refresh will
fix it.** No `ConnectFetchJob`, no `FreshaServiceProjector`, 0 services, 0 categories, 0 assignments —
all three dispatch sites are dashboard controllers. The hourly `integrations:refresh` *does* cover
`fresha` on a 2-day TTL, but `FreshaFetch.php:36-39` throws `FetchNotModifiedException` whenever
`payload.selection` is not an array, and the auto-routed rows are `selection: null` — so the refresh
304s before it reaches the projector, permanently. Services can only ever arrive via a human dashboard
selection. Size of the gap (services actually listed on Fresha) unverified — both Fresha pages are
client-rendered.

**F8 — event seeds cannot emit a finding.** `LinkRouter::seedEvent` returns `RouteResult::seeded(...)`
without findings, and that parameter defaults to `[]`. The `eventbrite` connection row exists, so no data
is lost, but the synced-modal findings list under-reports events by construction.

**F9 — `PreAccountBuild::scopeLive()` ignores `expires_at`.** Re-confirmed at
`app/Models/Core/User/PreAccountBuild.php:99-102`: the scope is `whereNull('claimed_at')` only. Unclaimed
builds hold per-IP cap slots past expiry. Pre-existing, carried over from the prompt, unchanged by this run.

- [x] **F9** · P3 — expired unclaimed builds still occupy a per-IP cap slot · **OPPORTUNISTIC (triage applied 2026-08-11): no scheduled work.** Fix in-passing, in the same commit, the next time `PreAccountBuild.php` / `PreAccountBuildService.php` is open for real work — per the standing rule in `CLAUDE.md`. The rule is what carries it forward, not the checkbox.

  **Premise verified 2026-08-11, impact downgraded.** The mechanism is real and was reproduced (temporary Pest test: `max_unclaimed_per_ip=1`, one build force-expired 90 days, second build from the same IP hash → `PreAccountBuildException`). The prompt's "hold cap slots **forever**" is wrong: `builds:prune-expired` runs `dailyAt('03:40')` (`routes/console.php:277`) and hard-deletes expired unclaimed rows, so the ceiling is **~28h**, not unbounded. Against a 30-day `expiry_days` that is a ~4% extension of a lockout that is *working as designed* — the cap is supposed to block that IP for 30 days. Failure mode is a clear 4xx, no data loss, self-heals overnight. Only `PreAccountBuildController:39` ever passes an `ipHash`; staff builds and `EarlyAccessService:77` pass `null`, so early-access rows (`expires_at IS NULL`) can't hold a slot at all.

  **The repair is booby-trapped — this is the load-bearing part of the finding.** Do **not** add `expires_at` to `scopeLive()`. `findLive()` dedupes through that same scope and it deliberately mirrors `pre_account_builds_live_source_unique ... WHERE (claimed_at IS NULL)` (baseline `:2923`). Desync them and: `findLive()` misses the expired row → INSERT → `23505` → the `catch (UniqueConstraintViolationException)` recovery calls `findLive()` again → still `null` → the visitor gets an unrecoverable `SOURCE_REF_INVALID` *"Could not create the build. Try again."* on that source ref until 03:40. That trades a 28h cap inflation for a 28h outage. **The default test lane cannot catch it:** `setupPreAccountBuildsTable()` (`tests/Pest.php:547-565`) builds a permissive SQLite stand-in with **no unique index**, so the duplicate INSERT just succeeds and the suite goes green.

  **Correct shape when absorbed:** leave `scopeLive()` alone (plus a comment recording the trap above — that comment is worth more than the behaviour change) and give the quota its own scope, `scopeCountsTowardIpQuota()` = `live()` + `(expires_at IS NULL OR expires_at > now())`, called from `PreAccountBuildService.php:102`. No migration; `pre_account_builds_ip_idx` still serves the query. Keep *failed* builds counting even though the sweep also deletes them past `failed_prune_hours` — a failed build is re-servable by `reserve()` and still owns an allocated handle, so excluding it would cut the churn floor from 3 sites/30d/IP to 3/24h and forcing a failure is trivial. Expired means gone; failed means try again.

  **Two loose ends deliberately not folded in:** (1) the genuinely unbounded case is a candidate that throws on *every* prune run (`PruneExpiredPreAccountBuilds.php:159-168` catches per-candidate and continues) — watch for repeating `pre_account.prune.candidate_failed` in Nightwatch rather than pre-hardening the quota against it; (2) nothing in the default suite verifies that PHP scopes agree with Postgres *partial* indexes, which is an assurance hole wider than this finding and outlives it.

---

## 5. Explicitly correct-by-design — do not re-raise

- **`routing.link_observations` empty (0 rows × 3).** `LinkRouter` writes none; only `LinkRoutingService`
  does, and the Instagram path never reaches it. Checked and expected.
- **`bioLinks` / `unmatched` absent from the payload.** PRIV-2 data minimisation,
  `InstagramSourceGenerator.php:91`. (`syncFindings` coming back is F1 — that part is *not* by design.)
- **Pinterest → custom link.** Deliberately retired 2026-07-28, `LegacyPlatformMap.php:117-121`.
- **The two `instagram` self-links → conflict with no row.** The `instagram` slot is already held by the
  source connection; a conflict offers the swap and writes nothing.
- **`is_published = false` while the site returns 200.** SIGNUP-3. Pre-account sites are public pre-claim;
  `is_published` is a dashboard flag, not a visibility control.
- **Facebook "missing" from `crucibletattooco`.** It is not an `<a href>` on the Linktree page, only
  embedded JSON, so it was never an input.
- **Gallery-max-6 trigger and `webp` variant rule untested.** Vacuous, not failing — there are no media
  rows to constrain (see F2).
- **`site.workplaces` empty** — ~~F6, a real gap~~ **correct and permanent on the Instagram path**
  (corrected 2026-08-11). Instagram does not disclose business email/phone to logged-out viewers, so the
  early return is the only reachable outcome. See F6 / §1.6.

---

## 6. Async re-check at 11:21Z (+58 min)

The first pass sampled state ~7 minutes after the last build. Several steps in this pipeline are async, so
everything material was re-queried at **11:21:00Z**, 58 minutes after `crucibletattooco` reached `ready`.

**Nothing has changed.** Every count is byte-identical to the first pass:

| metric | all three accounts, 10:29Z | all three accounts, 11:21Z |
|---|---|---|
| `site.site_media` | 0 / 0 / 0 | **0 / 0 / 0** |
| `site.workplaces` | 0 / 0 / 0 | **0 / 0 / 0** |
| `site.services` | 0 / 0 / 0 | **0 / 0 / 0** |
| `site.service_categories` | 0 / 0 / 0 | **0 / 0 / 0** |
| `content.items` / `content.item_links` | 0 / 0 | **0 / 0** |
| `routing.link_observations` | 0 / 0 / 0 | **0 / 0 / 0** |
| `custom` links | 0 / 0 / 9 | **0 / 0 / 9** |
| platform connections (non-IG) | 3 / 2 / 1 | **3 / 2 / 1** |
| `sector` / `sector_source` | `hair-salon`/`instagram`, null, null | **unchanged** |

Decisive evidence that no worker has touched these rows since the wave:
`max(platform_connections.updated_at)` per user is **10:20:56 / 10:22:00 / 10:22:39** — all still inside
the original run window. No late write, no retry, no deferred projection.

- **`public.failed_jobs` = 0 rows, total** (not just since the run). Nothing failed and silently retried.
- **Log sweep 10:22:45 → 11:21:00** in seven windows (each verified under the 100-record cap, with
  coverage timestamps checked so no gap was truncated): the only pipeline-adjacent entries are three
  `RefreshConnectionJob` runs at 10:23:06 / 10:23:16 / 10:23:28, all DONE. Those predate the
  `updated_at` values above and wrote nothing to these three users.
- Build states re-polled: all three still `ready`. All three sites still **200**, all three
  `GET /api/public/profiles/<handle>` still **200**.

**So every FAIL and every UNVERIFIED in this report is settled, not merely early.** F2 (no `site_media`),
F6 (no workplaces — since re-classified as correct-by-design, see above) and F7 (no services/categories,
no `ConnectFetchJob`) are confirmed terminal states,
not jobs that had yet to land. §3.4 and §3.5 stay UNVERIFIED for the same reason as before — no probe
resolved a product page or storefront, so the case never arose.

The one thing the re-check *did* surface is the pair of `error waiting on adopted process` entries at
10:30 — written up under *§6 — Errors and noise* in section 3 above. They carry no state impact.

---

## 7. State left behind

Three live, unclaimed, undeleted pre-account builds on dev, all reachable:

| handle | user_id | site |
|---|---|---|
| `simondoylehair` | `019feb30-4ee8-7032-a553-5fea6a729f93` | https://simondoylehair.partna.au |
| `jesshairstylist` | `019feb30-b3b6-7387-8c06-ec36c11144f7` | https://jesshairstylist.partna.au |
| `crucibletattooco` | `019feb31-a556-72ff-85ac-2a1728132305` | https://crucibletattooco.partna.au |

Three Apify scrapes billed, one per handle. **No build was retried.** Per-IP cap for
`28a2b71d…` is now **3 of 3** — the next signup build from this IP will 429 with `IP_BUILD_CAP` until one
is claimed or purged (F9: expiry alone will not free the slots).
