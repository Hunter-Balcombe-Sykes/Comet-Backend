# Slice 1b — Instagram in the pool, Google on display

Sub-slice of `2026-08-11-content-pool-convergence-design.md` §7 "Slice 1 — Media
pool live · XL", second half. Sibling: `2026-08-12-media-pool-slice-1a-design.md`.

1a built the asset spine — fingerprint inversion, `site_media_id`, `MediaUrlResolver`,
`frames[]`, the corrected `pool:media` rule, the upload backfill. 1b was scoped to
fill that spine with the two external sources and retire the legacy selection
table's media half.

**It does not do that, and this spec explains why.** Three facts established
against the live database and Google's published terms say Google photos cannot
join the pool on the same footing as uploads and Instagram. The slice splits the
media sources into two classes with different contracts:

| Class | Sources | Bytes | Identity | Poolable | Pinnable |
|---|---|---|---|---|---|
| **Owned** | uploads (1a), Instagram | mirrored to R2 | stable | yes | yes |
| **Borrowed** | Google Business | never stored | rotates | display only | **no** |

Scope boundary inherited from 1a: **backend only**. No dashboard work, no public
render work. The legacy `gallery` / `designMedia` wire keys stay — they cannot
retire until the frontends stop reading them.

---

## 0. Entry gate — FAILED, and what that means

Convergence invariant #5: no slice may cite another slice's checkpoint as evidence
for its own claims. Every 1a fact was re-derived. One of them does not hold.

### 0.1 1a has not landed

| Check | Result |
|---|---|
| `content.media_assets.site_media_id` exists on dev | ❌ **column does not exist** (`ERROR 42703`) |
| 1a merged into `development` | ❌ 16 commits on `worktree-media-pool-slice-1a`, clean, unmerged, worktree locked |
| **Code gate:** `mediaFingerprint()` prefers `ref` | ✅ **passes in the worktree** — `ProjectionWriter.php:1166` reads `$ref ?? $url` |
| Same on `development` | ❌ `ProjectionWriter.php:1155` still reads `$url ?? $ref` |

The code gate passes where 1a lives and fails where it has not yet arrived, which
is the correct reading of "1a is in review". The ordering hazard the gate protects
is therefore **live and unresolved**:

> No 1b code may land before 1a is merged and deployed to dev.

Nothing in this spec is blocked by that. Design proceeds; implementation is gated
on 1a. The plan carries this as its first precondition.

### 0.2 The kickoff prompt's gate SQL does not run

Corrected column names, for whoever runs it next:

| Kickoff SQL | Actual schema |
|---|---|
| `ingest.sources.kind` | `source_key` (and `surface_key`) |
| `ingest.streams.name` | `stream_name` |
| `ingest.effects.name` | `kind` + `cost_tag` |
| `site.content_selection.type` | `entry_type` |
| `site.platform_connections.provider` | `platform`, and the value is `google-business` (hyphen), not `google_business` |

### 0.3 Prod verification, deferred by 1a, still outstanding

1a's "no data migration required" argument was verified against dev only. This
spec does not clear it either — prod carries no customer data (`core.users` = 0),
so the check is cheap but must be run by the 1a merge, not assumed by 1b.

---

## 1. Verified state — dev (`glncumufgaqcmqhzwrxm`), 2026-08-12

Read from the database while writing this spec. Where it contradicts 1a, the
correction is stated. 1a's figures were taken earlier the same day and several
have already moved.

