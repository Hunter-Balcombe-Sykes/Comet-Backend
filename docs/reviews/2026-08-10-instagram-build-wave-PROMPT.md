# EXECUTE PROMPT — Instagram (`partna`) build wave + full verification report, 2026-08-10

**Give this file to a fresh session. It is self-contained.**

## Objective

Two phases, in order:

1. **Create three `partna` pre-account sites on dev** from three real Instagram handles, via the public
   signup endpoint — the same path a real visitor takes.
2. **Write one report** verifying what the pipeline did and did not do, end to end.

## Hard rules

1. **Dev only.** `https://dev-api.partna.au`. Never touch production.
2. **Sequential.** One build at a time; wait for terminal state before the next. Never parallelise.
3. **Never stop the creation phase.** If build 1 errors or fails, **record it and continue to build 2
   and 3 anyway.** All three attempts must be made regardless of what happens to any of them.
4. **Do NOT delete anything.** All three must be left live and unclaimed.
5. **Report only. Change nothing.** No code edits, no commits, no migrations, no config changes, no
   re-running of jobs to "make it work", no manual backfills. If something is broken, that is the
   finding — write it down and move on.
6. **Double-check every claim.** Every line in the report must name the table, column, log line or file
   that proves it. If you cannot prove it, write "unverified" — never infer.

## Background

- `POST /api/public/signup/build` creates a provisional `core.users` row (`status='unclaimed'`) plus an
  unpublished `site.sites` row, then dispatches `GeneratePreAccountSiteJob` (queue: `scraping`,
  timeout 300s) which runs `InstagramSourceGenerator::generate()`.
- Pairing is strict: `account_type: partna` ⇄ `source_type: instagram`.
- Each build costs a **paid Apify** scrape. Do not retry a build more than once; if you do, say so.
- Dev Supabase ref: `glncumufgaqcmqhzwrxm`. App name for logs: `partna`.

### Preconditions — already satisfied 2026-08-10, but re-check

The per-IP cap (`partna.pre_account.max_unclaimed_per_ip`, default 3) was freed by purging the two
2026-08-05 fixtures. Confirm it is still clear before starting:

```sql
select count(*) from core.pre_account_builds
where created_ip_hash = '4147c0d0476fc60576d5ad95bb1ae1ab8a02999395ea042f29d0a00c6689777b'
  and claimed_at is null;   -- expect 0; 3 or more means the next build 429s
```

⚠️ `PreAccountBuild::scopeLive()` (`app/Models/Core/User/PreAccountBuild.php:99-102`) is only
`whereNull('claimed_at')` — it **ignores `expires_at`**, so unclaimed builds hold cap slots forever.

---

# PHASE 1 — Create the three builds

For each handle, in this order:

```bash
curl -s -w '\n%{http_code}\n' -X POST https://dev-api.partna.au/api/public/signup/build \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"account_type":"partna","source_type":"instagram","source_ref":"<HANDLE>"}'
```

Expect **202** + `build_state: "pending"`. Poll every ~5s until `ready` or `failed`:

```bash
curl -s https://dev-api.partna.au/api/public/signup/builds/<BUILD_ID>
```

The 2026-08-05 run reached `ready` in ~25s. After **5 minutes** stop polling, record it as stuck, and
**move to the next handle**.

| # | `source_ref` | Built on dev before? |
|---|---|---|
| 1 | `simondoylehair` | Yes, 4× (all now purged) |
| 2 | `jess.hair.stylist` | **Never** |
| 3 | `crucibletattooco` | **Never** |

Record per build: HTTP status, full response body, `build_id`, `user_id`, `build_state`,
`failure_code`, and wall-clock time to terminal state.

**Then wait 3 minutes before starting Phase 2.** Several downstream steps are async jobs
(`LinkInBioScanJob`, `CommerceProbeJob`) and will not have finished when `build_state` flips to `ready`
— `ready` only covers the synchronous generator.

