# launch-check — runtime & config assurance suite

Counterpart to `scripts/audit/` (static code): verifies the **running system**.

| Group | What | How to run alone |
|---|---|---|
| A | Schema drift: SQLite test schema vs live dev Postgres snapshot | `php artisan test --filter=SchemaDriftGuardTest` (runs in CI via composer test) |
| B | Runtime smoke: .env exposure, debug leakage, telescope/horizon gates, 404-not-403, throttle | `scripts/launch-check/smoke.sh [--rate-limit]` |
| C | Supabase: RLS on, security advisors, snapshot staleness | `scripts/launch-check/supabase-check.sh` |
| D | Supply chain: composer audit + worker npm audit (+ gitleaks in CI) | via runner or CI |
| E | Security audit (Vigil): filesystem/secrets/dependency checks, same gate as CI (`--fail-on=critical`) | `APP_DEBUG=false php artisan vigil:audit --fail-on=critical` |
| F | Drill-log freshness: each failure-mode drill has a log postdating changes to its drilled path (read-only — never runs a drill; see `docs/runbooks/drills/`) | `scripts/launch-check/drill-freshness.sh` |
| G | Deployed env config: required vars present + APP_DEBUG/APP_ENV/queue/cache correct on the running Cloud env (via `cloud command:run`) | `scripts/launch-check/env-check.sh` |
| H | Deployed runtime health: sitepage served via edge (200 + text/html + `cf-cache-status` + the handle present in the body — all FAIL), plus Horizon masters up and Redis/media-disk/scheduler alive. Warn-only: cf-cache `HIT` and TLS-expiry headroom. Alias-301 runs **only** when `--alias` is given | `scripts/launch-check/runtime-health.sh` |
| I | Manual residue | `MANUAL-CHECKLIST.md` |

Full run: `scripts/launch-check/launch-check.sh` → `audits/launch-check/<date>/REPORT.md`

**Group G (`env`) and group H (`runtime`) are opt-in only** — both need the `cloud` CLI (plus
`jq` and `awk`, hard requirements of the shared payload parser: absent either, the probe fails
closed rather than skipping) and a reachable, deployed Laravel Cloud env, none of which a plain
local run can assume. Neither is in the default group set; run them explicitly with
`scripts/launch-check/launch-check.sh --only env,runtime` (or `scripts/launch-check/env-check.sh`
/ `scripts/launch-check/runtime-health.sh` directly). Both default to
`--env development --target pilot`; `--env production` requires `LAUNCH_CHECK_CONFIRM_PROD=1`
and is otherwise refused (one shared gate, `launch_check_prod_gate`).

**Nothing checked is never a pass.** Every "could not run" path in groups G and H — absent
`cloud` CLI, absent `jq`/`awk`, unparseable payload, and (group H) an unset handle — exits
non-zero. A WARN cannot reach the runner's exit code, so a WARN on a check that did not run is
indistinguishable from a pass; group H once WARNed on both a missing CLI and a missing handle
and a run that probed **nothing** still printed `LAUNCH-CHECK: all automated groups passed`.
`env-check.sh` and `runtime-health.sh` now share one verdict block and one prod gate
(`launch_check_verdict` / `launch_check_prod_gate` in `lib/cloud-json-parse.sh`) precisely so
the two cannot drift apart again.

**Group H (`runtime`) setup** — set `LAUNCH_CHECK_HANDLE` to a canary sitepage handle
(a currently published `<handle>.partna.au`) so the edge-probe half of group H has a
target to hit. **Without it group H FAILs**, because an edge probe that ran no request must
never infer "edge works" from "nothing was checked." The edge probe also asserts the handle
appears in the returned body: 200 + `text/html` + a `cf-cache-status` header are all satisfied
by a Worker catch-all serving some *other* page, so headers alone do not establish *whose*
sitepage was served. The runtime-liveness half (Horizon/Redis/media-disk/scheduler, run via
`cloud command:run`) is unaffected by `LAUNCH_CHECK_HANDLE` and runs whenever the `cloud` CLI
is available — `runtime-health.sh` passes the handle to `edge-check.sh` via the env var it
already reads, never via an argv array, specifically so an unset handle can never abort the
script under bash 3.2's `set -u` (an empty `"${arr[@]}"` expansion is an unbound-variable error
there). Alias-301 is **not** part of a default group-H run; it fires only via
`runtime-health.sh --alias <name> [--domain partna.au]`, which forwards both straight to
`edge-check.sh`.

