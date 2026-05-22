-- Track when a site was programmatically unpublished (e.g. account pending_deletion).
ALTER TABLE site.sites ADD COLUMN IF NOT EXISTS unpublished_at timestamptz;

COMMENT ON COLUMN site.sites.unpublished_at IS
  'Set by AccountDeletionService when a pending_deletion transition forces is_published=false. '
  'Cleared to NULL on cancel. Used as a signal: NULL means the site was manually unpublished '
  '(cancel must not re-publish it); non-NULL means the deletion flow owns the state.';
