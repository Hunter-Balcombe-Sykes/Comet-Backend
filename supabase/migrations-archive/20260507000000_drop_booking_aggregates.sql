-- CACHE-1 / CACHE-2: drop booking aggregate tables; live queries against
-- analytics.booking_events (indexed on professional_id, occurred_at DESC) are
-- the only read path in BookingAnalyticsController::myOverview() going forward.
-- No replacement trigger is needed — the live-query path is sufficient at scale.
-- No IF EXISTS: fail loudly on schema drift.

BEGIN;

DROP TABLE analytics.booking_metrics_daily,
           analytics.booking_metrics_hourly;

COMMIT;
