-- Unify the Instagram "Gallery" display toggle with the Content/Media "Latest
-- content auto sync" switch onto ONE field: site.sites.content_instagram_auto_enabled.
--
-- Historically these were two independent stores:
--   * display_settings['gallery'] on site.platform_connections (absent = ON)
--       gated the Instagram integration CARD payload.
--   * content_instagram_auto_enabled on site.sites (NULL = OFF)
--       gated the curated reel/post auto-slots.
-- The app now backs BOTH the toggle and the card gate on the boolean column, so
-- one switch governs all auto Instagram content. This one-time reconciliation
-- moves legacy display-toggle intent onto the boolean BEFORE the card gate
-- starts reading it, so no site's card silently flips, then strips the defunct
-- 'gallery' key. It preserves each site's CARD visibility; the curated slots
-- follow the unified value thereafter.

WITH ig AS (
    SELECT pc.user_id,
           bool_or((pc.display_settings ->> 'gallery') IS DISTINCT FROM 'false') AS any_on
    FROM site.platform_connections pc
    WHERE pc.platform = 'instagram' AND pc.is_active = true
    GROUP BY pc.user_id
)
UPDATE site.sites s
SET content_instagram_auto_enabled = CASE
        -- Never set (NULL): adopt the legacy card intent -- ON unless every
        -- active connection explicitly hid the gallery.
        WHEN s.content_instagram_auto_enabled IS NULL THEN ig.any_on
        -- Curated ON but the card was explicitly hidden everywhere: a deliberate
        -- hide must survive the unification.
        WHEN s.content_instagram_auto_enabled = true AND ig.any_on = false THEN false
        ELSE s.content_instagram_auto_enabled
    END
FROM ig
WHERE s.user_id = ig.user_id;

-- Drop the defunct 'gallery' key from every Instagram connection's
-- display_settings; null out any map left empty so no zombie state remains.
UPDATE site.platform_connections
SET display_settings = NULLIF(display_settings - 'gallery', '{}'::jsonb)
WHERE platform = 'instagram'
  AND display_settings ? 'gallery';
