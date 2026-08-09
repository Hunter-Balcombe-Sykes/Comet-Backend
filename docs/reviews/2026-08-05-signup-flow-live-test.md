# Live signup-flow test — 2026-08-05

Manual end-to-end exercise of the site-first signup flow against **dev** (`dev-api.partna.au`),
both source types, real scrapes, real billing. Not an `audit.sh` run — findings below are
**empirically measured**, each with the command that produced them.

Preconditions confirmed before the run: `partna.waitlist.enabled = false`, `bot_protection.mode = off`,
`APIFY_TOKEN` + `GOOGLE_MAPS_SERVER_API_KEY` set on dev, IP under the 3-outstanding-build cap.

## Artifacts created (still live on dev, unclaimed, auto-prune 2026-09-04)

| | Instagram | Google Business |
|---|---|---|
| `source_ref` | `@simondoylehair` | `ChIJy8-d8Tld1moRc1ICsjiqfA0` (Inspire Me Hair Artistry, 61 Errol St, North Melbourne) |
| `build_id` | `019fd066-6260-73e0-a497-9dd86d2b3aef` | `019fd066-89fe-70a9-b42c-ad0bcdf9666d` |
| `user_id` | `019fd066-61c6-7102-a1a6-9bcb802abb15` | `019fd066-89ba-718c-bfc8-579ea15abe7a` |
| `users.handle` | `simondoylehair2` | `inspire-me-hair-artistry` |
| `sites.subdomain` | `simondoylehair-3` | `inspire-me-hair-artistry` |
| Result | `202` → `ready` in ~25 s | `202` → `ready` in ~25 s |

## What worked — do not "fix" these

Recorded so a later reader doesn't mistake correct behaviour for a defect:

- **Dedupe-before-pairing.** Re-posting the same IG ref with `account_type: business` returned
  **200 with the existing `partna` build**, not `422 SOURCE_PAIRING_INVALID`. Deliberate (spec §4.1,
  `docs/api.md` §3). Do not "correct" it.
- **`422 SOURCE_REF_INVALID`** returned with `code` at the **top level**, per the frontend
  discriminator contract.
- **Link discovery (FOUND-24).** IG build seeded `fresha`, `youtube`, `eventbrite` from the bio;
  Google build seeded `facebook` **and** `instagram` off the listing/website, mirrored IG media to R2,
  and auto-grabbed a logo from the business's Wix site.
- **PII strip.** `GoogleBusinessPayload::stripThirdPartyPii` applied pre-claim as designed.
- **Per-IP advisory-lock cap** and the `pg_advisory_xact_lock` path behaved.

---

## Findings

Tick a box only when the fix is verified by an independent review, **or** when it is closed WONTFIX
with the reason written here. A ticked box means "resolved as an open question", not "code changed".

### - [x] `SIGNUP-1` · P0 · the API returns a `site_url` that 404s — **FIXED 2026-08-06**

> **DECIDED by Josh 2026-08-05 — settled, do not relitigate.** `sites.subdomain` is to be **retired**;
> `users.handle` becomes the single identifier. Because the column cannot be collapsed while rows
> disagree with their handle, this ships in **two stages**: `SIGNUP-1` (below) makes the two provably
> equal and is the prerequisite; `SIGNUP-7` is the retirement itself, on its own branch.

The live sitepage is keyed on **`core.users.handle`**. `sites.subdomain` routes **nothing**.

- `SyncSubdomainToKvJob.php:154` — `$kv->put($current, ['type' => 'individual'], $ttl)` where
  `$current = strtolower(trim($pro->handle))`.
- `IndividualProfileController::show` resolves `User::query()->where('handle_lc', $handleLc)`.
- `PreAccountBuildStatusResource` builds `site_url` from `$this->user?->site?->subdomain`.

Measured:

```
$ curl -o /dev/null -w '%{http_code}\n' https://simondoylehair-3.partna.au/   # the returned site_url
404
$ curl -o /dev/null -w '%{http_code}\n' https://simondoylehair2.partna.au/    # where the page is
200                                                                          # 22385 bytes
```

