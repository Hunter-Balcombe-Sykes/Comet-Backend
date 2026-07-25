-- Removes the CSAM pipeline tables. The moderation foundation (cases, decisions,
-- action_log, etc.) is unchanged. site.site_media scan columns are left in place —
-- they are harmless without the pipeline and will be used when CSAM is re-added.

BEGIN;

DROP TABLE IF EXISTS moderation.ncmec_submissions;
DROP TABLE IF EXISTS moderation.csam_quarantine;

COMMIT;
