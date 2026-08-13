# KICKOFF PROMPT — Slice 6: Reviews → `content.*`

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7
"Slice 6".

## ✅ Slice 1b is MERGED (2026-08-13) — the rule-2 block is lifted

Parent §4.3 **rule 2** held this slice behind 1b because `GoogleBusinessConnector`
declares three streams — `profile`, `reviews`, `media` — all served by a **single**
billed call:

```php
$effect = $io->effect('api', 'places.details', ['place_id' => $placeId]);
```

1b enabled `media` and is now on `development` (checkpoint §15). **You may start.**
Rebase onto `origin/development` first — 1b changed the connector under you.

### What 1b changed in your blast radius, and what it means for you

**1. `mapPhoto()`'s output shape changed.** The flat `authors` list of display
names is gone; photo credits are now a structured `attribution` block
(`{authors:[{name,uri}], maps_uri, flag_uri}`). Reviews are untouched — but if
you copy `mapPhoto()` as a model for a review mapper, copy the current one.

**2. `PlacesDetailsDriver` now resolves photo URLs inside the same effect.** The
digest is unchanged (still `['place_id' => …]`), deliberately: splitting media
onto its own effect kind would cost $25/1000 twice to isolate $7/1000 photo
calls. **Do not add an input key to this effect.** If your design needs one,
that is a blocker-gate conversation, not a detail — it doubles the Details bill
for every user.

**3. A REPLAYED effect does not re-run the driver — verified live, and this will
bite you.** When the ledger replays a cached Details result inside its freshness
window (`partna.ingest.effect_freshness_seconds`, 7 days), `run()` is never
called and you get the payload as it was captured. Observed on dev: a
`google_business` source re-run reported `effects_count: 0, cost_claimed: 0` and
the media assets came back **without** the new URL fields, because the cached
result predated the change. **Consequence for you:** after you change review
mapping, a source that ran within the last 7 days will keep landing the OLD
shape. To verify your work live you need a place that has never run, or you must
wait out freshness — re-running a recent source proves nothing.

**4. `ThirdPartyPii` now carries a corrected premise — read it before touching
reviewer PII.** `ThirdPartyPii::NESTED_KEYS = ['photos' => ['authors']]` strips
Google contributor names at two read boundaries. Its docblock used to justify
that with *"no attribution obligation attaches — photo refs are not yet resolved
to images"*. 1b made that false for photos and resolved it **by surface**: public
render carries photo credits (Places terms require it on display), DSAR export
and the legacy integration payload keep stripping them.

**That asymmetry is deliberate and is now documented in the class.** Do not
"resolve the inconsistency" by making the lanes agree. And note the shape of the
mistake, because your slice is the one most likely to repeat it: the connector
manifest's `redactions` list is **not** the only redaction registry, and checking
only it is exactly how 1b's spec missed this.

**5. Google `auto_sync` is OFF on all 12 sources**, as it was before 1b, and
`min_interval_secs` is now `604800` (7 days) on every one of them.
`StreamSpec` has **no** per-stream interval — cadence is a source-row setting,
not a manifest one. Enabling `reviews` means flipping `auto_sync`, and doing so
bills every enabled place.

**This slice handles third-party personal data and carries a P0 legal obligation.**
Treat every PII decision as a blocker-gate item, not a detail.

Paste everything below the line into a fresh session. It is self-contained.

---

**First action: rename this session to `slice-6-reviews`** so it is identifiable in
Remote Control instead of appearing as a machine name.

Read, in full, before touching anything:

1. `CLAUDE.md`
2. `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` — §3
   **Invariants**, §4.3 concurrency rules, §7 "Slice 6", §9, §10.
3. `docs/legal/reviewer-data-disclosure.md` — the LEGAL-2 obligation in full.
4. `docs/checklists/launch-readiness-checklist.md` — LEGAL-2 · P0, due **before the
   first pilot customer signs**.
5. `app/Console/Commands/PruneOrphanedReviewPiiCommand.php` — the `#PRIV-3` retention
   mechanism, docblock included. It already governs `content.f_review`.
