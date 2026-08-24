-- FI-1 convergence (scan-refinement run, 2026-08-20): socials are
-- single-account now (owner decree). Engine 1 harvests auto-created up to 5
-- connections per social surface while the catalog said multiAccount(5) —
-- converge existing data to the new invariant by soft-deleting every ACTIVE
-- social connection past the first per (user, surface), keeping is_primary
-- first, then the oldest. Soft delete (not hard): it is the app's own removal
-- shape, and the tombstone it leaves matches intent — these rows were
-- bug-created, the owner never chose them; a later DIRECT connect still beats
-- the tombstone by policy, so nothing is unrecoverable.
--
-- Dev impact measured before writing: exactly one affected user (the
-- gsnwilliams test account's reproduction artifact). This exists for any
-- environment that accumulated more.
--
-- FI-3's fake short-code profiles (on.soundcloud.com etc. minted via the
-- removed Hosts aliases): dev has zero — checked — so no clause for them;
-- resolveSocialLink's conflict finding self-heals any stragglers on rescan.
--
-- ROLLBACK: NONE. The soft-deletes are indistinguishable from user
--   disconnects after the fact; restoring them would resurrect rows the
--   invariant forbids. Roll forward.

with ranked as (
    select id,
           row_number() over (
               partition by user_id, surface_key
               -- is_active FIRST (final critic, 2026-08-20): an inactive
               -- takedown/placeholder row must never outrank the user's
               -- live connection, whatever its age or primary flag.
               order by is_active desc, is_primary desc, created_at asc, id asc
           ) as rn
    from site.platform_connections
    where routing_class = 'social'
      and deleted_at is null
)
update site.platform_connections pc
set deleted_at = now(), is_active = false, updated_at = now()
from ranked
where ranked.id = pc.id
  and ranked.rn > 1;
