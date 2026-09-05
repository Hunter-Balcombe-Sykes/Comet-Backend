# Media mirror follow-ups — design

**Date:** 2026-09-05
**Branch:** `fix/media-mirror-followups-2026-09-05`
**Origin:** review of PR #335 (`09edde246` "Mirror media on a managed queue, thumb-first, on a budget") and its three follow-up commits, requested 2026-09-05.

Five defects and structural weaknesses in the media-mirror lane. One has live
user-visible impact; the rest are latent or drift insurance. All five ship in
one PR — owner decision, 2026-09-05.

## Verified facts this design rests on

Every figure below was measured, not assumed. Timestamps matter: the first
reading of the mirror table went stale between two queries two minutes apart
because a pull wrote in between, which is the failure mode CLAUDE.md warns
about under "Gotchas that have each cost a session".

| Fact | Source | Measured |
|---|---|---|
| 29 assets in flight (`mirror_eligible`, no `storage_path`, no `site_media_id`); only 4 on a Meta CDN | dev `content.media_assets` | 2026-09-05 02:31:29Z |
| `mirror_eligible IS NULL` count is 0 — `healMirrorEligible` has fully backfilled | dev | 2026-09-05 02:31:29Z |
| `mirror_attempts >= max` count is 0 — the capped case is latent, not live | dev | 2026-09-05 02:31:29Z |
| `PARTNA_MEDIA_THUMB_EDGE` set on neither dev nor prod | `cloud environment:get` | 2026-09-05 |
| Dashboard consumes neither `pending` nor `thumb`/`posterThumb` | `PartnaAu/partna-monorepo` @ `73a845e2` | 2026-09-05 |

## Out of scope — recorded, not actioned

**The thumb tier is unconsumed.** `MediaMirror` writes a 640px thumbnail
beside every master, `MediaUrlResolver` returns it as `thumb`, and
`PoolResolver` ships it on items and frames. Nothing reads it. The dashboard's
grid tile renders the master:

```ts
const src = item.thumbnail ?? item.frames[0]?.url ?? null;   // media-grid.tsx:314
```

So the ~32 KB-vs-~260 KB win that motivated the thumb-first work in `09edde246`
is not currently realised. The fix is one line in `partna-monorepo`. **Owner
decision 2026-09-05: do not change the frontend.** Recorded here so the next
person reading the perf claim in `09edde246` knows it describes a capability,
not a measured outcome.

Note the observability cost this exposes: because `thumb` is derived from
`storage_path` rather than stored, there is no column and no failed lookup, so
an unconsumed field is indistinguishable from a consumed one on the backend
side.

## Unit 1 — `pending` derives from row state

**Defect (live).** `PoolResolver::pending()` decides "bytes genuinely in
flight" by matching the source URL against `InstagramMediaUrl::isMetaCdn()`,
which recognises `cdninstagram.com` and `fbcdn.net` only. The mirror lane's
own ownership allowlist is four platforms
(`MediaMirror::OWNED_REF_PREFIXES` — instagram, tiktok, facebook, threads).
Two allowlists that must agree, don't. On dev, 25 of 29 in-flight assets are
TikTok CDN and report `pending: false`, so the card renders the empty frame the
flag exists to prevent.

The same predicate has a second, latent bug: an asset whose retries are
exhausted (`mirror_attempts >= MediaMirror::maxAttempts()`) still matches the
URL test and reports `pending: true` forever — a skeleton that never resolves.
Dev count today is 0, so this is latent.

**Root cause.** A vendor-shaped predicate standing in for a state-shaped one.
"Is this coming?" is answerable from columns the row already carries; parsing a
third-party URL to infer it will be wrong again for every platform added after
the parser was written.

**Change.**

```php
private function pending(Collection $rows, array $resolved): bool
{
    $coverRows = $rows->filter(/* cover|poster|gallery */);

    foreach ($coverRows as $row) {
        if (isset($resolved[(string) $row->asset_id])) {
            return false;   // something already renders
        }
    }

    $max = MediaMirror::maxAttempts();

    foreach ($coverRows as $row) {
        if ($row->mirror_eligible === true
            && $row->storage_path === null
            && $row->site_media_id === null
            && (int) $row->mirror_attempts < $max) {
            return true;    // genuinely still coming
        }
    }

    return false;
}
```

`InstagramMediaUrl::isMetaCdn` is NOT deleted — `MediaMirror` still uses it for
the expired-URL pre-flight. Only `pending()` stops calling it.

**Cost.** The cover-rows query in `PoolResolver::hydrateItems` must select
`content.media_assets.mirror_eligible` and `.mirror_attempts`, growing its
column list from 10 to 12. Same rows, no extra round trip.

It sits on TWO hot paths, not one: `PoolWire::forSite` (the public profile
payload) and `SetupPayload::forPass` (`GET /api/site/setup`, measured on dev
2026-09-05 at p50 569ms after the batching work). Two more columns on rows
already being fetched is not a measurable cost, but the query is not a quiet
corner and any future addition to it should be weighed.