```
content.items kind='media'       : 20    (1a: 10 — a second google_business source ran)
  └ all 20 google_business, all headline_cache NULL, all item_media role='gallery' pos=0
content.media_assets             : 501   (1a: 485)
  ├ storage_path set             : 0
  ├ source_url NULL              : 20    (exactly the google media assets)
  ├ fingerprint 'url-' prefixed  : 501   (100% — no bare content hashes exist yet)
  ├ variant_family               : NULL on all 501
  └ dims_confidence              : 'declared' on the 20, NULL on 481
site.content_selection           : google-photo 80, ig-post 4, ig-reel 2, upload 3  = 89
                                   (1a: 82/4/2/3 = 91)
site.sections pool:media         : 10 still carrying latest_per_auto_source
ingest.sources google_business   : 12 (auto_sync=false, 2 have run)
ingest.sources instagram         : 1  (auto_sync=false, never run)
ingest.streams google_business/media : 2, health=ok, run_seq_max=1
ingest.effects                   : api/places.details ×2, status=ok, 20 cost_units
content.sources                  : connection 29 @p100, manual 1 @p200
site.platform_connections google-business : 16 rows (12 live), 150 photo entries
  ├ carrying 'url'               : 150/150, all unkeyed lh3.googleusercontent.com/place-photos/…
  └ carrying 'authors'           : 60 of 110 live (1a implied all)
```

`BrandAssetPipeline` has still produced zero rows (0 of 501 carry `storage_path`).
1a's "built and unexercised" holds.

---

## 2. The three findings that reshape this slice

### 2.1 Google Places photo resource names rotate between fetches

The parent spec, 1a §7, the kickoff prompt, and `ContentSelection`'s own docblock
(`app/Models/Core/Site/ContentSelection.php:14` — *"a Google photo `ref` (stable
name)"*) all assume a Places photo ref is a durable identifier. It is not.

**Evidence A — two independent billed fetches of the same place.** The legacy
refresh lane and the ingest lane each fetched place `ChIJse5g9zld1moRKH_lqhWAH9g`
for user `019f8313`:

```
legacy  site.platform_connections   2026-08-10 14:23:10   10 refs
ingest  content.source_items        2026-08-12 07:01:35   10 refs
                                    exact matches:         0
```

**Evidence B — the 80 stored selections against the current payload.** Eight
owners, each with exactly 10 `google-photo` selections and exactly 10 payload
photos for the *same* place_id, live connection, refreshed within two days:

```
selections: 80    live payload photos: 110    exact ref matches: 0
one owner:  sel 10, payload 10, token length 431 both, exact_match 0, prefix40_match 0
```

Same business, same photo count, same 431-character token format, same
`AWCwyd`-era encoding — and not one shared value. This is not photos rotating out
of the set; it is the identifier for the same photo being reissued per fetch.

**What this breaks.** `content.media_assets.fingerprint` is `url-sha1(ref)` after
1a, and `content.source_items.coord` embeds the ref verbatim
(`google_business:acct-<hash>:places/<place>/photos/<token>`). A second run of the
`google_business/media` stream therefore presents 10 unrecognised coords, mints 10
fresh assets under the unique `(user_id, fingerprint)` index, and `mergeInto()`
tombstones the 10 it no longer sees. Every sync, forever.

**Status: proven for refs, deduced for the churn.** No `google_business` source has
run twice (`run_seq_max=1`), so the duplicate-minting has not been observed. §6.1
carries it as a live assertion.

### 2.2 Resolved photo URLs expire at approximately 30 days

The kickoff asks whether the unkeyed lh3 URLs expire. They do. Bracketed by
fetching stored URLs of increasing age:

```
payload refreshed  2026-08-12  →  HTTP 200 (image/jpeg, 60,909 bytes)
                   2026-08-11  →  HTTP 200
                   2026-08-10  →  HTTP 200
                   2026-07-16  →  HTTP 200      (27 days)
                   2026-07-14  →  HTTP 403      (29 days)
                   2026-07-12  →  HTTP 403      (31 days)
```

The same-day control returning a real JPEG is what makes the 403s meaningful:
the requests are well-formed, the old links are dead.

A stored Google photo URL is good for about a month. Hotlinking is therefore not
a design choice with a downside — it is a gallery that breaks on a timer.

### 2.3 Google's terms forbid storing the photos, and the expiry enforces it

> "You must not pre-fetch, cache, or store Places API content beyond the allowed
> exceptions." — [Places API policies](https://developers.google.com/maps/documentation/places/web-service/policies)

Place IDs are exempt and may be stored indefinitely. **Photos carry no exemption.**
Attribution is separately required: credit the author, link to the photo on Google
Maps via `googleMapsUri`, and expose `flagContentUri` for content reporting.

