-- =====================================================================
-- Fix broken trigger function `trg_recompute_partna_url`
-- =====================================================================
-- Migration 20260527030000_rename_professional_to_user.sql renamed
-- `site.compute_professional_url(uuid)` → `site.compute_user_url(uuid)`
-- via ALTER FUNCTION ... RENAME. The rename preserves OID so direct
-- function-pointer references (e.g. trigger bindings) survive, but
-- callers that reference the function BY NAME inside PL/pgSQL bodies
-- do NOT — those resolve by name at execution time. The trigger
-- function `site.trg_recompute_partna_url` is one such by-name caller:
-- its body still reads `site.compute_professional_url(p_professional_id)`
-- even though that symbol no longer exists, which means every
-- INSERT/UPDATE on `site.sites` (which fires `sites_url_sync_aiu`
-- → `trg_sites_url_sync()` → `trg_recompute_partna_url()`) fails with
-- "function does not exist" until this lands.
--
-- Fix: patch the inner call to use the renamed symbol. Same behaviour
-- otherwise — recompute the cached `core.users.partna_url` after any
-- site row change so the QR code endpoint + UserResource(s) stay in
-- sync with the canonical subdomain.
--
-- Already applied to dev (`glncumufgaqcmqhzwrxm`) at 2026-05-29 06:32
-- via Supabase MCP `apply_migration`. Prod will pick it up on the
-- next `supabase db push`.
-- =====================================================================

CREATE OR REPLACE FUNCTION site.trg_recompute_partna_url(p_professional_id uuid)
RETURNS void LANGUAGE plpgsql AS $func$
DECLARE
    v_url text;
BEGIN
    v_url := site.compute_user_url(p_professional_id);

    UPDATE core.users
       SET partna_url = v_url
     WHERE id = p_professional_id;
END;
$func$;
