-- Audit log for brand-affiliate link lifecycle events (create / remove).
-- Rows in this table must outlive the brand_partner_links rows they describe,
-- so we FK to professionals instead and restrict cascade deletes.
CREATE TABLE IF NOT EXISTS brand.brand_partner_link_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,

    event_type text NOT NULL,
    actor_type text NOT NULL,
    actor_professional_id uuid REFERENCES core.professionals(id) ON DELETE SET NULL,
    staff_user_id uuid,

    slot_at_event smallint,
    pending_commission_count integer,
    pending_commission_cents bigint,
    commissions_voided_count integer DEFAULT 0,
    commissions_voided_cents bigint DEFAULT 0,

    reason text,

    created_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT brand_partner_link_events_event_type_check
        CHECK (event_type IN ('created', 'removed', 'commissions_voided_async')),
    CONSTRAINT brand_partner_link_events_actor_type_check
        CHECK (actor_type IN ('staff', 'brand', 'affiliate')),
    CONSTRAINT brand_partner_link_events_staff_actor_check
        CHECK (
            (actor_type = 'staff' AND staff_user_id IS NOT NULL)
            OR (actor_type <> 'staff')
        ),
    CONSTRAINT brand_partner_link_events_professional_actor_check
        CHECK (
            actor_type = 'staff'
            OR actor_professional_id IS NOT NULL
        ),
    CONSTRAINT brand_partner_link_events_slot_range
        CHECK (slot_at_event IS NULL OR slot_at_event BETWEEN 0 AND 3)
);

CREATE INDEX IF NOT EXISTS brand_partner_link_events_brand_idx
    ON brand.brand_partner_link_events (brand_professional_id, created_at DESC);
CREATE INDEX IF NOT EXISTS brand_partner_link_events_affiliate_idx
    ON brand.brand_partner_link_events (affiliate_professional_id, created_at DESC);
CREATE INDEX IF NOT EXISTS brand_partner_link_events_pair_idx
    ON brand.brand_partner_link_events (affiliate_professional_id, brand_professional_id, created_at DESC);