6. `app/Ingest/Connectors/GoogleBusinessConnector.php` — especially the
   `Manifest::$redactionScopes` docblock and `GoogleBusinessReviewProjector`.

## Rule zero — you may not assume any checkpoint is true

Parent invariant #5 and #6. Re-derive from dev. Where dev and this prompt disagree,
dev wins and you say so in the spec.

### Entry gate — run these first, paste output into the spec's §1

**Measured on dev 2026-08-13; five of this prompt's premises were wrong and are corrected
in place below.** Re-derive rather than trusting these numbers, but do not start from the
assumption that reviews have never landed — they have.

```sql
-- 1b must be done: expect media items present and google effects settled
SELECT kind, count(*) FROM content.items WHERE removed_at IS NULL GROUP BY 1;
SELECT kind, cost_tag, status, count(*) FROM ingest.effects GROUP BY 1,2,3;

-- Your target. MEASURED 2026-08-13: 15 and 15, not 0 — the connector's profile /
-- reviews / media streams share ONE places.details effect, so slice 1b's media
-- runs landed reviews with them. GoogleBusinessReviewProjector HAS executed.
SELECT count(*) FROM content.f_review;
SELECT count(*) FROM content.source_items WHERE kind = 'review';

-- The streams. Confirm 'reviews' is provisioned and whether it has ever run.
SELECT s.source_key, st.stream_name, s.last_run_at, s.auto_sync, s.health
FROM ingest.sources s LEFT JOIN ingest.streams st ON st.source_id = s.id
WHERE s.source_key = 'google_business' ORDER BY 2;

-- Where reviews live TODAY, so you know what you are replacing
SELECT platform, count(*) FROM site.platform_connections
WHERE platform = 'google-business' GROUP BY 1;

-- Claimed vs unclaimed drives the redaction scope
SELECT status, count(*) FROM core.users GROUP BY 1;
```

## The PII contract — get this right before anything else

`content.f_review` holds `author_name`, `author_photo_url`, `author_uri` (added
2026-08-13 for wire parity), `rating`, `text`, `reviewed_at`, PK `(item_id, source_id)`.
Three existing mechanisms already govern
that data and **none of them may be weakened**:

### 1. `when_unclaimed` redaction
`GoogleBusinessConnector`'s `Manifest::$redactionScopes` declares `author`,
`author_uri` and `author_photo` as `when_unclaimed`: an unclaimed owner never held
this data by consent, so it is stripped **before landing**; a claimed account keeps
full attribution. Its own docblock calls this the live-regression guard — get the
scope wrong and you either leak PII for unclaimed accounts or silently drop
attribution the moment someone claims their listing.

Assert **both** directions with a test: unclaimed lands redacted, claimed lands
attributed, and a claim *transition* behaves correctly.

### 2. `#PRIV-3` orphan pruning
`PruneOrphanedReviewPiiCommand` hard-deletes `f_review` rows once no **live**
`content.source_items` row points at that `(item_id, source_id)` pair, with a 14-day
grace window (`partna.ingest.review_pii_orphan_grace_days`). Its docblock explains
why it is orphan-based rather than a TTL: `ingest:dispatch` re-projects every 15
minutes, so an age rule over live rows never terminates.

**This command has never run against a non-empty table.** `content.f_review` holds 15
rows as of 2026-08-13 and the command has never acted on one. Verify it works, do not
assume it — invariant #6 applies to console commands too. Its stated guarantee was also
incomplete: the reviewer's name additionally sat in `content.items.headline_cache` and
`content.f_text.headline`, which it does not reach.

### 3. The public-wire decision
`docs/superpowers/plans/closed/2026-07-30-privacy-p3-docs-EXECUTE-PROMPT.md` records
that the public-wire `reviews` / `reviewSummary` legs were **kept by decision**, with
a mandatory LEGAL-2 follow-through. Slice 6 inherits that obligation. If your design
changes what reviewer data reaches the public wire, that is a legal-review item, not
a refactor — surface it.

