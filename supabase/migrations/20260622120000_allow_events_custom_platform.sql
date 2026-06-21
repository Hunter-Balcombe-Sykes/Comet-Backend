-- Allow the 'events-custom' platform key on site.platform_connections.
--
-- The "Tickets & Events" smart-detect card stores a pasted link that is neither
-- an Eventbrite nor a Humanitix URL as a snapshot custom-event row under this
-- platform (one-shot, never refreshed — same contract as custom links), so it
-- renders alongside real events on the sitepage Events section.
--
-- Postgres can't extend an existing CHECK in place, so drop + re-add it with the
-- full allow-list plus 'events-custom'. Follows CONVENTIONS.md §2: ADD ... NOT
-- VALID (lock-light), then VALIDATE in a separate transaction. Every existing row
-- already satisfies the widened (superset) constraint, so validation is instant.

-- Step A — swap the constraint, NOT VALID (skips the existing-row scan under lock).
BEGIN;
ALTER TABLE site.platform_connections DROP CONSTRAINT IF EXISTS platform_connections_platform_check;
ALTER TABLE site.platform_connections ADD CONSTRAINT platform_connections_platform_check
  CHECK (platform = ANY (ARRAY[
    'shop', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast', 'spotify',
    'soundcloud', 'bandcamp', 'mixcloud', 'deezer', 'tidal', 'youtube-music',
    'youtube', 'vimeo', 'twitch', 'instagram', 'pinterest', 'tiktok', 'facebook',
    'x', 'linkedin', 'threads', 'reddit', 'fresha', 'square', 'skool', 'strava',
    'google-business', 'custom', 'opentable', 'booking', 'reservations',
    'online-ordering', 'resdiary', 'nowbookit', 'events-custom'
  ]::text[])) NOT VALID;
COMMIT;

-- Step B — validate under SHARE UPDATE EXCLUSIVE (allows concurrent reads/writes).
BEGIN;
ALTER TABLE site.platform_connections VALIDATE CONSTRAINT platform_connections_platform_check;
COMMIT;
