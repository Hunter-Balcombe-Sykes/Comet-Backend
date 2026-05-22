-- ==========================================================================
-- Comet V2 Baseline Schema
-- Generated: 2026-04-03
-- Replaces: 82 incremental migrations from 20260109000000 to 20260331120000
--
-- Schemas: core, site, brand, commerce, notifications, analytics, billing, public
-- ==========================================================================

BEGIN;

-- ==========================================================================
-- 1. SCHEMA CREATION
-- ==========================================================================

CREATE SCHEMA IF NOT EXISTS core;
CREATE SCHEMA IF NOT EXISTS site;
CREATE SCHEMA IF NOT EXISTS brand;
CREATE SCHEMA IF NOT EXISTS commerce;
CREATE SCHEMA IF NOT EXISTS notifications;
CREATE SCHEMA IF NOT EXISTS analytics;
CREATE SCHEMA IF NOT EXISTS billing;
-- public schema exists by default

ALTER SCHEMA core OWNER TO postgres;
ALTER SCHEMA site OWNER TO postgres;
ALTER SCHEMA brand OWNER TO postgres;
ALTER SCHEMA commerce OWNER TO postgres;
ALTER SCHEMA notifications OWNER TO postgres;
ALTER SCHEMA analytics OWNER TO postgres;
ALTER SCHEMA billing OWNER TO postgres;

-- ==========================================================================
-- 2. SHARED FUNCTIONS
-- ==========================================================================

CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

ALTER FUNCTION public.set_updated_at() OWNER TO postgres;

CREATE OR REPLACE FUNCTION core.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION billing.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

-- Prevent non-admin staff from escalating roles
CREATE OR REPLACE FUNCTION core.prevent_staff_escalation()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $$
declare
  uid uuid := (select auth.uid());
  is_admin boolean;
begin
  if uid is null then
    return new;
  end if;

  select exists (
    select 1
    from core.comet_staff cs
    where cs.auth_user_id = uid
      and cs.role = 'admin'
  ) into is_admin;

  if not is_admin then
    if new.role is distinct from old.role then
      raise exception 'Only admins can change staff role';
    end if;

    if new.auth_user_id is distinct from old.auth_user_id then
      raise exception 'Only admins can change auth_user_id';
    end if;
  end if;

  return new;
end;
$$;

ALTER FUNCTION core.prevent_staff_escalation() OWNER TO postgres;

-- Auto-assign default theme on site creation
CREATE OR REPLACE FUNCTION core.set_default_theme_for_site()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $$
begin
  if new.theme_id is null then
    select id
    into new.theme_id
    from site.themes
    order by is_default desc, created_at
    limit 1;

    if new.theme_id is null then
      raise exception 'Cannot create site: no themes exist in site.themes';
    end if;
  end if;

  return new;
end;
$$;

ALTER FUNCTION core.set_default_theme_for_site() OWNER TO postgres;

-- Enforce max 6 gallery media per site
CREATE OR REPLACE FUNCTION core.enforce_site_gallery_max6()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $$
declare
  cnt int;
begin
  if new.deleted_at is not null then
    return new;
  end if;

  if tg_op = 'UPDATE' then
    if old.site_id = new.site_id and old.deleted_at is null and new.deleted_at is null then
      return new;
    end if;
  end if;

  select count(*)
    into cnt
  from site.site_media si
  where si.site_id = new.site_id
    and si.deleted_at is null
    and (tg_op <> 'UPDATE' or si.id <> new.id);

  if cnt >= 6 then
    raise exception 'Gallery limit reached: max 6 images per site';
  end if;

  return new;
end;
$$;

ALTER FUNCTION core.enforce_site_gallery_max6() OWNER TO postgres;

-- Validate brand team membership
CREATE OR REPLACE FUNCTION core.validate_brand_team_membership()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    brand_type text;
BEGIN
    SELECT p.professional_type
      INTO brand_type
      FROM core.professionals p
     WHERE p.id = NEW.brand_professional_id
       AND p.deleted_at IS NULL;

    IF brand_type IS DISTINCT FROM 'brand' THEN
        RAISE EXCEPTION 'brand_team_memberships.brand_professional_id must reference professional_type = brand'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

-- ==========================================================================
-- 3. CORE TABLES
-- ==========================================================================

-- core.professionals
CREATE TABLE IF NOT EXISTS core.professionals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    auth_user_id uuid NOT NULL,
    handle text NOT NULL,
    display_name text NOT NULL,
    bio text,
    country_code text,
    timezone text,
    status text DEFAULT 'active' NOT NULL,
    onboarding_step integer DEFAULT 0 NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    phone text NOT NULL,
    primary_email text NOT NULL,
    first_name text NOT NULL,
    last_name text,
    public_contact_number text,
    public_contact_email text,
    icon_bucket text DEFAULT 'public-assets',
    icon_path text,
    headshot_bucket text DEFAULT 'public-assets',
    headshot_path text,
    location_street_address text,
    location_postcode text,
    location_city text,
    location_state text,
    location_country text,
    handle_lc text NOT NULL,
    qr_slug text NOT NULL,
    deleted_at timestamptz,
    professional_type text NOT NULL DEFAULT 'barber',
    stripe_connect_account_id text,
    stripe_connect_status text NOT NULL DEFAULT 'not_connected',
    stripe_customer_id text,
    stripe_payment_method_id text,
    stripe_commission_funding_mode text NOT NULL DEFAULT 'auto_charge',
    stripe_manual_balance_cents integer NOT NULL DEFAULT 0,
    stripe_manual_balance_currency text NOT NULL DEFAULT 'AUD',
    CONSTRAINT professionals_headshot_bucket_when_path CHECK ((headshot_path IS NULL) OR (headshot_bucket IS NOT NULL)),
    CONSTRAINT professionals_icon_bucket_when_path CHECK ((icon_path IS NULL) OR (icon_bucket IS NOT NULL)),
    CONSTRAINT professionals_professional_type_check CHECK (
        professional_type IN (
            'professional', 'influencer', 'barber', 'hairdresser',
            'ambassador', 'promoter', 'brand', 'barbershop', 'salon'
        )
    ),
    CONSTRAINT pro_stripe_connect_status_check CHECK (stripe_connect_status IN ('not_connected', 'onboarding', 'active', 'restricted')),
    CONSTRAINT pro_stripe_commission_funding_mode_check CHECK (stripe_commission_funding_mode IN ('auto_charge', 'manual_topup')),
    CONSTRAINT pro_stripe_manual_balance_non_negative_check CHECK (stripe_manual_balance_cents >= 0)
);

ALTER TABLE core.professionals OWNER TO postgres;

ALTER TABLE ONLY core.professionals
    ADD CONSTRAINT professionals_pkey PRIMARY KEY (id);

ALTER TABLE ONLY core.professionals
    ADD CONSTRAINT professionals_auth_user_id_fkey FOREIGN KEY (auth_user_id) REFERENCES auth.users(id) ON DELETE CASCADE;

CREATE UNIQUE INDEX professionals_auth_user_id_unique ON core.professionals (auth_user_id) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX core_professionals_handle_lc_unique ON core.professionals (handle_lc) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX professionals_email_unique ON core.professionals (primary_email) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX professionals_public_contact_email_unique ON core.professionals (public_contact_email) WHERE (public_contact_email IS NOT NULL);
CREATE UNIQUE INDEX professionals_public_contact_number_unique ON core.professionals (public_contact_number) WHERE (public_contact_number IS NOT NULL);
CREATE UNIQUE INDEX professionals_qr_slug_unique ON core.professionals (qr_slug) WHERE (deleted_at IS NULL AND qr_slug IS NOT NULL);
CREATE INDEX professionals_deleted_at_idx ON core.professionals (deleted_at) WHERE (deleted_at IS NULL);
CREATE INDEX professionals_email_search_idx ON core.professionals (lower(primary_email));
CREATE INDEX idx_professionals_stripe_connect_account ON core.professionals (stripe_connect_account_id) WHERE stripe_connect_account_id IS NOT NULL;
CREATE INDEX idx_professionals_stripe_customer ON core.professionals (stripe_customer_id) WHERE stripe_customer_id IS NOT NULL;

-- core.comet_staff
CREATE TABLE IF NOT EXISTS core.comet_staff (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    auth_user_id uuid NOT NULL,
    role text DEFAULT 'support' NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    primary_email text,
    name text,
    phone text
);

ALTER TABLE ONLY core.comet_staff FORCE ROW LEVEL SECURITY;
ALTER TABLE core.comet_staff OWNER TO postgres;

ALTER TABLE ONLY core.comet_staff
    ADD CONSTRAINT comet_staff_pkey PRIMARY KEY (id);

ALTER TABLE ONLY core.comet_staff
    ADD CONSTRAINT "comet_staff_Primary Email_key" UNIQUE (primary_email);

ALTER TABLE ONLY core.comet_staff
    ADD CONSTRAINT comet_staff_auth_user_id_key UNIQUE (auth_user_id);

ALTER TABLE ONLY core.comet_staff
    ADD CONSTRAINT comet_staff_auth_user_id_fkey FOREIGN KEY (auth_user_id) REFERENCES auth.users(id) ON DELETE CASCADE;

