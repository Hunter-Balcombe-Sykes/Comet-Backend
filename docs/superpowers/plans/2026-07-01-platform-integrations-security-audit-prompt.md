# Security Audit Prompt — Platform Connections & Integrations subsystem

> **How to use:** Paste everything under the line into a fresh **Opus** session in this backend repo,
> run from a **default output style** (not explanatory — otherwise narration leaks into the audit file).
> This prompt drives a **two-vehicle security audit**: (1) run the standard `audit.sh --bundle security`
> pipeline for breadth, then (2) a Claude-native deep pass on the highest-risk flows the pipeline reasons
> about poorly (cross-file authorization, SSRF egress, token/secret handling). Both streams **merge into a
> single canonical `CONSOLIDATED.md`** in the run folder — fix-flow-ready, so `execute audit <that file>`
> works directly afterward. It is **report-only until the final merge**: build findings, don't fix code.

---

You are the lead **security** reviewer for the **Platform Connections & Integrations** subsystem of the
Partna Laravel 12 + Supabase backend. This is the feature area Tobias rebuilt over the last ~3 weeks: the
6-plan Platform Integrations Registry redesign (`f2ded51e`→`c3ead5f1`) plus the SmartLinks removal that
relocated the shared URL/SSRF primitives into `App\Services\Http`. It lets a user connect external platform
accounts (Instagram, Fresha, Square, Google Business, Shopify, ~30 link/embed/feed platforms), stores a
scraped snapshot in `site.platform_connections.payload`, refreshes it on a cron, and renders it publicly.

Your job is to find **security and tenant-isolation** defects — by reading the actual code, not skimming —
and ship one tiered `CONSOLIDATED.md`. A shallow "looks secure" is a failure. Every finding needs a
`file:line` citation and verbatim evidence. **If you did not read the file, you may not make a claim about it.**

## Scope

**In scope** (the recently-built integration surface):
- `app/Services/Platforms/**` — registry (`Registry/*`), `ProviderDetector`, `ShopProviderDetector`,
  `PlatformRefresher`, `PlatformInput`, strategies (`Strategies/**`), scrapers (24 `*Scraper`/`*Service`/
  `*Api` clients + `Menu/**` drivers), payload DTOs (`Payloads/**`), normalizers.
- `app/Services/Http/**` — `SafeUrlFetcher`, `MetadataParser`, `ParsedUrl`, `SafeUrlException` (the SSRF guard
  + URL primitives relocated out of SmartLinks in `5f82b2e0`).
