# EXECUTE PROMPT — services cutover, TASK 1 ONLY (apply the armed view migration)

> **SPENT 2026-08-17 — do not re-run.** Task 1 shipped. Verified independently:
> `20260817000000` is recorded in `supabase_migrations.schema_migrations`, and
> the `pg_depend` query returns zero rows for all three legacy service tables —
> `site.public_site_payload` is fully off them. Re-running this prompt would
> re-apply an applied migration. Kept for provenance.
>
> **Tasks 2–13 continue in `2026-08-17-services-cutover-EXECUTE-PROMPT-tasks-2-13.md`.**
>
> Two instructions below went stale the moment the shop prompt was corrected:
> pre-flight check 2's "PARKED" branch (nothing was ever parked — the parking
> instruction was withdrawn before it was acted on), and check 3's note about the
> shop prompt's stale warning (that warning has since been removed at source).
> Both were harmless; neither describes the world now.

> Paste the fenced block into a fresh session. Named EXECUTE, not KICKOFF: the
> spec and plan already exist, so this session implements rather than plans.
>
> **This prompt covers Task 1 of 13 and then STOPS — owner instruction
> 2026-08-17: Josh needs to check something after Task 1 before anything else
> runs.** A later prompt (or the owner directly) kicks off Tasks 2+.

---

```
Rename this session to services-cutover-task-1.

You are executing EXACTLY ONE task of the services-cutover plan: Task 1, "Apply
the armed view migration to dev". When Task 1's last step is done and reported,
you STOP. You do not start Task 2, you do not "keep momentum", you do not touch
app/ code at all — the owner needs to check something after Task 1 before the
rest of the plan may run. Dev only; production is out of scope for the whole
programme.

YOUR DOCUMENTS — both on the development branch (commit 137c42fdb):
- Plan:  docs/superpowers/plans/2026-08-17-services-cutover.md   ← Task 1 is
         fully written there, six steps with the exact commands and SQL. Follow
         it step by step and tick the boxes. Its Global Constraints apply.
- Spec:  docs/superpowers/specs/2026-08-17-services-cutover-design.md §3.1
         (Unit 1) — what the migration does and why it goes first.

RULE ZERO — VERIFY, DO NOT TRUST. Everything below was true on 2026-08-17.
Multiple sessions are active in this repository and the state moves hourly.
Re-check each precondition; where reality disagrees, reality wins and you say
so before acting.

WHAT TASK 1 IS, in one paragraph

supabase/migrations/20260817000000_public_site_payload_services_from_content.sql
(committed on development, ~19KB) recomposes the site.public_site_payload
VIEW's `services` key off content.* (manual half), replacing its read of the
three legacy service tables. It is deliberately UNAPPLIED on dev. Task 1
applies it on purpose, verifies the view no longer depends on the legacy
tables, re-warms KV, records the id-domain wire change, and commits the
manifest. The view is the LIVE RENDER PAYLOAD (PublicSitePayload →
SyncSubdomainToKvJob → every <handle>.partna.au sitepage), which is why the
verification steps are not optional.

PRE-FLIGHT — four checks before you run anything

1. SHARED CHECKOUT. The main checkout is shared by several live sessions and
   its branch can change under you mid-task (this has happened twice today,
   once with data loss). Rules:
   - Run `git worktree list` and `git branch --show-current` FIRST.
   - development may already be checked out in a sibling worktree (it was in
     .worktrees/dev-reconcile on 2026-08-17) — if so, do NOT contend for it.
     For the one commit this task makes (the wire manifest), create your own
     worktree: `git worktree add --detach .worktrees/svc-task1 origin/development`,
     commit there, push with `git push origin HEAD:development`, remove the
     worktree. Never `git reset` anything in the main checkout.
   - Verify the branch IN THE SAME COMMAND as any commit, not one command
     earlier.

2. WHERE IS THE MIGRATION FILE? Two valid states, handle both:
   a. Present at supabase/migrations/20260817000000_...sql on development —
      the normal state. Proceed.
   b. PARKED (moved out of supabase/migrations/) by the shop-brands-rehome
      session — its execute prompt tells it to park the file before its own
      db push, and to expect the services project to restore it. If parked:
      restoring the file to supabase/migrations/ IS your step 0, done in the
      same worktree/commit flow as the manifest, with a commit message saying
      the services project reclaimed it.
   If the file exists in NEITHER place, or schema_migrations already records
   20260817000000 as applied, STOP and report — someone else moved first.

3. IGNORE ONE STALE WARNING, on evidence. The shop-brands EXECUTE-PROMPT
   claims applying this migration "loses every Fresha user's public services".
   That was checked against the live dev catalog on 2026-08-17 and it is
   WRONG: the deployed view's services key filters `sv.source IS NULL` —
   owner-authored rows only; Fresha services have never been in the KV
   services key (they ride payload.selection). Re-verify yourself if you like
   (`pg_get_viewdef('site.public_site_payload')` — look for `source IS NULL`),
   but do not let that prompt's warning stop this task.

4. IS ANYONE MID-PUSH? `supabase db push` applies EVERY unapplied migration
   file in the checkout it runs from. Run it from a checkout at development's
   tip (your worktree from check 1 is exactly that), and check the dry-run
   lists EXACTLY ONE migration: 20260817000000. Anything else in the list is
   another session's work sitting unapplied — STOP and report; never apply a
   stranger's migration as a side effect.

THE STEPS — plan Task 1, steps 1–6, summarised (the plan has the full SQL):

1. Confirm 20260817000000 absent from supabase_migrations.schema_migrations.
2. supabase link --project-ref glncumufgaqcmqhzwrxm  (DEV — never the prod ref
   edplucmvkcnokyygxqsb), db push --dry-run (exactly one file), db push.
3. Run the plan's pg_depend query: the view must show ZERO dependencies on
   site.services / site.service_categories / site.service_category_assignments.
   Any row ⇒ STOP (the migration missed a reference; rollback is the pre-image
   CREATE OR REPLACE transcribed at the foot of the migration file itself).
4. Re-warm KV for published sites (the plan gives the cloud command:run
   one-liner) and spot-check one site: API services array == rendered sitepage
   services, both carrying content.items ids.
5. Create docs/wire-changes/2026-08-17-services-cutover.md with the KV
   id-domain entry (text is in the plan, verbatim).
6. cloud env:logs partna development --minutes 10 (clean), then commit the
   manifest via the worktree flow from pre-flight 1, push to development.

WHEN YOU ARE DONE

Report, in your final message: the dry-run output, the pg_depend result, the
parity spot-check site + verdict, the log check, the manifest commit sha —
then STOP. State explicitly that Tasks 2–13 have not been started and are
waiting on the owner. Do not tick anything beyond Task 1 in the plan.

STOP CONDITIONS (report, do not improvise): dry-run lists more than one file;
pg_depend returns rows post-apply; API/KV parity mismatch; errors in the dev
logs; the migration already applied; the file missing from both locations.
Rollback (only if the owner asks): re-run the migration file's foot pre-image
via psql — the file documents this; do not hand-edit schema_migrations.

NON-NEGOTIABLES
- Task 1 only. STOPPING IS THE DELIVERABLE.
- Never link or apply anything against the prod ref. Never push
  development:production.
- No Laravel migration files; no schema changes beyond applying the one
  already-written file.
- If reality diverges from this prompt or the plan, you raise it — you do not
  quietly adapt and proceed.
```