-- core.waitlist_signups
CREATE TABLE IF NOT EXISTS core.waitlist_signups (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name text NOT NULL,
    email text NOT NULL,
    email_lc text NOT NULL,
    phone text NOT NULL,
    applicant_type text NOT NULL,
    applicant_type_other text NULL,
    industry text NOT NULL,
    industry_other text NULL,
    pilot_program_opt_in boolean NOT NULL DEFAULT false,
    number_of_team_members integer NULL,
    number_of_affiliates_ambassadors integer NULL,
    is_brand_partner_or_ambassador boolean NULL,
    currently_sells_products boolean NULL,
    consent_source text NOT NULL DEFAULT 'waitlist_form',
    consent_ip_hash text NULL,
    consent_user_agent text NULL,
    last_submitted_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT waitlist_signups_type_check CHECK (applicant_type IN ('influencer', 'professional', 'brand', 'other')),
    CONSTRAINT waitlist_signups_industry_check CHECK (
        industry IN ('mens_grooming', 'womens_haircare', 'beauty_products', 'vitamins_and_supplements', 'services_and_software', 'other')
    ),
    CONSTRAINT waitlist_signups_team_members_non_negative CHECK (number_of_team_members IS NULL OR number_of_team_members >= 0),
    CONSTRAINT waitlist_signups_affiliates_non_negative CHECK (number_of_affiliates_ambassadors IS NULL OR number_of_affiliates_ambassadors >= 0),
    CONSTRAINT waitlist_signups_type_other_required CHECK (
        (applicant_type = 'other' AND applicant_type_other IS NOT NULL AND btrim(applicant_type_other) <> '')
        OR (applicant_type <> 'other' AND applicant_type_other IS NULL)
    ),
    CONSTRAINT waitlist_signups_industry_other_required CHECK (
        (industry = 'other' AND industry_other IS NOT NULL AND btrim(industry_other) <> '')
        OR (industry <> 'other' AND industry_other IS NULL)
    ),
    CONSTRAINT waitlist_signups_conditional_fields_check CHECK (
        (applicant_type = 'brand' AND number_of_team_members IS NOT NULL AND number_of_affiliates_ambassadors IS NOT NULL AND is_brand_partner_or_ambassador IS NULL AND currently_sells_products IS NULL)
        OR (applicant_type IN ('influencer', 'professional') AND number_of_team_members IS NULL AND number_of_affiliates_ambassadors IS NULL AND is_brand_partner_or_ambassador IS NOT NULL AND currently_sells_products IS NOT NULL)
        OR (applicant_type = 'other' AND number_of_team_members IS NULL AND number_of_affiliates_ambassadors IS NULL AND is_brand_partner_or_ambassador IS NULL AND currently_sells_products IS NULL)
    )
);

ALTER TABLE core.waitlist_signups OWNER TO postgres;

CREATE UNIQUE INDEX waitlist_signups_email_lc_unique ON core.waitlist_signups (email_lc);
CREATE INDEX waitlist_signups_last_submitted_idx ON core.waitlist_signups (last_submitted_at DESC);
CREATE INDEX waitlist_signups_type_idx ON core.waitlist_signups (applicant_type);
CREATE INDEX waitlist_signups_industry_idx ON core.waitlist_signups (industry);
CREATE INDEX waitlist_signups_pilot_opt_in_idx ON core.waitlist_signups (pilot_program_opt_in);

-- core.professional_integrations
CREATE TABLE IF NOT EXISTS core.professional_integrations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    provider varchar(64) COLLATE "C" NOT NULL,
    external_account_id varchar(255) COLLATE "C" NULL,
    access_token text COLLATE "C" NULL,
    refresh_token text COLLATE "C" NULL,
    expires_at timestamptz NULL,
    catalog_latest_time timestamptz NULL,
    last_catalog_sync_at timestamptz NULL,
    last_catalog_sync_error text COLLATE "C" NULL,
    provider_metadata jsonb NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    shopify_shop_domain text GENERATED ALWAYS AS (
        CASE
            WHEN provider = 'shopify'
            THEN lower(trim(provider_metadata ->> 'shop_domain'))
            ELSE NULL
        END
    ) STORED
);

CREATE UNIQUE INDEX professional_integrations_professional_provider_uq
    ON core.professional_integrations(professional_id, provider);
CREATE INDEX professional_integrations_provider_account_idx
    ON core.professional_integrations(provider, external_account_id);
CREATE INDEX professional_integrations_professional_idx
    ON core.professional_integrations(professional_id);
CREATE UNIQUE INDEX professional_integrations_shopify_domain_uq
    ON core.professional_integrations (shopify_shop_domain)
    WHERE shopify_shop_domain IS NOT NULL;

-- core.professional_legal_contents
CREATE TABLE IF NOT EXISTS core.professional_legal_contents (
    professional_id uuid PRIMARY KEY REFERENCES core.professionals(id) ON DELETE CASCADE,
    generated_privacy_policy text NOT NULL DEFAULT '',
    manual_privacy_policy text NULL,
    active_privacy_source varchar(16) COLLATE "C" NOT NULL DEFAULT 'templated',
    generated_terms_and_conditions text NOT NULL DEFAULT '',
    manual_terms_and_conditions text NULL,
    active_terms_source varchar(16) COLLATE "C" NOT NULL DEFAULT 'templated',
    template_variables jsonb NOT NULL DEFAULT '{}'::jsonb,
    generated_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT professional_legal_contents_active_privacy_source_chk CHECK (active_privacy_source IN ('templated', 'manual')),
    CONSTRAINT professional_legal_contents_active_terms_source_chk CHECK (active_terms_source IN ('templated', 'manual'))
);

CREATE INDEX professional_legal_contents_generated_at_idx
    ON core.professional_legal_contents (generated_at);

-- core.professional_confirmation_preferences
CREATE TABLE IF NOT EXISTS core.professional_confirmation_preferences (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    action_key text NOT NULL,
    skip_confirmation boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT professional_confirmation_preferences_professional_action_uq UNIQUE (professional_id, action_key)
);

CREATE INDEX professional_confirmation_preferences_professional_idx
    ON core.professional_confirmation_preferences (professional_id);

-- core.customers
CREATE TABLE IF NOT EXISTS core.customers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    email text,
    phone text,
    full_name text,
    source text,
    notes text,
    external_id text,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    deleted_at timestamptz,
    marketing_opt_in_cached boolean DEFAULT true
);

ALTER TABLE core.customers OWNER TO postgres;

ALTER TABLE ONLY core.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);

ALTER TABLE ONLY core.customers
    ADD CONSTRAINT customers_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

CREATE UNIQUE INDEX customers_professional_email_unique ON core.customers (professional_id, lower(email)) WHERE (email IS NOT NULL);
CREATE UNIQUE INDEX customers_professional_phone_unique ON core.customers (professional_id, phone) WHERE (phone IS NOT NULL);
CREATE INDEX customers_professional_deleted_at_idx ON core.customers (professional_id, deleted_at);
CREATE INDEX customers_professional_email_search_idx ON core.customers (professional_id, lower(email)) WHERE (email IS NOT NULL AND deleted_at IS NULL);
CREATE INDEX customers_professional_id_idx ON core.customers (professional_id);
CREATE INDEX customers_professional_name_search_idx ON core.customers (professional_id, lower(full_name)) WHERE (full_name IS NOT NULL AND deleted_at IS NULL);
CREATE INDEX customers_professional_phone_search_idx ON core.customers (professional_id, phone) WHERE (phone IS NOT NULL AND deleted_at IS NULL);
CREATE INDEX customers_marketing_opt_in_cached_idx ON core.customers (professional_id, marketing_opt_in_cached) WHERE (marketing_opt_in_cached IS NOT NULL);

-- ==========================================================================
-- 4. SITE TABLES
-- ==========================================================================

-- site.themes
CREATE TABLE IF NOT EXISTS site.themes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    key text NOT NULL,
    name text NOT NULL,
    description text,
    config jsonb DEFAULT '{}'::jsonb NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL
);

ALTER TABLE site.themes OWNER TO postgres;

ALTER TABLE ONLY site.themes
    ADD CONSTRAINT themes_pkey PRIMARY KEY (id);

ALTER TABLE ONLY site.themes
    ADD CONSTRAINT themes_key_key UNIQUE (key);

CREATE UNIQUE INDEX themes_single_default ON site.themes (is_default) WHERE (is_default = true);

-- site.sites
CREATE TABLE IF NOT EXISTS site.sites (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    subdomain text NOT NULL,
    theme_id uuid,
    is_published boolean DEFAULT false NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    subdomain_changed_at timestamptz,
    banner_bucket text DEFAULT 'public-assets',
    banner_path text,
    CONSTRAINT sites_banner_bucket_when_path CHECK ((banner_path IS NULL) OR (banner_bucket IS NOT NULL))
);

ALTER TABLE site.sites OWNER TO postgres;

ALTER TABLE ONLY site.sites
    ADD CONSTRAINT sites_pkey PRIMARY KEY (id);

ALTER TABLE ONLY site.sites
    ADD CONSTRAINT sites_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

ALTER TABLE ONLY site.sites
    ADD CONSTRAINT sites_theme_fk FOREIGN KEY (theme_id) REFERENCES site.themes(id) ON DELETE SET NULL;

CREATE UNIQUE INDEX sites_professional_unique ON site.sites (professional_id);
CREATE UNIQUE INDEX core_sites_subdomain_lower_unique ON site.sites (lower(subdomain));

-- site.blocks
CREATE TABLE IF NOT EXISTS site.blocks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    block_type text DEFAULT 'link' NOT NULL,
    title text,
    url text,
    icon_key text,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    block_group text DEFAULT 'links' NOT NULL,
    deleted_at timestamptz,
    is_enabled boolean NOT NULL DEFAULT false,
    CONSTRAINT link_blocks_block_group_check CHECK (block_group = ANY (ARRAY['links', 'sections']))
);

ALTER TABLE site.blocks OWNER TO postgres;

ALTER TABLE ONLY site.blocks
    ADD CONSTRAINT link_blocks_pkey PRIMARY KEY (id);

ALTER TABLE ONLY site.blocks
    ADD CONSTRAINT blocks_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

ALTER TABLE ONLY site.blocks
    ADD CONSTRAINT blocks_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE;

CREATE UNIQUE INDEX blocks_links_site_group_sort_uq ON site.blocks (site_id, block_group, sort_order) WHERE (block_group = 'links');
CREATE UNIQUE INDEX blocks_sections_site_group_sort_uq ON site.blocks (site_id, block_group, sort_order) WHERE (block_group = 'sections');
CREATE UNIQUE INDEX blocks_sections_site_group_type_uq ON site.blocks (site_id, block_group, block_type) WHERE (block_group = 'sections');
CREATE INDEX blocks_site_group_active_idx ON site.blocks (site_id, block_group, sort_order) WHERE (deleted_at IS NULL AND is_active = true);
CREATE INDEX core_link_blocks_professional_sort_idx ON site.blocks (professional_id, sort_order);
CREATE INDEX link_blocks_pro_group_sort_idx ON site.blocks (professional_id, block_group, sort_order);
CREATE INDEX link_blocks_professional_id_idx ON site.blocks (professional_id);
CREATE INDEX link_blocks_site_group_sort_idx ON site.blocks (site_id, block_group, sort_order);
CREATE INDEX link_blocks_site_id_idx ON site.blocks (site_id, sort_order);
CREATE INDEX blocks_site_type_active_idx ON site.blocks (site_id, block_type, sort_order) WHERE deleted_at IS NULL AND is_active = true;