The ~30-day URL life in §2.2 is that policy expressed in infrastructure.

**Together, §2.1–§2.3 close the question the kickoff framed as "hotlink or
mirror?".** Neither. Mirroring is prohibited; hotlinking expires; and the identity
rotates so the photo cannot be reliably referred to across syncs even if it could
be stored. Google photos are a licensed live feed, not an asset we hold.

---

## 3. Decisions

### D1 — Two media classes, different contracts

`Owned` media (uploads, Instagram) mirrors to R2, keys on stable identity, is
poolable and pinnable. `Borrowed` media (Google) is displayed live, re-resolved
inside the expiry window, never stored, never pinned.

This is a **correction to parent §2.4 and 1a §7**, both of which treat Google
photos as ordinary pool members. Stated here rather than diverged from quietly.

### D2 — Google URLs come from the ingest lane's own Details call, not the legacy payload

Parent §2.4 recommends reading servable URLs out of `site.platform_connections`
to avoid re-billing. **That is not implementable**, and §2.1 is why: the legacy
payload and the ingest lane are two separate fetches with two disjoint ref sets.
There is no join key between a pool media item and a legacy payload photo.

Refs and URLs are only consistent **within a single fetch**. So the resolution has
to happen where the refs are minted: `PlacesDetailsDriver`.

That driver's docblock already names this slice as the home:

> "Deliberately does NOT resolve photo refs to servable URLs. That is up to 15
> further billed media calls per run and it belongs to slice 1, where something
> actually renders them."

**Cost.** Place Details Photos bills $7.00 per 1,000 at the entry tier, first
1,000/month free. Ten photos per place per run = **$0.07 per run**. `PlacesBudget`
already carries a `photos` SKU with a 400/day cap and per-user 60/day, and
`resolvePhotoUrls()` already claims one slot per photo before the pool fires, so
the budget machinery needs no new concepts.

**One digest, not two.** Photo resolution is folded into the existing
`api/places.details` effect rather than added as a new effect kind. A separate
`resolve_photos` input would change the digest, splitting `profile`/`reviews` from
`media` into two billed Details calls — $25/1,000 each, far outweighing the $7/1,000
photo calls it would be trying to isolate. All three streams keep sharing one
Details call.

### D3 — Re-resolution cadence: 7 days, inside a 30-day window

`google_business` currently sits at `defaultIntervalSeconds: 172800` (48h). Left
there, every sync re-mints the whole Google media set (§2.1) — 10 assets per place
per two days, unbounded growth.

Set the `media` stream's interval to **7 days**: comfortably inside the ~30-day
expiry with a 4× safety margin against a shortened window, and 3.5× less churn
than today. The churn does not disappear — it cannot, while identity rotates — but
it becomes bounded and its cost becomes predictable ($0.07 × 4.3/month per place).

Asset accumulation is handled by D4, not by the cadence.

### D4 — Tombstoned borrowed assets are collected, not kept

Because each sync mints a fresh asset set, `content.media_assets` grows by 10 per
place per run with the previous rows orphaned (`item_media.asset_id` is
`ON DELETE SET NULL`, and the items themselves are tombstoned by `mergeInto()`).

A borrowed asset whose item is tombstoned and whose `source_url` is older than the
expiry window is dead by definition — the URL 403s. The prune command deletes
`content.media_assets` rows that are (a) unreferenced by any live `item_media`,
(b) carry no `storage_path` (never owned), and (c) older than 30 days.

This is also the mechanism that keeps us compliant with §2.3: borrowed content is
not retained.

### D5 — Google media is not pinnable

A pin is a promise that a specific item stays where the owner put it. For a
borrowed photo we cannot keep that promise: the identity rotates weekly and the
underlying photo may leave the place's set entirely.

