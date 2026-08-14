# Convergence — handoff brief

**For:** `jhunter7333` (backend)
**From:** planning session, 2026-08-14. Branch `feat/platform-pool-convergence`.
**Status:** planning complete, **no code changed**. Every figure re-derived from
dev; every mechanism read from source, not from older specs.

---

## What this is

The convergence programme's remaining backend work, scoped and verified: get
onto `content.*` + `app/Ingest` entirely, retire the legacy parallel stores,
and delete the declared-but-unbuilt seams. It is deliberately written to be
**reconciled with your slices**, not to replace them — see "Overlap" below.

Read in this order:

| File | What it is |
|---|---|
| `2026-08-14-platform-and-pool-convergence-scope.md` | the verified spec — state of the world |
| `2026-08-14-convergence-RUNBOOK.md` | constraints, environment traps, baseline, phase list |
| `2026-08-14-convergence-phases.md` | Phase 1–2 executable; 3–8 settled decisions |
| `convergence-log.md` | 17 findings, corrections, capability baseline |

## W# ↔ Phase# ↔ session mapping

The scope doc numbers work items W1–W10; the phases doc and the session order
(`2026-08-14-convergence-session-prompts.md`) number phases. They map:

| W# (scope doc) | Phase | Session |
|---|---|---|
| W1 identity keys | Phase 2 | `phase-2-identity-keys` |
| W2 listen sourcing | Phase 4 | `phase-4-listen` |
| W3 lean out | Phase 1 (the `channel` KIND finishes in Phase 4) | `phase-1-lean-out` |
| W4 menu convergence | Phase 3 (menu half) + Phase 5 live proof | `slice-4-menus` |
| W5 custom links pool | Phase 3 (links half) — **DONE 2026-08-15**, spec §22 | `custom-links-pool` |
| W6 pseudo-platform retirement | Phase 6 | `phase-6-pseudo-platforms` |
| W7 cutover + teardown | Phase 7 | `slice-7-teardown` |
| W8 documentation truth pass | Phase 8 | `phase-8-docs` |
| W9 delete `profile_fields` seam | Phase 1 | `phase-1-lean-out` |
| W10 `document` kind | — kept (owner reversal, log F8); nothing to do | — |

---

## Overlap with your work — please read first

Your 29 commits from 2026-08-13/14 were rebased under this branch and the plan
adjusted around them. Three intersections:

**1. Slice 6 (reviews) has landed — removed from this scope.** Your reviews
pool, `content.source_stats`, PII purging and legacy-review-read retirement are
all in. The 9-pool end state now only owes **`menu`** and **`links`**.

**2. `PoolRegistry` is shared.** Your `8dd1ff989` is the template Phase 3
follows — it proved pool addition is registry config plus one test, no
migration. Phase 3 edits the same file, so it should not run concurrently with
further pool work of yours.

**3. Slice 7 teardown is yours — Phase 7 defers to it.** Do not run a
competing teardown. Two corrections your kickoff needs:

- It says *"Supabase is on the Free plan: no PITR, no managed backups."*
  **The owner upgraded to Pro on 2026-08-14** — daily backups now exist.
- **Gate 2 is overridden by the owner.** It is genuinely unmet (`apps/pages`
  still reads `designMedia`; nothing reads `pools.media`) — but the owner has
  ruled the **frontend may break and will be rebuilt afterwards**. So there is
  no wire compatibility to preserve: delete the legacy wire keys outright
  rather than dual-serving them.

Your `slice-7` "rule zero" — *no slice may cite another slice's checkpoint as
evidence for its own claims* — is adopted here and is the right instinct. Every
number below was re-derived rather than inherited, and doing so corrected five
figures in the parent spec.

---

## Findings that would have cost you time

**The merge engine is built, tested, and starved — not missing.**
`Content\Identity\Resolver` is complete and pure, with 21 unit tests already
pinning cross-source corroboration, poisoning and kind-scoping. It is fully
wired through `ProjectionWriter::resolveItems()`. But
`writeIdentityKeys()` emits only **2 of 17** `KeyClass` values —
`platform_object` (embeds the platform, so can never match cross-source) and
`canonical_url` (never matches cross-platform). Hence `item_merges` = 0.
**Phase 2 is one method plus emission tests, not a build.**

