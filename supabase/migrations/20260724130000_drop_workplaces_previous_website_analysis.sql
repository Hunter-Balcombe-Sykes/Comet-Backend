-- Drops the orphaned `previous_website_analysis` column from site.workplaces.
-- Its writer (AnalyzePreviousWebsiteJob, WebsiteStyleAnalyzer v2) was deleted
-- along with the integration-factor/contribution-ledger machine — confirmed
-- zero remaining writers (grepped app/ for the column name; only the model
-- cast, an explicit data-export allow-list exclusion, and comments referenced
-- it, none of which write). Read-side consumers (WorkplaceResource,
-- DataExportPayloadBuilder) already deliberately excluded it from every
-- outward-facing payload, so this drop has no user-visible effect.

BEGIN;
SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.workplaces DROP COLUMN IF EXISTS previous_website_analysis;

COMMIT;