-- site.site_media (was core.site_images, renamed to site_media)
CREATE TABLE IF NOT EXISTS site.site_media (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    site_id uuid NOT NULL,
    bucket text DEFAULT 'public-assets' NOT NULL,
    path text NOT NULL,
    alt_text text,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    deleted_at timestamptz,
    is_active boolean DEFAULT true NOT NULL,
    pool varchar(20) NOT NULL DEFAULT 'gallery',
    media_type varchar(10) NOT NULL DEFAULT 'image',
    processing_state varchar(20) NOT NULL DEFAULT 'pending',
    original_mime varchar(100),
    original_size_bytes bigint,
    duration_ms integer,
    poster_path text,
    processing_error text,
    CONSTRAINT site_media_media_type_check CHECK (media_type IN ('image', 'video')),
    CONSTRAINT site_media_processing_state_check CHECK (processing_state IN ('pending', 'processing', 'ready', 'failed'))
);

ALTER TABLE site.site_media OWNER TO postgres;

ALTER TABLE ONLY site.site_media
    ADD CONSTRAINT site_media_pkey PRIMARY KEY (id);

ALTER TABLE ONLY site.site_media
    ADD CONSTRAINT site_media_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE;

CREATE INDEX sm_pool_active ON site.site_media(site_id, pool, is_active);
CREATE INDEX sm_pool_media_active ON site.site_media(site_id, pool, media_type, sort_order) WHERE deleted_at IS NULL AND is_active = true;
CREATE INDEX site_images_site_active_idx ON site.site_media (site_id) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX site_images_site_sort_active_unique ON site.site_media (site_id, sort_order) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX site_images_site_sort_order_active_uq ON site.site_media (site_id, sort_order) WHERE (deleted_at IS NULL AND is_active = true);
CREATE INDEX site_images_site_sort_idx ON site.site_media (site_id, sort_order);
CREATE INDEX site_media_site_active_sort_covering_idx ON site.site_media (site_id, sort_order) INCLUDE (alt_text, media_type, pool) WHERE deleted_at IS NULL AND is_active = true;

-- site.media_variants
CREATE TABLE IF NOT EXISTS site.media_variants (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    media_id uuid NOT NULL REFERENCES site.site_media(id) ON DELETE CASCADE,
    variant_key varchar(40) NOT NULL,
    artifact_type varchar(20) NOT NULL,
    disk varchar(40) NOT NULL DEFAULT 'media',
    path text NOT NULL,
    mime varchar(100),
    width integer,
    height integer,
    bitrate_kbps integer,
    file_size_bytes bigint,
    duration_ms integer,
    metadata jsonb,
    content_hash varchar(16),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE site.media_variants OWNER TO postgres;

CREATE UNIQUE INDEX mv_media_variant_artifact ON site.media_variants(media_id, variant_key, artifact_type);
CREATE INDEX mv_media_id ON site.media_variants(media_id);
CREATE INDEX mv_media_artifact_type ON site.media_variants(media_id, artifact_type);

-- site.site_subdomain_aliases
CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
    id uuid NOT NULL,
    site_id uuid NOT NULL,
    subdomain varchar(63) NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL
);

ALTER TABLE site.site_subdomain_aliases OWNER TO postgres;

ALTER TABLE ONLY site.site_subdomain_aliases
    ADD CONSTRAINT site_subdomain_aliases_pkey PRIMARY KEY (id);

ALTER TABLE ONLY site.site_subdomain_aliases
    ADD CONSTRAINT site_subdomain_aliases_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE;

CREATE INDEX core_site_subdomain_aliases_site_id_idx ON site.site_subdomain_aliases (site_id);
CREATE UNIQUE INDEX core_site_subdomain_aliases_subdomain_lower_unique ON site.site_subdomain_aliases (lower(subdomain::text));

-- site.services
CREATE TABLE IF NOT EXISTS site.services (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    title text NOT NULL,
    description text,
    category text,
    price_cents integer NOT NULL,
    currency_code char(3) DEFAULT 'AUD' NOT NULL,
    duration_minutes integer,
    is_active boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    deleted_at timestamptz,
    category_id uuid NULL,
    square_catalog_object_id varchar(255) COLLATE "C" NULL,
    square_variation_id varchar(255) COLLATE "C" NULL,
    square_catalog_version bigint NULL,
    square_last_synced_at timestamptz NULL,
    square_sync_error text COLLATE "C" NULL,
    fresha_service_id varchar(255) COLLATE "C" NULL,
    fresha_variation_id varchar(255) COLLATE "C" NULL,
    fresha_service_version bigint NULL,
    fresha_last_synced_at timestamptz NULL,
    fresha_sync_error text COLLATE "C" NULL,
    CONSTRAINT services_duration_minutes_check CHECK ((duration_minutes IS NULL) OR (duration_minutes > 0)),
    CONSTRAINT services_price_cents_check CHECK (price_cents >= 0)
);

ALTER TABLE site.services OWNER TO postgres;

ALTER TABLE ONLY site.services
    ADD CONSTRAINT services_pkey PRIMARY KEY (id);

ALTER TABLE ONLY site.services
    ADD CONSTRAINT services_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

CREATE INDEX services_active_order_idx ON site.services (professional_id, sort_order) WHERE (deleted_at IS NULL);
CREATE INDEX services_pro_active_sort_covering_idx ON site.services (professional_id, sort_order) INCLUDE (title, price_cents, is_active) WHERE (deleted_at IS NULL AND is_active = true);
CREATE INDEX services_prof_sort_idx ON site.services (professional_id, sort_order, created_at);
CREATE INDEX services_professional_id_deleted_at_idx ON site.services (professional_id, deleted_at);
CREATE UNIQUE INDEX services_professional_sort_order_uq ON site.services (professional_id, sort_order) WHERE (deleted_at IS NULL);
CREATE INDEX services_square_catalog_object_id_idx ON site.services(square_catalog_object_id);
CREATE INDEX services_square_variation_id_idx ON site.services(square_variation_id);
CREATE INDEX services_fresha_service_id_idx ON site.services(fresha_service_id);
CREATE INDEX services_fresha_variation_id_idx ON site.services(fresha_variation_id);

ALTER TABLE site.services
    ADD CONSTRAINT services_professional_square_variation_uq UNIQUE (professional_id, square_variation_id);

ALTER TABLE site.services
    ADD CONSTRAINT services_professional_fresha_variation_uq UNIQUE (professional_id, fresha_variation_id);

CREATE INDEX services_professional_category_sort_idx ON site.services (professional_id, category_id, sort_order);

-- site.service_categories
CREATE TABLE IF NOT EXISTS site.service_categories (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    title text NOT NULL,
    sort_order integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz NULL
);

ALTER TABLE site.service_categories
    ADD CONSTRAINT service_categories_id_professional_unique UNIQUE (id, professional_id);

CREATE UNIQUE INDEX service_categories_unique_title_per_professional
    ON site.service_categories (professional_id, lower(title)) WHERE deleted_at IS NULL;

ALTER TABLE site.service_categories
    ADD CONSTRAINT service_categories_sort_order_non_negative CHECK (sort_order >= 0);

CREATE INDEX service_categories_professional_sort_idx
    ON site.service_categories (professional_id, sort_order);

-- FK from services.category_id to service_categories (composite)
ALTER TABLE site.services
    ADD CONSTRAINT services_category_belongs_to_professional
    FOREIGN KEY (category_id, professional_id)
    REFERENCES site.service_categories(id, professional_id)
    ON DELETE SET NULL;

-- ==========================================================================
-- 5. BRAND TABLES
-- ==========================================================================

-- brand.brand_profiles
CREATE TABLE IF NOT EXISTS brand.brand_profiles (
    id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    professional_id uuid NOT NULL UNIQUE REFERENCES core.professionals(id) ON DELETE CASCADE,
    abn text,
    acn text,
    legal_business_name text,
    business_type text,
    industries jsonb DEFAULT '[]'::jsonb NOT NULL,
    estimated_annual_income text,
    business_website text,
    affiliate_visibility text NOT NULL DEFAULT 'invite_only',
    brand_status text NOT NULL DEFAULT 'deactivated',
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    CONSTRAINT chk_brand_profiles_affiliate_visibility CHECK (affiliate_visibility IN ('public', 'invite_only')),
    CONSTRAINT chk_brand_profiles_brand_status CHECK (brand_status IN ('active', 'deactivated'))
);

CREATE INDEX idx_brand_profiles_professional_id ON brand.brand_profiles(professional_id);

-- brand.brand_partner_links
CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    slot smallint NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT brand_partner_links_slot_check CHECK (slot BETWEEN 0 AND 3),
    CONSTRAINT brand_partner_links_not_self_check CHECK (affiliate_professional_id <> brand_professional_id)
);

CREATE UNIQUE INDEX brand_partner_links_affiliate_brand_uq ON brand.brand_partner_links (affiliate_professional_id, brand_professional_id);
CREATE UNIQUE INDEX brand_partner_links_affiliate_slot_uq ON brand.brand_partner_links (affiliate_professional_id, slot);
CREATE INDEX brand_partner_links_brand_idx ON brand.brand_partner_links (brand_professional_id);
CREATE INDEX brand_partner_links_affiliate_idx ON brand.brand_partner_links (affiliate_professional_id);

-- brand.brand_affiliate_invites
CREATE TABLE IF NOT EXISTS brand.brand_affiliate_invites (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    token varchar(80) NOT NULL,
    status varchar(24) NOT NULL DEFAULT 'pending',
    invite_type varchar(24) NOT NULL DEFAULT 'generic',
    email varchar(255) NULL,
    email_lc varchar(255) NULL,
    phone varchar(40) NULL,
    first_name varchar(80) NULL,
    last_name varchar(80) NULL,
    message text NULL,
    claimed_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    accepted_at timestamptz NULL,
    expires_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT brand_affiliate_invites_status_check CHECK (status IN ('pending', 'accepted', 'declined', 'expired')),
    CONSTRAINT brand_affiliate_invites_invite_type_check CHECK (invite_type IN ('generic', 'personalised'))
);

