-- Document the opt-in semantics of public_contact_* fields on core.professionals.
-- These columns are intentionally distinct from primary_email / phone: a professional
-- sets them when they explicitly want a contact detail exposed on their public site.
-- NULL = not sharing publicly (the default). Partial unique indexes (NOT NULL) enforce
-- that once set, the value is globally unique across all professionals.

COMMENT ON COLUMN core.professionals.public_contact_number IS
  'Optional contact number the professional chooses to display publicly on their site. NULL = not shared. Setting a value constitutes opt-in; clearing it removes it from public view.';

COMMENT ON COLUMN core.professionals.public_contact_email IS
  'Optional contact email the professional chooses to display publicly on their site. NULL = not shared. Setting a value constitutes opt-in; clearing it removes it from public view. Distinct from primary_email which is never exposed publicly.';
