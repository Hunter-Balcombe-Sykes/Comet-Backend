Execute the Stripe subscriptions implementation plan, subagent-driven, in an isolated worktree.

## Nothing may start billing when this merges — read this first

Stripe is set up and we have the keys, but **merging this plan must not start
anyone's clock or charge anyone**. The plan as written does not fully guarantee
that, and closing the gap is part of your job.

What the plan already gives us: `config('partna.billing.enforcement_enabled')`,
default false. It is consulted in exactly three places — the two public publish
gates and the dashboard middleware. It answers "do lapses have consequences?",
**not** "do we charge anyone?". Two things sit outside it:

- **Provisioning is ungated.** On merge, every claim would create a real Stripe
  customer and subscription with a live 30-day trial clock.
- **`billing:prune-disabled` is ungated.** Trial ends → no card → Stripe cancels
  → the webhook writes `status='disabled'` → 90 days later that command
  **deletes the account and releases the handle**, with the enforcement flag
  still off. It gates on `status`/`plan_status`, not on the flag.

So there are four layers, and you are adding one and fixing another:

| # | Layer | Answers | Status |
|---|-------|---------|--------|
| 1 | Stripe **test-mode** keys | "Can real money move?" | Use test mode only. Live keys are a later, separate decision |
| 2 | `provisioning_enabled` | "Do Stripe objects get created at all?" | **NEW — Task 1b below** |
| 3 | `missing_payment_method: 'cancel'` | Trial with no card ends in cancellation, never a charge | Already in the plan, Task 8. Keep it |
| 4 | `enforcement_enabled` | "Do lapses darken sites / 402 writes?" | Exists. **Prune must join it — Task 19b below** |

Both flags ship `false` in every environment. Set only the test-mode keys and
the price IDs; set neither flag.

## Skills

Use `superpowers:using-git-worktrees` to create the workspace, then
`superpowers:subagent-driven-development` to execute — one fresh subagent per
task, two-stage review between tasks. Use
`superpowers:verification-before-completion` before any claim that a stage is
done, and `superpowers:finishing-a-development-branch` at the end.

## The work

- **Plan:** `docs/superpowers/plans/2026-09-03-stripe-subscriptions.md`
- **Spec:** `docs/superpowers/specs/2026-09-02-stripe-subscriptions-design.md`

Both currently live ONLY on branch `docs/stripe-subscriptions-design` (worktree
`.worktrees/stripe-spec`, HEAD `1b684356c`). They are not on `development`.

19 tasks, 5 stages, plus Tasks 1b and 19b below. Read the plan's **"Deviations
from the spec, and why"** table and its **"The five things most likely to go
wrong"** section before starting task 1 — three of the spec's premises had
already moved when the plan was written, and the plan departs from the spec
deliberately on each. Do not "correct" the plan back toward the spec.

## Setup

The main worktree is currently on `docs/close-plans-1-and-3-2026-09-04`, NOT on
development. Branch from the remote, not from HEAD:

```bash
git fetch origin
git worktree add .worktrees/stripe-subs -b feat/stripe-subscriptions origin/development
cd .worktrees/stripe-subs
# Bring the plan and spec onto the branch so they ship with the code.
# Docs-only merge, no conflicts expected.
git merge --no-edit origin/docs/stripe-subscriptions-design
composer install
```

Confirm `composer install` ran in the worktree — autoload paths are per-worktree
and a missed install produces class-not-found failures that look like plan bugs.

Ask me for the **test-mode** publishable key, secret key, webhook signing secret
and the two test-mode price IDs when you reach Task 1. Put them in your local
`.env` only. Do NOT set them on dev or prod via `cloud` — that is an activation
step, not an implementation step.

## Task 1b — the provisioning gate (NEW, immediately after Task 1)

Add `partna.billing.provisioning_enabled`, env-driven, **default false**:

```php
        // Layer 2. Does ANY Stripe object get created? Separate from
        // enforcement_enabled on purpose: enforcement answers "do lapses have
        // consequences", this answers "is anyone's clock even running". With
        // this off the whole Stripe lane is inert — no customers, no
        // subscriptions, no trial clocks, nothing that can ever bill.
        'provisioning_enabled' => (bool) env('PARTNA_BILLING_PROVISIONING_ENABLED', false),
```

Create `App\Services\Billing\BillingMode::provisioningEnabled(): bool` — the
flag AND `services.stripe.secret` non-empty AND both `partna.billing.prices.*`
non-empty. One seam, following the house pattern (`InstagramScraper::isConfigured()`,
and the `Log::info('<thing>.not_configured')` + early return used by
`WebsiteMenuHtmlScanJob` and `GoogleMenuPhotoScanJob`).

