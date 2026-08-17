# EXECUTE PROMPT — Google Business (`business`) build wave + full verification report, 2026-08-10

**Give this file to a fresh session. It is self-contained.**
Sibling run: `2026-08-10-instagram-build-wave-PROMPT.md` (the `partna`/Instagram wave). Same flow,
different source type. Run them as separate sessions.

## Objective

1. **Create six `business` pre-account sites on dev** from six real Melbourne Google Business
   listings, via the public signup endpoint — the same path a real visitor takes.
2. **Write one report** verifying what the pipeline did and did not do, end to end.

⚠️ **Six builds cannot coexist.** The per-IP cap is **3 unclaimed builds**, counted forever
(`PreAccountBuild::scopeLive()` is only `whereNull('claimed_at')` — it **ignores `expires_at`**). So this
wave runs as **two batches of three**, and batch B cannot start until batch A's slots are released.

**You do not release them yourself.** Finish batch A, write up its half of the report, then **stop and
ask Josh**. Deleting anything on your own initiative is forbidden (rule 4).

**Batch A is non-food; batch B is food.** That split is deliberate — the food gates, the menu pipeline
and the Apify spend decision only engage on batch B, so keeping them apart makes the difference legible.

## Hard rules

1. **Dev only.** `https://dev-api.partna.au`. Never touch production.
2. **Sequential.** One build at a time; wait for terminal state before the next. Never parallelise.
3. **Never stop the creation phase.** If a build errors or fails, record it and continue to the next
   anyway. Every attempt in a batch gets made regardless of what happens to the others.
4. **Do NOT delete anything.** Every build must be left live and unclaimed. Releasing batch A's slots
   is **Josh's call, not yours** — ask, and wait.
5. **Report only. Change nothing.** No code edits, commits, migrations, config changes, manual job
   re-runs or backfills. If something is broken, that is the finding.
6. **Double-check every claim.** Name the table, column, log line or file that proves it. If you cannot
   prove it, write "unverified" — never infer.

## Background

- `POST /api/public/signup/build` creates a provisional `core.users` row (`status='unclaimed'`) plus an
  unpublished `site.sites` row, then dispatches `GeneratePreAccountSiteJob` → `GoogleBusinessSourceGenerator`.
- Pairing is strict: `account_type: business` ⇄ `source_type: google_business`.
- **`source_name` is REQUIRED** for `google_business` (max 120). A `place_id` is opaque, so the name
  seeds the handle, subdomain and display name. Omitting it is a validation error, not a default.
- **Cost:** each build spends one **Places Details** call (paid, and Places is the only uncapped paid
  API on this project). `GoogleBusinessEnrichJob` *may* additionally spend an Apify call — see §4.
- Dev Supabase ref: `glncumufgaqcmqhzwrxm`. App name for logs: `partna`.

### Preconditions

The per-IP cap (`partna.pre_account.max_unclaimed_per_ip`, default 3) counts **all unclaimed builds
forever** — `PreAccountBuild::scopeLive()` (`app/Models/Core/User/PreAccountBuild.php:99-102`) is only
`whereNull('claimed_at')` and **ignores `expires_at`**.

**Measure the cap; do not assume it.** Any number written in this file is stale by the time you read it
— the Instagram wave, staff builds and the daily prune all move it. Check immediately before starting,
and if it is full, **stop and ask Josh** which builds to release. Do not purge anything yourself.

```sql
-- Your own slot usage. Confirm the hash is yours first — see the warning below.
select count(*) from core.pre_account_builds
where created_ip_hash = '28a2b71d0d4730e305ba75f39630fda357ccf9a970e3b8233c2fec63d12d5b8b'
  and claimed_at is null;   -- 3 or more means the next build 429s

-- Fuller picture, and the one to trust if there is any doubt about the hash.
select coalesce(created_ip_hash,'(null - staff/early_access)') as ip_hash,
       count(*) as live_unclaimed, max(created_at) as newest
from core.pre_account_builds where claimed_at is null
group by created_ip_hash order by live_unclaimed desc, newest desc;
```

