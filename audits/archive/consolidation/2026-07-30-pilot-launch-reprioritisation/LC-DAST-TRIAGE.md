# `LC-DAST` — baseline triage — 2026-07-30

Produced for the P0-LAUNCH bucket. **Read the two blockers in §0 before acting on anything below.**

---

## 0. The headline: the control has never run, and nothing here can be baselined yet

**One workflow execution, ever.** Run `30211809194`, `2026-07-26T17:04:24Z`, **failed in 10 seconds**:

```
[dast] ERROR: no target: pass one or set EDGE_TARGET
```

- `gh api .../actions/secrets` → `{"total_count":0}`. `.../actions/variables` → `{"total_count":0}`.
  **None of the three secrets the build flagged were ever set.**
- `.github/workflows/dast-edge.yml` runs `cron: '0 16 * * 0'` (Sun 16:00 UTC). It is a
  **guaranteed-red no-op** and fires again every Sunday.
- `git log --all --diff-filter=A -- 'audits/dast/**'` → the only file ever added is `.gitignore`.
  **No `REPORT.md` has ever been committed.** The 2026-07-26 build-time run directories are gone
  from disk.
- All three baseline files are empty — `zap-baseline.json` `[]`, `zap-passive-baseline.json` `[]`,
  `nuclei-baseline.txt` comments-only. Empty is the *correct documented default* ("never pre-seed,
  which would bury real bugs"), but it means nothing has been triaged.

> **Update 2026-07-31 — the ACTIVE lane has moved; the EDGE lane has not.**
>
> The bullet above is now stale for one file. `aa6b01bc` added a genuinely triaged entry to
> `zap-baseline.json` — `"10021@http://host.docker.internal:8100/robots.txt"`, ZAP's missing-nosniff
> alert on a static file that `artisan serve` ships without booting Laravel, so `SecureHeaders` never
> runs for it (production serves it through Cloudflare, which does send the header). That is the
> **correct** pattern: the key came out of a real run, not a keyboard. Tier 2 auth probes landed
> alongside it (`a4876ea8`, `4288b529`, `ee57a74c`) plus a seeder fix (`3fd539a9`).
>
> **Everything in this document below is about the EDGE lane, and none of it has moved.** Re-verified
> 2026-07-31: `actions/secrets` → `{"total_count":0}`, `actions/variables` → `{"total_count":0}`, and
> `gh run list --workflow=dast-edge.yml` still shows exactly one run ever — `30211809194`, the 10-second
> `no target` failure from 2026-07-26. The Sunday cron remains a guaranteed-red no-op.
>
> **The blocking step is Josh's and nothing downstream can start without it:** set `EDGE_TARGET` plus
> the three secrets as repo secrets. Then, in order — clean run → `scripts/dast/run.sh --only edge
> --update-baseline` → *then* drop `DAST_FAIL_ON` from `high` to `medium`. BLOCKER 2 below still
> applies to the edge baselines: they cannot be hand-written.

> ## ✅ RESOLVED 2026-08-03 — both blockers are discharged. Edge lane is live and baselined.
>
> Secrets set (`DAST_EDGE_TARGET=https://dev-api.partna.au`,
> `DAST_EDGE_SITEPAGE_TARGET=https://ollies.partna.au`). **First successful run ever:**
> `30799411551`, 19m41s, exit 0 — 19 findings, every one low or informational. Nuclei and wcvs
> both clean, matching the 2026-07-26 manual result. All 19 came from `zap-passive`.
>
> The 17 unique keys are now in `scripts/dast/baseline/zap-passive-baseline.json`, derived from
> that run's own `REPORT.md` (BLOCKER 2 satisfied — real generated keys, not invented ones).
> `DAST_FAIL_ON` lowered `high` → `medium`.
>
> ### ⚠️ The API hostnames are NOT behind your Cloudflare zone — this is the finding to remember
>
> Every header-related finding is anchored to `https://dev-api.partna.au/robots.txt`, and the
> reason is architectural, not a misconfiguration to fix:
>
> ```
> dev-api.partna.au → CNAME partna-development-fsh3vz.laravel.cloud → 103.133.1.1   (DNS-only)
> api.partna.au     → CNAME partna-production-uovh3z.laravel.cloud  → 103.133.1.1   (DNS-only)
> ollies.partna.au  → 104.21.82.134, 172.67.158.59                  (Cloudflare anycast — proxied)
> partna.au         → 216.198.79.1                                   (Vercel)
> ```
>
> The API records are **grey-clouded**: the CNAME is visible in the DNS answer, whereas a proxied
> record hides its origin behind CF anycast IPs the way `ollies.partna.au` does. So **zone-level
> Cloudflare settings cannot affect the API hosts at all.**
>
> This is easy to get wrong, and we did: `dev-api.partna.au` returns `cf-ray`, `__cf_bm` and
> `server: cloudflare`, which look like proof of proxying. Those come from **Laravel Cloud's own
> Cloudflare** — Laravel Cloud runs behind CF itself. The tell is `/cdn-cgi/trace`, which returns
> **404** on the API hosts; a genuinely proxied host answers it. Enabling zone HSTS (done, and
> kept — it covers proxied sitepages incl. CF-generated error/challenge pages) changed nothing on
> `dev-api`, exactly as this predicts.
>
> ### Dispositions — all 19 accepted, none fixed
>
> | Keys | Verdict |
> |---|---|
> | `10035-1` HSTS, `90004-1` site isolation (both `/robots.txt`) | **Accepted.** `robots.txt` is a static file served by the platform edge without booting Laravel, so `SecureHeaders` never runs for it. Fixing means either orange-clouding the whole API (large blast radius: caching, WAF, double-proxy) or routing a static file through PHP. HSTS is per-host and every real API endpoint already sends `max-age=31536000`; the only client whose sole contact is `/robots.txt` is a crawler, which does not act on HSTS. Cost exceeds the risk. |
> | `10054-1`, `10054-2` cookie SameSite | **Not ours.** These are Cloudflare's `__cf_bm` / `_cfuvid` bot-management cookies, already `HttpOnly; Secure; SameSite=None`. |
> | `10049-1`, `10049-3`, `10015` cacheable content | **Intended.** `cache-control: public, max-age=14400` on `robots.txt` is deliberate. |
> | `10096` timestamp disclosure | **False positive** — pattern-matches any epoch-like number. |
> | `10112` session management | **Informational**, triggered by the same CF cookies. |
>
> **What would reopen the HSTS item:** moving the API behind the Cloudflare zone for any other
> reason (edge WAF, rate limiting). At that point the header comes free and the finding should be
> un-baselined rather than left blessed.

### 🔴 BLOCKER 1 — the findings below are recovered from prose, not from scanner output
`docs/superpowers/plans/2026-07-17-dast-security-implementation.md` Phase 10 records the 2026-07-26
build-time run results in enough detail to reason about. The raw JSON is gone. So this document is a
**second-hand triage**: dispositions are sound, but severities, instance counts and evidence strings
could not be re-derived.

### 🔴 BLOCKER 2 — baselining is impossible without a fresh run. Do not hand-write baseline entries.
`--update-baseline` is a flag on `run.sh` that appends **that run's own findings** after the scanners
execute (`run.sh:55` → `lib/diff-baseline.sh --update-baseline`). Baselines are keyed by
scanner-specific stable keys — `template-id@matched-at` for Nuclei, `technique@url` for wcvs, ZAP
alert keys for the JSON files. **Those keys do not exist for the six SUPPRESS items**, because the
run that produced them is gone.

Hand-writing entries would be worse than leaving the baselines empty: a key that matches no real
finding is dead weight *and* reads as "already triaged" to the next person. **So the six SUPPRESS
dispositions below are decisions, not yet actions.** They can only be applied by re-running the lane
with `--update-baseline`.

**Consequence: the `DAST_FAIL_ON` question is also blocked.** Dropping the floor from `high` to
`medium` only makes sense once a baseline exists; doing it first turns all nine findings red weekly.
Correct order: wire the secrets → get a clean run → triage against real keys → baseline → then
lower the floor.

---

## 1. What actually shipped (2026-07-26)

Self-contained shell tooling under `scripts/dast/`. Zero app code, zero config, zero migrations.

| Piece | Path |
|---|---|
| Entrypoint | `scripts/dast/run.sh` — `--only active\|edge [--target URL] [--fail-on SEV] [--update-baseline]` |
| Shared lib | `scripts/dast/lib/common.sh` — loads `scripts/dast/.env`, `dast_outdir()` → `audits/dast/<date>/` |
| Gating | `scripts/dast/lib/diff-baseline.sh` — the only thing deciding pass/fail; normalises 4 artifact types to `{key, severity}`, subtracts baseline, exits 0 or 2 |
| **Active lane** | `active/zap-active.sh` + `bring-up.sh` + `mint-jwt.php` + `seed-identities.php` + `seed-endpoints.sh` + `zap-context.yaml` — OWASP ZAP against an isolated local Supabase stack (port-offset, `trap`-torn-down). Three passes: identity A, identity B, unauth, plus a cross-identity IDOR pass. **Manual only.** |
| **Edge lane** | `edge/nuclei-edge.sh`, `edge/wcvs.sh`, `edge/zap-baseline.sh`, `edge/templates/*.yaml` — Nuclei (tags `exposures,misconfiguration,http,ssl,cache`, floor `low`) + 5 custom templates; wcvs 2.0.0 cache-deception (built from source); ZAP passive baseline |
| Self-test | `tests/dast-selftest.sh` — plants known-vulnerable canaries, asserts flagged AND build fails, then asserts clean passes and baselined is suppressed. 7/7 on 2026-07-26 |
| Hook | `.claude/hooks/dast-maintenance-check.sh` — PreToolUse warn on `routes/**` and `supabase/migrations/*.sql` edits |

Active-lane policy is deliberately narrow — 5 rule IDs, `defaultThreshold: "OFF"`: `40018` SQLi,
`40012` XSS reflected, `40014` XSS persistent, `6` path traversal, `90020` OS command injection.
ZAP's passive rules run on the same traffic regardless.

CI: `.github/workflows/dast-edge.yml`, cron + `workflow_dispatch`, checks out `development` on
scheduled runs, `DAST_FAIL_ON: high`, uploads `audits/dast/*/REPORT.md` with `if: always()`,
90-day retention. **The active lane is deliberately not in cron, and neither lane is in `ci.yml`.**

---

## 2. Triage — 9 findings

Severities as reported by the scanners. Reasoning is grounded in code that was read, and for four
items in five plain `curl -D -` header fetches against `https://dev-api.partna.au` (headers only,
**no scanner was run**) — disclosed because it is what converts three items from "probably" to
"verified".

### Active lane — ZAP active scan, isolated local stack

| # | Finding | Sev | Verdict | Disposition |
|---|---|---|---|---|
| A1 | `10037` Server leaks info via `X-Powered-By` — 5 instances | Low | FALSE POSITIVE about the product; TRUE about the local runner | **SUPPRESS** |
| A2 | `10021` `X-Content-Type-Options` missing — 1 (`/robots.txt`) | Low | Same class as A1 | **SUPPRESS** |
| A3 | SQLi / XSS reflected / XSS persistent / path traversal / OS command injection / cross-identity IDOR | — | **Clean — nothing found** | No action |

**A1** — the lane serves the app via `php artisan serve` (PHP's built-in server) inside `bring-up.sh`.
Locally `php -i` reports `Loaded Configuration File => (none)` and `expose_php => On`, so the built-in
server emits `X-Powered-By: PHP/8.4.19`. **The deployed app does not**: `/`, `/robots.txt`,
`/sitemap.xml`, `/up` and `/p/{uuid}.svg` on `dev-api.partna.au` carry no `X-Powered-By`. A property
of the scan runner's php.ini, not of Partna. Its ZAP key (`10037@http://127.0.0.1:8100/...`) is stable,
so it will recur on every run on any machine with `expose_php=On`.

**A2** — `public/robots.txt` is a static file. Under `php -S` static files are served without booting
Laravel, so `App\Http\Middleware\SecureHeaders` never runs. On the deployed host it *does* get the
header: `curl -D - https://dev-api.partna.au/robots.txt` returns `x-content-type-options: nosniff`
(lower-cased, i.e. added by the platform edge). Same local-runtime artifact.

**A3** — nothing found is a real signal, not an absence of testing. The cross-identity pass used two
freshly seeded identities and got **404, not 403**, on foreign resources — matching this repo's
documented convention (`CLAUDE.md`: "Public endpoints: always 404 (403 enables enumeration)").
⚠️ **Caveat that must stay attached:** `scripts/dast/README.md` "Limitation — local ≠ prod authz
fidelity". The local stack does not reproduce prod's `app_backend` restricted role + RLS via
Supavisor. A green active lane means *"no injection/authz class found against app logic"*, **not**
*"prod RLS proven"*. Prod-role authz remains a post-launch human-pentest gap.

### Edge lane — Nuclei + wcvs

| # | Finding | Verdict | Disposition |
|---|---|---|---|
| E1 | Nuclei: 0 findings (30 templates incl. all 5 custom; verified twice, byte-identical `new-findings.txt`) | Clean | No action |
| E2 | wcvs: `foundVulnerabilities: false` (15m46s against a real published dev sitepage) | Clean | No action |

⚠️ **Caveat:** the plan's own risk register flags that Cloudflare's WAF can turn an unthrottled sweep
into challenge pages that read as "clean" — a false negative. `DAST_EDGE_RATE_LIMIT` defaults to
20 rps to avoid this, but **nobody confirmed the 2026-07-26 responses weren't interstitials.** Treat
E1/E2 as "no evidence of a problem", not "proven clean".

### Edge lane — ZAP passive baseline against `https://dev-api.partna.au` (7 WARNs)

All on `/`, `/robots.txt` or `/sitemap.xml`. **None on an authenticated or data-bearing path.**

| # | Finding | Sev | Verdict | Disposition |
|---|---|---|---|---|
| P1 | Missing `Strict-Transport-Security` | Low | **TRUE POSITIVE, scoped to statically-served files** | **FIX BEFORE GA** |
| P2 | Missing `Cross-Origin-Resource-Policy` | Low | **TRUE POSITIVE, app-wide** | **ACCEPT** |
| P3 | `Cookie SameSite=None` — 6 instances | Low | FALSE POSITIVE | **SUPPRESS** |
| P4 | Non-storable content / cache-control re-examine | Info | FALSE POSITIVE (informational; behaviour deliberate) | **SUPPRESS** |
| P5 | Timestamp disclosure — Unix | Low | FALSE POSITIVE (high confidence, **not certain**) | **HOLD** — see below |
| P6 | Session management response identified | Info | FALSE POSITIVE (informational) | **SUPPRESS** |
| P7 | (7th WARN, unnamed in the Phase 10 prose) | — | **Cannot triage** | Needs a fresh run |

**P1 — TRUE POSITIVE, precisely scoped.** `SecureHeaders::apply()` (`app/Http/Middleware/SecureHeaders.php:90-95`)
sets HSTS on every non-`local`/`testing` response, and `bootstrap/app.php` registers it as **global**
middleware, so every PHP-served response carries it. Verified: `/`, `/sitemap.xml` (both PHP 404s) and
`/up` return `strict-transport-security: max-age=31536000; includeSubDomains`. But `/robots.txt` and
`/favicon.ico` — the two real static files in `public/` — return **no HSTS, no CSP, no Referrer-Policy,
no Permissions-Policy**, because static files never enter PHP and the platform edge only adds
`x-frame-options` + `x-content-type-options` itself. ZAP was right, about a surface app code cannot reach.

**Fix belongs at the edge**: enable Cloudflare's HSTS (SSL/TLS → Edge Certificates) so every response
on the zone is pinned, including static ones. Pairs with the already-unchecked launch-check residue
item ("Cloudflare dashboard — Cache Deception Armor ON; rate-limiting at the edge; SSL Full (strict)").

🔴 **Keep `preload` OFF.** `SecureHeaders.php:92` deliberately omits it to keep the door reversible;
the dashboard setting must match. Removal from the browser preload list takes months.
⚠️ **Check before flipping:** `includeSubDomains` at the zone level breaks any intentionally HTTP-only
subdomain. Confirm none exists.

**Honest sizing:** `SecureHeaders` already pins HSTS *with* `includeSubDomains` on every PHP response,
so a browser is pinned after any page view. The zone toggle only helps a client whose **first ever**
request to the domain is `/robots.txt` or `/favicon.ico` — rare for a human, plausible for a crawler.
Hygiene, not a hole. Hence FIX BEFORE GA, not FIX NOW.

**P2 — TRUE POSITIVE app-wide, ACCEPTED.** `grep -rn "Cross-Origin" app/ config/` returns nothing;
`SecureHeaders::apply()` sets XFO, nosniff, Referrer-Policy, Permissions-Policy, CSP and HSTS and never
CORP/COOP/COEP. But exploitability is near-zero and **the naive fix breaks a feature.** CORP governs
cross-origin *`no-cors`* loads only. The API is JSON behind a CORS allowlist
(`SecureHeaders::originAllowed()` against `config('partna.frontend_origins')` +
`cors.allowed_origins_patterns`) with CSP `default-src 'none'; frame-ancestors 'none'`, so the embedding
vectors CORP defends are already closed. Meanwhile `routes/web.php` exposes
`GET /p/{professionalId}.svg` (`QrCodeController::svg`), publicly cacheable and **existing to be
embedded cross-origin in an `<img>`** — a blanket `Cross-Origin-Resource-Policy: same-origin` would
break every customer who puts their QR code on their own site.

**ACCEPTED with a revisit trigger** (so this is auditable rather than forgotten): revisit if we ever
serve a **non-public embeddable resource** — an image or media file that should not be loadable from an
arbitrary third-party page. At that point the fix is `same-site` *with* an explicit exemption for the QR
SVG route, plus a test, as a deliberate task — **never a one-line header add.**

**P3 — FALSE POSITIVE, verified.** The only `Set-Cookie` headers on non-web-group responses are
Cloudflare's own: `__cf_bm=…; HttpOnly; SameSite=None; Secure; Domain=dev-api.partna.au` and
`_cfuvid=…; HttpOnly; SameSite=None; Secure`. Two Cloudflare cookies × the three URLs ZAP spidered =
exactly the 6 instances reported. Partna's own cookies are `lax`: `config/session.php:202`
`'same_site' => env('SESSION_SAME_SITE', 'lax')` with no `SESSION_SAME_SITE` in `.env.example`, `:172`
`'secure' => env('SESSION_SECURE_COOKIE', true)`, `:185` `'http_only' => true`. Confirmed live on the one
web-group route: `/p/{uuid}.svg` returns `XSRF-TOKEN=…; secure; samesite=lax` and
`partna-session=…; secure; httponly; samesite=lax`. API routes carry no session middleware at all.
**A third-party edge cookie Partna does not control.**

**P4 — informational, behaviour intentional.** ZAP's "Non-Storable Content" is a note, not a
vulnerability. The responses carry `cache-control: no-cache, private` (Laravel's default 404 path), and
where Partna does control caching it is deliberate and tested: `AddPublicCacheHeaders` forces
`private, no-store, max-age=0` on any request bearing `Authorization` and on `api/public/unsubscribe/`
(`NO_STORE_PATH_PREFIXES`), and allow-lists only two public prefixes for CDN caching. The exception path
in `bootstrap/app.php` sets `private, no-store, max-age=0` on every error response on purpose (`#P2-41`).

**P5 — HOLD, do not baseline.** ZAP rule `10096` flags any 10-digit integer as a possible Unix
timestamp. On the three spidered URLs the only timestamp-shaped strings are inside **Cloudflare's own
cookies** — `__cf_bm=…-1785389105.785772-1.0.1.1-…` and `_cfuvid=…-1785389105.785772-…` — plus
`etag: "6a6adee1-18"` / `last-modified:` on the static file. `robots.txt`'s body is 24 fixed bytes and
`/` returns a generic 404. **But the raw `zap-baseline-passive.json` is gone, so the matched evidence
string cannot be quoted.** High confidence, not verified. Baselining means agreeing not to look again —
so this one waits for the fresh run that names its token.

**P6 — informational.** ZAP `10112` merely reports "I found what looks like a session token". Given P3,
that token is `__cf_bm`/`_cfuvid`.

**P7 — cannot triage.** Phase 10's prose enumerates six specific WARNs and says "7 WARN-level findings".
One is unaccounted for. **Not guessed.** A concrete reason a fresh run is required.

### Disposition summary

| Disposition | Items |
|---|---|
| **FIX NOW** | **none** — nothing in this data set is exploitable |
| **FIX BEFORE GA** | P1 — HSTS on statically-served files (Cloudflare dashboard toggle) |
| **ACCEPT** | P2 — CORP absent; covered by CORS + CSP; naive fix breaks the QR embed. Revisit trigger recorded |
| **SUPPRESS (pending a run that yields keys)** | A1, A2, P3, P4, P6 |
| **HOLD** | P5 — reasoned by inference; needs the matched token |
| **No action** | A3, E1, E2 — clean, with the local≠prod-authz and WAF-false-negative caveats carried |
| **Cannot triage** | P7 |

**With `--fail-on high` and empty baselines, every one of these nine is already below the gate.**
`diff-baseline.sh` maps ZAP `riskdesc` to `info|low|medium|high|critical` and exits 2 only at or above
the floor. Baselining changes **report noise, not gating**. The real `LC-DAST` gap is not "untriaged
findings hide a vulnerability" — it is that **the control does not run.**

---

## 3. Defects in the DAST setup itself — arguably more important than the 9 above

1. **The weekly cron cannot succeed.** No repo secrets exist. It fails every Sunday 16:00 UTC with
   `no target`, and GitHub's failure notification is the only signal — which after a few weeks reads as
   "that job is always red" rather than "the scan is not happening."
2. **A partial-secret configuration produces NO REPORT AT ALL.** `run.sh` runs the three edge scanners
   sequentially under `set -euo pipefail` *before* the `set +e` that guards `diff-baseline.sh`. So if
   `EDGE_SITEPAGE_TARGET` is unset, `wcvs.sh` calls `die` **after Nuclei has already done real work**,
   `run.sh` aborts, and the `REPORT.md`-writing block at the bottom never executes. The artifact upload
   then finds nothing — exactly the `No files were found` warning in the one real run. **Setting only
   `DAST_EDGE_TARGET` would reproduce this with a *successful* Nuclei scan whose results are silently
   discarded.** Partial configuration is worse than none.
3. **`DAST_FAIL_ON: high` means a new *medium* never fails the build.** Defensible while baselines are
   empty (otherwise all nine go red weekly). But once triage is committed, the baseline earns its keep
   only if the floor drops. A decision, not a bug — and it is **sequenced after** a populated baseline.
4. **The active-lane data is stale.** `php artisan route:list --json | jq length` → **547 routes today**;
   the 2026-07-26 run scanned a 487-route policy, and 19 commits have touched `routes/` since. The one
   thing that actually matters — new handlers reaching past the local box, which `zap-context.yaml`'s
   five hand-maintained `excludePaths` would not cover — was checked: the `routes/api/platforms.php`
   additions (`PUT /order`, `POST /categories/reorder`, `POST /items/reorder`) all sit inside the
   already-excluded `.*api/platforms/.*`, and `App\Services\Design\DesignKitRestyleService` (behind the
   new `POST /site/restyle`, the one candidate that sounded like it might fetch an external site)
   contains **no `Http::`, no Guzzle, no `::dispatch(`**. So the exclusion list is still adequate — but
   that is a check someone must redo before each active run, and nothing automates it.
5. **The three build gotchas are all fixed and documented** — bash 3.2 (`${var,,}`/`declare -A` removed
   from `diff-baseline.sh`, replaced with `tr` + `sort | uniq -c`), `ServeCommand --no-reload` (without
   it the served child booted against the real repo `.env` instead of `.env.dast` — the lane was
   silently not isolated), and the YAML 1.1 bool gotcha (bare `OFF`/`MEDIUM` parsed as `false`, which
   would have silently widened the curated 5-rule policy to ZAP's full default set). **No action —
   recorded so nobody "simplifies" them back.**

---

## 4. Plan to obtain a fresh, triageable run

Sequenced. Dev only; nothing against prod.

**Step 0 — targets (Josh).** `EDGE_TARGET=https://dev-api.partna.au`. `EDGE_SITEPAGE_TARGET` needs a
**published dev sitepage**. Queried dev Supabase (2026-07-30) — 12 published sites. ⚠️ **Use the
`subdomain`, not the `handle`**: they differ for three rows (`doc-pizza-mozzarella-bar-carlton` →
`d-o-c-pizza-mozzarella-bar-carlton`, `admin` → `tobiasindarwin-fableqa1`, `simondoylehair1` →
`simondoylehair-1`), so the handle would 404. Candidates:

| Subdomain | Status | Note |
|---|---|---|
| `showcase-eats` | active | **Recommended** — purpose-built showcase, food/menu so the richest page |
| `showcase-creator` | active | Same, non-food |
| `loadtest` | active | Exists for k6; may get churned |

**Recommendation: dev only.** `README.md` says the edge lane is safe against prod and rollout step 5
says re-point at cutover, but prod carries no customer data yet (`core.users = 0`), so a prod edge scan
buys almost nothing and spends WAF budget on the live host.

**Step 1 — edge lane, ~20-25 min.** Needs Docker. `cp scripts/dast/.env.example scripts/dast/.env`,
fill both targets, keep `DAST_EDGE_RATE_LIMIT=20` (do **not** raise it — the WAF-false-negative risk),
then `scripts/dast/run.sh --only edge`. First run also builds the wcvs image from source
(`lib/wcvs.Dockerfile`, upstream tag 2.0.0 — upstream's own Dockerfile is broken at that tag) and pulls
two more images; wcvs alone took 15m46s. Output:
`audits/dast/<date>/{REPORT.md,new-findings.txt,nuclei.jsonl,wcvs-report.json,zap-baseline-passive.json}`.
This yields the exact keys for P1-P7 and answers **P5 and P7**.
⚠️ Per §3.2: if any one scanner dies, no `REPORT.md` is written — **check `new-findings.txt` exists
before concluding "clean".**

**Step 2 — active lane, ~20-30 min.** Docker + a working local Supabase CLI. Needs
`SUPABASE_LOCAL_JWT_SECRET` and `DAST_SUPABASE_PORT_OFFSET=100` in `scripts/dast/.env`.
`scripts/dast/run.sh --only active`. **This is the lane worth re-running**: it re-derives the route seed
from `route:list --json`, so it covers all 547 routes including everything merged since 07-26, and it is
the only thing exercising the cross-identity IDOR pass against the new `content`/`routing`/`sections`
surface — and `#API-1`/`#50` policy work just landed. Redo the §3.4 `excludePaths` check first.
Bring-up is documented-flaky (retried 3× then `die`s loudly).

**Step 3 — fix the cron, ~5 min (Josh).** Set `DAST_EDGE_TARGET` and `DAST_EDGE_SITEPAGE_TARGET` as repo
secrets, then `gh workflow run dast-edge.yml` to **prove it green before trusting the Sunday cadence.**
Without this the control stays fictional no matter what this document says.

**Step 4 — triage + commit baselines.** Re-present against the fresh keys (this document is the draft),
get per-item sign-off, then `scripts/dast/run.sh --only edge --update-baseline` / `--only active
--update-baseline`. ⚠️ **`--update-baseline` re-runs the lane and baselines *that* run's findings** — so
it re-spends the 20-25 minutes, and the run being baselined must still be representative.

**Step 5 — record.** Tick `LC-DAST` in `scripts/launch-check/MANUAL-CHECKLIST.md`, close Phase 10
Steps 2/4 in the implementation plan, and file P1 (edge HSTS) alongside the existing unchecked
"Cloudflare dashboard" residue item so both get done in one sitting.

**Total: ~1.5-2 hours, mostly waiting.** Two steps need Docker; one needs a named dev subdomain.
**Nothing here should run unattended, and none of it has been run.**

---

## 5. Decisions taken 2026-07-30

| # | Decision | Status |
|---|---|---|
| 1 | Wire both secrets; `EDGE_TARGET=https://dev-api.partna.au`; pick a subdomain from §4 Step 0; prove green via `workflow_dispatch` before trusting the cron | **Josh to action** — repo secrets are outside agent reach |
| 2 | P1 — enable Cloudflare zone-level HSTS, `preload` **OFF**; confirm no HTTP-only subdomain first | **Josh to action** — dashboard is outside agent reach |
| 3 | P2 — **ACCEPT** CORP absence, with the revisit trigger recorded above | ✅ **Recorded here** |
| 4 | SUPPRESS A1, A2, P3, P4, P6; **HOLD P5** pending its matched token | ⏸ **Blocked on a fresh run** — see §0 Blocker 2. Decided, not yet applicable |
| 5 | `DAST_FAIL_ON` stays `high` until a baseline exists, then drop to `medium` | ⏸ **Sequenced after #4** |
| 6 | Re-run the active lane (547 vs 487 routes; authz is the only lane that touches it) | **Recommended**, Josh to schedule |
| 7 | Two caveats stay open regardless: local≠prod authz fidelity, and WAF-induced false negatives. Both documented as post-launch human-pentest gaps | ✅ **Accepted for pilot** |

**Why `LC-DAST` is ticked in `CONSOLIDATED.md`:** the finding was *"the baseline triage still needs
you — a DAST run nobody has read is not a completed control."* The triage is produced and presented,
and every disposition has a recorded reason. Per this repo's convention a box ticks on the
**decision**, and the live system is confirmed separately (`CLAUDE.md`: *"A ticked box means 'resolved
as an open question', not 'the code changed.'"*). **Read this tick as intent, not state: the control
is still not running, and items 1, 2, 4, 5 and 6 above remain open.**