---

# PHASE 2 — The verification report

Work through every section. For each check record **PASS / FAIL / UNVERIFIED** plus the evidence.

Get your user_ids first:

```sql
select b.source_ref, b.id as build_id, b.user_id, b.build_state, b.failure_code, u.handle, s.subdomain
from core.pre_account_builds b
join core.users u on u.id = b.user_id
left join site.sites s on s.user_id = u.id
where b.source_ref in ('simondoylehair','jess.hair.stylist','crucibletattooco') and b.claimed_at is null
order by b.created_at;
```

## §1 — Identity and handle

| # | Check | How |
|---|---|---|
| 1.1 | IG username became the suggested handle | `core.users.handle` vs `source_ref` |
| 1.2 | `handle` == `subdomain` | compare `core.users.handle` / `site.sites.subdomain`, case-insensitively |
| 1.3 | `display_name` is the person's REAL name, not the handle | `core.users.display_name` |
| 1.4 | `first_name` populated sensibly | `core.users.first_name` |
| 1.5 | IG category became sector | `core.users.sector`, `sector_source` |
| 1.6 | Contact fields folded | `site.workplaces` rows + their `field_sources` |

**1.2 is the SIGNUP-1 regression.** Handle #2 (`jess.hair.stylist`) has **two periods**, which is the
exact divergence trigger: `Str::slug()` *drops* periods, `subdomainBaseFromHandle()` *replaces* them
with a hyphen. Expected-if-broken: handle `jesshairstylist` vs subdomain `jess-hair-stylist`.

**1.3 is the SIGNUP-2 regression**, fixed at `65b9b1fca`. On 2026-08-05 `simondoylehair` produced
`display_name = "simondoylehair2"` — the handle — with payload `fullName: null` and
`businessCategory: null`. Those are the "before" values; report the "after".

**1.5/1.6 have never once succeeded on dev.** `sector_source` has only ever been `google-business` or
`manual`, never `instagram`, and no `site.workplaces` row has ever carried an `instagram`-sourced
field. `InstagramIdentitySync::applyContactFields:76-77` reads `businessEmail` / `businessPhoneNumber`.
Treat these as genuinely open questions, and say plainly which way they came out.

## §2 — The scrape itself

| # | Check | How |
|---|---|---|
| 2.1 | Profile fields captured | payload `fullName`, `businessCategory`, `followersCount`, `postsCount` |
| 2.2 | `biography` present | payload — **known absent under both actors**; confirm, don't investigate |
| 2.3 | Profile picture mirrored to R2 | payload pic fields + `site.site_media` |
| 2.4 | Post media mirrored | `site.site_media` + `site.media_variants` row counts |
| 2.5 | Every media row has a `webp` variant | join `site.site_media` → `site.media_variants` |
| 2.6 | Gallery ≤ 6 | `core.enforce_site_gallery_max6` trigger caps it; over 6 = trigger failure |

On 2026-08-05 both accounts had payload `images` length **1** despite 365 and 908 posts. Establish
whether `images` only ever holds the profile pic and real media lives in `site.site_media`, or whether
media capture is under-filling. State which.

## §3 — Link routing (the core of this report)

### First, establish which router ran — do this before anything else

There are **two parallel routing systems** and they have different evidence trails:

- `app/Services/Platforms/LinkRouter` — legacy. Used by `InstagramAutoSync`, `LinkInBioScanJob`,
  `CommerceProbeJob`. **This is what the Instagram path uses.** It writes **no** observation rows.
- `app/Routing/LinkRoutingService` — writes `routing.link_observations` via `LinkObserver`. Only
  reached from `RoutingController` and the importers (`WebsiteImporter`, `LinkInBioImporter`).

So `routing.link_observations` being **empty for these users is expected**, not a finding. Check it and
say so explicitly:

```sql
select count(*), source from routing.link_observations where user_id = '<USER_ID>' group by source;
```

