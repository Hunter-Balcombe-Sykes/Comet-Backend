# Merge Gate-A audit work (B7–B12) into `development` and deploy

Goal: merge branch `audit-fix/gate-a-2026-07-20` (six reviewed, green audit units B7–B12) into
`development` and push it. **Pushing `development` is push-to-deploy on the LIVE dev API
(dev-api.partna.au, dev Supabase `glncumufgaqcmqhzwrxm` — real traffic).** This is NOT a clean
fast-forward: `origin/development` has diverged substantially since the branch's merge base and the
divergence overlaps the audit files. Do it carefully, gate on a green full suite, then push.

Open a fresh Claude Code session on **Opus** and paste from `=== PROMPT START ===`.

---

```
=== PROMPT START ===

Merge branch audit-fix/gate-a-2026-07-20 into development and push it to deploy. This is a real
semantic merge with a deploy at the end — follow the steps below, do not shortcut, and do NOT push
until the FULL suite is green on the merged result.

## Known state (verify, then update your mental model with a fresh fetch)
- audit-fix/gate-a-2026-07-20 tip was `2c169fe1` — six audit units B7–B12 (fix(audit): B7…B12) plus
  two hand-off prompt commits. All six were independently reviewed and full-suite-green when committed.
- origin/development was `5aedff88`; the branch's merge base with it is `65d6132e`.
- origin/development advanced ~20 commits past that base: the `feat/scraper-and-dkit-reworking` line
  (platform scraping, design-kit rework, menu scanning, LogoAutoGrabber, per-platform connection
  locking) AND a **behavior change: "claim no longer waits for ready" (Decision A)** — a claim now
  SUCCEEDS while a build is pending/building, where it used to reject a not-ready build.
- These overlap the audit files: `GoogleBusinessSourceGenerator` + `GoogleBusinessFetch` +
  `GoogleBusinessPayload` (B12), `UserServiceController` (B11 SEC-1), `ClaimSiteService` (B12 edge
  purge), `config/partna.php`, and several PreAccount tests.

## Discipline (non-negotiable)
- Verify the BRANCH NAME (`git rev-parse --abbrev-ref HEAD`), not just HEAD SHA, before any commit or
  push. A concurrent `git checkout` in this shared single worktree silently switches your branch
  (that is how five audit commits landed on `development` last session).
- NEVER `git stash` / `git checkout <file>` / `git restore` / `git reset` (a second dev + prior
  stashes live here). `git merge --abort` IS allowed to cleanly cancel a conflicted merge when the
  working tree was clean before you started. NEVER force-push.
- Never push to `production`. `development` push = deploy — see the sign-off step.

## Step 1 — orient
`git fetch origin && git checkout audit-fix/gate-a-2026-07-20 && git rev-parse --abbrev-ref HEAD`
(confirm the branch name). Re-measure: `git rev-parse --short origin/development` (it may have moved
past 5aedff88 — re-read the divergence with `git log --oneline origin/development ^HEAD`). Confirm the
working tree is clean (ignore untracked `docs/superpowers/plans/*`).

## Step 2 — merge origin/development INTO audit-fix (update the feature branch)
Do this direction (not the reverse) so audit-fix stays current for the remaining units (B14, B15, B21,
B13, B20, S4 — see PROMPT-execute-P2-remaining-part3.md) and development then fast-forwards cleanly.
`git merge origin/development` — expect conflicts. If the conflict scope looks larger than the
resolvable set below, `git merge --abort` and report to Josh rather than guessing.

## Step 3 — resolve the KNOWN test conflicts (behavior change + the B11 fillable rule)
Two conflicts were seen last time; there may be more after a fresh fetch:
- `tests/Feature/PreAccount/ClaimSiteServiceTest.php` — origin/development REPLACED the old
  `it('rejects a not-ready build')` with `it('claims successfully while pending')` +
  `it('claims successfully while building')` (the "claim no longer waits for ready" behavior).
  **Take origin/development's new tests** (the current behavior is correct). The audit side only
  changed the fillable write mechanism, which no longer applies to a deleted test.
- `tests/Feature/PreAccount/GoogleBusinessSourceGeneratorTest.php` — B12 added reviewer-PII-strip
  assertions; the scraper rework changed the same file. Reconcile so BOTH survive: the generator must
  still strip `reviews` + `photos[].authors` (B12) AND satisfy the scraper rework's expectations. Read
  the current `GoogleBusinessSourceGenerator` (Step 5) to know the real shape.

## Step 4 — THE B11 FILLABLE NO-OP RECONCILIATION (the non-obvious part)
B11 made these fields NON-fillable, so any mass-assignment write of them silently NO-OPS (no error on
SQLite; the test just stops testing what it claims, or a persisted-row `update` does nothing):
- `PreAccountBuild`: `build_state`, `claimed_at`, `failure_code`
- `User`: `status`, `deletion_token_hash`, `deletion_requested_at`, `deletion_confirmed_at`,
  `deletion_previous_status`, `deletion_mail_sent_at`, `admin_notes` (handle/handle_lc are STILL fillable)
- `Customer` / `Service` / `ServiceCategory` / `UserConfirmationPreference`: `user_id`
- `UserDeletionAuditEntry`: `user_id`, `actor_id`, `ip_address`, `professional_email_snapshot`,
  `actor_handle_snapshot`
origin/development's code and tests predate B11, so they still write these via `$m->update([...])` /
`Model::create([...])` / `new Model([...])`. After the merge, grep the merged tree for those writes and
convert each to `->forceFill([...])->save()` / `::forceCreate([...])` / a relation `create()` (see the
memory `feedback-fillable-tenancy-fk-associate`). The FULL SUITE is what surfaces them: a merged test
that silently no-ops a state write will fail its own assertion. Fix forward; do NOT relax B11.
Example already seen: origin/development's `$build->update(['build_state' => STATE_PENDING])` must
become `$build->forceFill(['build_state' => STATE_PENDING])->save()`.

## Step 5 — validate the AUTO-MERGED code (git merged it textually; confirm it's semantically right)
Read the merged versions and confirm BOTH sides' intent survives, coherently:
- `app/Services/PreAccount/ClaimSiteService.php` — B12's afterCommit `CloudflareCachePurgeJob` edge
  purge on claim must still be present AND origin/development's "claim no longer waits for ready" logic
  must be intact (they touch the same method).
- `app/Services/PreAccount/Generators/GoogleBusinessSourceGenerator.php` — B12's
  `GoogleBusinessPayload::stripThirdPartyPii()` call must survive alongside the scraper rework.
- `app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php` — B12's unclaimed-only strip on
  refresh (`user()->value('status') === 'unclaimed'`) must survive.
- `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php` — B11's skeleton
  direct-assignment (`new Service; $skeleton->user_id = ...`) and relation-create conversions must
  survive; confirm no reverted `new Service(['user_id'=>...])` crept back in.
- `config/partna.php` — both sides' new keys present.

## Step 6 — FULL SUITE GREEN (hard gate)
`composer test`. It MUST be green before any push. If failures appear, they are the merge's semantic
breaks + the fillable no-ops from Step 4 — fix each forward and re-run. Never accept "pre-existing"
without proof (`git show` the prior commit / re-run in isolation for flaky timing tests, e.g.
`ConnectResolverYoutubeTest` is a known flaky wall-clock test that passes solo). Also run
`vendor/bin/pint --test` on the changed files and fix your own new violations.

## Step 7 — commit the merge, fast-forward development, push (DEPLOY)
- Commit the resolved merge on audit-fix (the merge commit + any fixture fixes). Verify branch name +
  `git diff --cached --stat` first.
- `git checkout development` (confirm it is `origin/development` with no local drift; if drift, reconcile).
  `git merge --ff-only audit-fix/gate-a-2026-07-20` (should be a clean fast-forward now that audit-fix
  contains origin/development).
- **This push deploys the live dev API.** Present a one-line summary (units shipped + that the full
  suite is green) and get Josh's explicit go-ahead, then `git push origin development`.
- Optionally watch the deploy: `cloud deployment:list development` (build.running → deployment.succeeded).

## After the push
- Do NOT delete `audit-fix/gate-a-2026-07-20` — the Gate A audit is INCOMPLETE (units B14, B15, B21,
  B13, B20, S4 + the P3 units + the two deferred B8 audit-purges remain). The remaining work continues
  on audit-fix; it now contains origin/development so future merges are clean.
- Report: what merged, conflicts resolved, fillable fixtures reconciled, suite status, the deploy
  result. Then the remaining audit units can resume via PROMPT-execute-P2-remaining-part3.md.

## Stop and ask Josh if
- The conflict scope after fetch is materially larger than the two known test conflicts + the fillable
  fixtures (e.g. conflicts in core service/controller CODE you can't confidently reconcile).
- The auto-merged code (Step 5) dropped a B11/B12 change or a scraper-rework change — reconciling
  another dev's rework by guesswork is not worth a bad deploy; surface it.
- The full suite can't be made green with fix-forward changes within the fillable/behavior-change
  pattern above.

=== PROMPT END ===
```
