# DAST security scanning — design

**Date:** 2026-07-17
**Status:** Approved, pending implementation plan
**Revised:** 2026-07-17 — active lane owns an isolated bring-up (was preflight-only); two-identity auth for IDOR; `wcvs` promoted to v1; local≠prod RLS fidelity caveat added; edge/launch-check assert overlap flagged.
**Related:** assurance flows map (the "dynamic × code" cell), `scripts/launch-check/` (sibling suite member — assert suite not yet built), `scripts/audit/` (fix-flow consumer of findings)

## Problem

Partna has four assurance flows, modelled as a 2×2 of *code-vs-system* × *static-vs-dynamic*:

| | Static | Dynamic |
|---|---|---|
| **Code** | CI + audit pipeline | **← empty: DAST** |
| **System** | launch-check | k6 load |

Every existing flow reads code, config, or throughput. **None sends a hostile HTTP
request to the running app and inspects the response.** That is DAST's job, and it is the
one unfilled cell. Nothing today would catch a reflected injection, an authorization
bypass reachable only at runtime, a missing security header on the live edge, or a cache
deception that leaks one visitor's response to the next.

This spec builds `scripts/dast/` — a committed, re-runnable member of the assurance suite
(sibling to `scripts/launch-check/` and `scripts/audit/`) that closes that cell before
pilot.

## Constraints that shape the design

1. **Deployed dev serves live traffic.** Per CLAUDE.md, the development Laravel Cloud env
   serves **both** `dev-api.partna.au` and `api.partna.au`, backed by the live dev
   Supabase. Active DAST *mutates and fuzzes* — pointing an aggressive scan at that env is
   fuzzing production, which is unacceptable.
2. **Two dynamic surfaces with opposite needs.** The Laravel JWT API (`api.partna.au`) and
   the Cloudflare Worker + Cache API in front of the Astro sitepages
   (`<handle>.partna.au`) have different threat models. The API's high-value findings
   (injection, authz, mass-assignment) are *destructive to test* but *origin-only*. The
   Worker's high-value findings (cache deception/poisoning, headers, 301-alias leaks) are
   *edge-specific* but *non-destructive to test* (crafted GETs, not mutations).
3. **Local stack bring-up is fragile — so isolate it, don't avoid it.** Fresh-DB
   provisioning is documented-flaky and clashes with the sibling "Comet" local stack on
   ports 54321–54327. Rather than depend on a developer hand-starting the stack — which
   guarantees the active lane rots — the active runner **owns an isolated bring-up**: a
   project-scoped `supabase start` on a port offset that dodges Comet, with retried start
   and `trap`-guaranteed teardown. The highest-value findings live in the active lane, so
   its stack must come up reliably without a human, or the lane silently stops running.

## Non-goals

- **Ephemeral throwaway *cloud* scan environment.** A disposable Supabase **cloud** project
  + Laravel Cloud env + Worker/KV/domain is the textbook "most realistic" target, but for a
  pre-pilot with no customers it is a sub-project of its own (and provisioning is flaky).
  Rejected in favour of the local-active / edge-non-destructive split below. Note this is
  distinct from the active lane's **local** isolated bring-up (constraint 3) — that is a
  scripted local stack the runner owns and tears down, not a provisioned cloud environment.
- **Per-PR CI gating.** DAST is slow, needs a running target, and (active lane) needs a
  local stack. It is a manually- and cron-invoked suite member, not a `ci.yml` job.
- **Frontend / monorepo coverage.** Separate effort, unchanged by this spec.
- **Business-logic abuse chains.** Those need a human pentest post-launch; DAST tooling
  finds classes of bug, not multi-step logic abuse.

## Architecture — two lanes

The surface split (constraint 2) chooses the shape: **active lane** runs destructive scans
against a **local** stack where mutation is free; **edge lane** runs non-destructive probes
against the **real deployed host** where edge behaviour is faithful.

```
scripts/dast/
  run.sh                 # entrypoint: --only active|edge, --target <url>, --fail-on <sev>
  active/
    zap-active.sh        # boots ZAP docker, authenticated active scan
    seed-endpoints.sh    # php artisan route:list --json → ZAP URL/OpenAPI seed
    zap-context.yaml     # JWT auth replacer, scope, external-side-effect exclusions
  edge/
    nuclei-edge.sh       # nuclei vs target host
    templates/           # custom YAML = assert-style checks as reusable templates
    wcvs.sh              # v1 cache-deception scan (Web Cache Vulnerability Scanner)
  baseline/
    zap-baseline.json    # triaged/accepted active-lane findings (keyed alert + URL)
    nuclei-baseline.txt  # triaged/accepted edge findings (keyed template-id @ matched-at)
  .env.example           # ZAP_TARGET_LOCAL, EDGE_TARGET, SUPABASE_LOCAL_JWT, …
  README.md
```

