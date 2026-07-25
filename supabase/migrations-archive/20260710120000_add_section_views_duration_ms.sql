-- Per-page dwell (V1 cleanup signal): cumulative visible-time in milliseconds,
-- annotated onto the section impression row by POST /public/analytics/section-dwell.
-- NULL = never reported (legacy rows / tab killed before the leave beacon / old
-- clients). The writer GREATEST-merges cumulative client reports (ping's idempotency
-- pattern), and the popularity scoring job reads SUM(duration_ms) per section_key
-- into the page score's dwell term.
ALTER TABLE analytics.section_views
    ADD COLUMN IF NOT EXISTS duration_ms integer NULL;

COMMENT ON COLUMN analytics.section_views.duration_ms IS
    'Cumulative visible-time (ms) from section-dwell beacons; GREATEST-merged, capped at 600000. NULL = no dwell reported.';
