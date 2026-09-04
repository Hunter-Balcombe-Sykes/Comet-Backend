# GitHub org transfer — `Hunter-Balcombe-Sykes` → `PartnaAu`

**Purpose:** move `partna-backend` and `partna-db-backup` into `PartnaAu`, where every other
Partna repo already lives, **without changing repo visibility**. Written 2026-09-04, before the run.

Audience: whoever is doing the move, plus the Claude session assisting. Steps are labelled
**[you]** (browser / OAuth work no agent can do) or **[claude]** (API or repo work).

---

## The mechanics you are operating

| Fact | Consequence |
|---|---|
| `partna-backend` is **public**; every repo in `PartnaAu` is private. `PartnaAu` is on the **free** plan. | **Keep it public.** Public repos get unlimited Actions minutes and branch protection for free. Going private on a free org meters Actions at 2,000 min/month — against a ~20-minute `test` job across nine jobs — and, because protected branches are a public-repo-only feature on Free, would **silently drop the nine required status checks**. Private costs a Team upgrade, not a toggle. |
| GitHub keeps **permanent redirects** for the git protocol after a transfer. | `clone`/`fetch`/`push` against the old URL keep working indefinitely. Local remotes and all three `.worktrees/` (which share one `.git/config`) are not a blocker, just tidiness. |
| The repo has **no webhooks and no deploy keys** (verified 2026-09-04). Every integration is a **GitHub App**. | Apps do **not** follow a transfer. Laravel Cloud loses its connection the moment the repo moves, and only a human can reauthorise it. |
| Prod has `usesPushToDeploy: true` — the push IS the deploy. | Between the transfer and the Laravel Cloud reconnect, **deploys are dead**. Serving is unaffected: `api.partna.au` keeps answering. This is a deploy outage, not a site outage. |
| A transfer can be transferred back. | The whole operation is reversible. The only genuinely one-way step is reauthorising the Apps, which is tedious rather than risky. |
| `scripts/db/backup-to-r2.sh:132-133` hard-codes `Hunter-Balcombe-Sykes/partna-db-backup`. | Those two lines are correct **only while that repo sits in the old org**. Moving it makes them wrong. They are not the only ones: `docs/runbooks/RUN-PROMPTS.md`, `docs/runbooks/drills/04-backup-restore.md`, `docs/checklists/launch-readiness-checklist.md` and `docs/deploy/routine-deploy.md` each name the old org too, two of them in runnable `gh workflow run` commands. Grep for the org name, not just the script. (Historical PR links under `.superpowers/sdd/` are left alone — they are an accurate record, and GitHub redirects them.) |
| `partna-db-backup` carries **11** repo Actions secrets, including `BACKUP_PASSPHRASE` and `SUPABASE_DB_URL`. | Secrets are **write-only** — they cannot be read back out of GitHub. If a transfer drops them they must be re-entered from the password manager. **`BACKUP_PASSPHRASE` is the one that matters**: every `.enc` object in R2 was encrypted with it (`openssl enc -aes-256-cbc -pbkdf2`, `backup-to-r2.sh:111`), so losing it makes every existing encrypted backup permanently undecryptable. `backup-to-r2.sh:108` states it lives in the password manager — **confirm that before B3, not after**. |

### The invariant

**Visibility must not change.** Verify after the transfer, not just before:

```bash
gh api repos/PartnaAu/partna-backend --jq '.visibility'   # must print: public
```

---

## Phase A — baseline before touching the org

Establishes that CI is green *on the old org*, so any red afterwards is attributable to the move.

| # | Who | Step |
|---|---|---|
| A1 | **[claude]** | Branch off `development`; commit the gitleaks two-pass change + this runbook |
| A2 | **[claude]** | Open PR → `development` |
| A3 | **[claude]** | Confirm all **9** required checks green, `supply-chain` included (it now runs two gitleaks passes) |
| A4 | **[you]** | Merge |

## Phase B — the transfer

Do this when you are at a keyboard and free to run Phase C immediately after. Nothing should be mid-deploy.

| # | Who | Step |
|---|---|---|
| B1 | **[you]** | Confirm no deploy in flight (`cloud deployment:list development`) |
| B2 | **[claude]** | Transfer `partna-backend` → `PartnaAu`, **public** |
| B3 | **[claude]** | Transfer `partna-db-backup` → `PartnaAu` (stays private) |
| B4 | **[claude]** | Verify landing on both: visibility, the 9 required checks on `development` and `production`, secret scanning + push protection + Dependabot still enabled, `copilot` environment present |

**Deploys are down from B2 until C2.**

## Phase C — reconnect the deploy path *(browser only — no agent can do this)*

| # | Who | Step |
|---|---|---|
| C1 | **[you]** | Laravel Cloud → install / authorise its GitHub App on `PartnaAu`, granting `partna-backend` |
| C2 | **[you]** | Re-point **both** envs at `PartnaAu/partna-backend`; confirm prod still has `usesPushToDeploy: true` and `branch: production` |
| C3 | **[you]** | Reinstall any other App you want on the new org (Copilot, Claude Code) |
| C4 | **[you]** | Verify the **11** Actions secrets on `partna-db-backup` survived (list below). They are write-only — if any dropped, they must be re-entered from the password manager, not recovered from GitHub |
| C5 | **[claude]** | Smoke test: trigger a `development` deploy, then `cloud deployment:list development` — look for a `*.succeeded` status, not a bare `running` |

### `partna-db-backup` secrets to verify after transfer

```
BACKUP_PASSPHRASE              R2_MEDIA_DST_ACCESS_KEY_ID     R2_SRC_ENDPOINT
R2_ACCESS_KEY_ID               R2_MEDIA_DST_SECRET_ACCESS_KEY R2_SRC_SECRET_ACCESS_KEY
R2_ENDPOINT                    R2_SRC_ACCESS_KEY_ID           SUPABASE_DB_URL
R2_SECRET_ACCESS_KEY           R2_SRC_BUCKET
```

Check with `gh api repos/PartnaAu/partna-db-backup/actions/secrets --jq '.secrets[].name'` — names are readable, values are not.

## Phase D — cleanup

| # | Who | Step |
|---|---|---|
| D1 | **[claude]** | `git remote set-url origin` → new URL (covers all three worktrees; they share one config) |
| D2 | **[claude]** | Fix `scripts/db/backup-to-r2.sh:132-133` → `PartnaAu/partna-db-backup` |
| D3 | **[claude]** | Grep for surviving `Hunter-Balcombe-Sykes` references |
| D4 | **[claude]** | Update `CLAUDE.md` and the affected memory files |
| D5 | **[you]** | Optionally archive or delete the now-empty `Hunter-Balcombe-Sykes` org |

---

## What to check if something looks wrong

| Symptom | Cause | Fix |
|---|---|---|
| Push to `development` rejected with "required status checks" | Protection survived but the checks have not re-run | Normal. Wait for CI. |
| PR mergeable with **no** checks required | Branch protection did **not** survive the transfer | Re-apply from `gh api repos/PartnaAu/partna-backend/branches/development/protection`; the nine contexts are listed in `routine-deploy.md`. |
| Push to `production` succeeds but nothing deploys | Laravel Cloud not reconnected (C1/C2 incomplete) | Finish Phase C. The commit is on the branch; re-push or redeploy once connected. |
| `backup-to-r2.sh --delete` or the restore drill 404s | D2 not done | Fix the two hard-coded refs. |
| Repo shows as private | The transfer changed visibility | Flip it back to public immediately — see "The invariant" above for what private costs. |
