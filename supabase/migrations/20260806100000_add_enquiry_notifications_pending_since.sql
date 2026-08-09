-- Enquiry notification dispatch marker (2026-08-06 drill-03 finding 3).
--
-- PublicEnquiryController commits the enquiry row and THEN dispatches two
-- notification jobs. With Redis down the dispatch throws, so the lead is
-- persisted but nobody is ever told. This column records "dispatch failed",
-- which enquiries:reconcile-notifications drains once Redis returns.
--
-- WHY NOT REUSE email_sent_at / confirmation_sent_at. Those are the JOBS'
-- post-send idempotency stamps and they stay — they are what makes re-dispatch
-- safe. They cannot DRIVE the reconciler: both jobs are gated, so a correctly
-- skipped notification also leaves them NULL, and a reconciler keyed on NULL
-- would re-dispatch permanently-skipped enquiries every five minutes forever.
--
-- ROLLBACK: DROP INDEX site.enquiries_notifications_pending_idx;
--           ALTER TABLE site.enquiries DROP COLUMN notifications_pending_since;
ALTER TABLE "site"."enquiries"
    ADD COLUMN IF NOT EXISTS "notifications_pending_since" timestamp with time zone;

-- Partial: empty in steady state, which is the point. Only rows whose dispatch
-- actually failed are indexed, so the reconciler's sweep touches nothing on a
-- healthy system.
CREATE INDEX IF NOT EXISTS "enquiries_notifications_pending_idx"
    ON "site"."enquiries" ("notifications_pending_since")
    WHERE "notifications_pending_since" IS NOT NULL;
