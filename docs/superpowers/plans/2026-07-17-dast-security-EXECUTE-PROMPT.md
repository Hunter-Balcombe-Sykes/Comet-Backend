# Execute prompt — implement DAST security scanning (`scripts/dast/`)

Paste everything below the line into a fresh Claude Code session **running Opus** in this repo.

---

Implement the **DAST security scanning** plan end-to-end: `docs/superpowers/plans/2026-07-17-dast-security-implementation.md` (the plan is the source of truth — read it **fully** first; spec at `docs/superpowers/specs/2026-07-17-dast-security-design.md` for background/rationale only). The plan's **"Decisions locked in"** table and its four resolved open-questions are ACCEPTED as written — implement them. If the environment contradicts one (a spike fails, a CLI flag behaves differently), STOP and ask Josh rather than redesigning.

## What kind of build this is (read before planning your approach)

This is a **self-contained shell tool under `scripts/dast/`** — NOT a Laravel feature.

- **Zero** `config/partna.php`, app-code, or Supabase migration changes. If a phase seems to need one, STOP — it's out of scope for a `scripts/` tool.
- **No `composer test` and no `ci.yml` job** — by design (DAST is slow, needs a running target/stack, is manual/cron-invoked).
- **The acceptance gate is the Phase 8 self-test (the canary), not the app suite.** A DAST tool proves nothing with green unit tests; it proves itself by catching a known-bad and failing the build.
- It is **environment-interactive**: Docker, the Supabase CLI local stack, `php artisan serve`, and live scans. Much of the work is *running* things and *observing* output, not just writing files.

## Precondition — get the plan onto your build branch

The plan + spec currently live on `feature/launch-check-suite`, not `development`. Before Phase 0:

```bash
git fetch origin
git checkout -b feat/dast-security origin/development
git checkout feature/launch-check-suite -- \
  docs/superpowers/plans/2026-07-17-dast-security-implementation.md \
  docs/superpowers/plans/2026-07-17-dast-security-EXECUTE-PROMPT.md \
  docs/superpowers/specs/2026-07-17-dast-security-design.md
git add -A && git commit -m "docs(dast): carry plan+spec onto feat/dast-security"
```

Work in the **main checkout** (harness `.claude/worktrees/` symlink vendor/.env and break things). **This is a shared repo — commit after every phase and `git add` new files immediately.** (This plan's own authoring session lost an uncommitted draft to a concurrent branch switch — don't repeat it.)

## Your role & approach

Use the **superpowers:executing-plans** skill (phase-by-phase with review checkpoints) — it fits an environment-interactive build better than pure orchestration.

- You **personally** run the three **spikes** and every live Docker / `supabase` / scan / verify step — those need coherent hands-on iteration and judgment, not delegation.
- You **may** delegate the isolated *script-authoring* of a phase to a subagent. If you do: you are an **Opus** session and child agents **inherit the main-loop model** — pass `model: "sonnet"` **explicitly** on every `Agent` call. Never run two agents that execute scans/Docker concurrently (port + container-name collisions).
- After each phase: prove the phase's **"Done when"** empirically (show the command output), do an **independent review pass** (a fresh `model: "sonnet"` reviewer over the phase's diff + a re-run of its Done-when, OR your own skeptical re-verification if the phase is pure shell glue), tick the plan checkboxes, then commit.

## Build order

Phases **0 → 1 → 2 → 3 → 4** are the **active lane** (each depends on the previous). Phases **5 → 6 → 6b** are the **edge lane** and depend only on Phase 0 — they are independent of the active lane. **7 → 8 → 9 → 10** close out.

**Recommended order:** do Phase 0, then the **edge lane (5 → 6 → 6b)** first — it's non-destructive, needs no local stack, and gets you a runnable weekly scan fastest — then the active lane (1 → 4), then 7 → 10. (The plan numbers the active lane first for narrative; dependency-wise the edge lane is the lower-risk start.)

## The three spikes — resolve empirically, document the working mechanism in-code

1. **Port-offset `--workdir` (Phase 1).** Confirm `supabase start --workdir "$SCRATCH"` honours the scratch `config.toml` + migrations symlink and the offset actually dodges Comet's `54321–54327`. If `--workdir` semantics differ in the installed CLI, fall back to copy-`supabase/`-into-scratch + `cd`. **Stop Comet first if it's up** (sibling stack shares those ports).
2. **JWT signing mode (Phase 3).** `supabase status` → is the local stack HS256-shared-secret or JWKS-only? Prefer direct-sign HS256; configure the served app's `.env.dast` so `VerifySupabaseJwt` accepts it. If JWKS-only, mint with the stack's private key. The done-when (token A → 200 on A's resource, 404/403 on B's) is the proof it worked.
3. **macOS Docker networking (Phase 4).** `--network host` differs on Docker Desktop for Mac; the ZAP-container-reaching-`127.0.0.1` detail is likely `host.docker.internal`. Pin it here.

