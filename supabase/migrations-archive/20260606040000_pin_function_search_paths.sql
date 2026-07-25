-- Pin a fixed search_path on 12 trigger/helper functions.
--
-- WHY: Supabase's security advisor flags these as `function_search_path_mutable`
-- WARNs. A function without a pinned search_path inherits the *caller's*
-- search_path at execution time. A malicious caller can prepend a schema
-- containing a same-named shadow object (table/type/function) and hijack any
-- UNQUALIFIED reference inside the body — a privilege-escalation / object-
-- resolution vector. Pinning the path removes the mutability and clears the
-- advisor.
--
-- CHOICE OF PATH: every one of these 12 functions references only `NEW`/`OLD`,
-- pg_catalog builtins (now(), format(), interval casts, RAISE, etc.), and/or
-- objects that are ALREADY fully schema-qualified in the body (e.g.
-- core.user_handle_aliases, site.sites, core.users, site.design_kits,
-- site.compute_user_url). `pg_catalog` is always implicitly first in the
-- search_path, so builtins resolve regardless. There is therefore NOTHING
-- unqualified left to resolve, and the most secure, fully behaviour-preserving
-- choice for ALL twelve is the empty search_path (`SET search_path = ''`). No
-- function body is rewritten — only the config is pinned.
--
-- None of these functions are SECURITY DEFINER (all run with the caller's
-- privileges), but pinning the path is still the correct hardening and is what
-- the advisor requires.
--
-- Identity arguments below come from pg_get_function_identity_arguments(); the
-- trigger functions take no args (`()`), and the two helpers take their uuid /
-- void signatures exactly.

BEGIN;

-- Plain updated_at touch triggers: body is only `NEW.updated_at = now()`.
ALTER FUNCTION public.set_updated_at() SET search_path = '';
ALTER FUNCTION core.set_updated_at() SET search_path = '';
ALTER FUNCTION core.set_media_variants_updated_at() SET search_path = '';
ALTER FUNCTION core.set_user_confirmation_preferences_updated_at() SET search_path = '';

-- Append-only guard triggers: body is only `RAISE EXCEPTION`.
ALTER FUNCTION core.reject_staff_audit_log_mutation() SET search_path = '';
ALTER FUNCTION core.trg_handle_change_log_append_only() SET search_path = '';

-- Handle-lifecycle triggers: reference core.user_handle_aliases (qualified),
-- NEW/OLD, and builtins only.
ALTER FUNCTION core.trg_user_handle_alias_check() SET search_path = '';
ALTER FUNCTION core.trg_user_handle_change() SET search_path = '';

-- URL/design-kit helpers + triggers: every object reference
-- (site.sites, core.users, site.design_kits, site.compute_user_url,
-- site.trg_recompute_partna_url) is already schema-qualified.
ALTER FUNCTION site.compute_user_url(p_professional_id uuid) SET search_path = '';
ALTER FUNCTION site.trg_recompute_partna_url(p_professional_id uuid) SET search_path = '';
ALTER FUNCTION site.create_empty_design_kit() SET search_path = '';
ALTER FUNCTION site.trg_sites_url_sync() SET search_path = '';

COMMIT;
