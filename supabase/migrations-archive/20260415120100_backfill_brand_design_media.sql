-- Strip brand design media keys from site.sites.settings now that site_media
-- is the source of truth.
--
-- Why: after this migration, nothing reads settings.design.logo or
-- settings.design.media.placeholder_sitepage_images. Leaving them in place
-- creates drift risk and confuses future readers — the JSONB would say one
-- thing and the site_media table would say another.
--
-- Pre-beta context: dashboards already write through site_media via the upload
-- endpoints, and SyncShopifyBrandDesignJob was updated in the same change set
-- to write site_media too. Existing rows in production were already covered
-- when the user verified site_media has the matching design pool entries; this
-- migration finishes the cutover by clearing the dead JSONB keys.

BEGIN;

-- Step 1 — Surface any site whose JSONB had placeholder URLs but no matching
-- site_media row, so a human notices if there's pre-existing drift. NOTICE
-- only — does not abort.
DO $$
DECLARE
    drift_row record;
    drift_count int := 0;
BEGIN
    FOR drift_row IN
        SELECT s.id AS site_id, s.professional_id
        FROM site.sites s
        WHERE s.settings IS NOT NULL
          AND jsonb_typeof(s.settings) = 'object'
          AND jsonb_array_length(COALESCE(s.settings->'design'->'media'->'placeholder_sitepage_images', '[]'::jsonb)) > 0
          AND NOT EXISTS (
              SELECT 1 FROM site.site_media sm
              WHERE sm.site_id = s.id
                AND sm.pool = 'design'
                AND sm.purpose = 'placeholder'
                AND sm.deleted_at IS NULL
          )
    LOOP
        RAISE NOTICE 'Backfill drift: site % (professional %) has JSONB placeholders but no site_media rows.',
            drift_row.site_id, drift_row.professional_id;
        drift_count := drift_count + 1;
    END LOOP;

    IF drift_count > 0 THEN
        RAISE NOTICE 'Total drift sites: %', drift_count;
    END IF;
END $$;

-- Step 2 — Same drift check for logos.
DO $$
DECLARE
    drift_row record;
    drift_count int := 0;
BEGIN
    FOR drift_row IN
        SELECT s.id AS site_id, s.professional_id
        FROM site.sites s
        WHERE s.settings IS NOT NULL
          AND jsonb_typeof(s.settings) = 'object'
          AND (
              s.settings->'design'->'logo'->>'full_url' IS NOT NULL
              OR s.settings->'design'->'logo'->>'square_url' IS NOT NULL
          )
          AND NOT EXISTS (
              SELECT 1 FROM site.site_media sm
              WHERE sm.site_id = s.id
                AND sm.pool = 'design'
                AND sm.purpose IN ('logo_full', 'logo_square')
                AND sm.deleted_at IS NULL
          )
    LOOP
        RAISE NOTICE 'Backfill drift: site % (professional %) has JSONB logo but no site_media rows.',
            drift_row.site_id, drift_row.professional_id;
        drift_count := drift_count + 1;
    END LOOP;

    IF drift_count > 0 THEN
        RAISE NOTICE 'Total drift sites: %', drift_count;
    END IF;
END $$;

-- Step 3 — Strip placeholder_sitepage_images and brand_logo_* from settings.design.media.
UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design,media}',
    COALESCE(settings->'design'->'media', '{}'::jsonb)
        - 'placeholder_sitepage_images'
        - 'brand_logo_url'
        - 'brand_logo_path'
        - 'brand_logo_name',
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object'
  AND settings->'design'->'media' IS NOT NULL;

-- Step 4 — Strip logo subtree from settings.design.
UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design}',
    (settings->'design') - 'logo',
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object'
  AND settings->'design' ? 'logo';

COMMIT;