## Scope

### Unit 1 — Verify the ingest lane (reviews already land)
`GoogleBusinessReviewProjector` **has executed** — 15 records on dev as of 2026-08-13.
This unit proves the contract rather than building it: redaction in both directions and
across a claim transition, and `PruneOrphanedReviewPiiCommand` exercised against real
rows for the first time. Reviews are a
`SourceProfile::Sample` stream: vendor-curated, `orderField` null, **never dominates
and never deletes** (`mayDelete()` is false), and no `Covered` message is ever
emitted. The display set is simply whatever the latest ok run returned. Do not
"improve" this into an exhaustive stream.

### Unit 2 — Where reviews render — DECIDED, reviews get a pool
Owner decision 2026-08-12: **all four remaining types get pools**, reviews included.
Add a `PoolRegistry` entry (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`, plus a
`SECTION_SHAPE` block). `buildPools()` loops all `POOLS` and `PoolResolver::resolve()`
provisions the section on first read, so no backfill command is needed.

**It does NOT ship with no payload-builder change.** `PoolResolver::itemPayloads()` joins
nine facet tables and `f_review` is not among them; no public lane reads `f_review` at
all. A registry-only change ships a review card with no rating and no text — the pool
needs a `review` block on the item payload, and `ITEM_KEYS` updated with it.

Two constraints that follow from what a review *is*:

- **Not** in `LATEST_TAG_POOLS`. A "latest review" tag is meaningless and would
  misrepresent a vendor-curated sample as a chronology.
- The stream is `SourceProfile::Sample` — vendor-curated, `orderField` null, never
  dominates, never deletes. The pool's auto-rule must not assume a complete or
  ordered set. If the default `SECTION_SHAPE` rule implies either, give reviews its
  own shape rather than bending the default.

Owner curation of reviews (pinning a favourable one, excluding a bad one) is now
*possible* via the pool. Whether that is desirable is a product question — if you
think it is not, say so in the spec rather than quietly omitting the capability.

### Unit 3 — Retire the legacy read
Once `content.*` serves reviews, the `platform_connections`-payload read retires and
the wire manifest records the change. **`reviewSummary` is not the only companion:
FOUR keys sit behind one owner toggle** (`DisplaySettingsFilter:33`) — `reviews`,
`reviewSummary`, `rating` and `reviewCount` — and only the first has a `content.*` home.
Retiring the read means finding a home for the other three, or the retirement drops
three published fields on the floor.

## What slice 3b changed under you (verified on dev 2026-08-13)

Verify each yourself; this is a pointer, not evidence. Rule zero still applies.

- **`Pull.config` now carries a third key: `selection_ref` (`string|null`).** It sits
  beside `scope` and `scope_n`, and it is how a connector learns *which* sub-account
  the owner picked without reading a user. **A connector must treat `null` as
  land-nothing, never fetch-everything.** For a billed connector that rule is also a
  cost control: fetching a whole account because nobody chose anything spends money
  on data no owner asked for. `'storewide'` is a reserved token, not an id.
- **`selection_ref` is populated by `SourceProvisioner::sync()`, and it ships NULL.**
  See the deploy step below.
- **`ProjectionWriter` accepts a `collections` key on a projection.** If reviews ever
  need grouping, use it rather than writing a per-connector collections writer — 3b
  added the shared one and it handles the natural key
  (`collections_user_kind_external_ref_uq` on `user_id, kind, external_ref`) and the
  replace-by-source membership DELETE. **`position` on a collection is a SEED**:
  written on insert, never updated, because an owner can reorder and a scheduled run
  must not snap that back. `label` *is* updated (follow a vendor rename rather than
  duplicating it); `removed_at` is in neither list, so a scrape cannot resurrect a
  collection the owner deleted.

### DEPLOY STEP — nothing lands until sync has run

After a slice that adds a provisioning field like `selection_ref` deploys to an
environment, **nothing lands until `SourceProvisioner::sync()` has run for each
affected connection.** On dev, every Fresha source had `selection_ref = NULL` after
the migration and the first scheduled run would have landed nothing, everywhere,
while reporting success.

**Do not reach for `ingest:backfill-sources` unqualified.** Its dry-run on dev showed
it would process **80 connections across every connector**, bumping `next_attempt_at`
on unrelated sources. For this slice that matters twice over: bumping a Google
source's `next_attempt_at` is a **billed** call you did not intend. Scope the sync to
the connections that actually need it.

### For whoever runs your merge gate

`./vendor/bin/phpstan analyse` in a worktree dies with
`Child process error (exit code 255): while running parallel worker` and reports
"Result is incomplete because of severe errors", and it OOMs at the default 128M.
**Neither failure looks like what it is.** Use:

```bash
php -d memory_limit=1G ./vendor/bin/phpstan analyse <path> --no-progress --debug
```

`--debug` disables parallelism. Under that invocation the real errors print normally.

## Non-negotiables

- **The pool item payload already grew for this.** Slice 5b added four
  additive, nullable keys to every pool item regardless of kind —
  `description` (`f_text.body`), `vendor` (`f_catalog.vendor`), `variants`
  (list), `collectionIds` (list) — plus an additive `collections` map on the
  pool envelope, keyed by collection **uuid**, carrying `externalRef` /
  `provider` / `url` / `name` / `currency` / `favicon` / `logo` /
  `discountCode` / `position`. A review has no obvious use for either, but
  every pool item carries all four keys now — expect `description: null,
  vendor: null, variants: [], collectionIds: []` on a review item unless you
  have a real use for one of them, not an empty-payload bug.
- **The payload enforcement point is now `PoolResolver::ITEM_KEYS` /
  `STORE_KEYS` / `VARIANT_KEYS`, pinned by `tests/Feature/Content/PoolWireShapeTest.php`.**
  It replaces the retired shop allowlist mechanism and is strictly stronger —
  it fails on key **additions** as well as removals, because every payload is
  built key-by-key rather than filtered from a blob. If a review needs a new
  wire key, add it to `ITEM_KEYS` or the test fails; do not spread a row.
- **`content.items.removed_at` clearing is narrowed, not absolute — and 5b
  enforces the narrowing caller-side, not inside the write function.** Parent
  §9.8 (narrowed 2026-08-13 by slice 5b §3.3): an **owner-authored** write may
  clear it; a **connector** re-observing an item never may. A Google review
  that disappears and later reappears in the same scrape is a connector
  re-observation, not an owner act — it must not clear `removed_at`. **Do not
  assume a write function like `ShopContentWriter::syncStore()` guards this
  itself — it does not; its un-retire step is unconditional for every item it
  links.** The boundary is enforced by its callers: `ShopFetch` skips
  hand-curated brands and the individual bucket before ever reaching
  `syncStore()`, so only connector-driven items it retired can be
  un-retired by the connector path. If you copy this pattern for reviews, put
  the owner/connector gate in your caller, and check it explicitly — do not
  assume it, and do not put it inside the shared writer without checking who
  else calls that writer.
- **Never weaken a PII control.** Adding a review path that bypasses `redactionScopes`, or leaves `f_review` rows unreachable by `PruneOrphanedReviewPiiCommand`, is a launch blocker.
- **DSAR:** an export must disclose what is held. If reviewer data moves tables, `DataExportPayloadBuilder` follows. The 2026-08-05 precedent is that DSAR allowlists deliberately retain legacy keys so previously-stored payloads stay disclosable.
- **Do not re-bill.** Reviews share `places.details` with `profile` and `media`. If your design triggers extra billed calls, show the digest and freshness reasoning (`partna.ingest.effect_freshness_seconds`, 7 days).
- **Cache invalidation is three lanes** — `BuildState::bump($siteId)`, touch `site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. No CI check enforces this.
- **Schema changes are raw SQL** under `supabase/migrations/`. Pre-assigned prefix block: `20260813110000`–`20260813119999`.
- **Tests run SQLite, production is Postgres.** Verify constraint-bound writes against the DDL.
- **Post-deploy:** `cloud env:logs partna development --minutes 10` **and** a Nightwatch scan. Slice 0's checkpoint recorded a log scan and skipped Nightwatch; do not repeat that.

## If reality diverges, update the downstream prompts — do not just note it

**A checkpoint is not a communication channel.** Parent invariant #5 forbids any
slice citing another's checkpoint as evidence, so writing a discovery only into your
own checkpoint guarantees the next session never acts on it. **Edit their prompt.**

You inherit `GoogleBusinessConnector` from 1b, which makes you the most likely slice
to find that an upstream premise has moved. Propagate it **before you merge**:

| You discover / change | Update |
|---|---|
| A parent-spec fact is now wrong (a count, a claim, a constraint) | The parent spec's §1 and its revision note, in place |
| You changed `GoogleBusinessConnector`, `PlacesDetailsDriver`, or anything in the billed-effect lane | `slice-7-teardown`, and the parent spec §5 if the driver contract itself moved |
| You changed `ProjectionWriter`, `PoolResolver` or `PoolRegistry` | `slice-4-menus`, `slice-7-teardown`, and any of 3 / 5 still unmerged |
| A PII or redaction behaviour turned out different from `redactionScopes`' docblock | The parent spec §9, `slice-7-teardown` (DSAR), and `docs/legal/reviewer-data-disclosure.md` |
| `PruneOrphanedReviewPiiCommand` needed changes to work on real rows | `slice-7-teardown` — it must not drop tables the command still depends on |
| You consumed migration filename prefixes outside your block | Whichever slice owns the block you took from |

Two rules for the edit itself:

- **Edit in place; do not append a "correction" section.** A prompt read top to
  bottom must be true. A stale statement with a correction 80 lines later will be
  acted on before the correction is reached.
- **Say the fact, not the story.**

Anything with legal weight is different: **do not quietly propagate it.** If a
redaction, retention or disclosure behaviour is not what the documents claim, stop
and raise it. That is a decision, not an edit.

## Process — stop at every gate

1. **1b is merged** (2026-08-13, checkpoint §15). Rebase onto `origin/development` and re-derive its state yourself — do not cite its checkpoint (invariant #5).
2. **Recon + entry gate.** **STOP — sign-off.**
3. **Brainstorm** (`superpowers:brainstorming`) — unit 2 is a genuine open question and the PII contract needs deliberate design.
4. **Spec** → `docs/superpowers/specs/2026-08-12-slice-6-reviews-design.md`, with an explicit PII section. **STOP — sign-off. This slice touches personal data and a P0 legal item; the blocker gate applies.**
5. **Plan** (`superpowers:writing-plans`) → `docs/superpowers/plans/2026-08-12-slice-6-reviews.md`. **STOP — sign-off.**
6. **Implement** in a dedicated worktree, per unit: plan → implement → independent review → tick.
7. **Independent review** of the whole diff, explicitly including a PII pass. **STOP — sign-off.**
8. **Verify on dev.** Live SQL assertions pasted into a parent-spec checkpoint, including redaction proven in both directions and `PruneOrphanedReviewPiiCommand` exercised against real rows. Wire manifest at `docs/wire-changes/2026-08-12-slice-6-reviews.md`.
9. **Merge + push.** Rebase onto `development`, full suite + PHPStan + Pint green, **STOP — explicit sign-off**, then merge and push. Never push to `production`.

## Definition of done

Google reviews land in `content.items` + `content.f_review` through the ingest lane;
`when_unclaimed` redaction proven in both directions and across a claim transition;
`PruneOrphanedReviewPiiCommand` demonstrated working against real rows; the public
wire serves reviews from `content.*` with the legacy payload read retired; no
additional billed calls; LEGAL-2 status restated in the checkpoint — whether this
slice discharges any of it, or merely inherits it unchanged.