CREATE UNIQUE INDEX brand_affiliate_invites_token_uq ON brand.brand_affiliate_invites (token);
CREATE INDEX brand_affiliate_invites_brand_status_idx ON brand.brand_affiliate_invites (brand_professional_id, status);
CREATE INDEX brand_affiliate_invites_email_lc_idx ON brand.brand_affiliate_invites (email_lc);
CREATE UNIQUE INDEX brand_affiliate_invites_pending_brand_email_uq
    ON brand.brand_affiliate_invites (brand_professional_id, email_lc)
    WHERE status = 'pending' AND email_lc IS NOT NULL;

-- brand.brand_store_settings (V2 trimmed: only keep id, professional_id, default_commission_rate, payout_hold_days, created_at, updated_at)
CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    default_commission_rate numeric(5,2) NOT NULL DEFAULT 15
        CONSTRAINT bss_commission_range CHECK (default_commission_rate >= 0 AND default_commission_rate <= 100),
    payout_hold_days integer DEFAULT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (professional_id)
);

-- brand.brand_team_memberships
CREATE TABLE IF NOT EXISTS brand.brand_team_memberships (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    member_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    role text NOT NULL DEFAULT 'read_only',
    status text NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT brand_team_memberships_role_check CHECK (role IN ('owner', 'finance', 'marketing', 'analyst', 'read_only')),
    CONSTRAINT brand_team_memberships_status_check CHECK (status IN ('active', 'inactive'))
);

ALTER TABLE brand.brand_team_memberships OWNER TO postgres;

CREATE INDEX btm_brand_status_role_idx ON brand.brand_team_memberships (brand_professional_id, status, role);
CREATE INDEX btm_member_status_idx ON brand.brand_team_memberships (member_professional_id, status);
CREATE UNIQUE INDEX btm_active_brand_member_uq ON brand.brand_team_memberships (brand_professional_id, member_professional_id) WHERE status = 'active';
CREATE UNIQUE INDEX btm_single_active_owner_uq ON brand.brand_team_memberships (brand_professional_id) WHERE status = 'active' AND role = 'owner';

-- ==========================================================================
-- 6. COMMERCE TABLES
-- ==========================================================================

-- commerce.affiliate_product_selections (NEW TABLE)
CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    shopify_product_gid text NOT NULL,
    sort_order integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (affiliate_professional_id, shopify_product_gid)
);

CREATE INDEX affiliate_product_selections_affiliate_idx ON commerce.affiliate_product_selections (affiliate_professional_id);

-- commerce.commission_ledger_entries
-- V2: Shopify is source of truth for orders; ledger entries reference shopify_order_id as a plain text correlation field.
CREATE TABLE IF NOT EXISTS commerce.commission_ledger_entries (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    shopify_order_id text NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    entry_type text NOT NULL,
    status text NOT NULL DEFAULT 'pending',
    amount_cents integer NOT NULL,
    currency_code char(3) NOT NULL DEFAULT 'AUD',
    commission_rate numeric(7,4) NOT NULL,
    rate_source text NOT NULL,
    idempotency_key text NOT NULL,
    calculation_metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    payout_id uuid NULL,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT commission_ledger_entry_type_check CHECK (entry_type IN ('accrual', 'reversal', 'payout')),
    CONSTRAINT commission_ledger_status_check CHECK (status IN ('pending', 'approved', 'paid', 'reversed', 'disputed')),
    CONSTRAINT commission_ledger_rate_range_check CHECK (commission_rate >= 0 AND commission_rate <= 100),
    CONSTRAINT commission_ledger_rate_source_not_blank CHECK (btrim(rate_source) <> ''),
    CONSTRAINT commission_ledger_idempotency_not_blank CHECK (btrim(idempotency_key) <> ''),
    CONSTRAINT commission_ledger_not_self_brand_check CHECK (affiliate_professional_id <> brand_professional_id)
);

CREATE UNIQUE INDEX commission_ledger_entries_idempotency_uq ON commerce.commission_ledger_entries (idempotency_key);
CREATE INDEX commission_ledger_entries_brand_status_idx ON commerce.commission_ledger_entries (brand_professional_id, status, occurred_at DESC);
CREATE INDEX commission_ledger_entries_affiliate_status_idx ON commerce.commission_ledger_entries (affiliate_professional_id, status, occurred_at DESC);
CREATE INDEX commission_ledger_entries_shopify_order_idx ON commerce.commission_ledger_entries (shopify_order_id) WHERE shopify_order_id IS NOT NULL;
CREATE INDEX idx_cle_unpaid ON commerce.commission_ledger_entries (brand_professional_id, affiliate_professional_id, currency_code)
    WHERE payout_id IS NULL AND entry_type = 'accrual' AND status = 'approved';
CREATE INDEX idx_cle_payout ON commerce.commission_ledger_entries (payout_id) WHERE payout_id IS NOT NULL;
CREATE INDEX idx_cle_unpaid_reversals ON commerce.commission_ledger_entries (brand_professional_id, affiliate_professional_id, currency_code)
    WHERE payout_id IS NULL AND entry_type = 'reversal' AND status = 'approved';

-- commerce.commission_payouts
CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    stripe_payment_intent_id text,
    stripe_transfer_id text,
    status text NOT NULL DEFAULT 'pending'
        CONSTRAINT cp_status_check CHECK (status IN ('pending', 'pending_funds', 'collecting', 'collected', 'transferring', 'completed', 'failed', 'cancelled')),
    gross_commission_cents integer NOT NULL,
    platform_fee_cents integer NOT NULL DEFAULT 0,
    net_payout_cents integer NOT NULL,
    currency_code text NOT NULL DEFAULT 'AUD',
    failure_reason text,
    failure_code text,
    ledger_entry_count integer NOT NULL DEFAULT 0,
    funding_source text,
    wallet_debit_cents integer NOT NULL DEFAULT 0,
    charge_cents integer NOT NULL DEFAULT 0,
    eligible_after timestamptz NOT NULL,
    processed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT cp_different_parties CHECK (brand_professional_id <> affiliate_professional_id),
    CONSTRAINT cp_amounts_positive CHECK (gross_commission_cents > 0 AND net_payout_cents >= 0 AND platform_fee_cents >= 0)
);

ALTER TABLE commerce.commission_payouts OWNER TO postgres;

CREATE INDEX idx_cp_brand ON commerce.commission_payouts (brand_professional_id);
CREATE INDEX idx_cp_affiliate ON commerce.commission_payouts (affiliate_professional_id);
CREATE INDEX idx_cp_status_eligible ON commerce.commission_payouts (status, eligible_after) WHERE status = 'pending';
CREATE INDEX idx_cp_pending_eligible ON commerce.commission_payouts (eligible_after) WHERE status = 'pending' AND processed_at IS NULL;

-- Add FK from commission_ledger_entries.payout_id to commission_payouts
ALTER TABLE commerce.commission_ledger_entries
    ADD CONSTRAINT commission_ledger_entries_payout_id_fkey
    FOREIGN KEY (payout_id) REFERENCES commerce.commission_payouts(id) ON DELETE SET NULL;

-- commerce.commission_payout_items
CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    payout_id uuid NOT NULL REFERENCES commerce.commission_payouts(id) ON DELETE CASCADE,
    commission_ledger_entry_id uuid NOT NULL REFERENCES commerce.commission_ledger_entries(id) ON DELETE RESTRICT,
    amount_cents integer NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT cpi_unique_entry UNIQUE (commission_ledger_entry_id)
);

ALTER TABLE commerce.commission_payout_items OWNER TO postgres;

CREATE INDEX idx_cpi_payout ON commerce.commission_payout_items (payout_id);

-- commerce.brand_commission_topups
CREATE TABLE IF NOT EXISTS commerce.brand_commission_topups (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    stripe_checkout_session_id text NOT NULL,
    stripe_payment_intent_id text,
    amount_cents integer NOT NULL,
    currency_code text NOT NULL DEFAULT 'AUD',
    status text NOT NULL DEFAULT 'pending'
        CONSTRAINT bct_status_check CHECK (status IN ('pending', 'completed', 'failed')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT bct_amount_positive CHECK (amount_cents > 0),
    CONSTRAINT bct_unique_session UNIQUE (stripe_checkout_session_id)
);

ALTER TABLE commerce.brand_commission_topups OWNER TO postgres;

CREATE INDEX idx_bct_brand ON commerce.brand_commission_topups (brand_professional_id);

-- ==========================================================================
-- 7. NOTIFICATIONS TABLES
-- ==========================================================================

-- notifications.notifications
CREATE TABLE IF NOT EXISTS notifications.notifications (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid,
    type text NOT NULL,
    title text NOT NULL,
    body text NOT NULL,
    cta_url text,
    severity text DEFAULT 'info' NOT NULL,
    starts_at timestamptz,
    ends_at timestamptz,
    primary_action_label varchar(255) NULL,
    secondary_action_label varchar(255) NULL,
    secondary_action_url text NULL,
    category text NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    CONSTRAINT notifications_severity_check CHECK (severity = ANY (ARRAY['info', 'warning', 'critical']))
);

ALTER TABLE notifications.notifications OWNER TO postgres;

ALTER TABLE ONLY notifications.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);

ALTER TABLE ONLY notifications.notifications
    ADD CONSTRAINT notifications_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

CREATE INDEX notifications_broadcast_active_idx ON notifications.notifications (created_at DESC) WHERE (professional_id IS NULL);
CREATE INDEX notifications_pro_active_idx ON notifications.notifications (professional_id, created_at DESC) WHERE (professional_id IS NOT NULL);

-- notifications.notification_receipts
CREATE TABLE IF NOT EXISTS notifications.notification_receipts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    notification_id uuid NOT NULL,
    professional_id uuid NOT NULL,
    read_at timestamptz,
    dismissed_at timestamptz,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL
);

ALTER TABLE notifications.notification_receipts OWNER TO postgres;

ALTER TABLE ONLY notifications.notification_receipts
    ADD CONSTRAINT notification_receipts_pkey PRIMARY KEY (id);

ALTER TABLE ONLY notifications.notification_receipts
    ADD CONSTRAINT notification_receipts_notification_id_fkey FOREIGN KEY (notification_id) REFERENCES notifications.notifications(id) ON DELETE CASCADE;

ALTER TABLE ONLY notifications.notification_receipts
    ADD CONSTRAINT notification_receipts_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

