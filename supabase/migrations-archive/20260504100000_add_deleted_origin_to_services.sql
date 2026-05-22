-- Track whether a service was deleted by Square catalog sync or manually in Side St.
-- NULL = manually deleted (never auto-restore on sync); 'square' = safe to restore
-- if Square re-sends the item. Existing deleted rows default to NULL (conservative).
ALTER TABLE site.services ADD COLUMN IF NOT EXISTS deleted_origin varchar(16) NULL;

COMMENT ON COLUMN site.services.deleted_origin IS
    'square = deleted by Square catalog sync; NULL = manually deleted in Side St';