⚠️ **Verify the hash is yours before trusting the first query.** It is the sha256 of dev egress IP
`150.228.243.132`, measured 2026-08-10. An earlier revision quoted `4147c0d0…`, which belongs to a
**different origin** — it returned 0 regardless of the true state, i.e. it would have reported "cap
clear" even when full. If your egress IP differs, use the second query.

Note the cap is **per-IP**, so builds made from other origins (including the null-hash `staff` /
`early_access` rows) do not consume your slots.

---

# PHASE 0 — Resolve the six place_ids

The signup endpoint takes an opaque `place_id`, and the dashboard picker normally supplies it. Resolve
each target with the server key via Places Text Search (New). `GoogleBusinessService::resolve()` parses
Maps URLs but returns `{url,name,lat,lng}` — **no place_id** — so do not use it for this.

```bash
~/.composer/vendor/bin/cloud tinker development --timeout=120 --json --fields=exitCode,status,output --code="
\$key = config('services.google_maps.server_api_key');
foreach ([
  'Beefs Barbers, 238 Brunswick Street, Fitzroy VIC',
  'Melbourne Tattoo Company, 2 Somerset Place, Melbourne VIC',
  'Drunken Barber, 119 Smith Street, Fitzroy VIC',
  'Anada Bar Restaurant, 197 Gertrude Street, Fitzroy VIC',
  'Bar Liberty, 234 Johnston Street, Fitzroy VIC',
  'Pidapipo Gelateria, 299 Lygon Street, Carlton VIC',
] as \$q) {
  \$r = \Illuminate\Support\Facades\Http::withHeaders([
    'X-Goog-Api-Key' => \$key,
    'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress',
  ])->post('https://places.googleapis.com/v1/places:searchText', ['textQuery' => \$q]);
  echo \$q.' => '.\$r->body().PHP_EOL;
}
"
```

⚠️ `cloud tinker` returns **`exitCode: 0` even when the PHP threw** — read the `output` field, never
branch on the exit code.

Record the resolved `place_id` **and** the exact `displayName` for each. Confirm each is the Melbourne,
Australia business and not a same-named US one (several of these names collide with Melbourne, Florida).
If a lookup returns nothing or the wrong place, record that and skip that target — do not substitute a
different business without saying so.

---

# PHASE 1 — Create the builds (batch A, then batch B)

For each target, in this order:

```bash
curl -s -w '\n%{http_code}\n' -X POST https://dev-api.partna.au/api/public/signup/build \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"account_type":"business","source_type":"google_business","source_ref":"<PLACE_ID>","source_name":"<NAME>"}'
```

Expect **202** + `build_state: "pending"`. Poll every ~5s until `ready` or `failed`:

```bash
curl -s https://dev-api.partna.au/api/public/signup/builds/<BUILD_ID>
```

After **5 minutes** stop polling, record it as stuck, and move to the next target.

### BATCH A — non-food, run these first

