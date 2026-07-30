# scripts/dast/ — DAST security scanning

Sends hostile HTTP requests at a running target and inspects the responses —
the *dynamic × code* cell of the assurance suite (sibling to
`scripts/audit/` static review and `scripts/launch-check/` runtime checks).

Full design: `docs/superpowers/plans/2026-07-17-dast-security-implementation.md`
(source of truth) and `docs/superpowers/specs/2026-07-17-dast-security-design.md`
(background/rationale).

## Two lanes

- **active** — ZAP fuzzing an isolated, runner-owned local Supabase stack
  (port-offset, torn down via `trap` on every exit). Two seeded identities +
  an unauth pass drive authenticated scans and a cross-identity IDOR check.
  Manual only — never in cron or CI.
- **edge** — Nuclei (curated tags + custom assert templates) + wcvs
  (cache-deception) + a weekly OWASP ZAP *passive* baseline scan, all
  non-destructive, against `EDGE_TARGET`. Weekly cron; not in CI.

Neither lane runs in `ci.yml` — DAST is slow and needs a running
target/stack. This is a self-contained shell tool: zero `config/partna.php`,
app-code, or Supabase migration changes.

## One-time setup

```bash
cp scripts/dast/.env.example scripts/dast/.env   # fill in secrets, never commit
```

## Running each lane

**Edge lane** — non-destructive, safe to run against dev or prod anytime. Also runs automatically every Sunday via `.github/workflows/dast-edge.yml` once `DAST_EDGE_TARGET`/`DAST_EDGE_SITEPAGE_TARGET`/`DAST_EDGE_RATE_LIMIT` are set as GitHub repo secrets.

```bash
scripts/dast/run.sh --only edge
```

**Active lane** — manual only. Needs Docker running, mutates the runner's own throwaway local Supabase stack, takes several minutes (isolated bring-up + a curated ZAP scan against ~250 routes across two identities plus an unauth pass). Run this before a release, or after any change to auth/authorization/policy code — that's exactly the class of bug the cross-identity IDOR pass is built to catch.

```bash
scripts/dast/run.sh --only active
```

**After reviewing a run's findings**, accept the ones you're keeping into the triaged baseline (never automatic — always a human decision after reading `new-findings.txt`/`REPORT.md`):

```bash
scripts/dast/run.sh --only edge --update-baseline
scripts/dast/run.sh --only active --update-baseline
```

**Self-test (the tool's own acceptance gate)** — run this after changing anything under `scripts/dast/` itself, not as a routine security check. It proves the scanner isn't silently broken: plants known-vulnerable canaries in both lanes, asserts they're flagged AND fail the build, then asserts a clean target passes and a baselined finding is suppressed.

```bash
scripts/dast/tests/dast-selftest.sh
```

### Tier 2 — auth-layer probes (`active/tier2.sh`)

Runs inside the active lane, immediately after seeding and **before** ZAP, so a
broken auth layer fails the lane fast rather than after a full spider. Tier 1
(`tests/Authz/`) stubs `VerifySupabaseJwt` and runs in-process, so it can only
ever prove "the Policy said no"; these prove the **token itself** is rejected
and that a real race has exactly one winner.

| Probe | Asserts |
|---|---|
| JWT tampering | flipped signature, `alg=none`, HS256 downgrade → 401; expired `exp` → 401 |
| Real `aal1` staff token | `require.aal2` on `/api/staff/me` → 401 with `error=mfa_required` |
| Claim race | N distinct claimants, one build → exactly one 2xx, rest 409, zero 429 |

Every group carries a **control** (an untampered token must work; the staff
token must be valid on a non-AAL2 route; losers must report `ALREADY_CLAIMED`)
so a pass cannot come from a dead app or an uncontended race.

Three mechanics are load-bearing and easy to break by accident:

- **Tokens are always mutated from a real sign-in, never minted.** GoTrue
  validates `session_id` against `auth.sessions` and rejects forgeries with 403
  `session_not_found` — the wrong reason (see `active/mint-jwt.php`).
- **The claim race uses N *distinct* auth users**, because `throttle:claim` is
  keyed on `supabase_uid`. One token fired N times returns 429s and proves
  nothing; the probe asserts a 429 count of zero for exactly that reason.
  Claimants deliberately have **no `core.users` row** — `ClaimSiteService::claim()`
  throws `ACCOUNT_EXISTS` if one exists.
- **The expired-`exp` case cannot be forged.** Changing `exp` breaks the
  signature, making it indistinguishable from the flipped-signature probe. The
  only honest route is a genuinely short-lived real token, so `bring-up.sh`
  writes a low `jwt_expiry` into the **scratch** config only (`DAST_JWT_EXPIRY`,
  default 8s — same test-only-override pattern as the captcha branch; the
  Supabase CLI 2.101.0 accepts 8 without complaint, verified 2026-07-30). The
  probe then waits `exp + jwt_leeway_seconds + 3`, **not** `exp`:
  `VerifySupabaseJwt` sets `JWT::$leeway` from
  `config('supabase.jwt_leeway_seconds')` (default 60) to tolerate Supabase
  clock skew, so an 8s token is legitimately accepted for 68s. Sleeping only
  past `exp` returns 200 and reads like a critical auth bug when it is
  documented, deliberate behaviour. If the total wait would exceed 120s the
  probe **skips loudly** with the reason written into `tier2.md`; it never
  silently omits itself.

Two traps worth knowing before editing `tier2.sh`:

- **Do not "flip the last character" of a signature.** A 32-byte HMAC signature
  is 43 base64url characters, but 43 × 6 = 258 bits for a 256-bit value — the
  final character's two low bits are discarded on decode. Mutating the last
  character between values differing only in those bits (`A`→`B`) yields
  byte-identical signature bytes, so the token stays **valid** and the probe
  reports 200 while claiming to have tampered with it. The probe mutates the
  **first** character, where all six bits are significant.
- **The active lane is manual-only, so nothing catches it when a formatter
  breaks it.** `6461cff5` (a Pint-only commit) rewrote
  `Illuminate\Contracts\Console\Kernel::class` in `active/seed-identities.php`
  into a short reference plus a `use` import placed *below* the call site. PHP
  registers imports linearly during compilation, so `Kernel::class` compiled to
  the literal `'Kernel'` and the whole lane died with "Target class [Kernel]
  does not exist". The bootstrap now sits below the `use` block, which is both
  correct and Pint-stable.

Output lands in `<outdir>/tier2.md`. Any FAIL die()s the whole active lane —
deliberate: a token the auth layer accepts when it should not is a finding, not
a warning. **Never weaken an assertion to go green** — a real rejection failure
goes through `scripts/audit/fix-flow.md`'s blocker gate (auth code needs a
written plan + sign-off).

