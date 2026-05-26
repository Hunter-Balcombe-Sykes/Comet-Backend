-- 20260508500000_rename_url_columns.sql
-- Originally: renamed user_url → partna_url and brand_affiliate_url → site_url.
-- After the source migration (20260508100000) was updated to use the final column
-- names directly, this file became a no-op for fresh installs. The column renames
-- were absorbed into 20260508100000. This migration is kept to maintain the
-- applied-migration history record on the dev database.
--
-- Safe cleanup: drop the interim function name if it somehow still exists.

BEGIN;

DROP FUNCTION IF EXISTS site.trg_recompute_user_url(uuid);

COMMIT;