Gate these, each logging `billing.provisioning_disabled` and returning:

- **`ClaimSiteService`, the in-transaction write.** This is the subtle one and
  the plan's Task 8 must be written with it from the start, not patched after.
  When provisioning is off the claim writes **no** billing state at all —
  `plan_status` stays NULL. Do NOT write `trialing` with a
  `plan_current_period_end` of `now()+30d`: if the flag stays off for three
  months, that anchor is in the past when it is finally turned on, and Stripe
  rejects a `trial_end` in the past — the trial would end instantly on the first
  real subscription. Accounts claimed while the flag is off are backfilled by
  `billing:enroll-existing` at activation, anchored to the run date, which is
  exactly what that command already exists to do. Spec §7's "no dark window"
  property is unaffected because nothing reads `plan_status` while
  `enforcement_enabled` is also false.
- `CreateStripeSubscriptionJob::handle()` — return without provisioning.
- `ReconcileSubscriptionsCommand::handle()` — return, or it re-dispatches hourly.
- `EnrollExistingCommand::handle()` — refuse with a message naming the flag.

Additionally, **defensively clamp a past anchor in `SubscriptionProvisioner`**:
if `plan_current_period_end` is in the past, re-anchor to
`now() + config('partna.billing.trial_days')` and write it back before calling
Stripe. Reconcile can legitimately pick up a long-stalled row, and a past
`trial_end` is a hard Stripe error, not a soft one.

`BillingActions`, `BillingGrants` and `BillingStateController` gate on
`isConfigured()` (keys present), **not** on `provisioningEnabled()` — a staff
comp or a state read should work the moment the keys are in, independent of
whether new subscriptions are being minted. `BillingStateController` must serve
the projection from `core.users` with `paymentMethod: null` when unconfigured,
never calling Stripe; the entitlement half of that payload never depended on
Stripe being reachable, which is the entire point of the projection columns.

Do NOT gate the webhook route on either flag. It already 503s fail-closed on a
missing signing secret, which is correct.

**Tests:** a claim with the flag off completes, leaves `plan_status` NULL,
dispatches nothing, and logs — and assert the claim path actually RAN, not
merely that nothing threw. A claim with the flag on behaves exactly as Task 8
specifies. And a provisioner given a past anchor re-anchors rather than sending
Stripe a past `trial_end`.

## Task 19b — gate the prune command (NEW, immediately after Task 19)

`billing:prune-disabled` deletes accounts and releases handles. As the plan
writes it, that fires whenever `status='disabled'` and
`plan_status IN ('unpaid','canceled')` — with `enforcement_enabled` still false.
Deleting real accounts on a billing timer we have not turned on is the worst
available outcome, so:

- Return early unless `config('partna.billing.enforcement_enabled')` is true,
  logging `billing.prune.enforcement_disabled`.
- Add a test asserting an account 120 days disabled and canceled is **NOT**
  purged while the flag is false, and IS purged when it is true. The negative
  half is the point.

Deletion is the one genuinely irreversible thing in this branch. It gets the
flag, not a comment.

## Execution rules

**One implementation subagent at a time.** Concurrent subagents in the same
worktree share the git index; two agents staging files at once corrupts the
commit. Parallelism is fine for read-only research, never for tasks that write.

**Never let a subagent run a long command in the background.** A subagent's
background bash never notifies it and the agent idles forever. Any run over ~10
minutes — the full suite, the PG lane, CI polling — stays with you, the
controller.

**TDD is not optional here.** Two tasks contain a deliberate "prove the wrong
version fails" step (Task 6 step 7, the `<=` ordering guard; Task 17 step 6, the
fourth cache lane). Both failure modes are silent and leave every other
assertion green. If either proof step PASSES when it should fail, the test is
asserting the wrong thing — fix the test, not the guard, and tell me.

**Fake Stripe in every automated test.** Bind a Mockery double of
`\Stripe\StripeClient` — **typed against the real class**, because an untyped
Mockery mock silently accepts calls a real client would reject. Live test-mode
calls happen only in the manual stage-gate verification below, never in CI.