Output for every run lands in `audits/dast/<date>/REPORT.md` (a merged human view)
alongside the raw scanner artifacts (ZAP JSON/HTML, Nuclei JSONL), matching
`launch-check`'s `audits/launch-check/<date>/REPORT.md` convention.

### Tooling

ZAP and Nuclei are different instruments, one per lane:

- **ZAP** is a *fuzzer* — it mutates parameters and watches for injection/authz breakage.
  It owns the **active lane**. Nuclei cannot discover a novel SQLi.
- **Nuclei** is a *matcher* — it fires thousands of known-bad-pattern templates fast, with
  low false-positives. It owns the **edge lane**. ZAP cannot cheaply sweep thousands of
  known exposures, and its custom templates double as launch-check asserts.
- **`wcvs`** (Hackmanit Web Cache Vulnerability Scanner, Go, Docker-able) is a **v1 member
  of the edge lane**, not a flagged add-on. Cache deception/poisoning is the one threat
  class *unique to the Worker + Cache API architecture*, neither ZAP nor Nuclei covers it
  well, and Nuclei's cache templates are thin — so the marquee edge risk gets a first-class
  tool.

## Active lane (ZAP → local stack)

1. **Own an isolated bring-up.** The runner starts a project-scoped local stack on a port
   offset (dodging Comet's 54321–54327), with retried `supabase start` and a
   `trap`-guaranteed teardown, then health-checks it (supabase, `php artisan serve`, redis)
   before scanning. This reverses the earlier "never own bring-up" stance (constraint 3):
   the active lane holds the highest-value findings, so its stack must come up reliably
   without a human.
2. **Seed from routes.** `php artisan route:list --json` → the API endpoint list ZAP needs
   (a JSON API has no HTML links to spider). Transformed to a ZAP URL/OpenAPI seed.
3. **Auth — two identities, not one.** Mint **two** local Supabase JWTs for two seeded users,
   each owning known resources → ZAP replacer rules drive an authenticated context per
   identity, plus one unauthenticated pass over the public surface. Two identities are
   required to detect horizontal privilege escalation / IDOR (user A reading user B's site,
   media, enquiry) — the single highest-value runtime authz class for this app, and one a
   single-JWT scan structurally cannot find. The minted JWT claims must reproduce the prod
   shape (`sub`/`aal`/`amr`) or authenticated scans hit false 401/403 walls and under-test.
4. **Scan.** ZAP active-scan rules (SQLi, XSS, path traversal, command injection) + ZAP's
   access-control checks, run per identity and cross-identity (A's token against B's
   resources) to surface IDOR.

**Exclusion rule — the inversion.** The local DB is throwaway, so destructive *internal*
mutation is *free* and desirable (that is the whole reason the active lane is local).
Therefore `zap-context.yaml` excludes **only routes with external side effects** — vendor
API calls, real email sends, `SyncSubdomainToKvJob` KV writes — because those reach past
the local box even from a local request. It does **not** exclude ordinary mutating routes.

**Fidelity caveat — local authz ≠ prod authz.** Prod authorization depends on the
`app_backend` restricted DB role (audit schema SELECT/INSERT-only, `NOLOGIN` fail-closed
baseline) and Supabase RLS, reached through Supavisor. A local stack does not reproduce that
boundary faithfully, so the active lane can both *miss* authz bugs that only manifest under
the prod role and *flag* "vulnerabilities" that RLS would block in prod. A green active lane
means "no injection/authz class found against the app logic," **not** "prod RLS is proven."
Prod-role authz coverage stays a gap for a human pentest post-launch.

## Edge lane (Nuclei + wcvs → real host, non-destructive)

- **Config-driven target.** `EDGE_TARGET` defaults to deployed dev + a sample
  `<handle>.partna.au` now; re-pointed at the real prod host at pilot cutover via one env
  change, no runner edit. Rate-limit and tag-allowlist the run: the default target also
  serves live `api.partna.au` traffic and sits behind Cloudflare, so an unthrottled sweep
  can trip the WAF and skew results into false negatives (challenge pages read as clean).
- **Cache deception first.** `wcvs` targets the sitepage host (`<handle>.partna.au`) for
  cache deception + poisoning — the class unique to the Worker + Cache API front end and the
  edge lane's highest-value finding. Ships in v1.
- **Nuclei** runs a curated, tag-pinned set (exposures, misconfig, http-headers, ssl, cache)
  **plus custom `templates/`** encoding assert-style checks as reusable YAML:
  - `/.env` → 404, `/.git` absent
  - telescope / horizon gated
  - 404-not-403 on missing/inaccessible resources (enumeration guard)
  - handle/subdomain alias → **301** to canonical
  - cache-control correct on API responses vs sitepage responses
- **Non-destructive only.** GET/HEAD templates, no mutation → safe against live traffic.

**Overlap note — assert-style checks get one home.** The five assert templates above
duplicate what the (not-yet-built) launch-check probe suite is meant to assert. Maintaining
the same assertions in two tools with two baselines is a liability. Because launch-check's
assert suite doesn't exist yet, the implementation picks *one* home: either these live in
Nuclei `templates/` and the launch-check suite calls the edge lane, or launch-check gains a
remote `--target` mode and owns the asserts while Nuclei owns only CVE/exposure/cache sweeps
— **not both.**

## Findings, baseline & gating (baseline-after-triage)

Both scanners are noisy, so a committed suite member needs a *triaged* baseline — never
baseline first (that buries real bugs); triage real findings, then accept the residual.

- **First run:** everything surfaces into `REPORT.md` + raw artifacts. Triage: real bugs →
  the `execute audit` fix-flow (`scripts/audit/fix-flow.md`); confirmed false-positives /
  accepted-risk → `baseline/`.
- **Every run after:** the runner exits **non-zero only on NEW findings ≥ threshold**
  (`--fail-on high` default) not present in the baseline. That is what makes it a gate
  rather than a report.

**Stable keys matter.** ZAP baselines by *alert + URL*; Nuclei by *template-id @
matched-at*. The baseline files store those stable keys (not free text) so the run-to-run
diff is meaningful and doesn't churn.

## Cadence & integration

- **Active lane → manual only.** Needs a local stack; runs before launch and before large
  deploys. Never in per-PR CI.
- **Edge lane → weekly cron candidate.** Non-destructive + cheap; also fills the
  "continuous cadence: weekly CVE/secret scans" assurance gap. Same runner, `--only edge`.
- **Both stay out of `ci.yml`** — consistent with the "repeatable committed tool" delivery
  shape, not a CI gate.

## Testing the scanner itself

A silently-broken scanner reports "all clear" forever — the worst failure mode — so the
runner is proven to **catch a known-bad and fail the build**:

- **Canary test:** plant a deliberately-vulnerable fixture (a temp route reflecting
  unsanitized input for ZAP; a deliberately-exposed path for Nuclei), run the lane, assert
  it is flagged **and** the runner exits non-zero.
- **Clean + baseline test:** run against a clean target, assert green; add a finding to the
  baseline, assert it is suppressed on the next run.

## Open implementation questions (for the plan, not blockers)

- Exact ZAP seed format: `route:list --json` → ZAP context URLs vs a generated OpenAPI
  document. Decide during implementation based on which ZAP consumes most reliably.
- Local JWT minting for the **two** seeded identities: sign with the local Supabase JWT
  secret directly vs create local auth users and exchange. Prefer direct signing if the
  local secret is available to the runner; either way the two JWTs must carry distinct `sub`
  claims and own distinct seeded resources so the cross-identity IDOR pass has real targets.
- Curated Nuclei template subset: pin specific template tags/IDs (not "run everything")
  to keep edge-lane runtime and noise bounded.
- Where the assert-style edge checks live: Nuclei `templates/` (launch-check calls the lane)
  vs a launch-check remote `--target` mode (Nuclei drops the assert templates). Resolve
  alongside building the launch-check probe suite so the assertions don't get authored twice.

## Rollout

1. Build `scripts/dast/` with both lanes behind `run.sh --only`.
2. First active-lane run against the runner's own isolated bring-up (two seeded identities);
   triage → baseline.
3. First edge-lane run against deployed dev (Nuclei + `wcvs`); triage → baseline.
4. Add the edge lane to the weekly cron alongside the other continuous-cadence scans.
5. At pilot cutover, re-point `EDGE_TARGET` at the real prod host.
