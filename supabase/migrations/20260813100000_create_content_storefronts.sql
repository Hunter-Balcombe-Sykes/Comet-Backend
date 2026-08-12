-- 20260813100000_create_content_storefronts.sql
-- Slice 5a §3.1. A 1:1 sidecar on content.collections carrying storefront
-- behaviour, so content.collections stays generic for the service and menu
-- categories that slices 3 and 4 put in it.
--
-- ROLLBACK: DROP TABLE IF EXISTS content.storefronts;
CREATE TABLE IF NOT EXISTS content.storefronts (
    collection_id       uuid PRIMARY KEY REFERENCES content.collections(id) ON DELETE CASCADE,
    provider            text        NOT NULL,
    url                 text,
    source_url          text,
    currency            text,
    discount_code       text,
    referral_query      text        NOT NULL DEFAULT '',
    is_individual       boolean     NOT NULL DEFAULT false,
    fetch_mode          text,
    connect_status      text,
    connect_error       text,
    products_curated_at timestamptz,
    logo_url            text,
    favicon_url         text,
    logo_mark_url       text,
    logo_mark_svg_url   text,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE content.storefronts IS
    'Slice 5a: per-store behaviour for a content.collections row of kind=storefront. referral_query is affiliate revenue — see spec §3.7.';
