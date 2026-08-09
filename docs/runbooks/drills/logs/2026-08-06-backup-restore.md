# Drill log — 04 Backup / restore (recon + Phase 3 rehearsal)

- **Date:** 2026-08-06 (AEST)
- **Runbook:** [../04-backup-restore.md](../04-backup-restore.md)
- **Operator:** Claude (Opus 5), driven by Josh
- **Scope this run:** **Phase 1 recon + Phase 3 rehearsal only.** The restore *mechanism* was not
  re-derived — see "Why the restore was not re-run" below.
- **Source of truth:** prod `edplucmvkcnokyygxqsb`, read-only. Nothing was restored into any live
  project; no scratch project was created. **Cost: $0.**

## Why the restore was not re-run — stated plainly, not silently skipped

`restore-drill.yml` last ran **2026-08-04 16:59 UTC** (success, 44 s) and the
[2026-08-05 log](2026-08-05-backup-restore.md) recorded **PASS** on the mechanism: object fetched,
decrypted, restored, per-schema table-count floors met, row counts fingerprinting prod.

Nothing since then can have changed that outcome. This drill tests the *platform*, not our code, and
the 2026-08-05 auth/resilience merge touches neither the dump, the encryption, the R2 object, nor
`pg_restore`. Re-running it would have re-derived a two-day-old PASS.

What the 2026-08-05 run left genuinely open was **Phase 3** — recorded as **PARTIAL**, "the step most
likely to be improvised at 3 a.m." That is what this run closes, and it is where the finding is.

## Phase 1 — Reconnaissance

| Item | Value | Source |
|---|---|---|
| Org plan | **Free** | Management API `GET /v1/organizations` |
| PITR | **`pitr_enabled: false`** | `GET /projects/{ref}/database/backups` |
| Managed backups | **`backups: []`** — none exist | same |
| Prod project | `Partna Production`, `ap-southeast-2`, `ACTIVE_HEALTHY` | `GET /projects/{ref}` |
| Newest weekly dump | **2026-08-02 15:51:11 UTC** (success, 62 s) | `gh run list --workflow weekly-db-backup` |
| **Measured RPO** | **≈ 3 d 8 h** at time of drill | now − newest object |
| **Worst-case RPO** | **≈ 7 days** | weekly cadence is the only backup that exists |

Free plan ⇒ no PITR and no managed daily backups: **expected, not a finding**. The weekly R2 dump
remains the entire backup story.

The measured 3 d 8 h is again an artefact of *when* the drill ran (compare 1 d 23 h on 2026-08-04).
The number that matters is still the worst case, ~7 days, and it remains an unrecorded policy
decision — see finding 3.

## Phase 3 — the gap between "restored" and "working" — REHEARSED

The 2026-08-05 run recorded this as PARTIAL because `restore-drill.yml` proves the data comes back
but not that the app can connect to it. Rehearsed here by hand.

**Target:** the local Supabase stack's Postgres, used as a stand-in for a freshly restored project.
It is a genuine Supabase-flavoured cluster with the same role set, and — usefully — it reproduces
the fail-closed `app_backend` state a fresh restore leaves behind. No scratch project was created,
so no cost was incurred.

### Role state, before anything

| Role | Local (stand-in target) | Prod |
|---|---|---|
| `app_backend` | exists, **`rolcanlogin = false`** | exists, **`rolcanlogin = true`** |
| `anon` / `authenticated` / `service_role` | exist, NOLOGIN | — |
| `authenticator`, `supabase_admin` | exist, LOGIN | — |
| `pg_trgm` extension | present | — |

Prod being `LOGIN` confirms the 2026-07 credential gap stayed closed. The local target being
`NOLOGIN` is exactly the post-restore state the runbook warns about.

### The rehearsal — the actual 3 a.m. sequence

```
1. connect as app_backend
   → FATAL:  role "app_backend" is not permitted to log in

2. ALTER ROLE app_backend WITH LOGIN PASSWORD '<secret>';
   → ALTER ROLE

3. connect as app_backend
   → app_backend | postgres          ✅

4. select count(*) from core.users;
   → 7                               ✅ grants survived; it can read the business schemas

5. ALTER ROLE app_backend WITH NOLOGIN;   -- restore fail-closed
   → app_backend | f                 ✅
```

