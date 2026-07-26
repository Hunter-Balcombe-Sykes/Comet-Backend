# DAST Security Scanning — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan phase-by-phase. Phases use checkbox (`- [ ]`) syntax for tracking. Phases are numbered 0–10 (plus 6b, the weekly OWASP ZAP passive baseline scan) and have explicit dependencies — do not run a phase before its dependency's "Done when" is met.

**Goal:** Build `scripts/dast/` — a committed, re-runnable member of the assurance suite (sibling to `scripts/launch-check/` and `scripts/audit/`) that fills the one empty cell of the assurance 2×2: *dynamic × code*. It sends hostile HTTP requests at a running target and inspects the responses. Two lanes: an **active lane** (ZAP fuzzing an isolated, runner-owned local stack) and an **edge lane** (Nuclei + wcvs + a weekly OWASP ZAP passive baseline scan probing the real deployed host, non-destructively). Real findings feed the `execute audit` fix-flow; a triaged baseline turns it into a gate.

**Architecture:** `run.sh --only active|edge` is the single entrypoint. The **active lane** owns an isolated `supabase start` on a port offset (dodging the sibling "Comet" stack), seeds two identities that own distinct resources, drives an authenticated + cross-identity ZAP active scan, and tears the stack down via `trap`. The **edge lane** runs a curated tag-pinned Nuclei set + custom assert templates + `wcvs` cache-deception + a weekly **OWASP ZAP passive baseline scan** (`zap-baseline.py` — spider + passive rules, no active attacks) against `EDGE_TARGET`, all non-destructive. Both lanes diff against a committed, stable-keyed baseline and exit non-zero only on NEW findings ≥ `--fail-on`.

**Tech Stack:** Bash runner; Docker-hosted scanners (ZAP `zaproxy/zap-stable`, Nuclei `projectdiscovery/nuclei`, wcvs `hackmanit/web-cache-vulnerability-scanner`); Supabase CLI local stack; `php artisan serve` + `php artisan route:list --json`; PHP one-shot for JWT minting; jq for JSONL diffing. No Laravel app-code or `config/` changes — this is a self-contained tool under `scripts/dast/`.

**Spec (source of truth):** `docs/superpowers/specs/2026-07-17-dast-security-design.md` (Approved, revised 2026-07-17). Read it before starting. This plan implements that spec verbatim and resolves its four "Open implementation questions" as settled decisions below.

---

## Decisions locked in (from spec + revision)

| Decision | Choice | Rationale |
|---|---|---|
| Active-lane stack | **Runner owns an isolated bring-up** (`supabase start` on a port offset, retried, `trap`-teardown) | Reverses the old preflight-only stance; the highest-value findings live here, so the stack must come up without a human or the lane rots |
| Active-lane identities | **Two seeded JWTs** owning distinct resources + one unauth pass | A single JWT structurally cannot find horizontal privilege escalation / IDOR — the top runtime authz class for this app |
| JWT claim shape | Reproduce prod shape: `sub` / `aal` / `amr` (+ `iss`/`aud`/`exp`) | Otherwise authenticated scans hit false 401/403 walls and under-test |
| Cache-deception tool | **`wcvs` ships in v1**, first-class in the edge lane | Cache deception/poisoning is the one threat class unique to the Worker + Cache API front end; neither ZAP nor Nuclei covers it |
| Exclusion rule (active) | Exclude **only external-side-effect routes** (vendor APIs, email, KV writes), NOT ordinary mutating routes | Local DB is throwaway — internal mutation is free and desired; only calls that reach *past the local box* must be excluded |
| Local ≠ prod authz fidelity | **Carried as a stated limitation, not solved** | Prod authz uses the `app_backend` restricted role + RLS via Supavisor; local doesn't reproduce it. Green active lane ≠ "prod RLS proven". Stays a post-launch human-pentest gap |
| Gating | `--fail-on high` default; exit non-zero only on NEW findings ≥ threshold not in baseline | Baseline-after-triage: never baseline first |
| Cadence | Active = manual only; edge = weekly cron; **neither in `ci.yml`** | Slow + needs a target/stack |
| Weekly OWASP ZAP baseline | A non-destructive **ZAP baseline scan** (`zap-baseline.py`, spider + passive rules) runs as the third edge-lane tool on the weekly edge cron against `EDGE_TARGET` | Passive ZAP crawls the live surface for missing headers / cookie flags / CSP / info leaks that Nuclei's template match and wcvs's cache probes don't cover; safe on live traffic; reuses the already-pulled ZAP image (no new tool) |

### Open questions from the spec → resolved for this plan