## What updates automatically vs what you maintain by hand

The **route surface is fully automatic** — `seed-endpoints.sh` re-derives the OpenAPI seed from `php artisan route:list --json` on every active-lane run, so new/removed/changed API endpoints are picked up with zero action needed. Same for the two seeded identities (freshly created each run) and the baseline diff itself (a finding at a new key just shows as "new"; one that stops occurring just stops appearing — no config change needed either way).

**Four things don't auto-update and need a human to keep them current:**

1. **The active lane's exclusion list** (`active/zap-context.yaml`'s `excludePaths`) — a hardcoded set of route patterns whose handlers reach past the local box (vendor API calls, real email/notification sends, Cloudflare KV writes). If you add a new route that does one of those things, it won't be auto-excluded — the active lane could trigger a real external side effect from a "local" scan until someone adds it here. Grep for `SyncSubdomainToKvJob::dispatch`, `Mail::`/`Notification::send`, and new entries under `routes/api/platforms.php` when reviewing this.
2. **The 5 custom Nuclei templates** (`edge/templates/*.yaml`) — each asserts against a specific hardcoded path (e.g. `/api/customers/{id}`, `/api/public/unsubscribe/...`). If those specific routes get renamed or restructured, the template should be reviewed so it's still testing something real.
3. **`active/seed-identities.php`** — hardcodes the exact fields needed to build a full identity (User → Site → SiteMedia → Customer → Enquiry), plus the Tier 2 fixtures (a `core.partna_staff` identity, a pool of bare claimant auth users, and one first-come `core.pre_account_builds` row). A schema change (new required column, renamed relation, a tightened CHECK on `build_state`/`built_via`/`source_type`) will break this script until it's updated to match. Note that `auth_user_id` and `status` are **not** in `User::$fillable` — they are assigned directly, and mass-assigning them instead would silently produce an `active` user and make the claim race vacuous.
4. **The curated active-scan rule set** (5 rule IDs in `zap-active.sh`: SQLi, XSS reflected/persistent, path traversal, command injection) — static by design (never "run everything"); only touch it if you deliberately want to broaden or narrow what vulnerability classes get tested.

## Baselines — triage, don't pre-seed

`baseline/*` starts empty and is populated **only** by reviewed triage
(`--update-baseline`, run by a human after reading the findings) — never
pre-seeded, which would bury real bugs. See Phase 10 of the implementation
plan for the first-run triage process.

## Limitation — local ≠ prod authz fidelity

The active lane's local stack does not reproduce prod's `app_backend`
restricted role + RLS via Supavisor. A green active lane means "no
injection/authz class found against app logic," not "prod RLS proven."
Stays a post-launch human-pentest gap — see `REPORT.md` on each run.