Every step worked from the runbook's text alone — **except the one that matters most.** See
finding 1.

### Migration state

| Source | Head |
|---|---|
| prod `edplucmvkcnokyygxqsb` | `20260803100001` |
| local stand-in target | `20260803100001` |
| repo `development` @ `de8fcff7` | `20260803100001` |

**All three match** — the Phase 3 check passes.

⚠️ Worth recording separately: `origin/development` (`ae63fb857`) carries **four migrations beyond
this head** — `20260805090000_content_item_links`, `20260805100000_auto_sync_toggles_unify`,
`20260805110000_drop_field_bindings`, `20260805130000_drop_user_confirmation_preferences` — none of
which are applied to prod. That is a deploy-state fact, not a restore fault, but it means "migration
state matches" is only true against `development`, not against the tip of the branch.

### Connection-string shape

Not fully testable here: the local stack's Supavisor pooler container is **stopped**, so the
`DB_USERNAME=app_backend.<ref>` tenant-prefix form could not be exercised. A direct `psql` login as
`app_backend` was verified instead (step 3 above). **The tenant-prefix form remains unrehearsed** and
should be checked against a real scratch project the next time one exists.

## Verdict

| Criterion (from runbook) | Result | Notes |
|---|---|---|
| Restore completed by runbook alone, no improvisation | **PASS (carried, not re-derived)** | 2026-08-04 workflow run, success. Explicitly not re-run — see rationale above. |
| RTO and RPO measured and written down | **PASS** | RPO re-measured at ≈3 d 8 h; worst case ≈7 d. RTO carried from 2026-08-05 (~4 s of restore work on a 460 KiB dump). |
| Integrity checks clean | **PASS (carried)** | Per-schema floors + row-count fingerprint, 2026-08-04. |
| **Role/connection gap documented with the exact commands that closed it** | **PASS — was PARTIAL** | Rehearsed end to end above, with the exact failure message, the fix, a verified read, and the revert. |

**Overall: PASS on the mechanism (carried), and the Phase 3 gap is now CLOSED** — with one new
finding that only doing it by hand could have surfaced.

## Findings

1. **🔴 P2 (new) — the `app_backend` password's storage location is documented nowhere, so Phase 3
   cannot actually be executed from the docs.** Every reference across the deploy docs is a
   placeholder: `'<secret>'` (`PROMPT-execute-prod-seed-bootstrap.md:44`), `'<from-secret>'`
   (`production-cutover.md:233`, `PROMPT-execute-cutover-phase-1-prod-db.md:152`), `'<prod-secret>'`
   (`prod-cutover-change-checklist.md:20`). `PROMPT-execute-cutover-phase-2-prod-env.md:100` states
   outright "you cannot know" it, and `docs/runbooks/secret-rotation.md:119-128` explicitly puts
   `DB_PASSWORD` **out of scope**: "this doc doesn't have a verified-safe procedure for [it]".
   **Failure scenario:** prod is lost; the operator restores the dump (~4 s, proven), reaches
   `ALTER ROLE app_backend WITH LOGIN PASSWORD '<secret>'`, and there is no documented place to get
   that secret. Every path forward is improvisation, and the runbook explicitly says any step
   requiring improvisation is a finding. In practice Josh holds it personally
   (`PROMPT-execute-cutover-phase-1-prod-db.md:21` — "Josh has the prod DB password ready"), so this
   is a **bus-factor and lookup-time problem, not a lost secret** — but that lookup is part of RTO
   and it is currently unbounded and single-person. **Filed, not fixed** — writing down *where* a
   production credential lives is Josh's call, not a drill-time edit.

