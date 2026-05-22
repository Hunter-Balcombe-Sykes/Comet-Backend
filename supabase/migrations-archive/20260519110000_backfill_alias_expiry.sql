-- 20260519110000_backfill_alias_expiry.sql
-- Stamps expires_at on legacy alias rows that predate the lifecycle system.
-- Policy:
--   • Aliases < 90 days old: full 90-day lifetime measured from created_at
--   • Aliases >= 90 days old: short 14-day grace window from rollout (notified before prune)
-- NOTE: Push to dev first. Always confirm with Josh before running against prod.

BEGIN;

-- Newer aliases (< 90 days old): full 90-day lifecycle from created_at
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

-- Older aliases (>= 90 days): 14-day grace from rollout (T-3 + T-1 notifications will fire first)
UPDATE site.professional_handle_aliases
   SET expires_at = now() + interval '14 days'
 WHERE expires_at IS NULL;

UPDATE site.site_subdomain_aliases
   SET expires_at = now() + interval '14 days'
 WHERE expires_at IS NULL;

COMMIT;