**Knock-on.** `PostgresLaneReadCoverageTest` runs in the cheap `composer test`
lane and will demand both columns on every `tests/Postgres/` stand-in that
provisions `content.media_assets` for a file driving `PoolResolver`. Fix by
`ALTER TABLE … ADD COLUMN IF NOT EXISTS` so it survives first-creator-wins —
never by thinning a stand-in or relaxing the assertion.

**No schema change.** Both columns already exist on `content.media_assets`.

**Tests.**
- A TikTok-CDN row in flight reports `pending: true` (fails before the change).
- A row at `mirror_attempts >= max` reports `pending: false`.
- A resolved cover short-circuits to `false` regardless of the other rows.
- A borrowed asset (`mirror_eligible = false`, e.g. a Places photo) reports
  `false` — it is never coming, correctly.

**Wire risk: none.** `pending` is in `PoolResolver::DASHBOARD_ONLY_ITEM_KEYS`,
so it never reaches the public payload, and the dashboard does not read it
(verified above).

## Unit 2 — freeze the thumb edge

**Defect (latent).** `MediaMirror::THUMB_SUFFIX` is the const `'.640.webp'`,
frozen deliberately so an edge change cannot orphan existing objects. But the
rendered edge is configurable via `partna.media.thumb_edge` /
`PARTNA_MEDIA_THUMB_EDGE`. Setting it to 480 writes 480px bytes to a path that
still claims 640, mixed indistinguishably with genuine 640s, with no signal
that a backfill is owed. The knob is unsafe to exercise, which makes it worse
than no knob.

**Why frozen rather than encoded in the path.** `MediaUrlResolver` derives the
thumb URL from `storage_path` by string substitution with no existence check —
that is what makes the tier free of a schema change and free of a HEAD per
image. A variable edge would leave the resolver unable to know which edge a
given row used without either a HEAD per image or a new column, i.e. exactly
the two costs the derived design exists to avoid.

**Why not simply make it settable (owner question, 2026-09-05).** Two reasons.
The filename makes a promise about *size*; changing the setting makes the
filename lie. And every object already on R2 is 640, so a change would not
resize them — it needs a re-encode of the whole bucket. A setting that requires
a backfill to take effect is a constant with extra steps. `thumb_quality` stays
configurable precisely because the filename promises nothing about quality.

The flexibility worth having one day is *more* tiers (a 320 for phones, a 1280
for retina), not a different single tier. That is a tier list with each edge in
its own filename and a map on the wire — the natural `srcset` shape, and a
different design. Build it when a consumer asks; not now.

**Change.**
- Add `public const THUMB_EDGE = 640;` beside `THUMB_SUFFIX`, with a docblock
  stating the two are frozen together and a tier change means new suffix +
  backfill.
- `MediaMirror::thumbEdge()` returns the const.
- Delete `'thumb_edge'` from `config/partna.php` and `PARTNA_MEDIA_THUMB_EDGE`
  from `.env.example`.
- `thumb_quality` unchanged.

**No backfill.** The var was never set on dev or prod (verified above), so no
mislabelled object exists.

**Test.** A guard asserting `THUMB_SUFFIX` encodes `THUMB_EDGE`, so a future
edit cannot move one without the other.

## Unit 3 — one image-vs-video classifier

**Defect (no live symptom — drift insurance).** Two independent determinations
of "is this asset a video":

- `ProjectionWriter::budgetMirrors()` builds `$videoIds` from
  `$entry['role'] === 'video'`, and the dispatch passes
  `video: isset($videoIds[$assetId])`. That flag chooses the queue lane.
- `MediaMirror::isImageOnlyAsset()` re-derives it from
  `content.item_media.role` at mirror time.

They agree today because both trace to the same role data. If they ever
disagree, a video rides the managed queue and takes
`MirrorMediaAssetJob::MANAGED_TIMEOUT` (85s) instead of 120s, and a 15 MB reel
over a cold edge dies at the platform with no reason recorded on the row.

This repo has a documented history of this exact pattern — two independent link
classifiers, three independent name-casers — where the duplicate was harmless
until it wasn't.

**Change — share the RULE, not the read.** The two call sites legitimately
need different reads: `MediaMirror` asks about ONE asset from inside its job,
while `ProjectionWriter` needs the answer for a whole slice at dispatch time
and must not go N+1. Forcing them onto one query would be the wrong fix.

What unifies is the predicate. Extract the role decision to a single shared
rule — `MediaMirror::rolesIndicateVideo(array $roles): bool`, holding today's
`in_array('video', $roles, true)` logic and nothing else — then:

- `MediaMirror::isImageOnlyAsset()` keeps its per-asset read and calls the rule.
- `ProjectionWriter::dispatchMirrors()` reads roles for the slice it is already
  querying (`content.item_media` rows for those asset ids, one batched read
  beside the existing `content.media_assets` read) and calls the same rule to
  set `video:`.

