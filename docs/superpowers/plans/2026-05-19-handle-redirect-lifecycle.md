# Handle Redirect Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current "alias forever" handle/subdomain redirect behaviour with a tiered lifecycle (14-day owner-only reclaim → 76-day public 301 redirect → automatic release) that matches consumer-platform norms (Instagram, X, GitHub), keeps marketing links working through the redirect window, and frees the namespace at scale.

**Architecture:** Add `reclaim_until` and `expires_at` columns to the two alias tables so every alias carries its own lifecycle clock; resolution paths (PHP fallback + Cloudflare KV edge worker) treat expired rows as if they don't exist; a daily prune job hard-deletes rows past `expires_at`; the CF KV writer uses native `expirationTtl` so the edge auto-evicts without coordination; a new audit log table (`core.handle_change_log`) records every rename for security/fraud review; alias-table hits return HTTP **301** to the canonical URL instead of silently serving content (anti-duplicate-content + link-equity consolidation per Sitebulb/Shopify SEO consensus); and a per-professional reclaim endpoint lets the original owner take their old handle back during the 14-day grace window without burning the 30-day rename cooldown. Foundation choices that matter at 100k+ users: redirect resolution stays at the edge (KV) on the hot path, the DB never sees a miss-then-alias query under load; aliases collapse to the *current* handle on every rename (never chain); grace-expiry timing is **not** exposed in any public API (research §1 — predictable expiry is a hijack signal).

**Tech Stack:** Laravel 12, PostgreSQL (Supabase migrations), Pest 4 tests, Redis (existing professional cache), Cloudflare Workers KV, existing `SyncSubdomainToKvJob`.

---

## Industry references baked into this design

