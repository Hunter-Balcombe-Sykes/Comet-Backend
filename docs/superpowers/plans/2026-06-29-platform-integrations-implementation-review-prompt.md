# Review Prompt — Platform Integrations Registry & Strategy Redesign (full implementation review)

> **How to use:** Paste the section below (everything under the line) into a fresh Opus session in
> this backend repo *after* implementation is complete. It is a self-contained instruction set. It is
> **report-only** — it must not edit code, only produce a graded review. Run from a **default output
> style** (not explanatory) so no narration leaks into the report.

---

You are the lead reviewer for a large, completed backend refactor. Your job is to verify — by **reading
the actual code line by line**, not skimming — that the implementation of the *Platform Integrations
Registry & Strategy Redesign* is correct, faithful to its plans, non-breaking, secure, scalable, and a
durable foundation that won't need structural rework unless third-party platforms themselves change.

This was a deliberate, staged, multi-week refactor of the single most coupled area of the codebase. Treat
it with the seriousness of a pre-merge architectural sign-off. A shallow "looks good" is a failure of this
task. Every conclusion you reach must be backed by a specific `file:line` citation and, where it matters, a
verbatim code quote. **If you did not read the file, you may not make a claim about it.**

## Ground truth — read these first, in full, yourself (do not delegate this step)

1. **Design spec (the contract you are grading against):**
   `docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md`
