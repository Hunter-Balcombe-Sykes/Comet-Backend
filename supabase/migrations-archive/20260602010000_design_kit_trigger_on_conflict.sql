-- Make site.create_empty_design_kit() idempotent.
-- The original bare INSERT fails with a PK violation if a design_kits row
-- already exists (backfill race, re-insert, or test fixture), which aborts
-- the parent site.sites INSERT and prevents site creation. ON CONFLICT DO
-- NOTHING treats an existing row as a success.
CREATE OR REPLACE FUNCTION site.create_empty_design_kit()
RETURNS TRIGGER AS $$
BEGIN
  INSERT INTO site.design_kits (site_id) VALUES (NEW.id) ON CONFLICT (site_id) DO NOTHING;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
