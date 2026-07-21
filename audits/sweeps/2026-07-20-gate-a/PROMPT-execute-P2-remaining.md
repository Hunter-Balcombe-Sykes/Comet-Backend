# Gate A — execute prompt, PART 2 (remaining P2 units)

Continues `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md` from where the first session stopped.
**8 units are already done and committed** on branch `audit-fix/gate-a-2026-07-20` (B2, S1+S2, S3, B1,
B3, B4, B5, B6 — all P0/P1 work plus the two policy-gate sweeps). This prompt covers the remaining
**P2 units: B7, B8, B9, B10, B11, B12, B13, B14, B15, B20, B21, S4.** The P3-only units (B16, B17,
B18, B19) have their own prompt: `PROMPT-execute-P3-remaining.md` — run that AFTER this one.

**How to use:**
1. Open a **fresh Claude Code session in this repo** on model **Opus**.
2. Paste everything from `=== PROMPT START ===` to the end as your first message.
3. Expect sign-off requests on B11, B13, B20 and S4 (blockers by design). The rest run without asking.

---

```
=== PROMPT START ===

Continue executing audit audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md. The branch
audit-fix/gate-a-2026-07-20 already exists with 8 units committed; check it out and continue on it —
do NOT create a new branch, do NOT re-do finished units. Follow scripts/audit/fix-flow.md, with the
gate-specific overrides below.

## First: orient yourself
- `git fetch && git checkout audit-fix/gate-a-2026-07-20 && git log --oneline -12` — confirm the 8
  fix(audit) commits are present (B2, S1+S2, S3, B1, B3, B4, B5, B6).
- Read `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md` end to end, including its `## Progress`,
  `## Discovered during execution`, `## Requires a schema change` and `## Known limits` sections. The
  Progress block is the source of truth for what's done and the tier re-counts — NOT the "Findings at
  a glance" table at the top, which is wrong as generated (it says P1 13/P2 62/P3 51; the real
  line-level split is P0 2/P1 14/P2 68/P3 44, both summing to 128).
- **Every finding line carries only `Where` + `What to do`. The full Technical/Plain English/Evidence
  lives in the `sources/<run>.md` file named at the end of the line.** Open every source file a unit
  points at and read those findings IN FULL before planning that unit. A plan from CONSOLIDATED alone
  is working from a third of the evidence.

## The single most important lesson from the first 8 units — VERIFY EVERY PREMISE

Roughly 40% of the findings worked so far had premises that were **wrong, stale, or whose prescribed
fix was actively harmful.** Before implementing ANY finding:
- Read the actual code at the cited location and confirm the defect still exists as described. Line
  numbers in this audit have drifted — locate code by reading, not by trusting the cited line.
- **The audit was scanned against a STALE snapshot.** At least five findings were already fixed by
  prior audit-fix runs whose commits are ancestors of this branch (`7b68bda5`, `3f41d147`,
  `f5cdcd99`). Run `git log --oneline --since=2026-07-10 -- <file>` on any file before touching it.
- When a finding says "the same file already does X elsewhere," find that existing pattern and reuse
  it exactly — do NOT invent a second mechanism. If the claimed pattern doesn't exist, the fix is
  bigger than stated; report that instead of improvising.
- If a premise is false or already-satisfied, mark the finding `no_change_needed` with evidence
  (quote the code / cite the SHA) and move on. That is a valid, valuable outcome — do not manufacture
  a fix for a problem that doesn't exist.

## Standing decisions carried from session 1 (these override the runbook where they conflict)

- **Cutover (Josh):** the prod cutover will collapse migration history into a fresh baseline, so the
  migration files in this repo will NOT replay against a populated prod DB. Any migration-touching
  work is hygiene for local `db reset` / preview branches / DR — scope it accordingly.