## Gotchas block (keep these in front of you every phase; hand them to any subagent verbatim)

- **NEVER point the active lane at real dev/prod.** The active lane fuzzes and mutates — it runs **only** against the runner's own isolated local stack. The edge lane runs against `EDGE_TARGET` (dev now) but is **non-destructive (GET/HEAD + passive) only** and **rate-limited** (`DAST_EDGE_RATE_LIMIT`, default 20) — an unthrottled sweep trips Cloudflare's WAF and reads challenge pages as "clean" (false negatives). Sanity-check responses aren't CF interstitials before trusting a clean edge result.
- **Teardown is load-bearing.** Set the `trap` **before** `supabase start`; prove a forced mid-bring-up `exit 1` leaves **no** `partna-dast` containers and no orphaned `artisan serve` (`docker ps` + `pgrep -f 'artisan serve'` clean). A lane that leaks a stack rots the machine.
- **External-side-effect exclusions are load-bearing.** The served app runs `QUEUE_CONNECTION=sync` (jobs inline), so an un-excluded mutating route could hit a vendor API, send real email, or write Cloudflare KV **from a "local" scan**. Exclude `platforms/*`, notification/email sends, and any `SyncSubdomainToKvJob` (subdomain create/rename) route — and **only** those (ordinary internal mutation is free and desired on the throwaway DB).
- **Baselines start EMPTY.** `baseline/zap-baseline.json` = `[]`, `baseline/zap-passive-baseline.json` = `[]`, `baseline/nuclei-baseline.txt` = header only. Populated **only** by the Phase-10 reviewed triage, never pre-seeded (pre-seeding buries real bugs).
- **Local ≠ prod authz fidelity.** The local stack does not reproduce the `app_backend` restricted role + RLS. Triage local-only-RLS artifacts **into** the passive/active baseline with a note — don't chase them as real bugs. A green active lane ≠ "prod RLS proven"; say so in `REPORT.md`.
- **Two different "baselines".** A ZAP baseline *scan* (Phase 6b, `zap-baseline.py`, passive mode) is a scan **mode**; the `baseline/` folder is the **triaged accepted-findings** store. Passive findings → `baseline/zap-passive-baseline.json`, kept separate from the active lane's `baseline/zap-baseline.json` (different target host).
- **Tools run from Docker.** Nuclei + wcvs are not installed; ZAP has no host binary. `docker pull` all three in Phase 0 (`zaproxy/zap-stable`, `projectdiscovery/nuclei`, `hackmanit/web-cache-vulnerability-scanner`). The Phase-6b passive scan reuses `zaproxy/zap-stable` — no new image.
- **Fresh-DB provisioning is documented-flaky.** Phase-1's retried `supabase start` + `db reset` mitigates; if bring-up fails 3× the lane must `die` loudly, never silently skip.

## Acceptance gate (the whole tool's definition of done — Phase 8)

`tests/dast-selftest.sh` must pass, proving the scanner isn't silently broken:
- **Canary, both lanes:** plant the deliberately-vulnerable fixture (active: reflected-input route behind `DAST_CANARY=1`; edge: local server exposing `/.env`), run the lane, assert **(a)** the vuln is flagged in scanner output **AND (b)** `run.sh` exits non-zero. Both halves — a scanner that finds-but-doesn't-fail is as broken as one that doesn't find.
- **Clean + suppression:** clean target → exit 0; add the finding's stable key to the baseline → suppressed on re-run, exit 0.

Do not call the tool done until this gate is green for **both** lanes.

## Ship — STOP, do not push

This is a new tool on a branch that's tangled with a parallel session's work. When the acceptance gate is green:

1. Ensure every phase is committed on `feat/dast-security` with the plan checkboxes ticked and each commit SHA noted next to its phase.
2. Run the full self-test once more from a clean state; paste the output.
3. **Report to Josh and stop — do NOT merge or push.** Summarize: which phases landed, the self-test evidence, any spike that resolved differently than the plan assumed, and the first-run triage results (Phase 10) with what went into each baseline and why. Josh decides the merge/push.

## Failure posture — stop and ask Josh when

- A spike resolves in a way that contradicts a "Decisions locked in" entry (e.g. JWKS-only local stack forces a different auth model).
- Bring-up fails 3× even after stopping Comet (the documented fresh-DB flakiness).
- The active lane cannot be reliably isolated from external side effects (an exclusion you can't express).
- Anything tempts you toward an app-code / migration / `config/` change — that's out of scope by definition.
- Anything requires touching **prod** Supabase or prod hosts (prod is paused + out of scope; `EDGE_TARGET` stays dev until Josh re-points it at cutover).
