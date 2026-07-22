# Gate A — execute prompt, PART 3 (final P2 units)

Continues `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md`. **PART 2 completed six units**
(B7, B8, B9, B10, B11, B12), plus discovered items DISC-5 and DISC-6 — all committed on branch
`audit-fix/gate-a-2026-07-20`. On 2026-07-21 origin/development was merged into that branch and
`development` was fast-forwarded to it and **deployed to the live dev API** (branch tip `ea9df2ab`).
This prompt covers the **six remaining P2 units: B14, B15, B21 (non-blockers)
and B13, B20, S4 (blockers)**. The P3-only units (B16, B17, B18, B19) still have their own prompt:
`PROMPT-execute-P3-remaining.md` — run that AFTER this one.

**How to use:** open a fresh Claude Code session on **Opus**, then paste everything from
`=== PROMPT START ===` to the end as your first message.

---

```
=== PROMPT START ===

Continue executing audit audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md. Branch
audit-fix/gate-a-2026-07-20 already has units B7–B12 + DISC-5/DISC-6 committed and now contains
origin/development (merged 2026-07-21; branch tip ea9df2ab). Check it out and continue on it — do
NOT create a new branch, do NOT re-do finished units. Follow scripts/audit/fix-flow.md with the
overrides below.

## First: orient yourself
- `git fetch && git checkout audit-fix/gate-a-2026-07-20 && git rev-parse --abbrev-ref HEAD`
  — CONFIRM the branch name reads exactly `audit-fix/gate-a-2026-07-20` and `git log --oneline -12`
  shows the origin/development merge commit (ea9df2ab) + DISC-6/DISC-5 + fix(audit): B7…B12.
  (A concurrent session moved this repo's shared branch to `development` mid-run last time; five
  commits landed on the wrong branch before it was caught. See the git rule below.)
- Read CONSOLIDATED.md end to end, especially `## Progress` (P2 45/69 done), `## Discovered during
  execution` (DISC-1..DISC-7), and the per-finding resolution notes on B7–B12 (they record several
  audit premises that were WRONG). The one-line finding text points at a `sources/<run>.md` file that
  holds the full Technical/Evidence — OPEN it before planning a unit.

## Standing decisions (carry from parts 1–2; these override the runbook where they conflict)
- **VERIFY EVERY PREMISE.** In parts 1–2, roughly HALF of findings had premises that were wrong, stale,
  already-fixed, or whose prescribed fix was actively harmful. Read the actual code at the cited spot;
  line numbers have drifted (origin/development was merged in — big diff). `git log --oneline
  --since=2026-07-10 -- <file>` before touching any file. A `no_change_needed` with quoted evidence is a
  valid, valuable outcome.
- **GIT: verify the BRANCH NAME (`git rev-parse --abbrev-ref HEAD`), not just HEAD SHA, before EVERY
  commit.** A concurrent `git checkout` in this shared single worktree silently switches your branch.
  Also `git diff --cached --stat` and confirm the exact file list before committing. NEVER `git stash`
  / `git checkout <file>` / `git restore` / `git reset` (a second dev + prior stashes live here) — to
  see old content use `git show <ref>:<path>`. Do NOT push to development/production. `development` is
  at ea9df2ab (B7–B12 + DISC-5/6, deployed 2026-07-21) and audit-fix now contains it; your work stays
  on audit-fix only — Josh merges it to development when ready.
- **Subagents stall.** Two implementer subagents hit an infra stall (600s watchdog) and one a network
  error in part 2. Keep implementer tasks TIGHTLY scoped. If one dies mid-run it usually leaves
  lint-clean partial edits — do NOT revert; assess state with the full suite and complete forward.
  Pin `model: sonnet` on every implement/review spawn (Opus fan-out blows the budget).
- **No migration applied to any live DB.** A NEW file under supabase/migrations/ gets db-pushed to the
  LIVE dev Supabase (glncumufgaqcmqhzwrxm, real traffic) by `db push`. If a finding genuinely needs a
  new migration, flag it as a gated decision and WAIT for Josh — do not fold it in. Prefer editing
  existing migration files in place. (B8's two audit-retention purges + B20's RLS/default items are in
  this class.)
- **SQLite string-literal trap** (bit prod 2026-07-19): an unknown quoted identifier is a STRING LITERAL
  on SQLite, so "the query ran / 200" proves nothing. Verify every column name against real DDL in
  supabase/migrations/, never tests/Pest.php stubs or a model's $fillable. Tests must assert returned
  DATA. NOT-NULL columns 23502 on Postgres but pass on SQLite.
- **Authorization:** `$this->authorizeForUser($user, ...)`, never `authorize(...)` (Supabase JWT →
  Auth::user() is null → silent pass). Never inline `abort(...,403)` (CI fails). 404 for
  doesn't-exist/not-yours; 403 only for role/type.
- **Cadence:** full plan(Opus)→implement(Sonnet)→independent-review(separate Sonnet) for the blockers
  (B13, B20, S4); combine plan+impl(Sonnet)→single review(Sonnet) for B14, B15, B21. For a risky diff
  spanning two concerns, run TWO reviewers in parallel over the halves (background, sonnet). Tick a box
  `[ ]`→`[x]` ONLY after tests pass AND review says PASS. `composer test` is the gate — run it per unit
  (touch shared test infra? definitely); NEVER run it while a subagent is running tests. Verify branch +
  staged file list, then commit code + ticked audit file together: `fix(audit): <unit> — <ids>`. End
  commit messages with:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>

## $fillable lesson from B11 — CRITICAL for S4
Removing a field from $fillable silently DROPS it from EVERY mass-assignment write —
`Model::create`, `$m->update` (a persisted-row update silently NO-OPS, no error), `updateOrCreate`
(its INSERT path mass-assigns the search keys), `new Model([...])`. `preventSilentlyDiscardingAttributes`
is OFF, so nothing throws — it 23502s only on Postgres NOT-NULL (green on SQLite). Fix each trusted
writer FIRST with `forceFill()->save()` / `forceCreate()` / `->relation()->associate()` (associate for
FKs only), with value-assertion tests (`->fresh()->field`), and run the FULL suite (short-ref breaks).
**Cost signal:** removing a NOT-NULL, no-DB-default column (e.g. `User.handle`) ripples to ~90 test
files that create users via raw `User::create([...])` — Josh chose to KEEP such fields fillable when
the /me endpoint already excludes them and a dedicated flow sets them. BEFORE removing any such field,
run `grep -rl "<Model>::create(" tests/` to size the blast radius, and present it. Laravel factories
bypass $fillable (Model::unguarded), so only raw `::create`/`->update` in test helpers break. Full
detail in the memory `feedback-fillable-tenancy-fk-associate`.

## Unit order and per-unit notes (work in this order)

### B14 — public route and ingest hardening (P2) — combine plan+impl, single review
Sources: `sources/wiring.md`, `sources/public-surface.md`.
- `wiring/SEC-2` (P2 M): analytics ingest endpoints validate no site/subdomain ownership, only rate
  limit. Nonce or Origin/Referer check. This is the meatiest — think about false-positive risk on
  legit beacons.
- `wiring/SEC-1` (P2 S): early-access marketing form is the only public mutation with no bot-token gate
  (marketing site posts cross-origin without a token bootstrap). If a token bootstrap can't be added,
  tighten the per-IP throttle below the shared bucket.
- `public-surface/SEC-1` (P2 S): `PublicConfigController::integrations()` returns the Google Maps key
  on an unauthed route. Its only consumer is the authed dashboard → move it behind user.api. Verify the
  key's referrer restriction is the current mitigation (provider-side), so this is defence-in-depth.
- `wiring/CFG-1` (P2 S): Nightwatch enabled-by-default but token has no fallback/prod guard — add the
  fail-hard-in-prod guard mirroring app.debug / feedback.ip_hash_pepper in AppServiceProvider::boot().
- `wiring/CFG-2` (P3 S): HORIZON_DOMAIN unset → /horizon reachable from every subdomain (still
  Basic-auth-sealed in prod). Set HORIZON_DOMAIN + .env.example.

### B15 — outbound HTTP hardening (P2/P3) — combine plan+impl, single review
Source: `sources/outbound-ssrf.md`.
- `outbound-ssrf/EDGE-1` (P2 M): add explicit `->timeout(10)->connectTimeout(3)` to six Http:: calls —
  CloudflarePurgeService::purgeUrls, CloudflareKvService::{put,delete,bulkPut}, StreamingTokenManager
  ::refreshToken, TwitchApiClient::getLiveHandles, KickApiClient::getLiveHandles. **B1 already added a
  timeout to CloudflarePurgeService's purge call — check overlap, don't double-apply.** The
  development merge also changed SafeUrlFetcher (+69 lines) — re-verify current state before editing.
- P3s (S): `SEC-1` urlencode handle/product-handle in purge URLs; `CFG-1` Twitch/Kick token_url →
  config/services.php; `CFG-2` SafeUrlFetcher UA → config; `CFG-3` CloudflarePurgeService enumeration
  caps → config (leave the array_chunk(...,30) — that's Cloudflare's hard limit).

### B21 — test/prod parity (P2) — combine plan+impl, single review
Source: `sources/parity-jobs.md`. One finding `parity-jobs/PARITY-1`: `pre_account_builds.user_id` is
NOT NULL in prod but nullable in tests/Pest.php's SQLite stub, and the factory never sets it. Change the
stub to NOT NULL; the breakages that surface ARE the parity gap becoming visible — fix them, don't relax
the stub back. NOTE: B9 + B11 changed pre_account_builds heavily (user_id is set via `associate()`, is
non-fillable). Verify the current creation paths set user_id before flipping the stub.

### B13 — Cloudflare Worker hardening (P2) — BLOCKER (Worker/KV), FULL rigour, present plan + WAIT
Source: `sources/edge-worker.md`. The Worker returned no P0/P1 — these are defence-in-depth.
`SyncSubdomainToKvJob` is the ONLY writer to SUBDOMAIN_KV; do NOT add a second writer or change routing.
- `edge-worker/EDGE-2` (P2 S): delete Cookie/Authorization from the request forwarded to PARTNA_PAGES.
- `edge-worker/EDGE-1` (P2 S): strip/override Vary on the cloned response before caches.default.put.
- `edge-worker/CFG-3` (P2 M): a BUILD-TIME CI diff between config('partna.reserved_subdomains') and the
  JS RESERVED set — NOT a runtime fetch (the reserved check is on the hot path).
- `edge-worker/CFG-1` (P3 S): cache TTLs → wrangler.toml [vars]. `CFG-2` (P3 S): PARTNA_DOMAIN →
  env var.
- **ALSO fold in `edge-worker/PRIV-1` here** (deferred from B7): 5 console.error call-sites log the raw
  handle/hostname/URL — keep the identifier out of the structured field (P3, handle is already public).
  It's the same file + same single deploy path as B13, so it belongs in this Worker session.
Present the plan with blast radius (the Worker has one deploy path) + your recommendation and wait for
Josh's sign-off before implementing.

### B20 — schema: RLS gaps + column defaults (P2) — BLOCKER (RLS + migration), FULL rigour, present + WAIT
Source: `sources/pii-schema.md` (NOTE: pii-schema breached the recall threshold — 415KB — its clean
P0/P1 result is the least trustworthy in the gate; read carefully). ⚠️ Schema change → NEW migrations →
gated per the standing decision above.
- `pii-schema/SCHEMA-1` (P2 S): site.workplaces created without RLS. `SCHEMA-2` (P2 S):
  site.content_selection without RLS. `SCHEMA-3` (P2 S): menu_platform_links / menu_item_platforms UUID
  PKs lack a gen_random_uuid() DB default. All three are genuine and need NEW migration files.
- `user-api/SCHEMA-1` (P2 M): design_kits updateOrInsert race — **premise likely already unreachable:**
  `trg_create_empty_design_kit` (20260527070000, hardened ON CONFLICT DO NOTHING in 20260602010000)
  inserts the design_kits row atomically at site creation. Verify historical rows were backfilled, then
  likely close `no_change_needed` — do NOT author a backfill migration without confirming a real gap.
- `staff-api/SCHEMA-1` (P3 M): core.users staff search lacks pg_trgm GIN indexes for ILIKE '%term%'.
  New migration (pg_trgm extension + CONCURRENTLY indexes).
Present the plan: which items need new migrations, the smallest-correct DDL, and the new-version-on-live-
dev hazard. Wait for sign-off. Per the cutover note, migration work is hygiene for local db reset /
preview / DR — scope accordingly.

### S4 — eleven-model $fillable sweep (P2, effort L) — BLOCKER, FULL rigour, present plan + WAIT
Source: `sources/models-data.md` (the SEC-1 entry there is comprehensive — read it in full). Replace
permissive mass-assignment ($guarded=['id'] on the 6 Moderation models; $guarded=[] on the 2 Views;
tenancy-FK/system fields in $fillable on ContentSelection, Enquiry, Feedback, User, IntegrationConnection,
EmailSubscription, EarlyAccessSignup, Block) with explicit allowlists. **APPLY THE B11 $FILLABLE LESSON
ABOVE** — this is the same class, larger. For each field removed: grep every write site, convert trusted
writers to forceFill/forceCreate/associate FIRST, add value-assertion tests, run the FULL suite, and
CHECK THE TEST-SIDE BLAST RADIUS before removing any NOT-NULL-no-default field (present it if it's
disproportionate, as with User.handle in B11). The audit's "user verified every write path safe" is about
runtime exploitability, NOT about whether removal breaks the app's own writes — it does. Keep
account_type + primary_email fillable on User (validated flows). Views models: `$guarded=['*']` (read-only
fail-fast), not `[]`. Present the plan with the per-model write-site map + test blast radius and wait for
sign-off; this is L-effort — expect it to be large.

## Discovered items (in CONSOLIDATED `## Discovered during execution`) — leave for their own units
DISC-1 (→B19), DISC-2 (accepted), DISC-3 (191-file stub sweep), DISC-4 (ffprobe test), DISC-5 (staff
{category} 500 — DONE, shipped 2026-07-21), DISC-6 (UserBootstrapService handle TOCTOU — DONE, shipped
2026-07-21), DISC-7 (InstagramAutoSync pre-consent sibling connections). Do NOT work the remaining
ones here unless one blocks a unit.

## Also still open (do NOT forget at archive time)
- B8 `models-data/PRIV-2` + `PRIV-3`: audit.user_deletion_audit + audit.data_export_audit retention
  purges — DEFERRED to the pre-cutover schema window (Josh). Need a SECURITY DEFINER prune migration.
- B7 `edge-worker/PRIV-1`: folded into B13 (above).

## When these six units are done
- `composer test` once for the whole branch — must be green.
- Do NOT run archive-done.sh — the P3 units (B16–B19) + the two deferred B8 audit-purges + the discovered
  items remain, so not every box is [x].
- Report: units done, units blocked (with reasons), test status, branch name. Hand off to
  PROMPT-execute-P3-remaining.md. Do NOT push — Josh reviews and merges.

## Stop and ask if
- A blocker's plan is ready (B13, B20, S4) — present it with blast radius + recommendation.
- A finding needs a new migration (gated) — flag it, don't fold it in.
- A $fillable removal (S4) has a disproportionate test-side blast radius — present it before proceeding.
- Two review rounds fail on a unit — mark it blocked and surface it.

=== PROMPT END ===
```
