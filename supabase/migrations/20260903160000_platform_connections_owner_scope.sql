-- ROLLBACK: ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS owner_scope;
-- Additive and nullable, so the drop is an exact structural inverse. It
-- discards the scope recorded since this ran; the router lane re-derives it on
-- the next reconcile, and the backfill below is re-runnable, so nothing
-- unrecoverable is lost.

-- Whose account or link is this — the person's own, or the workplace they work
-- AT? Today both are stored identically, so a barber who books through the
-- shop's Fresha and someone with their own booking are indistinguishable, and
-- the page can only ever say "Book now" instead of "Book at Anseo Studio" vs
-- "Book with me".
--
-- A dedicated column rather than another `payload` key: `payload->>'source'`
-- already exists and cannot answer this. It is populated on roughly half the
-- rows and mixes three vocabularies — platform names ('instagram'), origins
-- ('link_in_bio'), the meaningless 'auto', and 'google-business' vs
-- 'google_business' as two spellings of one value. Overloading it would make a
-- fourth vocabulary, not a source of truth.
--
-- NULL is a first-class value meaning "we do not know", and most existing rows
-- keep it. It is never guessed: only the rows whose provenance is actually
-- recorded are backfilled below.
--
-- Written at connect time by RoutingCapabilityGate::ownerScopeFor(), from the
-- same rule that refuses the workplace's accounts (2026-09-03): a link found on
-- the workplace's website or Google listing, on an account whose
-- workplace_brand_is_site_identity is false, belongs to the workplace.
-- guard:no-unsafe-migrations:disable-file
-- Justification (2026-09-03, CI repair). The CHECK below constrains
-- `owner_scope`, a column ADDED BY THIS SAME FILE. Every row is NULL when it
-- runs and the constraint admits NULL, so the validation scan cannot fail —
-- the same reasoning Check 1 already encodes when exempting an index on a
-- column added in the same migration. Check 3 carries no analogous carve-out,
-- and that gap is what is being opted out of here, not a real locking risk.
-- The NOT VALID + VALIDATE split is also awkward in shape rather than merely
-- unnecessary: the constraint is created inside a DO block so the file stays
-- idempotent, and a transaction cannot be committed from within one, so the
-- split would mean restructuring an already-applied migration for no
-- behavioural gain.
-- site.platform_connections holds 1256 rows on dev (2026-09-03), so the scan is
-- milliseconds; the file is already applied there, and production carries zero
-- users, so every future execution is a from-zero apply against an empty table.
ALTER TABLE site.platform_connections
    ADD COLUMN IF NOT EXISTS owner_scope TEXT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'site.platform_connections'::regclass
          AND conname = 'platform_connections_owner_scope_check'
    ) THEN
        ALTER TABLE site.platform_connections
            ADD CONSTRAINT platform_connections_owner_scope_check
            CHECK (owner_scope IS NULL OR owner_scope IN ('self', 'workplace'));
    END IF;
END $$;

COMMENT ON COLUMN site.platform_connections.owner_scope IS
    'Whose this is: ''self'' (the account holder''s own) or ''workplace'' (the venue they work at). NULL = unknown, the state every row predating 2026-09-03 keeps. System-written by RoutingCapabilityGate::ownerScopeFor — not fillable.';

-- Backfill, guarded: production carries no `routing` schema at all, so an
-- unguarded reference to routing.source_intents would break a from-zero apply
-- there (see CLAUDE.md — prod is missing content/ingest/routing/catalog).
--
-- Only rows whose origin is RECORDED are touched, and only in the direction we
-- can prove. A workplace-sourced origin on an account whose workplace is not
-- its identity is the workplace's — that is the same inference the gate makes.
-- Everything else is left NULL rather than assumed 'self': a link the user
-- pasted and a link we never labelled look identical here, and inventing
-- provenance is worse than admitting we lack it.
-- The alias exclusion is load-bearing. SourceReconciler::applyIntent returns an
-- EXISTING connection's id when the found link resolves to an account the user
-- already holds (#R4 identity resolution), and the intent then records that
-- pre-existing connection_id. So a workplace-sourced intent can point at a
-- connection that is emphatically the person's OWN — jessejensz's shop lists
-- his Instagram (certifiedbarberboy), which is the very handle he signed up
-- with, and the naive backfill relabelled his own account as the salon's.
--
-- `created_at < first_seen_at` does NOT separate these: the connection is
-- written inside the reconcile and the intent stamped a moment later, so 7 of
-- the 9 dev rows "predate" their intent whether aliased or not. The identifier
-- does separate them — if it matches what the account signed up as, it is
-- theirs.
DO $$
BEGIN
    IF to_regclass('routing.source_intents') IS NOT NULL THEN
        UPDATE site.platform_connections c
           SET owner_scope = 'workplace'
          FROM routing.source_intents si,
               core.users u
         WHERE si.connection_id = c.id
           AND u.id = c.user_id
           AND c.owner_scope IS NULL
           AND u.account_type = 'partna'
           AND si.origin IN ('website_import', 'google_business')
           AND NOT EXISTS (
               SELECT 1
                 FROM core.pre_account_builds b
                WHERE b.user_id = u.id
                  AND lower(b.source_ref) = lower(si.identifier)
           );
    END IF;
END $$;