CREATE UNIQUE INDEX notification_receipts_notification_professional_uq ON notifications.notification_receipts (notification_id, professional_id);
CREATE INDEX receipts_pro_idx ON notifications.notification_receipts (professional_id, updated_at DESC);
CREATE INDEX receipts_unread_idx ON notifications.notification_receipts (professional_id, notification_id) WHERE (read_at IS NULL AND dismissed_at IS NULL);

-- notifications.notification_email_preferences
CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    category_key text NOT NULL,
    enabled boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (professional_id, category_key)
);

-- notifications.notification_email_policies
CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid REFERENCES core.professionals(id) ON DELETE CASCADE,
    category_key text NOT NULL,
    mode text NOT NULL DEFAULT 'default' CHECK (mode IN ('default', 'force_on', 'force_off')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_notif_email_policies_global ON notifications.notification_email_policies (category_key) WHERE professional_id IS NULL;
CREATE UNIQUE INDEX uq_notif_email_policies_per_professional ON notifications.notification_email_policies (professional_id, category_key) WHERE professional_id IS NOT NULL;

-- notifications.email_subscriptions
CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid,
    list_key varchar(50) DEFAULT 'marketing' NOT NULL,
    email text NOT NULL,
    full_name text,
    status varchar(20) DEFAULT 'subscribed' NOT NULL,
    subscribed_at timestamptz,
    unsubscribed_at timestamptz,
    unsubscribe_token varchar(80) NOT NULL,
    consent_source varchar(50),
    consent_ip_hash text,
    consent_user_agent text,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    email_lc text NOT NULL,
    qr_slug text
);

ALTER TABLE notifications.email_subscriptions OWNER TO postgres;

ALTER TABLE ONLY notifications.email_subscriptions
    ADD CONSTRAINT email_subscriptions_pkey PRIMARY KEY (id);

ALTER TABLE ONLY notifications.email_subscriptions
    ADD CONSTRAINT email_subscriptions_qr_slug_key UNIQUE (qr_slug);

ALTER TABLE ONLY notifications.email_subscriptions
    ADD CONSTRAINT email_subscriptions_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

CREATE INDEX email_subs_global_list_status_idx ON notifications.email_subscriptions (list_key, status) WHERE (professional_id IS NULL);
CREATE INDEX email_subs_pro_list_status_idx ON notifications.email_subscriptions (professional_id, list_key, status) WHERE (professional_id IS NOT NULL);
CREATE UNIQUE INDEX email_subscriptions_unique_global_list_email_lc ON notifications.email_subscriptions (list_key, email_lc) WHERE (professional_id IS NULL);
CREATE UNIQUE INDEX email_subscriptions_unique_pro_list_email_lc ON notifications.email_subscriptions (professional_id, list_key, email_lc) WHERE (professional_id IS NOT NULL);
CREATE UNIQUE INDEX email_subscriptions_unsubscribe_token_unique ON notifications.email_subscriptions (unsubscribe_token);

-- ==========================================================================
-- 8. BILLING TABLES
-- ==========================================================================

-- billing.plans
CREATE TABLE IF NOT EXISTS billing.plans (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    plan_key text NOT NULL UNIQUE,
    name text NOT NULL,
    stripe_price_id text NOT NULL UNIQUE,
    is_active boolean NOT NULL DEFAULT true,
    sort_order integer NOT NULL DEFAULT 0,
    entitlements jsonb,
    description text,
    price_cents integer NOT NULL DEFAULT 0,
    currency_code text NOT NULL DEFAULT 'AUD',
    billing_interval text NOT NULL DEFAULT 'month',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- billing.subscriptions
CREATE TABLE IF NOT EXISTS billing.subscriptions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    plan_id uuid NOT NULL REFERENCES billing.plans(id) ON DELETE RESTRICT,
    provider text NOT NULL DEFAULT 'stripe',
    stripe_customer_id text,
    stripe_subscription_id text UNIQUE,
    status text NOT NULL,
    current_period_start timestamptz,
    current_period_end timestamptz,
    cancel_at_period_end boolean NOT NULL DEFAULT false,
    trial_ends_at timestamptz,
    ended_at timestamptz,
    provider_payload jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX billing_subscriptions_professional_id_idx ON billing.subscriptions (professional_id);
CREATE INDEX billing_subscriptions_status_idx ON billing.subscriptions (status);
CREATE UNIQUE INDEX billing_one_current_sub_per_professional ON billing.subscriptions (professional_id) WHERE ended_at IS NULL;
CREATE INDEX billing_subscriptions_trial_ends_idx ON billing.subscriptions (trial_ends_at) WHERE status IN ('trialing', 'active') AND trial_ends_at IS NOT NULL;
CREATE INDEX billing_subscriptions_cancel_period_end_idx ON billing.subscriptions (current_period_end) WHERE cancel_at_period_end = true AND ended_at IS NULL;
CREATE INDEX billing_subscriptions_plan_status_idx ON billing.subscriptions (plan_id, status) WHERE ended_at IS NULL;

-- ==========================================================================
-- 9. ANALYTICS TABLES
-- ==========================================================================

-- ---- Raw events ----

-- analytics.site_visits
CREATE TABLE IF NOT EXISTS analytics.site_visits (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    occurred_at timestamptz DEFAULT now() NOT NULL,
    session_id uuid,
    visitor_id uuid,
    ip_hash text,
    user_agent text,
    referrer text,
    utm_source text,
    utm_medium text,
    utm_campaign text,
    created_at timestamptz DEFAULT now() NOT NULL,
    country_code text,
    device_type text
);

ALTER TABLE analytics.site_visits OWNER TO postgres;

ALTER TABLE ONLY analytics.site_visits ADD CONSTRAINT site_visits_pkey PRIMARY KEY (id);
ALTER TABLE ONLY analytics.site_visits ADD CONSTRAINT site_visits_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;
ALTER TABLE ONLY analytics.site_visits ADD CONSTRAINT site_visits_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE;

CREATE INDEX analytics_site_visits_professional_occurred_idx ON analytics.site_visits (professional_id, occurred_at);
CREATE INDEX site_visits_professional_time_idx ON analytics.site_visits (professional_id, occurred_at);
CREATE INDEX site_visits_site_time_idx ON analytics.site_visits (site_id, occurred_at);
CREATE INDEX site_visits_pro_date_range_idx ON analytics.site_visits (professional_id, occurred_at DESC) INCLUDE (country_code, device_type);

-- analytics.link_clicks
CREATE TABLE IF NOT EXISTS analytics.link_clicks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    link_block_id uuid NOT NULL,
    occurred_at timestamptz DEFAULT now() NOT NULL,
    session_id uuid,
    visitor_id uuid,
    ip_hash text,
    user_agent text,
    referrer text,
    utm_source text,
    utm_medium text,
    utm_campaign text,
    created_at timestamptz DEFAULT now() NOT NULL
);

ALTER TABLE analytics.link_clicks OWNER TO postgres;

ALTER TABLE ONLY analytics.link_clicks ADD CONSTRAINT link_clicks_pkey PRIMARY KEY (id);
ALTER TABLE ONLY analytics.link_clicks ADD CONSTRAINT link_clicks_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;
ALTER TABLE ONLY analytics.link_clicks ADD CONSTRAINT link_clicks_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE;
ALTER TABLE ONLY analytics.link_clicks ADD CONSTRAINT link_clicks_block_fk FOREIGN KEY (link_block_id) REFERENCES site.blocks(id) ON DELETE CASCADE;

CREATE INDEX analytics_link_clicks_professional_occurred_idx ON analytics.link_clicks (professional_id, occurred_at);
CREATE INDEX link_clicks_link_time_idx ON analytics.link_clicks (link_block_id, occurred_at);
CREATE INDEX link_clicks_pro_date_range_idx ON analytics.link_clicks (professional_id, occurred_at DESC) INCLUDE (link_block_id);
CREATE INDEX link_clicks_professional_time_idx ON analytics.link_clicks (professional_id, occurred_at);
CREATE INDEX link_clicks_site_time_idx ON analytics.link_clicks (site_id, occurred_at);

-- analytics.lead_submissions
CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    occurred_at timestamptz DEFAULT now() NOT NULL,
    subdomain text,
    site_id uuid,
    professional_id uuid,
    customer_id uuid,
    ip_hash text,
    user_agent text,
    referrer text,
    outcome text NOT NULL,
    form_started_at_ms bigint
);

ALTER TABLE analytics.lead_submissions OWNER TO postgres;

ALTER TABLE ONLY analytics.lead_submissions ADD CONSTRAINT lead_submissions_pkey PRIMARY KEY (id);
ALTER TABLE ONLY analytics.lead_submissions ADD CONSTRAINT lead_submissions_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;
ALTER TABLE ONLY analytics.lead_submissions ADD CONSTRAINT lead_submissions_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE SET NULL;
ALTER TABLE ONLY analytics.lead_submissions ADD CONSTRAINT lead_submissions_customer_fk FOREIGN KEY (customer_id) REFERENCES core.customers(id) ON DELETE SET NULL;

CREATE INDEX lead_submissions_ip_time_idx ON analytics.lead_submissions (ip_hash, occurred_at DESC);
CREATE INDEX lead_submissions_prof_time_idx ON analytics.lead_submissions (professional_id, occurred_at DESC);
CREATE INDEX lead_submissions_site_time_idx ON analytics.lead_submissions (site_id, occurred_at DESC);

-- analytics.booking_events
CREATE TABLE IF NOT EXISTS analytics.booking_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES site.sites(id) ON DELETE CASCADE,
    brand_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    status text NOT NULL DEFAULT 'completed',
    source text NOT NULL DEFAULT 'site_booking_checkout',
    square_booking_id text NULL,
    square_payment_id text NULL,
    service_variation_id text NULL,
    service_name text NULL,
    payment_method text NULL,
    customer_name text NULL,
    customer_email text NULL,
    customer_phone text NULL,
    currency_code text NOT NULL DEFAULT 'AUD',
    amount_paid_cents integer NOT NULL DEFAULT 0,
    raw_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    appointment_start_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT booking_events_status_check CHECK (status IN ('accepted', 'pending', 'completed', 'cancelled', 'failed')),
    CONSTRAINT booking_events_amount_nonnegative CHECK (amount_paid_cents >= 0)
);

