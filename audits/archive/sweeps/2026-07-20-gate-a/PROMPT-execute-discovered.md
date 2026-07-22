# Gate A — execute prompt: DISCOVERED items (DISC-1, 3, 4, 7, 8, 9)

Continues `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md`. The six P2/P3 units (B13–B21, S4) are
DONE + merged + deployed (see `## Progress`). This prompt clears the **open items in
`## Discovered during execution`** — bugs the audit's own scope never opened, found while working
the units. DISC-2/5/6 are already `[x]`; this covers the six that remain.

**How to use:** open a fresh Claude Code session on **Opus**, then paste everything from
`=== PROMPT START ===` to the end as your first message.

---

```
=== PROMPT START ===

Continue executing audit audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md. Work ONLY the open
`discovered/DISC-*` items listed below. Follow scripts/audit/fix-flow.md with the overrides below.

## First: orient
- `git fetch && git checkout audit-fix/gate-a-2026-07-20 && git rev-parse --abbrev-ref HEAD`
  — CONFIRM the branch name reads exactly `audit-fix/gate-a-2026-07-20`. This branch already contains
  origin/development (a merge landed 2026-07-21); if origin/development has moved a lot since, consider
  `git merge origin/development` first and re-gate, so your work stays current.
- Read CONSOLIDATED.md `## Discovered during execution` end to end — each DISC entry has the full
  Where / What to do / Why. OPEN the cited file before planning.

## Standing decisions (carry from parts 1–3; these override the runbook where they conflict)
- **VERIFY EVERY PREMISE** against the actual code/DDL before touching anything — roughly half of the
  original findings had stale premises. A `no_change_needed` with quoted evidence is a valid outcome.
- **GIT:** verify the BRANCH NAME (`git rev-parse --abbrev-ref HEAD`), not just HEAD SHA, before EVERY
  commit (a concurrent checkout in the shared main worktree can switch it). `git diff --cached --stat`
  before committing. NEVER `git stash` / `git checkout <file>` / `git restore` / `git reset` — use
  `git show <ref>:<path>`. Work in an isolated worktree if other sessions are active. Do NOT push to
  development/production without explicit sign-off (a push to development = live deploy).
- **NO new migration applied to any live DB.** A NEW file under supabase/migrations/ is db-pushed to the
  LIVE dev Supabase by `db push`. If a DISC genuinely needs a new migration (DISC-8 option A), flag it as
  gated and WAIT for Josh — do not fold it in.
- **SQLite string-literal / NOT-NULL trap:** an unknown quoted identifier is a STRING LITERAL on SQLite,
  and SQLite ignores NOT-NULL — "the query ran / 200" proves nothing. Verify column names against real
  DDL in supabase/migrations/; assert returned DATA.
