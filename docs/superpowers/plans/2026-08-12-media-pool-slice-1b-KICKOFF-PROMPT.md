# KICKOFF PROMPT — Slice 1b: Google URL pass-through, Instagram mirroring, and the 91 selections

**Use this after slice 1a has landed and been verified on dev.** 1a built the asset
spine (fingerprint inversion, `site_media_id`, `MediaUrlResolver`, `frames[]`, the
corrected `pool:media` rule, the upload backfill). 1b fills that spine with the two
external sources and retires the legacy selection table's media half.

This prompt asks for **a spec first, then an implementation plan** — not code. 1b is
the slice that spends money, mirrors third-party bytes, and takes a data-loss
decision, so it gets the same design-before-build treatment 1a got.

Paste everything below the line into a fresh session. It is self-contained.

---

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — the parent
   programme. §2.1–§2.4, §7 "Slice 1", §9 (cache lanes), §10 (wire changes), and
   especially §3 **Invariants**, which govern this slice.
3. `docs/superpowers/specs/2026-08-12-media-pool-slice-1a-design.md` — the sibling
   spec. Its §7 "Out of scope — carried to 1b" is your scope statement.
4. The 1a implementation plan and its checkpoint, wherever it landed under
   `docs/superpowers/plans/`.
5. `docs/wire-changes/2026-08-12-media-pool-slice-1a.md` — the wire manifest 1a
   produced. 1b's wire changes append to this lineage, they do not restart it.

## Rule zero — you may not assume 1a landed

Convergence invariant #5: **no slice may cite another slice's checkpoint as evidence
for its own claims.** A checkpoint records what someone believed on the day. Re-derive
every 1a fact below from the database and the code before you build on it. Where dev
and this prompt disagree, dev wins and you say so in the spec.

### Entry gate — run these first, paste the output into the spec's §1

```sql
-- 1a's items landed: expect kind='media' ≈ 35 (10 google + 25 uploads)
SELECT kind, count(*) FROM content.items WHERE removed_at IS NULL GROUP BY 1 ORDER BY 2 DESC;

-- 1a's upload backfill: expect 25 with site_media_id
SELECT count(*) FILTER (WHERE site_media_id IS NOT NULL) AS uploads,
       count(*) FILTER (WHERE storage_path IS NOT NULL) AS mirrored,
       count(*) FILTER (WHERE source_url IS NOT NULL)   AS hotlinked,
       count(*) AS total
FROM content.media_assets;

-- 1a's rule correction: expect 0
SELECT count(*) FROM site.sections
WHERE kind='collection' AND rule::text LIKE '%latest_per_auto_source%'
  AND rule::text LIKE '%"media"%';

-- what 1b has to migrate
SELECT type, count(*) FROM site.content_selection GROUP BY 1 ORDER BY 2 DESC;

-- the lanes 1b turns on
SELECT kind, count(*), count(*) FILTER (WHERE auto_sync) AS auto,
       count(*) FILTER (WHERE last_run_at IS NOT NULL) AS ever_ran
FROM ingest.sources GROUP BY 1;
SELECT s.kind, st.name, count(*) FROM ingest.streams st
JOIN ingest.sources s ON s.id = st.source_id GROUP BY 1,2;
SELECT kind, name, status, count(*) FROM ingest.effects GROUP BY 1,2,3;
```

### Code gate — the one ordering constraint that must not be violated

Open `app/Ingest/Projection/ProjectionWriter.php::mediaFingerprint()` and confirm with
your own eyes that it reads `$fingerprint = $ref ?? $url` (ref preferred), not
`$url ?? $ref`.

**If it still prefers `url`, stop.** 1a did not land, and unit 1 below — adding `url`
to `GoogleBusinessConnector::mapPhoto()` — will silently re-key the existing Google
assets under `UNIQUE (user_id, fingerprint)`, mint a duplicate set via
`resolveMediaAssets()`'s `insertOrIgnore`, and leave `content.item_media.asset_id`
pointing at the orphaned originals. That is the exact duplication 1a exists to
prevent. Report the gate failure and stop; do not "fix it as part of 1b".

Also confirm on **production**, not just dev, that no `content.media_assets` row is
keyed off a URL for a projector that also emits a ref. 1a's no-migration-needed
argument was verified against dev only and explicitly deferred prod verification.

## Scope — the four questions 1b must answer

Everything here comes from 1a §7 and parent §2.4. Treat each as a **decision to take
explicitly and record**, not a mapping exercise to grind through.

### 1. Google photos — pass the URL through, without re-billing

