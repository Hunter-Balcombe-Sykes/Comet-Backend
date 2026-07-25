-- =====================================================================
-- Credentials & Experience — promote core.users.about JSONB → child tables
-- =====================================================================
-- Credentials and experience entries are relational data with ordering, typed
-- fields, and per-row visibility gates. Embedding them in core.users.about JSONB
-- forces full-table scans on the batchCheck visibility pass. Promote each array
-- to its own table keyed by (user_id, sort_order).
--
-- The `about` column on core.users is NOT dropped here — the column is retained
-- (blast-radius minimization) so any legacy reads degrade gracefully rather than
-- erroring during the rollout window. The column simply stops being written.
-- Drop it in a follow-up migration once all read paths are confirmed clean.

-- ── Credentials ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS core.user_credentials (
    id          uuid        PRIMARY KEY,
    user_id     uuid        NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
    title       text        NOT NULL,
    issuer      text,
    year        text,           -- stored as text; cast to int at read time
    description text,
    sort_order  integer     NOT NULL DEFAULT 0,
    created_at  timestamptz,
    updated_at  timestamptz
);

CREATE INDEX IF NOT EXISTS idx_user_credentials_user
    ON core.user_credentials (user_id, sort_order);

-- ── Experience ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS core.user_experience (
    id           uuid        PRIMARY KEY,
    user_id      uuid        NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
    role         text        NOT NULL,
    organisation text,           -- holds the "place" field from the legacy payload
    start_year   text,
    end_year     text,
    description  text,
    sort_order   integer     NOT NULL DEFAULT 0,
    created_at   timestamptz,
    updated_at   timestamptz
);

CREATE INDEX IF NOT EXISTS idx_user_experience_user
    ON core.user_experience (user_id, sort_order);

-- ── Backfill from about JSONB ────────────────────────────────────────────────
-- Iterates every user that has a non-empty about.credentials / about.experience
-- array and inserts a row per entry with sort_order = array index.
-- The about column stays intact after backfill (it is NOT stripped).
-- Cast failures (malformed data) are skipped via WHERE filters below.

-- Credentials backfill
INSERT INTO core.user_credentials (id, user_id, title, issuer, year, description, sort_order, created_at, updated_at)
SELECT
    gen_random_uuid(),
    u.id,
    NULLIF(btrim(c->>'title'), ''),
    NULLIF(btrim(c->>'issuer'), ''),
    NULLIF(btrim(c->>'year'), ''),
    NULLIF(btrim(c->>'description'), ''),
    (row_number() OVER (PARTITION BY u.id ORDER BY ordinality) - 1)::integer,
    now(), now()
FROM core.users u,
     jsonb_array_elements(u.about->'credentials') WITH ORDINALITY AS t(c, ordinality)
WHERE (u.about ? 'credentials')
  AND jsonb_typeof(u.about->'credentials') = 'array'
  AND (c->>'title') IS NOT NULL
  AND btrim(c->>'title') <> '';

-- Experience backfill
INSERT INTO core.user_experience (id, user_id, role, organisation, start_year, end_year, description, sort_order, created_at, updated_at)
SELECT
    gen_random_uuid(),
    u.id,
    NULLIF(btrim(e->>'role'), ''),
    NULLIF(btrim(COALESCE(e->>'place', e->>'organisation', e->>'organization')), ''),
    NULLIF(btrim(e->>'start'), ''),
    NULLIF(btrim(e->>'end'), ''),
    NULLIF(btrim(e->>'description'), ''),
    (row_number() OVER (PARTITION BY u.id ORDER BY ordinality) - 1)::integer,
    now(), now()
FROM core.users u,
     jsonb_array_elements(u.about->'experience') WITH ORDINALITY AS t(e, ordinality)
WHERE (u.about ? 'experience')
  AND jsonb_typeof(u.about->'experience') = 'array'
  AND (e->>'role') IS NOT NULL
  AND btrim(e->>'role') <> '';

-- ROLLBACK:
-- DROP TABLE IF EXISTS core.user_credentials;
-- DROP TABLE IF EXISTS core.user_experience;
