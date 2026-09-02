-- Owner (2026-09-03): the same Instagram / Google Business source may start
-- any number of sign-ups — a fresh, independent build each time, never a
-- re-serve of an existing one and never a redirect to an existing account.
-- Uniqueness survives ONLY for the outreach lanes (staff / early_access,
-- which includes ManyChat webhook builds), whose CSV idempotency, webhook
-- retry dedupe and token-mint security all depend on one live build per
-- source. built_via is NOT NULL with a CHECK constraint, so a plain <> is
-- null-safe here.
DROP INDEX IF EXISTS "core"."pre_account_builds_live_source_unique";
CREATE UNIQUE INDEX "pre_account_builds_live_source_unique" ON "core"."pre_account_builds" USING "btree" ("source_type", "source_ref_lc") WHERE (("claimed_at" IS NULL) AND ("built_via" <> 'signup'));
