-- Slice 6 Task 1. Per-SOURCE aggregates for a connected Google place: the star
-- average, the review count, and Google's own prose review summary. These
-- describe the PLACE, not any one review, so they have no content.items row to
-- hang a facet on — this is the first source-level fact in content.*.
--
-- Why a new table rather than an existing lane: the `profile` stream that would
-- naturally own place-level facts projects to nothing (3 record_versions, 0
-- source_items on dev), and the field-bindings lane its ProjectorRegistry
-- docblock defers to was created by 20260728150000 and deliberately dropped by
-- 20260805110000 for never gaining a production caller. Writing these onto the
-- reviews stream instead keeps them off machinery slice 7 could legitimately
-- delete. See the design spec §5.2.
--
-- summary_text is Google-authored prose about the business, derived from
-- reviews. It is NOT redacted pre-claim — GoogleBusinessPayload::
-- stripThirdPartyPii removes `reviews` only and leaves rating/reviewCount/
-- reviewSummary untouched, and this table mirrors that precedent rather than
-- inventing a stricter one. It IS withheld from DSAR, same as the legacy
-- `reviewSummary` key. That asymmetry is deliberate (see ThirdPartyPii).
--
-- ON DELETE CASCADE from content.sources: these rows must not outlive the
-- source, and account-close erasure reaches them through the existing
-- content.sources -> core.users cascade chain.
--
-- NOT APPLIED by this task — the coordinator applies it to shared dev.
--
-- ROLLBACK: DROP TABLE IF EXISTS content.source_stats;

BEGIN;

CREATE TABLE IF NOT EXISTS content.source_stats (
    source_id    uuid PRIMARY KEY REFERENCES content.sources (id) ON DELETE CASCADE,
    rating_avg   double precision NULL,
    rating_count integer NULL,
    summary_text text NULL,
    updated_at   timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE content.source_stats IS
    'Source-level aggregates that describe the connected account/place itself rather than any one item — currently the Google Business star average, review count and Google-authored review summary. Written by the reviews stream (design spec 2026-08-12 §5.2). NOT reviewer PII: rating/count are business facts and summary_text is Google prose about the business, so none is redacted pre-claim, matching GoogleBusinessPayload::stripThirdPartyPii. summary_text IS withheld from DSAR.';

COMMIT;