**Two joining keys have no data at all.** `content.f_catalog`: `isrc` 0,
`gtin` 0 — columns exist, no connector populates them. Apple Podcasts carries
no guid/enclosure either. So of the Joining tier only `ContentDigest` is
emittable (914 fingerprints, 716 distinct — real duplicates to unify). Music
dedup falls back to corroborating title keys. **Consequence: when choosing the
Spotify/SoundCloud Apify actor in Phase 4, prefer one that returns ISRC.**

**Phase 3's 318-slug migration is collision-free.** Uniqueness scopes match on
`(user_id, slug)`; checked live — 9 collisions exist and **every one is an
`event`, none `menu_item`**. Those collisions also reveal that slice 2
**dual-wrote event slugs**: 16 in `content.item_slugs`, 11 still in
`site.item_slugs`. Owner approved deleting the legacy 11 in teardown.

**You were right about the profile stream, and more precisely than I was.** I
recorded that field bindings "do not exist"; your spec correctly says the lane
is *redundant, not unfinished* — built in `20260728150000_field_bindings.sql`
and deliberately dropped in `20260805110000_drop_field_bindings.sql`. Same
verdict: delete the `profile_fields` seam, keep `IdentitySync` and
`site.workplaces` (the identity store, not legacy).

**`commerce_probe` is a live bug.** `CommerceProbeJob::ORIGIN =
'commerce_probe'` but `source_intents_origin_check` does not allow it, so every
probe that resolves a store throws `23514` at `SourceReconciler.php:181`. The
probe succeeds; only the intent write fails. Owner approved adding the value.
Phase 1.1, independent of everything else.

**Nightwatch #427 (`products_curated_at` ambiguous) is already fixed** in
`fb8491bfc` — do not act on it. Nightwatch shows history; check for a landed
fix first. `#371`, `#429`, `#430` are pre-existing dev noise.

---

## Environment traps (each cost a failed command during planning)

- `pg_dump` is **not on PATH** → `/opt/homebrew/opt/libpq/bin/pg_dump`
- The DB password must come from **Laravel config**, never shell-parsed from
  `.env` — shell parsing auth-failed while Laravel connected fine
- No `timeout(1)` on this machine
- Pool tests must live in `tests/Feature/Content/` (your own commit message
  records why)
- After any `app/Catalog/Definitions/*` change: `catalog:compile` +
  `routing:corpus` + **`pint` on the generated artefacts**, or the diff is
  thousands of formatting-only lines

---

## Owner decisions, all settled

1. Substack demoted; `article` kind deleted
2. Gumroad demoted to link-only
3. `channel` kind + `ChannelCardProjector` + `f_channel` deleted — **but the
   kind retires in Phase 4, not Phase 1**: Spotify/SoundCloud still produce it
   until they convert to `track`
4. `document` kind **kept** (declared, unpooled) — documents are upload-only
   with no platform source; **no documents pool**
5. YouTube Music kept and provisioned — free, keyless RSS, only real `track`
   producer, never provisioned
6. Spotify/SoundCloud → Apify track sourcing; channel projectors deleted
7. `ingest:backfill-sources` may be run — the end-to-end proof
8. Migrations applied straight to dev
9. Twitch de-sourced including `vods` (never succeeded; may be unconfigured
   rather than broken — owner accepted deletion anyway)
10. Legacy event slugs deleted in teardown
11. `commerce_probe` added to the constraint
12. **Frontend may break and will be rebuilt** — not a gate
13. Apify cap **US$18** for any verification run ($2.75 of $29 used at
    planning time; the ~$8 left is deliberate, so dev's scheduled ingest keeps
    working)
14. ISRC/GTIN extraction **not** in scope now — accept title-based dedup, and
    prefer an ISRC-returning actor in Phase 4

---

## Verified capability baseline (all tested 2026-08-14)

| | |
|---|---|
| dev Supabase | `glncumufgaqcmqhzwrxm` (prod `edplucmvkcnokyygxqsb` — never touch) |
| artisan → dev | `ingest:project --dry-run` = 47 streams, 586 records, 0 failed |
| Apify | token valid; `memo23~uber-eats-scraper` returned 29 items matching `UberEatsMenuConnector`'s expected shape exactly |
| `pg_dump` gate | verified with real data — 318/44/402 exact |
| Nightwatch | app `a1698025-90b3-426d-94ae-4b85ae5bb4c2` |