The pool's auto half already surfaces Google photos through the corrected
`kind_is(media)` rule with no pin required — 1a §5.1 makes exactly this point about
uploads. So the enforcement is narrow: `PoolController::select()`
(`app/Http/Controllers/Api/Content/PoolController.php`, routed
`POST /content/pools/{pool}/selection/{item}`) rejects an item whose
`content.sources.connection_id` resolves to a `google_business` source, with a
typed error rather than a silent drop. `deselect()` and `reorder()` need no
change — there is nothing to remove or order.

**Owner-facing consequence, stated plainly:** the six dev sites whose entire
`content_selection` is Google photos (10 each, zero uploads, zero Instagram) have
empty backgrounds today and will still have empty backgrounds after this slice.
Google photos flow to the page automatically; they do not flow into the background
picker. Filling that picker is uploads, Instagram, or a separate product decision
about whether the backdrop may draw from a live borrowed feed. **Out of scope
here, and deliberately not papered over.**

### D6 — Attribution is carried, not deferred

`mapPhoto()` (`GoogleBusinessConnector.php:256-277`) collects
`authorAttributions[].displayName` and `GoogleBusinessMediaProjector` then discards
all of it. Attribution is required by §2.3 and is currently lost end to end.

1b restores it:

- `mapPhoto()` additionally carries `authorAttributions[].uri`, the photo's
  `googleMapsUri`, and `flagContentUri` where the Places response provides them.
- `content.media_assets` gains an `attribution jsonb NULL` column, mirroring
  `content.brand_asset_refs.attribution`.
- `frames[]` carries it to the wire so a renderer can satisfy the display
  requirement.

**Known gap, flagged not absorbed:** only 60 of 110 live payload photos carry
`authors` at all. Where Google returns no attribution there is nothing to display
and the frame carries null. That is Google's data, not our loss — but it means
"attribution is present" cannot be asserted as a 100% invariant, and the pilot
should know a subset of Google photos will render uncredited. **This has legal
weight and is called out rather than absorbed.**

### D7 — A media item may have a null headline, by contract

All 20 Google media items carry `headline_cache = NULL`, and
`InstagramMediaProjector` also emits `'headline' => null`. The kickoff asks
whether this is a data fix or a contract question. It is a contract question, and
the answer is that a photo does not need a headline.

The pool contract tolerates a null headline for `kind='media'` by design. The
renderer falls back to `alt_text`, then to nothing. No backfill, no synthetic
headline. Asserted in tests so a later "fix" does not reintroduce one.

### D8 — Instagram supersedes the multi-photo mirror spec

`docs/superpowers/specs/2026-08-11-instagram-multi-photo-mirror-design.md` is
"pending owner sign-off" and designs mirroring into the **legacy**
`platform_connections.payload` lane, which this slice replaces. **1b supersedes
it.** Its §1.1 finding is absorbed, not discarded, and is confirmed live:

```php
// InstagramConnectionSeeder.php:82
$folder = 'platforms/instagram/'.$connection->created_at->timestamp;
// :92  $this->mirrorOne($media['photo']['thumbnailUrl'], "{$folder}/photo.jpg");
```

The timestamp is the **connection's** `created_at`, which never changes, so every
refresh overwrites one fixed `photo.jpg` in place. 1b's paths must not reproduce
this (see U5).

The superseded spec should be marked as such in its own header when 1b lands.

### D9 — The mirror writes onto the ProjectionWriter row; it does not mint its own

The kickoff names the fingerprint collision as the trap, correctly.
`BrandAssetPipeline` keys on a bare **content hash** of the decoded bytes
(deliberately, per its `#PRIV-5` comment); `ProjectionWriter` keys on
`url-sha1(...)`. A mirror that minted its own row would produce two assets for one
photo with `item_media` pointing at one of them.

**Chosen: the mirror updates the ProjectionWriter-minted row in place**, setting
`storage_path`, `mime_type`, measured `width`/`height`,
`dims_confidence='measured'` and `variant_family='native'`. The fingerprint is
never rewritten. `BrandAssetPipeline` is the **donor of the fetch/re-encode/store
logic**, extracted behind a small interface — not the writer of record.

This keeps 1a §1.2's "three writers, disjoint fingerprint shapes" property intact:
`url-<sha1>` (ProjectionWriter, now including mirrored rows), bare content hash
(BrandAssetPipeline for brand assets), `url-sha1('upload:{id}')` (1a).

