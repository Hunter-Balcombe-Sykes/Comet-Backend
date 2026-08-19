-- ROLLBACK: NONE. Both UPDATEs overwrite without a recorded pre-image
-- (nulled partna contact pairs, copied business description/address).
-- Identity-mirror backfill (2026-08-19 plan, decision 11).
--
-- BEGIN + SET LOCAL added 2026-08-19: core.users is a HOT_TABLES member
-- (CONVENTIONS.md / guard Check 5) and both statements below rewrite a large
-- slice of it. Without the timeouts the backfill queues behind live traffic
-- instead of failing fast, and the guard rejects the file — which aborts
-- `composer test` before Pest, since the guard runs first in that script.
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '30s';
--
-- partna: the workplace is WHERE THEY WORK — its contact pair should never
-- have mirrored onto the person's public contact. Null the mirrored values;
-- each person re-enters their own (accepted consequence: the sitepage's
-- contact line goes empty until they do).
UPDATE core.users
SET public_contact_number = NULL,
    public_contact_email = NULL
WHERE account_type = 'partna';

-- business: the workplace IS the account — copy its description and address
-- onto the matching user columns so the new mirror starts from a consistent
-- state. COALESCE keeps any user-entered value where the workplace has none.
UPDATE core.users u
SET bio = COALESCE(w.description, u.bio),
    location_street_address = COALESCE(w.address_line1, u.location_street_address),
    location_city = COALESCE(w.city, u.location_city),
    location_state = COALESCE(w.state, u.location_state),
    location_postcode = COALESCE(w.postcode, u.location_postcode),
    location_country = COALESCE(w.country, u.location_country)
FROM site.sites s
JOIN site.workplaces w ON w.site_id = s.id
WHERE s.user_id = u.id
  AND u.account_type = 'business';
COMMIT;