- `app/Http/Controllers/Api/Platforms/**` (34 controllers) + `Concerns/ManagesIntegrationConnection.php`.
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php` — the **unauthenticated** read path.
- `app/Http/Requests/Platforms/**` (38 Form Requests) and `app/Http/Resources/Platforms/**`
  (esp. `PublicIntegrationConnectionResource`, `InstagramConnectionResource`, `ShopBrandResource`).
- `app/Jobs/Platforms/**` — `InstagramConnectJob`, `DeleteMirroredMediaJob`, `GoogleBusinessEnrichJob`,
  `MenuFetchJob`.
- `app/Models/Core/Site/IntegrationConnection.php` + `app/Observers/Core/IntegrationConnectionObserver.php`.
- `app/Policies/IntegrationConnectionPolicy.php`, `app/Providers/PlatformRegistryServiceProvider.php`,
  `app/Rules/PlatformInRegistry.php`.
- `app/Console/Commands/{RefreshIntegrationConnections,EnforcePlatformLinkCap,RetryUnavailableMenus}Command.php`,
  relevant `routes/console.php` entries.
- `routes/api/integrations.php` (authenticated routes under `/integrations` **and** legacy `/platforms`),
  plus the public routes in `routes/api.php` (~lines 89, 128, 131).
- Config: `config/services.php` (twitch/kick/fresha/apify/google_maps secrets), `config/partna.php`
  (`limits.platforms.*`, `social_platforms`, `streaming_platforms`, `platform_links_max`).
- Schema: the `platform_connections` migrations — `20260602150238_create_platform_connections.sql`,
  `20260609000000_harden_platform_connections.sql` (RLS), `20260616000000_allow_pending_refresh_status.sql`,
  `20260624010000_schema_hardening_constraints.sql`, `20260629120000_drop_platform_connections_check.sql`,
  and the menu side-tables (`20260617130000_create_menus.sql`, `20260619050000_menu_relational_redesign.sql`).

**Out of scope — do not audit, do not report:**
- The scale/caching/queue posture. **This was already swept today** in
  `audits/sweeps/2026-07-01-connection-scale-health/CONSOLIDATED.md` (14 findings, 0 P0, all on the
  write/refresh side). Read it once so you don't re-derive its findings — your job is the **orthogonal**
  security/tenant-isolation axis. If a security defect happens to touch the same file, cite the scale finding
  and focus only on the security consequence.
- OAuth callback / token-refresh / redirect-URI / state-nonce logic — **none exists.** `OAuthConnect` and
  `WebhookRefresh` are empty, unimplemented seam interfaces. Do not hunt for or report the absence of OAuth
  hardening; the connect model is URL/handle-paste + server-side scrape. Reallocate that effort to §D–§F.
- The removed SmartLinks / commerce / booking features. Proposals to restore or guard removed features are
  always-drop (see the drop list).

## Ground truth — read these first, yourself (do not delegate)

1. **This repo's `CLAUDE.md`** and **`AI_CONTEXT.md`** — the authorization doctrine (Supabase JWT →
   `Auth::user()` is null; actor at `$request->attributes->get('professional')` / `$this->currentUser()`),
   the Policy pattern (`authorizeForUser`, never inline `abort_unless`), the **403-vs-404 anti-enumeration
   rule**, `AccountCapabilities` gating, and the **SQLite-vs-Postgres schema-drift warning** (a passing
   SQLite suite does NOT prove a NOT NULL / CHECK-bound Postgres write is safe).
2. **`scripts/audit/adjudicate-prompt.md`** — the canonical finding format, tier definitions + calibration
   anchors, the **14-item always-drop list**, and the Partna authorization doctrine block. Your final output
   must obey this exactly. Internalize the drop list before you start so you don't burn effort on false
   positives (CSRF-on-JWT, N+1 on small per-user reads, Eloquent "SQLi", missing-HTTPS, etc.).
3. **Today's scale-health CONSOLIDATED** (see out-of-scope) — read once, for de-confliction only.
4. The **prior Instagram SSRF** history: it was a CRITICAL SSRF in the old `InstagramController::mirror()`,
   fixed via `SafeUrlFetcher` + host allowlist + image-only content-type. The mirror logic now lives in
   `InstagramConnectJob`. Confirm the fix survived the refactor and wasn't weakened (see §D).

## Vehicle 1 — run the security bundle first (breadth)

Run the standard pipeline against the scope. **Never run two `audit.sh` at once.** This produces a
first-pass `CONSOLIDATED.md` you will later merge your deep-pass findings into:

```bash
scripts/audit/audit.sh --bundle security --name connection-security \
  --scope app/Services/Platforms \
  --scope app/Services/Http \
  --scope app/Http/Controllers/Api/Platforms \
  --scope app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php \
  --scope app/Http/Requests/Platforms \
  --scope app/Http/Resources/Platforms \
  --scope app/Jobs/Platforms \
  --scope app/Models/Core/Site/IntegrationConnection.php \
  --scope app/Observers/Core/IntegrationConnectionObserver.php \
  --scope app/Policies/IntegrationConnectionPolicy.php \
  --scope app/Providers/PlatformRegistryServiceProvider.php \
  --scope app/Rules/PlatformInRegistry.php \
  --scope app/Console/Commands/RefreshIntegrationConnectionsCommand.php \
  --scope routes/api/integrations.php \
  --scope supabase/migrations/20260602150238_create_platform_connections.sql \
  --scope supabase/migrations/20260609000000_harden_platform_connections.sql \
  --scope supabase/migrations/20260629120000_drop_platform_connections_check.sql
```

The `security` bundle = 5 lenses: **security · schema-rls · configuration-hygiene · edge-worker ·
privacy-compliance**. Output lands at `audits/sweeps/<today>-connection-security/CONSOLIDATED.md`. Report
the tier counts, then proceed to Vehicle 2. Do **not** hand the user this file yet — it's the substrate you
merge into.

## Vehicle 2 — Claude-native deep pass (depth on what the pipeline reasons about poorly)

The DeepSeek→Sonnet pipeline is weak at multi-file reasoning: tracing an authorization decision across
controller → concern → policy, following a URL from request through detector to a `fetch()`, or proving a
field never leaks. Do that yourself. **Method: gather-then-judge.** Use **Haiku `Explore`/general-purpose
agents for breadth** (read assigned files end-to-end, return `file:line` + verbatim quotes — never a
verdict); reserve **your Opus judgment** for the security calls. Then spot-read the highest-risk files
yourself to confirm every inventory you rely on.

### The directed leads (these are where the risk actually is — hit every one)

**A. Tenant isolation / authorization — no cross-user access.**
- Every read/write/forget path flows through `ManagesIntegrationConnection`. Confirm each calls
  `authorizeForUser($user, 'view'|'create'|'update'|'delete', …)` — trace the concern, not just the policy.
- `IntegrationConnectionPolicy` returns **404 (not 403)** for non-owner (anti-enumeration). Verify it, and
  verify `denyIfPendingDeletion` on create/update/delete.
- The multi-account key is `acct-<sha1(...)>`. Confirm a user cannot address another user's connection by
  forging `resource_id` / the account key / a raw UUID — walk the lookup that resolves a request to a row.
- The manual refresh route `POST {base}/{platform}/refresh` is the one user-influenced `{platform}` path
  segment. Confirm it's gated on `registry->isRefreshable()` **and** ownership before any fetch fires.
- Is there any by-UUID or non-HTTP entry (job, command, internal call) that mutates a connection **without**
  going through the policy? The routes lean on `user.api` for the owner binding; the policy is the
  defense-in-depth layer — prove both are actually present on every path.

**B. The dropped DB CHECK — app-layer validation is now the *only* gate.**
- `20260629120000_drop_platform_connections_check.sql` removed the `platform` CHECK. The model's `saving()`
  hook (SEC-1, `4581d0c3`) validating against `PlatformRegistry` is the sole thing preventing an arbitrary
  `platform` string from being stored. Pressure-test it: does it fire on **every** write path (create,
  update, `updateOrCreate`, mass-assignment, `->save()` after a non-dirty→dirty transition)? Can a write
  that leaves `platform` non-dirty but mutates `payload` slip through? Is `platform` guarded against
  mass-assignment? What is stored on Postgres if the hook is bypassed (no DB backstop now)?
- Cross-check on **both** SQLite and Postgres per the drift warning: the `payload jsonb NOT NULL` and the
  `last_refresh_status` CHECK (`ok|unavailable|error|pending`) are Postgres-only — a write that violates them
  passes SQLite CI green and 500s on real Postgres. Flag any payload/status write not proven against the DDL.

**C. RLS & schema posture.**
- `20260609000000_harden_platform_connections.sql` enables RLS default-deny with `app_backend` BYPASSRLS.
  Confirm the app connects as a role that the RLS model actually intends, that no policy accidentally opens
  the table to `anon`/`authenticated`, and that `search_path` handling can't be abused. Same check for the
  menu side-tables.

**D. SSRF / scraper egress — THE priority. Two guards exist; they are not equal.**
- **Enumerate every outbound fetch** in `app/Services/Platforms/**` and `app/Jobs/Platforms/**`. For each,
  determine whether it goes through `SafeUrlFetcher::assertSafe()` (resolves host, rejects
  private/reserved/loopback/link-local incl. `169.254.169.254`, re-validates each redirect hop) or a **bare
  `Http::` / direct client**. Every user-pasted-URL fetch that does **not** use `SafeUrlFetcher` is a finding.
- **`InstagramConnectJob::mirrorOne()/mirrorVideo()`** fetch scraper-supplied CDN URLs and `Storage::put()`
  to R2 using a **host-string allowlist** (`cdninstagram.com`, `fbcdn.net`) + `withoutRedirecting()` +
  content-type + 50 MB cap — but **no IP-resolution check**. Judge: can a malicious/compromised scraper
  response or a subdomain-takeover / DNS-controlled host under those suffixes point the fetch at an internal
  IP? Is host-string allowlisting sufficient here, or should it also run through `SafeUrlFetcher`? This is the
  exact class of bug that was previously CRITICAL — grade it seriously.
- **`FreshaController::fetchEmployeeServices()`** POSTs to hardcoded `fresha.com/graphql` via bare
  `Http::withHeaders()` (spoofed origin/operation headers + pinned persisted-query hash). The URL is fixed,
  so SSRF risk is low — but check: is any part of that request body/headers attacker-influenced, and does the
  response get stored/rendered without sanitization?
- `DeleteMirroredMediaJob` refuses prefixes not under `platforms/` — verify that path-traversal guard is
  airtight (no `..`, no absolute-path escape, no user-controlled folder segment reaching outside the tenant).
- Content-type / size validation on **every** rehosted-media path (not just Instagram): confirm a fetched
  resource can't be an HTML/SVG/script masquerading as an image, and can't be an unbounded download.

**E. Secrets, tokens & payload leakage.**
- `payload` is cast to plain `array` — **no encryption**. Confirm nothing sensitive is stored there that
  would matter if the row leaked (it holds scraped public snapshots, but verify no API keys, no scraper
  tokens, no Fresha hash, no internal credentials ever land in `payload`).
- Server-side secrets live in config (`twitch/kick.client_secret`, `apify.token`,
  `fresha.booking_init_hash` — note it has a **hardcoded default**, `google_maps.server_api_key`). Confirm
  none are logged, serialized into a payload, returned in any Resource, or exposed via the public config feed
  (`GET /public/config/integrations`). Grep the scrapers for `Log::*` calls that dump request URLs/headers/
  tokens. Confirm no `env()` call outside `config/`.
- The public read Resource (`PublicIntegrationConnectionResource`) must strip internal fields (`_folder`,
  `source`) and the `social_platforms` server-side-only fields (`host_allowlist`, `url_path_extractor`).
  Trace what it emits field-by-field; a stored internal field reaching the public JSON is a finding.

**F. Public endpoint — enumeration & exposure.**
- `PublicIntegrationController` is unauthenticated and edge-cached. Confirm it returns **404** (never 403 or
  a distinguishable error) for a missing/private handle, and that it can't be walked to enumerate which users
  have which platforms connected. Confirm the allowlist (guarded by `PublicIntegrationAllowlistTest`)
  actually restricts which platforms/fields are exposed, and that a newly-added platform defaults to
  **not-exposed** (fail-closed) rather than leaking until someone remembers to gate it — SEC-2 (`75d64261`)
  made the public payload fail-closed; verify that's intact.

**G. Input validation on connect.**
- Each `Connect*Request` validates a pasted URL/handle. Confirm the `handle_pattern` / `host_allowlist`
  regexes in `config/partna.php` can't be bypassed (e.g. `@evil.com`, userinfo tricks, unicode/IDN homographs,
  `javascript:`/`data:` schemes) to smuggle a non-platform URL into the detector and downstream fetch.
- `ProviderDetector` does host-level matching only and defers strict validation to connect — verify that
  deferral is honored and there's no path where detection alone triggers a fetch of an unvalidated host.

### What's already tested (don't re-report a covered gap as if it's open — verify the test actually holds)

`SafeUrlFetcherTest`, `IntegrationConnectionGuardTest`, `PlatformConnectionModelTest`,
`IntegrationConnectionPolicyEnforcementTest`, `IntegrationConnectionPolicyTest`,
`PlatformConnectionAuthorizationTest`, `PublicIntegrationAllowlistTest`, `PublicPlatformEndpointTest`,
`PlatformResourceContractTest`, `InstagramAsyncConnectTest`, `InstagramR2CleanupTest`,
`RegistryCoverageTest`, `PlatformInRegistryRuleTest`, the golden-master
(`IntegrationContractGoldenMasterTest`). For each directed lead above, first check whether a test already
locks the behavior — if it does, either confirm the behavior is safe (and don't report it) or show the test
has a gap the finding exploits (a **test-coverage** finding is valid if a real security path is unguarded).

## Merge — one canonical CONSOLIDATED.md (the only deliverable)

Fold Vehicle 1 (bundle output) and Vehicle 2 (deep-pass findings) into the **single**
`audits/sweeps/<today>-connection-security/CONSOLIDATED.md`, deduped and renumbered. Emit it in the **exact
canonical format** from `scripts/audit/adjudicate-prompt.md`:

- Header block: **Scope** (lens list + paths audited) · **Findings at a glance** (P0–P3 count table) ·
  **Execution policy** (Plan: Opus 4.8 · Implement: Sonnet 4.6 · Review: separate Sonnet 4.6 · combine
  plan+impl for S/XS · trigger `execute audit <this file>`). Open with a one-line **Horizon read** that
  states this is the security/tenant-isolation complement to the same-day scale-health sweep.
- `## Progress` block (per-tier `0 of N complete`).
- Tiered findings **P0 → P1 → P2 → P3**, most-urgent last within each tier. Every finding carries:
  `- [ ] **#ID** · Pn — title`, then **Where** (`file:line`) · **Affects** · **Effort** (S/M/L/XL) ·
  **What to do** (action bullets) · **Technical** (one paragraph for the engineer) · **Plain English**
  (2–4 sentences, founder-readable, no Laravel/Supabase jargon) · **Evidence** (verbatim source excerpt).
- ID prefixes by lens: `SEC-*` (auth/policy/SSRF/secrets), `SCHEMA-*` (RLS/constraints), `CFG-*` (config
  hygiene), `EDGE-*` (Cloudflare/edge-cache), `PRIV-*` (PII/export/delete/retention). Renumber sequentially
  within each tier after dedup.
- `## Suggested Bundled Sessions` — group findings sharing a file/root-cause into `execute audit` sessions.
- `## Standalone — do NOT bundle` — **always** list here: every P0, anything touching auth/authorization,
  any DB/migration/schema change, any L/XL item. (SSRF and the dropped-CHECK findings almost certainly land
  here.) Write "None" under either section only if genuinely empty.

Tier calibration (from the adjudicate prompt): **P0** = exploitable today by a real user / total auth bypass
/ data exposure on a plausible path. **P1** = real security gap that ships bad behavior in a known scenario.
**P2** = hardening / defense-in-depth / observability gap. **P3** = polish. A cross-tenant read/write or a
working SSRF to an internal IP is **P0**. "Host-string allowlist is weaker than IP-resolution but not
currently exploitable" is **P1/P2** — state which and why.

## Verification (evidence, not assertion)

- Run the relevant guard tests by name and **show output** — do not claim "tests pass" without it:
  `php artisan test tests/Feature/Platforms tests/Feature/Security tests/Unit/Http tests/Unit/Platforms`.
  Run in the **main checkout**, never a worktree, never concurrently with another test runner.
- For any finding asserting a Postgres-only failure (NOT NULL / CHECK / RLS), verify against the actual
  `supabase/migrations/` DDL — a green SQLite suite does not disprove it.
- Do **not** apply fixes. This produces findings; the separate `execute audit` flow fixes them.

## Hard rules
- **No claim without a citation.** Every finding → `file:line` + verbatim evidence, or it's dropped.
- **Obey the always-drop list** in `adjudicate-prompt.md` — no CSRF-on-JWT, no N+1 on small per-user reads,
  no Eloquent-"SQLi", no missing-HTTPS, no reintegration findings, no intentional-dormancy findings.
- **Read, don't skim.** Whole files for `SafeUrlFetcher`, `InstagramConnectJob`, `ManagesIntegrationConnection`,
  `IntegrationConnectionPolicy`, the model, and `PublicIntegrationConnectionResource`. Grep is for finding,
  never for concluding.
- **De-conflict with the same-day scale-health sweep** — security axis only; don't re-report scale findings.
- **One artifact:** the merged `CONSOLIDATED.md` in canonical format. No prose summary after the Standalone
  section; no separate report.
- **Be a skeptic, not a cheerleader.** Your value is the cross-tenant read or the SSRF you catch. If it's
  genuinely solid, say so plainly — but only after you've tried to break it.
