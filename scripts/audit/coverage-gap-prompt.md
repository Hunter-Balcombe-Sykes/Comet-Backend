# Assurance coverage-gap review — reusable prompt

Paste the block below into a **fresh session** (plan mode on). It is deliberately
long: the whole value of this review is that it refuses to trust any lane's own
green tick.

Re-run it after any change to the assurance surface (new CI job, new phpunit
config, new drill, new k6 scenario, new DAST profile).

---

## THE PROMPT

You are auditing **assurance coverage**, not code. Your question is not "is this
code wrong?" — it is **"what could break in production that no lane would
catch, and which lanes are lying about what they cover?"**

Do **not** run `scripts/audit/audit.sh`. That pipeline reads source files
looking for defects. This review reads *assertions* and *risk surfaces* and
reports on the gap between them. Absence is the finding, so a scanner that only
reads what exists structurally cannot produce it.

Work in plan mode. Produce a written report. Change no code.

### Ground rules

1. **Evidence or silence.** Every claim cites `path/file.php:line`, a CI job
   name, or a command you actually ran with its output. If you cannot cite it,
   you do not know it — say "unverified" and move on.
2. **A test existing proves nothing.** For each one, establish three separate
   facts: does it **exist**, does it **run** (in which lane/config), and does it
   **gate** (is that lane a required status check / a pre-push hook / manual
   only). A test that exists in no lane is decoration. A lane that gates nothing
   is advisory.
3. **Never infer a test's coverage from its filename or its describe block.**
   Read the assertions. A file called `TenantIsolationTest` may assert a 200.
4. **Do not trust a ticked checkbox in `audits/`.** Ticked means "resolved as an
   open question", which includes WONTFIX. Judge from code and lanes only.
5. **Report what you did not cover.** If you ran out of budget on a surface, say
   which one and why. Silent truncation reads as "covered everything".

### Phase 0 — inventory the lanes (verify each; the list below is a starting
### point from 2026-08-03 and may be stale)

For each lane, record: what it runs, where it is defined, what triggers it, what
its failure does (block / warn / nothing), and when it last actually ran.

| Lane | Where |
|---|---|
| Default unit+feature suite | `composer test` → `phpunit.xml`; CI job `test` |
| Postgres lane | `composer test:pg` → `phpunit.pg.xml`; CI job `postgres-tests` |
| Applied-schema lane | `composer test:schema` → `phpunit.schema.xml`; CI job `schema-tests` |
| Authorization matrix lane | `composer test:authz` → `phpunit.authz.xml`; CI job `schema-tests` (note: **shares a job**, verify it actually reaches the step) |
| Schema-drift gate | CI job `schema-drift`; `scripts/launch-check/schema-drift-baseline.json` |
| Outbound HTTP / SSRF guard | CI job `outbound-http-guard`; `tests/Feature/Architecture/OutboundHttpGuardTest.php` |
| Supply chain | CI job `supply-chain`; `php artisan checkpoint:scan` |
| Checkpoint suppressions | CI job `checkpoint-suppressions` |
| SAST / static analysis | PHPStan (`composer analyse`, `phpstan-baseline.neon`), Pint, the three `composer guard:*` scripts, `scripts/guard-no-unsafe-migrations.php` |
| Worker lanes | CI jobs `worker-tests`, `worker-static`; `cloudflare-worker/` vitest + `wrangler types` regen-and-diff |
| DAST | `scripts/dast/` (`baseline/`, `active/`, `edge/`, `tests/`, `run.sh`); CI workflow `dast-edge.yml` job `edge-scan` |
| Load testing | `scripts/launch-check/k6/` — `baseline.js`, `spike-origin.js`, `spike-edge.js`, `jobs.js`, `seed.sql` |
| Launch-check groups A–H | `scripts/launch-check/launch-check.sh` — schema, smoke, supabase, supply, security, drills, env, runtime |
| Failure drills | `docs/runbooks/drills/` 01–04 + `logs/`; freshness enforced by `scripts/launch-check/drill-freshness.sh` |
| Pre-push hook | `.githooks/pre-push` (PHP half + Worker half, **skip independently**) |
| Branch protection | `gh api repos/:owner/:repo/branches/production/protection` and the same for `development` — which checks are *required*, and do admins bypass |
| Runtime monitoring | Nightwatch — treat as detection, not prevention; note which failure classes it would surface *after* the fact |

