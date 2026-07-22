-- Profile design presets are computed at read time from core.users fields
-- (ProfileDesignPresets); the stored integration-factor contribution layer
-- is retired. Manual site.design_kits columns are untouched.
drop table if exists site.design_kit_contributions;