**Origin — two independent collision loops.** `PreAccountBuildService.php:117` passes **`$seed`**, not
`$user->handle`, into `createSiteWithRetry($this->siteProvisioning->subdomainBaseFromHandle($seed))`.
`HandleAllocator::allocate($seed)` walks `simondoylehair → …1 → …2`; `createSiteWithRetry` separately
walks `simondoylehair → -1 → -2 → -3` (`SiteProvisioningService::buildCandidate` joins with `-`). The
suffix formats differ, so once anything collides the two can **never** re-converge.

#### Re-measured 2026-08-06 — the origin is THREE causes, not one, and two more consequences

The "two independent collision loops" account explains only part of the 12. Confirmed against dev:

1. **Normalisation mismatch — the dominant cause, and it needs no collision at all.**
   `Str::slug()` **drops** apostrophes and periods; `subdomainBaseFromHandle()` **replaces** them with
   a hyphen. Same seed, different output: handle `errols` / subdomain `errol-s`; handle
   `doc-pizza-mozzarella-bar-carlton` / subdomain `d-o-c-pizza-mozzarella-bar-carlton`.
2. **Suffix-format mismatch on collision** — as described above (`simondoylehair2` / `simondoylehair-3`,
   `business1` / `business-1`).
3. **Handle renames that bypass `RenameSubdomainAction`** — handle `admin` / subdomain
   `tobiasindarwin-fableqa1`; handle `user-ot9fss` / subdomain `user-aazovq`. `UserBootstrapService`
   force-fills `handle`/`handle_lc` on the refresh path without touching the subdomain.
4. **Reserved-word asymmetry** (found during implementation) — `HandleAllocator` never checked the
   reserved list; `createSiteWithRetry` did and suffixed. Seed `www` → handle `www` / subdomain `www-1`,
   zero collisions.

`RenameSubdomainAction` is **already convergent** (`:112-120` force-fills `handle`/`handle_lc` from the
incoming subdomain), so provisioning was the only creator of divergence.

**Consequences beyond the reported dead `site_url`** — three of the four were unrecorded:

- `site.compute_user_url()` (baseline `:396-404`) reads **`sites.subdomain`**, and trigger
  `sites_url_sync_aiu` pipes it into `core.users.partna_url`. *(The execute prompt states this function
  keys on handle. It does not.)*
- `SyncSubdomainToKvJob:172` uses `partna_url` as the `redirect` in **every alias KV entry** — alias
  301s point at the dead host.
- `SiteObserver:60` → `WarmPublicSiteCacheJob(strtolower($site->subdomain))` → that job does
  `User::where('handle_lc', $subdomain)` (`:69`). On a diverged row it **matches nothing**, so the
  profile cache key visitors actually read is never warmed. `SiteObserver:46` purges the wrong host's
  edge cache. `ClaimSiteService:155` repeats the same mistake on every claim.

**Blast radius on dev:** `12 of 37` non-deleted sites have `handle <> subdomain`. Re-measured
2026-08-06: still `12 / 37`. **6 of the 12 hold active `core.user_handle_aliases` rows** (8 rows) —
those are the only ones needing a KV write, since the primary KV key is the handle and does not change.

#### ✅ BACKFILL APPLIED to dev 2026-08-06 — `12 diverged → 1`

Ran via `cloud command:run development --cmd="php artisan partna:converge-site-subdomains"` (dry run
first, then `--apply`). Both exited 0.

**Result: converged 11, skipped 1.** Verified from the DB, not from the command's own output:

```sql
select count(*) filter (where lower(u.handle) <> lower(s.subdomain)) as still_diverged, count(*) as total
from core.users u join site.sites s on s.user_id = u.id where u.deleted_at is null;
-- still_diverged: 1, total: 37
```

The survivor was deliberate: handle `admin` / subdomain `tobiasindarwin-fableqa1`. `admin` is a
**reserved subdomain**, so converging would park a site on a name `ResolvesSubdomainFromHost` rejects.
The command skips it and says so.