CREATE INDEX booking_events_professional_occurred_idx ON analytics.booking_events (professional_id, occurred_at DESC);
CREATE INDEX booking_events_brand_occurred_idx ON analytics.booking_events (brand_professional_id, occurred_at DESC);
CREATE INDEX booking_events_site_occurred_idx ON analytics.booking_events (site_id, occurred_at DESC);
CREATE UNIQUE INDEX booking_events_professional_booking_uq ON analytics.booking_events (professional_id, square_booking_id) WHERE square_booking_id IS NOT NULL;
CREATE INDEX booking_events_professional_appointment_idx ON analytics.booking_events (professional_id, appointment_start_at DESC);

-- ---- Daily aggregates ----

-- analytics.site_metrics_daily
CREATE TABLE IF NOT EXISTS analytics.site_metrics_daily (
    day date NOT NULL,
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES site.sites(id) ON DELETE CASCADE,
    timezone text NOT NULL,
    visits_count integer NOT NULL DEFAULT 0,
    unique_visitors integer NOT NULL DEFAULT 0,
    clicks_count integer NOT NULL DEFAULT 0,
    unique_clickers integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, professional_id, site_id)
);

CREATE INDEX site_metrics_daily_professional_day_idx ON analytics.site_metrics_daily (professional_id, day DESC);
CREATE INDEX site_metrics_daily_site_day_idx ON analytics.site_metrics_daily (site_id, day DESC);

-- analytics.booking_metrics_daily
CREATE TABLE IF NOT EXISTS analytics.booking_metrics_daily (
    day date NOT NULL,
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    bookings_count integer NOT NULL DEFAULT 0,
    total_spent_cents bigint NOT NULL DEFAULT 0,
    paid_bookings_count integer NOT NULL DEFAULT 0,
    customers_count integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, professional_id, currency_code)
);

CREATE INDEX booking_metrics_daily_professional_day_idx ON analytics.booking_metrics_daily (professional_id, day DESC);

-- analytics.brand_metrics_daily
CREATE TABLE IF NOT EXISTS analytics.brand_metrics_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, currency_code, timezone)
);

CREATE INDEX brand_metrics_daily_brand_day_idx ON analytics.brand_metrics_daily (brand_professional_id, day DESC);

-- analytics.brand_commission_daily
CREATE TABLE IF NOT EXISTS analytics.brand_commission_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    payout_status text NOT NULL,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    accrual_cents integer NOT NULL DEFAULT 0,
    reversal_cents integer NOT NULL DEFAULT 0,
    payout_cents integer NOT NULL DEFAULT 0,
    net_outstanding_cents integer NOT NULL DEFAULT 0,
    entries_count integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT brand_commission_daily_payout_status_check CHECK (payout_status IN ('pending', 'approved', 'paid', 'reversed', 'disputed')),
    PRIMARY KEY (day, brand_professional_id, affiliate_professional_id, payout_status, currency_code, timezone)
);

CREATE INDEX brand_commission_daily_brand_day_idx ON analytics.brand_commission_daily (brand_professional_id, day DESC);
CREATE INDEX brand_commission_daily_affiliate_day_idx ON analytics.brand_commission_daily (affiliate_professional_id, day DESC);

-- analytics.brand_affiliate_daily (RENAMED from brand_influencer_daily)
CREATE TABLE IF NOT EXISTS analytics.brand_affiliate_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    commission_accrued_cents integer NOT NULL DEFAULT 0,
    commission_reversed_cents integer NOT NULL DEFAULT 0,
    commission_net_cents integer NOT NULL DEFAULT 0,
    customers_count integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, affiliate_professional_id, currency_code, timezone)
);

CREATE INDEX brand_affiliate_daily_brand_affiliate_day_idx ON analytics.brand_affiliate_daily (brand_professional_id, affiliate_professional_id, day DESC);

-- analytics.professional_metrics_daily
CREATE TABLE IF NOT EXISTS analytics.professional_metrics_daily (
    day date NOT NULL,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    commission_accrued_cents integer NOT NULL DEFAULT 0,
    commission_reversed_cents integer NOT NULL DEFAULT 0,
    commission_paid_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, affiliate_professional_id, currency_code, timezone)
);

CREATE INDEX professional_metrics_daily_affiliate_day_idx ON analytics.professional_metrics_daily (affiliate_professional_id, day DESC);

-- analytics.professional_customer_daily
CREATE TABLE IF NOT EXISTS analytics.professional_customer_daily (
    day date NOT NULL,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    timezone text NOT NULL,
    customers_count integer NOT NULL DEFAULT 0,
    new_customers_count integer NOT NULL DEFAULT 0,
    returning_customers_count integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, affiliate_professional_id, timezone)
);

CREATE INDEX professional_customer_daily_affiliate_day_idx ON analytics.professional_customer_daily (affiliate_professional_id, day DESC);

-- ---- Hourly aggregates ----

-- analytics.site_metrics_hourly
CREATE TABLE IF NOT EXISTS analytics.site_metrics_hourly (
    hour_start timestamptz NOT NULL,
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES site.sites(id) ON DELETE CASCADE,
    timezone text NOT NULL,
    visits_count integer NOT NULL DEFAULT 0,
    unique_visitors integer NOT NULL DEFAULT 0,
    clicks_count integer NOT NULL DEFAULT 0,
    unique_clickers integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (hour_start, professional_id, site_id)
);

CREATE INDEX site_metrics_hourly_professional_hour_idx ON analytics.site_metrics_hourly (professional_id, hour_start DESC);
CREATE INDEX site_metrics_hourly_site_hour_idx ON analytics.site_metrics_hourly (site_id, hour_start DESC);

-- analytics.booking_metrics_hourly
CREATE TABLE IF NOT EXISTS analytics.booking_metrics_hourly (
    hour_start timestamptz NOT NULL,
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    bookings_count integer NOT NULL DEFAULT 0,
    total_spent_cents bigint NOT NULL DEFAULT 0,
    paid_bookings_count integer NOT NULL DEFAULT 0,
    customers_count integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (hour_start, professional_id, currency_code)
);

CREATE INDEX booking_metrics_hourly_professional_hour_idx ON analytics.booking_metrics_hourly (professional_id, hour_start DESC);

-- analytics.brand_metrics_hourly
CREATE TABLE IF NOT EXISTS analytics.brand_metrics_hourly (
    hour_start timestamptz NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents bigint NOT NULL DEFAULT 0,
    refunded_cents bigint NOT NULL DEFAULT 0,
    returned_cents bigint NOT NULL DEFAULT 0,
    net_cents bigint NOT NULL DEFAULT 0,
    commission_net_cents bigint NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (hour_start, brand_professional_id, currency_code)
);

CREATE INDEX brand_metrics_hourly_brand_hour_idx ON analytics.brand_metrics_hourly (brand_professional_id, hour_start DESC);

-- analytics.professional_metrics_hourly
CREATE TABLE IF NOT EXISTS analytics.professional_metrics_hourly (
    hour_start timestamptz NOT NULL,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents bigint NOT NULL DEFAULT 0,
    refunded_cents bigint NOT NULL DEFAULT 0,
    returned_cents bigint NOT NULL DEFAULT 0,
    net_cents bigint NOT NULL DEFAULT 0,
    commission_accrued_cents bigint NOT NULL DEFAULT 0,
    commission_reversed_cents bigint NOT NULL DEFAULT 0,
    commission_paid_cents bigint NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (hour_start, affiliate_professional_id, currency_code)
);

CREATE INDEX professional_metrics_hourly_affiliate_hour_idx ON analytics.professional_metrics_hourly (affiliate_professional_id, hour_start DESC);

-- ==========================================================================
-- 10. PUBLIC TABLES
-- ==========================================================================

CREATE TABLE IF NOT EXISTS public.failed_jobs (
    id bigint NOT NULL,
    uuid varchar(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);

ALTER TABLE public.failed_jobs OWNER TO postgres;

CREATE SEQUENCE IF NOT EXISTS public.failed_jobs_id_seq
    START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO postgres;
ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;
ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);

ALTER TABLE ONLY public.failed_jobs ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.failed_jobs ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);

CREATE TABLE IF NOT EXISTS public.job_batches (
    id varchar(255) NOT NULL,
    name varchar(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);

ALTER TABLE public.job_batches OWNER TO postgres;

ALTER TABLE ONLY public.job_batches ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);

-- ==========================================================================
-- 11. VIEWS
-- ==========================================================================

