-- users.bio: the person's own About Me paragraph (2026-08-19 identity plan,
-- decision 3). Both account types. A previous `bio` column was dropped by
-- 20260705120002_drop_dead_profile_columns_tables as dead — this one is a
-- deliberate re-add with live consumers (dashboard Identity page, public
-- profile wire), not a revert of that cleanup.
ALTER TABLE core.users ADD COLUMN bio text;
COMMENT ON COLUMN core.users.bio IS 'Owner-authored About Me paragraph, max 1000 chars app-side. For business accounts the workplace description mirrors onto this (WorkplaceObserver); for partna it is independent.';