2. **The six staged implementation plans** (read each one's end-state + exit criteria):
   - `docs/superpowers/plans/2026-06-27-platform-integrations-registry-spine.md`
   - `docs/superpowers/plans/2026-06-27-platform-integrations-link-only.md`
   - `docs/superpowers/plans/2026-06-28-platform-integrations-embed.md`
   - `docs/superpowers/plans/2026-06-28-platform-integrations-feed.md`
   - `docs/superpowers/plans/2026-06-29-platform-integrations-picker-shop.md`
   - `docs/superpowers/plans/2026-06-29-platform-integrations-bespoke-specials.md`
3. **Architectural ground truth:** `CLAUDE.md` (this repo) and `AI_CONTEXT.md` — especially the rules on
   `AccountCapabilities`, Resource classes, Policy authorization, 403-vs-404, and the SQLite-vs-Postgres
   schema-drift warning.

From the design spec, extract the **falsifiable claims** the implementation must satisfy, including at
minimum:
- "One declaration per platform" — adding platform #31 is a single descriptor line: **no migration, no
  `match()` edit, no scattered changes.**
- "Typed payload boundary" — the ~29 scattered `data_get($row->payload, …)` sites and hand-rolled
  `is_array()` checks are **gone**; replaced by `readonly` DTOs with `fromArray()`/`toArray()`.
- "Kill the central `match()`" — `PlatformRefresher` iterates the registry
  (`foreach ($registry->refreshable() …)`), and `ProviderDetector` is registry-driven.
- "Zero observable change" — every route + JSON response is **byte-identical**; the golden-master test
  proves it.
- "The lone schema change" — a single `DROP CONSTRAINT` migration on
  `site.platform_connections_platform_check`; registry validation replaces the DB CHECK.
- Capability gating baked in via `PlatformDescriptor::availableFor(User)`.
- Seams defined but **not** implemented: `OAuthConnect`, `ApiKeyConnect`, `WebhookRefresh`, no
  `platform_tokens` table.

> **Known caveat — confirm the end-state, don't assume it.** At authoring time, the centralizer-collapse
> step (registry-driven `PlatformRefresher`/`ProviderDetector` + the `DROP CONSTRAINT` migration) was the
> *last* and possibly-deferred batch. You must determine for yourself whether it actually landed. If it
> didn't, that is not automatically a defect — but you must state plainly whether the design's headline
> "kill the `match()` / zero-migration" goals are **met, partially met, or outstanding**, and grade the
> foundation accordingly. Do not paper over a gap, and do not invent one.

## Method — two-tier, gather-then-judge

Use **Haiku agents for breadth (reading and inventorying)** and **Opus (you, plus Opus sub-reasoners where
needed) for depth (judgment)**. Never let a Haiku agent reach an architectural verdict; never let yourself
make a factual claim about a file a Haiku agent hasn't fully read or you haven't opened.

### Phase 1 — Haiku fan-out: build complete, cited inventories (parallel)

Dispatch Haiku `Explore`/general-purpose agents, **one scoped slice each**, in parallel. Every agent must
**read each assigned file end-to-end** (not grep-and-guess) and return a structured inventory with
`file:line` anchors and short verbatim quotes. No agent may summarize a file it only partially read; if a
file is too large, it reads it in chunks until complete. Suggested slices:

1. **Registry spine** — `app/Services/Platforms/Registry/{PlatformRegistry,PlatformDescriptor,PlatformCategory}.php`.
   Return: every public method + signature; how descriptors are built/registered; the preset factories
   (`linkOnly`, `oEmbed`, etc.); how `availableFor`, `refreshable()`, `keys()` work.
2. **Strategy contracts + implementations** — everything under
   `app/Services/Platforms/Strategies/`. Return: each interface and its method signature; every concrete
   strategy; confirm `OAuthConnect`/`ApiKeyConnect`/`WebhookRefresh` are seam-only (interface, no
   implementation, no caller).
3. **Payload DTOs** — every file in `app/Services/Platforms/Payloads/`. For each DTO return: properties +
   types; the full body of `fromArray()` and `toArray()`; whether hydration is lenient (missing→null,
   extra→ignored); any internal-only fields (e.g. Instagram `_folder`).
4. **The full platform inventory** — enumerate every platform the system supports (descriptor list, the
   archetype tables in the plans, the old CHECK constraint values). Produce the canonical platform list and
   each platform's archetype + strategy mix + payload DTO + Resource.
5. **Controllers** — `app/Http/Controllers/Api/Platforms/*`. Return: which are the retained bespoke three
   (Instagram/Fresha/Shop), which is the generic registry-driven controller, which old per-platform
   controllers were *meant to be deleted* — and whether any dead/orphaned ones remain.
6. **Centralizers** — `PlatformRefresher.php`, `ProviderDetector.php`, `ShopProviderDetector.php`,
   `RefreshController.php`. Return the verbatim dispatch mechanism (is it a `match()`/hard-coded list, or
   registry iteration?) with line numbers.
7. **Resources + Requests** — `app/Http/Resources/Platforms/*`, `app/Http/Requests/Platforms/*`. Return:
   which Resources consume typed DTOs vs. still touch raw arrays; the validation rule that enforces
   `platform ∈ registry`.
8. **Untyped-access sweep** — grep the whole `app/` for residual `data_get($…->payload`, `$…->payload[`,
   raw `->payload` array indexing, and hand-rolled `is_array($…payload)`. Return every hit with `file:line`
   and 2 lines of context, and cross-check against `tests/Feature/Platforms/NoUntypedPayloadAccessTest.php`
   to see what it actually guards.
9. **Migrations + schema** — `supabase/migrations/` touching `platform_connections`. Return the CHECK
   constraint history and whether a `DROP CONSTRAINT` migration exists; confirm no new per-platform tables.
10. **Tests** — inventory every test under `tests/**/Platforms/**` and the golden-master fixtures. Return
    what each guard test asserts (golden master, registry coverage, `PlatformInRegistryRule`, archetype
    parity, fetch parity, public allowlist).

Wait for all inventories. Then **you** spot-read the highest-risk files yourself to confirm the Haiku
reports — at minimum `PlatformRegistry.php`, `PlatformDescriptor.php`, every payload DTO's `fromArray`,
`PlatformRefresher.php`, `ProviderDetector.php`, and the golden-master test. Never trust an inventory you
haven't sampled.

### Phase 2 — Opus judgment passes (you reason; spawn Opus sub-reviewers for independent second opinions on the two hardest dimensions)

Grade each dimension below **Pass / Concerns / Fail**, with cited evidence. For "Concerns"/"Fail", give the
exact `file:line`, why it's wrong, the blast radius, and a concrete fix.

**A. Plan adherence.** Does the code match each of the six plans' declared end-state and exit criteria? Walk
the design's Locked Decisions (§3) and Architecture (§4–§9) and confirm each is realized. Flag every
deviation — and judge whether the deviation is an *improvement*, a *defensible call*, or a *regression from
the agreed design*.

**B. Non-breaking / contract fidelity (highest priority — spawn an independent Opus sub-reviewer).** The
design's central promise is "zero observable change." Verify the golden-master test genuinely covers every
integration read endpoint (does its fixture count equal the registered integration read-routes? could an
endpoint silently escape the snapshot?). Trace at least 3 platforms end-to-end — old payload shape →
`fromArray` → typed code → `toArray` → Resource → JSON — and confirm the output is byte-identical to what
the pre-refactor code emitted. Hunt specifically for fields a DTO might **drop** or **reorder**.

**C. Typed-boundary completeness.** Confirm the untyped `data_get`/`is_array` access is actually eliminated
(Phase-1 sweep #8), not merely moved. Every residual hit is a finding. Confirm each DTO is the *honest
complete schema* — it carries every field its Resource emits **and** every internal field the controller/
scraper writes. A DTO that silently omits a stored field is a data-loss bug.

**D. Decentralization.** Confirm `PlatformRefresher` and `ProviderDetector` no longer hard-code platform
lists. If a `match()`/hard-coded arm remains, state exactly what still requires editing to add a platform,
and reconcile against the design's "kill the `match()`" goal and the deferral caveat above.

**E. Scalability — the "platform #31" test.** Concretely: to add a new URL-based platform, exactly what must
change? Walk it. The design promises *one descriptor line, zero migration, zero refresher/detector edits.*
If reality requires more (a migration, a controller, a `match()` arm, a Resource, a CHECK edit), the
foundation has not met its core goal — say so and quantify. Also assess: does the registry scale to 100
platforms without an O(n) hotspot, a boot-time cost, or a god-file?

**F. Security (spawn an independent Opus sub-reviewer).** Cover:
   - **SSRF / scraper egress** — connect/fetch flows accept user-pasted URLs and fetch them. Confirm
     host-allowlisting / `SafeUrlFetcher`-style guards are present and applied on **every** new fetch path
     (Instagram mirror was a prior CRITICAL SSRF — verify the new strategy layer didn't reintroduce an
     unguarded fetch). Image/content-type validation on rehosted media.
   - **Authorization** — every connect/refresh/disconnect/read path authorizes via a Policy
     (`authorizeForUser`, not inline `abort_unless`); no tenant can touch another's connection.
   - **Capability gating** — `availableFor()` is actually consulted at the connect flow, dashboard list,
     and public render — not defined-but-unused.
   - **Enumeration** — public/unauthenticated endpoints return **404** (not 403) for missing/inaccessible
     resources; the public allowlist (`PublicIntegrationAllowlistTest`) restricts what's exposed.
   - **Dropped DB CHECK** — confirm app-level validation (`PlatformInRegistryRule` + registry gate) fully
     covers what the CHECK used to, on **both** SQLite and Postgres (per the CLAUDE.md drift warning).
   - **Secrets / tokens** — no platform tokens or API keys logged, serialized into payloads, or returned.

**G. Durability & "is this the best idea?"** Step back as a staff engineer. Pressure-test the design's
durability table (§12): for each named future (platform #31, paid-tier gating, upstream API change, a real
OAuth platform, a webhook platform) confirm the seam genuinely makes it additive — read the seam interfaces
and verify they'd actually accommodate the future without restructuring. Then give an honest critique: is
the descriptor + strategy spine the right abstraction, or is there hidden coupling, an over-engineered seam
with no consumer, a leaky abstraction (e.g. the "generic" controller riddled with per-platform `if`s), or a
simpler design that would have been better? Name anything you'd change before this becomes load-bearing.

**H. Code quality & hygiene.** Dead code (old controllers/services that should have been deleted), orphaned
references, `match()`/switch leftovers, comment quality per CLAUDE.md's bar, naming consistency, and any
`ShouldQueue` job missing `$backoff`/`->afterCommit()` discipline. Confirm no Laravel migration files were
created (Supabase-only rule).

### Phase 3 — Verification (evidence, not assertion)

Run and report actual output (do not claim "tests pass" without showing it):
- `composer test` (or the scoped `php artisan test tests/Feature/Platforms tests/Unit/Platforms`) — full
  suite green. Per CLAUDE.md, run tests in the **main checkout**, not a worktree, and never concurrently
  with another test runner.
- The specific guard tests by name: golden-master contract, `RegistryCoverageTest`,
  `PlatformInRegistryRuleTest`, `NoUntypedPayloadAccessTest`, the archetype/fetch parity tests,
  `PublicIntegrationAllowlistTest`, `PlatformResourceContractTest`.
- `php artisan pint --test` for style; `composer analyse` if Larastan is wired.
- Note the SQLite-vs-Postgres caveat: a passing SQLite suite does **not** prove a NOT NULL/CHECK-bound
  write is safe on Postgres — for any constraint-bound payload write, verify against the actual
  `supabase/migrations/` DDL, and call out anything that could be green in CI but 500 on real Postgres.

### Phase 4 — Synthesis (you, Opus)

Produce one report with:
1. **Verdict** — one line: is this a durable, ship-ready foundation? (Yes / Yes-with-fixes / No.)
2. **Scorecard** — dimensions A–H, each Pass/Concerns/Fail with a one-line justification.
3. **Findings** — tiered **P0 (breaks contract/security/data-loss) → P1 → P2 → P3 (polish)**. Each finding:
   `file:line`, verbatim evidence, why it's wrong, blast radius, concrete fix. No finding without a citation.
4. **Plan-adherence ledger** — per plan (spine, link-only, embed, feed, picker-shop, bespoke-specials):
   fully realized / deviated (how) / incomplete (what's left).
5. **Design-claim ledger** — each falsifiable claim from the spec marked Met / Partial / Unmet, with proof.
6. **Durability assessment** — the honest "best idea?" critique and what you'd change before it's load-bearing.
7. **What was NOT reviewed** — be explicit about any file or path you did not fully read, so the gap is
   visible rather than hidden.

## Hard rules for this review
- **No claim without a citation.** Every assertion ties to `file:line` (+ quote for the important ones).
- **Read, don't skim.** Whole files for the spine, descriptors, DTOs, centralizers, and golden master.
  Grep is for *finding*, never for *concluding*.
- **Report only.** Do not modify code. If you find a P0, describe the fix; don't apply it.
- **Distinguish "deferred by design" from "broken."** The centralizer-collapse / `DROP CONSTRAINT` batch
  may be intentionally outstanding — verify against the plans before grading it a defect.
- **Be a skeptic, not a cheerleader.** Your value is the problem you catch, not the praise you give. If it's
  genuinely solid, say so plainly — but only after you've tried to break it.
