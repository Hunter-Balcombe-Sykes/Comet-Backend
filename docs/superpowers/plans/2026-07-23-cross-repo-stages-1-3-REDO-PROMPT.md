# Cross-repo contract & dead-code — Stages 1–3 REDO (against the CORRECT frontend)

Redoes **only Stages 1–3** (backend↔frontend contract drift, known-dead subsystems,
frontend dead-code sweep) of the 2026-07-20 campaign. Stage 4 (backend dead-code)
already ran correctly and is **not** part of this redo.

## Why this exists

The original Stages 1–3 ran against the **wrong frontend**: the local working tree at
`../partna-frontend` is the `hunterbalcombesykes/partna-frontend` fork sitting on branch
`test/embedded-visible-change` @ `816063b2` (HEAD **2026-05-13**, ~2 months stale, still
full of the removed commerce/stripe/shopify subsystems). Every finding from that run was
invalid and has been deleted.

**Correct frontend:** `PartnaAu/partna-frontend` @ **`main`** — actively developed
(a commit landed 2026-07-23). Confirm with `gh repo view PartnaAu/partna-frontend`.

---

## Preconditions — do these before Stage 1

**1. Materialize the correct frontend locally (HUMAN step — this session must NOT
clone/pull/checkout the frontend).** Get a **clean checkout of `PartnaAu/partna-frontend`
@ `main`** at a **NEW path** — do NOT reuse the stale `../partna-frontend` working tree.
Suggested:
```bash
git clone --branch main --depth 1 https://github.com/PartnaAu/partna-frontend.git \
  "/Users/joshuahunter/Herd/Side Street/partna-frontend-main"
export PARTNA_FRONTEND_PATH="/Users/joshuahunter/Herd/Side Street/partna-frontend-main"
```
`contract-inventory.sh` will **refuse** unless the checkout is on `main` with a clean tree
(that guard is the whole point — no `--allow-dirty` this time). If you can't get a clean
tree because sessions are mid-edit, park a clean `main` first.

> Read-only alternative for spot-checks: `gh api "repos/PartnaAu/partna-frontend/contents/<path>?ref=main"`
> reads any file at current `main` without a local clone. The audit *scanners* need local
> files, so a local checkout is still required for the runs — but the adjudicator can use
> `gh api` to double-check the live `main` state.

**2. Stage 0 pipeline is ALREADY BUILT — do NOT rebuild it.** These exist and are tested
(`AuditPipelineIntegrityTest` green): `scripts/audit/contract-inventory.sh`, lenses
`frontend-backend-contract.md` (XREPO) + `cross-repo-dead-code.md` (XDEAD), and the
`cross-repo` bundle in `audit.sh`. The `.tsx/.jsx/.mjs` glob fix is in `audit-scan.sh`.

**3. One `audit.sh` at a time.** They share the local `claude` CLI budget.

---

## Operational rules — apply to EVERY run (these are the fixes learned the hard way)

- **Space-safe scoping.** The repo path contains a space ("Side Street"). NEVER build
  `--scope` args with `$(printf -- "--scope %s" $F/lib/*.ts)` — word-splitting mangles the
  path. Use a bash **array**:
  ```bash
  scopes=(); for f in "$F"/lib/*.ts "$F"/lib/*.tsx; do [ -e "$f" ] && scopes+=(--scope "$f"); done
  scripts/audit/audit.sh ... "${scopes[@]}"
  ```
- **Verify every scope exists first.** `main`'s layout differs from the stale fork — the
  dead subsystems may already be gone. `ls -d "$F/<dir>"` before scoping; drop missing paths.
- **Size-split anything >~280KB** into balanced halves <~180KB (measure with the byte glob
  below). The scan-recall ceiling is 350KB; over it, recall degrades. Re-measure on `main`
  — sizes will differ from the fork.
- **Auto-retry on kill.** Adjudications get intermittently killed by transient host memory
  pressure (phase- and size-independent). A `killed` status ≠ a failure — with `--keep-drafts`
  the drafts survive; just re-run the same command. Escalate only if one run is killed 3×.
- **Read each run's tiers from its own dated folder** (`audits/sweeps/<date>-<name>/CONSOLIDATED.md`
  for bundle runs). Folders may span two dates if a run crosses midnight.
- Always pass **`--keep-drafts`** (cheap insurance across a long chain).

Byte-measuring helper (frontend file types):
```bash
sz() { find "$1" -type f \( -name '*.ts' -o -name '*.tsx' -o -name '*.js' -o -name '*.jsx' \
  -o -name '*.mjs' \) ! -path '*/node_modules/*' ! -path '*/.next/*' ! -path '*/build/*' \
  ! -path '*/out/*' ! -path '*/.turbo/*' -exec cat {} + 2>/dev/null | wc -c; }
```

---

## Stage 0.5 — discover the real `main` layout (no audit runs)

Before scoping anything, map what actually exists on `main` and how big it is:
```bash
F="$PARTNA_FRONTEND_PATH"
ls -d "$F"/app "$F"/components "$F"/features "$F"/lib "$F"/proxy.ts "$F"/knip.json 2>&1
for d in "$F"/features/* "$F"/components/* "$F"/lib "$F"/app/api; do
  [ -d "$d" ] && printf "%-50s %6d KB\n" "${d#$F/}" $(( $(sz "$d")/1024 )); done | sort -k2 -n
```
Use this to (a) confirm which known-dead subsystems still ship (Stage 2) and (b) build
size-balanced scope groups for Stage 3.