`budgetMirrors()` keeps its own images/videos bucketing untouched — that runs
on projection entries for *budget* purposes and is a different question
("which bucket does this post spend from"), not the lane question. Only the
flag reaching `MirrorMediaAssetJob` changes source.

Ordering note: `dispatchMirrors` runs after the projection has written
`content.item_media`, so the rows are queryable at that point. If
implementation finds otherwise, that invalidates this unit's approach and it
should stop and re-plan rather than fall back to the duplicate rule.

**Owner decision 2026-09-05:** keep this unit in the PR despite having no live
failure.

**Test.** An asset whose `item_media` roles include `video` is dispatched with
`video: true` and never takes `MANAGED_TIMEOUT`.

## Unit 4 — `SitePublishState`

**Weakness.** Two raw cross-domain reads of `site.sites.is_published` from the
ingest layer:

- `ProjectionWriter::siteInSetup()` — flips mirror ordering images-first while
  a site is unpublished. Un-memoised, so it re-queries per dispatch call.
- `SourceScheduler::scoreDue()` — admits a scheduled `auto_sync` source only
  when its owner's site is published.

Both use the query builder directly across a domain boundary. The cost has
already been paid once: this coupling is why PR #335 had to add `is_published`
to nine PG-lane stand-ins.

**Stated honestly:** this unit does NOT undo those nine stand-ins. The read
still happens and the column is still required. What it buys is one stub point
for tests, one place to change when "published" stops being a single boolean —
which the pre-account carve-out means it already nearly isn't, since
`is_published` and "publicly visible" diverged in the public read path on
2026-09-01 — and the removal of a redundant per-dispatch query.

**Change.** New `App\Site\SitePublishState`:

```php
final class SitePublishState
{
    /** @var array<string, bool|null> */
    private array $memo = [];

    public function isPublished(string $userId): ?bool
    {
        return $this->memo[$userId] ??= $this->read($userId);
    }
}
```

Null means "no site row", which both call sites already treat distinctly from
false — `siteInSetup()` returns false for a missing site (not "in setup"), and
that behaviour must be preserved exactly.

Injected into `ProjectionWriter` and `SourceScheduler`. Request-scoped, so the
memo lives for one job / one request; no cache, no TTL, nothing to invalidate.

**Tests.** Two calls for one user issue one query. A missing site row keeps
`siteInSetup()` returning false.

## Unit 5 — document the budget's real meaning

**Correction first.** The review originally framed the 10/6 cap as coupled to
the setup grid's tile count. That was wrong — `media-grid.tsx` renders every
item it is given and caps nothing.

**What is actually true and undocumented.** `budgetMirrors()` caps at 10 image
posts + 6 video posts per platform per pull, one asset per post, newest first,
with already-mirrored posts consuming slots (the anti-creep property). The
consequence nobody has written down: a signup gets one eager pass, so **a new
site's grid can show at most 10 mirrored images**, with the rest arriving over
subsequent 15-minute `ingest:dispatch` ticks.

A second undocumented property: the anti-creep guarantee depends on
`publishedByItem` being populated. When both sides are null the sort falls back
to arrival order, and "newest first" quietly becomes "whatever the vendor
returned first".

**Change.** Comments only — on `config/partna.php`'s `pull_budget` block and on
`budgetMirrors()`. No behaviour change.

## Verification

- `composer test` — the ~20 min cheap lane. Includes
  `PostgresLaneReadCoverageTest`, which Unit 1 will trip until the stand-ins
  gain their columns.
- `composer test:pg` — REQUIRED. Units 1 and 4 change what `ProjectionWriter`
  and `PoolResolver` read, and CLAUDE.md is explicit that a green SQLite run
  says nothing about that lane. This has bitten twice (slice 5a; `da958493e`).
- `vendor/bin/phpstan analyse` and `vendor/bin/pint --test`.
- No migration — every column Unit 1 reads already exists. Nothing to push to
  Supabase.

## Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Unit 1's two extra columns trip PG stand-ins across many files | Medium | Expected and enumerable; fix by ADDING columns, never thinning. `composer test:pg` before review. |
| `pending` semantics change reaches a consumer we did not find | Low | Dashboard-only key; monorepo grepped at `73a845e2` with no hits. |
| Unit 3 changes dispatch flags and silently reroutes a lane | Low-Medium | Test pins the video path explicitly; no config change, so a revert restores exactly. |
| Unit 4's memo holds a stale value within one long job | Low | Publish state does not change mid-projection; memo is request-scoped, not cached. |
| Nothing here is reversible by config | Low | All five are code-only. A revert of the PR restores current behaviour exactly; no data is written or migrated. |

## Non-goals

- Any frontend change (owner decision, 2026-09-05).
- Multi-tier / `srcset` thumbnails — noted as the future extension point, not built.
- Changing the 10/6 budget numbers — Unit 5 documents them, it does not tune them.
- Prod reconciliation. Production lacks the `content` schema entirely; none of
  this reaches it.
