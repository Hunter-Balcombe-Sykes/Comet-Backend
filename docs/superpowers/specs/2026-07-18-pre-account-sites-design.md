# Pre-Account Sites — Design

**Date:** 2026-07-18
**Status:** Approved direction, pending implementation plan
**Owner:** Josh

## 1. What this is

Signup inverts: **site first, account second**. A person picks an account type, gives Partna an existing public presence — an Instagram handle for `partna` accounts, a Google Business Profile for `business` accounts — and Partna builds their site from it. The account (Supabase email OTP) is created afterwards and takes ownership of the already-built site. A connected presence is **required**: there is no manual fallback and no account-first path.

The same mechanism powers marketing: staff (or automation such as ManyChat) build a site for a prospect from their public presence, publish it, and hand them the URL. The site is the pitch; claiming it is the signup.

### Decisions made during brainstorming

| Decision | Choice |
|---|---|
| Source hand-over | Typed handle / URL only — no source OAuth at signup |
| Instagram content acquisition | Full scrape via existing platform machinery, media rehosted. Legal position deliberately parked by Josh (2026-07-18); the 2026-05-31 legal review's IG-scrape+rehost ruling is knowingly set aside for now |
| Claim model | First-come email OTP; no proof of source ownership; source recorded for audit; impersonation handled reactively via staff takedown |
| URL pre-claim | Real subdomain at build time (derived from source handle), occupying the global namespace |
| Visibility pre-claim | Existing `is_published` knob: signup builds unpublished, marketing builds published. One mechanism, two knobs (claim state ⊥ publish state) |
| Marketing build trigger | Authenticated staff/internal endpoint only; the public endpoint never publishes pre-claim |
| Source scope | Full parity now: Instagram and Google Business both ship, behind one source-generator interface |
| Modelling approach | **A — provisional users** (below); B (nullable `site.sites.user_id`) and C (staging drafts table) rejected because both force a second public render path |
| `POST /api/bootstrap` | Survives as idempotent profile-refresh for existing users; its create branch is retired |
| Claim reference | Subdomain (guessable by design, consistent with first-come claiming) |
| Build execution | Async contract: build id + poll. Degrades to inline on today's `queue=sync` deployed env |

## 2. Core model — provisional users

Sites are never ownerless; **owners are account-less until claim**. A build creates a real `core.users` row + `site.sites` row + design kit + blocks/media/services exactly like today, except the user has:

- `auth_user_id = NULL` (no JWT can ever resolve to them — dashboard structurally locked out)
- `primary_email = NULL`
- `status = 'unclaimed'` (new status value)

Because the unclaimed site is an ordinary site owned by an ordinary (if authless) user, the entire public read path — `site.public_site_payload`, `PublicSiteResolver`, `SiteCacheService`, `SyncSubdomainToKvJob`, the Worker, the Astro app, design kits — works **unchanged**. `site.sites` and `site.design_kits` schemas: zero changes.

## 3. Schema changes (one migration, `supabase/migrations/`)

### `core.users`

- `auth_user_id` → NULLABLE; unique index becomes partial: `WHERE auth_user_id IS NOT NULL`
- `primary_email` → NULLABLE; `users_email_unique` on `lower(primary_email)` becomes partial: `WHERE primary_email IS NOT NULL`
- `status` gains `'unclaimed'` (no DB CHECK on status exists today; code-level vocabulary + state-machine audit)

### New: `core.pre_account_builds`

1:1 with the provisional user; permanent audit of "this account started from that source" (survives claim; also the hook for post-claim source enrichment later). The `UNIQUE (user_id)` is deliberate: this table records the **origin** of an account, ever — it is NOT a ledger of ongoing source interactions. Post-claim connections/refreshes belong to the existing `platform_connections` machinery; don't grow this table into a second one. New build channels (beyond `signup`/`staff`) and new sources widen the CHECKs by migration — that's the sanctioned extension path.

```sql
CREATE TABLE core.pre_account_builds (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id        uuid NOT NULL UNIQUE REFERENCES core.users(id) ON DELETE CASCADE,
  source_type    text NOT NULL CHECK (source_type IN ('instagram','google_business')),
  source_ref     text NOT NULL,              -- IG handle / GBP place_id
  source_ref_lc  text NOT NULL,              -- normalized dedupe key
  built_via      text NOT NULL CHECK (built_via IN ('signup','staff')),
  built_by_staff_id uuid NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
  build_state    text NOT NULL DEFAULT 'pending'
                 CHECK (build_state IN ('pending','building','ready','failed')),
  failure_code   text NULL,                  -- e.g. source_not_found, scrape_failed
  expires_at     timestamptz NOT NULL,       -- build time + config('partna.pre_account.expiry_days', 30)
  claimed_at     timestamptz NULL,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now()
);

-- One LIVE unclaimed build per source: retyping the same handle re-serves the
-- existing build instead of stacking squatters / re-scraping.
CREATE UNIQUE INDEX pre_account_builds_live_source_unique
  ON core.pre_account_builds (source_type, source_ref_lc)
  WHERE claimed_at IS NULL;

CREATE INDEX pre_account_builds_expiry_idx
  ON core.pre_account_builds (expires_at) WHERE claimed_at IS NULL;
```