-- site.public_site_payload (updated references from core.* to site.*)
CREATE OR REPLACE VIEW site.public_site_payload
WITH (security_invoker='on') AS
SELECT
  s.id as site_id,
  s.professional_id,
  s.subdomain,
  jsonb_build_object(
    'site', jsonb_build_object(
      'id', s.id,
      'subdomain', s.subdomain,
      'settings', s.settings,
      'is_published', s.is_published,
      'gallery', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'webp'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'gallery'
          AND sm.media_type = 'image'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'content_images', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'webp'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'content'
          AND sm.media_type = 'image'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'gallery_videos', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'media_type', sm.media_type,
            'processing_state', sm.processing_state,
            'duration_ms', sm.duration_ms,
            'poster', sm.poster_path,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'mp4'
            ), '{}'::jsonb),
            'streams', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'hls_playlist'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'gallery'
          AND sm.media_type = 'video'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'content_videos', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'media_type', sm.media_type,
            'processing_state', sm.processing_state,
            'duration_ms', sm.duration_ms,
            'poster', sm.poster_path,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'mp4'
            ), '{}'::jsonb),
            'streams', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'hls_playlist'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'content'
          AND sm.media_type = 'video'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb)
    ),
    'professional', jsonb_build_object(
      'id', p.id,
      'handle', p.handle,
      'display_name', p.display_name,
      'bio', p.bio,
      'country_code', p.country_code,
      'timezone', p.timezone,
      -- Opt-in fields: NULL = not sharing publicly; setting a value is the professional's
      -- explicit choice to display that contact detail on their public site.
      -- primary_email / phone are intentionally excluded from this view.
      'public_contact_number', p.public_contact_number,
      'public_contact_email', p.public_contact_email
    ),
    'theme', CASE WHEN t.id IS NULL THEN NULL::jsonb ELSE jsonb_build_object('id', t.id, 'key', t.key, 'name', t.name, 'config', t.config) END,
    'links', COALESCE((
      SELECT jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'settings', b.settings
        )
        ORDER BY b.sort_order, b.created_at
      )
      FROM site.blocks b
      WHERE b.site_id = s.id AND b.block_group = 'links' AND b.is_active = true AND b.deleted_at IS NULL
    ), '[]'::jsonb),
    'sections', COALESCE((
      SELECT jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'is_enabled', b.is_enabled,
          'is_active', b.is_active,
          'settings', b.settings
        )
        ORDER BY b.sort_order, b.created_at
      )
      FROM site.blocks b
      WHERE b.site_id = s.id AND b.block_group = 'sections' AND b.is_enabled = true AND b.is_active = true AND b.deleted_at IS NULL
    ), '[]'::jsonb),
    'services', COALESCE((
      SELECT jsonb_agg(
        jsonb_build_object(
          'id', sv.id,
          'title', sv.title,
          'description', sv.description,
          'price_cents', sv.price_cents,
          'currency_code', sv.currency_code,
          'duration_minutes', sv.duration_minutes,
          'is_active', sv.is_active,
          'sort_order', sv.sort_order,
          'category', COALESCE(sc.title, 'Services')
        )
        ORDER BY COALESCE(sc.sort_order, 2147483647), LOWER(COALESCE(sc.title, 'Services')), sv.sort_order, sv.created_at
      )
      FROM site.services sv
      LEFT JOIN site.service_categories sc
        ON sc.id = sv.category_id
        AND sc.deleted_at IS NULL
      WHERE sv.professional_id = p.id AND sv.is_active = true AND sv.deleted_at IS NULL
    ), '[]'::jsonb),
    'legal', CASE
      WHEN plc.professional_id IS NULL THEN NULL::jsonb
      ELSE jsonb_build_object(
        'privacy_policy',
          CASE
            WHEN plc.active_privacy_source = 'manual'
              AND NULLIF(BTRIM(COALESCE(plc.manual_privacy_policy, '')), '') IS NOT NULL
            THEN plc.manual_privacy_policy
            ELSE plc.generated_privacy_policy
          END,
        'terms_and_conditions',
          CASE
            WHEN plc.active_terms_source = 'manual'
              AND NULLIF(BTRIM(COALESCE(plc.manual_terms_and_conditions, '')), '') IS NOT NULL
            THEN plc.manual_terms_and_conditions
            ELSE plc.generated_terms_and_conditions
          END,
        'active_privacy_source', plc.active_privacy_source,
        'active_terms_source', plc.active_terms_source
      )
    END
  ) as payload
FROM site.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN site.themes t ON t.id = s.theme_id
LEFT JOIN core.professional_legal_contents plc ON plc.professional_id = p.id
WHERE
  s.is_published = true
  AND p.status = 'active'
  AND p.deleted_at IS NULL;

COMMENT ON VIEW site.public_site_payload IS 'Complete public site payload with two-flag section visibility (is_enabled + is_active)';

-- site.all_site_data (updated references from core.* to site.*)
CREATE OR REPLACE VIEW site.all_site_data AS
SELECT
    s.id AS site_id,
    s.subdomain,
    s.is_published,
    s.settings AS site_settings,
    s.created_at AS site_created_at,
    s.updated_at AS site_updated_at,
    t.id AS theme_id,
    t.key AS theme_key,
    t.name AS theme_name,
    t.config AS theme_config,
    p.id AS professional_id,
    p.handle AS professional_handle,
    p.display_name AS professional_display_name,
    p.bio AS professional_bio,
    p.icon_bucket AS professional_icon_bucket,
    p.icon_path AS professional_icon_path,
    p.headshot_bucket AS professional_headshot_bucket,
    p.headshot_path AS professional_headshot_path,
    p.location_street_address AS professional_location_street_address,
    p.location_city AS professional_location_city,
    p.location_state AS professional_location_state,
    p.location_postcode AS professional_location_postcode,
    p.location_country AS professional_location_country,
    COALESCE(jsonb_agg(
      jsonb_build_object(
        'id', b.id,
        'site_id', b.site_id,
        'professional_id', b.professional_id,
        'block_type', b.block_type,
        'block_group', b.block_group,
        'title', b.title,
        'url', b.url,
        'icon_key', b.icon_key,
        'sort_order', b.sort_order,
        'is_active', b.is_active,
        'settings', b.settings,
        'created_at', b.created_at,
        'updated_at', b.updated_at
      )
      ORDER BY b.sort_order
    ) FILTER (WHERE b.id IS NOT NULL), '[]'::jsonb) AS blocks
FROM site.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN site.themes t ON t.id = s.theme_id
LEFT JOIN site.blocks b ON b.site_id = s.id
GROUP BY s.id, t.id, p.id;

-- ==========================================================================
-- 12. TRIGGER BINDINGS
-- ==========================================================================

-- Core triggers
CREATE OR REPLACE TRIGGER set_timestamp_professionals BEFORE UPDATE ON core.professionals FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_comet_staff BEFORE UPDATE ON core.comet_staff FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER prevent_staff_escalation BEFORE UPDATE ON core.comet_staff FOR EACH ROW EXECUTE FUNCTION core.prevent_staff_escalation();
CREATE OR REPLACE TRIGGER set_timestamp_customers BEFORE UPDATE ON core.customers FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_professional_legal_contents BEFORE UPDATE ON core.professional_legal_contents FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER trg_waitlist_signups_set_updated_at BEFORE UPDATE ON core.waitlist_signups FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE FUNCTION core.set_professional_integrations_updated_at() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.updated_at = now(); RETURN NEW; END; $$;
CREATE OR REPLACE TRIGGER trg_professional_integrations_set_updated_at BEFORE UPDATE ON core.professional_integrations FOR EACH ROW EXECUTE FUNCTION core.set_professional_integrations_updated_at();

CREATE OR REPLACE FUNCTION core.set_professional_confirmation_preferences_updated_at() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.updated_at = now(); RETURN NEW; END; $$;
CREATE OR REPLACE TRIGGER trg_professional_confirmation_preferences_set_updated_at BEFORE UPDATE ON core.professional_confirmation_preferences FOR EACH ROW EXECUTE FUNCTION core.set_professional_confirmation_preferences_updated_at();

-- Site triggers
CREATE OR REPLACE TRIGGER set_timestamp_sites BEFORE UPDATE ON site.sites FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_default_theme_on_sites BEFORE INSERT ON site.sites FOR EACH ROW EXECUTE FUNCTION core.set_default_theme_for_site();
CREATE OR REPLACE TRIGGER set_timestamp_link_blocks BEFORE UPDATE ON site.blocks FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_site_media BEFORE UPDATE ON site.site_media FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER enforce_site_gallery_max6 BEFORE INSERT OR UPDATE OF site_id, deleted_at ON site.site_media FOR EACH ROW EXECUTE FUNCTION core.enforce_site_gallery_max6();
CREATE OR REPLACE TRIGGER set_timestamp_site_subdomain_aliases BEFORE UPDATE ON site.site_subdomain_aliases FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_themes BEFORE UPDATE ON site.themes FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE FUNCTION core.set_media_variants_updated_at() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.updated_at = now(); RETURN NEW; END; $$;
CREATE OR REPLACE TRIGGER trg_media_variants_set_updated_at BEFORE UPDATE ON site.media_variants FOR EACH ROW EXECUTE FUNCTION core.set_media_variants_updated_at();

CREATE OR REPLACE TRIGGER trg_service_categories_updated_at BEFORE UPDATE ON site.service_categories FOR EACH ROW EXECUTE FUNCTION core.set_updated_at();

-- Brand triggers
CREATE OR REPLACE TRIGGER trg_brand_team_memberships_set_updated_at BEFORE UPDATE ON brand.brand_team_memberships FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER trg_validate_brand_team_membership BEFORE INSERT OR UPDATE OF brand_professional_id ON brand.brand_team_memberships FOR EACH ROW EXECUTE FUNCTION core.validate_brand_team_membership();

-- Commerce triggers
CREATE OR REPLACE TRIGGER trg_commission_ledger_entries_set_updated_at BEFORE UPDATE ON commerce.commission_ledger_entries FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER trg_cp_set_updated_at BEFORE UPDATE ON commerce.commission_payouts FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER trg_bct_set_updated_at BEFORE UPDATE ON commerce.brand_commission_topups FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- Billing triggers
CREATE OR REPLACE TRIGGER trg_billing_plans_updated_at BEFORE UPDATE ON billing.plans FOR EACH ROW EXECUTE FUNCTION billing.set_updated_at();
CREATE OR REPLACE TRIGGER trg_billing_subscriptions_updated_at BEFORE UPDATE ON billing.subscriptions FOR EACH ROW EXECUTE FUNCTION billing.set_updated_at();

-- Analytics triggers
CREATE OR REPLACE TRIGGER trg_booking_events_set_updated_at BEFORE UPDATE ON analytics.booking_events FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ==========================================================================
-- 13. RLS POLICIES
-- ==========================================================================

-- analytics.lead_submissions
ALTER TABLE analytics.lead_submissions ENABLE ROW LEVEL SECURITY;

-- analytics.link_clicks
ALTER TABLE analytics.link_clicks ENABLE ROW LEVEL SECURITY;

CREATE POLICY link_clicks_anyone_insert_valid_block ON analytics.link_clicks FOR INSERT TO anon WITH CHECK (EXISTS (
    SELECT 1 FROM site.blocks b JOIN site.sites s ON s.id = b.site_id
    WHERE b.id = link_clicks.link_block_id AND b.site_id = link_clicks.site_id
    AND b.professional_id = link_clicks.professional_id AND b.is_active = true AND s.is_published = true
));

CREATE POLICY link_clicks_staff_all ON analytics.link_clicks TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()));

-- analytics.site_visits
ALTER TABLE analytics.site_visits ENABLE ROW LEVEL SECURITY;

CREATE POLICY site_visits_anyone_insert_valid_site ON analytics.site_visits FOR INSERT TO anon WITH CHECK (EXISTS (
    SELECT 1 FROM site.sites s WHERE s.id = site_visits.site_id AND s.professional_id = site_visits.professional_id AND s.is_published = true
));

CREATE POLICY site_visits_staff_all ON analytics.site_visits TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()));

-- core.professionals
ALTER TABLE core.professionals ENABLE ROW LEVEL SECURITY;

