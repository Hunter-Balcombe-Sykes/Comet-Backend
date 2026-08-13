# Convergence — running log

Append-only. Decisions are recorded **before** acting on them. Context
compacts; this file does not.

---

## 2026-08-14 · Planning session (owner asleep, execution deferred)

### Status: PLANNING COMPLETE, EXECUTION NOT STARTED

Branch `feat/platform-pool-convergence` exists, rebased onto
`origin/development` (which had moved 10 commits). **No code changed.**

### Why execution is deferred

`jhunter7333` pushed 10 commits between 00:33 and 01:54; it is now 03:22.
That cadence (5–20 min apart) reads as an active session, not an end-of-day
push. Their work lands in `app/Ingest/ProjectionWriter.php` and
`app/Ingest/SourceProvisioner.php` — two of the four files Phase 2 depends on.

Their work is **complementary, not contradictory**: services (slice 3)
hardening — `LegacyServiceSortOrder` (fixes a live 500 from a global
`services_user_sort_order_uq`), reorder consolidation, cache-lane
invalidation, and a genuinely useful fix letting Fresha `book-now` share URLs
provision a source.

One expiry note: `LegacyServiceSortOrder` manages `site.services.sort_order`,
and Phase 7 drops `site.services`. It is not wasted work — it fixes a real bug
today — but Phase 7 must **delete** it rather than port it.

**Morning action:** confirm that session is finished, or carve lanes
explicitly (suggested split: this run takes ingest identity keys + menu/links
pools + teardown; the other keeps services).

---

## Findings that changed the plan

### F1 — The merge engine is built, tested, and starved (not missing)

`App\Content\Identity\Resolver` is complete and pure: joining → user-`same` →
user-`different` cut → corroborating (cross-source only) → evidential
candidates, with poisoned-key detection. **21 unit tests** in
`tests/Unit/Content/ResolverTest.php` already pin exactly the semantics we
need, including cross-source corroboration and category-corroborated short
dish names.

It is fully wired: `ProjectionWriter::resolveItems()` (line ~628) reads
`content.identity_keys`, builds `SourceItem`s, calls `Resolver::resolve()`,
then `bindGroup()` and `recordCandidates()` — on every projection.

`ProjectionWriter::writeIdentityKeys()` (line 490) emits exactly **two** of
seventeen `KeyClass` values: `platform_object` (which embeds the platform, so
it can never match cross-source by construction) and `canonical_url` (which
never matches across platforms). Therefore the Joining tier can union nothing,
and Corroborating/Evidential are empty — which is why `item_merges` = 0 and
`identity_candidates` = 0.

**Consequence: Phase 2 is one method plus emission tests, not a build.**
Materially smaller than the scope doc assumed.

### F2 — `writeIdentityKeys()` survived the 10 new commits unchanged
Verified post-rebase: still two key classes, moved 480 → 490. Phase 2's
analysis holds against current `origin/development`.

### F3 — Phase 7's dump gate works today
Proven against real tables: `site.menu_items` 318, `site.menu_categories` 44,
`site.menu_item_categories` 402 — all exact matches to live, 200K dump with
schema and data. The gate is a real assertion that passes now.

### F4 — Two 3am traps, already paid for once each
- `pg_dump` is **not on PATH** → `/opt/homebrew/opt/libpq/bin/pg_dump`
- The DB password must come from Laravel config, never shell-parsed from
  `.env` — shell parsing auth-failed while Laravel connected fine.

### F5 — `commerce_probe` is a live bug (fix in run)
`CommerceProbeJob::ORIGIN = 'commerce_probe'`, but
`source_intents_origin_check` allows only
`paste, website_import, link_in_bio, bio_harvest, google_business, staff,
reproject`. Every probe that resolves a store throws `23514` at
`SourceReconciler.php:181`. The probe itself succeeds; only the intent write
fails. 2 occurrences in 24h, 1 user affected.

