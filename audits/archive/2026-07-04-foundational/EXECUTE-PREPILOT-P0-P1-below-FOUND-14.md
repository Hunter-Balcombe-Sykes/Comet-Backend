# Execute prompt — foundational audit, pre-pilot P0 (below #FOUND-14) + all P1

> Paste this into a fresh session (Opus session; it delegates to Sonnet subagents,
> escalating to Opus only where noted). It drives the **remaining pre-pilot P0 findings
> below #FOUND-14** plus the **entire P1 extensibility-spine tier** through the standard
> `execute audit` runbook.

---

## Mission

Work these findings in `audits/sweeps/2026-07-04-foundational/CONSOLIDATED.md`, following
`scripts/audit/fix-flow.md` **exactly** (plan → implement → independent review → tick +
commit, per unit), in tier order (all P0 before P1):

- **P0 (pre-pilot, below #FOUND-14):** #FOUND-25, #FOUND-34, #FOUND-35, #FOUND-36, #FOUND-37, #FOUND-49
- **P1 (extensibility spines):** #FOUND-5, #FOUND-11, #FOUND-23

> Scope note: including P1 #FOUND-23 **reverses** its earlier "highest-leverage deferral"
> (Josh's explicit call on 2026-07-04). **#FOUND-24 (XL — the audit's widest blast radius)
> stays deferred** — fix on next-platform-add, not pre-pilot. Treat #FOUND-23 as a full
> blocker unit (below).

## Scope table — identify each finding by its BODY, never by the Standalone-section bullet IDs

### P0 — data-shape / compliance (cost rises once real user data lands)
| ID | Finding (by body) | Effort | Plan | Migration target |
|----|-------------------|--------|------|------------------|
| **#FOUND-25** | ShopController brand/product map → relational child tables (`site.shop_brands`, `site.shop_products`); shrink the `shop` `platform_connections.payload` to a marker | **L** | **Opus** | new tables + `site.platform_connections` |
| **#FOUND-34** | Row-type `str_starts_with('event-'/'link-')` on `resource_id` → nullable `resource_kind` discriminator column | M | Sonnet | `site.platform_connections` |
| **#FOUND-35** | Social-link `handle` still in `site.blocks.settings` JSONB → nullable `handle` column + backfill (same pattern already used for `platform`/`category`) | M | Sonnet | `site.blocks` |
| **#FOUND-36** | `MenuSource` filters `url` in PHP over `online-ordering` rows → *candidate* `url` column. **Body says "leave as-is / later, not now."** | M | Sonnet | `site.platform_connections` |
| **#FOUND-37** | Dead `core.users.about` JSONB column (credentials/experience already in child tables) → drop column + cast + strip logic | S | Sonnet | `core.users` |
| **#FOUND-49** | Booking section `settings.platform` JSONB — related fields already promoted. **Body says "leave as-is, read in exactly one place today."** | S | Sonnet | `site.blocks` |

### P1 — important extensibility spines
| ID | Finding (by body) | Effort | Plan | Kind |
|----|-------------------|--------|------|------|
| **#FOUND-5** | ~36 hand-typed `->defaults('platform', '<slug>')` route strings → generate from `PlatformRegistry` descriptor + add a CI/coverage check that every default resolves | S | Sonnet | routes, **no migration** |
| **#FOUND-11** | Bare platform-string literals across queries/jobs/dispatch arrays → string-backed `Platform` enum; longer-term tie to a `config('partna.platforms')` registry | M | Sonnet | jobs/enum, **no migration** |
| **#FOUND-23** | Adding a 3rd food-delivery platform edits 5+ spots → one `config('partna.menu.platforms')` entry per platform, read by `MenuApifyScraper`/`MenuMerger`/`MenuSource`/`MenuFetchJob` | **L** | **Opus** | config/code, **no migration** |

### Explicitly OUT of scope — do not touch
- **#FOUND-14** — being implemented in `.worktrees/audit-fix-found-14-canonical-key-2026-07-04`. See sequencing.
- **#FOUND-1** (GDPR export registry) and **#FOUND-13** (menu badges) — already closed (PR #226 / #225).
- **#FOUND-24** (XL connect-registry, 13 controllers + 4 route groups) — **kept deferred**: fix on the next music/video/social platform add, not on calendar.
- All **P2 / P3** findings.

---

## Non-negotiable landmines (read before writing any code)

1. **ID drift — trust the finding body, not the Standalone bullets.** The
   `## Standalone — do NOT bundle` section mislabels IDs (calls the MenuSource-url finding
   "#FOUND-35", the drop-`about` finding "#FOUND-36"). The **finding body** under the tier
   headers is authoritative. Re-read the body for every ID above before acting.

2. **Verify each finding's premise against live code + schema + git FIRST.** These are
   auto-generated. #FOUND-13 was a P0 that proved **premise-invalid** and was closed with a
   documented no-op (no migration). A finding that verification shows is already-solved or
   unjustified closes the same way — an inline comment pinning the decision — **not** forced
   work. Expected outcome, not failure. Two specific stale premises to expect:
   - **#FOUND-5 cites `routes/api/integrations.php`** — that file was **renamed to
     `routes/api/platforms.php`** (commits `7fcc31a0` / `b4419f7c`). Confirm the live path on
     your base branch before editing.
   - **#FOUND-36 and #FOUND-49 bodies already say "leave as-is."** Expect both to resolve as
     **documented-defer** (one-line comment at the read site), unless verification surfaces a
     real present-day query/filter/uniqueness need. ⚠️ Raise this conflict with Josh at the
     gate: his triage wanted the booking-platform promotion (#FOUND-49) batched in, but the
     adjudicated body downgrades it. Present both readings; let him pick.

3. **Table collision — four findings ALTER `site.platform_connections`** (the
   `IntegrationConnection` table): #FOUND-14 (in flight), #FOUND-25, #FOUND-34, #FOUND-36.
   - **Do NOT start #FOUND-25 until #FOUND-14 is merged to `development`;** rebase onto
     post-14 `development` before implementing it.
   - Coordinate every new migration timestamp to sort **after** #FOUND-14's `canonical_key`
     migration.

4. **File collision within P1 — order the enum before the menu registry.**
   - #FOUND-11 (Platform enum) and #FOUND-23 (menu registry) **both rewrite
     `MenuFetchJob.php`.** Do #FOUND-11 first; #FOUND-23's registry may absorb part of it —
     reconcile in #FOUND-23's plan, don't re-litigate the enum.
   - #FOUND-5 (route defaults from registry) touches only `routes/api/platforms.php`; nothing
     else in scope touches it now that #FOUND-24 is deferred.
   - Net order: **spine bundle (5 + 11) → #FOUND-23.**

5. **Run units sequentially, not as parallel worktrees.** Parallel migrations on one table
   race on timestamps, and parallel edits to `MenuFetchJob` / the routes file collide; this
   repo needs 1–3 rebase cycles under concurrent bundle sessions. One unit at a time.

6. **Supabase migrations only — never Laravel migrations** (a composer guard rejects them).
   Raw SQL in `supabase/migrations/`. Apply to the **dev** ref `glncumufgaqcmqhzwrxm`
   (`supabase db push` or MCP `apply_migration`). **Do not** promote to prod. *(P0 only — the
   P1 items are config/code, no schema change.)*

7. **Tests run on SQLite; prod is Postgres and the schemas drift.** `NOT NULL` / `CHECK` are
   unenforced in the in-memory test DB, so bad DDL passes CI green and only 500s on real
   Postgres. Verify column nullability/defaults against the actual `supabase/migrations/`
   DDL, not just a green suite. New columns here are **NULLABLE**, backfilled explicitly.

8. **Worktree hygiene:** base each worktree off `origin/development` (default branch is
   `production`, and local `development` may lag); each worktree needs its own
   `composer install` + `.env`; run the **full** `composer test` in the main checkout, not a
   filtered subset (namespace-relocation short-refs and eager-scraper wiring only fail under
   the full suite).

9. **Subagent model overrides are mandatory.** This is an Opus session; an unlabelled
   subagent inherits Opus and blows cost. Set the model **explicitly** on every subagent:
   **Opus** only for the #FOUND-25 and #FOUND-23 *planning* passes; **Sonnet** for everything
   else (all implement/review passes and the 5+11 spine bundle).

---

## Work units & order

Four units, run **sequentially**, in this order:

**P0 (do first):**
1. **P0 schema sweep (batched)** — #FOUND-34 + #FOUND-35 + #FOUND-37 as real promotions,
   + #FOUND-36 & #FOUND-49 verify-first (likely documented-defer). Per Josh's triage, **one
   migration file**, ticked + committed together. Plan/Impl/Review all Sonnet. *(Coordinate
   migration timestamp after #FOUND-14.)*
2. **#FOUND-25** — ShopController relational extraction (standalone). New child tables +
   payload shrink + data migration. **Blocked on #FOUND-14 merge**; rebase first. Plan **Opus**
   → Impl Sonnet → review Sonnet.

**P1 (after all P0):**
3. **P1 spine bundle** — #FOUND-5 + #FOUND-11 (route-defaults-from-registry + `Platform`
   enum). Plan/Impl/Review all Sonnet.
4. **#FOUND-23** — menu-platform registry (standalone, L). Plan **Opus** → Impl Sonnet →
   review Sonnet.

---

## Procedure — apply `scripts/audit/fix-flow.md` per unit

For **each** unit, in order:

**a) Blocker gate.** Gate is ON for a unit that is P0, a DB migration, L/XL effort, or
standalone. That means: **units 1, 2, 4 gate** (present the plan, blast radius, and your recommendation —
incl. the promote-vs-defer calls on #FOUND-36/#FOUND-49 — and **wait for Josh's explicit
go-ahead**). **Unit 3 (5 + 11) does NOT gate** — it proceeds autonomously.

**b) Plan** — spawn a planning subagent scoped to the unit, at the unit's Plan model (set
explicitly). It reads the cited code and returns: files to change, exact per-finding change,
migration DDL (P0), tests to add/run, risks. *(Combine plan+impl is fine for the S/XS
mechanical parts of the 5+11 bundle.)*

