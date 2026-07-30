# Execute prompt — three open items: reviewer PII on the public wire, a P3 tail, a docs triage

> ## ✅ EXECUTED AND FULLY CLOSED 2026-07-30
>
> All three units resolved. `271-PRIV-2` is now ticked — **both legs settled the same day.**
>
> | Unit | Outcome |
> |---|---|
> | **1** — reviewer PII | Investigated. **Nested `photos[].authors` leg SHIPPED** @ `31ccf162` — `ThirdPartyPii::stripNested()` runs after `array_intersect_key` in BOTH gates. **Public-wire `reviews`/`reviewSummary` leg DECIDED — kept** (no code change), with a mandatory `LEGAL-2` follow-through. |
> | **2** — 19 dead `whereNotNull` | **No action**, deliberately. All 19 left in place per CLAUDE.md's "absorb the P3 tail, never schedule it". |
> | **3** — plans triage | **14 deleted, 4 kept** @ `4e664284`. Kept: `weekly-db-backup` + `worker-static-analysis` (never shipped); `dast-security-implementation` + `k6-load-testing` (shipped as code, but their open boxes are actions on Josh — baseline triage, joint measured run). |
>
> **Decision B — DECIDED 2026-07-30: `reviews`/`reviewSummary` STAY on the public wire.** Josh's explicit
> call, taken WITHOUT resolving the Astro render question (which remains unanswered — the Astro app is a
> separate repo, not checked out on this machine; `partna-frontend-main` is `commet-web`, the Next.js
> dashboard, and no `astro.config.*` exists locally). Deciding to keep made the render question moot: it
> only mattered for costing a removal. **No code change — the current behaviour IS the decision.**
>
> **⚠️ The condition attached to that decision:** publishing third-party reviewer identity is only
> defensible if the privacy policy discloses it. `LEGAL-2 · P0` in
> `docs/checklists/launch-readiness-checklist.md` now carries a mandatory sub-item spelling out exactly
> what must be covered (APP 6 secondary-purpose / second-subject processing), due before the first pilot
> customer signs. **A generic policy template will not cover this.**
>
> **Options as framed, for the record:** B1 drop `reviews`, keep `reviewSummary`+`rating`+`reviewCount` ·
> **B2 status quo — CHOSEN** · B3 drop both · **B4 flipping the `reviews` display-toggle default —
> REJECTED** (one toggle key covers `reviews`/`reviewSummary`/`rating`/`reviewCount`, so it takes the star
> rating with it and silently un-publishes for every existing connection) · **B5 redact author name but
> keep review text — REJECTED** (most likely of all options to breach the Places API terms;
> `authorAttribution` exists *because* Google requires attribution on displayed reviews).
>
> **Why the nested fix landed at the read boundary:** the two frozen parity tests in
> `GoogleBusinessDetailsTest` assert `GoogleBusinessFetch` *storage* behaviour, so scrubbing on the way
> OUT left them green with a zero-line diff. A write-boundary fix would have forced rewriting both and
> collided with `BackfillClaimedGoogleBusinessReviewsCommand`, which exists specifically to re-fetch
> reviews for claimed accounts.

Paste the block below into a **fresh Claude Code session** at the repo root.

Triage was done 2026-07-30 at `61c4dbfb` — **this prompt carries the findings, so the session does
not repeat them.** The three units are unrelated and deliberately ordered by risk, not by size:

| Unit | Nature | Gate |
|---|---|---|
| 1 | Third-party PII on the public sitepage wire **and** in DSAR exports | 🔴 **privacy/product — STOP for Josh** |
| 2 | 19 provably-dead `whereNotNull` clauses | ⚠️ **do NOT sweep** — opportunistic only |
| 3 | 18 plan files, several shipped | 🟡 confirm the exact delete list first |

Unit 1 is the only one that should produce a branch. Units 2 and 3 exist mainly to stop a future
session "helpfully" doing the wrong thing with them.

---

## The prompt

