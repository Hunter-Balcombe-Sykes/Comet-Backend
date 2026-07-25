-- Custom domain "primary" toggle.
--
-- When true AND the custom domain is verified active, the user's canonical
-- sitepage URL (dashboard "view"/copy links, skeleton previews, share targets)
-- becomes the custom domain instead of <handle>.partna.au. Default false:
-- connecting a domain never auto-promotes it — the user opts in from settings,
-- and the API only permits flipping it true once the domain is active. Swapping
-- back to default sets it false again.
ALTER TABLE site.sites
    ADD COLUMN IF NOT EXISTS custom_domain_primary boolean NOT NULL DEFAULT false;
