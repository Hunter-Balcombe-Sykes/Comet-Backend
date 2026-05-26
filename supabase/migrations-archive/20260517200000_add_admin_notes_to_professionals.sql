-- B6 #NOTES-1: Staff-only free-text notes column on professionals so support
-- can pin tribal knowledge ("VIP brand, do not suspend", "DMCA pending") to a
-- brand's record. Exposed via ProfessionalStaffResource only — never leaked
-- through ProfessionalResource (/me).
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL;