**Resolved 2026-08-06 by deleting the account** (Josh's call). It was a QA fixture —
`tobiasindarwin+fableqa1@gmail.com`, not staff, no builds, no custom domain. Purged via the supported
path, `AccountDeletionService::adminPurgeNow()` (pseudonymise → delete the Supabase auth user →
hard-delete → retire KV), not raw SQL. Verified: user row, site row and handle-alias rows all gone; no
`admin` handle remains anywhere.

**dev is now `0 / 36` diverged — full convergence.**

#### ⚠️ The deletion exposed a SIXTH consequence of divergence: a self-referential 301 loop

After the purge, `tobiasindarwin-fableqa1.partna.au` returned **301 redirecting to itself**, and
`admin.partna.au` returned 522 — the exact symptom `SyncSubdomainToKvJob`'s docblock describes for a
malformed alias entry ("infinite self-loop that surfaces to the visitor as a 522").

Cause: this user had renamed their handle to `admin` while the subdomain stayed
`tobiasindarwin-fableqa1`, so `writeAliasEntries()` wrote the alias key `tobiasindarwin-fableqa1` with
`redirect: $pro->partna_url` — and `partna_url` is computed from the **subdomain**, i.e.
`https://tobiasindarwin-fableqa1.partna.au`. **The alias pointed at itself.** Any diverged row that
also holds a handle alias produces this.

It then became an *orphan*: `SyncSubdomainToKvJob::retire()` deletes only the handle key and the
custom-domain pointer — its docblock says "Aliases are left to expire via their own TTL / the
`handles:prune-expired-aliases` sweep" — but the alias DB rows were hard-deleted with the user, so that
sweep could never see them. The KV entry would have served a redirect loop until its TTL lapsed (up to
90 days), in the namespace **prod shares with dev**.

Cleared explicitly via `CloudflareKvService::delete()`. `tobiasindarwin-fableqa1.partna.au` now 404s,
matching a never-existed host.

**Generalisation worth acting on separately:** hard-deleting a user orphans their alias KV entries,
because retirement is TTL-driven off DB rows that no longer exist. Not specific to this account.

#### `admin.partna.au` returns 522 — EXPECTED, by design, unrelated to any of this

Recorded because it looks alarming and cost a detour. `cloudflare-worker/src/index.js:879-881`:

```js
// Multi-level subdomains and reserved labels pass through.
if (subdomain === "" || subdomain.includes(".") || RESERVED.has(subdomain)) {
    return passThrough(request);
}
```

`admin` is in the Worker's `RESERVED` set (`:72`, mirroring `config/partna.php`), so the request is
`fetch()`ed straight to origin **before any KV lookup**. No origin exists for `admin.partna.au`, so the
TCP connect fails and Cloudflare renders 522. Every observation fits: `api` is also reserved but has a
real Laravel origin (404), `www` has one (200), and a never-existed host is *not* reserved so it takes
the KV → Astro path (404).

**Corollary worth keeping:** a reserved label can never route to a user's site — the Worker
short-circuits it ahead of KV. So handle `admin` was unroutable regardless of the subdomain, which is
exactly why `ConvergeSiteSubdomainsCommand` refuses to converge onto a reserved name.

⚠️ Diagnostic note: `dig` cannot distinguish these cases. Every **proxied** Cloudflare record resolves
to the same anycast IPs, so identical `dig` output says nothing about which origin (or Worker branch)
serves the host. Read the Worker source, not DNS.

**KV, as predicted:** zero writes to any `<handle>` key — every non-alias row reported
`KV: none — the main entry is keyed on the handle, which is not changing`. Only 5 users took alias
`redirect` updates (7 keys: `tobiasindarwin-fablebiz1`, `tobiasindarwin-fableqa3`,
`tobiasindarwin-fableqa6`, `tobiasindarwin-fableqa7`, `tobias`, `user-aazovq`, `ceo`). Those entries
already existed pointing at now-dead hosts, so this **corrected** them rather than adding anything to
the namespace prod shares with dev. The 6th alias-holder was the skipped `admin` row.

**Side effects verified:** `core.users.partna_url` was recomputed by the `sites_url_sync_aiu` trigger
on every converged row (e.g. `https://simondoylehair-3.partna.au` → `https://simondoylehair2.partna.au`),
and `sites.subdomain_changed_at` is still `NULL` throughout — the repair did not spend anyone's 30-day
rename cooldown.

**The P0 symptom is gone end to end**, re-running the finding's own commands:

```
$ curl -o /dev/null -w '%{http_code}\n' https://simondoylehair-3.partna.au/   # the OLD returned site_url
404                                                                          # nothing owns it now
$ curl -o /dev/null -w '%{http_code}\n' https://simondoylehair2.partna.au/    # what the API returns now
200                                                                          # 22385 bytes — matches the original measurement
$ curl -o /dev/null -w '%{http_code}\n' https://inspire-me-hair-artistry.partna.au/
200
```

```sql
select count(*) filter (where u.handle <> s.subdomain) as diverged, count(*) as total
from core.users u join site.sites s on s.user_id = u.id where u.deleted_at is null;
```

Consequence: every collided signup shows the visitor a dead link at the exact moment they decide
whether to claim.

### - [x] `SIGNUP-2` · P1 · every Instagram-built site is nameless — **FIXED 2026-08-06**

`display_name` came out as `simondoylehair2` — the handle — instead of the account's real name.
Stored payload has `fullName: null`, `businessCategory: null`, and **no `biography` key at all**.

Not account-specific and not new — **12 of 12** Instagram connections on dev, back to 2026-07-20:

```sql
select u.handle, u.display_name, c.payload->>'fullName', c.payload->>'biography',
       c.payload->>'businessCategory', c.payload->>'followersCount'
from core.users u join site.platform_connections c on c.user_id = u.id and c.platform = 'instagram'
order by u.created_at desc;
```

`followersCount: 11062` and `postsCount: 365` populate **from the same `$profile` node**, so the node
resolves — the actor is not returning the keys `InstagramConnectionSeeder.php:152` reads
(`fullName`, `businessCategoryName`). Note `InstagramConnector.php:140` already tolerates the
snake_case variant via `Fields::firstString($profile, ['fullName', 'full_name'])`; the seeder does not.
`InstagramIdentitySync:25` consumes `$payload['fullName']`, so a null there is why the fallback fires.

#### ⚠️ Premise corrected 2026-08-06 — "12 of 12, back to 2026-07-20" is wrong

`fullName` populated **correctly on every connection from 2026-06-16 to 2026-07-20**, and is null on
**every connection from 2026-07-21 onward**. Measured on dev (`glncumufgaqcmqhzwrxm`):

| created | username | `fullName` | `businessCategory` |
|---|---|---|---|
| 2026-06-16 | `natalieannehair` | `Natalie Anne Ayoub` | null |
| 2026-07-05 | `doc_gastronomia` | `DOC Gastronomia` | `None,Italian Restaurant` |
| 2026-07-08 | `st_ali` | `ST. ALi COFFEE ROASTERS` | `None,Brand` |
| 2026-07-17 | `starbucksau` | `Starbucks Coffee Australia` | `None,Cafe` |
| **2026-07-20** | **`nasa`** | **`NASA`** | **`Government Agencies`** |
| **2026-07-27** | **`nasa`** | **null** | **null** |
| 2026-07-31 | `Basette_barberia_` | null | null |
| 2026-08-05 | `simondoylehair` | null | null |

The two `nasa` rows are the control: **same account, same key, same code path, opposite results**.
That rules out both hypotheses in the execute prompt (actor emits snake_case *for these accounts*;
field absent *for these accounts*) and rules in a regression with a date.

**Cause — the actor swap, not the key name.** `7969ba981` (2026-07-19) *"feat(instagram): swap to
figue actor, config-driven"* moved to `figue~instagram-profile-scraper`
(`config/partna.php:378`). `c33a72248` (2026-07-23) then landed *"fix: read the figue actor's
snake_case media fields"*, whose message states outright: **"Live diagnostic against the real actor
shows raw Instagram GraphQL naming... Read both shapes, legacy first."** It fixed
`profile_pic_url_hd`, `display_url`, `video_url` in `InstagramScraper` — and missed the identity
fields, which live in two other files. The `has_pic` column corroborates the timeline exactly:
false for `gsnwilliams` (07-23), true from 07-24 onward.

**No billed Apify run was needed** — the stored history contains the A/B, and the actor's own
commit message documents the naming convention.

**The fix is two files, not one** (`InstagramConnectionSeeder.php:206` passes the **raw** `$profile`
to `applyIdentity`, so both readers see raw actor keys):

- `InstagramConnectionSeeder.php:152-153` — `fullName`, `businessCategoryName`
- `InstagramIdentitySync.php:25-26` — `businessCategoryName`, `fullName`

`InstagramIdentitySync::applyContactFields:76-77` reads `businessEmail` / `businessPhoneNumber` off
the same raw node and is presumably affected too — but **unproven**: zero `site.workplaces` rows on
dev carry any `instagram`-sourced field, ever, so there is no "before" case to compare. Treat the
dual-spelling there as defensive, not as a demonstrated fix. Same for sector: `core.users.sector_source`
has only `google-business` (8) and `manual` (6) — never `instagram`.

Existing tests (`tests/Feature/Platforms/InstagramIdentitySyncTest.php`) feed **camelCase** fixtures
throughout, which is why the suite stayed green across the whole regression window.

**`biography` is out of scope and is not part of this regression** — it never populated under either
actor, and adding it means a new `InstagramPayload` field plus a `InstagramConnectionResource` key,
i.e. a wire change. Tracked separately, not fixed here.

### - [x] `SIGNUP-3` · P1 · unpublished sites are fully public — **DOCUMENTED 2026-08-06, no gate added**

> **DECIDED by Josh 2026-08-05 — settled, do not relitigate.** **Accept and document as intended.**
> Pre-account sites are public by design so a visitor can see their site before claiming.
> `is_published` is therefore **not** a public-visibility control — it is a dashboard-level flag.
> No gate is to be added to the profiles route or the KV write. The work is documentation plus
> reconciling the four read paths that currently disagree.

Both builds are `is_published = false`, yet both render **200 with full content** at
`<handle>.partna.au` and via `GET /api/public/profiles/{handle}`.

`is_published` gates `PublicSiteResolver:24`, `PublicDocumentDownloadController:29`,
`AnalyticsController:414`, `QrCodeController:34` — but **not** the profiles route (no reference to it
in `IndividualProfilePayloadBuilder` or `IndividualProfileController`) and **not** the KV write
(`SyncSubdomainToKvJob:111` gates on `isActive() || isUnclaimed()`, never on published).

So a scraped business that never asked to be on Partna is publicly visible to anyone who guesses the
handle, pre-claim. Contradicts `docs/api.md` ("if false, public site endpoint returns 404 or 403") and
the CLAUDE.md note that unclaimed sites render "when published". **May be deliberate** — see the prompt.

#### Verified 2026-08-06 — all six claims hold, at the exact cited lines

Gates (`is_published` checked): `PublicSiteResolver.php:24`, `PublicDocumentDownloadController.php:29`,
`AnalyticsController.php:414`, `QrCodeController.php:34`. Non-gates (zero references to `is_published`):
`IndividualProfilePayloadBuilder`, `IndividualProfileController`, `SyncSubdomainToKvJob`.

**Two further paths found while documenting — the finding's list was not exhaustive:**

- A **fifth gate**, and it is invisible to a PHP grep: `GET /api/public/site` gates in **SQL**.
  `site.public_site_payload`'s WHERE clause is
  `s.is_published = true AND p.status IN ('active','unclaimed') AND p.deleted_at IS NULL`
  (baseline `:2080`), so an unpublished site yields no row and `PublicSiteController` 404s.
- **Two further NON-gates**, both unauthenticated profile sub-resources with zero `is_published`
  references: `PublicIntegrationController` (`/profiles/{handle}/integrations` and `/platforms`) and
  `PublicMenuController` (`/profiles/{handle}/menu`).

⚠️ Those two matter for LEGAL-2 specifically: the menu is **scraped** third-party content and the
integrations payload carries **Google Business data**. They are the exposure surface, and neither was
on the finding's list.

#### ⚠️ LEGAL-2 interaction — it interacts, and materially. Flagged, NOT actioned.

The 2026-07-21 decision behind the reviewer-PII position (`df0ea28c`, Gate-A `preaccount-claim/PRIV-1`)
rests on the premise that **exposure is gated by `is_published`, not claim status**. `SIGNUP-3` shows
that premise is false for the two paths that actually serve the public sitepage.

Pre-claim is still covered: `GoogleBusinessPayload::stripThirdPartyPii()` fires while
`status === 'unclaimed'` (`GoogleBusinessFetch.php:69`, `GoogleBusinessSourceGenerator.php:72`).

**The gap is `claimed` + `is_published = false`.** Reviewer name, photo, permanent Google contributor
link and verbatim text are re-inflated after claim (that is what
`BackfillClaimedGoogleBusinessReviewsCommand` exists to do), and un-publishing does **not** withhold
them from the profiles route or the KV entry. An owner who un-publishes to make their site private
is still serving third parties' PII.

Consequence for `LEGAL-2` (`docs/checklists/launch-readiness-checklist.md`, P0, due before the first
pilot customer): the APP 6 disclosure **cannot be scoped to "published sites"**, because `is_published`
is not a privacy boundary. This does not change whether LEGAL-2 is required — only what it must say.

### - [x] `SIGNUP-4` · P2 · Google address never folded into `location_*` — **PREMISE ERROR, closed 2026-08-06**

Places returned `61 Errol St, North Melbourne VIC 3051, Australia` and the stored payload holds
`lat: -37.803639`, `lng: 144.9492` — but `location_city`, `location_state`, `location_postcode`,
`location_street_address` are all **null** on the user row. `sector` / `sector_source` folded correctly
(`hair-salon` / `google-business`), so the `IdentitySync` fold ran; the address leg specifically did not.

#### ⚠️ Closed — the address DID fold. The finding checked the wrong table.

The fold target is **`site.workplaces`**, not `core.users.location_*`. Measured on dev for the same
build (`019fd066-89ba-718c-bfc8-579ea15abe7a`):

| `site.workplaces` column | value | `field_sources` |
|---|---|---|
| `address_line1` | `61 Errol St` | `google-business` @ `2026-08-05T05:30:28Z` |
| `city` | `North Melbourne` | `google-business` @ same |
| `state` | `Victoria` | `google-business` @ same |
| `postcode` | `3051` | `google-business` @ same |
| `country` | `AU` | `google-business` @ same |
| `latitude` / `longitude` | `-37.803639` / `144.9492` | `google-business` @ same |

The payload's `addressParts` (`{lines:["61 Errol St"], suburb:"North Melbourne", state:"Victoria",
postcode:"3051", country:"AU"}`) mapped cleanly. `IdentitySync.php:61-65` states the design
explicitly: *"Address is written as structured columns (never a flat string — that column was dropped
2026-07-23...) from addressParts."*

`core.users.location_*` is the **user-owned** store, written by exactly one path —
`UpdateUserRequest` (the dashboard PATCH). `IdentitySync` deliberately never writes it. Two stores,
two owners; nulls there are correct for a scraped pre-account build.

**No code change.** Closed as resolved-as-a-question. Open product question, NOT actioned here:
should the dashboard surface the workplace address when the user's own `location_*` is empty?

### - [x] `SIGNUP-5` · P3 · `docs/api.md` §3 drifted from the code — **FIXED 2026-08-06**

- Doc: "`subdomain` and `site_url` are present only once `build_state` is `ready`". Code: measured
  `subdomain` present at `pending`. `PreAccountBuildStatusResource` documents this as **deliberate**
  ("the frontend needs it pre-ready to call POST /api/claim now that claim no longer waits for ready").
- Same resource comment says claim no longer waits for ready; `docs/api.md` still lists
  `409 BUILD_NOT_READY` as a claim error.

The **doc** is wrong, not the code.

#### Verified 2026-08-06 — plus two further doc defects found in the same table

- `PreAccountBuildStatusResource.php:28` sets `subdomain` unconditionally; only `site_url` is
  ready-gated (`:39`). So `docs/api.md:201` and `:233` are both wrong about `subdomain`, right about
  `site_url`.
- **`409 BUILD_NOT_READY` cannot fire on claim at all.** The only occurrence in `app/` is
  `StaffPreAccountBuildController.php:71` — the *staff invite* endpoint ("Build is not ready to
  invite."). `docs/api.md:256` lists it under `POST /api/claim`, where it no longer exists.
- **`docs/api.md` still documents the dropped theme system in SIX places** — and CLAUDE.md explicitly
  forbids reintroducing it, so these are a live trap for a future session reading the docs as spec:

  | line | claims | reality |
  |---|---|---|
  | 318 | `site.sites.theme_id` column, "must exist in themes table" | column absent from live dev schema; `site.themes` dropped |
  | 436 | response field `theme: {id, key, name, config}` | not emitted |
  | 616 | example response containing `"theme": {...}` | not emitted |
  | 885-886 | `PATCH /api/site` accepts `theme_id` | no `theme_id` in any Form Request |
  | 1103-1104 | `GET /api/themes`, `POST /api/themes/{theme}/select` | no `themes` route anywhere in `routes/` |

  Verified 2026-08-06: `grep -rn "themes" routes/` and `grep -rn "theme_id" app/Http/Requests/` both
  return nothing. Design vars live in `site.design_kits`; the only surviving meaning of "theme" is
  `theme_mode`.
- `docs/api.md:316` — typo, "unqiue".

### - [x] `SIGNUP-6` · P3 · log noise — **RESOLVED 2026-08-06, env var set by Josh**

`PARTNA_MEDIA_DISK not set (legacy fallback: SIDEST_MEDIA_DISK); using filesystems.default disk`
fires ~20× per build on dev (`configured_media_disk: media`, `fallback_disk: public_dev`).

#### Root-caused 2026-08-06 — the message is wrong, and it is masking a silent disk override

**`PARTNA_MEDIA_DISK` IS set on dev** — to `media` (confirmed via
`cloud environment:get development --show-sensitive`; 93 vars, this is one of them).

The warning fires anyway because `MediaDiskResolver.php:36-37` reads the override **only** from the
`$_ENV` / `$_SERVER` superglobals. Laravel Cloud injects env vars so that `env()`/`config()` see them,
but PHP's `variables_order` leaves the superglobals unpopulated — so `$explicit` is null even though
the variable exists. Resolution then falls to the `$configured === 'media'` branch, finds
`filesystems.default` = `public_dev` (platform-injected — `FILESYSTEM_DISK` is **not** among the 93
user-set vars), sees an s3 driver, warns, and **returns `public_dev`**.

So dev media is landing on a different disk than the config names, and this "noise" is the only
evidence of it. The `public_dev` disk is documented in `config/filesystems.php:91-95` as a legacy
alias that mirrors `media`, so nothing is broken today — but the override is silent by design.

#### ✅ RESOLVED 2026-08-06 — `PARTNA_MEDIA_DISK=public_dev` set on dev by Josh. No code change.

Verified, not taken on trust:

```
$ cloud environment:get development --json --fields=environmentVariables --show-sensitive
[{'key': 'PARTNA_MEDIA_DISK', 'value': 'public_dev'}]
```

`config('partna.media_disk')` now equals what `MediaDiskResolver` already returned, so **behaviour is
unchanged** and the `$configured === 'media'` branch — the one that logged the warning ~20× per build —
is no longer reachable. The config now states what actually happens rather than being silently
overridden.

Note this does **not** fix the underlying seam: `MediaDiskResolver` still reads only `$_ENV`/`$_SERVER`,
which Laravel Cloud leaves unpopulated, so any *future* `PARTNA_MEDIA_DISK` change will again be
invisible to the explicit-override branch and take effect only via `config()`. Left as-is deliberately —
see the rejected alternatives below.

Rejected alternatives, recorded so they are not relitigated:
- *Change `MediaDiskResolver` to consult `config('partna.media_disk')`.* Behaviour DOES change — dev
  media would start landing on `media` instead of `public_dev`, splitting old and new uploads across
  two buckets without a backfill.
- *Memoise `resolve()` per process* (~20 lines → 1, no behaviour change). Kills the noise but leaves
  the config lying about which disk is in use.
- *WONTFIX.* Nothing is broken today (`public_dev` mirrors `media`), but the config would stay wrong.

### - [ ] `SIGNUP-7` · P1 · retire `sites.subdomain` — **SEPARATE BRANCH, NOT part of this run**

Follow-on from `SIGNUP-1`'s decision. Recorded here so the decision has a home; **do not attempt it in
the `SIGNUP-1..6` execution run.** Measured blast radius, 2026-08-05:

- **308 references** to `subdomain` across ~60 files in `app/` + `routes/`; heaviest:
  `RenameSubdomainAction` (51), `SiteCacheService` (30), `PublicSiteController` (21),
  `DataExportPayloadBuilder` (16), `ResolvesSiteFromRequest` (16), `SubdomainAvailabilityService` (14),
  `SiteProvisioningService` (13), `ResolvesSubdomainFromHost` (13), `SiteObserver` (12).
- **Two database views project the column** — `site.all_site_data`, `site.public_site_payload`. A
  view-drop is a Postgres-only failure mode; the SQLite suite will not catch it.
- **`site.site_subdomain_aliases`** — a full alias table with `reclaim_until` / `expires_at` / 301
  semantics and a prune command, duplicating `core.user_handle_aliases`. Retirement means collapsing
  two alias mechanisms into one.
- **`analytics.lead_submissions.subdomain`** (historical rows) and **`sites.subdomain_changed_at`**.
- **Two frontend wire fields** — the `POST /api/claim` request body and the build-poll response —
  and the frontend is a **separate repo**, so the backend must accept both spellings through a
  deprecation window rather than cutting over.
- 6 domain-scoped public routes resolve by subdomain (`routes/api/publicSite.php:19-46`): site show,
  customer leads, enquiry, subscribe, marketing preference, unsubscribe. **Reachability through the
  Cloudflare Worker is unproven** — measured 2026-08-05, `*.partna.au/api/public/site` returns the
  Astro 404 page for a non-diverged published site too (`gsnwilliams`), because the Worker forwards
  everything to `env.PARTNA_PAGES`. Establish whether these routes are live before designing around them.

Effort: **XL.** Needs its own spec, its own branch, and a frontend change landed in lockstep.

---

## Progress

`6 / 7` resolved — P0 `1/1` · P1 `2/3` · P2 `1/1` · P3 `2/2`
(`SIGNUP-7` is out of scope for the `SIGNUP-1..6` run — tracked, not executed, and is the only
unresolved item. **The `SIGNUP-1..6` run is complete.**)

Worked 2026-08-06 on `audit-fix/signup-flow-live-test-2026-08-05`, plan → implement → independent
review per `scripts/audit/fix-flow.md`.

| id | outcome | commit |
|---|---|---|
| `SIGNUP-1` | fixed — convergence + guard + backfill command + regression tests | `71e981ff5` |
| `SIGNUP-2` | fixed — figue actor's snake_case identity fields, two files | `65b9b1fca` |
| `SIGNUP-3` | documented, no gate added (Josh's decision) | `eff63a082` |
| `SIGNUP-4` | closed — premise error, the fold was already correct | `71e981ff5` |
| `SIGNUP-5` | fixed — docs corrected to match the code | `eff63a082` |
| `SIGNUP-6` | resolved — `PARTNA_MEDIA_DISK=public_dev` set on dev, verified; no code change | — |
| `SIGNUP-7` | not executed, by design | — |

**Three of the six premises were wrong as written** and were corrected in place rather than
"fixed": `SIGNUP-2` (regression dated to the 2026-07-19 actor swap, not "always broken"),
`SIGNUP-4` (the address folded correctly, into a different table), and `SIGNUP-6` (the env var is
set; the warning masks a silent disk override). `SIGNUP-1` and `SIGNUP-3` were understated — each
had live consequences the finding did not record.