| # | Business | `source_name` to send | Why this target |
|---|---|---|---|
| 1 | Beef's Barbers, 238 Brunswick St, Fitzroy | `Beef's Barbers` | **Apostrophe in the name** — the SIGNUP-1 normalisation trigger |
| 2 | Melbourne Tattoo Company, 2/2 Somerset Pl, CBD | `Melbourne Tattoo Company` | **23-char name** vs the `max:15` business rule; Squarespace; `shop.` subdomain |
| 3 | Drunken Barber, 119–121 Smith St, Fitzroy | `Drunken Barber` | Squarespace; **legacy numeric Facebook URL**; links a *sister salon* |

**Stop after batch A.** Write up its half of the report, then ask Josh to release the slots.

### BATCH B — food, only after Josh confirms the cap is clear

Re-check the cap query first. Everything the non-food batch never touches lives here: the food gates,
the menu extractors, and the paid-Apify decision.

| # | Business | `source_name` to send | Why this target |
|---|---|---|---|
| 4 | Añada, 197 Gertrude St, Fitzroy | *(use the exact Places `displayName`)* | **Accented `ñ`** + food; Squarespace; **HTML** menu |
| 5 | Bar Liberty, 234 Johnston St, Fitzroy | `Bar Liberty` | Food; **two PDF menus**; Obee gift vouchers |
| 6 | Pidapipo Gelateria, 299 Lygon St, Carlton | `Pidapipo Gelateria` | **The gelato gap** — food in reality, not in either food list; multi-location |

**#4 Añada** — a Spanish tapas and wine bar. Two axes at once. Its Google `displayName` very likely
carries the **accented `ñ`**, which is the character class that broke handle/subdomain convergence for
apostrophes and periods (SIGNUP-1): `Str::slug()` transliterates, `subdomainBaseFromHandle()` may not.
**Send the exact `displayName` Places returns — do not hand-type "Anada"**, or you destroy the test.
Its menu is an **HTML page** on Squarespace, so it exercises `SquarespaceMenuExtractor` /
`WebsiteMenuHtmlScanJob`.

**#5 Bar Liberty** — serves **two PDF menus** (`/s/A-LA-CARTE-*.pdf` and a wine list PDF), so it is the
only target that exercises `PdfLinkDetector` + `WebsiteMenuPdfScanJob`. Its gift-voucher link is
`vouchers.obeeapp.com` — Obee is **not** in the catalog, so expect a custom link. Being a bar, it is a
food sector, so reservations and online-ordering are **allowed** here (the exact inverse of the
Instagram wave's `supernormal_180` case).

**#6 Pidapipo** — a gelateria, and the point is a **mismatch between two independently-defined food
lists**, both verified 2026-08-10:

- `SectorTaxonomy::FOOD_SECTORS` = `restaurant, cafe, bakery, bar, food-truck, caterer, personal-chef`.
  **No ice-cream, gelato or dessert sector.** So `isFood()` likely returns **false**, and `gateAllows()`
  then **denies** reservations and online-ordering — for a business that is obviously food.
- `GoogleBusinessEnrichJob::needsApify()` keywords = `restaurant, cafe, coffee, bar, bakery, food,
  pizza, kitchen, diner, eatery, bistro, pub, takeaway, grill`. **Also no gelato or ice cream.** So no
  Apify call either, despite being a food venue.

Pidapipo typically carries delivery links (UberEats/DoorDash). If those are gate-denied to custom links
because a gelateria isn't "food", **that is the finding** — record which sector was actually assigned,
what `isFood()` resolved to, and which links were demoted. It is also **multi-location** (Carlton,
Fitzroy, CBD, Windsor), so confirm the bound `place_id` matches the Carlton address in `site.workplaces`.

**Then wait 8 minutes before Phase 2.** Longer than the Instagram wave: `GoogleBusinessEnrichJob` runs
async after the generator returns, and `GoogleMenuPhotoScanJob` is dispatched with an explicit
**5-minute delay** (`GoogleBusinessEnrichJob:315`). `build_state: ready` only covers the synchronous
generator.

---

# PHASE 2 — The verification report

Record **PASS / FAIL / UNVERIFIED** plus evidence for every check.

```sql
select b.source_ref, b.id as build_id, b.user_id, b.build_state, b.failure_code, u.handle, s.subdomain
from core.pre_account_builds b
join core.users u on u.id = b.user_id
left join site.sites s on s.user_id = u.id
where b.source_type = 'google_business' and b.claimed_at is null
order by b.created_at desc limit 3;
```

## §1 — Identity, handle and the name rules

| # | Check | How |
|---|---|---|
| 1.1 | Business name became the handle | `core.users.handle` vs `source_name` |
| 1.2 | `handle` == `subdomain` | compare case-insensitively |
| 1.3 | `display_name` word-trimmed to the business cap | `core.users.display_name` |
| 1.4 | `first_name` — is it trimmed too? | `core.users.first_name` |
| 1.5 | Sector folded from the Google category | `core.users.sector`, `sector_source` (expect `google-business`) |
| 1.6 | `account_type` | expect `business` |

**1.2 is the SIGNUP-1 regression, and target #1 is built to provoke it.** `Str::slug()` **drops**
apostrophes; `subdomainBaseFromHandle()` **replaces** them with a hyphen. Expected-if-broken: handle
`beefs-barbers` vs subdomain `beef-s-barbers`. The 2026-08-05 run saw exactly this shape on a real row
(handle `errols` / subdomain `errol-s`).

**1.3/1.4 matter because of a live trap.** `BusinessName::wordTrim` runs before the display-name write,
and `UpdateUserRequest` enforces **`max:15`** for business accounts. On 2026-08-05,
`Inspire Me Hair Artistry` produced `display_name = "Inspire Me Hair"` (15 chars, trimmed) but
`first_name = "Inspire Me Hair Artistry"` (24 chars, **untrimmed**). Determine whether `first_name` is
covered by the `max:15` rule — if it is, these rows would **422 on the owner's first profile edit after
claim**, which is precisely the failure `wordTrim` exists to prevent. Target #2 is a 23-char name
chosen to re-test this.

## §2 — Places data and the PII strip

| # | Check | How |
|---|---|---|
| 2.1 | Place details stored | `site.platform_connections` where `platform='google-business'` |
| 2.2 | `place_id` + `apify_status` set | same row's columns |
| 2.3 | Address folded into **`site.workplaces`** | `address_line1`, `city`, `state`, `postcode`, `country`, `latitude`, `longitude` + `field_sources` |
| 2.4 | Phone / hours / rating captured | payload |
| 2.5 | **Reviewer PII stripped** pre-claim | `GoogleBusinessPayload::stripThirdPartyPii` — no reviewer names, photos, contributor URLs or verbatim text |

⚠️ **2.3 — do not check `core.users.location_*`.** That was a confirmed premise error on 2026-08-05
(`SIGNUP-4`, closed). `core.users.location_*` is the **user-owned** store, written only by the dashboard
PATCH; `IdentitySync` deliberately never writes it. Nulls there are **correct** for a scraped build. The
fold target is `site.workplaces`.

**2.5 is a legal control, not a nicety** — it feeds `LEGAL-2`, which is P0 before the first pilot
customer. If any reviewer PII survives pre-claim, that is the single most important finding in this
report.

## §3 — Website harvest and scan

All six targets have their own website (#2, #3 and #4 are Squarespace). The free
`WebsiteLinkHarvester` runs against `payload.website` before any paid call.

| # | Check |
|---|---|
| 3.1 | Website URL captured from the listing |
| 3.2 | `WebsiteLinkHarvester` ran and returned links |
| 3.3 | Logo / favicon harvested (`WebsiteLogoCandidateExtractor`, `FaviconFetcher`) |
| 3.4 | Accent colour resolved (`SiteAccentResolver` / `ResolveSiteAccentJob`) and applied to `site.design_kits` |
| 3.5 | Gallery candidates grabbed (`WebsiteGalleryCandidateExtractor` / `GalleryAutoGrabber`) — **≤ 6**, each with a `webp` `site.media_variants` row |
| 3.6 | About prose extracted (`AboutProseExtractor`) |
| 3.7 | Contact email extracted (`ContactEmailExtractor`) |
| 3.8 | **Batch A** — menu extractors should **no-op** (none are food) |
| 3.9 | **Batch B** — menu pipeline actually ran: `MenuFetchJob`, `WebsiteMenuHtmlScanJob` (#4, Squarespace HTML), `WebsiteMenuPdfScanJob` + `PdfLinkDetector` (#5, two PDFs), `GoogleMenuPhotoScanJob` (all) |
| 3.10 | **Batch B** — menu items landed in `site.menu_items` / `site.menu_item_categories`, or say plainly that they did not |

The 2026-08-05 Google build auto-grabbed a logo from the business's Wix site, so 3.3 has a working
precedent. A gallery item without a matching `webp` variant renders an empty URL — 3.5 must check both
tables, not just `site.site_media`.

## §4 — Did it spend Apify? (it should not)

`GoogleBusinessEnrichJob::needsApify()` returns true **only** when the harvest came back empty **or**
the Google category matches one of: `restaurant, cafe, coffee, bar, bakery, food, pizza, kitchen,
diner, eatery, bistro, pub, takeaway, grill`.

Expected per target — **state the actual outcome against each prediction**:

| # | Target | Prediction | Why |
|---|---|---|---|
| 1–3 | barber / tattoo | **No Apify** | non-food, real websites → harvest non-empty |
| 4 | Añada | **Apify fires** | category will contain `restaurant` or `bar` |
| 5 | Bar Liberty | **Apify fires** | category will contain `bar` |
| 6 | Pidapipo | **No Apify** | "ice cream / gelato" matches **no** keyword |

| # | Check |
|---|---|
| 4.1 | `apify_status` on each connection |
| 4.2 | Log line `google_business.enrich_job.*` — which branch ran |
| 4.3 | For each, did Apify fire? If yes: empty harvest, or which keyword matched? |
| 4.4 | Any prediction above that came out wrong — and why |

This section is about **money**, so be precise. Apify firing on a barber, or on Pidapipo, means the
keyword list is behaving differently than read. Every build already spends a Places call regardless.

## §5 — Link routing

### Which router ran

`GoogleBusinessAutoSync` extends the same `BuildsAutoSyncFindings` write path as `LinkRouter`
(`app/Services/Platforms/`), **not** `app/Routing/LinkRoutingService`. So `routing.link_observations`
will likely be **empty for these users, and that is expected** — check it and say so explicitly. Do not
conclude "no links were routed" from an empty table.

```sql
select count(*), source from routing.link_observations where user_id = '<USER_ID>' group by source;
```

### Gates — and they INVERT between the two batches

`gateAllows()` keys on `isBusiness()` **and** `SectorTaxonomy::isFood($user->sector)`:

| category | Batch A (business, non-food) | Batch B (business, food) |
|---|---|---|
| `social` | seed | seed |
| `booking` | **seed** (`$isBusiness ? !$isFood : true`) | **DENIED** → custom link |
| `event` / `event-organiser` | seed | seed |
| `shop` | probe → `pending` | probe → `pending` |
| `reservations` | **DENIED** → custom link | **seed** |
| `online-ordering` | **DENIED** → custom link | **seed** |

⚠️ **Booking flips the other way on food.** `$isBusiness ? !$isFood : true` means a *food* business is
**denied** booking — so a Fresha/Booksy link on Añada or Bar Liberty becomes a **custom link**, while
the same link on Beef's Barbers seeds a connection. Verify both directions; report either as correct.

**Target #6 is the one to watch**: if Pidapipo's sector is not in `FOOD_SECTORS`, it takes the **Batch A**
column despite being a food venue — meaning its delivery links get demoted. Record which column it
actually landed in, and the `sector` value that decided it.

Same two by-design behaviours as the Instagram wave: **first-link-per-platform wins**
(`RouteContext::$seenPlatforms` → later duplicates return `skipped`), and the **probe budget is 6 per
run** (`RouteContext::DEFAULT_MAX_PROBES`), past which links go straight to custom without a probe. If
any account exceeded 6, state how many fell off the end.

### Per-link ledger

Fetch each business's website yourself, enumerate every outbound link, and account for **every one**:
seeded / conflict / custom / skipped / pending / gate-denied. **The count must balance.**

Specific things to watch:

- **Target #3's Facebook URL is the legacy numeric form** (`/Drunken-Barber-543923568968459/`).
  `InstagramAutoSync::socialUsername` documents a known blind spot in the Facebook normalizer for
  reserved path segments. Check what username it extracted, if any.
- **Target #3 links a sister salon** (`prophecyhair.com.au`). That is a *different business* and must
  **not** be claimed as this one's own site or socials. Over-claiming here is a finding.
- **Target #2 has a `shop.` subdomain** (`shop.melbournetattoocompany.com`) — check whether it probed
  as a storefront and produced a shop connection, or fell to a custom link.

## §6 — Does the loop continue? (cascade — the richest part of this wave)

Unlike the Instagram wave, the Google path has a **real multi-hop cascade**, and this is where to look
hardest.

`GoogleBusinessAutoSync::applyFindingHandled()` (`:129-132`) overrides the default write branch: when a
discovered link carries `apply.instagram`, it **re-dispatches the Instagram scrape**
(`dispatchInstagram`, `:667`) instead of writing a plain link. So:

> Google listing → website harvest → Instagram link found → **Instagram scrape dispatched** → that IG
> connection's own bio links → `InstagramAutoSync` → `LinkRouter` → further platforms.

| # | Check |
|---|---|
| 6.1 | Was an Instagram connection created from the *Google* build? |
| 6.2 | Did that IG connection get a real scrape (media, followers), or is it a bare placeholder? |
| 6.3 | Did the IG connection's **own bio links** then get routed? (hop 3) |
| 6.4 | If a bio link was a Linktree, did `LinkInBioScanJob` fire? (hop 4) |
| 6.5 | Did `dispatchAutoBookingConnect` (`:296`) auto-connect a booking platform? |
| 6.6 | For any seeded **booking** connection, did services/categories populate? |

**6.6 — read the code before judging.** The booking slot in `BuildsAutoSyncFindings` states: *"no
vendor call, no dispatch, DB only."* `ConnectFetchJob` — which `FreshaServiceProjector::sync()` hangs
off — is dispatched from the **dashboard controllers** (`GenericPlatformController:180`,
`DefersBespokeConnect:97`), not the auto-route. So an auto-routed booking connection is likely a bare
`{url, provider, source:"auto"}` row with **no services and no categories**. Verify against the real
Fresha/Booksy page (target #1 uses Fresha), quantify the gap, and **do not fix it**.

```sql
select
 (select count(*) from site.services where user_id='<USER_ID>') as services,
 (select count(*) from site.service_categories where user_id='<USER_ID>') as categories,
 (select count(*) from site.service_category_assignments a join site.services s on s.id=a.service_id where s.user_id='<USER_ID>') as assignments;
