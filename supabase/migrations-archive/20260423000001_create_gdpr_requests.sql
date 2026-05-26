-- Create core.gdpr_requests — audit trail + idempotency guard for Shopify GDPR webhooks.
--
-- Why: Shopify retries webhooks on any non-2xx response. Without an idempotency key
-- we'd process the same `customers/redact` or `shop/redact` twice. The unique index
-- on payload_hash (sha256 of raw body) gives us a fast dedupe at write time.
--
-- Also adds core.customers.redacted_at so RedactCustomerJob can mark anonymised
-- rows (the row is kept for commission ledger integrity; only PII is overwritten).

BEGIN;

CREATE TABLE IF NOT EXISTS core.gdpr_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    topic text NOT NULL,
    shop_domain text NOT NULL,
    shopify_shop_id bigint,
    payload_hash char(64) NOT NULL,
    payload jsonb NOT NULL,
    professional_id uuid,
    status text NOT NULL DEFAULT 'received',
    error text,
    received_at timestamptz DEFAULT now() NOT NULL,
    completed_at timestamptz,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    CONSTRAINT gdpr_requests_pkey PRIMARY KEY (id),
    CONSTRAINT gdpr_requests_topic_chk CHECK (topic IN ('customers/data_request', 'customers/redact', 'shop/redact')),
    CONSTRAINT gdpr_requests_status_chk CHECK (status IN ('received', 'processing', 'completed', 'failed', 'skipped'))
);

ALTER TABLE core.gdpr_requests OWNER TO postgres;

ALTER TABLE ONLY core.gdpr_requests
    ADD CONSTRAINT gdpr_requests_professional_fk
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

-- Idempotency: unique on sha256(raw body). Duplicate deliveries from Shopify
-- fail insert and the controller treats that as "already handled".
CREATE UNIQUE INDEX gdpr_requests_payload_hash_unique
    ON core.gdpr_requests (payload_hash);

CREATE INDEX gdpr_requests_shop_topic_idx
    ON core.gdpr_requests (shop_domain, topic, received_at DESC);

-- Ops query: find stuck jobs (received/processing rows older than N hours).
CREATE INDEX gdpr_requests_status_received_idx
    ON core.gdpr_requests (status, received_at);

ALTER TABLE core.gdpr_requests ENABLE ROW LEVEL SECURITY;

CREATE POLICY gdpr_requests_app_backend_all
    ON core.gdpr_requests
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

GRANT SELECT, INSERT, UPDATE, DELETE ON core.gdpr_requests TO app_backend;

COMMENT ON TABLE core.gdpr_requests IS
    'Audit trail for Shopify GDPR webhooks. payload_hash (sha256 of raw body) has a unique index — this is the idempotency guard for Shopify retries.';

-- Add redacted_at to core.customers — marker for GDPR customer anonymisation.
-- Row is kept (commission ledger integrity); email/phone/full_name are overwritten with placeholders.
ALTER TABLE core.customers
    ADD COLUMN IF NOT EXISTS redacted_at timestamptz;

COMMENT ON COLUMN core.customers.redacted_at IS
    'Set when customer PII is anonymised via Shopify customers/redact webhook. Non-null means email/phone/full_name have been overwritten with placeholders.';

COMMIT;