If it is empty, your evidence for routing decisions is: the seeded rows themselves, plus the
`scraping`-queue logs. **Do not conclude "no links were routed" from an empty observations table.**

⚠️ `InstagramSourceGenerator.php:91` **strips** `bioLinks`, `syncFindings` and `unmatched` from the
provisional payload (PRIV-2). Their absence from `site.platform_connections.payload` is **by design**.
You therefore **cannot** enumerate the input links from the stored payload. Get them from the live
Instagram bio and from the logs.

### §3a — Enumerate the inputs

For each account list every bio link the scrape saw: `bio_links` entries plus `externalUrl`. Then, for
each, state the outcome: **seeded / conflict / custom / skipped / pending**.

### §3b — Per-link outcome rules (do not report these as bugs)

`LinkRouter::gateAllows()` for a **`partna`** account:

| category | partna | so a link of this kind should… |
|---|---|---|
| `social` | allowed | seed a connection |
| `booking` | allowed | seed a connection |
| `event` / `event-organiser` | allowed | seed via `EventsSeeder` |
| `shop` | allowed | dispatch `CommerceProbeJob` → outcome `pending` |
| `reservations` | **DENIED** | fall through to **custom link** |
| `online-ordering` | **DENIED** | fall through to **custom link** |

Reservations and online-ordering are `business` + food-sector only. A partna account routing them to
custom is **correct**.

Two more by-design behaviours that look like misses:

- **First-link-per-platform wins.** `RouteContext::$seenPlatforms` — a second link to an
  already-seeded platform returns `skipped`. Not a miss.
- **Probe budget is 6 per run.** `RouteContext::DEFAULT_MAX_PROBES = 6`. Unclassified and `shop` links
  share it; past 6 they go **straight to custom** without a probe. If any account had >6 such links,
  say how many fell off the end — that is a silent cap and must be stated, not hidden.

### §3c — Link-in-bio unroll

Target #3 (`crucibletattooco`) has a **Linktree**; target #2 (`jess.hair.stylist`) also has one.

- `LinkInBioDetector` matches 18 curated hosts. `linktr.ee` is one.
- A match dispatches `LinkInBioScanJob`, which fetches the page and pushes **every outbound link**
  through the same `LinkRouter`. Nothing about the bio-link URL itself is persisted.

Verify: was `LinkInBioScanJob` dispatched? Did it run? Did each link **inside** the Linktree get an
outcome? Enumerate the Linktree's links yourself (fetch the page) and account for **every one**.

Known contents of `linktr.ee/crucibletattooco`: custom site `crucibletattooco.com.au` plus 5 sub-pages,
**Bluesky**, **Pinterest**, TikTok, Facebook, Instagram, and `paytherent.net.au`.

Expect **Bluesky** and **Bookwell** to be unclassified — verified 2026-08-10, they have zero references
anywhere in `app/Catalog/` or `app/Services/Platforms/`. **Pinterest is different: it was deliberately
retired** 2026-07-28 (`app/Catalog/LegacyPlatformMap.php:117-121`), so a Pinterest link *should* land
as a custom link. Report Pinterest as correct-by-decision, not as a gap.

### §3d — Outcome-type verification

| # | Check |
|---|---|
| 3.1 | Every classified platform link produced a `site.platform_connections` row |
| 3.2 | Every unclassified link became a custom link — nothing silently vanished |
| 3.3 | Shop/unclassified links dispatched `CommerceProbeJob` within budget |
| 3.4 | A probe that resolved a **product page** produced an **item** (`content.items`, `content.item_links`) |
| 3.5 | A probe that resolved a **storefront** produced a shop connection |
| 3.6 | Input link count == seeded + custom + skipped + pending + denied-by-gate. **Nothing unaccounted for.** |

3.6 is the single most important check in this report. Every input URL must land in exactly one bucket.

## §4 — Does the loop continue? (cascade)