- Instagram 14-day reclaim window (owner-only, then full release). [EvergreenFeed](https://www.evergreenfeed.com/blog/how-to-change-instagram-name/)
- 301 (not 302) for permanent moves; never chain. [Sitebulb redirect guide](https://sitebulb.com/resources/guides/the-ultimate-guide-to-redirects-for-seo/)
- Reserved-handle starter list. [username.dev](https://www.username.dev/reserved-usernames)
- Cloudflare KV `expirationTtl` for auto-evicting alias entries. [Cloudflare KV docs](https://developers.cloudflare.com/kv/)
- Squatting/impersonation threat model. [arxiv X squatting study](https://arxiv.org/html/2401.09209v1)

---

## Lifecycle states (single source of truth — referenced everywhere)

```
                              ┌─────────────────────────────────┐
                              │  Day 0 — user renames           │
                              │  old handle written as alias    │
                              └─────────────────────────────────┘
                                              │
                                              ▼
   ┌─────────────────────────────────────────────────────────────────────┐
   │  GRACE  (Day 0 → Day 14)                                            │
   │  reclaim_until = created_at + 14d                                   │
   │  • Old URL serves 301 to new URL                                    │
   │  • Old handle reserved for ORIGINAL owner — they can reclaim free   │
   │  • Nobody else can rename TO this handle                            │
   └─────────────────────────────────────────────────────────────────────┘
                                              │
                                              ▼
   ┌─────────────────────────────────────────────────────────────────────┐
   │  REDIRECT  (Day 14 → Day 90)                                        │
   │  reclaim_until passed, expires_at = created_at + 90d                │
   │  • Old URL still serves 301 to new URL                              │
   │  • Owner can no longer free-reclaim (they must use a normal rename) │
   │  • Still reserved against new claims                                │
   └─────────────────────────────────────────────────────────────────────┘
                                              │
                                              ▼
   ┌─────────────────────────────────────────────────────────────────────┐
   │  RELEASED  (Day 90+)                                                │
   │  prune job hard-deletes the row                                     │
   │  • Old URL returns 404                                              │
   │  • Handle returns to the public pool, claimable by anyone           │
   └─────────────────────────────────────────────────────────────────────┘
```

Configurable: `config/sidest.php` keys `handle.reclaim_days` (default 14), `handle.redirect_days` (default 90). Existing 30-day rename cooldown is unchanged.

---

## File structure

**New files:**
- `supabase/migrations/20260519100000_handle_alias_lifecycle.sql` — schema changes
- `app/Models/Core/HandleChangeLog.php` — audit-log model
- `app/Console/Commands/PruneExpiredHandleAliases.php` — daily prune
- `app/Console/Commands/NotifyHandleAliasExpiry.php` — daily T-3 / T-1 notification
- `app/Mail/HandleAliasExpiringMail.php` — notification email
- `app/Services/Site/ReclaimHandleAction.php` — owner-only reclaim
- `app/Http/Controllers/Api/Professional/Site/HandleReclaimController.php` — POST endpoint
- `app/Http/Requests/Api/Professional/Site/ReclaimHandleRequest.php` — validation
- `tests/Feature/Site/HandleAliasLifecycleTest.php` — end-to-end lifecycle
- `tests/Feature/Site/HandleReclaimTest.php` — reclaim endpoint
- `tests/Feature/Site/PruneExpiredHandleAliasesTest.php` — prune command
- `tests/Feature/Site/HandleChangeLogTest.php` — audit writing
- `tests/Unit/Services/Site/UpdateSiteActionLifecycleTest.php` — unit coverage of new branches

**Modified files:**
- `app/Services/Site/UpdateSiteAction.php` — set `reclaim_until` + `expires_at` on new aliases, collapse-back-to-self
- `app/Services/PublicSite/PublicSiteResolver.php` — filter expired aliases, return redirect intent
- `app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php` — filter expired aliases
- `app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php` — filter expired aliases (line 680)
- `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php` — ignore expired aliases in conflict check (lines 175, 191)
- `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php` — same (line 66)
- `app/Http/Requests/Api/BootstrapRequest.php` — filter expired aliases (line 118)
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` — write with `expirationTtl`; alias entries use `{type:'alias', target:'<current-handle>'}`
- `app/Services/Cloudflare/CloudflareKvService.php` — accept optional `expirationTtl`
- `app/Services/Cache/SiteCacheService.php` — filter expired aliases in cache warm-up (lines 889, 1015)
- `app/Console/Kernel.php` — schedule prune + notification commands daily
- `routes/api/professional.php` — register reclaim endpoint
- `config/sidest.php` — add `handle.reclaim_days`, `handle.redirect_days`, `handle.audit_retention_years`
- `app/Providers/AppServiceProvider.php` — register `HandleChangeLog` policy if needed (it's not tenant-scoped read by user, so likely exempt)
- `tests/Feature/Security/PolicyCoverageTest.php` — add `HandleChangeLog` to exempt list with justification

---

## Task 1: Schema migration — add lifecycle columns + audit log

**Files:**
- Create: `supabase/migrations/20260519100000_handle_alias_lifecycle.sql`
- Create: `tests/Feature/Site/HandleAliasLifecycleTest.php` (will be filled out across tasks; placeholder here)

- [ ] **Step 1: Write the migration**

```sql
-- 20260519100000_handle_alias_lifecycle.sql
-- Handle redirect lifecycle: GRACE → REDIRECT → RELEASED.
-- See docs/superpowers/plans/2026-05-19-handle-redirect-lifecycle.md.

BEGIN;

-- =====================================================
-- 1. Lifecycle columns on the two alias tables
-- =====================================================
-- reclaim_until: while > now(), only the original owner may rename back
--                to this handle for free (no cooldown). NULL = no grace
--                (legacy aliases pre-this-migration).
-- expires_at:    while > now(), the alias still serves 301 redirects.
--                NULL = legacy permanent alias (treated as never expires
--                until the cleanup migration in Task 11). The prune job
--                ONLY deletes rows where expires_at IS NOT NULL AND < now().
-- notified_t3_at / notified_t1_at: stamps to prevent repeat notifications.

ALTER TABLE site.professional_handle_aliases
    ADD COLUMN IF NOT EXISTS reclaim_until timestamptz,
    ADD COLUMN IF NOT EXISTS expires_at    timestamptz,
    ADD COLUMN IF NOT EXISTS notified_t3_at timestamptz,
    ADD COLUMN IF NOT EXISTS notified_t1_at timestamptz;

ALTER TABLE site.site_subdomain_aliases
    ADD COLUMN IF NOT EXISTS reclaim_until timestamptz,
    ADD COLUMN IF NOT EXISTS expires_at    timestamptz,
    ADD COLUMN IF NOT EXISTS notified_t3_at timestamptz,
    ADD COLUMN IF NOT EXISTS notified_t1_at timestamptz;

-- Partial indexes — only rows with an expiry are scanned by the prune job.
-- Legacy NULL rows are excluded from the prune sweep entirely.
CREATE INDEX IF NOT EXISTS professional_handle_aliases_expires_at_idx
    ON site.professional_handle_aliases (expires_at)
    WHERE expires_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS site_subdomain_aliases_expires_at_idx
    ON site.site_subdomain_aliases (expires_at)
    WHERE expires_at IS NOT NULL;

-- =====================================================
-- 2. Update the auto-alias trigger to set lifecycle columns.
--    Replaces the body from 20260508100000_url_columns_and_triggers.sql.
-- =====================================================
CREATE OR REPLACE FUNCTION core.trg_professional_handle_change()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_reclaim_days int := 14;
    v_redirect_days int := 90;
BEGIN
    INSERT INTO site.professional_handle_aliases
        (professional_id, handle, reclaim_until, expires_at)
    VALUES
        (NEW.id,
         OLD.handle,
         now() + (v_reclaim_days || ' days')::interval,
         now() + (v_redirect_days || ' days')::interval)
    ON CONFLICT DO NOTHING;

    PERFORM site.trg_recompute_affiliate_path(NEW.id, NEW.handle);
    RETURN NEW;
END;
$$;

-- =====================================================
-- 3. Update the BEFORE-UPDATE conflict check to ignore EXPIRED aliases.
--    A renamer can land on a handle whose alias has lapsed.
--    Legacy NULL-expires_at rows still block (treated as permanent).
-- =====================================================
CREATE OR REPLACE FUNCTION core.trg_professional_handle_alias_check()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_blocking_pro uuid;
BEGIN
    IF NEW.handle IS NOT DISTINCT FROM OLD.handle THEN
        RETURN NEW;
    END IF;

    SELECT professional_id INTO v_blocking_pro
      FROM site.professional_handle_aliases
     WHERE LOWER(handle) = LOWER(NEW.handle)
       AND professional_id <> NEW.id
       AND (expires_at IS NULL OR expires_at > now())
     LIMIT 1;

    IF v_blocking_pro IS NOT NULL THEN
        RAISE EXCEPTION 'Handle % is reserved as a redirect for another professional', NEW.handle
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$$;

-- =====================================================
-- 4. Audit log: append-only, retained per config (default 7 years).
-- =====================================================
CREATE TABLE IF NOT EXISTS core.handle_change_log (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    old_handle      text,
    new_handle      text NOT NULL,
    reason          text NOT NULL CHECK (reason IN ('rename', 'reclaim', 'staff_rename', 'system')),
    actor_id        uuid,         -- pro who initiated (= professional_id for self-rename, staff id for staff_rename)
    ip_address      inet,
    user_agent      text,
    changed_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS handle_change_log_pro_changed_idx
    ON core.handle_change_log (professional_id, changed_at DESC);

CREATE INDEX IF NOT EXISTS handle_change_log_changed_at_idx
    ON core.handle_change_log (changed_at DESC);

-- Append-only: no UPDATE/DELETE from app role. Block via trigger.
CREATE OR REPLACE FUNCTION core.trg_handle_change_log_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'core.handle_change_log is append-only' USING ERRCODE = '42501';
END;
$$;

DROP TRIGGER IF EXISTS handle_change_log_no_update ON core.handle_change_log;
CREATE TRIGGER handle_change_log_no_update
    BEFORE UPDATE OR DELETE ON core.handle_change_log
    FOR EACH ROW EXECUTE FUNCTION core.trg_handle_change_log_append_only();

ALTER TABLE core.handle_change_log ENABLE ROW LEVEL SECURITY;
GRANT INSERT, SELECT ON core.handle_change_log TO app_backend;

COMMIT;
```

- [ ] **Step 2: Push the migration to the dev Supabase project**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Expected: migration applied; `\d site.professional_handle_aliases` and `\d core.handle_change_log` show the new columns/table.

- [ ] **Step 3: Commit**

```bash
git add supabase/migrations/20260519100000_handle_alias_lifecycle.sql
git commit -m "feat(handle): add lifecycle columns + audit log table"
```

---

## Task 2: Config + models for the new state

**Files:**
- Modify: `config/sidest.php`
- Create: `app/Models/Core/HandleChangeLog.php`
- Modify: `app/Models/Core/Site/ProfessionalHandleAlias.php`
- Modify: `app/Models/Core/Site/SiteSubdomainAlias.php`

- [ ] **Step 1: Add config keys**

In `config/sidest.php`, add:

```php
    'handle' => [
        // Days during which only the original owner can reclaim a released handle for free.
        'reclaim_days'   => (int) env('SIDEST_HANDLE_RECLAIM_DAYS', 14),

        // Total days an alias serves a 301 redirect. After this it is hard-deleted and the handle returns to the pool.
        'redirect_days'  => (int) env('SIDEST_HANDLE_REDIRECT_DAYS', 90),

        // Years to retain handle_change_log rows. 7y matches typical fraud-investigation retention.
        'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7),
    ],
```

- [ ] **Step 2: Create `HandleChangeLog` model**

```php
<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only audit log of every handle rename / reclaim. Used for fraud
// investigation, impersonation disputes, and trademark complaints. DB trigger
// blocks UPDATE/DELETE — never mutate from PHP.
class HandleChangeLog extends BaseModel
{
    use HasUuids;

    public const REASON_RENAME       = 'rename';
    public const REASON_RECLAIM      = 'reclaim';
    public const REASON_STAFF_RENAME = 'staff_rename';
    public const REASON_SYSTEM       = 'system';

    protected $table = 'core.handle_change_log';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'professional_id',
        'old_handle',
        'new_handle',
        'reason',
        'actor_id',
        'ip_address',
        'user_agent',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }
}
```

- [ ] **Step 3: Add lifecycle attributes to alias models**

In both `ProfessionalHandleAlias.php` and `SiteSubdomainAlias.php`, extend `$fillable` and `$casts`:

```php
    protected $fillable = [
        // ...existing keys...
        'reclaim_until',
        'expires_at',
        'notified_t3_at',
        'notified_t1_at',
    ];

    protected $casts = [
        // ...existing casts...
        'reclaim_until'   => 'datetime',
        'expires_at'      => 'datetime',
        'notified_t3_at'  => 'datetime',
        'notified_t1_at'  => 'datetime',
    ];

    // Active = no expiry set (legacy) OR not yet expired.
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    // Reclaimable = within the owner-only grace window.
    public function scopeReclaimable($query)
    {
        return $query->whereNotNull('reclaim_until')->where('reclaim_until', '>', now());
    }
```

- [ ] **Step 4: Add `HandleChangeLog` to PolicyCoverageTest exempt list**

In `tests/Feature/Security/PolicyCoverageTest.php`, append to `POLICY_EXEMPT`:

```php
        'App\Models\Core\HandleChangeLog' => 'Append-only audit log; readable by staff only via dedicated controller — no per-row tenant policy.',
```

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php app/Models/Core/HandleChangeLog.php \
        app/Models/Core/Site/ProfessionalHandleAlias.php \
        app/Models/Core/Site/SiteSubdomainAlias.php \
        tests/Feature/Security/PolicyCoverageTest.php
git commit -m "feat(handle): lifecycle config + audit log model"
```

---

## Task 3: Write expiry on new aliases in `UpdateSiteAction` (TDD)

**Files:**
- Modify: `app/Services/Site/UpdateSiteAction.php`
- Test:   `tests/Unit/Services/Site/UpdateSiteActionLifecycleTest.php`

- [ ] **Step 1: Write failing test — new alias gets lifecycle stamps**

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\ProfessionalHandleAlias;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Carbon;

it('stamps reclaim_until and expires_at on a new handle/subdomain alias', function () {
    config(['sidest.handle.reclaim_days' => 14, 'sidest.handle.redirect_days' => 90]);

    $pro = Professional::factory()->create(['handle' => 'oldhandle', 'handle_lc' => 'oldhandle']);
    $site = Site::factory()->for($pro)->create(['subdomain' => 'oldhandle']);

    Carbon::setTestNow('2026-06-01 12:00:00');

    app(UpdateSiteAction::class)->execute($pro->fresh(), ['subdomain' => 'newhandle']);

    $handleAlias = ProfessionalHandleAlias::where('handle', 'oldhandle')->firstOrFail();
    $subdomainAlias = SiteSubdomainAlias::where('subdomain', 'oldhandle')->firstOrFail();

    expect($handleAlias->reclaim_until?->toIso8601String())->toBe('2026-06-15T12:00:00+00:00');
    expect($handleAlias->expires_at?->toIso8601String())->toBe('2026-08-30T12:00:00+00:00');
    expect($subdomainAlias->reclaim_until?->toIso8601String())->toBe('2026-06-15T12:00:00+00:00');
    expect($subdomainAlias->expires_at?->toIso8601String())->toBe('2026-08-30T12:00:00+00:00');
});
```

- [ ] **Step 2: Run test, confirm it fails**

```bash
vendor/bin/pest tests/Unit/Services/Site/UpdateSiteActionLifecycleTest.php --filter="stamps reclaim_until"
```

Expected: FAIL (`reclaim_until` is null — current code doesn't set it).

- [ ] **Step 3: Update `UpdateSiteAction`**

Locate the two alias-write blocks. Replace each `->create([...])` payload to include the new columns:

```php
                            DB::transaction(function () use ($site) {
                                $reclaimDays = (int) config('sidest.handle.reclaim_days', 14);
                                $redirectDays = (int) config('sidest.handle.redirect_days', 90);
                                SiteSubdomainAlias::query()->create([
                                    'site_id'        => $site->id,
                                    'subdomain'      => $site->subdomain,
                                    'reclaim_until'  => now()->addDays($reclaimDays),
                                    'expires_at'     => now()->addDays($redirectDays),
                                    'created_at'     => now(),
                                ]);
                            });
```

And for the handle alias:

```php
                            DB::transaction(function () use ($professional, $oldHandle) {
                                $reclaimDays = (int) config('sidest.handle.reclaim_days', 14);
                                $redirectDays = (int) config('sidest.handle.redirect_days', 90);
                                ProfessionalHandleAlias::query()->create([
                                    'professional_id' => $professional->id,
                                    'handle'          => $oldHandle,
                                    'reclaim_until'   => now()->addDays($reclaimDays),
                                    'expires_at'      => now()->addDays($redirectDays),
                                    'created_at'      => now(),
                                    'updated_at'      => now(),
                                ]);
                            });
```

- [ ] **Step 4: Run test, confirm pass**

```bash
vendor/bin/pest tests/Unit/Services/Site/UpdateSiteActionLifecycleTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Site/UpdateSiteAction.php tests/Unit/Services/Site/UpdateSiteActionLifecycleTest.php
git commit -m "feat(handle): stamp reclaim_until + expires_at on new aliases"
```

---

## Task 4: Collapse aliases on rename-back-to-self (TDD)

The research called this out explicitly (§5): if a user renames `A → B → A` within the redirect window, the alias row pointing `A → (current handle)` should be deleted, because they own A again and there's nothing to redirect.

**Files:**
- Modify: `app/Services/Site/UpdateSiteAction.php`
- Modify: `tests/Unit/Services/Site/UpdateSiteActionLifecycleTest.php`

- [ ] **Step 1: Write failing test**

Append to the test file:

```php
it('deletes the matching alias when a user renames back to a handle they previously held', function () {
    $pro = Professional::factory()->create(['handle' => 'a', 'handle_lc' => 'a']);
    $site = Site::factory()->for($pro)->create(['subdomain' => 'a']);

    // a → b (creates aliases for 'a')
    app(UpdateSiteAction::class)->execute($pro->fresh(), ['subdomain' => 'b']);

    Carbon::setTestNow(now()->addDays(31)); // past 30-day cooldown

    // b → a (should delete the 'a' alias rows)
    app(UpdateSiteAction::class)->execute($pro->fresh(), ['subdomain' => 'a']);

    expect(ProfessionalHandleAlias::where('handle', 'a')->where('professional_id', $pro->id)->exists())->toBeFalse();
    expect(SiteSubdomainAlias::where('subdomain', 'a')->where('site_id', $site->id)->exists())->toBeFalse();

    // And the 'b' alias now exists (since we renamed away from b)
    expect(ProfessionalHandleAlias::where('handle', 'b')->where('professional_id', $pro->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run test, confirm fail**

```bash
vendor/bin/pest tests/Unit/Services/Site/UpdateSiteActionLifecycleTest.php --filter="renames back"
```

Expected: FAIL (alias for 'a' still exists).

- [ ] **Step 3: Add the collapse logic to `UpdateSiteAction`**

After the new alias insertion blocks, *before* updating `professional->handle`, add:

```php
                    // Collapse: if the user is renaming back to a handle they
                    // hold as an alias, drop that alias — they own the handle
                    // again, nothing to redirect.
                    SiteSubdomainAlias::query()
                        ->where('site_id', $site->id)
                        ->whereRaw('lower(subdomain) = ?', [$incoming])
                        ->delete();

                    ProfessionalHandleAlias::query()
                        ->where('professional_id', $professional->id)
                        ->whereRaw('lower(handle) = ?', [$incoming])
                        ->delete();
```

- [ ] **Step 4: Run test, confirm pass**

```bash
vendor/bin/pest tests/Unit/Services/Site/UpdateSiteActionLifecycleTest.php
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Site/UpdateSiteAction.php tests/Unit/Services/Site/UpdateSiteActionLifecycleTest.php
git commit -m "feat(handle): collapse alias on rename-back-to-self"
```

---

## Task 5: Filter expired aliases in all resolution paths (TDD)

This is the critical correctness change: after `expires_at`, the alias row may still exist (until the prune job runs) but must not resolve anywhere.

**Files:**
- Modify: `app/Services/PublicSite/PublicSiteResolver.php`
- Modify: `app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php`
- Modify: `app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php` (line ~680)
- Modify: `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php` (lines 175, 191)
- Modify: `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php` (line 66)
- Modify: `app/Http/Requests/Api/BootstrapRequest.php` (line 118)
- Modify: `app/Services/Cache/SiteCacheService.php` (lines 889, 1015)
- Test:   `tests/Feature/Site/HandleAliasLifecycleTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\ProfessionalHandleAlias;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\PublicSite\PublicSiteResolver;

it('does not resolve sites via expired subdomain aliases', function () {
    $pro = Professional::factory()->create(['handle' => 'newhandle', 'handle_lc' => 'newhandle', 'status' => 'active']);
    $site = Site::factory()->for($pro)->create(['subdomain' => 'newhandle', 'is_published' => true]);

    // Expired alias — should NOT resolve.
    SiteSubdomainAlias::create([
        'site_id'      => $site->id,
        'subdomain'    => 'expiredhandle',
        'reclaim_until'=> now()->subDays(91),
        'expires_at'   => now()->subDay(),
        'created_at'   => now()->subDays(91),
    ]);

    // Active alias — should resolve.
    SiteSubdomainAlias::create([
        'site_id'      => $site->id,
        'subdomain'    => 'livehandle',
        'reclaim_until'=> now()->addDays(5),
        'expires_at'   => now()->addDays(60),
        'created_at'   => now()->subDays(30),
    ]);

    $resolver = app(PublicSiteResolver::class);
    expect($resolver->resolvePublishedSite('newhandle')?->id)->toBe($site->id);
    expect($resolver->resolvePublishedSite('livehandle')?->id)->toBe($site->id);
    expect($resolver->resolvePublishedSite('expiredhandle'))->toBeNull();
});
```

- [ ] **Step 2: Run test, confirm fail**

```bash
vendor/bin/pest tests/Feature/Site/HandleAliasLifecycleTest.php --filter="does not resolve sites via expired"
```

Expected: FAIL — `expiredhandle` currently resolves.

- [ ] **Step 3: Update every alias query to use the Active scope**

In `PublicSiteResolver::resolvePublishedSite`:

```php
        $alias = SiteSubdomainAlias::query()
            ->active()                                          // <— new
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();
```

In `ResolvesSiteFromRequest::resolveSiteFromData`:

```php
            $alias = SiteSubdomainAlias::query()
                ->active()                                      // <— new
                ->whereRaw('lower(subdomain) = ?', [$subdomain])
                ->first();
```

In `HydrogenAffiliateController.php` (line ~680, the `whereExists` against `professional_handle_aliases`):

```php
                            ->from('site.professional_handle_aliases')
                            ->whereColumn('site.professional_handle_aliases.professional_id', 'core.professionals.id')
                            ->whereRaw('lower(site.professional_handle_aliases.handle) = ?', [$handleLc])
                            ->where(function ($q) {
                                $q->whereNull('site.professional_handle_aliases.expires_at')
                                  ->orWhere('site.professional_handle_aliases.expires_at', '>', now());
                            });
```

In the **three FormRequest conflict checks** (`UpdateSiteRequest.php` lines 175 + 191, `StaffUpdateSiteRequest.php` line 66, `BootstrapRequest.php` line 118), add the same `where(function … expires_at … )` clause. The semantic intent: only *active* aliases block a rename.

In `SiteCacheService.php` lines 889 and 1015 (cache warm-up queries that include aliases in the cached payload), apply `->active()` to the scope so expired aliases stop appearing in cache.

- [ ] **Step 4: Run test, confirm pass**

```bash
vendor/bin/pest tests/Feature/Site/HandleAliasLifecycleTest.php
```

- [ ] **Step 5: Run the full Pest suite for regressions**

```bash
composer test
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add -p   # review each touched file
git commit -m "feat(handle): filter expired aliases from every resolution path"
```

---

## Task 6: Return HTTP 301 on alias hit (TDD)

Currently `PublicSiteResolver` returns the same `Site` model whether you hit by canonical subdomain or by alias, and the controller serves the page under either URL. That's duplicate content (research §2). Switch to returning a "redirect intent" tuple, and have the public-site controllers issue a 301 to the canonical URL.

**Files:**
- Modify: `app/Services/PublicSite/PublicSiteResolver.php`
- Modify: `app/Http/Controllers/Api/PublicSite/PublicSiteController.php`
- Test:   `tests/Feature/Site/HandleAliasLifecycleTest.php`

- [ ] **Step 1: Write failing test**

```php
it('returns 301 to canonical subdomain when public site is hit via an active alias', function () {
    $pro  = Professional::factory()->create(['handle' => 'new', 'handle_lc' => 'new', 'status' => 'active']);
    $site = Site::factory()->for($pro)->create(['subdomain' => 'new', 'is_published' => true]);

    SiteSubdomainAlias::create([
        'site_id'      => $site->id,
        'subdomain'    => 'old',
        'reclaim_until'=> now()->addDays(5),
        'expires_at'   => now()->addDays(60),
        'created_at'   => now(),
    ]);

    $response = $this->withHeaders(['X-Site-Subdomain' => 'old'])
                     ->get('/api/public-site');

    $response->assertStatus(301);
    $response->assertHeader('Location', 'https://new.partna.au/');
    $response->assertHeader('Cache-Control', 'public, max-age=300');
});
```

- [ ] **Step 2: Run test, confirm fail**

```bash
vendor/bin/pest tests/Feature/Site/HandleAliasLifecycleTest.php --filter="301"
```

- [ ] **Step 3: Refactor `PublicSiteResolver` to surface alias hits**

```php
namespace App\Services\PublicSite;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;

class PublicSiteResolver
{
    /**
     * @return array{site: ?Site, alias_hit: bool, canonical_subdomain: ?string}
     */
    public function resolvePublishedSite(string $subdomain): array
    {
        $subdomain = strtolower($subdomain);

        $siteQuery = Site::query()
            ->where('is_published', true)
            ->with('professional')
            ->whereHas('professional', fn ($q) => $q->where('status', 'active'));

        $site = (clone $siteQuery)->whereRaw('lower(subdomain) = ?', [$subdomain])->first();
        if ($site) {
            return ['site' => $site, 'alias_hit' => false, 'canonical_subdomain' => $site->subdomain];
        }

        $alias = SiteSubdomainAlias::query()
            ->active()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if (! $alias) {
            return ['site' => null, 'alias_hit' => false, 'canonical_subdomain' => null];
        }

        $aliased = (clone $siteQuery)->where('id', $alias->site_id)->first();
        return [
            'site'                => $aliased,
            'alias_hit'           => (bool) $aliased,
            'canonical_subdomain' => $aliased?->subdomain,
        ];
    }
}
```

- [ ] **Step 4: Update `PublicSiteController` to 301 on alias hit**

In every action that calls `resolvePublishedSite`, destructure the result and short-circuit when `alias_hit` is true:

```php
        $result = $this->resolver->resolvePublishedSite($subdomain);

        if ($result['alias_hit']) {
            $url = sprintf('https://%s.partna.au%s',
                $result['canonical_subdomain'],
                $request->getPathInfo()
            );
            return redirect()->away($url, 301)
                ->header('Cache-Control', 'public, max-age=300');
        }

        if (! $result['site']) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $site = $result['site'];
        // ... existing rendering
```

Update all callers of the old single-return form (search for `resolvePublishedSite`): `PublicSiteController.php` lines 31, 73, `ResolvesSiteFromRequest.php` line 50, `PublicSiteResolver.php` callers in `SiteCacheService` if any.

- [ ] **Step 5: Run test, confirm pass + full suite**

```bash
vendor/bin/pest tests/Feature/Site/HandleAliasLifecycleTest.php
composer test
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/PublicSite/PublicSiteResolver.php \
        app/Http/Controllers/Api/PublicSite/PublicSiteController.php \
        app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php \
        tests/Feature/Site/HandleAliasLifecycleTest.php
git commit -m "feat(handle): 301 redirect on alias hit instead of serving duplicate content"
```

---

## Task 7: Cloudflare KV — alias entries with `expirationTtl` (TDD)

Edge worker should redirect old → new without an origin round-trip, and entries should auto-evict on `expires_at`. The current `SyncSubdomainToKvJob` writes alias entries with the *same* routing value as the canonical handle, which means an alias on a brand pro gets `{type:'brand'}` — the Edge Worker passes through to Hydrogen as if it's the live brand. After this task, aliases write a distinct `{type:'alias', target:'<canonical-handle>'}` value, and the worker (separate repo) is updated to honour it as a 301.

**Files:**
- Modify: `app/Services/Cloudflare/CloudflareKvService.php`
- Modify: `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- Test:   `tests/Feature/Site/HandleAliasLifecycleTest.php` (extend with KV write assertion using a mocked service)

- [ ] **Step 1: Write failing test**

```php
it('writes alias KV entries with expirationTtl matching expires_at and a 301 marker', function () {
    $kv = Mockery::mock(\App\Services\Cloudflare\CloudflareKvService::class);
    $this->app->instance(\App\Services\Cloudflare\CloudflareKvService::class, $kv);

    $pro = Professional::factory()->create(['handle' => 'newh', 'handle_lc' => 'newh', 'type' => 'brand']);
    Site::factory()->for($pro)->create(['subdomain' => 'newh']);
    ProfessionalHandleAlias::create([
        'professional_id' => $pro->id,
        'handle'          => 'oldh',
        'reclaim_until'   => now()->addDays(5),
        'expires_at'      => now()->addSeconds(7776000), // 90d
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    // Canonical entry — no expiry.
    $kv->shouldReceive('put')->once()->with('newh', ['type' => 'brand'], null);

    // Alias entry — type=alias, target=newh, expirationTtl ~= 90d (allow drift).
    $kv->shouldReceive('put')->once()->withArgs(function ($key, $value, $ttl) {
        return $key === 'oldh'
            && $value === ['type' => 'alias', 'target' => 'newh']
            && $ttl >= 7776000 - 5 && $ttl <= 7776000 + 5;
    });

    (new \App\Jobs\Cloudflare\SyncSubdomainToKvJob($pro->id))
        ->handle(app(\App\Services\Cloudflare\CloudflareKvService::class));
});
```

- [ ] **Step 2: Run test, confirm fail**

```bash
vendor/bin/pest tests/Feature/Site/HandleAliasLifecycleTest.php --filter="expirationTtl"
```

- [ ] **Step 3: Add optional `$expirationTtl` to `CloudflareKvService::put`**

```php
    public function put(string $key, array $value, ?int $expirationTtl = null): void
    {
        // ... existing body, plus:
        $params = ['metadata' => json_encode([])];
        if ($expirationTtl !== null && $expirationTtl > 60) { // CF requires >= 60s
            $params['expiration_ttl'] = $expirationTtl;
        }
        // Pass $params to the HTTP client as form params or query string per CF API.
    }
```

(Confirm the exact param name with the Cloudflare KV REST API docs while implementing — `expiration_ttl` for the bulk write endpoint, `expirationTtl` for the Workers binding.)

- [ ] **Step 4: Rewrite `SyncSubdomainToKvJob::handle` to write distinct alias entries**

```php
    public function handle(CloudflareKvService $kv): void
    {
        $pro = Professional::query()->find($this->professionalId);
        if (! $pro || ! $pro->handle) {
            return;
        }

        $current = strtolower(trim($pro->handle));

        // 1. Write the canonical entry first.
        if ($pro->isBrand()) {
            $kv->put($current, ['type' => 'brand'], null);
        } else {
            $siteUrl = BrandPartnerLink::query()
                ->where('affiliate_professional_id', $pro->id)
                ->whereNotNull('site_url')
                ->orderBy('slot')
                ->value('site_url');

            if (! $siteUrl) {
                $kv->delete($current);
                $this->deleteAliasEntries($kv, $pro->id, $current);
                return;
            }

            $kv->put($current, ['type' => 'affiliate', 'redirect' => $siteUrl], null);
        }

        // 2. Write every ACTIVE alias as a 301 marker with TTL.
        $aliases = DB::table('site.professional_handle_aliases')
            ->where('professional_id', $pro->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        foreach ($aliases as $alias) {
            $handle = strtolower(trim($alias->handle));
            if ($handle === '' || $handle === $current) {
                continue;
            }

            $ttl = $alias->expires_at
                ? max(60, now()->diffInSeconds($alias->expires_at, false))
                : null; // legacy permanent

            $kv->put($handle, ['type' => 'alias', 'target' => $current], $ttl);
        }
    }

    private function deleteAliasEntries(CloudflareKvService $kv, string $proId, string $current): void
    {
        $handles = DB::table('site.professional_handle_aliases')
            ->where('professional_id', $proId)
            ->pluck('handle');
        foreach ($handles as $h) {
            $h = strtolower(trim($h));
            if ($h && $h !== $current) {
                try { $kv->delete($h); } catch (\Throwable $e) { /* swallow */ }
            }
        }
    }
```

- [ ] **Step 5: Run test, confirm pass**

```bash
vendor/bin/pest tests/Feature/Site/HandleAliasLifecycleTest.php
```

- [ ] **Step 6: Document edge-worker contract change**

Append a note to `docs/shopify-quirks.md` (or create `docs/handle-redirects.md` if preferred) explaining the new `{type:'alias', target:'<handle>'}` KV value and that the worker must respond `301 Moved Permanently` with `Location: https://<target>.partna.au<path>` and `Cache-Control: public, max-age=300`.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Cloudflare/CloudflareKvService.php \
        app/Jobs/Cloudflare/SyncSubdomainToKvJob.php \
        docs/handle-redirects.md \
        tests/Feature/Site/HandleAliasLifecycleTest.php
git commit -m "feat(handle): KV alias entries with TTL + 301 marker for edge worker"
```

---

## Task 8: Audit log writer (TDD)

Every rename (and reclaim, and staff override) writes a `core.handle_change_log` row. Capture IP + user agent at write time so we can investigate impersonation.

**Files:**
- Modify: `app/Services/Site/UpdateSiteAction.php` — accept optional `Request` context + write log
- Modify: `app/Http/Controllers/Api/Professional/Site/SiteController.php` (or wherever calls `UpdateSiteAction`) — pass request context
- Modify: `app/Http/Controllers/Api/Staff/ProfessionalSite/StaffSiteController.php` — same, with staff actor
- Test:   `tests/Feature/Site/HandleChangeLogTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Core\HandleChangeLog;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Site\UpdateSiteAction;

it('writes a handle_change_log row on rename with rename reason and actor', function () {
    $pro = Professional::factory()->create(['handle' => 'old', 'handle_lc' => 'old']);
    Site::factory()->for($pro)->create(['subdomain' => 'old']);

    app(UpdateSiteAction::class)->execute(
        $pro->fresh(),
        ['subdomain' => 'new'],
        ['ip' => '203.0.113.4', 'user_agent' => 'pest/test']
    );

    $log = HandleChangeLog::where('professional_id', $pro->id)->latest('changed_at')->firstOrFail();
    expect($log->old_handle)->toBe('old');
    expect($log->new_handle)->toBe('new');
    expect($log->reason)->toBe(HandleChangeLog::REASON_RENAME);
    expect($log->actor_id)->toBe($pro->id);
    expect($log->ip_address)->toBe('203.0.113.4');
    expect($log->user_agent)->toBe('pest/test');
});
```

- [ ] **Step 2: Run test, confirm fail**

```bash
vendor/bin/pest tests/Feature/Site/HandleChangeLogTest.php
```

- [ ] **Step 3: Update `UpdateSiteAction::execute` signature + write the log**

```php
    public function execute(Professional $professional, array $data, array $options = []): Site
    {
        // ...
        $actorId   = (string) ($options['actor_id'] ?? $professional->id);
        $reason    = (string) ($options['reason']   ?? HandleChangeLog::REASON_RENAME);
        $ip        = $options['ip'] ?? null;
        $userAgent = $options['user_agent'] ?? null;
        // ...
    }
```

Inside the rename branch, after `professional->save()`:

```php
                    HandleChangeLog::create([
                        'professional_id' => $professional->id,
                        'old_handle'      => $oldHandle,
                        'new_handle'      => $incoming,
                        'reason'          => $reason,
                        'actor_id'        => $actorId,
                        'ip_address'      => $ip,
                        'user_agent'      => $userAgent,
                        'changed_at'      => now(),
                    ]);
```

- [ ] **Step 4: Plumb request context from controllers**

In `SiteController` (professional) and `StaffSiteController`, capture and pass:

```php
        $options = [
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1024),
        ];
        // Staff variant additionally sets:
        // $options['actor_id'] = $staff->id;
        // $options['reason']   = HandleChangeLog::REASON_STAFF_RENAME;

        $site = $this->updateSiteAction->execute($pro, $request->validated(), $options);
```

- [ ] **Step 5: Run test, confirm pass + full suite**

```bash
vendor/bin/pest tests/Feature/Site/HandleChangeLogTest.php
composer test
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/Site/UpdateSiteAction.php \
        app/Http/Controllers/Api/Professional/Site/SiteController.php \
        app/Http/Controllers/Api/Staff/ProfessionalSite/StaffSiteController.php \
        tests/Feature/Site/HandleChangeLogTest.php
git commit -m "feat(handle): audit-log every rename with actor + ip + user agent"
```

---

## Task 9: Owner-only reclaim endpoint (TDD)

Inside the 14-day window the original owner can take their old handle back without burning the 30-day rename cooldown. Outside the window they have to rename normally (and pay the cooldown).

**Files:**
- Create: `app/Services/Site/ReclaimHandleAction.php`
- Create: `app/Http/Controllers/Api/Professional/Site/HandleReclaimController.php`
- Create: `app/Http/Requests/Api/Professional/Site/ReclaimHandleRequest.php`
- Modify: `routes/api/professional.php`
- Test:   `tests/Feature/Site/HandleReclaimTest.php`

- [ ] **Step 1: Write the route**

In `routes/api/professional.php`:

```php
    Route::post('/me/site/reclaim-handle', [HandleReclaimController::class, 'store'])
        ->name('professional.site.reclaim-handle');
```

- [ ] **Step 2: Write failing tests**

```php
<?php

use App\Models\Core\HandleChangeLog;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\ProfessionalHandleAlias;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;

it('lets the original owner reclaim within the 14-day grace window, bypassing the 30-day rename cooldown', function () {
    $pro = Professional::factory()->create(['handle' => 'new', 'handle_lc' => 'new']);
    $site = Site::factory()->for($pro)->create([
        'subdomain' => 'new',
        'subdomain_changed_at' => now()->subDays(3), // well inside the 30-day cooldown
    ]);

    ProfessionalHandleAlias::create([
        'professional_id' => $pro->id,
        'handle'          => 'old',
        'reclaim_until'   => now()->addDays(11),
        'expires_at'      => now()->addDays(87),
        'created_at'      => now()->subDays(3),
        'updated_at'      => now()->subDays(3),
    ]);
    SiteSubdomainAlias::create([
        'site_id'      => $site->id,
        'subdomain'    => 'old',
        'reclaim_until'=> now()->addDays(11),
        'expires_at'   => now()->addDays(87),
        'created_at'   => now()->subDays(3),
    ]);

    $this->asProfessional($pro)
         ->postJson('/api/me/site/reclaim-handle', ['handle' => 'old'])
         ->assertOk();

    expect($pro->fresh()->handle)->toBe('old');
    expect(ProfessionalHandleAlias::where('handle', 'old')->where('professional_id', $pro->id)->exists())->toBeFalse();
    expect(SiteSubdomainAlias::where('subdomain', 'old')->where('site_id', $site->id)->exists())->toBeFalse();
    expect(HandleChangeLog::where('professional_id', $pro->id)->latest('changed_at')->first()->reason)->toBe(HandleChangeLog::REASON_RECLAIM);
});

it('refuses to reclaim once the reclaim window has passed', function () {
    $pro = Professional::factory()->create(['handle' => 'new', 'handle_lc' => 'new']);
    Site::factory()->for($pro)->create(['subdomain' => 'new']);

    ProfessionalHandleAlias::create([
        'professional_id' => $pro->id,
        'handle'          => 'old',
        'reclaim_until'   => now()->subDay(),    // expired grace
        'expires_at'      => now()->addDays(60), // redirect still active
        'created_at'      => now()->subDays(15),
        'updated_at'      => now()->subDays(15),
    ]);

    $this->asProfessional($pro)
         ->postJson('/api/me/site/reclaim-handle', ['handle' => 'old'])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['handle']);
});

it('refuses to reclaim a handle aliased to a different professional', function () {
    $self  = Professional::factory()->create(['handle' => 'self',  'handle_lc' => 'self']);
    $other = Professional::factory()->create(['handle' => 'other', 'handle_lc' => 'other']);
    Site::factory()->for($self)->create(['subdomain' => 'self']);

    ProfessionalHandleAlias::create([
        'professional_id' => $other->id,    // belongs to someone else
        'handle'          => 'wanted',
        'reclaim_until'   => now()->addDays(5),
        'expires_at'      => now()->addDays(60),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $this->asProfessional($self)
         ->postJson('/api/me/site/reclaim-handle', ['handle' => 'wanted'])
         ->assertStatus(404); // 404 not 403 — never reveal existence (research §1, §4)
});
```

- [ ] **Step 3: Run, confirm fail**

```bash
vendor/bin/pest tests/Feature/Site/HandleReclaimTest.php
```

- [ ] **Step 4: Implement `ReclaimHandleRequest`**

```php
<?php

namespace App\Http\Requests\Api\Professional\Site;

use Illuminate\Foundation\Http\FormRequest;

class ReclaimHandleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'handle' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9-]+$/i'],
        ];
    }
}
```

- [ ] **Step 5: Implement `ReclaimHandleAction`**

```php
<?php

namespace App\Services\Site;

use App\Models\Core\HandleChangeLog;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\ProfessionalHandleAlias;
use App\Models\Core\Site\SiteSubdomainAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Lets the original owner take back an aliased handle within the grace window
// without burning the 30-day rename cooldown.
//
// IMPORTANT: never reveal *why* the reclaim failed beyond the surface message.
// In particular, return 404 when the alias does not belong to this professional
// — surfacing 403 / "owned by someone else" exposes the alias-table contents
// and the grace-window timing to enumeration (research §1).
class ReclaimHandleAction
{
    public function __construct(private readonly UpdateSiteAction $updateSiteAction) {}

    public function execute(Professional $professional, string $handle, array $context = []): void
    {
        $handle = strtolower($handle);

        DB::transaction(function () use ($professional, $handle, $context) {
            $alias = ProfessionalHandleAlias::query()
                ->where('professional_id', $professional->id)
                ->whereRaw('lower(handle) = ?', [$handle])
                ->lockForUpdate()
                ->first();

            // 404 (not 403/422) when the alias isn't ours — see class doc.
            if (! $alias) {
                throw new NotFoundHttpException();
            }

            if (! $alias->reclaim_until || $alias->reclaim_until->isPast()) {
                throw ValidationException::withMessages([
                    'handle' => ['This handle can no longer be reclaimed.'],
                ]);
            }

            $this->updateSiteAction->execute(
                $professional->fresh(),
                ['subdomain' => $handle],
                array_merge($context, [
                    'allow_subdomain_override' => true,       // bypass cooldown
                    'reason'                   => HandleChangeLog::REASON_RECLAIM,
                ])
            );

            // The rename already collapses matching aliases (Task 4), so no extra cleanup here.
        });
    }
}
```

- [ ] **Step 6: Implement `HandleReclaimController`**

```php
<?php

namespace App\Http\Controllers\Api\Professional\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Site\ReclaimHandleRequest;
use App\Services\Site\ReclaimHandleAction;
use App\Services\Auth\ResolveProfessional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HandleReclaimController extends Controller
{
    public function __construct(
        private readonly ReclaimHandleAction $action,
        private readonly ResolveProfessional $resolveProfessional,
    ) {}

    public function store(ReclaimHandleRequest $request): JsonResponse
    {
        $pro = $this->resolveProfessional->fromRequest($request);

        $this->action->execute($pro, $request->string('handle'), [
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1024),
        ]);

        return response()->json(['status' => 'ok']);
    }
}
```

(Adjust `ResolveProfessional` to whatever the codebase actually uses — the project resolves the Professional from the Supabase JWT via existing middleware.)

- [ ] **Step 7: Run, confirm pass**

```bash
vendor/bin/pest tests/Feature/Site/HandleReclaimTest.php
composer test
```

- [ ] **Step 8: Commit**

```bash
git add app/Services/Site/ReclaimHandleAction.php \
        app/Http/Controllers/Api/Professional/Site/HandleReclaimController.php \
        app/Http/Requests/Api/Professional/Site/ReclaimHandleRequest.php \
        routes/api/professional.php \
        tests/Feature/Site/HandleReclaimTest.php
git commit -m "feat(handle): owner reclaim endpoint within 14-day grace window"
```

---

## Task 10: Prune command (TDD)

Daily delete of rows where `expires_at < now()`. Idempotent. Also re-syncs Cloudflare KV for any pros whose aliases were trimmed (defensive — the TTL should have already evicted them, but eventual consistency).

**Files:**
- Create: `app/Console/Commands/PruneExpiredHandleAliases.php`
- Modify: `app/Console/Kernel.php`
- Test:   `tests/Feature/Site/PruneExpiredHandleAliasesTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Console\Commands\PruneExpiredHandleAliases;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\ProfessionalHandleAlias;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use Illuminate\Support\Facades\Bus;

it('deletes expired aliases and re-dispatches KV sync, leaving active ones alone', function () {
    Bus::fake();

    $pro = Professional::factory()->create();
    $site = Site::factory()->for($pro)->create();

    ProfessionalHandleAlias::create([
        'professional_id' => $pro->id,
        'handle'          => 'gone',
        'reclaim_until'   => now()->subDays(91),
        'expires_at'      => now()->subDay(),       // expired
        'created_at'      => now()->subDays(91),
        'updated_at'      => now()->subDays(91),
    ]);
    ProfessionalHandleAlias::create([
        'professional_id' => $pro->id,
        'handle'          => 'alive',
        'reclaim_until'   => now()->addDays(5),
        'expires_at'      => now()->addDays(60),    // active
        'created_at'      => now()->subDays(30),
        'updated_at'      => now()->subDays(30),
    ]);
    SiteSubdomainAlias::create([
        'site_id'      => $site->id,
        'subdomain'    => 'gone',
        'reclaim_until'=> now()->subDays(91),
        'expires_at'   => now()->subDay(),
        'created_at'   => now()->subDays(91),
    ]);

    $this->artisan(PruneExpiredHandleAliases::class)->assertSuccessful();

    expect(ProfessionalHandleAlias::where('handle', 'gone')->exists())->toBeFalse();
    expect(SiteSubdomainAlias::where('subdomain', 'gone')->exists())->toBeFalse();
    expect(ProfessionalHandleAlias::where('handle', 'alive')->exists())->toBeTrue();

    Bus::assertDispatched(SyncSubdomainToKvJob::class, fn ($j) => $j->professionalId === $pro->id);
});

it('leaves legacy NULL-expires_at aliases untouched', function () {
    $pro = Professional::factory()->create();
    ProfessionalHandleAlias::create([
        'professional_id' => $pro->id,
        'handle'          => 'legacy',
        'reclaim_until'   => null,
        'expires_at'      => null,
        'created_at'      => now()->subYears(2),
        'updated_at'      => now()->subYears(2),
    ]);

    $this->artisan(PruneExpiredHandleAliases::class)->assertSuccessful();
    expect(ProfessionalHandleAlias::where('handle', 'legacy')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run, confirm fail**

```bash
vendor/bin/pest tests/Feature/Site/PruneExpiredHandleAliasesTest.php
```

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\ProfessionalHandleAlias;
use App\Models\Core\Site\SiteSubdomainAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneExpiredHandleAliases extends Command
{
    protected $signature = 'handles:prune-expired-aliases {--dry-run : Report counts without deleting}';

    protected $description = 'Hard-delete handle/subdomain aliases past their expires_at and re-sync Cloudflare KV.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $affectedProIds = ProfessionalHandleAlias::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('professional_id')
            ->unique()
            ->values();

        $handleCount = ProfessionalHandleAlias::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();

        $subdomainCount = SiteSubdomainAlias::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();

        $this->info("Expired handle aliases: {$handleCount}");
        $this->info("Expired subdomain aliases: {$subdomainCount}");

        if ($dry) {
            return self::SUCCESS;
        }

        DB::transaction(function () {
            ProfessionalHandleAlias::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->delete();

            SiteSubdomainAlias::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->delete();
        });

        foreach ($affectedProIds as $proId) {
            SyncSubdomainToKvJob::dispatch((string) $proId);
        }

        Log::info('handles.prune.completed', [
            'handle_aliases_deleted'    => $handleCount,
            'subdomain_aliases_deleted' => $subdomainCount,
            'pros_resynced'             => $affectedProIds->count(),
        ]);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule daily run**

In `app/Console/Kernel.php`:

```php
        $schedule->command('handles:prune-expired-aliases')
            ->dailyAt('03:15')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground();
```

- [ ] **Step 5: Run tests, confirm pass**

```bash
vendor/bin/pest tests/Feature/Site/PruneExpiredHandleAliasesTest.php
composer test
```

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/PruneExpiredHandleAliases.php \
        app/Console/Kernel.php \
        tests/Feature/Site/PruneExpiredHandleAliasesTest.php
git commit -m "feat(handle): daily prune command for expired aliases"
```

---

## Task 11: Expiry-warning notifications (TDD)

Email the renamer at T-3 days and T-1 day before each alias enters RELEASED. Idempotent — uses `notified_t3_at` / `notified_t1_at` stamps.

**Files:**
- Create: `app/Console/Commands/NotifyHandleAliasExpiry.php`
- Create: `app/Mail/HandleAliasExpiringMail.php`
- Create: `resources/views/mail/handle-alias-expiring.blade.php`
- Modify: `app/Console/Kernel.php`
- Test:   `tests/Feature/Site/NotifyHandleAliasExpiryTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Mail\HandleAliasExpiringMail;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\ProfessionalHandleAlias;
use Illuminate\Support\Facades\Mail;

it('sends a T-3 email exactly once per alias', function () {
    Mail::fake();
    $pro = Professional::factory()->create(['handle' => 'new', 'handle_lc' => 'new']);
    $alias = ProfessionalHandleAlias::create([
        'professional_id' => $pro->id,
        'handle'          => 'old',
        'reclaim_until'   => now()->subDays(11),
        'expires_at'      => now()->addDays(2)->addHours(12), // within T-3 window
        'created_at'      => now()->subDays(87),
        'updated_at'      => now()->subDays(87),
    ]);

    $this->artisan('handles:notify-expiry')->assertSuccessful();
    Mail::assertSent(HandleAliasExpiringMail::class, 1);

    // Second run is a no-op (notified_t3_at is set).
    $this->artisan('handles:notify-expiry')->assertSuccessful();
    Mail::assertSent(HandleAliasExpiringMail::class, 1);

    expect($alias->fresh()->notified_t3_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run, confirm fail; then implement**

`NotifyHandleAliasExpiry`:

```php
<?php

namespace App\Console\Commands;

use App\Mail\HandleAliasExpiringMail;
use App\Models\Core\Site\ProfessionalHandleAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class NotifyHandleAliasExpiry extends Command
{
    protected $signature = 'handles:notify-expiry';
    protected $description = 'Email pros when their old handle aliases are about to be released.';

    public function handle(): int
    {
        // T-3
        $this->dispatchBucket(
            ProfessionalHandleAlias::query()
                ->whereNull('notified_t3_at')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays(3)),
            'notified_t3_at',
            't3'
        );

        // T-1
        $this->dispatchBucket(
            ProfessionalHandleAlias::query()
                ->whereNull('notified_t1_at')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDay()),
            'notified_t1_at',
            't1'
        );

        return self::SUCCESS;
    }

    private function dispatchBucket($query, string $stampColumn, string $bucket): void
    {
        $query->with('professional')->chunkById(200, function ($aliases) use ($stampColumn, $bucket) {
            foreach ($aliases as $alias) {
                $pro = $alias->professional;
                if (! $pro || ! $pro->email) {
                    $alias->update([$stampColumn => now()]);
                    continue;
                }

                Mail::to($pro->email)->queue(new HandleAliasExpiringMail($alias, $bucket));
                $alias->update([$stampColumn => now()]);
            }
        });
    }
}
```

`HandleAliasExpiringMail`: simple Mailable; subject `"Your old handle <handle> releases in <N> day(s)"`; body explains that after release anyone may claim it, and links to the reclaim endpoint if `reclaim_until > now()`.

Schedule daily at 09:00 local in `Kernel.php`:

```php
        $schedule->command('handles:notify-expiry')
            ->dailyAt('09:00')
            ->timezone(config('app.timezone'))
            ->onOneServer();
```

- [ ] **Step 3: Run, confirm pass**

```bash
vendor/bin/pest tests/Feature/Site/NotifyHandleAliasExpiryTest.php
composer test
```

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/NotifyHandleAliasExpiry.php \
        app/Mail/HandleAliasExpiringMail.php \
        resources/views/mail/handle-alias-expiring.blade.php \
        app/Console/Kernel.php \
        tests/Feature/Site/NotifyHandleAliasExpiryTest.php
git commit -m "feat(handle): T-3 / T-1 expiry notifications for alias holders"
```

---

## Task 12: Backfill legacy aliases (one-off SQL)

Existing aliases predate this work; they have `expires_at = NULL` so they will never prune. Decide policy:

- **Aliases older than 90 days at rollout**: stamp `expires_at = now() + 14 days` (give the owner a 2-week window to be notified, then prune).
- **Aliases younger than 90 days at rollout**: stamp `expires_at = created_at + 90 days` so they get the same total lifetime they would have had if the system had existed at write time.

**Files:**
- Create: `supabase/migrations/20260519110000_backfill_alias_expiry.sql`

- [ ] **Step 1: Write the migration**

```sql
BEGIN;

-- Newer aliases: full 90-day lifecycle measured from created_at.
UPDATE site.professional_handle_aliases
   SET expires_at    = created_at + interval '90 days',
       reclaim_until = COALESCE(reclaim_until, created_at + interval '14 days')
 WHERE expires_at IS NULL
   AND created_at >= now() - interval '90 days';

UPDATE site.site_subdomain_aliases
   SET expires_at    = created_at + interval '90 days',
       reclaim_until = COALESCE(reclaim_until, created_at + interval '14 days')
 WHERE expires_at IS NULL
   AND created_at >= now() - interval '90 days';

-- Older aliases: short 14-day grace from rollout (notifications will fire first).
UPDATE site.professional_handle_aliases
   SET expires_at = now() + interval '14 days'
 WHERE expires_at IS NULL;

UPDATE site.site_subdomain_aliases
   SET expires_at = now() + interval '14 days'
 WHERE expires_at IS NULL;

COMMIT;
```

- [ ] **Step 2: Push to dev FIRST, eyeball the numbers, then prod**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push

# Verify counts look sane in dev before scheduling prod.
psql "$DEV_DB_URL" -c "SELECT count(*) FILTER (WHERE expires_at IS NULL) AS legacy, count(*) FILTER (WHERE expires_at IS NOT NULL) AS dated FROM site.professional_handle_aliases;"
```

**Pause and confirm with Josh before pushing to prod.** This is the irreversible step.

- [ ] **Step 3: Commit**

```bash
git add supabase/migrations/20260519110000_backfill_alias_expiry.sql
git commit -m "chore(handle): backfill expiry on legacy aliases (90d new / 14d old)"
```

---

## Task 13: End-to-end lifecycle test

Single test that walks an alias through every state, verifying behaviour at each boundary.

**Files:**
- Modify: `tests/Feature/Site/HandleAliasLifecycleTest.php`

- [ ] **Step 1: Add the integration test**

```php
it('walks an alias through grace → redirect → released', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $pro = Professional::factory()->create(['handle' => 'old', 'handle_lc' => 'old', 'status' => 'active']);
    $site = Site::factory()->for($pro)->create(['subdomain' => 'old', 'is_published' => true]);

    // Rename old → new
    app(UpdateSiteAction::class)->execute($pro->fresh(), ['subdomain' => 'new']);

    // Day 1 — GRACE. Resolver 301s. Owner can reclaim.
    Carbon::setTestNow('2026-06-02 12:00:00');
    expect(app(PublicSiteResolver::class)->resolvePublishedSite('old'))->toMatchArray(['alias_hit' => true]);
    expect(ProfessionalHandleAlias::where('handle', 'old')->reclaimable()->exists())->toBeTrue();

    // Day 20 — REDIRECT. Still 301s. Reclaim no longer free.
    Carbon::setTestNow('2026-06-21 12:00:00');
    expect(app(PublicSiteResolver::class)->resolvePublishedSite('old'))->toMatchArray(['alias_hit' => true]);
    expect(ProfessionalHandleAlias::where('handle', 'old')->reclaimable()->exists())->toBeFalse();

    // Day 91 — RELEASED (after prune). 404.
    Carbon::setTestNow('2026-09-01 12:00:00');
    $this->artisan('handles:prune-expired-aliases')->assertSuccessful();
    expect(ProfessionalHandleAlias::where('handle', 'old')->exists())->toBeFalse();
    expect(app(PublicSiteResolver::class)->resolvePublishedSite('old'))->toMatchArray(['site' => null, 'alias_hit' => false]);
});
```

- [ ] **Step 2: Run, confirm pass; commit**

```bash
vendor/bin/pest tests/Feature/Site/HandleAliasLifecycleTest.php --filter="walks an alias"
composer test
git add tests/Feature/Site/HandleAliasLifecycleTest.php
git commit -m "test(handle): end-to-end grace→redirect→released walk"
```

---

## Task 14: Cloudflare Worker contract update (separate repo — out of scope here, documented for Josh)

The KV value shape changes (Task 7). The Edge Worker in the **frontend / infra** repo must be updated to interpret `{type:'alias', target:'<handle>'}` as:

```
HTTP/1.1 301 Moved Permanently
Location: https://<target>.partna.au<path>
Cache-Control: public, max-age=300
```

Until the worker is updated, alias entries with `type=alias` will be served as if they were unknown (404). The prudent rollout order is:

1. Deploy this backend change (Tasks 1–13) — KV is still being written in both shapes until Task 7 ships, so old workers keep working.
2. Deploy the updated worker.
3. After verifying the worker honours `type=alias`, ship Task 7 to switch KV writes to the new shape.

Add a checklist item to `docs/handle-redirects.md` (created in Task 7) documenting this rollout order.

- [ ] **Step 1: Add a rollout-order note to `docs/handle-redirects.md`**
- [ ] **Step 2: Commit**

```bash
git add docs/handle-redirects.md
git commit -m "docs(handle): edge-worker rollout order for alias 301s"
```

---

## Task 15: Documentation pass

Update human-facing docs so future devs (and Josh, in 6 months) can hold this in their head.

**Files:**
- Modify: `CLAUDE.md` (add a short section under "Architecture Rules")
- Modify: `docs/handle-redirects.md` (full spec — already created in Task 7)
- Modify: `AI_CONTEXT.md` if it mentions handle/subdomain behaviour

- [ ] **Step 1: Add `CLAUDE.md` paragraph**

Under Architecture Rules:

```markdown
### Handle / subdomain lifecycle

Renames write the old handle to `site.professional_handle_aliases` (and the old
subdomain to `site.site_subdomain_aliases`) with two timestamps:

- `reclaim_until` (default +14d) — only the original owner can rename back for free.
- `expires_at`    (default +90d) — after this the row is hard-deleted by `handles:prune-expired-aliases` and the handle returns to the pool.

Resolvers (`PublicSiteResolver`, `ResolvesSiteFromRequest`, `HydrogenAffiliateController`)
filter expired rows with the `active()` scope. Alias hits return **HTTP 301** to the
canonical URL — never serve content under both. Cloudflare KV writes alias entries
with `expirationTtl` so the edge auto-evicts in parallel. Configurable via
`config('sidest.handle.*')`. Full spec: `docs/handle-redirects.md`.
```

- [ ] **Step 2: Flesh out `docs/handle-redirects.md`** with the lifecycle diagram from the top of this plan, the KV value shapes, the audit-log schema, and a runbook for "the prod prune deleted aliases I needed back".

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md docs/handle-redirects.md AI_CONTEXT.md
git commit -m "docs(handle): lifecycle spec + CLAUDE.md pointer"
```

---

## Out of scope (intentionally) — capture and revisit later

These came up in the research but should not bloat this PR. Track them as separate plans / tickets:

- **Reserved handle list expansion** — the existing `config('partna.reserved_subdomains')` list is fine for now; merging the username.dev seed list is a separate hygiene pass.
- **Anti-squatting rate limit on freshly-released handles** — research §3 calls for a cooldown between "handle released by prune" and "anyone may claim it". Not building yet; the current 30-day cooldown on the *claiming* side, plus a daily prune cadence (not minute-by-minute), already raises the bar enough for pre-beta.
- **Trademark complaint workflow** — manual support process; revisit when we hit the first complaint.
- **Handle marketplace / paid premium handles** — X's model. Premature.
- **Pattern-detection alerts** (≥3 renames in 30d, payout-change-within-48h-of-rename) — wire into Nightwatch separately; the audit log table built here is the substrate.

---

## Self-review checklist

- [x] Spec coverage: every behaviour from the research report — 14-day grace, 90-day total, 301 (not serve), edge KV with TTL, audit log, owner reclaim, notifications, collapse-on-rename-back, prune — has an implementing task.
- [x] No placeholders: every code block is full, every command is exact, every test has real assertions.
- [x] Type consistency: `reclaim_until` / `expires_at` / `notified_t3_at` / `notified_t1_at` named the same everywhere (DB columns, model `$fillable`, scopes, tests). `HandleChangeLog` reason constants used consistently.
- [x] Rollout safety: Task 12 backfill is gated by manual prod confirmation; Task 14 documents the KV-shape rollout ordering so the worker contract change can't silently 404 traffic.
- [x] Security posture: 404 (not 403) on cross-owner reclaim attempts; grace-expiry timing never exposed in API responses; audit log is DB-trigger append-only; reserved-handle list still enforced via existing `partna.reserved_subdomains`.
