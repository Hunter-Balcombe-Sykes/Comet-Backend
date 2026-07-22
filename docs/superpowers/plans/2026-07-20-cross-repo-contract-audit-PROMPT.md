# Cross-repo contract & dead-code campaign — backend ↔ frontend

Compares the Laravel backend against the Next.js frontend in **both** directions,
then sweeps dead code across the whole of both repos.

- **Backend repo:** `Side Street/backend` (this repo)
- **Frontend repo:** `Side Street/partna-frontend` — GitHub `hunterbalcombesykes/side-st`
  (redirects to `partna-frontend`; same repo, renamed)

Three questions this answers:

1. What did the backend build that the frontend never wired up?
2. What does the frontend call that the backend no longer serves? *(already has
   confirmed hits — see Stage 2)*
3. What is dead in either repo, including code only provable-dead cross-repo?

---

## Preconditions — check these before Stage 1

- **Frontend on `main`, clean tree.** Local checkout is currently on
  `test/embedded-visible-change`. Josh runs the checkout; **never** pull, fetch,
  or check out the frontend from a backend session. `contract-inventory.sh`
  refuses to run otherwise.
- **`PARTNA_FRONTEND_PATH`** set, or the default `../partna-frontend` resolves.
- **One `audit.sh` at a time.** They share the local `claude` CLI budget.
- Each run writes `audits/<category>/<date>-<name>/CONSOLIDATED.md`. Fix with
  `execute audit <path>` (runbook: `scripts/audit/fix-flow.md`).

---

## Stage 0 — build the pipeline support (no audit runs) · **needs sign-off**

Stage 0 modifies the shared audit pipeline and a CI guard. It is a blocker-gate
item under `fix-flow.md`: produce the plan, present it, wait for sign-off.

> **Prompt:**
> Build Stage 0 of `docs/superpowers/plans/2026-07-20-cross-repo-contract-audit-PROMPT.md`.
> Five deliverables, listed below. Present a plan for all five and wait for my
> sign-off before implementing — this touches the shared audit pipeline and a CI
> guard. After implementing, run `composer test` and confirm
> `tests/Feature/Architecture/AuditPipelineIntegrityTest.php` passes.

**0.1 — `scripts/audit/audit-scan.sh`: fix the file-type glob.**
Add `*.tsx`, `*.jsx`, `*.mjs`. Exclude `*/.next/*`, `*/build/*`, `*/out/*`,
`*/.turbo/*`. Fence each file by its real extension instead of hardcoded
` ```php `.

> This is a standalone bug. The glob is `.php .blade.php .sql .js .ts .yml .yaml .sh`
> — **no `.tsx`**. Scoping `app/` on the frontend today reads 197 `.ts` files and
> silently skips all 237 `.tsx` ones. Per `CLAUDE.md`: *"a scope path whose
> extension isn't in it is read as nothing"* — and a lens that reads nothing
> reports nothing, which is indistinguishable from clean. Fixing this changes
> what **every** lens can see, so treat new findings in already-audited areas as
> expected, not as regressions.

**0.2 — `scripts/audit/contract-inventory.sh`** (new). Deterministic pre-pass,
no LLM. Emits `CONTRACT-INVENTORY.md` (~50KB):

- *Backend:* `php artisan route:list --json` (473 routes — method, uri, name,
  middleware, action); `config/partna.php` feature flags; `AccountCapabilities`
  capability keys; staff `integration.*` / `feature.*` availability rules.
- *Frontend:* `app/api/**/route.ts` BFF routes + the backend URL each proxies to;
  literal `/api/(professional|public|staff|account|internal)/…` call sites;
  `proxy.ts` `RESERVED_PATHS`; the `app/**/page.tsx` route surface.
- *Reconciliation buckets:* `MATCHED`, `BACKEND-ONLY`, `FRONTEND-ONLY`,
  `UNRESOLVED`.

> **Path normalization is the whole game.** The frontend writes
> `` `/api/professional/sites/${siteId}/blocks` ``; the backend registers
> `api/professional/sites/{site}/blocks`. Neither string matches. Normalize both
> sides (`${…}` and `{param}` → `{}`) before diffing. Anything still unmatched
> goes to **`UNRESOLVED`, never to `BACKEND-ONLY`** — that bucket is the entire
> false-positive defense. Header stamps frontend branch + SHA.

**0.3 — `scripts/audit/lenses/frontend-backend-contract.md`** (new), prefix `XREPO`.
Six categories: (1) backend route with no frontend consumer; (2) frontend call to
a nonexistent backend route — **P1, live runtime 404s**; (3) vestigial BFF routes
for deleted subsystems; (4) half-wired capability or feature flag; (5) backend
code reachable *only* via an orphaned route; (6) contract shape drift (e.g. a
frontend still reading `themeMode`/`accent`/`fontFamily` after the design-kit
migration, or `skeletonId` vs `architectureId`).

**0.4 — `scripts/audit/lenses/cross-repo-dead-code.md`** (new), prefix `XDEAD`.
Frontend-side and cross-repo dead code. Explicitly **not** a re-implementation of
`code-quality-slop` — backend intra-repo dead code stays with that lens (Stage 4).

Both new lenses inherit `code-quality-slop`'s anti-false-positive discipline,
hardened for two repos: **the adjudicator must grep BOTH repos before confirming
any absence claim**, and an `UNRESOLVED` entry can never become a finding without
a source read.

**0.5 — Wiring.** Add a `cross-repo` bundle to `audit.sh` containing both new
lenses (`--lens-file` takes one file, so running both in a single pass requires a
bundle). Add a cross-repo exemption to `AuditPipelineIntegrityTest`.

> Two guards will fail on day one otherwise. **Bundle reachability:** a lens in no
> bundle can never run — this is what left `test-prod-parity` orphaned.
> **Declared-scope:** the guard requires `codebase_chunks()` to feed every
> `--scope` path a lens names in its prose, which is structurally impossible for
> paths outside this repo. Exempt cross-repo lenses explicitly; do not paper over
> it by dropping the scope lines from the lens doc.

---

## Stage 1 — contract drift (2 runs) · highest value

> **Prompt:**
> Run Stage 1 of `docs/superpowers/plans/2026-07-20-cross-repo-contract-audit-PROMPT.md`.
> First run `scripts/audit/contract-inventory.sh` and report the four bucket
> counts. Then execute the 2 audit runs **sequentially** — never two `audit.sh` at
> once. Report tier counts and folder paths only; do not paste file contents. Stop
> and tell me if a run fails. Treat every `UNRESOLVED` entry as unproven: it may
> not become a finding without reading source in both repos.

```bash
A=scripts/audit/audit.sh
scripts/audit/contract-inventory.sh                                    # writes CONTRACT-INVENTORY.md

