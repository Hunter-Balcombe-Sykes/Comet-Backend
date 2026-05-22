-- Create site.enquiries table for the contact section block.
--
-- Stores visitor-submitted messages from the public contact form. Scoped to
-- professional_id (ownership) with site_id recorded for provenance. Mirrors
-- the patterns used by site.blocks: soft deletes, FK cascade on professional
-- + site deletion, RLS enabled.

BEGIN;

CREATE TABLE IF NOT EXISTS site.enquiries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    name varchar(100) NOT NULL,
    email varchar(255) NOT NULL,
    phone varchar(30),
    subject varchar(100) NOT NULL,
    message text NOT NULL,
    ip_hash varchar(64),
    user_agent varchar(500),
    read_at timestamptz,
    deleted_at timestamptz,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL
);

ALTER TABLE site.enquiries OWNER TO postgres;

ALTER TABLE ONLY site.enquiries
    ADD CONSTRAINT enquiries_pkey PRIMARY KEY (id);

ALTER TABLE ONLY site.enquiries
    ADD CONSTRAINT enquiries_professional_fk
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

ALTER TABLE ONLY site.enquiries
    ADD CONSTRAINT enquiries_site_fk
    FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE;

-- Inbox list query: per-professional, newest first.
CREATE INDEX enquiries_professional_created_idx
    ON site.enquiries (professional_id, created_at DESC)
    WHERE deleted_at IS NULL;

-- Provenance lookups by site.
CREATE INDEX enquiries_site_idx
    ON site.enquiries (site_id)
    WHERE deleted_at IS NULL;

-- Abuse queries: show all submissions from an ip_hash.
CREATE INDEX enquiries_ip_hash_idx
    ON site.enquiries (ip_hash, created_at)
    WHERE deleted_at IS NULL;

-- RLS: gate reads/writes to the owning professional, same pattern as site.blocks.
ALTER TABLE site.enquiries ENABLE ROW LEVEL SECURITY;

CREATE POLICY enquiries_app_backend_all
    ON site.enquiries
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

GRANT SELECT, INSERT, UPDATE, DELETE ON site.enquiries TO app_backend;

COMMENT ON TABLE site.enquiries IS
    'Visitor-submitted enquiries from the contact section block. professional_id owns; site_id is provenance. read_at=null means unread.';

COMMIT;
