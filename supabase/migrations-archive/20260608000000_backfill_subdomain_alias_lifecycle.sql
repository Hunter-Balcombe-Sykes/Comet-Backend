-- Backfill subdomain-alias lifecycle stamps (SEM-1 follow-up).
--
-- Before the fix in commit 4d96fe37, SiteSubdomainAlias::$fillable omitted
-- reclaim_until/expires_at, so UpdateSiteAction's create([...]) silently
-- dropped both stamps and every subdomain alias was born with NULL expiry.
-- Such rows are treated as permanently active by scopeActive() (NULL expiry =
-- active) and are skipped by handles:prune-expired-aliases (it filters
-- WHERE expires_at IS NOT NULL), so they never expire and never release the
-- subdomain back to the pool.
--
-- This one-shot UPDATE reconstructs the lifecycle the rows SHOULD have had,
-- anchored on each row's own created_at:
--   reclaim_until = created_at + 14 days  (owner-only grace window)
--   expires_at    = created_at + 90 days  (301-redirect window, then pruned)
-- The 14/90 literals mirror config('partna.handle.reclaim_days'/'redirect_days')
-- defaults — a SQL migration cannot read Laravel config, so the defaults are
-- inlined here.
--
-- Pure data backfill: NO BEGIN/COMMIT (CONVENTIONS.md §5). The table is tiny
-- and not a hot table, but the convention holds regardless. Idempotent — the
-- WHERE clause means a re-run is a no-op and a fresh DB (no rows) is a no-op.
UPDATE site.site_subdomain_aliases
SET reclaim_until = created_at + INTERVAL '14 days',
    expires_at    = created_at + INTERVAL '90 days'
WHERE expires_at IS NULL;