| Spec open question | Resolution in this plan | Where |
|---|---|---|
| ZAP seed format: `route:list --json` → context URLs vs generated OpenAPI | **Spike both in Phase 2, default to the one ZAP imports cleanly.** Recommend a generated minimal OpenAPI 3 doc (ZAP's `openapi` import expands path params + methods better than a flat URL list) with a flat-URL fallback | Phase 2 |
| Local JWT minting: direct-sign vs create+exchange | **Direct-sign HS256 with the local stack's JWT secret** (`supabase status`), served app configured to accept the shared-secret fallback. Phase-3 spike confirms the local stack's signing mode (HS256 shared-secret vs JWKS) and adjusts | Phase 3 |
| Curated Nuclei subset | **Tag-pinned allowlist** (`exposures,misconfiguration,http,ssl,cache`) + explicit `-severity` floor, never "run everything" | Phase 5 |
| Where the assert-style edge checks live | **Author them as Nuclei `templates/` now** (edge lane owns them). The not-yet-built launch-check probe suite will *call the edge lane* rather than re-author. Flagged to revisit when that suite is built | Phase 5 |

---

## Prerequisites

### Tools

| Tool | Present on this machine? | How the runner uses it | Install / provision |
|---|---|---|---|
| `docker` | ✅ `/usr/local/bin/docker` | Hosts all three scanners; runner pulls images | already installed |
| `supabase` CLI | ✅ `/opt/homebrew/bin/supabase` | Active-lane isolated stack | already installed |
| `php artisan route:list --json` | ✅ works (458 routes) | Active-lane seed source | already available |
| **ZAP** | ⚠️ via Docker only — no host binary | Active-lane fuzzer **and** edge-lane passive baseline scan (`zap-baseline.py`, same image) | `docker pull zaproxy/zap-stable` (no `brew`/host install needed) |
| **Nuclei** | ❌ **NOT installed** | Edge-lane matcher | `docker pull projectdiscovery/nuclei` (preferred, hermetic) or `brew install nuclei` |
| **wcvs** | ❌ **NOT installed** | Edge-lane cache-deception | ~~`docker pull hackmanit/web-cache-vulnerability-scanner`~~ **no such image exists on Docker Hub or GHCR (confirmed 2026-07-26)** — build locally via `require_wcvs_image()` in `lib/common.sh`, which clones upstream's repo pinned to release tag `2.0.0` and `docker build`s it, tagged `hackmanit/web-cache-vulnerability-scanner:2.0.0` so every later `docker run hackmanit/web-cache-vulnerability-scanner` call is unchanged |
| `jq` | assume present (verify in Phase 0) | JSONL parsing / baseline diff | `brew install jq` if missing |

> **Flag for the operator:** Nuclei and wcvs are **not installed**; ZAP has **no host binary**. The plan runs all three from Docker; ZAP and Nuclei are `docker pull`, wcvs is `docker build` from a pinned upstream tag (see row above — Phase 0 does this via `require_wcvs_image()`). Do not assume a host `nuclei`/`zap.sh` exists.

### Secrets & env (the runner reads these; never commit real values)

| Key | Lane | Meaning |
|---|---|---|
| `ZAP_TARGET_LOCAL` | active | Base URL the runner serves the app on, e.g. `http://127.0.0.1:8100` |
| `DAST_SUPABASE_PORT_OFFSET` | active | Integer added to every `543xx` port in the scratch config, default `100` → `544xx` (dodges Comet's `54321–54327`) |
| `SUPABASE_LOCAL_JWT_SECRET` | active | The local stack's JWT signing secret (from `supabase status`), used to mint the two identity tokens. **Secret — never commit.** |
| `EDGE_TARGET` | edge | API host for Nuclei, defaults to `https://dev-api.partna.au` now; re-pointed to prod at cutover |
| `EDGE_SITEPAGE_TARGET` | edge | A sample `<handle>.partna.au` host for wcvs cache-deception + alias-301 asserts |
| `DAST_FAIL_ON` | both | Default severity floor for non-zero exit, default `high` (overridable by `--fail-on`) |
| `DAST_EDGE_RATE_LIMIT` | edge | Requests/sec cap so the run doesn't trip Cloudflare's WAF, default `20` |

### `.env.example` (committed, at `scripts/dast/.env.example`)

```
# --- active lane (local, runner-owned stack) ---
ZAP_TARGET_LOCAL=http://127.0.0.1:8100
DAST_SUPABASE_PORT_OFFSET=100
SUPABASE_LOCAL_JWT_SECRET=

# --- edge lane (real deployed host, non-destructive) ---
EDGE_TARGET=https://dev-api.partna.au
EDGE_SITEPAGE_TARGET=https://<sample-handle>.partna.au
DAST_EDGE_RATE_LIMIT=20

# --- gating ---
DAST_FAIL_ON=high
```

---

## File map

### New files (all under `scripts/dast/`)

| Path | Responsibility | Phase |
|---|---|---|
| `run.sh` | Entrypoint: `--only active|edge`, `--target <url>`, `--fail-on <sev>`, `--update-baseline`; arg parse, lane dispatch, REPORT.md merge, exit-code logic | 0, 7 |
| `.env.example` | Documented env keys (above) | 0 |
| `README.md` | What each lane does, how to run, how to triage → baseline | 0, 10 |
| `lib/common.sh` | Shared: env load, `log()`, docker-image preflight, `audits/dast/<date>/` output dir | 0 |
| `active/bring-up.sh` | Scratch-config + port-offset `supabase start` (retried), migrate, `php artisan serve`, health checks, `trap` teardown | 1 |
| `active/seed-endpoints.sh` | `route:list --json` → ZAP OpenAPI seed (+ flat-URL fallback) | 2 |
| `active/seed-identities.php` | Seed 2 users + distinct resources into the local DB; emit their ids + auth uids | 3 |
| `active/mint-jwt.php` | Direct-sign an HS256 Supabase-shaped JWT for a given `sub`/`aal`/`amr` | 3 |
| `active/zap-context.yaml` | ZAP context: scope, JWT replacer rules (per identity), external-side-effect exclusions | 3, 4 |
| `active/zap-active.sh` | Boot ZAP (docker), run authenticated active scan per identity + cross-identity + unauth pass | 4 |
| `edge/nuclei-edge.sh` | Nuclei vs `EDGE_TARGET`, curated tags + `templates/`, rate-limited | 5 |
| `edge/templates/*.yaml` | Custom assert templates (`.env`/`.git`, telescope/horizon, 404-not-403, alias-301, cache-control) | 5 |
| `edge/wcvs.sh` | wcvs cache-deception/poisoning vs `EDGE_SITEPAGE_TARGET` | 6 |
| `edge/zap-baseline.sh` | OWASP ZAP **passive** baseline scan (`zap-baseline.py`, spider + passive rules) vs `EDGE_TARGET`, non-destructive; reuses the `zaproxy/zap-stable` image | 6b |
| `baseline/zap-baseline.json` | Triaged/accepted **active** findings, keyed `alertRef + url` | 7, 10 |
| `baseline/zap-passive-baseline.json` | Triaged/accepted **passive** ZAP findings, keyed `alertRef + url` (separate from active — different target host) | 6b, 7, 10 |
| `baseline/nuclei-baseline.txt` | Triaged/accepted edge findings, keyed `template-id @ matched-at` | 7, 10 |
| `lib/diff-baseline.sh` | Normalize scanner output → stable keys, subtract baseline, decide exit code | 7 |
| `tests/canary/` | Deliberately-vulnerable fixtures (reflected-input route, exposed path) for the self-test | 8 |
| `tests/dast-selftest.sh` | Canary + clean/baseline-suppression proofs | 8 |

### Touched outside `scripts/dast/`

| Path | Change | Phase |
|---|---|---|
| `.github/` or existing cron config | Add weekly `--only edge` invocation (runs Nuclei + wcvs + the ZAP passive baseline) | 9 |
| `audits/dast/` | New output root (created at first run; add `.gitignore` for raw artifacts, commit `REPORT.md`) | 0 |

> **No** `config/partna.php`, app-code, or Supabase migration changes. If a phase seems to need one, STOP — it's out of scope for a scripts/ tool.

---

## Phase 0 — Scaffolding + `run.sh` arg parsing

**Depends on:** nothing. **Files:** `run.sh`, `lib/common.sh`, `.env.example`, `README.md` (stub), `audits/dast/.gitignore`.

- [x] **Step 1: Branch prep**

```bash
git fetch origin && git checkout development && git pull origin development
git log --oneline -5
git checkout -b feat/dast-security
```

- [x] **Step 2: Create the tree**

```bash
mkdir -p scripts/dast/{active,edge/templates,baseline,lib,tests/canary}
```

- [x] **Step 3: Write `scripts/dast/.env.example`** — the block from Prerequisites above.

- [x] **Step 4: Write `lib/common.sh`** — shared helpers:
  - loads `scripts/dast/.env` (falls back to env vars; never fails if a key is unset, only when a lane needs it),
  - `log()` / `die()`,
  - `dast_outdir()` → `audits/dast/$(date +%F)/` (create + `echo` the path),
  - `require_docker_image <name>` → `docker image inspect` or `docker pull`.
  - (added, not in original plan) `require_wcvs_image()` — wcvs has no prebuilt image; builds it from a pinned upstream tag. See Step 7 note.

- [x] **Step 5: Write `run.sh`** — arg parsing + dispatch skeleton:

```bash
#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib/common.sh"

ONLY="" TARGET="" FAIL_ON="${DAST_FAIL_ON:-high}" UPDATE_BASELINE=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --only)            ONLY="$2"; shift 2 ;;
    --target)          TARGET="$2"; shift 2 ;;
    --fail-on)         FAIL_ON="$2"; shift 2 ;;
    --update-baseline) UPDATE_BASELINE=1; shift ;;
    -h|--help)         usage; exit 0 ;;
    *) die "unknown arg: $1" ;;
  esac
done
[[ "$ONLY" =~ ^(active|edge)$ ]] || die "--only active|edge required"

OUTDIR="$(dast_outdir)"
case "$ONLY" in
  active) "$HERE/active/zap-active.sh"  "$OUTDIR" "$TARGET" ;;
  edge)   "$HERE/edge/nuclei-edge.sh"   "$OUTDIR" "$TARGET"
          "$HERE/edge/wcvs.sh"          "$OUTDIR"
          "$HERE/edge/zap-baseline.sh"  "$OUTDIR" "$TARGET" ;;   # weekly OWASP ZAP passive baseline (Phase 6b)
esac
# Phase 7 wires diff-baseline + REPORT.md merge + exit code here.
```

- [x] **Step 6: `audits/dast/.gitignore`** — ignore raw artifacts, keep `REPORT.md`:

```
*
!.gitignore
!*/
!*/REPORT.md
```

- [x] **Step 7: Pull scanner images + verify tooling**

```bash
docker pull zaproxy/zap-stable
docker pull projectdiscovery/nuclei
# hackmanit/web-cache-vulnerability-scanner has no prebuilt image anywhere
# (confirmed 2026-07-26) — built locally instead, see lib/common.sh require_wcvs_image().
which jq || brew install jq
chmod +x scripts/dast/run.sh scripts/dast/lib/common.sh scripts/dast/active/*.sh scripts/dast/edge/*.sh
```

- [x] **Step 8: Commit.**

**Done when:** `scripts/dast/run.sh --only edge` reaches lane dispatch (even if the lane scripts are still stubs) and `--only bogus` dies with a clear message; all three images present locally. ✅ Verified 2026-07-26 — `--only bogus` dies with usage; `--only edge` reaches dispatch and fails loud on the Phase-5 stub; `docker images` shows `zaproxy/zap-stable:latest`, `projectdiscovery/nuclei:latest`, `hackmanit/web-cache-vulnerability-scanner:2.0.0`.

---

## Phase 1 — Active lane: isolated bring-up + `trap` teardown

**Depends on:** Phase 0. **Files:** `active/bring-up.sh`.

This is the reversal from the spec: the runner **owns** the stack. It must come up without a human and always tear down.

- [x] **Step 1: Scratch config with a port offset.** The committed `supabase/config.toml` uses `54321–54329` (collides with Comet by design). The runner MUST NOT edit the committed file. Instead it materializes a scratch workdir. **Deviation, verified 2026-07-26:** the plan's `sed` one-liner is broken — `sed`'s replacement text is not shell-evaluated, so it writes the LITERAL string `port = $((54321+100))` into the TOML file (invalid; TOML wants an integer, not an unevaluated shell expression). Replaced with a bash read-loop using `BASH_REMATCH` + real arithmetic:

```bash
OFFSET="${DAST_SUPABASE_PORT_OFFSET:-100}"
SCRATCH="$(mktemp -d)/dast-supabase"
mkdir -p "$SCRATCH/supabase"
while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" =~ ^port\ =\ (543[0-9]{2})$ ]]; then
        echo "port = $(( ${BASH_REMATCH[1]} + OFFSET ))"
    elif [[ "$line" =~ ^project_id\ = ]]; then
        echo 'project_id = "partna-dast"'
    else
        echo "$line"
    fi
done < "$REPO_ROOT/supabase/config.toml" > "$SCRATCH/supabase/config.toml"
ln -s "$REPO_ROOT/supabase/migrations" "$SCRATCH/supabase/migrations"
```

> **Phase-1 spike — RESOLVED 2026-07-26, plan's assumption confirmed:** `supabase start --workdir "$SCRATCH"` honours the scratch `config.toml` + migrations symlink exactly as-is, using CLI 2.101.0. No fallback to copy-then-cd needed. Verified end-to-end: offset ports (54421-54429), correct `project_id = "partna-dast"`, baseline migration applied automatically on the fresh scratch DB, and — critically — `supabase stop --workdir "$SCRATCH" --no-backup` cleanly removed all 12 `partna-dast` containers (confirmed via `docker ps -a`).

- [x] **Step 2: Retried `supabase start` + `trap` teardown — set the trap BEFORE start** so a mid-boot failure still tears down. Implemented as written; verified both a graceful `SIGTERM` and a **forced abort ~2s into bring-up** (killed the instant the first `supabase_db_partna-dast` container appeared, well before health-check/serve) leave zero containers, zero orphaned `artisan serve`, and no stray `.env.dast` (see Step 4). One cosmetic note: `trap teardown EXIT INT TERM` fires the handler twice on a caught signal (bash re-fires the EXIT trap after the INT/TERM handler returns) — harmless since every teardown action is idempotent (`|| true`, `rm -rf`/`rm -f`), left as-is rather than adding a guard flag for a non-bug.

- [x] **Step 3: Apply migrations to the local stack** — `supabase db reset --workdir "$SCRATCH"`. In practice this is usually a no-op (a truly fresh scratch volume already gets every migration applied during `start`), kept as a defensive idempotent step against a stale volume surviving a prior crashed run that skipped teardown.

- [x] **Step 4: Serve the app against the local stack.** Generate a scratch `.env.dast`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array` (avoids a Redis dependency; sync means jobs run inline — which is exactly why external-side-effect routes must be excluded in Phase 4). **Deviation — a real bug caught the hard way, 2026-07-26:** the plan says "scratch `.env.dast`" without specifying where; an earlier version of this script wrote it under `$SCRATCH` (the mktemp'd supabase workdir). `php artisan serve --env=dast` resolves `.env.dast` relative to the **application base path** (`$REPO_ROOT/.env.dast`), not `$SCRATCH` — with no file at the expected path, Laravel silently fell back to the real repo `.env`, meaning the served app was actually running against whatever `DB_HOST` the developer's real `.env` points at, **not** the isolated scratch stack. Caught via `php artisan tinker --env=dast` returning a DNS failure for an unrecognized third-party Supabase host instead of the expected `127.0.0.1` scratch DB. (No live-data exposure occurred — that real `.env`'s `DB_HOST` happens to be a dead host per existing project notes — but the failure mode is exactly what "NEVER point the active lane at real dev/prod" exists to prevent, so this had to be fixed, not shrugged off.) Fixed: `.env.dast` now written to `$REPO_ROOT/.env.dast`, added to `.gitignore`, and removed by `teardown()` on every exit path.

- [x] **Step 5: Health-check gate** — poll `GET $ZAP_TARGET_LOCAL/up` and the Supabase API `.../auth/v1/health` until both 200 or a 60s timeout. Implemented; also independently confirmed DB connectivity through the fixed `.env.dast` via `php artisan tinker --env=dast --execute="...core.users..."` (returned `0`, the expected empty-throwaway-DB count) — a `route:list`-independent proof per the plan's own Step-5 intent.

**Done when:** invoking `active/bring-up.sh` from a clean machine (Comet running or not) brings up an offset stack + served app, health-checks green, and — critically — a forced `exit 1` mid-script leaves **no** `partna-dast` containers and no orphaned `php artisan serve` (verify `docker ps` + `pgrep -f 'artisan serve'` are clean after a deliberate abort). ✅ Verified 2026-07-26, both halves: (1) normal run — healthy in 47s, `/up` → 200 "Application up", all 12 containers running, DB query succeeded through the scratch stack; (2) forced abort ~2s into bring-up — `docker ps -a` and `pgrep -f 'artisan serve'` both clean after teardown, `.env.dast` removed.

---

## Phase 2 — Active lane: route seeding

**Depends on:** Phase 1 (needs a running app to introspect + a target ZAP consumes). **Files:** `active/seed-endpoints.sh`.

- [x] **Step 1: Pull the route table** — `php artisan route:list --json` (487 `api/` routes now, up from the plan's 458 — codebase has grown; the filter logic is unaffected). Filter to the fuzz surface: `uri` starting `api/`. Checked for `_boost/*`, `up`, `{path}` catch-alls, and Vite/asset routes under `api/` — none currently exist there (the exclusion logic is still implemented defensively in case one is added later).

- [x] **Step 2: Spike — pick the seed format ZAP imports cleanly.** Both formats produced (`active/seed-endpoints.php`, a companion file — JSON transformation is far more natural in PHP than bash+jq, mirroring Phase 3's `mint-jwt.php`; deviation from the plan's file list of `active/seed-endpoints.sh` only). **Path params are NOT substituted with Phase-3 seeded ids at generation time** — declared as proper OpenAPI `parameters` (`in: path`, `required: true`) instead, letting ZAP's own import/scanner expand them. This resolves an apparent ordering issue in the plan: Phase 2 depends only on Phase 1, Phase 3 depends on Phase 1–2 (not the reverse), and Phase 4's own dispatch order runs `seed-endpoints.sh` *before* `seed-identities.php` — so real seeded ids cannot exist yet when this seed is generated. Real-id substitution for the cross-identity IDOR pass is Phase 4's context/exclusion concern, not the seed's.
  - OpenAPI import tested twice: first against no live target (proved the doc format itself is valid — ZAP attempted every operation with zero parse/format errors, only "Connection refused" since nothing was listening) then for real against a live `bring-up.sh` stack. **Confirms `host.docker.internal` (not `127.0.0.1`) is the correct target host from inside a ZAP container** — useful early confirmation for Phase 4's macOS Docker networking spike.
  - Kept both formats (no fallback needed — OpenAPI imported cleanly).

- [x] **Step 3: Write the artifact** to `$OUTDIR/seed-openapi.json` (and `seed-urls.txt`).

**Done when:** `seed-endpoints.sh` emits a seed covering the `api/` surface, path params substituted with Phase-3 seeded ids, and a manual `zap` import of that seed lists the endpoints in the ZAP sites tree with zero import errors. ✅ Verified 2026-07-26 (path-param substitution deferred to Phase 4 per the note above — see there for the real-id wiring) — against a live bring-up.sh stack, ZAP's `openapi` automation job: "Job openapi added 483 URLs... Job openapi finished... Automation plan succeeded!" Zero errors in the full run log.

---

## Phase 3 — Active lane: two-identity JWT auth + ZAP context

**Depends on:** Phases 1–2. **Files:** `active/seed-identities.php`, `active/mint-jwt.php`, `active/zap-context.yaml`.

- [x] **Step 1: Seed two identities that own distinct resources.** `seed-identities.php` — a standalone bootstrapped script (`--env=dast`, not `tinker --execute`) inserts, deterministically:
  - **User A** (`core.users`, known `id` + `auth_user_id`) owning a site, a gallery media row, a customer, and an enquiry.
  - **User B** — same shape, different ids.
  Emits `{id, auth_user_id, email, password, handle, site_id, media_id, customer_id, enquiry_id}` per identity to `$OUTDIR/identities.json` (`email`/`password` are new fields, not in the plan's original list — needed by Step 3, see below). **Deviation found the hard way:** `core.users.auth_user_id` carries a real FK to `auth.users` — an arbitrary generated UUID 23503-violates it. The row must exist via Supabase Auth itself: created through the local GoTrue admin API (`POST /auth/v1/admin/users`, service-role key) rather than invented, which is also what a real signup flow's Auth-first step does.

- [x] **Step 2: Confirm the local stack's signing mode (spike) — RESOLVED, plan's direct-sign approach does NOT work.** `supabase status` confirms HS256-shared-secret mode as the plan expected (`JWT_SECRET` present). But direct-signing an HS256 token with a forged `session_id` and hitting the app: `VerifySupabaseJwt` correctly rejects HS256 on its JWKS path (alg-confusion guard) and falls through to the Auth-Server path as designed — which forwards the token to GoTrue's `/auth/v1/user`. **GoTrue itself validates that the `session_id` claim corresponds to a real row in `auth.sessions`** — a forged one is rejected with `403 session_not_found` regardless of how correctly everything else is shaped. There is no way to forge a valid session_id; direct-signing is a dead end against this Supabase CLI version (2.101.0). Resolved via the plan's own named fallback: **create+exchange** — sign in for real via GoTrue's password grant, which issues a genuine RS256/ES256 token carrying a real session. A second, unrelated blocker surfaced en route: the committed `supabase/config.toml` has Turnstile captcha **enabled** by default (`[auth.captcha] enabled = true`), which 500s any password-grant sign-in with "captcha verification process failed" when no real Cloudflare secret is configured (`SUPABASE_AUTH_CAPTCHA_SECRET` is intentionally unset for local dev). Fixed by having `bring-up.sh`'s config transform also force `enabled = false` under `[auth.captcha]` in the **scratch** copy only — the committed config.toml is never touched.

- [x] **Step 3: `mint-jwt.php` — REWRITTEN to sign in via GoTrue's password grant, not direct-sign.** Per Step 2's finding, direct HS256 signing cannot produce a token GoTrue's session check will accept. `mint-jwt.php`'s interface changed from `<sub> <aal>` to `<email> <password>` (both emitted per identity by `seed-identities.php`); it POSTs to `/auth/v1/token?grant_type=password` and echoes the real `access_token`. Claim shape (`sub`/`aal`/`amr`/`iss`/`aud`/`session_id`) comes from GoTrue itself — matches prod naturally, no hand-crafting needed, and a bonus: since the token is genuinely RS256/ES256-signed, `VerifySupabaseJwt`'s **primary JWKS path** accepts it directly at runtime — `SUPABASE_JWKS_FAIL_CLOSED=false` stays in `.env.dast` as a defensive fallback but isn't load-bearing in practice.

- [x] **Step 4: `zap-context.yaml`** — three passes via ZAP's automation framework (context A / context B / unauth), each a `replacer` rule injecting `Authorization: Bearer <token>`, templated by Phase 4's `zap-active.sh` (`__TARGET_URL__`/`__TOKEN_A__`/`__TOKEN_B__` placeholders). Verified the YAML is valid and functional: ZAP's automation framework parsed and executed real requests through this exact replacer file with zero errors ("Automation plan succeeded!").

**Done when:** a manual authenticated request through ZAP's proxy with token A returns 200 on one of A's own resources (proving claim shape is accepted), and the same token returns 404/403 on one of B's resources (proving isolation is testable, i.e. the target for Phase 4's IDOR pass exists). ✅ Verified 2026-07-26, two complementary ways: (1) direct curl with token A's exact `Authorization` header — `GET /api/customers/{A's customer}` → **200** with A's own customer JSON; `GET /api/customers/{B's customer}` → **404** `{"message":"Resource not found"}` (404, not 403 — matches CLAUDE.md's public-endpoint convention); (2) the same token + same two URLs run through ZAP's own automation-framework `requestor` job using this exact `zap-context.yaml` replacer mechanism completed cleanly with zero plan errors, confirming the injection mechanism itself works end-to-end (ZAP's own per-request status-code test type wasn't identified in time to also get a machine-checked assertion from inside ZAP itself — the curl-based proof carries the authorization-logic claim).

**Second deviation, found investigating an unrelated symptom:** while chasing this Phase 3 auth flow, discovered `active/bring-up.sh`'s `php artisan serve --env=dast` was **not actually serving against the scratch stack** — Laravel's `ServeCommand` spawns a *separate* child process (`php -S host:port server.php`) to handle real HTTP requests, and by default (no `--no-reload`) filters `$_ENV` down to a small passthrough allowlist before starting that child, so it never receives `.env.dast`'s values and boots against the real repo `.env` instead (verified via `ServeCommand::startProcess()` source — see `bring-up.sh` comment). This corrects Phase 1's done-when claim: the tinker-based DB-connectivity proof there was real, but never actually exercised the *served* app, which — until this fix — was silently not isolated. Caught here via a JWKS-fetch error naming an unrecognized third-party Supabase host, not a DB error. Fixed with `--no-reload`, which makes `ServeCommand` pass the full (already-correct) `$_ENV` through unfiltered; no functionality lost since this one-shot bring-up never needed live-reload-on-`.env`-change. Re-verified Phase 1's health-check + DB-query proof against the fixed served app (see this phase's commit).

---

## Phase 4 — Active lane: scan + exclusion rules

**Depends on:** Phase 3. **Files:** `active/zap-active.sh`, finalize `active/zap-context.yaml`.

- [ ] **Step 1: Enumerate external-side-effect routes to exclude** (the spec's inversion — exclude ONLY these, not ordinary mutations). Grep the codebase for routes whose handlers dispatch jobs/services that reach past the local box:
  - vendor API calls — the `platforms/*` surface (187 routes) that connect/refresh external providers, Instagram mirror, etc.,
  - real email/notification sends,
  - `SyncSubdomainToKvJob` (Cloudflare KV writes) — any site create/rename/subdomain route,
  - webhook *dispatch* to external hosts.
  Encode as `excludePaths` regexes in `zap-context.yaml`. Document each exclusion with a one-line why (matches the audit-file `Evidence` discipline).

- [ ] **Step 2: `zap-active.sh`** — drive ZAP's automation framework (docker `zaproxy/zap-stable`, `zap.sh -cmd -autorun`) to:
  1. import the Phase-2 seed,
  2. **spider/import** each context,
  3. run **active scan** rules: SQLi, XSS, path traversal, command injection, + ZAP's **access-control** checks, per identity,
  4. run the **cross-identity IDOR pass**: context A's token against B's seeded resource ids (a 200 where 404/403 is expected = finding),
  5. run the **unauth pass** over the public surface,
  6. export JSON + HTML to `$OUTDIR/zap/`.

```bash
docker run --rm --network host \
  -v "$HERE/active:/zap/wrk/:rw" -v "$OUTDIR/zap:/zap/out:rw" \
  zaproxy/zap-stable zap.sh -cmd -autorun /zap/wrk/zap-plan.yaml
```

(`--network host` so the container reaches `127.0.0.1:$PORT`; on macOS Docker Desktop use `host.docker.internal` as the target host instead — set in the spike.)

- [ ] **Step 3: Wire into `run.sh`** — `--only active` calls `bring-up.sh` (which `trap`s teardown), then `seed-endpoints.sh`, `seed-identities.php`, mints tokens, runs `zap-active.sh`. Teardown happens on exit regardless of scan result.

**Done when:** a full `run.sh --only active` completes end-to-end on a clean machine (bring-up → seed → scan → teardown), produces `$OUTDIR/zap/*.json`, the exclusion regexes verifiably keep ZAP off the `platforms/*` and subdomain-write routes (grep the ZAP log for excluded URLs = 0 requests), and the stack is torn down afterward.

---

## Phase 5 — Edge lane: Nuclei curated set + custom assert templates

**Depends on:** Phase 0. **Files:** `edge/nuclei-edge.sh`, `edge/templates/*.yaml`.

- [x] **Step 1: `nuclei-edge.sh`** — curated, tag-pinned, rate-limited (never "run everything"). **Deviation from the plan's snippet, verified empirically 2026-07-26 against nuclei v3.11.0:** `-t /templates/` alone (as a single custom dir with no default `-t`) mounts ONLY the custom templates, dropping the curated official set entirely; and `-t <default> -it /templates/` (include-templates) silently drops all 5 custom templates when combined with `-tags`/`-severity` (0 of 5 loaded — confirmed via a local test target). The working mechanism is **two repeated `-t` flags** — `-t /root/nuclei-templates -t /templates/` — both filtered by the same `-tags`/`-severity`; this loads 31 templates (26 official + all 5 custom) in one pass. Actual script:

```bash
docker run --rm \
  -v dast-nuclei-templates:/root/nuclei-templates \
  -v "$HERE/edge/templates:/templates:ro" \
  -v "$OUTDIR:/out" \
  projectdiscovery/nuclei \
  -u "$TARGET" \
  -t /root/nuclei-templates -t /templates/ \
  -tags exposures,misconfiguration,http,ssl,cache \
  -severity low,medium,high,critical \
  -rate-limit "$RATE_LIMIT" \
  -var "alias_host=${ALIAS_HOST}" \
  -jsonl -o /out/nuclei.jsonl -stats
```

- [x] **Step 2: Author the five custom assert templates** (this is their **one home** — launch-check will call the lane, not re-author). One YAML each under `edge/templates/`:
  - `env-git-not-exposed.yaml` — `GET /.env` → 404, `/.git/config` → 404/403.
  - `debug-tools-gated.yaml` — `/telescope`, `/horizon` → not 200 (redirect/401/404).
  - `enumeration-404-not-403.yaml` — a missing/foreign resource on a public endpoint → **404**, never 403 (matches the 403-vs-404 standard in CLAUDE.md).
  - `alias-301-canonical.yaml` — a known alias handle/subdomain → **301** to the canonical URL (matches the handle-redirect lifecycle).
  - `cache-control-correct.yaml` — API responses carry no-store/appropriate `Cache-Control`; sitepage responses carry the cacheable header. Distinguishes the two surfaces.

Example template shape:

```yaml
id: env-not-exposed
info: { name: ".env not exposed", severity: high, tags: [exposures,partna] }
http:
  - method: GET
    path: ["{{BaseURL}}/.env"]
    matchers-condition: and
    matchers:
      - type: status
        status: [200]
        negative: true        # a 200 here is the finding
```

**Deviation, verified empirically 2026-07-26:** the `negative: true` in this snippet is backwards. Tested directly against a controlled local target (a Python http.server exposing a fake `.env`) — a plain `status: [200]` matcher with **no** `negative` correctly fires when the response IS 200 (the bad state). `negative: true` on `status: [200]` inverts to "fire when status is anything BUT 200," which is not what any of these templates want. All 5 custom templates use plain positive matchers on the bad-state condition instead. `alias-301-canonical.yaml` is the one legitimate use of `negative: true` in this set (fire when status is NOT 301 — a genuine "flag the absence of the good state" case) and its severity is `low` (not `high`) because it depends on a currently-live alias host (`EDGE_ALIAS_HOST`, transient data) — a stale/unset one must not noisily fail the gate. `cache-control-correct.yaml` only implements the deterministic no-store-on-tokenized-path half (see file) — the mirror-image "cacheable profile" assertion needs a live seeded handle and is deferred to Phase-10 manual triage per the plan's own "not-yet-built launch-check probe suite" deferral note.

- [x] **Step 3: Rate-limit + WAF note.** Keep `-rate-limit` low; `EDGE_TARGET` also serves live `api.partna.au` and sits behind Cloudflare — an unthrottled sweep trips the WAF and reads challenge pages as "clean" (false negatives). Documented in the script header.

**Done when:** `run.sh --only edge --target https://dev-api.partna.au` produces `nuclei.jsonl`, all five custom templates execute (visible in `-stats`), and none of the asserts false-positive against the known-good dev host on a first eyeball. ✅ Verified 2026-07-26 — `nuclei-edge.sh audits/dast/2026-07-26 https://dev-api.partna.au`: "Templates loaded for current scan: 31" (26 official + 5 custom), "Scan completed in 16.9s. No results found." — clean, zero false positives against dev.

---

## Phase 6 — Edge lane: wcvs cache-deception

**Depends on:** Phase 0. **Files:** `edge/wcvs.sh`.

- [x] **Step 1: `wcvs.sh`** — target the sitepage host (the Worker + Cache API front end), the one surface where cache deception is reachable. **Deviations, both verified 2026-07-26:**
  1. wcvs's real CLI (built from source — see Phase 0's wcvs note) has no `scan` subcommand or `--report`/`--generate-report` long flags; it's `-u <url> -gp <dir> -gr -gl` (generate-path + generate-report + generate-log), and it writes its own timestamp+random-suffixed filename (`WCVS_<date>_<rand>_Report.json`), not a fixed `wcvs-report.json` — the script copies that file to `$OUTDIR/wcvs-report.json` after the run so the artifact name matches this plan's convention.
  2. wcvs's default test suite includes a `dos` category, confirmed via upstream's own published references to implement "Responsible Denial of Service with Web Cache Poisoning" — a DoS-testing technique that contradicts this lane's non-destructive-only contract (gotchas block: edge lane is "GET/HEAD + passive only"). Excluded explicitly via `-skiptest dos`; every other default category (deception, cookies, css, forwarding, smuggling, headers, parameters, fatget, cloaking, splitting, pollution, encoding) runs.

```bash
docker run --rm -v "$WCVS_OUT:/out" hackmanit/web-cache-vulnerability-scanner:2.0.0 /wcvs \
  -u "$TARGET" -gp /out/ -gr -gl -nc -ns -skiptest dos -rr "$RATE_LIMIT"
```

Non-destructive by construction (crafted GETs probing whether a path-confusion / extension trick gets a private response cached for the next visitor). Rate-limit consistent with Phase 5.

- [x] **Step 2: Wire into `run.sh --only edge`** after Nuclei (already in the Phase-0 dispatch).

**Done when:** `wcvs.sh` runs against `EDGE_SITEPAGE_TARGET`, emits `wcvs-report.json`, and its findings (if any) are captured for the Phase-7 diff. A clean run against the current Worker config reports no confirmed deception. ✅ Verified 2026-07-26 — `wcvs.sh` against `https://user-kvjm7i.partna.au` (a real published dev handle, found via Supabase MCP query): "Skipping Cache Poisoned Denial Of Service" confirms the dos exclusion took effect; full scan (15m46s — tests 2922 headers + 6454 params + all other categories) completed with `foundVulnerabilities: false, hasError: false, isVulnerable: false`. Zero false positives against a known-good dev host.

---

## Phase 6b — Edge lane: weekly OWASP ZAP passive baseline scan

**Depends on:** Phase 0 (Docker + `zaproxy/zap-stable` already pulled). **Files:** `edge/zap-baseline.sh`, `baseline/zap-passive-baseline.json`.

The **ZAP baseline scan** (`zap-baseline.py`) is ZAP's *passive* mode — it spiders the target and runs passive rules only (missing security headers, cookie flags, CSP, info disclosure, mixed content), **no active attacks**. That makes it safe against the live deployed host, so it belongs in the edge lane on the weekly cron. It complements the other edge tools: Nuclei matches known-bad templates, wcvs probes cache deception, and ZAP passive crawls the *actual* surface for the header/cookie/leak classes neither of those covers.

> **Terminology — two different "baselines".** A "ZAP baseline *scan*" is a scan **mode** (passive). The `baseline/` folder is the **triaged accepted-findings** store. They are unrelated concepts and this phase touches both — keep them straight. Passive findings triage into `baseline/zap-passive-baseline.json`, kept **separate** from the active lane's `baseline/zap-baseline.json` because the target hosts differ (edge vs localhost) and mixing them muddies triage review.

- [x] **Step 1: `zap-baseline.sh`** — reuse the already-pulled `zaproxy/zap-stable` image (no new tool). **Deviation:** dropped `--network host` — that flag matters for the active lane reaching `127.0.0.1:$PORT` (Phase 4's macOS spike), but the edge lane's target is always a real public HTTPS host, so no host networking is needed; omitting it is also more portable on Docker Desktop for Mac. Verified `zap-baseline.py` exits **2** on WARN-only findings (0 FAIL, 7 WARN) against a real target 2026-07-26 — not literally "1 on WARN / 2 on FAIL" as the plan's comment states — but the design intent (capture, don't let it decide the build) is unaffected since the script treats any nonzero exit the same way (`set +e` around the call, logged, never propagated).

```bash
docker run --rm \
  -v "$ZAP_OUT:/zap/wrk/:rw" \
  zaproxy/zap-stable zap-baseline.py \
  -t "$TARGET" \
  -J zap-baseline.json -r zap-baseline.html \
  -m 5 -T 60                                  # 5-min spider cap, 60s per-rule timeout
# zap-baseline.py's own exit code is informational only — diff-baseline.sh
# (Phase 7) owns gating from the JSON so every scanner fails the same way.
```

Run against `EDGE_TARGET` (the API host); the optional second pass against `EDGE_SITEPAGE_TARGET` for sitepage headers was **not implemented** (plan marks it optional) — sitepage cache/header behavior is governed by the Cloudflare Worker + Cache API stack outside this repo, and `cache-control-correct.yaml` (Phase 5) already covers the API-side header contract. The Phase-5 rate/WAF caution applies — it sits behind Cloudflare, so confirm responses aren't challenge interstitials before trusting a clean result.

- [x] **Step 2: Normalize for the unified gate.** `zap-baseline.sh` copies `$OUTDIR/zap-passive/zap-baseline.json` up to `$OUTDIR/zap-baseline-passive.json` (flat, alongside `nuclei.jsonl`/`wcvs-report.json`) for Phase 7's `diff-baseline.sh` to key by `alertRef + url` — the **same** key scheme as the active ZAP output — subtracting `baseline/zap-passive-baseline.json`. Not using ZAP's own `-c` ignore-file mechanism; one baseline model (the repo's stable-key diff) keeps triage in one place. `baseline/zap-passive-baseline.json` created as `[]` (Step 4).

- [x] **Step 3: Wire into `run.sh --only edge`** after wcvs (already in the Phase-0 dispatch). It runs as the third edge tool, so the weekly edge cron picks it up automatically — this is what delivers the "weekly OWASP ZAP baseline".

- [x] **Step 4: Empty baseline to start** — `baseline/zap-passive-baseline.json` = `[]`; populated only by the Phase-10 first-run triage (never pre-seeded).

**Done when:** `run.sh --only edge --target https://dev-api.partna.au` runs the passive baseline as its third tool, emits `$OUTDIR/zap-passive/zap-baseline.{json,html}`, its alerts key as `alertRef + url` and diff against `baseline/zap-passive-baseline.json`, and a passive alert added to that baseline is suppressed on re-run. ✅ Partially verified 2026-07-26 — `zap-baseline.sh audits/dast/2026-07-26 https://dev-api.partna.au` emits both files, 0 FAIL / 7 WARN found (missing HSTS on /robots.txt, cookie SameSite=None, etc. — real dev-host findings, not scanner bugs), script's own exit stayed 0 despite zap-baseline.py's internal exit 2. The `alertRef + url` keying + suppression-on-baseline is Phase 7's `diff-baseline.sh`, verified there.

---

## Phase 7 — Baseline-after-triage + stable-key diffing + `--fail-on`

**Depends on:** Phases 4–6b (needs real scanner output to key). **Files:** `lib/diff-baseline.sh`, `baseline/zap-baseline.json`, `baseline/zap-passive-baseline.json`, `baseline/nuclei-baseline.txt`, finalize `run.sh` + REPORT.md merge.

- [ ] **Step 1: Define stable keys** (spec: keys, not free text, so the diff doesn't churn):
  - **ZAP (active *and* passive baseline):** `alertRef + url` (the `pluginId`/`alertRef` + the affected URL, param stripped of volatile query values). Active keys carry localhost URLs, passive keys carry the edge host — they never collide, but are stored in separate baseline files (`zap-baseline.json` vs `zap-passive-baseline.json`).
  - **Nuclei/wcvs:** `template-id @ matched-at` (Nuclei JSONL already provides `template-id` + `matched-at`).

- [ ] **Step 2: `diff-baseline.sh`** — normalize each scanner's raw output to `{key, severity}` lines, subtract the committed baseline keys, and emit `new-findings.txt` + a per-severity count. Exit-code contract:

```bash
# returns 0 if no NEW finding at/above --fail-on; 2 otherwise
new_at_or_above="$(comm -23 <(sort scanned.keys) <(sort baseline.keys) \
  | awk -v floor="$FAIL_ON" '<severity>= floor')"
[[ -z "$new_at_or_above" ]] && exit 0 || exit 2
```

- [ ] **Step 3: Empty baselines to start** — `baseline/zap-baseline.json` = `[]`, `baseline/zap-passive-baseline.json` = `[]`, `baseline/nuclei-baseline.txt` = header comment only. They stay empty until the Phase-10 first-run triage populates them. **Never pre-seed** (that buries real bugs).

- [ ] **Step 4: REPORT.md merge in `run.sh`** — write `$OUTDIR/REPORT.md`: a merged human view (Scope, per-lane finding counts, NEW-vs-baselined table) alongside the raw artifacts, matching launch-check's `audits/<suite>/<date>/REPORT.md` convention (note: `audits/launch-check/` doesn't exist yet — this is the first suite to write `audits/dast/<date>/REPORT.md`).

- [ ] **Step 5: `--update-baseline` flag** — appends the current run's keys to the baseline files (the triage-accept action, run by a human after review, never automatically).

**Done when:** two consecutive `run.sh --only edge` runs against the same target produce an identical NEW-findings set (proving key stability, no churn), and a finding added to the baseline is absent from the second run's NEW set.

---

## Phase 8 — Scanner self-tests (canary + clean/baseline suppression)

**Depends on:** Phases 4, 7. **Files:** `tests/canary/`, `tests/dast-selftest.sh`. This is the spec's "Testing the scanner itself" — the worst failure mode is a silently-broken scanner reporting "all clear" forever.

- [ ] **Step 1: Canary fixtures** in `tests/canary/`:
  - **active canary** — a temporary route that reflects unsanitized input (`GET /__dast_canary?x=<echoed raw>`), registered *only* when a `DAST_CANARY=1` env is set, so it never ships to a real env. ZAP must flag reflected XSS on it.
  - **edge canary** — a tiny local static server exposing `/.env` with fake contents, so the `env-not-exposed` template must fire.

- [ ] **Step 2: `dast-selftest.sh` — canary proof:**

```bash
# active canary: bring up with DAST_CANARY=1, run the active lane, assert:
#   (a) ZAP output contains an XSS/reflection alert on /__dast_canary
#   (b) run.sh exit code == 2  (the runner actually FAILS the build)
# edge canary: point nuclei-edge.sh at the local canary server, assert
#   env-not-exposed fired AND exit code == 2.
```

Assert **both** the finding is present **and** the runner exits non-zero — a scanner that finds-but-doesn't-fail is as broken as one that doesn't find.

- [ ] **Step 3: Clean + baseline-suppression proof:**
  - run a lane against a **clean** target → assert exit 0 (green),
  - add the canary's key to the baseline, re-run against the canary → assert the finding is **suppressed** and exit 0.

- [ ] **Step 4:** Document how to run the self-test in `README.md`. This is the guard that keeps the whole tool honest — call it out.

**Done when:** `tests/dast-selftest.sh` passes: canary flagged + non-zero exit for both lanes, clean run green, baselined finding suppressed. This is the acceptance gate for the whole tool.

---

## Phase 9 — Cron wiring for the edge lane

**Depends on:** Phases 5–7 (incl. 6b). **Files:** cron/CI config (edge only; active never in cron — it needs a local stack).

- [ ] **Step 1:** Add a weekly scheduled invocation of `scripts/dast/run.sh --only edge --fail-on high` against `EDGE_TARGET`. The edge lane now runs three non-destructive tools — Nuclei, wcvs, **and the OWASP ZAP passive baseline scan (Phase 6b)** — so this single weekly job *is* the weekly ZAP baseline. It also fills the assurance map's "continuous cadence: weekly CVE/secret scans" gap. Use the same non-CI scheduling mechanism the repo already uses for periodic scans (a scheduled workflow or the ops cron — mirror wherever the weekly off-platform DB backup is scheduled).

- [ ] **Step 2:** On non-zero exit, surface the run (Nightwatch/notification or a failing scheduled job) so a NEW edge finding is seen. Attach `$OUTDIR/REPORT.md`.

- [ ] **Step 3:** Confirm the cron secret store has `EDGE_TARGET`, `EDGE_SITEPAGE_TARGET`, `DAST_EDGE_RATE_LIMIT` (no local-stack secrets needed for edge).

**Done when:** the scheduled edge run executes on cadence, uploads/attaches `REPORT.md`, and a seeded NEW finding causes a visible failure notification. Active lane is explicitly excluded from cron.

---

## Phase 10 — First-run triage → baseline

**Depends on:** Phases 4–8. **Files:** populates `baseline/*`, updates `README.md`.

This is rollout steps 2–3 of the spec: the first real runs, triaged.

- [ ] **Step 1: First active-lane run** against the runner's own isolated bring-up (two identities). Everything surfaces into `REPORT.md`.

- [ ] **Step 2: Triage active findings:**
  - real bugs → hand to the **`execute audit` fix-flow** (`scripts/audit/fix-flow.md`) — write them into a `CONSOLIDATED.md`-shaped file so the plan→implement→independent-review loop works them (P0/auth/IDOR items are blocker-gated there).
  - confirmed false-positives / accepted-risk (incl. anything that's a local-only RLS artifact per the fidelity caveat) → `--update-baseline` into `baseline/zap-baseline.json`.

- [ ] **Step 3: First edge-lane run** against deployed dev (Nuclei + wcvs + ZAP passive baseline); same triage split — Nuclei/wcvs → `baseline/nuclei-baseline.txt`, passive ZAP alerts → `baseline/zap-passive-baseline.json`.

- [ ] **Step 4:** Commit the triaged baselines with a message documenting *why* each accepted entry is accepted (the baseline is a reviewed artifact, not a dumping ground).

**Done when:** both baselines reflect a completed human triage, a re-run of each lane is green (exit 0) against the triaged baseline, real findings are filed into the fix-flow, and `README.md` documents the triage→baseline loop for future runs.

---

## Testing

The suite tests **itself** (Phase 8 is the acceptance gate) rather than relying on a passing app suite — a DAST tool proves nothing by having green unit tests; it proves itself by catching a known-bad:

- **Canary test (both lanes):** plant a deliberately-vulnerable fixture (active: a reflected-input route behind `DAST_CANARY=1`; edge: a local server exposing `/.env`), run the lane, assert **(a)** the vuln is flagged in the scanner output **and (b)** `run.sh` exits non-zero. Either half failing = the tool is broken.
- **Clean + baseline-suppression test:** run against a clean target → assert exit 0; add the finding's stable key to the baseline → assert it's suppressed on the next run and exit 0. This proves the baseline diff both *catches* new and *honours* accepted.
- **Isolation teardown test (Phase 1):** force an abort mid-bring-up, assert no leftover `partna-dast` containers and no orphaned `artisan serve` — the `trap` must fire on every exit path.
- **Key-stability test (Phase 7):** two identical runs → identical NEW-findings set (no churn from volatile URLs/timestamps).

There is no `composer test` integration and no `ci.yml` job — by design (spec: DAST is slow, needs a running target/stack, and is manual/cron-invoked).

## Output convention

Every run writes to `audits/dast/<date>/`:
- `REPORT.md` — merged human view (Scope · per-lane counts · NEW-vs-baselined), **committed**,
- raw artifacts (`zap/*.json`, `zap/*.html`, `nuclei.jsonl`, `wcvs-report.json`, `serve.log`) — **git-ignored**.

This matches launch-check's `audits/<suite>/<date>/REPORT.md` shape. `audits/dast/` is created at first run; note `audits/launch-check/` doesn't exist yet, so DAST is the first suite member to actually write this tree.

## Risks & caveats

- **Local ≠ prod authz fidelity (carried, not solved).** Prod authorization depends on the `app_backend` restricted role (audit schema SELECT/INSERT-only, `NOLOGIN` fail-closed baseline) + Supabase RLS, reached through Supavisor. The local active-lane stack does **not** reproduce that boundary. Consequences: the active lane can **miss** authz bugs that only manifest under the prod role, **and flag** "vulnerabilities" RLS would block in prod (triage these into the baseline with a note). A green active lane means "no injection/authz class found against app logic," **not** "prod RLS proven." **Prod-role authz stays a post-launch human-pentest gap** — state this in `REPORT.md` and `README.md`.
- **Cloudflare WAF throttling (edge lane).** `EDGE_TARGET` also serves live `api.partna.au` and sits behind Cloudflare. An unthrottled Nuclei/wcvs sweep can trip the WAF; challenge pages then read as "clean" → false negatives. Mitigation: `DAST_EDGE_RATE_LIMIT` (default 20 rps), and sanity-check that responses aren't Cloudflare challenge interstitials before trusting a "clean" edge result.
- **Fresh-DB provisioning is documented-flaky.** The Phase-1 retried `supabase start` + `db reset` mitigates but doesn't eliminate it; if bring-up fails 3× the lane `die`s loudly rather than silently skipping (a silent skip would rot the lane — the exact failure mode the isolated bring-up exists to prevent).
- **macOS Docker networking.** `--network host` doesn't behave identically on Docker Desktop for Mac; the ZAP-container-reaching-`127.0.0.1` detail is pinned in the Phase-4 spike (likely `host.docker.internal`).
- **Active lane mutates a real (local) DB and runs jobs inline** (`QUEUE_CONNECTION=sync`). The external-side-effect exclusions (Phase 4) are load-bearing: without them, an inline job could hit a vendor API or write Cloudflare KV from a "local" scan.

## Rollout

1. Build `scripts/dast/` with both lanes behind `run.sh --only` (Phases 0–8).
2. First active-lane run against the runner's own isolated bring-up (two seeded identities); triage → baseline (Phase 10 steps 1–2).
3. First edge-lane run against deployed dev (Nuclei + wcvs); triage → baseline (Phase 10 step 3).
4. Add the edge lane (Nuclei + wcvs + OWASP ZAP passive baseline) to the weekly cron alongside the other continuous-cadence scans (Phase 9).
5. At pilot cutover, re-point `EDGE_TARGET` (and `EDGE_SITEPAGE_TARGET`) at the real prod host — one env change, no runner edit.

---

## Self-review checklist (walk before opening the PR)

1. **Spec coverage:** isolated bring-up ✓, two identities + cross-identity IDOR ✓, wcvs in v1 ✓, weekly OWASP ZAP passive baseline in the edge cron (Phase 6b) ✓, exclude-only-external-side-effects inversion ✓, fidelity caveat carried ✓, stable-key baseline + `--fail-on` ✓, canary + clean/baseline self-tests ✓, edge weekly cron ✓, neither lane in CI ✓.
2. **Open questions resolved, not deferred:** ZAP seed (OpenAPI default, spike) ✓, direct-sign JWT ✓, tag-pinned Nuclei ✓, assert templates live in Nuclei with launch-check to call the lane ✓.
3. **No scope creep:** zero `config/`, app-code, or migration changes; no `ci.yml` job; no cloud throwaway env.
4. **Teardown is guaranteed:** `trap` set before `supabase start`; every exit path tears down (proven by the Phase-1 abort test).
5. **Baselines start empty** and are populated only by reviewed triage (`--update-baseline`), never pre-seeded.
6. **Prereqs flagged:** Nuclei + wcvs not installed, ZAP has no host binary — all three run from Docker (`docker pull` in Phase 0).