```

The 2026-08-05 Google build seeded `facebook` **and** `instagram` off the listing/website and mirrored
IG media to R2 — so hops 1–2 have a working precedent. Establish how far the chain got this time.

## §7 — Auto-signup rules

| # | Check |
|---|---|
| 7.1 | `is_published` is `false` | `site.sites.is_published` |
| 7.2 | Site is nonetheless publicly reachable | `curl -o /dev/null -w '%{http_code}' https://<handle>.partna.au/` → **200 expected** |
| 7.3 | KV entry written, keyed on **handle** | `SyncSubdomainToKvJob` is the only writer |
| 7.4 | `GET /api/public/profiles/<handle>` returns built content | `designKit`, `architectureId: staple` |
| 7.5 | `status = 'unclaimed'` | `core.users.status` |
| 7.6 | `expires_at` set (~30 days) | `core.pre_account_builds.expires_at` |

7.1 + 7.2 together are **correct, decided behaviour** (SIGNUP-3). Confirm, don't flag.

⚠️ **7.4 carries a live legal exposure**, recorded on 2026-08-06 and unresolved: the
`/profiles/{handle}/integrations` and `/profiles/{handle}/menu` sub-resources have **zero
`is_published` references**, and the integrations payload carries **Google Business data**. Note what
those two endpoints expose for an unclaimed, unpublished business. Report only.

