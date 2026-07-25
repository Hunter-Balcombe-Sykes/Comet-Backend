-- Backfill the promoted columns from payload, then strip ONLY apifyStatus from
-- payload (placeId STAYS — first-class identifier). No transaction (CONVENTIONS
-- §5); each UPDATE auto-commits. Pre-beta = zero rows, so this is a no-op now —
-- written for prod-shape parity. apify_status values are constrained by the CHECK
-- added in file 1 (any non-enum value fails closed, which is correct).
UPDATE site.platform_connections
SET apify_status = payload->>'apifyStatus'
WHERE platform = 'google-business'
  AND payload ? 'apifyStatus'
  AND apify_status IS NULL;

UPDATE site.platform_connections
SET place_id = payload->>'placeId'
WHERE platform = 'google-business'
  AND payload ? 'placeId'
  AND place_id IS NULL;

UPDATE site.platform_connections
SET payload = payload - 'apifyStatus'
WHERE platform = 'google-business'
  AND payload ? 'apifyStatus';

-- ROLLBACK:
-- Re-inject apifyStatus back into payload from the column (placeId already in payload):
-- UPDATE site.platform_connections
-- SET payload = jsonb_set(payload, '{apifyStatus}', to_jsonb(apify_status))
-- WHERE platform = 'google-business' AND apify_status IS NOT NULL;
