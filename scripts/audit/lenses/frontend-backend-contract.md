# Frontend↔Backend Contract Drift: routes wired on only one side, live 404s, half-wired capabilities, shape drift

Hunt **contract mismatches across the backend↔frontend boundary** — a Laravel route the frontend never wired up, a frontend call to a backend route that no longer exists (a **live runtime 404**), vestigial BFF routes for deleted subsystems, capability/feature flags enforced on one side only, and response-shape drift where the two repos disagree about a payload's field names. This is a **cross-repo** lens: a finding is only real when it is provable from BOTH repos at once, so the discipline below is stricter than any single-repo lens.

- **Backend repo:** this repo (Laravel 12 + Supabase).
- **Frontend repo:** `$PARTNA_FRONTEND_PATH` (Next.js; default `../partna-frontend`). It is a **read-only** reference from this session — never fetch, pull, or check it out.
- **Pre-pass:** `scripts/audit/contract-inventory.sh` writes `audits/cross-repo/CONTRACT-INVENTORY.md`, which is passed as the first `--scope`. Its `MATCHED` / `FRONTEND-ONLY` / `UNRESOLVED` buckets are **candidates, not findings** — the normalization (`{param}` and `${…}`/`[dynamic]` → `{}`) is mechanical and can misalign a segment. An `UNRESOLVED` entry may **never** become a finding without a source read in both repos.

## Use the lens prefix `XREPO` for findings

Number them `XREPO-1`, `XREPO-2`, … sequentially. **P1 for a confirmed frontend call to a nonexistent backend route (category 2 — a live 404 for real users). P2 for a half-wired capability/flag or a vestigial BFF route for a deleted subsystem. P3 for a backend route with no consumer or a non-breaking shape drift.** Never P0 unless the 404 is on a load-bearing path (signup, claim, publish) that takes down a core flow.

## Findings categories

### (1) Backend route with no frontend consumer
- A registered route in `route:list` whose normalized URI appears in **neither** a frontend literal `/api/…` call site **nor** an `app/api/**/route.ts` BFF proxy target. Candidate-only: the frontend may build the path dynamically. **Grep the frontend before claiming this** — string concatenation, a base-URL constant, or a helper that appends the path all hide a real caller. Internal/webhook/health routes are expected to have no browser consumer — not a finding.

### (2) Frontend call to a nonexistent backend route — **P1, live 404**
- A frontend literal `/api/(professional|public|staff|account|internal)/…` call, or a BFF `route.ts` that proxies to a backend path, whose normalized form matches **no** route in `route:list`. This is a real user-facing 404. Confirm by: (a) reading the frontend call site to get the exact path and method; (b) grepping this repo's `routes/` for the route (including param-renamed variants — a `{site}` vs `{siteId}` mismatch is a normalization artifact, not a 404). Only after both is it a finding.

### (3) Vestigial BFF route for a deleted subsystem
- An `app/api/**/route.ts` (or `proxy.ts` `RESERVED_PATHS` entry) for a subsystem the backend has removed — commerce/shop/store, booking/Fresha, Square, waitlist, affiliates, theme-picker/SmartLinks. The BFF route still exists and proxies to a backend path that now 404s. Distinct from (2): (2) is a live call from app code; (3) is dead proxy plumbing. Confirm the backend genuinely has no such route AND that the frontend page/feature calling it is itself gone or dead.

### (4) Half-wired capability or feature flag
- A backend `AccountCapabilities` capability key, `config/partna.php` feature flag, or staff `integration.*` / `feature.*` availability rule that the frontend **ignores** (renders the feature regardless), OR a frontend capability/flag gate (`lib/account-capabilities.ts`, `features/integrations`) that keys off a capability the backend no longer emits. Either direction ships a UI that promises what the API won't deliver (or hides what it will). Confirm by reading the capability's emission in this repo AND its consumption in the frontend.

### (5) Backend code reachable ONLY via an orphaned route
- A controller action / service method whose sole entrypoint is a route in category (1). It greps "clean" (has a caller) inside this repo, but that caller is itself dead cross-repo. This is the bridge to Stage 4: a `SLOP` dead-code pass cannot see it (the route keeps it "reachable"); only the cross-repo view proves it dead. Cite the route, the action, and the absence of any frontend consumer.