2. **🟡 P2 (sharpened) — media/object storage has NO backup at all.** The 2026-08-05 run recorded
   "no evidence the mirror ran". Stronger now: it cannot have run. `docs/runbooks/media-backup-setup.md`
   opens with **"Status: NOT YET IMPLEMENTED"**; the workflow it describes
   (`weekly-media-backup.yml`) has **never been committed** — `Hunter-Balcombe-Sykes/partna-db-backup`
   contains only `weekly-db-backup` and `restore-drill` — and Step 1's dashboard prerequisites
   (bucket, scoped token, secrets, lifecycle rule) are all still unchecked. **Failure scenario:**
   the prod R2 bucket or account is lost and **every sitepage image and video is gone permanently**,
   with the DB restore cheerfully bringing back rows that point at objects which no longer exist.
   A DB backup does not include object storage. Runbook Phase 1 asks exactly this question and the
   answer is "it would not survive". **Filed** — the setup is dashboard-only work.

   **Addendum, post-drill 2026-08-06.** The last open question blocking that setup — which account
   owns the prod bucket — is now answered, and the answer changed the plan. Both envs resolve
   `AWS_BUCKET=fls-a1334790-…` at endpoint `…367be3a2035528943240074d0096e0cd.r2.cloudflarestorage.com`,
   which is **Laravel Cloud's** Cloudflare account, not ours (`e1a594b2…`). So the mirror needs
   **two rclone remotes**, and the source credential comes from Laravel Cloud's key API
   (`cloud bucket-key:create … --permission read_only`) rather than a Cloudflare API token — the
   secret is shown once, at creation, and never again. `media-backup-setup.md` has been rewritten
   accordingly (Step 1, the workflow, and Open decision 1).

   **Second addendum, same day — partially closed.** `weekly-media-backup.yml` is now committed
   (`567d1082`, workflow id `328413731`) and `partna-media-backup` exists. Outstanding: the 30-day
   `deleted/` lifecycle rule, six GitHub secrets, and the prod bucket attach (finding 5). Scoped to
   the **prod bucket only** by decision, which makes the attach load-bearing — the workflow carries a
   `MIN_OBJECTS` floor (0 today, **raise to 1 at first pilot upload**) so an unattached prod fails the
   run rather than reporting a green mirror of an empty bucket.

   **Third addendum — substantially closed.** Bucket, lifecycle rule, both credentials and all six
   secrets are in place, and run `31086678346` completed green with every step executing, including
   the fail-closed verify. Both credentials authenticate and both buckets are reachable.
   **Deliberately NOT marked resolved.** Prod holds 0 media, so `rclone sync` copied nothing and
   `rclone check --one-way` was *vacuously* true — the destination token's **write** permission is
   still untested, and a read-only token would have produced an identical green. The remaining step
   is a real object round-trip (upload probe → run → confirm in `current/` → delete → run → confirm
   in `deleted/<date>/`), which also exercises the tombstone path the GDPR position depends on.
   Calling this closed on a vacuous green would repeat F6 exactly.

   **Deferred to prod go-live by decision (Josh, 2026-08-06)** rather than closed with a synthetic
   probe. Defensible: the deferred failure is loud — a destination token without write permission
   makes `rclone sync` error on the first real object — and the one silent mode, mirroring nothing
   while green, is already guarded by `MIN_OBJECTS`. Tracked as a single trigger in
   `media-backup-setup.md` ("DO THESE TOGETHER, at the first real media in prod"): raise
   `MIN_OBJECTS` 0→1 *and* verify a real object round-trips. **This finding stays open until both
   are done** — the workflow is connected but unexercised, which is not the same as a working
   backup.

5. **🔴 P2 (new, post-drill 2026-08-06) — prod and dev share ONE media bucket, so the media-loss
   blast radius is a single control-plane action.** `AWS_BUCKET` and `AWS_ENDPOINT` are byte-identical
   across both Laravel Cloud envs: one managed storage resource attached twice. The cutover explicitly
   required otherwise — `prod-cutover-change-checklist.md` §C "Media / storage (R2)" asks for "a
   **separate prod R2 bucket + its own keys**" and is still `[ ]`, so this is an unclosed cutover step,
   not a design choice. Same class of fault as the shared KV namespace two sections above it.
   **Failure scenario:** every dev-side destructive path deletes objects out of the bucket prod serves
   — the 30-day soft-delete purge (`config/partna.php:1026`, `routes/console.php:53`), synchronous
   erasure (`AccountDeletionService.php:733-738`), `media:gc-orphaned-video-artifacts` — and detaching
   or deleting that one resource takes prod and dev media together. Exposure is zero today
   (`core.users = 0` in prod) and becomes real at the first pilot upload. **Blocks finding 2**: there
   is no point mirroring a bucket that is about to be replaced.

   **🟢 CLOSED same day.** A prod bucket already existed unattached (`partna_production` /
   `fls-a1bab29a-…`, created 2026-05-08) and prod held no media, so the split needed no object
   migration. Flipped it to public (it was created `private` with `url: null`, which would have
   broken every sitepage image), then attached it in the dashboard. Verified from the control plane —
   no deploy required: prod now reads `AWS_BUCKET=fls-a1bab29a-…` with `AWS_ACCESS_KEY_ID` matching
   `partna_production / default_access_key`, dev unchanged, and **the two environments no longer
   share a bucket or a credential**. `prod-cutover-change-checklist.md` §C steps 1–3 ticked.