`app/Ingest/Connectors/GoogleBusinessConnector.php:256-277` (`mapPhoto()`) reads the
raw Places shape and emits `ref`, `width_px`, `height_px`, `authors` — and discards
the URL. Meanwhile `app/Services/Platforms/GoogleBusinessService.php` already resolves
and stores servable URLs: `resolvePhotoUrls()` (~`:516`) makes the billed per-photo
media call, `carryForwardPhotoUrls()` (~`:474`) re-uses stored ones on refresh so a
re-sync does not re-bill. 1a verified 150/150 photo entries in the legacy
`site.platform_connections` payload carry an unkeyed
`https://lh3.googleusercontent.com/place-photos/...` URL.

Decide and justify:

- **Hotlink or mirror?** The parent's `variant_family` CHECK already admits `'google'`
  and `'proxy'` alongside `'native'`, so both answers are representable. Weigh: do
  those unkeyed lh3 URLs expire? What happens to a public gallery when one does?
- **Where does the URL enter the lane** — connector `mapPhoto()` from the live fetch,
  or a one-time read out of the legacy payload for the 10 existing items? These are
  different code paths with different billing consequences. The parent's §2.4
  recommendation is the payload read; say whether you are following it.
- **Do not re-bill.** Any design that re-runs Places Details per photo to obtain a URL
  we already have stored is rejected on cost grounds. If your design touches
  `ingest.effects`, show the digest/freshness reasoning.
- **Attribution.** `mapPhoto()` collects `authorAttributions` and
  `content.brand_asset_refs` has an `attribution` column — `content.media_assets` does
  not. Google's Places terms require attribution display for photos. Decide whether
  1b adds a column, carries it on `item_media`, or defers, and if it defers, say what
  that means for the pilot. Flag anything with legal weight rather than absorbing it.
- **The NULL `headline_cache` on all ten Google media items.** Decide whether a photo
  needs a headline at all, or whether the pool contract should tolerate a null one by
  design. This is a contract question, not a data fix.

### 2. Instagram — provision the stream, mirror the bytes

`app/Ingest/Connectors/InstagramConnector.php:71` already declares a `media`
StreamSpec. `ingest.sources` has one instagram row, `auto_sync=false`, never run.

**Reconcile the stale premises before designing.** Three documents pre-date 1a and
each carries at least one claim that is now false:

| Document | Stale claim to re-check |
|---|---|
| `docs/superpowers/plans/2026-08-11-media-pool-instagram-EXECUTE-PROMPT.md` | "blocked on exactly one unbuilt thing: the driver that performs a billed effect". `app/Ingest/Runtime/Effects/InstagramActorDriver.php` now exists. Whether it has ever *run* is a separate fact — check `ingest.effects` for `actor/instagram`. Invariant #6: registration is not execution. |
| `docs/superpowers/specs/2026-08-11-instagram-multi-photo-mirror-design.md` | Status is "pending owner sign-off". It designs mirroring into the **legacy** `platform_connections.payload` lane, which 1b is meant to replace. Decide: does 1b supersede it, absorb its findings, or leave it live alongside? Say which, in writing. Its §1.1 latent bug — mirrored photos written to a fixed `platforms/instagram/<ts>/photo.jpg` that every refresh overwrites in place — is a real hazard your path design must not reproduce. |
| `docs/superpowers/plans/2026-08-11-media-pool-google-EXECUTE-PROMPT.md` | Written when `content.items kind='media'` was 0. Useful for its "already built, do not rebuild" table; unreliable for state. |

Design constraints:

- **`App\Services\Brand\BrandAssetPipeline` is the donor**, not the writer.
  `storeAsset()` (`:187-222`) fetches via `SafeUrlFetcher`, re-encodes to webp, puts to
  `config('partna.media_disk')`, and inserts `content.media_assets` with
  `storage_path`, measured dims, `dims_confidence='measured'`,
  `variant_family='native'`. It has produced **zero rows on dev** — built and
  unexercised. Verify that before leaning on it.
- **The fingerprint collision is the trap.** `BrandAssetPipeline` keys on a bare
  **content hash** of the decoded bytes (deliberately, per its `#PRIV-5` comment);
  `ProjectionWriter` keys on `url-sha1(...)`. If a generalised mirror *mints its own*
  asset row for an image `ProjectionWriter` already minted under `url-sha1(ref)`, you
  get two asset rows for one photo and `item_media` points at only one. The mirror must
  **write `storage_path` onto the ProjectionWriter-minted row**, or the two writers
  must be reconciled into one — and 1a's §1.2 already notes the "exactly two writers"
  premise in parent §2.1 was false. State which you chose.
