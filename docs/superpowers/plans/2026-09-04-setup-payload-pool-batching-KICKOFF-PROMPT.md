# Kickoff prompt — Setup Payload Pool Batching

Paste the block below into a fresh Claude Code session started from
`/Users/joshuahunter/Herd/Side Street/backend`.

---

```
Execute docs/superpowers/plans/2026-09-04-setup-payload-pool-batching.md end to end,
subagent-driven, in an isolated worktree. Run autonomously — do not stop to ask me to
approve git, gh, test, or file operations. Only stop if you hit something the plan does
not cover and guessing would be unsafe.

SKILLS
- superpowers:using-git-worktrees to create the workspace.
- superpowers:subagent-driven-development to run the tasks (fresh subagent per task,
  two-stage review between tasks).
- superpowers:verification-before-completion before any claim that a task passed.

WORKSPACE
Create the worktree at .worktrees/setup-pool-batching on a new branch
perf/setup-payload-pool-batching-2026-09-04 cut from development. Confirm with
`git worktree list` before starting. Two sibling worktrees already exist
(.worktrees/legacy-payload-lane, .worktrees/stripe-spec) — leave both alone; do not
touch files they own.

EXECUTION
Work Tasks 1 → 2 → 3 → 4 in order. Each task: dispatch a fresh subagent with the task's
steps verbatim, then review its work yourself before ticking the boxes and moving on.
Tick checkboxes in the plan file as you go and commit that with the task.

The acceptance bar for Tasks 1 and 2 is byte-identical wire output. tests/Feature/Setup/
must pass with EVERY assertion untouched. If a subagent edits an assertion in
SetupControllerTest.php or SetupMenuWireTest.php, reject its work and send it back — the
bug is in its code, not the test.

Task 3 is the one that may legitimately turn out to be a no-op. If flipping
withDuplicateCandidates to false changes any setup assertion, revert Task 3, say so, and
carry on to Task 4 — that is a valid outcome, not a failure.

CI POLICY
Ignore CI entirely until Task 4. Do not open the PR early, do not poll `gh pr checks`
between tasks. Local `./vendor/bin/pest <path>` per the plan's steps is the only gate
while implementing.

Repo gotchas that will otherwise cost you a cycle:
- `composer test --filter` is BROKEN here. Run whole files by path.
- The gate is `pint --test`, not `pint`. Run `php artisan pint` before each commit.
- Tests run SQLite; production is Postgres. A green suite says nothing about CHECK or
  NOT NULL constraints. This plan adds no schema, so that only matters if you find
  yourself writing a migration — which would mean you have misread a task.
- Do not create Laravel migration files. A composer guard rejects them.

FINISH
At Task 4: run `composer test` and `composer test:pg`, then push the branch and open the
PR with the body the plan gives. THEN wait for CI — all nine checks. Note `test` takes
~20 minutes; a red `test` job with no actual test failure and `curl error 28` is a
packagist flake, so just `gh run rerun --failed`.

When CI is green, merge the PR to development and push. Merging auto-deploys to
dev-api.partna.au; there is no migration in this change, so no schema step is needed.
Confirm the deploy reached `deployment.succeeded` with
`~/.composer/vendor/bin/cloud deployment:list development --json | head -c 400`.

If CI is genuinely red (not a flake), STOP and report. Do not merge with --admin or
otherwise bypass the required checks — that needs my explicit say-so, per how the last
two bypasses went.

CLEAN UP
After the merge is confirmed:
- remove the worktree (`git worktree remove .worktrees/setup-pool-batching`)
- delete the local and remote branch
- `git worktree list` should show only the main checkout and the two pre-existing
  siblings
- leave the main checkout on development, clean, fast-forwarded to the merge

REPORT BACK
One short summary: what each task changed, the before/after numbers from Task 4 Step 4
if you were able to exercise the dialog, anything you reverted, and anything the plan got
wrong. Do not paste diffs or file dumps.
```
