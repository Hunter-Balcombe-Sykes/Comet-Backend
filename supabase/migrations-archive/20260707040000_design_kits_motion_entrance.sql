-- motion.entrance — Selection kit var driving the page-load entrance
-- treatment ('none' | 'fade' | 'rise' | 'stagger'). Package default: 'rise'.
-- NULLABLE, no DB default — code-side defaults fill at read time (kit rules).
ALTER TABLE site.design_kits ADD COLUMN IF NOT EXISTS motion_entrance TEXT NULL;