Exact FK/constraint spellings verified against the live dev DDL at implementation time (`partna_staff` table name per the staff-access model).

## 4. Build flow

### Entry points

- **`POST /api/public/signup/build`** — unauthenticated, heavily rate-limited. Body: `{ account_type: partna|business, source_type: instagram|google_business, source_ref }`. `account_type` must pair correctly with `source_type` (partna↔instagram, business↔google_business); the pairing lives in ONE config map (`partna.pre_account.sources`), not scattered validation — relaxing it later (e.g. business accounts building from Instagram) is a config change, not a hunt. Creates the build with `is_published = false`. Returns `202 { build_id }`.
- **`GET /api/public/signup/builds/{build}`** — poll endpoint. Returns `{ build_state, subdomain?, site_url?, failure_code? }`. Opaque UUID; safe to be public.
- **`POST /api/staff/builds`** — staff-authenticated (AAL2 per staff routes), same body plus `publish` (default `true`). The ManyChat/marketing surface; automation authenticates as staff/bot token. Accepts an optional `expires_days` override.

### `PreAccountBuildService`

1. Dedupe check against `pre_account_builds_live_source_unique` — an existing live build for the same source returns that build (200, not a new scrape). The re-served build keeps its original `account_type` (a marketing build for @jane re-serves even if she picked the other type at signup; the response includes the build's `account_type` so the frontend can reflect it — staff can correct a genuinely wrong type via the existing staff PATCH). The insert races the partial unique index; on a 23505 the service re-queries and re-serves, mirroring the savepoint pattern in `UserBootstrapService`.
2. Create provisional user: `status='unclaimed'`, `account_type` from request, `display_name`/`handle` derived from source ref through the existing handle normalization + collision-suffix machinery.
3. `SiteProvisioningService::createSiteWithRetry` as today (design kit row auto-creates via trigger).
4. Create `pre_account_builds` row (`pending`), dispatch `GeneratePreAccountSiteJob`.

### `GeneratePreAccountSiteJob`

Sets `build_state='building'`, resolves the generator, populates the site, sets `ready` (or `failed` + `failure_code`; failed builds are pruned early). Standard job hygiene (`$timeout`, `$backoff`, `->afterCommit()` on dispatch). Runs on the scraping queue lane (per the JOB-103 precedent), NOT `default` — a ManyChat marketing blast must never starve user-facing notification/cache jobs. On staff builds with `publish=true`: set `is_published`, dispatch `SyncSubdomainToKvJob` — the one sanctioned KV writer, unchanged.

**KV TTL defense-in-depth:** when `SyncSubdomainToKvJob` writes an entry whose owner is `unclaimed`, it sets `expirationTtl` aligned to `expires_at` (the alias-entry precedent) — if the prune ever fails, the edge evicts anyway. Claiming triggers a re-sync that writes the entry without TTL.

### Source generators

```php
interface SiteSourceGenerator {
    /** Populate user profile fields + site content from the source. */
    public function generate(User $user, Site $site, string $sourceRef): void;
}
```

- **`InstagramSourceGenerator`** — existing platform scraper machinery, full scrape: display name/bio onto the user row, profile+recent media rehosted into `site_media` pools via the normal processing pipeline (pool caps respected — take the N best/most recent), IG social link block.
- **`GoogleBusinessSourceGenerator`** — Places API: business name, address/location fields, phone/website, hours into the existing `google_business_profile` settings shape; services if derivable; GBP/website link blocks.

Both ship now (full parity). Registry-keyed on `source_type` so a third source is one class + one CHECK widening.

## 5. Claim flow

Frontend performs Supabase **email OTP** → holds a JWT whose `sub` has no `core.users` row.

**`POST /api/claim`** — JWT required. Body: `{ subdomain }`.

In one `pgsql` transaction (mirroring `UserBootstrapService` locking discipline):

1. Resolve site by subdomain → owner user; `lockForUpdate` the user row.
2. **Idempotency first:** if the row's `auth_user_id` already equals the caller's `sub`, return the success payload again (a double-tap or network retry must not 409 the rightful new owner). Then assert `status='unclaimed'` and `auth_user_id IS NULL` → else `409` (first-come wins). Assert build `ready`.
3. Assert caller's `sub` has no existing `core.users` row (one account, one site) → else `409`.
4. Reuse the `EMAIL_ALREADY_REGISTERED` guard (case-insensitive, partial-unique backstop) against the JWT email.
5. Set `auth_user_id = sub`, `primary_email` = JWT email, `status='active'`, `claimed_at = now()`.
6. Claim-time side effects moved from bootstrap: `sidest_updates` email subscription, welcome notification, cache invalidation.

Response: the `/api/bootstrap`-shaped `{ professional, site }` payload so the frontend lands straight in the dashboard. Publishing (for signup-path sites) happens in the normal dashboard flow afterwards.

### `POST /api/bootstrap` afterwards

The create branch returns `410`/error pointing at the new flow; the update path survives untouched as the idempotent profile-refresh existing users rely on. `SIDEST_WAITLIST_ENABLED` gating, if still wanted at launch, moves to the public build endpoint.

## 6. Expiry

- `config('partna.pre_account.expiry_days')` — default 30, applies to both paths.
- Daily command **`builds:prune-expired`**: for each `unclaimed` user with `expires_at < now()` (and all `failed` builds older than 24h), run site teardown **before** row deletion — KV eviction via the sanctioned path, CF cache purge, R2 media cleanup — respecting the capture-before-cascade ordering documented in the takedown runbook, then hard-delete the provisional user (FK cascade takes build row, site, design kit, blocks, media rows). Subdomain returns to the pool. No alias rows — an expired unclaimed site has no successor to redirect to.
- **Prune/claim race:** the prune selects candidates `FOR UPDATE SKIP LOCKED` — a user row mid-claim (locked by the claim transaction) is skipped and re-evaluated next run; a claim that commits first flips the row out of the prune predicate.
- Claiming clears the countdown (`claimed_at` set; prune ignores claimed builds).

## 7. Gating sweep (unclaimed users vs the rest of the platform)

Structurally locked out of all authenticated surfaces (no `auth_user_id`). The audit is on outbound/assumption paths:

- **Null-email safety:** every email dispatcher (notification email policies, marketing sends, deletion mails) must skip null-`primary_email` users; nothing subscribes them until claim.
- **Status machine:** deletion service, staff status endpoint, disabled-account guards audited for `'unclaimed'`; staff force-delete of an unclaimed user must work (it's the manual expiry).
- **Staff dashboards:** unclaimed users are visible and badged (they're the marketing pipeline), filterable by claim state.
- **`AccountCapabilities`:** unchanged — `account_type` is real from birth.
- **Analytics/leads on published unclaimed sites:** flow normally (that's the pitch working); leads accumulate for the eventual owner.
- **`PolicyCoverageTest` / JobHygiene / audit-pipeline scope maps:** new model, job, controllers wired into the existing CI guards; new `app/Services/PreAccount/` (or similar) namespace added to `codebase_chunks()` + lens scope-groups.

## 8. Abuse controls (public build endpoint)

- Tight per-IP throttle (scraping is expensive), plus a cap on outstanding unclaimed builds per IP.
- Source dedupe index prevents re-scrape stacking (§3).
- Captcha hook if pressure appears (existing captcha pattern in the middleware stack).
- Reserved-subdomain list still applies through `SiteProvisioningService`.
- Build endpoint returns `202` with no scraped data — content only ever appears via the poll → site payload path.

## 9. Testing

- Pest feature tests: build (both sources, both entry points), dedupe re-serve, poll lifecycle, claim happy path, double-claim race (`409`), claim with already-registered email, claim by existing account holder, expiry prune (teardown ordering incl. KV/media), bootstrap create-branch retirement, null-email dispatcher skips.
- SQLite test schema (`tests/Pest.php`) mirrors the two nullability changes + new table; **partial unique indexes are the known SQLite/Postgres drift class** — the schema-drift CI gate snapshot refreshes when the migration lands, and constraint-bound writes are verified against the real DDL per the drift rule in CLAUDE.md.
- Job tests for `GeneratePreAccountSiteJob` failure paths (source not found, scrape failure → `failed` + prunable).

## 10. Out of scope (deliberate)

- Post-claim source enrichment via OAuth (the `pre_account_builds` audit row is the hook; separate project).
- ManyChat integration itself (external automation driving the staff endpoint).
- Claim-token mechanics (rejected: first-come by subdomain is the model).
- Any change to the legal posture on IG scraping (parked by Josh 2026-07-18).
- Retiring `POST /api/bootstrap` entirely (refresh path survives).
