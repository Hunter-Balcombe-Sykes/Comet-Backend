-- City-dot demographics: capture the edge-resolved visitor coordinates
-- (Cloudflare request.cf latitude/longitude, forwarded by the partna-pages
-- middleware as X-Visitor-Lat/Lon) alongside the existing city name so the
-- analytics map can plot real city pins instead of country centroids.
--
-- Additive + nullable: legacy rows keep NULL and simply don't get a map pin
-- (the "Top cities" name list is unaffected). Bounds are validated in the
-- detector layer (DetectsClientInfo::parseCoordinate), not as a CHECK — the
-- SQLite test schema can't enforce CHECKs, so code-side validation is the
-- fail-closed layer per repo convention.

ALTER TABLE analytics.site_visits
    ADD COLUMN IF NOT EXISTS latitude double precision,
    ADD COLUMN IF NOT EXISTS longitude double precision;