## §8 — Errors and noise

```bash
cloud env:logs partna development --minutes 30
```

Cloud CLI only — the boost log tools serve stale local output and are forbidden. Report every
exception, failed job, 5xx and repeated warning. Note that the dev log window currently carries heavy
external scanner traffic (`/nuclei.svg`, `/wd/hub`, …) — that is background noise, not from this run.

---

# Deliverable

A single markdown report at `docs/reviews/2026-08-10-google-business-build-wave-RESULTS.md`:

1. **Summary table** — six businesses × batch / place_id / created / handle / display_name / links found /
   routed / platforms connected / Apify spent yes-no.
2. **Per-account link ledger** — every website link, its outcome, and the row proving it. Must balance.
3. **Cascade trace** — for each account, how many hops the chain reached (§6), with evidence per hop.
   This is the centrepiece of this wave.
4. **Section-by-section PASS / FAIL / UNVERIFIED** against §1–§8.
5. **Findings** — with evidence. No severity theatre, no proposed fixes.
6. **Explicitly correct-by-design** — gate denials for reservations/online-ordering, first-link-per-
   platform skips, empty `link_observations`, null `core.users.location_*`, unpublished-but-public.
   This stops the next reader re-raising them.

**Do not delete any build. Do not fix anything. Do not change any code.** If batch A is still holding
the cap when you finish its report, stop and ask Josh — do not release the slots yourself.