### F6 — Nightwatch #427 was already fixed; do not act on it
`products_curated_at` ambiguity was fixed in `fb8491bfc` (2026-08-13 08:17),
ten hours after the exception fired. Lesson: Nightwatch shows history —
always check whether a fix already landed before acting.

### F8 — CORRECTION: do NOT delete the `document` kind (needs owner decision)

I earlier recommended deleting `document` as "0 items, no producer, unrelated
to `site.site_documents`". The first half was right — `site.site_documents` is
the built-sitepage JSON cache, not user files. **The recommendation was still
wrong.**

There is a real user-documents feature: `site.site_media` with
`pool='documents'` (2 rows on dev), managed by
`App\Http\Controllers\Api\User\Account\UserDocumentController` via
`SiteMedia::POOL_DOCUMENTS`.

So `document` is the **third instance of the same pattern** as `link` and
`menu_item`: a declared `KindRegistry` kind whose data currently lives in a
legacy store, awaiting a pool. It is not dead weight.

**Plan changed:** Phase 1 no longer deletes the `document` kind. Two options
for the owner:
- (a) leave the kind declared, `site.site_media` pool='documents' stays legacy
  — cheap, but breaks the "no legacy in use" goal for this one feature
- (b) add a **documents pool** to Phase 3 alongside menu and links — 2 rows to
  migrate, likely the smallest pool of the three, and consistent with the goal

Default taken while unattended: **do not delete**, defer the choice. Deleting a
kind with a legitimate future use is harder to undo than leaving it declared.

Owner decisions #4 and #10 (`document` kind → delete) are superseded by this.

### F9 — Kind deletion: narrow the registry, NOT the DB CHECK domain

`content.source_items.kind` and `content.items.kind` each carry a DB CHECK
constraint listing all 14 kinds, guarded by
`tests/Postgres/ContentKindDomainParityTest.php`.

That guard **extracts the clause from two specific migration files by name**
(`20260729150000_source_items_kind_check_not_valid.sql` and
`20260727140000_content_schema.sql`) and asserts the two domains are
set-equal. A *new* narrowing migration would therefore not be read by it —
the test would keep comparing the original text and keep passing — while its
hardcoded `CONTENT_ITEMS_KIND_DOMAIN` (14 values) silently went stale. Fixing
that properly means rewriting the guard's extraction to understand superseding
migrations.

Verified separately: **nothing binds `KindRegistry` to the DB domain.**
`KindRegistry::kinds()` has no callers in `app/` or `tests/`.

**Decision:** when retiring a kind, Phase 1 will
- remove it from `KindRegistry` (the app-level truth),
- delete its projector/connector/registry entries (the real dead code),
- delete its orphan rows (9 `channel`, 1 `article`),
- and **leave the DB CHECK domain permissive**, documented as a deliberate
  backstop rather than the source of truth.

Rationale: removing an unused value from a CHECK domain buys almost nothing,
while the migration + guard-rewrite it forces is disproportionate churn and
risks leaving a guard that reads as authoritative but no longer is. A
permissive backstop with a narrow application registry is the safer asymmetry.

### F7 — Pre-existing dev noise, not caused by this run
`#371` cache-SLO violations, `#429`/`#430` Redis timeouts. Recurring on dev
before any of this work.

---

## Verified capability baseline (all tested 2026-08-14)

| Capability | State |
|---|---|
| Supabase MCP | dev `glncumufgaqcmqhzwrxm`, prod `edplucmvkcnokyygxqsb` (never touch) |
| artisan against dev | `ingest:project --dry-run` → 47 streams, 586 records, 0 failed |
| Apify | token valid (`partna`, Starter); actor `memo23~uber-eats-scraper` returned 29 items with the exact field shape `UberEatsMenuConnector` expects |
| Apify budget | $2.75 of $29 used; **run cap $18** |
| `pg_dump` | verified with real data (see F3) |
| Nightwatch | app `a1698025-90b3-426d-94ae-4b85ae5bb4c2` |
| `cloud env:logs` | works; `--minutes` window appears lagged |