- **Cadence:** plan(Opus)→implement(Sonnet)→independent-review(separate Sonnet). Pin `model: sonnet` on
  implement/review spawns. Tick a box `[ ]`→`[x]` ONLY after tests pass AND review says PASS. `composer
  test` is the gate — run it per unit, NEVER while a subagent is running tests, and run the final
  full-branch gate QUIETLY (no concurrent agents: `ConnectResolverYoutubeTest` is a known flaky
  wall-clock test that starves under CPU load — re-run it solo to confirm, don't chase it). Commit code +
  ticked audit file together: `fix(audit): DISC-<n> — <summary>`. End commit messages with:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>

## Items (work smallest/safest first)

### DISC-9 — `site.blocks` SQLite stub nullable vs prod NOT NULL (P3) — SMALL, do first
Exactly the B21 shape for a different table. `tests/Pest.php::setupBlocksTable()` declares
`user_id`/`site_id` NULLABLE; prod (`20260526000000_baseline_standalone_user.sql`) has both NOT NULL.
Flip the stub to NOT NULL; the breakages that surface ARE the parity gap — fix them, don't relax the
stub. S4 Tier 2b already converted the Block writers, so blast should be near-zero. Combine plan+impl,
single review.

### DISC-4 — no test exercises the real ffprobe chain (P3) — test-only
`app/Services/Media/VideoVariantService.php::probe()` (~L75-79) is mocked wholesale in every video test,
so the fail-closed control `requests-resources/SEC-2` was CLOSED relying on is verified by nothing. Point
`config('partna.ffprobe_binary')` at a stub script (one exiting non-zero, one emitting bad JSON) and
assert the real Process → RuntimeException → InvalidVideoFileException → 422 chain (see the
`reference-testing-information_schema-sqlite` pattern for stub scripts). No app-code change. Combine
plan+impl, single review.

### DISC-7 — InstagramAutoSync writes pre-consent sibling connections (P3) — DESIGN DECISION, present first
`InstagramConnectionSeeder::seed()` → `InstagramAutoSync::seed()` (`app/Services/Platforms/InstagramAutoSync.php`)
writes NEW `IntegrationConnection` rows (facebook/tiktok/x/linkedin/fresha/square) parsed from a scraped
bio, for a not-yet-consenting provisional (`unclaimed`) subject. B12's PRIV-2 trimmed the IG connection's
own fields but did NOT undo these siblings. `seed()` is shared machinery, so skipping it for provisional
builds means threading a provisional flag. Present the plan + a recommendation (skip AutoSync when the
subject is `unclaimed`? gate on capability?) and WAIT for sign-off — this is the "collects more
pre-consent than needed" question, larger than the payload fields B12 addressed.

### DISC-3 — three shared SQLite stubs declare dropped columns (P3, M) — 191-FILE BLAST, plan first
`tests/Pest.php::setupSitesTable()` (~L489-493: hero_title/hero_subtitle/primary_button_text/
primary_button_url/bio_text — dropped by `20260705120002`), `setupServicesTable()` (~L1189-1198: 10
`square_*`/`fresha_*` cols), and `tests/Feature/.../ServicesIsolationTest.php:18` (`square_variation_id`
+ its false justifying comment). Inverse of a masked-bug (dead cols PRESENT, not real cols missing —
nothing in app/ selects them, verified by grep), so it's hygiene, not a live bug. But `setupSitesTable()`
is used by **191 test files** — present the plan + blast-radius before editing; expect churn. FULL rigour.

### DISC-1 — DROP INDEX without CONCURRENTLY on a HOT table (P2) — migration-safety, gated/cutover
`supabase/migrations/20260701180000_strip_block_settings_keys_and_views.sql:19` does `DROP INDEX` (no
CONCURRENTLY) inside a transaction alongside a full-table `UPDATE site.blocks` — and `site.blocks` IS on
`scripts/guard-no-unsafe-migrations.php:34`'s HOT_TABLES list. This is a strictly-stronger instance of
S1's `migrations-early/MIG-1`. **Earmarked to fold into B19** (`PROMPT-execute-P3-remaining.md`), which
works the same migration-safety area — do it THERE, or here if B19 hasn't run, but do it ONCE and tick so
B19 doesn't duplicate. NOTE: this migration is already applied to dev, so an in-place edit is hygiene for
fresh `db reset` / preview / the cutover baseline only (it does NOT re-run on dev). Do NOT author a new
forward migration for it. Consider extending the guard script with a `DROP INDEX … ` (non-CONCURRENTLY)
check on HOT tables so this class is caught automatically.

### DISC-8 — `HasActionLogLifecycle` writes a non-existent column (P2) — DECISION, present first
`app/Jobs/Moderation/Concerns/HasActionLogLifecycle.php:64` does
`ActionLogEntry::query()->where('id', …)->update(['status'=>'failed', 'failed_at'=>now(), …])`, but
`moderation.action_log` (`20260528000000_create_moderation_schema.sql`) has NO `failed_at` column → a
latent 42703 (undefined_column) on Postgres in the moderation action-FAILURE path (query-builder update,
so it bypasses $fillable and is NOT caught by SQLite). Two options — present both + recommend:
  - (A) add a `failed_at timestamptz NULL` column → NEW migration → **gated**, WAIT for Josh; or
  - (B) drop the phantom column from the write and record failure via the existing `status='failed'` +
    `failure_reason` (no schema change — likely the right call; verify no reader depends on `failed_at`).
Add a Postgres-gated test that the failure path actually persists (the SQLite suite won't catch this).

## When done
- `composer test` once for the whole branch — green (run it quietly; re-run any flaky wall-clock test
  solo). Update the `## Progress` counts + the DISC checkboxes.
- Do NOT run archive-done.sh unless every remaining box (incl. the P3 units + deferred items) is `[x]`.
- Report: items done, items presented-and-waiting (DISC-7, DISC-8, and DISC-1 if new migration), test
  status, branch. Do NOT push — Josh reviews and merges/deploys.

## Stop and ask if
- A DISC needs a new migration (DISC-8 option A) — flag it, don't fold it in.
- DISC-7's design decision or DISC-3's 191-file blast is ready — present with a recommendation.
- Two review rounds fail on an item — mark it blocked and surface it.

=== PROMPT END ===
```