Then answer explicitly:

- Which CI jobs are **required status checks** and which are decorative?
- Which lanes run only on a schedule, and when did each last complete?
- Is there any `phpunit.*.xml` whose `<testsuite>` paths exclude directories
  that exist on disk? List the orphaned directories — those tests run in **no**
  lane. (This has happened before; check `tests/Postgres`, `tests/Schema`,
  `tests/Authz`, `tests/Integration` in particular.)
- Do any tests skip themselves at runtime (`->skip()`, `markTestSkipped`,
  a `pgsql`/driver/env guard) in every lane that runs them? Count them. A test
  that skips in every configured lane is a never-run assertion.

### Phase 1 — build the risk model before looking at any test

Derive the surfaces from the codebase, not from the test names. Enumerate:

**Security surfaces**
- Every public (unauthenticated) route in `routes/api/*.php` and `routes/api.php`.
- Every route reachable with a user JWT but touching another tenant's data
  (IDOR/enumeration surface). Cross-check against `app/Policies/`.
- Every staff route and its AAL2 requirement.
- Every write path that bypasses Eloquent (`DB::table()->update()`, raw SQL) —
  these skip observers, policies and cache invalidation.
- Every outbound HTTP call site and its category (A/B/C/D per
  `docs/superpowers/specs/2026-07-30-outbound-http-guard-design.md`).
- Every place user input reaches: a filesystem path, a shell command, a DB
  identifier, an HTML/JSON response rendered by the Worker or the Astro app,
  a redirect target, an email recipient.
- Every secret/PII egress: logs, notifications, exports, staff endpoints,
  error responses, analytics payloads.
- Auth edge cases: expired/`nbf`-skewed/`alg=none`/base64url-variant JWTs,
  missing `aal`, revoked sessions, unclaimed (`status='unclaimed'`) users,
  soft-deleted users, claim races.
- Rate limiting: which limiters exist, keyed on what, and what happens when the
  keying header (`CF-Connecting-IP`) is absent or spoofed.
- The Worker: KV poisoning, alias loops, cache-key collisions across the shared
  prod/dev KV namespace, `redirect: "manual"` behaviour.

**Performance / resilience surfaces**
- N+1 and unbounded queries on public read paths.
- Every cache key: does it carry a TTL (no `Cache::forever` — `volatile-lru` is
  instance-wide), who invalidates it, and what happens on a cache miss storm.
- Every dependency whose outage the app must survive: Redis, Supabase/Postgres,
  Supavisor pool exhaustion, R2, Cloudflare, Resend, Places, Instagram, Fresha.
  For each: is the failure mode fail-open or fail-closed, and is that *asserted*
  anywhere or merely intended?
- Every unbounded loop / timeout / retry: which outbound calls have no timeout,
  which queue jobs have no `$tries`/backoff ceiling.
- Queue/Horizon: job growth under load, eviction risk, poison-message handling.
- Cold-start and warm-cache behaviour of the public sitepage path end to end
  (Worker → KV → origin → Postgres).

Write this model down as a list of numbered surfaces **before** Phase 2. It is
the denominator.

### Phase 2 — map surfaces to lanes

Produce a matrix: one row per surface from Phase 1, one column per lane from
Phase 0. Each cell is one of:

- **ASSERTED** — a specific test/scan makes a specific claim. Cite `file:line`.
- **VACUOUS** — something appears to cover it but does not (see Phase 3).
- **PARTIAL** — happy path covered, the named edge case is not.
- **NONE** — nothing.

Only surfaces with at least one **ASSERTED** cell in a lane that *gates* are
genuinely covered. Everything else is a finding.

### Phase 3 — hunt false PASSes (weight this equally with gap-finding)