- **No migration applied to any live DB as part of this run.** And prefer editing existing migration
  files in place over creating NEW `supabase/migrations/` versions — a new version is applied to the
  LIVE DEV Supabase project (`glncumufgaqcmqhzwrxm`, which serves real traffic) by `db push`. If a
  finding genuinely needs a new migration, flag it as a separate gated decision; do not fold it in.
- **SQLite string-literal trap (this bit prod on 2026-07-19):** on SQLite an unknown quoted
  identifier is a STRING LITERAL, not an error, so "does the query run" tests are vacuous. Verify
  every column name against the real DDL in `supabase/migrations/`, never against `tests/Pest.php`'s
  stubs or a model's `$fillable`. `tests/Feature/Security/DataExportCoverageTest.php` now has a
  `MigrationColumnReplay` helper that derives a table's true column set from the migrations — reuse
  its approach if you need to verify columns. Tests must assert returned DATA, not just a 200.
- **Authorization:** always `$this->authorizeForUser($user, ...)`, NEVER `$this->authorize(...)` —
  under Supabase JWT `Auth::user()` is null, so `authorize()` calls `Gate::forUser(null)` and
  silently passes. A gate written that way looks like a fix and enforces nothing. Never inline
  `abort_unless(...,403)` (CI fails on it). 404 for "doesn't exist / isn't yours", 403 only for
  role/type restrictions.
- **Pin subagent models explicitly** on every spawn (`model: sonnet` for implement and review) — child
  agents inherit the main-loop model, and an Opus fan-out exhausts the session budget.
- **Never `git stash` / `git checkout <file>` / `git restore` / `git reset`** — there is a second
  active developer and a prior session's stash; a discard loses their work. Read-only git only; to
  see old content use `git show <ref>:<path>` and READ it. Forbid `git stash` explicitly in every
  implementer/reviewer prompt you spawn.