Josh's specific question: once a platform is connected, do **its** URLs go back through the router?

Check per seeded connection whether anything downstream ran:

| # | Check |
|---|---|
| 4.1 | Did any seeded connection dispatch a `ConnectFetchJob`? |
| 4.2 | Did a seeded **Fresha** connection fetch its service menu? |
| 4.3 | Were services projected into `site.services`? |
| 4.4 | Were categories projected into `site.service_categories` / `site.service_category_assignments`? |
| 4.5 | Did any URL discovered inside a connection's payload get routed? |

```sql
select
 (select count(*) from site.services where user_id='<USER_ID>') as services,
 (select count(*) from site.service_categories where user_id='<USER_ID>') as categories,
 (select count(*) from site.service_category_assignments a join site.services s on s.id=a.service_id where s.user_id='<USER_ID>') as assignments;
```

⚠️ **Read the code before judging this section.** `BuildsAutoSyncFindings` (the booking write path
`LinkRouter::seedBooking` uses) states for the booking slot: *"no vendor call, no dispatch, DB only."*
`ConnectFetchJob` is dispatched from the **controllers** (`GenericPlatformController:180`,
`DefersBespokeConnect:97`, `EventsController:48`) — i.e. the dashboard connect flow — and
`FreshaServiceProjector::sync()` hangs off that fetch.

So the likely finding is that an **auto-routed** Fresha connection is a bare
`{url, provider, source: "auto"}` row with **no services and no categories**, while a **dashboard**
connect gets the full projection. **Verify this; do not assume it.** If confirmed, report it as a
behavioural gap between the auto-route and connect paths, quantify it (how many services the account
actually has on Fresha vs how many landed), and **do not fix it**.

## §5 — Auto-signup rules

| # | Check |
|---|---|
| 5.1 | Instagram auto-media / latest-media rule enabled on create | `AutoSyncSetting` pool (`app/Site/Pools/AutoSyncSetting.php`) — the old `sites.content_instagram_auto_enabled` column is gone |
| 5.2 | `is_published` is `false` | `site.sites.is_published` |
| 5.3 | Site is nonetheless publicly reachable | `curl -o /dev/null -w '%{http_code}' https://<handle>.partna.au/` → **200 expected** |
| 5.4 | KV entry written | `SyncSubdomainToKvJob` is the only writer; keyed on **handle** |
| 5.5 | `GET /api/public/profiles/<handle>` returns the built content | includes `designKit`, `architectureId: staple` |
| 5.6 | `status = 'unclaimed'` | `core.users.status` |

5.2 + 5.3 together are **correct, decided behaviour** (SIGNUP-3): pre-account sites are public
pre-claim by design; `is_published` is a dashboard flag, not a visibility control. Confirm, don't flag.

## §6 — Errors and noise

Pull the window and report anything unexpected:

```bash
cloud env:logs partna development --minutes 30
```

Use the Cloud CLI only — the boost log tools serve stale local output and are forbidden. Report every
exception, failed job, 5xx and repeated warning seen during the run.

---

# Deliverable

A single markdown report at `docs/reviews/2026-08-10-instagram-build-wave-RESULTS.md`:

1. **Summary table** — three accounts × created / handle / display_name / links found / links routed /
   platforms connected.
2. **Per-account link ledger** — every input URL, its outcome, and the row that proves it. This is the
   heart of the report; §3.6 must balance.
3. **Section-by-section PASS / FAIL / UNVERIFIED** against §1–§6.
4. **Findings** — anything that did not work, with evidence. No severity theatre, no fixes proposed.
5. **Explicitly correct-by-design** — a short list of things that look wrong but are not (gate denials
   for reservations/online-ordering, first-link-per-platform skips, empty `link_observations`, stripped
   `bioLinks`, retired Pinterest, unpublished-but-public). This stops the next reader re-raising them.

**Do not delete the three builds. Do not fix anything. Do not change any code.**