A lane that reports green on an uncovered surface is worse than a missing lane,
because it stops anyone looking. Specifically check for:

- **Assertions that cannot fail.** `expect($x)->toContain($a, $b)` — Pest's
  `toContain` is *variadic*, so a second argument is another needle, **not** a
  failure message. Any `assertTrue(true)`-shaped assertion. Any test whose only
  assertion is `assertStatus(200)` on a route that returns 200 for everyone.
- **Coverage tests that enumerate an empty set.** A "every route has a policy"
  test whose route collection resolves to zero routes passes trivially. Force
  the collection to be non-empty and assert its size.
- **Grep-based guards whose pattern no longer matches** the code it guards
  (renamed namespace, reformatted call). Verify by deliberately breaking the
  thing in a scratch copy and confirming the guard goes red.
- **Drills whose log records a PASS that the drill could not actually have
  observed** — e.g. asserting a fail-open path while the dependency was still
  up, or reading a metric that was never emitted. Read
  `docs/runbooks/drills/logs/*.md` against the drill's own procedure.
- **DAST baselines that were hand-written rather than produced by a real run.**
  Check `scripts/dast/**/baseline*` provenance — a baseline authored by hand
  suppresses findings that were never seen.
- **k6 scripts that exercise a path the seed data invalidates.** `seed.sql` and
  `jobs.js` hard-code three real invariants (gallery max 6 per site; a gallery
  item needs a matching `site.media_variants` webp row; analytics writes need a
  matching `Origin`/`Referer`). If any drifted, the load test is measuring a
  404/empty path at full speed and reporting healthy throughput.
- **SQLite-vs-Postgres drift.** The default suite runs SQLite; production is
  Postgres. Any test asserting a CHECK constraint, a NOT NULL, a trigger, a
  partial unique index, a `FOR UPDATE`, or an unknown quoted identifier is
  asserting nothing outside the pg/schema lanes. (SQLite treats unknown quoted
  identifiers as **string literals** — such a test passes while asserting the
  literal.) List every such test and which lane it needs.
- **PHPStan baseline entries that hide a live bug** rather than a false
  positive, and suppression hashes that are content-addressed (editing the
  matched code mints a new hash and silently un-suppresses or re-suppresses).

For each false PASS, state the **failure scenario**: the concrete change or
production event that would slip through, and which lane reported green.

### Phase 4 — adversarial pass on your own findings

Before writing the report, try to refute each finding. For each one ask: is
there a lane I did not check that covers this? Did I read the whole assertion?
Is the surface actually reachable in production? Default to dropping a finding
you cannot substantiate. Prefer 15 substantiated findings to 60 speculative
ones.

### Output

Write to `audits/consolidation/<YYYY-MM-DD>-assurance-coverage/COVERAGE-GAP.md`:

1. **Lane inventory table** — exists / runs / gates / last ran, per Phase 0.
2. **Orphaned and never-run assertions** — tests in no lane, tests that skip in
   every lane they are in. Exact counts and paths.
3. **Coverage matrix** — surfaces × lanes, per Phase 2.
4. **False PASSes** — highest priority section, each with its failure scenario.
5. **Gaps, ranked** — P0/P1/P2, each with: Plain English, Technical, the
   surface it leaves exposed, and the *smallest* assertion that would close it
   (name the lane it belongs in — that choice is most of the work).
6. **Explicitly out of scope** — what you did not examine and why.

Do not propose a testing framework, a coverage-percentage target, or a new
tool. The deliverable is a ranked list of specific missing assertions and
specific lying ones.

---

## Notes for whoever runs this

- Budget it. Phase 1 alone is large; recall degrades past ~100K tokens, so
  running it **per surface family** (public routes / auth+authz / data egress /
  dependency resilience / load+cache / Worker edge) in separate sessions will
  find more than one sweep. The matrix can be stitched afterwards.
- If a phase turns up more than ~20 findings in one family, stop and split.
- Findings that are "add a test" are cheap; findings that are "this lane has
  been green and blind for N weeks" are the ones worth the run.