- **Paths must be content-addressed**, so a re-sync of changed bytes cannot overwrite
  a URL a user has already picked (see the multi-photo spec's §1.1).
- **`SecretParams::minimiseUrl()`** — 1a states it redacts `_nc_sid` but not
  `oh` / `oe` / `_nc_ohc`, so an IG URL re-signs every sync. Verify the actual strip
  list yourself. After 1a this no longer affects *identity* (the ref is the key), but
  it does affect `source_url` churn, which is the reason to mirror rather than store
  links. Confirm the reasoning still holds.
- **Budget.** `CostClass::Actor` is weighted 50 and `InstagramActorDriver` claims
  against `ApifyBudget` — one slot normally, two on a thin profile. Say what a media
  stream run costs and how often it may run.
- **The proof the parent asks for:** no duplicate assets after **two consecutive**
  Instagram syncs. That is a live assertion, not a unit test.

### 3. The 91 `site.content_selection` rows

Verified on dev at 1a time: google-photo 82, ig-post 4, ig-reel 2, upload 3.

- 82 `google-photo` migrate by `external_ref` against the legacy payload's resolved
  URLs.
- 3 `upload` migrate by `media_id` — check whether 1a's backfiller already gave these
  items a home, so you migrate the *selection*, not the item.
- **The 6 `ig-post` / `ig-reel` rows carry neither `media_id` nor `external_ref`.**
  They resolve positionally against a live connection payload. There is nothing stable
  to migrate them by. Re-verify that on dev, and if it holds, this is a **data-loss
  decision to take explicitly** — whose 6 rows, on whose sites, and what the owner sees
  after. Do not paper over it with a positional guess.

Migration is production code under `app/Services/Migration/` with an artisan command
and `--dry-run`, per invariant #4. Idempotent, re-runnable, counts reported.

### 4. What 1b does NOT do

- **The `gallery` / `designMedia` wire keys stay.** They cannot retire until the
  frontends stop reading them. 1a set this boundary; 1b holds it.
- **No dashboard work, no public render work.** Backend only, plus a wire-change
  manifest naming the consuming repos.
- **`site.site_media` demotion and the `site.content_selection` drop are slice 7**,
  gated on 1–6 having their assertions on record (invariant #3).

## Non-negotiables carried from 1a

- **Cache invalidation is three lanes, not one.** Every raw-write seam (both migration
  commands, the mirror) must: `BuildState::bump($siteId)`; touch `site.sites.updated_at`
  (because `IndividualProfilePayloadBuilder::cacheKey()` composes the key from it, and
  `bump()` writes a different table); and dispatch `CloudflareCachePurgeJob`. There is
  **no CI check** that enforces this — `BuildState`'s docblock claims one and it does
  not exist. Assert it in tests directly.
- **`item_media_role_check` is `cover|gallery|poster|avatar|logo`.** Tests run SQLite;
  production is Postgres. Verify every constraint-bound write against the DDL in
  `supabase/migrations/`, not against a green suite.
- **Schema changes are raw SQL under `supabase/migrations/`**, one `CONCURRENTLY`
  statement per file, never a Laravel migration.
- **Every outbound fetch goes through `SafeUrlFetcher`** (category B). An lh3 or IG CDN
  URL that arrived in a third-party payload is untrusted by definition. Adding a host
  allowlist entry is not the fix.
- **The `mergeInto()` regression still applies.** `preferOwnerAnchored()` protects
  owner-authored items from the hard delete, but 1a was the first media-kind exercise.
  A Google or Instagram run landing after the migration must leave the 25 upload items
  and the migrated selections alive — assert it.
- **Invariant #1:** no unit is done without a live dev assertion, SQL and output pasted
  into the checkpoint.
- **Post-deploy:** `cloud env:logs partna development --minutes 10` **and** a Nightwatch
  scan. Slice 0's checkpoint recorded a log scan and skipped Nightwatch; do not repeat
  that.

## Process

1. **Brainstorm first** (`superpowers:brainstorming`). Do not go straight to a spec —
   the four questions above are genuine decisions and at least two have no obvious
   right answer.
2. **Write the spec** to
   `docs/superpowers/specs/2026-08-12-media-pool-slice-1b-design.md`, matching 1a's
   structure: §1 verified state with the entry-gate output, then the decisions with
   their reasoning, the change as ordered units, cache lanes, verification, definition
   of done, out-of-scope. Where 1b contradicts the parent or 1a, state the correction
   explicitly rather than quietly diverging.
3. **Stop for sign-off.** This slice hits money (billed effects), third-party bytes,
   migrations, and the public wire — every one of those trips the blocker gate. Spec
   is reviewed before the plan is written.
4. **Then the plan** (`superpowers:writing-plans`) to
   `docs/superpowers/plans/2026-08-12-media-pool-slice-1b.md`, per-unit
   plan → implement → independent review, with the checkpoint structure 1a used.
5. **Wire manifest** at `docs/wire-changes/2026-08-12-media-pool-slice-1b.md`, before
   and after shapes, consuming repos named.

## Definition of done for THIS session

A reviewed spec and an implementation plan. **No production code.** If the entry gate
fails, the deliverable is the gate failure report and nothing else.