**Group H severity** — `launch-check:runtime`'s pilot/launch downgrade is scoped to exactly
two checks that legitimately deviate on dev: `horizon-masters` and `queue-backlog` (dev runs
`QUEUE_CONNECTION=sync` with 0 Horizon workers by design). Every other check — `failed-jobs`,
`redis`, `storage`, `scheduler` — FAILs on an unhealthy result AND on a thrown/unverifiable
probe (e.g. an unreachable DB) **at both `pilot` and `launch`**; a probe throwing is "could
not check", never "within threshold", so it is never silently downgraded to WARN. Default
target through the runner is `runtime-health.sh`'s own default (`pilot`); pass
`launch-check.sh --only runtime --runtime-target launch` for the stricter pre-launch gate.

**`env-check.sh` / `runtime-health.sh` internals — never reintroduce a line-scanning
parser.** Both scripts shell out through `cloud command:run --json` and both source the
SAME parser, `scripts/launch-check/lib/cloud-json-parse.sh` — do not fork a second copy.
`cloud command:run --json` has a confirmed, live, INTERMITTENT bug: raw `0x0A` bytes are emitted
inside the `output` string value instead of the `\n` escape JSON requires, which breaks
strict JSON parsing on almost any multi-line remote output (i.e. most real output,
including `launch-check:env`'s own). The observed real shape is `{"output":"…","exitCode":N}`
— output FIRST, exitCode LAST — optionally preceded by progress objects.

The script handles this **structurally**, in three steps:

1. `PARSE_MODE=json` — strict `jq` parse, used whenever the payload is already well-formed.
2. `PARSE_MODE=repaired` — an `awk` state machine walks the payload tracking whether it is
   inside a JSON string and escapes the raw control bytes that occur inside string values
   (`0x0A` → `\n`, everything else `0x01`–`0x1F` → `\uXXXX`). Backslash escapes are passed
   through untouched, which is what keeps the string tracking honest. The repaired text is
   then parsed with `jq`.
3. If the repaired text still does not parse, the script **fails closed** — "could not be
   repaired", exit 1. There is no third fallback and there must never be one.

`exitCode` and `output` are then read as **structured fields** of the last top-level object
that actually **carries an `exitCode` key** — that is the result record, and picking it by key
rather than by position keeps a trailing progress object from displacing it. Only if no object
in the stream has one does it fall back to the last object of any shape, so a well-formed
`{"error":true,…}` response is still reported rather than swallowed. Each field is type-checked
(a non-numeric `exitCode` or non-string `output` fails closed). PASS requires BOTH a genuine
numeric `exitCode` of 0 AND the PASS sentinel as an exact whole line; a FAIL sentinel always
wins; absence of a FAIL marker is never treated as success. That verdict is implemented once,
in `launch_check_verdict`, and shared by both probes.

Why this shape and not text scanning: **two earlier versions shipped a FALSE PASS.** Round 1
grepped `"exitCode":` and the sentinels over the whole blob, so an embedded `"exitCode":0`
plus PASS text in the remote command's own output reported `passed` on a genuine remote
exit 1. Round 2 anchored the exit code to the payload tail (correct) but picked the record
start by "last physical line beginning with `{"output":"`", so a DECOY line inside the
output content faked a second record and passed again. Content that mimics structure beats
every textual landmark you can pick; once a real parser reads the structure, bytes *inside*
a string value can never become a sibling record or field.

Regression coverage lives in `scripts/launch-check/env-check-parser.test.sh` (plain
executable bash — no `bats` dependency; run it directly). It includes both shipped
false-PASS PoCs verbatim and decoy record boundaries at the start, middle and end of the
output content, plus multiple decoys in one payload. Extend it; never weaken an assertion.
**It runs in CI** (the `test` job's "Launch-check parser regression suite" step in
`.github/workflows/ci.yml`) — an unrun regression test is how the group-H WARN-instead-of-FAIL
drift above went unnoticed in the first place.

**After any schema change:** `supabase db push`, then `php scripts/launch-check/refresh-schema-snapshot.php`, commit the snapshot. If the drift gate then fails, mirror the constraint in `tests/Pest.php` (preferred) or regenerate the baseline (`SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest`).

Setup: `cp .env.example .env` in this dir, add a Supabase PAT.