### D10 — Selections: migrate 3, drop 86, on the record

Verified on dev: `google-photo` 80, `ig-post` 4, `ig-reel` 2, `upload` 3.

**The 3 uploads migrate**, by `media_id`, and only the *selection* — 1a's
backfiller already gives the underlying items a home.

**The 80 google-photo rows are dropped.** Three independent reasons, any one
sufficient:

1. They were never curated. `ContentSelectionService::maybeSeedFromGoogle()`
   auto-seeds every photo in payload order at connect time and is "a no-op once
   the user has any real content pick". All eight sites show exactly 10, at
   contiguous positions. No human chose these.
2. They already resolve to nothing. `resolve()` drops a row whose ref misses the
   payload (`:80-84`, its own comment: *"Dangling ref — the photo rotated out of
   the connection payload"*). Per §2.1, all 80 miss. They have been dead since
   roughly 40 hours after each connect.
3. Under D5 there is no destination. Google media is not pinnable.

**The 6 ig-post / ig-reel rows are dropped.** Re-verified: they carry neither
`media_id` nor `external_ref` (`with_ref=0 with_media=0`). They resolve
positionally against a live payload — `$instagram->images[0]`, `$instagram->videoUrl`.
There is no identifier to migrate. Positional reconstruction is explicitly
rejected: the kickoff forbids it, and the order was Google's/Instagram's anyway,
not the owner's.

**Nothing is deleted by this slice.** The migration is additive; `site.content_selection`
is dropped in slice 7. The "drop" is a decision not to carry them, recorded with
counts, affected site ids, and this reasoning, in the checkpoint.

---

## 4. The change

Ten units. **U0 is a precondition, not a unit.** U1–U4 (Google) and U5–U7
(Instagram) are independent of each other and may proceed in parallel. U8–U9 depend
on nothing.

### U0 — precondition: 1a merged and deployed to dev

Re-run the §0.1 gate. `site_media_id` present on dev, `mediaFingerprint()` reading
`$ref ?? $url` on `development`. **No 1b unit starts until both hold.**

### U1 — `content.media_assets.attribution`

New column, `jsonb NULL`, no DB default. Raw SQL under `supabase/migrations/`, one
statement, never a Laravel migration. Shape:

```json
{"authors": [{"name": "…", "uri": "…"}], "maps_uri": "…", "flag_uri": "…"}
```

Nullable throughout — see D6's known gap.

### U2 — `mapPhoto()` and the media projector carry attribution

`GoogleBusinessConnector::mapPhoto()` additionally emits author `uri`,
`googleMapsUri` and `flagContentUri` where present.
`GoogleBusinessMediaProjector` stops discarding them and passes an `attribution`
key on its media entry. `ProjectionWriter::resolveMediaAssets()` writes the column.

Redaction check: these are *photographer* attributions, not the reviewer PII the
connector's `when_unclaimed` scopes cover. Confirm the manifest's `redactions` list
is not silently widened by the new keys.

### U3 — `PlacesDetailsDriver` resolves photo URLs in the same fetch

Per D2. The driver calls the existing `GoogleBusinessService` photo-resolution
path so `PlacesBudget`'s `photos` SKU claim, the `PHOTO_REF_PATTERN` guard, and
the pooled-concurrency cap all continue to apply unchanged — this unit adds a call
site, not a second billing path. Resolved URLs ride back on the raw response's
photo entries; `mapPhoto()` picks them up as `url` alongside `ref`.

**This is the unit 1a's ordering constraint exists to protect.** Under
`development`'s current `$url ?? $ref` it would re-key the 20 existing Google
assets and mint duplicates. U0 is what makes it safe.

Also set the `media` stream interval to 7 days (D3).

### U4 — pins reject borrowed media

Per D5. `PoolController::select()` resolves the item's source and rejects a
`google_business` connection source with a typed error. Test asserts the rejection
**and** that the auto half still surfaces the same item — the point is that the
photo stays visible, only the promise of permanence is withheld.

403 vs 404 (per `CLAUDE.md`): this is a capability restriction on an item the owner
legitimately owns, not a hidden resource, so **403** is correct here.

### U5 — Instagram media stream provisioning and content-addressed paths

Enable the `media` stream on the existing `instagram` `ingest.sources` row
(currently `auto_sync=false`, never run).

Mirror paths are **content-addressed**: derived from the asset fingerprint, never
from a connection timestamp (D8). Shape follows `BrandAssetPipeline`'s existing
convention:

```
content-media/{user_id}/{sha256-of-bytes|32}.webp
```

A re-sync of changed bytes writes a new path; it cannot overwrite a URL a user has
already picked.

**Budget.** `CostClass::Actor`, weighted 50, claimed against `ApifyBudget`
(`apify_daily_cap` 200/day). One slot per run, two when the profile comes back
thin (`InstagramActorDriver`'s own docblock). At the connector's
`defaultIntervalSeconds: 604800` — one run per handle per week, 1–2 slots.

### U6 — the mirror

Extract `BrandAssetPipeline`'s fetch → re-encode → store path behind an interface
and drive it from the projection lane, writing onto the ProjectionWriter-minted
row per D9. Every fetch goes through `SafeUrlFetcher` (category B): an Instagram
CDN URL arrived in a third-party payload and is untrusted by definition. Adding a
host allowlist entry is not the fix.

`SecretParams::minimiseUrl()` verified directly: `_nc_sid` is caught via the `sid`
entry in `SECRET_SEGMENTS`; `oh`, `oe` and `_nc_ohc` appear in no list. 1a's claim
holds — an Instagram URL re-signs to a new `source_url` every sync. After 1a that
no longer touches identity, and after this unit `storage_path` is what serves, so
the churn is cosmetic. That is the reason to mirror rather than store links, and
it still holds.

### U7 — borrowed-asset prune command

Per D4. `app/Services/Migration/` + artisan command, `--dry-run`, idempotent,
counts reported. Deletes `content.media_assets` rows unreferenced by live
`item_media`, with no `storage_path`, older than 30 days.

### U8 — `ContentSelectionMigrator`

Per D10. `app/Services/Migration/ContentSelectionMigrator.php` plus an artisan
command with `--dry-run`, per convergence invariant #4 — production code, tested,
idempotent, re-runnable, counts reported. Migrates 3 upload selections; reports
the 80 + 6 as explicitly dropped with their site ids. Writes nothing to
`site.content_selection`.

### U9 — wire manifest

`docs/wire-changes/2026-08-12-media-pool-slice-1b.md`, appending to 1a's lineage
rather than restarting it. Before/after shapes, consuming repos named
(Partna-App dashboard, the monorepo public render).

`frames[]` gains an optional `attribution` object. Additive; nothing breaks.

---

## 5. Cache invalidation — all three lanes

Both migration commands and the mirror are raw-write seams. Per parent §9.2 and
1a §4, copying **`MediaUploadBackfiller::invalidate()`** — which is the verified
three-lane implementation:

> **Do not copy `PoolController::poolChanged()`.** It runs only two lanes
> (`BuildState::bump` + `CloudflareCachePurgeJob`) and deliberately omits the
> `sites.updated_at` touch, because an interactive pool edit self-heals when the
> 60s payload TTL expires. A bulk migration has no such luxury — it must be correct
> the moment it finishes. 1a's spec §4 named `poolChanged()` as the template; that
> reference is corrected here.

| Lane | Action | Why bumping alone is not enough |
|---|---|---|
| build state | `BuildState::bump($siteId)` | this is what it is for |
| 60s payload cache | touch `site.sites.updated_at` | `IndividualProfilePayloadBuilder::cacheKey()` composes the key from `updated_at`; `bump()` writes a different table, so the stale payload serves for the full TTL |
| Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` | the CDN outlives the origin write |

There is **no CI check** that a raw-write seam bumps — `BuildState`'s docblock
claims one and it does not exist. Asserted directly in tests, per unit.

---

## 6. Verification

### 6.1 Live dev assertions — SQL and output pasted into the checkpoint

```sql
-- U3: google media items carry a servable url and attribution
SELECT count(*) FILTER (WHERE source_url IS NOT NULL) AS with_url,
       count(*) FILTER (WHERE attribution IS NOT NULL) AS with_attr,
       count(*) AS total
FROM content.media_assets a
JOIN content.item_media im ON im.asset_id = a.id
JOIN content.items i ON i.id = im.item_id AND i.kind='media';

-- U6: instagram media landed with owned bytes
SELECT count(*) FILTER (WHERE storage_path IS NOT NULL) AS mirrored,
       count(DISTINCT storage_path) AS distinct_paths, count(*) AS total
FROM content.media_assets WHERE fingerprint LIKE 'url-%';

-- U8: three upload selections migrated, nothing else
SELECT entry_type, count(*) FROM site.content_selection GROUP BY 1;
```

**The two assertions no SQL can stand in for:**

1. **§2.1's churn, observed.** Run `google_business/media` twice and record the
   asset/item delta. This is the deduction in §2.1 turned into an observation.
   Expected: a fresh set each run, the previous set tombstoned. If it does *not*
   churn, §2.1 is wrong about the mechanism and D3/D4/D5 must be revisited before
   merge. Cost: one Details call ($0.025) plus 10 photo calls ($0.07).
2. **The parent's no-duplicate proof, for Instagram.** Two consecutive Instagram
   syncs produce no duplicate assets — the shortcode-stable ref makes this the
   case Google cannot satisfy, and it is the property that justifies D1's split.

### 6.2 Pest

- attribution round-trips connector → projector → asset → `frames[]`; a photo with
  no `authorAttributions` yields null rather than an empty object (D6 gap)
- a media item with a null headline resolves and renders (D7)
- a pin on a `google_business`-sourced media item is rejected; the same item still
  appears in the auto half (D5)
- the mirror writes `storage_path` onto the **existing** asset row — asset count
  unchanged, fingerprint unchanged (D9)
- two mirror runs over changed bytes produce two distinct paths, neither
  overwriting the other (D8)
- the prune spares an asset with `storage_path`, an asset referenced by live
  `item_media`, and an asset under 30 days old (D4)
- `ContentSelectionMigrator` is idempotent across two runs; the 86 are counted as
  dropped, not silently skipped (D10)
- **1a §8.3 regression, extended:** a Google run *after* the Instagram sync and the
  selection migration leaves the 25 upload items and all mirrored Instagram assets
  alive. `preferOwnerAnchored()` protects owner-authored rows from `mergeInto()`'s
  hard delete, but it has never been exercised against a media-kind merge with two
  connector sources present
- every raw-write seam bumps build state, moves `sites.updated_at`, and dispatches
  a purge (§5)

### 6.3 Postgres, not SQLite

Tests run SQLite; production is Postgres. Verified against the live DDL, not a
green suite:

- `item_media_role_check` = `cover|gallery|poster|avatar|logo` — confirmed by
  `pg_get_constraintdef`. `frames` emits only these.
- `media_assets_variant_family_check` = `google|shopify|ytimg|native|proxy`, or
  NULL. Currently NULL on all 501 rows. Mirrored Instagram assets write `'native'`.
- `media_assets_dims_confidence_check` = `measured|declared|guessed`, or NULL.
- `media_assets_fingerprint_unique (user_id, fingerprint)` — the constraint D9
  exists to protect.
- `item_media_asset_id_fkey` is `ON DELETE SET NULL`, which is what makes U7's
  prune safe: deleting a borrowed asset nulls the link rather than cascading into
  items.

### 6.4 Post-deploy

`cloud env:logs partna development --minutes 10`, clean, **and** a Nightwatch scan.
Slice 0's checkpoint recorded a log scan and skipped Nightwatch; 1a's kickoff
called that out. Do not repeat it a third time.

---

## 7. Definition of done

A dev account's media pool contains its Instagram photos, mirrored to R2, surviving
two consecutive syncs with no duplicate assets. Google photos resolve to live URLs
with attribution on the public payload, are not pinnable, and re-resolve on a 7-day
cadence inside the expiry window. The three upload selections are migrated; the 86
undropped rows are recorded as a decision with their site ids. The prune command
re-runs without deleting a live or owned asset. A Google run after all of it leaves
every upload and Instagram item alive.

---

## 8. Reported, not fixed

Found while establishing §2. Neither belongs in this slice; both are real.

### 8.1 `carryForwardPhotoUrls()` has never worked, and it is billing us

`GoogleBusinessService::carryForwardPhotoUrls()` (`:474-493`) skips re-billing by
matching a fresh photo against the prior payload **on `ref`**. Refs rotate (§2.1),
so it never matches, so every legacy refresh re-resolves all 10 photos.

Its SCALE-3 comment — *"never re-billed"* — describes an optimisation that has not
fired since it was written.

**Cost.** Refresh cadence is ~40h (`detailsFetchedAt` freshness), so ~18
resolves/photo/month against the ~1 the URL expiry actually requires:

| Scale | With carry-forward working | Today | Delta |
|---|---|---|---|
| dev, 12 connections | ~120 photo calls/mo | ~2,160/mo | ~$8/mo |
| 500 connected users | ~5,000/mo | ~90,000/mo | **~$600/mo** |

Negligible now, material at pilot scale. Fixing it means matching on something
that does not rotate — which, given §2.1, means matching on nothing available in
the legacy lane. The real fix is the ingest lane (D2), which makes this a slice-7
retirement question rather than a patch.

### 8.2 Place Details is billed twice per place

`integrations:refresh` fetches Details for the legacy payload, and the ingest lane
fetches Details again through `api/places.details`. Same place, same day, two
Enterprise+Atmosphere calls at $25/1,000. Confirmed by the two disjoint ref sets in
§2.1 evidence A — they are two fetches, not one shared one.

Collapsing them is the natural end of the legacy payload's life and belongs with
slice 7, not here.

---

## 9. Out of scope

**Carried from 1a, still out:**

- Retiring the `gallery` / `designMedia` wire keys — blocked on the frontends
- Any dashboard or public render work

**New to this slice:**

- Filling the background picker for the six Google-only sites (D5). Product
  decision, not a migration.
- Whether the backdrop may draw from a live borrowed feed (D5).
- §8.1 and §8.2.

**Carried to slice 7:**

`site.site_media` demotion, the `site.content_selection` drop, collapsing the
double Details bill, and the observers those orphan. Gated on slices 1–6 having
their assertions on record (invariant #3).

---

## 10. Corrections to the parent spec and 1a

Stated explicitly rather than diverged from quietly, per the kickoff.

| Document | Claim | Correction |
|---|---|---|
| parent §2.4, 1a §7, kickoff §3 | the 82 google-photo selections migrate by `external_ref` | Refs rotate (§2.1). Zero of 80 match any live payload ref or any existing asset fingerprint. They are unmigratable **and** already dead. D10. |
| parent §2.4 | read servable URLs from the legacy payload to avoid re-billing | Not implementable — the payload and the ingest lane are disjoint fetches with no join key. D2. |
| kickoff §3.1 | `mapPhoto()` "discards the URL" | `PlacesDetailsDriver` returns the **raw** Places response, which carries no URL at all. There is nothing to stop discarding; the URL must be resolved. D2. |
| kickoff §3.1 | "hotlink or mirror?" — both representable | Neither. Mirroring is prohibited (§2.3), hotlinking expires (§2.2), identity rotates (§2.1). D1. |
| `ContentSelection.php:14` | a Google photo ref is a "stable name" | It is not. Docblock corrected in U2. |
| 1a §1.1 | 10 media items, all google_business | 20 as of 2026-08-12 — a second source ran. §1. |
| 1a §1.4 | 150/150 photo entries carry a url | Holds. But only 60 of 110 **live** entries carry `authors`. D6. |
| 1a §5.2 | the §8.3 regression covers a Google run after the backfill | Extended: two connector sources are present after this slice, not one. §6.2. |