6. **🟡 P3 (new, post-drill 2026-08-06) — two unidentified credentials on the dev media bucket.**
   Surfaced while verifying the split. Dev's injected `AWS_ACCESS_KEY_ID` matches *neither* key that
   `bucket-key:list` reports on `partna_development`, and the bucket also carries an unaccounted
   second `read_write` key named `newacesskey` (created 2026-06-24). Dev media serves fine, so the
   credential is valid but unenumerated — most likely a stale value predating a key rotation.
   Before the split, prod carried the *same* unidentified value; the attach fixed prod's side only.
   **Failure scenario:** credentials with unknown scope and unknown ownership hold read-write access
   to the bucket that currently holds every byte of Partna media, and neither can be rotated or
   revoked with confidence because nobody knows what would break. Low urgency — dev data is
   disposable — but it is the kind of thing that is cheap to resolve now and expensive to untangle
   later.

   **Usage search, 2026-08-06 — no consumer found for `newacesskey`.** Searched by access-key id
   across: the backend repo (no hit), the local `.env` (`AWS_ACCESS_KEY_ID` is *empty* — local dev
   does not use R2 at all), the `partna-frontend` and `partna-frontend-main` checkouts (no hit, and
   neither references `AWS_ACCESS_KEY_ID` / `R2_ACCESS` / `S3_ACCESS` anywhere), and the dev Laravel
   Cloud env (its injected key matches *neither* bucket key, so it is not this one either). Laravel
   Cloud exposes no per-key usage telemetry and the bucket lives in their Cloudflare account, so
   last-used cannot be checked — absence of a found consumer is not proof of absence.

   **Fix path — two halves, both need Josh:**
   - *Dev on an unenumerated credential:* re-attach `partna_development` to the dev env in the
     dashboard, exactly as was done for prod. That re-injects the bucket's own `default_access_key`.
     Dev is running, so the app keeps using the old baked config until the next deploy — safe to do
     at any time, verify afterwards with the same control-plane check used for prod.
   - *`newacesskey`:* delete once the above lands and dev is verified on `default_access_key`.
     Deletion is irreversible — an R2 secret cannot be regenerated — so it should follow the
     re-attach, not precede it.

3. **🟡 P2 (carried, still open) — worst-case RPO ≈ 7 days is an unrecorded policy decision.**
   Unchanged from 2026-08-05. Prod still holds no customer data, so exposure is currently zero, but
   the pilot changes that. A daily cron costs roughly the same as the weekly one. Josh's call.

4. **🟢 Phase 3 closed.** The role fix, the grant survival check and the migration-head match all
   work as written. Rehearsing them cost minutes and produced finding 1.

## Runbook corrections

1. **`04-backup-restore.md` Phase 3** — record that the `app_backend` secret's location is
   undocumented, so the step cannot be completed from the docs alone; note the tenant-prefix
   connection form still needs a real scratch project to rehearse.
2. **`04-backup-restore.md` Phase 1** — the media question now has a definite answer: not
   implemented, no workflow committed. State it rather than re-asking each quarter.
3. **`04-backup-restore.md` Phase 1** — add a second media question the drill does not currently ask:
   *which* bucket, in *whose* account, and is it shared with dev? Asking only "is media backed up?"
   would never have surfaced finding 5. Both facts are one `environment:get` away and both change the
   recovery story.

## Next run due

**Quarterly** (TECH-S3-7 / OPS-S4-4) — next due **2026-11**. Re-run the full restore sooner if the
backup workflow, the encryption, or the dump shape changes. Re-rehearse Phase 3 against a real
scratch project once one exists, to close the Supavisor tenant-prefix gap.
