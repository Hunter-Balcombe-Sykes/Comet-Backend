-- Drop orphan typography columns added in the early design-kit migrations
-- (20260527080000). The vars were wiped from @partnaau/design-system before
-- any client ever wrote non-NULL values; columns are confirmed all-NULL.
ALTER TABLE site.design_kits
  DROP COLUMN IF EXISTS typography_font_heading,
  DROP COLUMN IF EXISTS typography_font_body;
