-- =====================================================================
-- Design kit: background-image toggle (boolean)
-- =====================================================================
-- Adds a boolean column to back effects.bgImageEnabled in the
-- @partnaau/design-system kit. When true (default in the package, NULL
-- in the DB column = use the package's true default), the skeleton
-- dispatcher emits `--dk-bg-image: url(content_images[0].url)` so the
-- body background renders the user's first content image as cover/
-- center/no-repeat. When false (or no content image), the vars.css
-- fallback `--dk-bg-image: none` applies and only colors.bg shows.
--
-- Stored as BOOLEAN to make truthy/falsy values explicit; NULL = "no
-- override, use the package default" (true), false = "explicit off",
-- true = "explicit on" (functionally same as NULL but lets the editor
-- distinguish "user opted in" from "user hasn't touched it").
--
-- groupKitColumns in IndividualProfilePayloadBuilder already maps
-- effect_* → effects.* and snakes the rest, so this column flows into
-- designKit.effects.bgImageEnabled automatically — no PHP change.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  ADD COLUMN effect_bg_image_enabled BOOLEAN NULL;

COMMIT;
