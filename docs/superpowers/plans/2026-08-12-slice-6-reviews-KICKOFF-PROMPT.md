# KICKOFF PROMPT — Slice 6: Reviews → `content.*`

Part of `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7
"Slice 6".

## ⛔ Do NOT start this while slice 1b is in flight

Parent §4.3 **rule 2**. `GoogleBusinessConnector` declares three streams — `profile`,
`reviews`, `media` — and all three are served by a **single** billed call:

```php
$effect = $io->effect('api', 'places.details', ['place_id' => $placeId]);
```

1b enables `media`; this slice enables `reviews`. Run them together and you edit the
same connector file, share one ledger digest (claim-first means one run gets
`refused` while the other holds the claim), and compete for the same Places budget
across the same 12 `google_business` sources. Confirm 1b is merged before starting.

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

```sql
-- 1b must be done: expect media items present and google effects settled
SELECT kind, count(*) FROM content.items WHERE removed_at IS NULL GROUP BY 1;
SELECT kind, cost_tag, status, count(*) FROM ingest.effects GROUP BY 1,2,3;

-- Your target. Expect 0 on both.
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

`content.f_review` holds `author_name`, `author_photo_url`, `rating`, `text`,
`reviewed_at`, PK `(item_id, source_id)`. Three existing mechanisms already govern
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

**This command is written against a table that has always been empty.** Slice 6 is
the first time it will have real rows to act on. Verify it works, do not assume it —
invariant #6 applies to console commands too.

### 3. The public-wire decision
`docs/superpowers/plans/closed/2026-07-30-privacy-p3-docs-EXECUTE-PROMPT.md` records
that the public-wire `reviews` / `reviewSummary` legs were **kept by decision**, with
a mandatory LEGAL-2 follow-through. Slice 6 inherits that obligation. If your design
changes what reviewer data reaches the public wire, that is a legal-review item, not
a refactor — surface it.

## Scope

### Unit 1 — Provision the `reviews` stream and land records
`GoogleBusinessReviewProjector` is registered but has never executed. Reviews are a
`SourceProfile::Sample` stream: vendor-curated, `orderField` null, **never dominates
and never deletes** (`mayDelete()` is false), and no `Covered` message is ever
emitted. The display set is simply whatever the latest ok run returned. Do not
"improve" this into an exhaustive stream.

### Unit 2 — Where reviews render — DECIDED, reviews get a pool
Owner decision 2026-08-12: **all four remaining types get pools**, reviews included.
Add a `PoolRegistry` entry (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`, plus a
`SECTION_SHAPE` block) and provision sections for existing users. `buildPools()`
loops all `POOLS`, so it ships publicly with no payload-builder change.

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
the wire manifest records the change. `reviewSummary` (aggregate rating/count) may
have a different source than the individual reviews — check before assuming one
change covers both.

## Non-negotiables

- **Never weaken a PII control.** Adding a review path that bypasses `redactionScopes`, or leaves `f_review` rows unreachable by `PruneOrphanedReviewPiiCommand`, is a launch blocker.
- **DSAR:** an export must disclose what is held. If reviewer data moves tables, `DataExportPayloadBuilder` follows. The 2026-08-05 precedent is that DSAR allowlists deliberately retain legacy keys so previously-stored payloads stay disclosable.
- **Do not re-bill.** Reviews share `places.details` with `profile` and `media`. If your design triggers extra billed calls, show the digest and freshness reasoning (`partna.ingest.effect_freshness_seconds`, 7 days).
- **Cache invalidation is three lanes** — `BuildState::bump($siteId)`, touch `site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. No CI check enforces this.
- **Schema changes are raw SQL** under `supabase/migrations/`. Pre-assigned prefix block: `20260813110000`–`20260813119999`.
- **Tests run SQLite, production is Postgres.** Verify constraint-bound writes against the DDL.
- **Post-deploy:** `cloud env:logs partna development --minutes 10` **and** a Nightwatch scan. Slice 0's checkpoint recorded a log scan and skipped Nightwatch; do not repeat that.

## Process — stop at every gate

1. **Confirm 1b is merged.** If not, stop — that is the deliverable.
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