---

## Stage 1 — contract drift (inventory + 2 runs) · highest value

```bash
F="$PARTNA_FRONTEND_PATH"
scripts/audit/contract-inventory.sh                 # NO --allow-dirty; must be clean main
# report the four bucket counts: MATCHED / FRONTEND-ONLY / UNRESOLVED / (BACKEND-ONLY=0)

A=scripts/audit/audit.sh
$A --category cross-repo --name contract-drift-v2 --bundle cross-repo \
  --scope audits/cross-repo/CONTRACT-INVENTORY.md \
  --scope routes --scope app/Http/Resources \
  --scope "$F/app/api" --scope "$F/proxy.ts" --keep-drafts

$A --category cross-repo --name capability-wiring-v2 --bundle cross-repo \
  --scope audits/cross-repo/CONTRACT-INVENTORY.md \
  --scope app/Services/Accounts --scope config/partna.php \
  --scope "$F/lib/account-capabilities.ts" --scope "$F/lib/account" \
  --scope "$F/features/integrations" --keep-drafts
```
Category-2 findings (frontend calls a backend route that doesn't exist) are **live 404s (P1)**.
Because `main` is current, the FRONTEND-ONLY bucket should be far smaller than the fork's 20 —
anything left is a *real* current-main drift, not a relic. Every `UNRESOLVED`/`FRONTEND-ONLY`
row stays unproven until confirmed by reading source in BOTH repos.

---

## Stage 2 — known-dead subsystems (adaptive — may be mostly EMPTY on `main`)

The fork still had `features/commerce`, `lib/shopify`, `lib/square`, `features/booking`,
`features/affiliates`, etc. On current `main` these may already be removed — **verify each
with `ls` (Stage 0.5) and scope only what exists.** A near-empty Stage 2 is the *correct,
healthy* result if the frontend already dropped them. For whatever still ships:
```bash
# Example — include ONLY the dirs that exist:
$A --category cross-repo --name dead-subsystems-v2 --bundle cross-repo \
  $( for p in features/commerce features/billing lib/square lib/shopify lib/stripe-connect.ts \
              features/booking features/affiliates "app/api/public/booking" "app/api/public/waitlist" ; do
       [ -e "$F/$p" ] && printf ' --scope %q' "$F/$p"; done ) \
  --scope "$F/proxy.ts" --keep-drafts
```
(Build the scope list as an array if any path has a space; `%q` above is a safeguard.)

---

## Stage 3 — frontend dead-code sweep (scope the REAL dirs, split by size)

Sweep everything Stage 2 didn't cover, using `--bundle cross-repo` (runs XREPO + XDEAD).
Do NOT reuse the fork's scope groups blindly — rebuild them from Stage 0.5's real dirs, each
group kept **<~280KB** (split larger ones). The fork needed these groups; `main` will differ:
`components/*`, `app/(app)/account/(dashboard)/*`, `app/(app)/account/(auth)`, `app/(marketing)`,
`app/api`, `packages`, `lib/*` (top-level `.ts`/`.tsx` via a bash array), `lib/<engines>`,
`features/*`. Split any dashboard/components/lib group that measures over the ceiling.

For the top-level `lib` glob group, use the array form (spaces!):
```bash
scopes=(); for f in "$F"/lib/*.ts "$F"/lib/*.tsx; do [ -e "$f" ] && scopes+=(--scope "$f"); done
scopes+=(--scope "$F/lib/account")
$A --category cross-repo --name fe-lib-core-v2 --bundle cross-repo "${scopes[@]}" --keep-drafts
```

`knip` cross-check: `main` has `knip.json` at its root — the XDEAD adjudicator should run it
and report agree/disagree per unused-export finding.

---

## Deliverables — TWO ownership-split consolidated files

After Stages 1–3 finish, consolidate ALL their `CONSOLIDATED.md` files into two action lists,
classified by **which team fixes each finding**, deduped, ranked by severity. Write to
`audits/cross-repo/CONSOLIDATED-cross-repo-drift/`:

- **`FRONTEND-DEV.md`** — frontend team acts: a frontend call to a backend endpoint that
  doesn't exist / was removed (live 404); vestigial BFF routes / `proxy.ts` reserved paths
  for deleted subsystems; frontend dead code / dead subsystems still shipping; a component
  reading a stale contract shape (`themeMode`/`accent`/`fontFamily`, or only `skeletonId`
  with no `architectureId`).
- **`BACKEND-DEV.md`** — backend team acts: a backend route with no consumer; backend code
  reachable only via an orphaned route; a capability / `integration.*` / `feature.*` rule the
  backend must expose/gate/remove; a Resource that must emit a field it currently doesn't.

Rules: use each finding's own "What to do / which repo owns it" as the primary signal; a
dual-owned finding goes under its PRIMARY actor with a one-line `> ⚠ also needs <other-team>`
note (never fully duplicated); merge cross-run duplicates into one entry listing all source
runs; preserve finding IDs, tiers, and verbatim Evidence; each file opens with a
per-team tier table and the `⚠ frontend @ main <sha>` provenance stamp. A subagent is well
suited to this synthesis pass.

---

## Notes

- Fold nothing into `campaigns.md` until this clean-`main` run passes once.
- If `contract-inventory.sh` stamps the output STALE, the checkout wasn't clean `main` — stop
  and fix the checkout; do not trust STALE cross-repo findings (that is the mistake this redo
  corrects).