$A --category cross-repo --name contract-drift --bundle cross-repo \
  --scope audits/cross-repo/CONTRACT-INVENTORY.md \
  --scope routes --scope app/Http/Resources \
  --scope "$PARTNA_FRONTEND_PATH/app/api" \
  --scope "$PARTNA_FRONTEND_PATH/proxy.ts"                             # ~290 KB

$A --category cross-repo --name capability-wiring --bundle cross-repo \
  --scope audits/cross-repo/CONTRACT-INVENTORY.md \
  --scope app/Services/Accounts --scope config/partna.php \
  --scope "$PARTNA_FRONTEND_PATH/lib/account-capabilities.ts" \
  --scope "$PARTNA_FRONTEND_PATH/lib/account" \
  --scope "$PARTNA_FRONTEND_PATH/features/integrations"                # ~230 KB
```

**Why first:** category 2 findings are live runtime 404s, not tidiness. This is
the only stage that answers the original question directly; everything after it
is sweep coverage.

---

## Stage 2 — the known-dead subsystems (2 runs) · highest hit-rate per token

Go where the evidence already points. These subsystems were **removed from the
backend** but their frontend still exists:

| Subsystem | Backend status | Frontend still present |
|---|---|---|
| Shopify / Stripe / commerce | stripped (standalone strip-down) | `features/commerce` 74KB, `lib/shopify` 25KB, `lib/stripe-connect.ts`, `app/api/shopify/*`, `dashboard/commerce` 57KB, `dashboard/shop` 22KB |
| Square | dropped 2026-05-11 | `lib/square` 29KB |
| Booking / Fresha | dropped 2026-05-11 | `features/booking` 6KB, `app/api/public/booking/*` |
| Waitlist | retired 2026-07-19, **table dropped** | `app/api/public/waitlist` |
| Affiliates | no backend surface | `features/affiliates` 46KB, `dashboard/affiliates` 32KB |
| Theme picker / SmartLinks | removed | `proxy.ts` reserves `theme-1`, `theme-2`, `unique-themes`, `smart-tools` |

> **Prompt:**
> Run Stage 2 of `docs/superpowers/plans/2026-07-20-cross-repo-contract-audit-PROMPT.md`
> — the 2 known-dead-subsystem runs. Sequential, counts + paths only, stop on
> failure. These target subsystems the backend has already removed, so expect a
> high confirmed-dead rate — but still require the adjudicator to grep both repos
> before confirming, since a "dead" feature may still be reachable from a live
> route or referenced by a page that is itself still linked.

```bash
$A --category cross-repo --name dead-commerce --bundle cross-repo \
  --scope "$PARTNA_FRONTEND_PATH/features/commerce" \
  --scope "$PARTNA_FRONTEND_PATH/features/billing" \
  --scope "$PARTNA_FRONTEND_PATH/lib/square" \
  --scope "$PARTNA_FRONTEND_PATH/lib/shopify" \
  --scope "$PARTNA_FRONTEND_PATH/lib/stripe-connect.ts" \
  --scope "$PARTNA_FRONTEND_PATH/app/(app)/account/(dashboard)/commerce" \
  --scope "$PARTNA_FRONTEND_PATH/app/(app)/account/(dashboard)/shop" \
  --scope "$PARTNA_FRONTEND_PATH/app/api/shopify"                      # ~251 KB

$A --category cross-repo --name dead-affiliates-booking --bundle cross-repo \
  --scope "$PARTNA_FRONTEND_PATH/features/affiliates" \
  --scope "$PARTNA_FRONTEND_PATH/features/booking" \
  --scope "$PARTNA_FRONTEND_PATH/app/(app)/account/(dashboard)/affiliates" \
  --scope "$PARTNA_FRONTEND_PATH/app/api/public/booking" \
  --scope "$PARTNA_FRONTEND_PATH/app/api/public/waitlist" \
  --scope "$PARTNA_FRONTEND_PATH/proxy.ts"                             # ~105 KB
```

**Stopping after Stage 2 is legitimate.** Stages 1–2 are 4 runs and cover the
drift you have actual evidence for. Stages 3–4 are coverage instruments.

---

## Stage 3 — frontend dead-code sweep (8 runs)

Everything Stage 2 didn't already cover. ~1.97MB total across 434 TS/TSX files.

> **Prompt:**
> Run Stage 3 of `docs/superpowers/plans/2026-07-20-cross-repo-contract-audit-PROMPT.md`
> — the 8 frontend dead-code runs. Sequential, counts + paths only, stop on
> failure. Before confirming any "unused" finding, cross-check `knip` output
> (`knip.json` is configured at the frontend root) — where knip and the lens
> disagree, say so in the finding rather than picking a side silently. When all 8
> finish, give me a deduplicated summary ranked by severity.

```bash
F="$PARTNA_FRONTEND_PATH"
$A --category cross-repo --name fe-components-core --bundle cross-repo \
  --scope "$F/components/ui" --scope "$F/components/page-shells" \
  --scope "$F/components/tables"                                       # 294 KB
$A --category cross-repo --name fe-components-rest --bundle cross-repo \
  --scope "$F/components/fields" --scope "$F/components/feedback" \
  --scope "$F/components/lists" --scope "$F/components/charts" \
  --scope "$F/components/dialogs" --scope "$F/components/containers" \
  --scope "$F/components/section-header" --scope "$F/components/page-menu" \
  --scope "$F/components/summary" --scope "$F/components/controls"     # ~190 KB
$A --category cross-repo --name fe-dashboard --bundle cross-repo \
  --scope "$F/app/(app)/account/(dashboard)/settings" \
  --scope "$F/app/(app)/account/(dashboard)/onepage" \
  --scope "$F/app/(app)/account/(dashboard)/design" \
  --scope "$F/app/(app)/account/(dashboard)/overview" \
  --scope "$F/app/(app)/account/(dashboard)/contacts" \
  --scope "$F/app/(app)/account/(dashboard)/notifications"             # 278 KB
$A --category cross-repo --name fe-app-shell --bundle cross-repo \
  --scope "$F/app/(app)/account/(auth)" --scope "$F/app/(marketing)" \
  --scope "$F/app/api" --scope "$F/packages"                           # ~150 KB
$A --category cross-repo --name fe-lib-engines --bundle cross-repo \
  --scope "$F/lib/public-theme" --scope "$F/lib/chat-engine" \
  --scope "$F/lib/hooks"                                               # 223 KB
$A --category cross-repo --name fe-lib-core --bundle cross-repo \
  $(printf -- "--scope %s " "$F"/lib/*.ts "$F"/lib/*.tsx) \
  --scope "$F/lib/account"                                             # ~160 KB
$A --category cross-repo --name fe-features-site --bundle cross-repo \
  --scope "$F/features/sitepage" --scope "$F/features/design" \
  --scope "$F/features/settings"                                       # 198 KB
$A --category cross-repo --name fe-features-rest --bundle cross-repo \
  --scope "$F/features/analytics" --scope "$F/features/about" \
  --scope "$F/features/integrations" --scope "$F/features/auth" \
  --scope "$F/features/notifications"                                  # ~130 KB
```

---

## Stage 4 — backend dead-code sweep (12 runs)

Uses the **existing** `code-quality-slop` lens, not a new one — it is already
tuned for this repo's house style and its category 3 is exactly backend dead code.

> **Prompt:**
> Run Stage 4 of `docs/superpowers/plans/2026-07-20-cross-repo-contract-audit-PROMPT.md`
> — the 12 backend dead-code runs, using `code-quality-slop`. Sequential, counts +
> paths only, stop on failure. Enforce the lens's own rule strictly: **no
> "unused" claim without a repo-wide grep across `app/`, `routes/`, `config/`,
> `tests/`**. Also cross-reference Stage 1's `BACKEND-ONLY` bucket — a service
> that greps clean in this repo but is only reachable from an orphaned route is
> an `XREPO` category-5 finding, not a `SLOP` one.

```bash
S="--lens-file scripts/audit/lenses/code-quality-slop.md"
$A --category cross-repo --name be-platforms-a $S \
  $(printf -- '--scope %s ' app/Services/Platforms/[A-M]*.php)         # 334 KB
$A --category cross-repo --name be-platforms-b $S \
  $(printf -- '--scope %s ' app/Services/Platforms/[N-Z]*.php) \
  --scope app/Services/Platforms/Strategies --scope app/Services/Platforms/Registry \
  --scope app/Services/Platforms/Payloads --scope app/Services/Platforms/Normalizers \
  --scope app/Services/Platforms/Concerns                              # 261 KB
$A --category cross-repo --name be-design-media $S \
  --scope app/Services/Design --scope app/Services/Media               # 323 KB
$A --category cross-repo --name be-user-analytics $S \
  --scope app/Services/User --scope app/Services/Analytics             # 274 KB
$A --category cross-repo --name be-site-cache $S \
  --scope app/Services/PublicSite --scope app/Services/Site \
  --scope app/Services/Cache --scope app/Services/Notifications \
  --scope app/Services/Moderation                                      # 317 KB
$A --category cross-repo --name be-services-rest $S \
  --scope app/Services/Segments --scope app/Services/PreAccount \
  --scope app/Services/Auth --scope app/Services/Http \
  --scope app/Services/BotProtection --scope app/Services/Cloudflare \
  --scope app/Services/Streaming --scope app/Services/Profile \
  --scope app/Services/FeatureFlags --scope app/Services/Feedback      # ~211 KB
$A --category cross-repo --name be-controllers-user $S \
  --scope app/Http/Controllers/Api/User --scope app/Http/Controllers/Api/Staff  # 326 KB
$A --category cross-repo --name be-controllers-public $S \
  --scope app/Http/Controllers/Api/Platforms \
  --scope app/Http/Controllers/Api/PublicSite \
  --scope app/Http/Controllers/Api/Internal \
  --scope app/Http/Controllers/Api/Webhooks                            # 323 KB
$A --category cross-repo --name be-jobs $S \
  --scope app/Jobs --scope app/Observers --scope app/Mail \
  --scope app/Notifications                                            # 339 KB
$A --category cross-repo --name be-models-console $S \
  --scope app/Models --scope app/Console --scope app/Policies \
  --scope app/Enums --scope app/DTOs --scope app/Support --scope app/Rules  # 342 KB
$A --category cross-repo --name be-http-shapes $S \
  --scope app/Http/Requests --scope app/Http/Resources                 # 282 KB
$A --category cross-repo --name be-wiring $S \
  --scope routes --scope config --scope app/Providers                  # 344 KB
```

**Why wiring is last but not optional:** vestigial config keys, dead routes, and
orphaned service-provider bindings are where removed subsystems leave their final
traces. It is also the run most likely to corroborate Stage 1's `BACKEND-ONLY`
bucket.

---

## Rules

- **Sequential only.** Never two `audit.sh` at once — they share the `claude` CLI
  budget.
- **Stages are stop-anywhere.** Stage order is value-per-token descending.
- **Every scope group is measured** and sits under the 350KB scan-recall ceiling.
  Groups marked `~` are computed from directory sums; re-measure if a group
  grows. Recall is measured at 10/10 at 2KB vs 8/10 at 669KB — adding files to a
  run takes attention away from the files already in it.
- **Absence claims need grep, not judgement.** The scan tier only sees scoped
  files, so it cannot distinguish "no consumer exists" from "the consumer is in a
  file I wasn't given". Every "not connected" / "unused" finding must cite a
  repo-wide grep across **both** repos.
- A failed run writes **no** audit and exits non-zero — if `CONSOLIDATED.md`
  exists, adjudication genuinely succeeded.
- Consider `--keep-drafts` on the long stages; it is cheap insurance against a
  session-limit failure mid-campaign.

---

## After the baseline

Do not re-run this campaign wholesale. Re-run **Stage 1 only** after any change
to routes, Resources, or capabilities — it is 2 runs and catches new drift at the
moment it is introduced. Stages 3–4 are baselines; audit the delta
(`--changed-since <ref>`) instead.

Once Stage 0 ships and Stage 1 has run clean once, fold Stages 1–2 into
`scripts/audit/campaigns.md` as "Campaign 7 — Cross-repo contract" so it joins the
Gate A/Gate B priority tables.