CREATE POLICY professionals_all_authenticated ON core.professionals TO authenticated
    USING ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

-- core.comet_staff
ALTER TABLE core.comet_staff ENABLE ROW LEVEL SECURITY;

CREATE POLICY comet_staff_select_authenticated ON core.comet_staff FOR SELECT TO authenticated
    USING ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')));

CREATE POLICY comet_staff_insert_admin ON core.comet_staff FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin'));

CREATE POLICY comet_staff_update_authenticated ON core.comet_staff FOR UPDATE TO authenticated
    USING ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')))
    WITH CHECK ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')));

CREATE POLICY comet_staff_delete_admin ON core.comet_staff FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin'));

-- core.customers
ALTER TABLE core.customers ENABLE ROW LEVEL SECURITY;

CREATE POLICY customers_all_authenticated ON core.customers TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = customers.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = customers.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

-- core.professional_legal_contents
ALTER TABLE core.professional_legal_contents ENABLE ROW LEVEL SECURITY;

CREATE POLICY legal_contents_pro_all ON core.professional_legal_contents TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY legal_contents_staff_all ON core.professional_legal_contents TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()));

-- site.blocks
ALTER TABLE site.blocks ENABLE ROW LEVEL SECURITY;

CREATE POLICY link_blocks_select_authenticated ON site.blocks FOR SELECT TO authenticated
    USING (((is_active = true) AND (EXISTS (SELECT 1 FROM site.sites s WHERE s.id = blocks.site_id AND s.is_published = true)))
        OR (EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY link_blocks_insert_authenticated ON site.blocks FOR INSERT TO authenticated
    WITH CHECK ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY link_blocks_update_authenticated ON site.blocks FOR UPDATE TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY link_blocks_delete_authenticated ON site.blocks FOR DELETE TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY link_blocks_public_read_active_published ON site.blocks FOR SELECT TO anon
    USING ((is_active = true) AND (EXISTS (SELECT 1 FROM site.sites s WHERE s.id = blocks.site_id AND s.is_published = true)));

-- site.sites
ALTER TABLE site.sites ENABLE ROW LEVEL SECURITY;

CREATE POLICY sites_select_authenticated ON site.sites FOR SELECT TO authenticated
    USING ((is_published = true)
        OR (EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY sites_insert_authenticated ON site.sites FOR INSERT TO authenticated
    WITH CHECK ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY sites_update_authenticated ON site.sites FOR UPDATE TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY sites_delete_authenticated ON site.sites FOR DELETE TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.professionals p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY sites_public_read_published ON site.sites FOR SELECT TO anon USING (is_published = true);

-- site.site_media
ALTER TABLE site.site_media ENABLE ROW LEVEL SECURITY;

CREATE POLICY site_media_select_authenticated ON site.site_media FOR SELECT TO authenticated
    USING ((EXISTS (SELECT 1 FROM site.sites s JOIN core.professionals p ON p.id = s.professional_id WHERE s.id = site_media.site_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY site_media_insert_authenticated ON site.site_media FOR INSERT TO authenticated
    WITH CHECK ((EXISTS (SELECT 1 FROM site.sites s JOIN core.professionals p ON p.id = s.professional_id WHERE s.id = site_media.site_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY site_media_update_authenticated ON site.site_media FOR UPDATE TO authenticated
    USING ((EXISTS (SELECT 1 FROM site.sites s JOIN core.professionals p ON p.id = s.professional_id WHERE s.id = site_media.site_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((EXISTS (SELECT 1 FROM site.sites s JOIN core.professionals p ON p.id = s.professional_id WHERE s.id = site_media.site_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY site_media_delete_staff ON site.site_media FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()));

CREATE POLICY site_media_public_read_published ON site.site_media FOR SELECT TO anon
    USING ((deleted_at IS NULL) AND (EXISTS (SELECT 1 FROM site.sites s WHERE s.id = site_media.site_id AND s.is_published = true)));

-- site.site_subdomain_aliases
ALTER TABLE site.site_subdomain_aliases ENABLE ROW LEVEL SECURITY;

CREATE POLICY aliases_pro_all ON site.site_subdomain_aliases TO authenticated
    USING (EXISTS (SELECT 1 FROM site.sites s JOIN core.professionals p ON p.id = s.professional_id WHERE s.id = site_subdomain_aliases.site_id AND p.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM site.sites s JOIN core.professionals p ON p.id = s.professional_id WHERE s.id = site_subdomain_aliases.site_id AND p.auth_user_id = auth.uid()));

CREATE POLICY aliases_staff_all ON site.site_subdomain_aliases TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()));

CREATE POLICY aliases_public_read ON site.site_subdomain_aliases FOR SELECT TO anon
    USING (EXISTS (SELECT 1 FROM site.sites s WHERE s.id = site_subdomain_aliases.site_id AND s.is_published = true));

-- site.themes
ALTER TABLE site.themes ENABLE ROW LEVEL SECURITY;

CREATE POLICY themes_select_authenticated ON site.themes FOR SELECT TO authenticated USING (true);
CREATE POLICY themes_public_read ON site.themes FOR SELECT TO anon USING (true);

CREATE POLICY themes_insert_staff ON site.themes FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()));

CREATE POLICY themes_update_staff ON site.themes FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()));

CREATE POLICY themes_delete_staff ON site.themes FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()));

-- site.services
ALTER TABLE site.services ENABLE ROW LEVEL SECURITY;

CREATE POLICY services_pro_all ON site.services TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY services_staff_all ON site.services TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()));

-- notifications.email_subscriptions
ALTER TABLE notifications.email_subscriptions ENABLE ROW LEVEL SECURITY;

CREATE POLICY email_subs_pro_all ON notifications.email_subscriptions TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY email_subs_public_insert ON notifications.email_subscriptions FOR INSERT TO anon WITH CHECK (true);
CREATE POLICY email_subs_public_unsubscribe ON notifications.email_subscriptions FOR SELECT TO anon USING (unsubscribe_token IS NOT NULL);

CREATE POLICY email_subs_staff_all ON notifications.email_subscriptions TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()));

-- notifications.notifications
ALTER TABLE notifications.notifications ENABLE ROW LEVEL SECURITY;

-- notifications.notification_receipts
ALTER TABLE notifications.notification_receipts ENABLE ROW LEVEL SECURITY;

-- billing.plans
ALTER TABLE billing.plans ENABLE ROW LEVEL SECURITY;

CREATE POLICY "read active plans" ON billing.plans FOR SELECT TO anon, authenticated USING (is_active = true);

-- billing.subscriptions
ALTER TABLE billing.subscriptions ENABLE ROW LEVEL SECURITY;

CREATE POLICY "read own subscription" ON billing.subscriptions FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.professionals p
        WHERE p.id = billing.subscriptions.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL
    ));

-- ==========================================================================
-- 14. SEED DATA
-- ==========================================================================

-- Seed plans
INSERT INTO billing.plans (plan_key, name, stripe_price_id, sort_order, entitlements, price_cents, billing_interval)
VALUES
    ('free',  'Free',  'price_FREE_REPLACE_ME',  0, '{"custom_domain": false, "max_links": 10}', 0, 'month'),
    ('pro',   'Pro',   'price_PRO_REPLACE_ME',   1, '{"custom_domain": false, "max_links": 50}', 2900, 'month'),
    ('elite', 'Elite', 'price_ELITE_REPLACE_ME', 2, '{"custom_domain": true,  "max_links": 999999}', 5900, 'month')
ON CONFLICT (plan_key) DO NOTHING;

-- Seed themes
INSERT INTO site.themes (key, name, description, config, is_default)
VALUES
    ('theme-1', 'Theme 1', 'Default theme.', '{}'::jsonb, true),
    ('theme-2', 'Theme 2', 'Theme 2 placeholder theme.', '{}'::jsonb, false),
    ('theme-3', 'Theme 3', 'Theme 3 placeholder theme.', '{}'::jsonb, false)
ON CONFLICT (key) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    config = EXCLUDED.config,
    is_default = EXCLUDED.is_default,
    updated_at = now();

-- ==========================================================================
-- 15. ROLE PERMISSIONS
-- ==========================================================================

-- Schema usage grants for Supabase roles
GRANT USAGE ON SCHEMA analytics TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA core TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA site TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA brand TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA commerce TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA notifications TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA billing TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA public TO postgres, anon, authenticated, service_role;

-- Billing grants
GRANT SELECT ON billing.plans TO anon, authenticated;
GRANT SELECT ON billing.subscriptions TO authenticated;

-- app_backend runtime role
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        -- Created NOLOGIN; password + LOGIN must be set out-of-band after migration runs:
        --   ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret-store>';
        -- Real credential lives in Laravel Cloud env (DB_PASSWORD), never in git.
        CREATE ROLE app_backend NOLOGIN;
    END IF;

    -- Grant permissions (always runs now that role is guaranteed to exist)
        -- Schema usage
        EXECUTE 'GRANT USAGE ON SCHEMA core TO app_backend';
        EXECUTE 'GRANT USAGE ON SCHEMA site TO app_backend';
        EXECUTE 'GRANT USAGE ON SCHEMA brand TO app_backend';
        EXECUTE 'GRANT USAGE ON SCHEMA commerce TO app_backend';
        EXECUTE 'GRANT USAGE ON SCHEMA notifications TO app_backend';
        EXECUTE 'GRANT USAGE ON SCHEMA analytics TO app_backend';
        EXECUTE 'GRANT USAGE ON SCHEMA billing TO app_backend';
        EXECUTE 'GRANT USAGE ON SCHEMA public TO app_backend';

        -- Full CRUD on all schemas
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA core TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA site TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA brand TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA commerce TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA notifications TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA analytics TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA billing TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_backend';

        -- Sequences
        EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA core TO app_backend';
        EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA site TO app_backend';
        EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA brand TO app_backend';
        EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA commerce TO app_backend';
        EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA notifications TO app_backend';
        EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA analytics TO app_backend';
        EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA billing TO app_backend';
        EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_backend';

        -- Default privileges for future tables
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA core GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA site GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA brand GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA commerce GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA notifications GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA analytics GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA billing GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';

        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA core GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA site GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA brand GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA commerce GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA notifications GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA analytics GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA billing GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
END $$;

COMMIT;