**c) Implement** — separate Sonnet subagent applies the plan. House rules: Supabase (not
Laravel) migrations, Resource classes, Policies (no inline 403s), `$backoff` on any
`ShouldQueue` job. Runs relevant tests until green.

**d) Review** — a **fresh, independent** subagent (never the implementer), at the Review
model, gets the finding + the diff and returns PASS or specific defects. FAIL → new
implementer round; after 2 failed rounds mark the unit blocked and surface to Josh.

**e) Record + commit** — flip `- [ ]` → `- [x]` for every finding in the unit (including any
closed as documented-defer, with rationale noted like #FOUND-13), bump the `## Progress`
counts, and commit code + audit file together: `fix(audit): <unit> — <ids>`.

---

## Definition of done

- All four units are either committed (green + independent review PASS) or explicitly blocked
  with a reason surfaced to Josh.
- Full `composer test` green on each branch (run in the main checkout).
- Run `scripts/audit/archive-done.sh audits/sweeps/2026-07-04-foundational`. **It will NOT
  archive** — #FOUND-14, #FOUND-24, and all P2/P3 remain open — and that is correct. Run it anyway
  (harmless); do not force-archive.
- Report: units done, units blocked (with reason), test status, branch name(s). Josh reviews
  and merges — do not push to `development`/`production` without his say-so.