- **Before every commit:** `git diff --cached --stat`, confirm the file list is exactly what you
  intend (the index can carry the other dev's work), keep commits surgical, do NOT run `php artisan
  pint` across changed files (a dedicated style commit is the only exception). Commit code + the
  ticked audit file together per unit: `fix(audit): <unit> — <ids>`. End commit messages with:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>

## Cadence — full rigour where risk lives, lighter where it doesn't

The first session used plan→implement→independent-review on every unit and it repeatedly caught
defects the implementer's own checks missed (a migration fix that RELOCATED a half-applied state; a
comment asserting index coverage that didn't exist; ten drifted test stubs; a mutating admin action
left on a no-op gate). Keep that for the units where it earns its cost:

- **Full plan (Opus) → implement (Sonnet) → independent review (separate Sonnet):** B11, B20, B13,
  B9, S4.
- **Combined plan+implement (Sonnet) → single independent review (Sonnet):** B7, B8, B10, B12, B14,
  B15, B21.
- For risky diffs spanning very different concerns, run TWO reviewers in parallel over separate
  halves (this is how B1 and B3 were reviewed) — pin `model: sonnet`, `run_in_background: true`.
- Record: tick `[ ]`→`[x]` for a finding ONLY after tests pass AND the independent review returns
  PASS; update the `## Progress` counts; commit. After 2 failed review rounds on a unit, mark it
  blocked and surface it rather than forcing it.
- `composer test` is the gate. NEVER run it while a subagent is also running tests — wait for the
  subagent to return. Never accept an implementer's "this failure is pre-existing" without proof
  (`git show` the prior commit).

## Unit order and per-unit notes (work strictly in this order)

### 9. B7 — PII minimisation in logs (P2) — combine plan+impl, single review
⚠️ **`public-surface/PRIV-2` and `PRIV-3` are ALREADY DONE in the working tree, uncommitted** (the
second developer wrote them: `PublicCustomerLeadController.php` UA-capping via
`AnalyticsEventSanitizer::userAgent`, and `PublicCustomerLeadRequest.php` `strip_tags` on notes). Read
those diffs first, verify they're correct, and RECONCILE — do not duplicate or revert them; commit
them as part of B7 with attribution noted.
- `outbound-ssrf/PRIV-1` (EXIF/GPS stripping) is the heavy one — it's image processing, not log
  redaction. First determine actual exposure: does `ImageVariantService` already re-encode variants
  through a library that drops EXIF as a side effect? If variants are already clean and the stored
  ORIGINAL isn't publicly served, this is much smaller than it looks. Report the exposure analysis
  before writing any EXIF stripper; don't add a heavy image dependency without confirming the need.
- `edge-worker/PRIV-1` is in the Cloudflare Worker (`cloudflare-worker/`, JavaScript) — a handle is
  weaker "PII" than an IP and the Worker has one deploy path. Consider recommending deferral rather
  than over-engineering the Worker; do NOT touch Worker routing or KV logic.
- The hash-the-identifier findings reuse the repo's existing HMAC-SHA256 `ip_hash` helper — find it,
  don't reinvent. `authz-core/PRIV-1` explicitly asks for a shared minimiser across
  `TokenRevocationService` and `AuthFactorEventRepository` — extract one, don't duplicate.

### 10. B8 — retention windows that never prune (P2) — combine plan+impl, single review
- The Waitlist-retirement work established the pattern: a PII table needs a scheduled prune AND
  export/purge wiring, guarded by `DataExportCoverageTest`. Follow it.
- `gdpr-deletion-export/PRIV-3` (`analytics.item_views` PII outliving an account) needs a schema
  change / new purge step — see the `## Requires a schema change` table. Verify grants: `app_backend`
  has SELECT/INSERT only on `audit`; check what it has on `analytics` before writing an UPDATE/DELETE.

### 11. B9 — pre-account lifecycle races (P2) — FULL rigour (Opus plan; races)
- `claim-and-provision` found nothing above P3 — the claim race itself is well defended. These are
  adjacent paths (stuck-build reconcile, HandleAllocator check-then-insert, IP abuse-cap race,
  subdomain-rename lock scope). Keep the `IdentitySync`-via-observer and lock/savepoint contracts
  intact; don't bypass the machinery to "fix" a race.

### 12. B10 — Nightwatch blind spots (P2) — combine plan+impl, single review
- `Log::error` without `report()` never reaches Nightwatch. All on PII-erasure/deletion paths. Small,
  mechanical: add `report()` alongside the existing log calls. Don't change log LEVELS.

### 13. B11 — `$fillable` doctrine on tenant-owned models (P2) — BLOCKER, FULL rigour, Opus plan
⚠️ **Present the plan and wait for sign-off.** ⚠️ **Do NOT combine with S4** (the L-effort
eleven-model sweep). Per the *Fillable Tenancy-FK → associate()* rule: a trusted creation path must
move to `->relation()->associate()` BEFORE the FK leaves `$fillable`, or creation silently breaks.
`pre_account_builds.user_id` / `built_by_staff_id` are the correct precedent (already non-fillable via
`associate()`). Run the FULL suite after — removing a field from `$fillable` breaks same-namespace
short refs a grep won't catch.

### 14. B12 — pre-claim scraping data minimisation (P2) — FULL rigour, Opus plan
- Provisional (`unclaimed`) users haven't consented. Both generators persist the full vendor payload
  when the unclaimed sitepage renders only a few fields. **Keep the `IdentitySync`-via-observer
  contract intact — narrow what the seeder WRITES, don't bypass the machinery.** (`config/partna.php`
  `pre_account.*` is the source→generator registry.)

### 15. B13 — Cloudflare Worker hardening (P2) — BLOCKER (Worker/KV), FULL rigour
⚠️ **Present the plan and wait for sign-off.** The Worker returned no P0/P1 — its read path is sound;
these are defence-in-depth (header filtering, `Vary` sanitisation, reserved-subdomain CI diff, TTL
config). `SyncSubdomainToKvJob` is the ONLY writer to `SUBDOMAIN_KV` — do not add a second writer or
change routing. `edge-worker/CFG-3` wants a CI diff check between `config/partna.php` and the JS
`RESERVED` set — a build-time check, NOT a runtime fetch.

