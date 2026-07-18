# Handoff prompt — write the implementation plan for Pre-Account Sites

Paste everything below the line into a fresh Claude Code session in this repo.

---

Write the implementation plan for the **Pre-Account Sites** feature — signup inverts to site-first (build a site from a typed Instagram handle / Google Business Profile, account created after via Supabase email OTP, first-come claim), plus staff-triggered pre-built marketing sites. Use the **superpowers:writing-plans** skill.

**Spec (approved, source of truth):** `docs/superpowers/specs/2026-07-18-pre-account-sites-design.md` — read it fully first. Save the plan to `docs/superpowers/plans/2026-07-18-pre-account-sites.md`.

## Decisions are SETTLED — do not re-litigate
The spec's §1 decisions table was agreed with Josh on 2026-07-18. In particular do NOT re-open: typed-handle sources (no OAuth), full IG scrape with the legal position deliberately parked, first-come email-OTP claiming, real subdomain at build, provisional-users modelling (Approach A), bootstrap surviving refresh-only, claim-by-subdomain, async build contract. If you find a genuine technical contradiction while planning, flag it for Josh — don't silently redesign.

## Verify premises against CURRENT code before planning each area
The spec names classes/columns from a design session — confirm each against the repo and live dev DB before writing tasks around them (auto-generated designs have been bitten by phantom columns before):

- **Live dev DDL:** `core.users` (`auth_user_id`, `primary_email`, `handle_lc` NOT NULLs; `users_auth_user_id_unique`, `users_email_unique` index definitions), `site.sites` constraints, and the exact staff table name for the `built_by_staff_id` FK. Check via Supabase MCP against dev ref `glncumufgaqcmqhzwrxm` — **NEVER** the prod ref. Note: repo baseline SQL still says `professional_id`; the live DB is what counts.
- **Bootstrap/provisioning seams:** `App\Services\User\UserBootstrapService` (locking discipline, savepoint pattern, `EMAIL_ALREADY_REGISTERED` guards, `ensureSidestUpdatesSubscription`, welcome notification) and `App\Services\User\SiteProvisioningService` (`createSiteWithRetry`, `subdomainBaseFromHandle`, reserved list, collision-suffix machinery).
- **Instagram scrape machinery:** find the actual current scraper entry points behind `PlatformRegistry` (Apify-based) and the scraping queue lane name (JOB-103 introduced `redis_scraping`). The generator must reuse, not duplicate.
- **GBP:** the existing `google_business_profile` site-settings shape (`GET/PUT /api/site/google-business-profile`) and whether a Google Places API key/config already exists or the plan must add one.
- **Media ingestion:** how scraped/rehosted images enter `site_media` + variants server-side without an HTTP upload (existing service seam vs new one), pool caps in `config/partna.php`.
- **KV:** `SyncSubdomainToKvJob` payload shape and whether `expirationTtl` is already supported by its writer path (aliases use it).
- **Status machine:** every place that branches on `core.users.status` (deletion service, staff status endpoint, disabled guards, middleware) — the plan needs an explicit task sweeping these for `'unclaimed'`, and a sweep for null-`primary_email` safety in every email dispatcher.

## Hard repo rules the plan must respect
- Schema changes = raw SQL in `supabase/migrations/` only (composer guard rejects Laravel migrations). One migration; partial unique indexes as specced.
- SQLite test schema in `tests/Pest.php` must mirror the nullability changes + new table; partial-index semantics differ on SQLite — plan Postgres-DDL verification per the schema-drift rule in CLAUDE.md, and a schema-drift baseline/snapshot refresh after the migration lands.
- `SyncSubdomainToKvJob` stays the ONLY KV writer. Policies via `authorizeForUser` + registration in `AppServiceProvider::boot()` (PolicyCoverageTest enforces). New namespaces wired into the audit pipeline's `codebase_chunks()` + lens scope-groups (AuditPipelineIntegrityTest enforces). Jobs need `$timeout` + `$backoff` (JobHygiene), `->afterCommit()` on dispatch — never a typed `$afterCommit` property.
- Resources for all API responses; Form Requests for validation; 404-not-403 on public routes for missing/inaccessible resources.
- Migrations do NOT auto-run on deploy (`migrate --force` commented out) — the plan's migration task must include applying it to dev Supabase via `supabase db push` / MCP `apply_migration`, with Josh in the loop.

## Plan-shape guidance
- Phase the work so each phase lands green independently: (1) migration + model/nullability groundwork + gating sweeps, (2) build service + generators + job + endpoints, (3) claim flow + bootstrap create-branch retirement, (4) expiry prune + KV TTL, (5) staff surface + docs (`docs/api.md`, `AI_CONTEXT.md` — both currently stale on account_type; update the parts this feature touches).
- Frontend is a separate repo (read-only reference) — the plan covers backend only, but must list the frontend-facing contract changes (new endpoints, bootstrap create-branch 410) in one section for handoff.
- Josh commits and pushes — plans never include pushing.

Write the plan, then ask Josh to review it before any execution session starts.
