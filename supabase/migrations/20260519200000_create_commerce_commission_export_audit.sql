-- 20260519100000_create_commerce_commission_export_audit.sql
-- Audit table for the async commission transactions export pipeline.
-- One row per export request; tracks progress (cursor + counters), final file
-- metadata, and retention deadline. Indexes are in a separate file per the
-- post-2026-05-14 CONCURRENTLY convention.

BEGIN;

CREATE TABLE commerce.commission_export_audit (
    id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id             uuid REFERENCES core.professionals(id) ON DELETE SET NULL,
    role                        text NOT NULL CHECK (role IN ('brand','affiliate')),
    format                      text NOT NULL CHECK (format IN ('csv','xlsx')),
    filters                     jsonb NOT NULL DEFAULT '{}'::jsonb,
    status                      text NOT NULL DEFAULT 'queued'
                                CHECK (status IN ('queued','processing','completed','failed','cancelled')),
    recipient_email             text NOT NULL,

    payouts_total               integer NOT NULL DEFAULT 0,
    payouts_processed           integer NOT NULL DEFAULT 0,
    chunk_size                  integer NOT NULL DEFAULT 500,
    chunks_total                integer NOT NULL DEFAULT 0,
    chunks_completed            integer NOT NULL DEFAULT 0,
    last_processed_payout_id    uuid,
    next_chunk_index            integer NOT NULL DEFAULT 0,

    file_path                   text,
    file_size_bytes             bigint,
    file_sha256                 text,

    error_message               text,

    created_at                  timestamptz NOT NULL DEFAULT now(),
    processing_at               timestamptz,
    completed_at                timestamptz,
    expires_at                  timestamptz
);

COMMENT ON TABLE commerce.commission_export_audit IS
    'Per-request audit row for async commission transactions exports. Drives chunk-job orchestration, progress UI, and retention.';
COMMENT ON COLUMN commerce.commission_export_audit.last_processed_payout_id IS
    'Stable cursor: chunk N fetches payouts after this id. Survives inserts/deletes.';
COMMENT ON COLUMN commerce.commission_export_audit.expires_at IS
    'When the file in R2 becomes eligible for the daily prune sweep.';

COMMIT;
