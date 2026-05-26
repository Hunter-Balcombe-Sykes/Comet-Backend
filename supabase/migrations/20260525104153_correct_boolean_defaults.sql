-- Correct two boolean column defaults that pointed the wrong way for the
-- individual-standalone-site model:
--
--   site.sites.is_published                — false → true
--     A handle URL with no published site 404s, so signup ought to land on
--     a working public page. By the time SiteProvisioningService runs in
--     ProfessionalBootstrapService::bootstrap(), display_name is already
--     saved in the same transaction, so the UpdateSiteAction publish gate
--     ("must have display name") is satisfied for self-service publishes.
--
--   core.customers.marketing_opt_in_cached — true → NULL (no default)
--     The column is tri-state: true = subscribed, false = unsubscribed,
--     null = un-synced (Customer::isMarketingOptedIn() falls through to
--     core.notifications.email_subscriptions when null). A default of
--     `true` makes a freshly-inserted row claim a subscription that does
--     not exist upstream — a phantom opt-in until SyncCustomerMarketingOptInJob
--     reconciles. Dropping the default keeps the null-means-"look it up"
--     contract honest.
--
-- Existing rows are intentionally left untouched.

BEGIN;

ALTER TABLE site.sites
    ALTER COLUMN is_published SET DEFAULT true;

ALTER TABLE core.customers
    ALTER COLUMN marketing_opt_in_cached DROP DEFAULT;

COMMIT;