**Migrations go to dev Supabase before the merge** (house rule):

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run   # read every line
supabase db push
```

Task 2 has the three migration files and the verification queries. `psql` is
installed but keg-only — `/opt/homebrew/opt/libpq/bin/psql`.

**Run the right test lane.** `composer test` is SQLite and says nothing about
Postgres CHECK/NOT NULL behaviour. Task 6 and Task 2 both need `composer test:pg`.
Task 18 touches capability gating, which has schema-lane coverage —
`composer test:schema`.

## Stage gates — verify against Stripe TEST MODE, locally

You have test-mode keys, so the plan's live gate items are real and must be met
— but run them against your **local** `composer dev` server with
`stripe listen`, using your local `.env`. Nothing gets set on dev or prod.

- **Stage 1** (tasks 1, 1b, 2–7): `stripe listen --forward-to
  http://localhost:8000/api/internal/webhooks/stripe` drives a test-mode
  subscription end to end — signature accepted, `billing.webhook_events` row
  written, `plan_status` projected, a redelivered event acked as a duplicate.
  Plus the `<=` proof and `composer test:pg`.
- **Stage 2** (tasks 8–10): with `PARTNA_BILLING_PROVISIONING_ENABLED=true` in
  your local `.env` only, a claim mints a Stripe customer and subscription and
  writes `trialing` in the transaction with `plan_event_at` NULL. Then flip it
  back to `false` locally and confirm a claim leaves `plan_status` NULL and
  dispatches nothing. **Do not run `billing:enroll-existing` against dev** — it
  is an activation step.
- **Stage 3** (tasks 11–13): a real test card through Elements projects
  `active`; the 3DS card `4000 0027 6000 3184` returns `requires_action` with a
  usable `clientSecret`; a plan swap invoices immediately and the webhook flips
  `account_type`.
- **Stage 4** (tasks 14–18): **STOP and report before I approve the merge.**
  Verify locally with `enforcement_enabled=true`, then set it back to false.
  Tasks 14 and 18 ship regardless of any flag; **14 closes a free-upgrade hole
  that is open in production today** — `PATCH /api/me {"account_type":"business"}`
  is a free tier upgrade right now. It is the one genuinely live security fix in
  this branch; verify it against a real request, not just the unit test.
- **Stage 5** (tasks 19, 19b): verify the prune gate in both directions.

**Before you open the PR, prove the merge is inert.** With both flags false and
no `STRIPE_*` vars set — i.e. exactly what dev will look like — run the claim
path and assert: `plan_status` NULL, no Stripe call attempted, no job in
Horizon's failed list, public profiles unaffected, prune finds nothing. That is
the acceptance test for this branch, more than any single feature test.

If a gate item fails, stop and tell me what you saw.

## Review

After each task, run the plan's own verification steps. After each stage, use
`superpowers:requesting-code-review` on the stage's diff. Report findings to me
with your own assessment before acting on them — reviewer suggestions in this
repo have a measured history of being confidently wrong about premises, so
verify each claim against the code before implementing it.

## The go-live brief — a required deliverable

Write `docs/deploy/stripe-go-live.md`. It is read months from now by someone who
did not build this, so it is a runbook, not a summary: exact commands, exact env
keys, exact dashboard steps, and what "it worked" looks like at each point.
Follow the shape of `docs/deploy/routine-deploy.md`.

Structure it as **the ordered sequence of switches**, because the ordering is
the whole safety property:

1. **What is merged and what it does today.** Both flags false, no `STRIPE_*`
   vars set on any environment, nobody's clock running, nothing charging.
   Name the four layers and what each one holds back.

2. **Step 1 — keys on dev, test mode.** Which vars, set via `cloud`, all still
   test-mode values. Register the dev webhook endpoint
   (`https://dev-api.partna.au/api/internal/webhooks/stripe`), subscribe the
   seven event types from spec §8.4, record the signing secret. **The signing
   secret differs per environment** — a dev secret on prod means every delivery
   400s, which looks like a code bug and is not. Verify: webhook returns 200 on
   a `stripe trigger`, still nothing provisioning (flag off).

3. **Step 2 — provisioning on, dev.** `PARTNA_BILLING_PROVISIONING_ENABLED=true`.
   New claims now mint test-mode customers and subscriptions. Verify a claim end
   to end. Note that nobody can be charged: `missing_payment_method: 'cancel'`
   means a trial with no card ends in cancellation, not an invoice.

4. **Step 3 — backfill.** `billing:enroll-existing`, dry-run first. Repeat the
   plan's §13 warning in full: the trial anchors 30 days from the RUN date,
   never from `claimed_at`; anchoring to claim date retroactively expires
   everyone the instant enforcement is switched on. Call out that accounts
   claimed while provisioning was off have `plan_status` NULL and are exactly
   what this command is for.