### (6) Contract shape drift
- The two repos disagree about a payload's field names. Canonical cases from the design-kit migration: a frontend still reading `themeMode`, `accent`, or `fontFamily` (stripped — the backend now emits `designKit` column values) or reading `settings.design.*`; `skeletonId` vs `architectureId` (the backend emits `architectureId`, with `skeletonId` a transitional alias — a frontend reading ONLY `skeletonId` with no `architectureId` fallback is drift). Also: a Resource field the frontend expects but the Resource no longer emits, or a renamed key. Confirm by reading the `app/Http/Resources` class AND the frontend consumer.

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- Give the **normalized path** and, for route findings, the **exact** frontend string and backend route (or its absence).
- Quote the frontend evidence verbatim (file:line from `$PARTNA_FRONTEND_PATH`) AND the backend evidence (route registration, Resource `toArray`, capability emission).
- **State the two-repo verification explicitly:** the grep run in THIS repo and the grep run in the FRONTEND repo, each with its result. A finding that cites only one side is not allowed.
- Name the fix and which repo owns it (e.g. "delete the BFF route + page in frontend", "the backend must re-add `GET api/public/booking/config`", "frontend must read `architectureId`").

## Anti-false-positive directive (adjudicator)

You (the Sonnet adjudicator) have `Read`/`Grep`/`Glob` over **both** repos. The scan tier saw only the scoped files and the inventory's candidate buckets — it **cannot** know whether a route with no static caller is called dynamically, or whether a "missing" backend route exists under a renamed param. Before confirming any XREPO finding:

- **Absence is a grep result, never a judgement.** Every "no consumer" / "no such route" claim must cite a grep across BOTH repos (`app/`, `routes/`, `config/` here; the whole frontend tree there). No grep, no finding.
- **Normalization misses are not 404s.** If the inventory flags a `FRONTEND-ONLY` path but a backend route shares its prefix and differs only in a `{param}` segment, it is a normalization artifact — **drop it.**
- **An `UNRESOLVED` entry may never be promoted without a source read in both repos.** The pre-pass deliberately refuses to assert `BACKEND-ONLY`; you promote to it only after confirming no dynamic caller exists.
- **Respect intentional transition state.** The `skeletonId` alias and other deliberately-transitional keys (see `CLAUDE.md`, `system-prompt.md`) are kept on purpose — not drift.
- **Respect the stale-frontend caveat.** If `CONTRACT-INVENTORY.md`'s header is stamped STALE (frontend not on clean `main`), treat its buckets as extra-provisional and re-derive from a source read.

A short, provable cross-repo report beats a long list of normalization noise. When the two-repo evidence isn't there, drop it.

## Suggested scope groups

Targeted runs pair the inventory with the relevant slice of both repos. Frontend paths use `$PARTNA_FRONTEND_PATH` (a live checkout) and are read-only.

### Contract drift (routes + resources)
```
--scope audits/cross-repo/CONTRACT-INVENTORY.md
--scope routes
--scope app/Http/Resources
--scope $PARTNA_FRONTEND_PATH/app/api
--scope $PARTNA_FRONTEND_PATH/proxy.ts
```

### Capability / feature-flag wiring
```
--scope audits/cross-repo/CONTRACT-INVENTORY.md
--scope app/Services/Accounts
--scope config/partna.php
--scope $PARTNA_FRONTEND_PATH/lib/account-capabilities.ts
--scope $PARTNA_FRONTEND_PATH/features/integrations
```

## Exhaustiveness directive

Every `FRONTEND-ONLY` inventory row is a candidate P1 that must be either confirmed with two-repo evidence or explicitly dropped as a normalization artifact — do not leave one unexamined. Every removed subsystem named in the campaign (commerce, booking, Square, waitlist, affiliates, theme-picker) is a place to look for categories 3 and 5. But never invent a finding to pad the list: when the contract is intact, an empty category is the correct output.