### 16. B14 — public route and ingest hardening (P2) — combine plan+impl, single review
- Analytics ingest ownership validation, early-access bot-token gate, Maps API key exposure, Horizon
  dashboard domain. `wiring` came back clean at P0/P1 over a 262 KB scope — read those as "no obvious
  hole", weaker evidence than a small scope. Verify the Maps-key finding's real exposure (provider
  referrer restriction may already mitigate).

### 17. B15 — outbound HTTP hardening (P2/P3) — combine plan+impl, single review
- The June 3rd SSRF fix held. Lead finding `outbound-ssrf/EDGE-1` (P2) = missing HTTP timeouts on six
  `Http::` calls; the rest are P3 (URL-encoding, config extraction). Note B1 already added
  `->timeout()/->connectTimeout()` to `CloudflarePurgeService`'s purge call — check for overlap so you
  don't double-apply.

### 18. B20 — schema: RLS gaps + column defaults (P2) — BLOCKER (RLS + migration), FULL rigour
⚠️ **Present the plan and wait for sign-off. Schema change.** ⚠️ **Premise already checked in session
1:** `user-api/SCHEMA-1` (design_kits `updateOrInsert` race) — the `trg_create_empty_design_kit`
trigger EXISTS (`20260527070000`, hardened to `ON CONFLICT DO NOTHING` in `20260602010000`), so every
site gets its `design_kits` row atomically at creation and the race is unreachable. Verify the
historical rows were backfilled, then likely close SCHEMA-1 `no_change_needed` — do NOT author a
backfill migration without confirming a real gap. SCHEMA-1/2/3 (RLS on `site.workplaces` /
`site.content_selection`, UUID defaults) are genuine and need new migration files — but see the
cutover note: prefer the smallest correct change and flag the new-version-on-dev hazard.

### 19. B21 — test/prod parity (P2) — combine plan+impl, single review
- One finding: `parity-jobs/PARITY-1` — `pre_account_builds.user_id` is NOT NULL in prod but nullable
  in the SQLite stub and the factory never sets it. Change the stub to NOT NULL and fix whatever that
  surfaces. Trivial, but expect the NOT NULL to break tests that relied on the lax stub — those
  breakages are the parity gap becoming visible; fix them, don't relax the stub back.

### 20. S4 — eleven-model `$fillable` sweep (P2, effort L) — BLOCKER, FULL rigour, Opus plan
⚠️ **Present the plan and wait for sign-off. L effort. Do NOT combine with B11** (do this AFTER B11
lands). Same *Fillable Tenancy-FK → associate()* rule — replace permissive mass-assignment with
explicit `$fillable` allowlists and migrate skeleton-pattern call sites to explicit assignment FIRST.
Run the FULL suite; a namespace/short-ref break won't show in a targeted run.

## Discovered items (logged in CONSOLIDATED `## Discovered during execution`) — leave for their own units
DISC-1 (folds into B19, next session), DISC-2 (accepted), DISC-3 (191-file stub sweep), DISC-4
(ffprobe test), DISC-5 (staff `{category}` route 500). Do not work these here unless one blocks a unit.

## When these P2 units are done
- `composer test` once for the whole branch — must be green.
- Do NOT run `archive-done.sh` yet — the P3 units (B16, B17, B18, B19) remain. The folder archives
  only when EVERY box in CONSOLIDATED is `[x]`.
- Report: units done, units blocked (with reasons), test status, branch name. Then hand off to
  `PROMPT-execute-P3-remaining.md`.
- **Do not push to `development` or `production`.** Josh reviews and merges.

## Stop and ask if
- A blocker unit's plan is ready (B11, B13, B20, S4) — present it with blast radius + recommendation.
- Two review rounds fail on the same unit — mark it blocked and surface it.
- A finding's premise turns out wrong in a way that suggests the audit misread the architecture.

=== PROMPT END ===
```
