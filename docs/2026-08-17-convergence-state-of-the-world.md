# Content Pool Convergence — state of the world (2026-08-17)

The programme is **closed on dev**. This is the short version for the owner; the
authoritative record is
`docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`, closing
checkpoint **§31**.

Every figure here was re-derived on 2026-08-17 between 11:05 and 11:12 UTC, not
cited from an earlier document. They are samples with timestamps, not invariants —
see "the one thing to carry forward" at the end.

---

## What shipped

`content.*` is now the single store for curated content. Platforms are sources,
not owners, and **seven pools** serve the public wire off one curation surface:
`custom_links`, `events`, `listen`, `media`, `menus`, `services`, `shop`.

**Ten legacy tables are gone on dev** — the four menu tables and
`site.content_selection` (slice 7), `site.shop_brands` and `site.shop_products`
(shop re-home), and `site.services`, `site.service_categories` and
`site.service_category_assignments` (services cutover). Verified by `to_regclass`,
not by reading a list.

Live off `dev-api.partna.au` (`ollies`, 200, 241KB): menus 40 items / 12
collections · shop 30 / 5 · services 25 · custom_links 23 · media 11 · events 4 ·
listen 3. Across dev, `content.items` holds 323 menu items, 77 media, 77 services,
52 products, 37 links, 20 reviews, 18 events.

Fourteen sessions, thirteen slices and phases, each with a checkpoint on record.

---

## What needs you

**Two rulings were made during this phase and are now recorded** (§31.4) — no
action needed, listed so you know what was decided in your name:

1. `pools.services` carrying Fresha services **stands**. The pool is the union;
   `profile.services` stays owner-authored only. The rebuild must filter by
   `origin` or pick one surface, or a salon renders its services twice.
2. **60s stale public payload is not acceptable** for owner-initiated pool
   mutations. All three cache lanes must fire.

**Four things want a decision from you:**

| | Why it needs you |
|---|---|
| **Production reconciliation** | Prod is not "behind" — it is a **different schema**. It lacks the `content`, `ingest`, `routing` and `catalog` schemas *outright*; `content.items` does not exist there. Ledger: 4 rows vs dev's 106. All ten dropped tables still present. Nothing from this programme has touched it. Scope exists (`plans/2026-08-17-prod-schema-reconciliation.md`); the sequencing is a call, not a task. |
| **#SEC-1** *(auth lane — not this programme)* | `POST /api/public/auth/resolve-identifier` is unauthenticated and returns a user's **private** login email for a public handle. Handles are enumerable by design, so it is a harvesting vector. Its bot-protection middleware is **inert on both environments** (`BOT_PROTECTION_MODE` unset, config defaults to `off`); the only real control is a 20/min per-IP throttle. Prod exposure is nil today — zero users — but dev holds real addresses. Raised as a STOP by the programme review. |
| **LEGAL-2** — Google reviewer PII | Wanted before pilot. |
| `content.item_merges` **still 0** | Cross-platform identity has never been exercised. Not a defect — nothing has forced it — but it means an untested lane ships with the rebuild. |

---

## What is deferred, with an owner

- **Three-lane cache defect.** `ProjectionWriter::bumpSite()` skips the
  `site.sites.updated_at` write, so hand-adding a pool item, deleting one, or
  changing item links serves stale public content for the TTL while the CDN is
  correctly purged. Four endpoints affected. Fixing it at `bumpSite()` closes the
  whole projection-write surface at once. → fix-flow session.
- **Audit backlog** from the review: 0 P0 · 6 P1 · 13 P2 · 15 P3, in
  `audits/sweeps/2026-08-17-*`. → fix-flow sessions.
- **`content.storefronts.user_id` NOT NULL is half-proven.** The UPDATE arm ran
  live; the INSERT arm — the one the constraint would break — is unexercised,
  because all five storefronts predate the migration. Closing it needs a billed
  Apify scrape.
- **Not verified, stated rather than skipped:** Nightwatch was not scanned (needs
  an OAuth grant), and the authenticated verbs (edit / resync / hide / delete /
  restore / reorder) remain unproven — no owner JWT was ever available. The
  services DROPs went in on that basis, knowingly.
- **RLS accepted-posture revisit** · **Google aggregates cadence** ·
  `anseo-studio`'s unprovisionable Fresha URL.

---

## Where the records live

| | |
|---|---|
| Programme record, checkpoints §12–§31 | `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` |
| **Frontend rebuild's input** — one manifest per contract change | `docs/wire-changes/` |
| Session index + standing rulings | `docs/superpowers/plans/2026-08-14-convergence-session-prompts.md` |
| Findings log (F-numbers) · phase map · W#↔Phase# | `docs/convergence-log.md` · `docs/2026-08-14-convergence-phases.md` · `docs/convergence-HANDOFF.md` |
| The review's six audit runs | `audits/sweeps/2026-08-17-*` |
| Prod reconciliation scope | `docs/superpowers/plans/2026-08-17-prod-schema-reconciliation.md` |

The wire manifests describe the new wire **on its own terms**, never as a diff
from the legacy shape — the frontend is being rebuilt, so a diff would document a
shape nobody will write against.

---

## The one thing to carry forward

**A live coverage gate is valid only until the next write.**

This programme watched it happen five times: slice 7's 318/318 became 283/293
across a single scrape and cost 23 rows; `ollies` menus went 65→40 mid-review;
`pool:watch` vanished as four connections were soft-deleted; §26's `partna.*`
count moved; and the dev migration ledger read 105 during the review and 106 an
hour later during phase 8.

**None of those was a regression. Every one of them reads as one.**

Timestamp every reading. Gate on per-row derivation, never on totals — net counts
can *fall* while uncovered rows appear, and the total conceals the hole.