5. **Step 4 — the full verification checklist**, before any enforcement: real
   card projects `active`; 3DS `4000 0027 6000 3184` returns `requires_action`;
   plan swap invoices and flips `account_type`; duplicate delivery acks once;
   `billing:reconcile-subscriptions` reports 0 stalled and 0 drift.

6. **Step 5 — live-mode keys.** Create the two products and their **AUD monthly**
   prices in live mode (currency and interval are locked by design, D14; annual
   is a second price later with no schema change). Swap all five vars per
   environment. State plainly that this is the point real money becomes
   possible, and that layer 3 still means no card equals no charge.

7. **Step 6 — enforcement on, dev first.** `PARTNA_BILLING_ENFORCEMENT_ENABLED=true`,
   and the three things that make it survivable: it is env-driven so it can be
   killed without a redeploy or rollback; **verify against a COLD handle**,
   because §9.1 fails open for one resolve TTL after the flip and a warm-cache
   check reads as a false negative; and `past_due` stays live by design, so an
   account mid-dunning is not evidence the gate is broken. Note that this flag
   also arms `billing:prune-disabled` — the first deletion is 90 days out, but
   the timer starts here.

8. **Rollback, per step.** Be honest that schema rollback does not exist, so the
   migration is the one irreversible action, and that flipping either flag back
   to false is immediate and needs no redeploy.

Link it from `docs/api.md`'s billing section and from the plan.

## Landing it

Once all stages are done, the go-live brief is written, and I have approved:

1. Full local verification, in this order — do not skip to the next on a failure:
   ```bash
   composer test          # ~20 min
   composer test:pg
   composer test:schema
   composer analyse
   php artisan pint --test
   ```
2. Open a PR to `development` from `feat/stripe-subscriptions`. The repo is
   `PartnaAu/partna-backend` (moved orgs 2026-09-04 — old
   `Hunter-Balcombe-Sykes` URLs redirect silently, so use the new one). The PR
   description's first line must state that **both flags ship false and no
   `STRIPE_*` var is set on any environment**, so the merge starts no clock and
   charges nobody.
3. **All nine required checks must be green** before merge: `test`,
   `postgres-tests`, `schema-tests`, `schema-drift`, `worker-tests`,
   `worker-static`, `outbound-http-guard`, `supply-chain`,
   `checkpoint-suppressions`.
   A red `test` job with **no actual test failure** — `curl error 28`, exit 100 —
   is a packagist flake, not a regression: `gh run rerun --failed`. Do not merge
   with `--admin` past a genuinely failing check without asking me first.
4. Merge the PR, then fast-forward and push `development`.
5. Confirm the dev Supabase ledger matches: every one of the three migration
   filenames present in `supabase_migrations.schema_migrations`, with the
   version stamped as the FILENAME's. If a version was stamped differently at
   apply time, re-stamp with `UPDATE version` — never
   `supabase migration repair --status reverted`.
6. Verify the dev deploy landed: `cloud deployment:list development` — match a
   `*.succeeded` status, not a bare `running`.
7. Smoke-test on `dev-api.partna.au`, and this is the real acceptance test for a
   gated build: a claim still succeeds end to end, leaves `plan_status` NULL,
   attempts no Stripe call, and adds nothing to Horizon's failed list. Public
   profiles are unaffected. `billing:prune-disabled --dry-run` finds nothing.

## Do NOT

- **Do not touch production.** No `git push origin development:production`, no
  `supabase link --project-ref edplucmvkcnokyygxqsb`. Prod schema has diverged
  from dev and prod reconciliation is separate deferred work.
- Do not set any `STRIPE_*` var, or either billing flag, on dev or prod. Local
  `.env` only. All environment configuration is a go-live step.
- Do not use live-mode keys anywhere. Test mode only.
- Do not run `billing:enroll-existing` against dev.
- Do not remove `account_type` from `User::$fillable` — the plan explains why at
  Task 14 step 3; it is a ~250-test-file trap.
- Do not create Laravel migration files. Raw SQL in `supabase/migrations/` only.
- Do not add the four §8.4 notification-only event handlers or the §10.2
  referral clawback wiring. The plan's self-review states why both are out of
  scope; raise them as follow-ups instead.
- Do not use `--admin` to bypass required checks. A previous merge did that and
  `postgres-tests` never verified the thing it was meant to guard.

## When you are done

Move the plan to `docs/superpowers/plans/closed/` and update
`~/Developer/IMPLEMENTATION-STATUS.md` via the `partna-handoff-status` skill.
Then tell me: what shipped, which switches are still off, and anything you found
that the plan got wrong.