> Three open items on `development`, triaged 2026-07-30 at `61c4dbfb`. Work them in order. Unit 1 is
> the only one with real substance; units 2 and 3 are mostly a decision and a confirmation.
>
> 🔴 **Other Claude sessions are active on this repo.** `development` moved four times in one
> afternoon on 2026-07-30. Before editing, run `git worktree list`, check each sibling worktree's
> `git status`, and base any branch on `origin/development` **explicitly** — `origin/HEAD` is
> `production` here, so tooling that defaults to the default branch bases you on the wrong ref.
>
> ---
>
> ### Unit 1 — 🔴 Google reviewer PII (271-PRIV-2, public-wire leg) — INVESTIGATE, THEN STOP
>
> **Do not change the allowlists before the decision gate below. This is a product and legal call,
> not a lint fix.**
>
> #### What is actually exposed (verified 2026-07-30, do not re-derive)
>
> `GoogleBusinessService.php:291-299` stores, per review, capped at 5:
> `author` (`authorAttribution.displayName`), `authorUri` (a persistent link to the reviewer's Google
> contributor profile), `authorPhoto`, `rating`, `text` (verbatim), `publishedAgo`, `publishTime`.
>
> `PublicIntegrationConnectionResource::ALLOWLIST['google-business']` carries `reviews` and
> `reviewSummary`, so all of the above is on the public sitepage wire.
>
> **The part that is easy to miss — and that changes the shape of the fix.**
> `GoogleBusinessService.php:304-315` also builds `photos[].authors`, a list of Google contributor
> **display names**. `photos` is allowlisted on BOTH surfaces, and BOTH filters
> (`PublicIntegrationConnectionResource::filterPayload()` line 390 and
> `DsarPayloadFilter::filter()` line 180) are a **top-level** `array_intersect_key`. Nested keys are
> never inspected. Verified by running the real filter:
>
> ```
> DSAR export keys: name, photos
> DSAR reviews present? no
> DSAR photos[0].authors: ["Jane Doe","Bob Smith"]
> ```
>
> So third-party names reach the **DSAR export as well as the public wire**, through a path that
> removing `reviews`/`reviewSummary` does not touch. The DSAR leg of 271-PRIV-2 is closed only for
> the four top-level `THIRD_PARTY_KEYS`; `photos[].authors` is a separate, still-open hole on both
> surfaces. **Any fix that only removes two top-level keys leaves this behind.**
>
> #### The decisive question — answer it FIRST, it may collapse the whole trade-off
>
> **Does the Astro sitepage actually render `payload.reviews`?**
> `SitepageDataResolverService.php:268` notes "Reviews is no longer presented as its own page
> (2026-07-13)", so this may already be dead weight on the wire. If nothing renders it, removal is a
> **no-visible-change fix** and every argument below evaporates.
>
> The frontend is a separate repo and is **STRICTLY READ-ONLY** from a backend session — never clone,
> pull, commit or push. Read it one of these two ways:
> - `gh search code --repo PartnaAu/partna-frontend 'reviewSummary'` (also: `authorPhoto`, `authorUri`,
>   `.reviews`, `photos.*authors`), or
> - the clean checkout at `/Users/joshuahunter/Herd/Side Street/partna-frontend-main` — **re-confirm it
>   is still on clean `main` before trusting it.** Its sibling
>   `/Users/joshuahunter/Herd/Side Street/partna-frontend` is a TRAP: it sits on a ~2-month-stale test
>   branch and once produced ~56 invalid findings. Never point a scan at it.
>
> #### Constraints that shape any remedy (already established — do not relitigate)
>
> 1. **Two frozen parity tests assert the CURRENT behaviour** —
>    `GoogleBusinessDetailsTest.php:360` (`keeps full reviewer data on refresh for a claimed (active)
>    owner (PRIV-1)`) and `:383` (`self-heals full reviewer data on the first refresh after claim`).
>    Any removal must consciously rewrite these, not delete them quietly. They encode Josh's 2026-07-21
>    call (`df0ea28c`) that exposure is gated by `is_published`, not claim status.
> 2. **The `reviews` display toggle is not a scalpel.** `DisplaySettingsFilter::SUPPRESSIONS` maps
>    `'reviews' => ['reviews', 'reviewSummary', 'rating', 'reviewCount']` — one toggle covers all four,
>    so flipping its default OFF also removes `rating` and `reviewCount`, needs
>    `PlatformRegistryServiceProvider` kept in lockstep, and silently un-publishes reviews for every
>    existing connection (default-ON semantics mean nobody has ever stored `reviews: true`).
> 3. **Redacting the author name while keeping the review text is the option most likely to breach the
>    Google Places API terms** — `authorAttribution` exists because Google requires attribution on
>    displayed reviews. Do not propose it as the "middle ground" without saying this.
> 4. `reviewSummary` is an **aggregate with no reviewer identity** and is already published as
>    first-party copy via `GoogleBusinessAutoSync.php:336`. It is a viable PII-free substitute.
>    `rating` and `reviewCount` survive every option.
> 5. `docs/checklists/launch-readiness-checklist.md:98` — `LEGAL-2 Privacy Policy` is still unchecked.
>    There is no published commitment to conform to, so **this decision sets the policy** rather than
>    following one. That argues for settling it before the pilot, not after.
>
> #### What to produce
>
> A short written recommendation covering: the answer to the render question (with the evidence), what
> `photos[].authors` should do on each surface, which of the options in §2-4 you recommend and why, and
> what the parity tests would have to become. **Then STOP for Josh's sign-off.** Under
> `scripts/audit/fix-flow.md`'s blocker gate, anything touching privacy/PII pauses regardless of how
> small the diff looks.
>
> If — and only if — the render question comes back "nothing renders it", say so plainly and note that
> the recommendation is then a no-visible-change removal, but still take the sign-off before writing it.
>
> ---
>
> ### Unit 2 — 19 dead `whereNotNull` clauses — ⚠️ DO NOT SWEEP
>
> **This unit is a decision to leave things alone. Read it so you don't "fix" it.**
>
> A `whereNotNull('x')` sitting in an AND-chain beside any comparison on the same column
> (`where('x', '<', …)`, `!=`, `<>`, `whereIn`, `whereBetween`) is **always redundant**: SQL's
> three-valued logic makes `x < $cutoff` evaluate to NULL — not TRUE — for a NULL row, so the
> comparison already excludes it. Verified directly with `sqlite3`, and it holds in Postgres too.
>
> One such clause was removed on 2026-07-30 (`3d85dc5c`, `IntegrationConnection::scopeStrandedPending`)
> because a migration comment had wrongly claimed the scope "has to carry" it — a false belief someone
> was relying on. The remaining 19 carry no such belief, and removing them changes nothing.
>
> **8 of them are backed by a partial index whose predicate is literally `WHERE (col IS NOT NULL)`** —
> `feature_flag_overrides.expires_at`, `user_handle_aliases.expires_at`,
> `site_subdomain_aliases.expires_at`, `moderation.cases.sla_due_at`, `ingest.sources.in_flight_since`.
> Postgres's predicate prover *should* still choose those indexes without the explicit clause (a strict
> operator implies its argument is NOT NULL), but that is unverified against a live `EXPLAIN`, and
> being wrong costs a sequential scan on a prune job. **Leave these alone:**
> `PruneExpiredHandleAliases:29,31`, `PruneExpiredFeatureFlagOverridesCommand:21`,
> `FeatureFlagService:328`, `ModerationSlaScanCommand:29`, `SourceScheduler:210,226`, `RunSourceJob:121`.
>
> The other 11 are pure noise: `SiteSubdomainAlias:68`, `UserHandleAlias:67`, `PruneNotifications:37,78`,
> `PruneExpiredPreAccountBuilds:46`, `ServicesVisibility:30`, `WorkplaceVisibility:30,31`,
> `BackfillUserKvEntries:41`, `BackfillSubdomainKvCommand:54`,
> `BackfillPreviousWebsiteContentScanCommand:47`, `AccountDeletionService:1247`.
>
> **Do not open a branch for these.** CLAUDE.md is explicit: "absorb the P3 tail, never schedule it"
> and "never run a clear-the-backlog campaign". Zero behaviour change across 15 files — one of them a
> PII deletion path — is churn. Delete one only when you already have that file open for real work, and
> mention it in that commit's body. If you are reading this prompt as your task, the correct action for
> unit 2 is **no action**.
>
> ---
>
> ### Unit 3 — `docs/superpowers/plans/` triage — CONFIRM THE LIST, THEN DELETE
>
> 18 files. The repo convention (verified against git log — commits `74887df6`, `581edbf9`, `20e451c9`)
> is that a shipped plan is **deleted**, not moved to `plans/closed/`. That folder exists but holds one
> file and is not the dominant path. **Specs under `docs/superpowers/specs/` are permanent** and are
> never deleted for lifecycle reasons — only if the feature itself was ripped out. Do not touch specs.
>
> Candidates, by last-touched date — **verify each against git log and live code before proposing it,
> not against the plan's own status header, which goes stale:**
>
> ```
> 2026-07-18  2026-07-17-weekly-db-backup.md
> 2026-07-22  2026-07-21-email-deliverability-hardening-PROMPT.md
> 2026-07-22  2026-07-21-phpstan-analyse-gate-repair-PROMPT.md
> 2026-07-22  2026-07-21-staff-category-and-bootstrap-race-PROMPT.md
> 2026-07-23  2026-07-23-signup-testing-repairs.md
> 2026-07-23  2026-07-23-worker-async-pilot-PROMPT.md
> 2026-07-25  2026-07-17-dast-security-EXECUTE-PROMPT.md
> 2026-07-25  2026-07-24-launch-check-3-cutover-PROMPT.md
> 2026-07-25  2026-07-25-booking-incomplete-connection.md
> 2026-07-25  2026-07-25-fetch-budget-reentrancy-and-refresh-PROMPT.md
> 2026-07-25  2026-07-25-sitepage-cache-freshness-rollout.md
> 2026-07-25  2026-07-25-sitepage-cache-freshness.md
> 2026-07-26  2026-07-17-dast-security-implementation.md
> 2026-07-26  2026-07-26-k6-load-testing-EXECUTE-PROMPT.md
> 2026-07-26  2026-07-26-k6-load-testing-MEASURED-RUN-EXECUTE-PROMPT.md
> 2026-07-26  2026-07-26-k6-load-testing.md
> 2026-07-30  2026-07-30-authz-matrix-tier1.md
> 2026-07-30  2026-07-30-worker-static-analysis.md
> ```
>
> Corroborating "shipped" memories exist for DAST (`project_dast_security_shipped`), k6
> (`project_k6_harness_guard_tests_shipped`), worker-async (`project_worker_async_pilot_shipped`),
> launch-check (`project_launch_check_suite`) and the authz matrix — but **confirm in the repo**, not
> from memory: memories are point-in-time and some are days stale.
>
> ⚠️ **The two 2026-07-30 files may still be in flight** — another session was actively working the
> authz matrix and worker static analysis that day. Check `git worktree list` and each sibling's
> `git status` before proposing either for deletion.
>
> **Present the exact file list to Josh and wait for explicit confirmation before deleting anything.**
> It is a bulk destructive action, recoverable via git history but annoying, and the convention memory
> requires the confirmation step.
>
> ---
>
> ### Verification (unit 1 only — units 2 and 3 change no code)
>
> ```bash
> composer analyse                       # must stay 0
> vendor/bin/pint --test                 # WHOLE REPO
> php artisan test tests/Feature/Platforms tests/Feature/Security
> php artisan test tests/Feature/Platforms/Registry/DsarAllowlistCoverageTest.php
> ```
>
> Known traps:
> - **`composer test` may die before running any tests** — it runs `guard:no-unsafe-migrations` first,
>   which has been red on `development` for reasons unrelated to your change. Use `php artisan test
>   <paths>` directly. A red `Run tests` step is NOT evidence about your change; diff the failed-test
>   set and the passed COUNT against the prior CI run before concluding anything.
> - `DsarAllowlistCoverageTest` asserts no allowlist carries a top-level third-party key. It will NOT
>   catch a nested one. If unit 1 lands a nested-key fix, that test needs a nested case too.
>
> ### Definition of done
>
> - Unit 1 **investigated and reported, not implemented** — with the render question answered from real
>   frontend evidence, and an explicit position on `photos[].authors` for both surfaces.
> - Unit 2 — **no code change**. State in your report that you deliberately left all 19.
> - Unit 3 — exact file list presented and confirmed before any deletion.

---

## Notes for whoever pastes this

- **Unit 1's `photos[].authors` finding is new as of 2026-07-30** and is not in the original 271-PRIV-2
  write-up, which predates it. It means the DSAR half of that finding is *not* fully closed, contrary
  to what `project_google_reviewer_pii_open` said before this date — that memory has been corrected.
- Expect `development` to have moved. Re-run the verified greps if anything looks off; the line numbers
  in unit 1 were accurate at `61c4dbfb`.
- Units 2 and 3 will look like easy wins to a fresh session. They are not wins — one is a deliberate
  no-op and the other is destructive. That is why they are written up at all.
