-- #P3-18 (Bundle B22): record the delivery outcome of the GDPR data-export
-- notification email so support can distinguish "I never received my export"
-- from a hard bounce. markEmailSent() stamps 'sent' on handoff to the mailer;
-- a future Resend delivery/bounce webhook handler advances the value to
-- 'delivered' / 'bounced' / 'complaint'.
--
-- The inline column-level CHECK is safe here even on a populated table: the
-- column is brand-new so every existing row defaults to NULL, which satisfies
-- the CHECK without a table scan. (The guard:no-unsafe-migrations lint only
-- flags named table-level `ADD CONSTRAINT ... CHECK`, not inline column
-- constraints on a freshly added column — see CONVENTIONS.md §2.)

ALTER TABLE audit.data_export_audit
    ADD COLUMN IF NOT EXISTS email_delivery_status TEXT NULL
        CHECK (email_delivery_status IN ('sent', 'delivered', 'bounced', 'complaint'));
