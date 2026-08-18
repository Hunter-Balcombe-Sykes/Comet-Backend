# Schema / RLS / search_path Audit — 2026-08-18

**Branch:** audit-fix/instagram-wave-findings-2026-08-18
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260819001000_link_observations_allow_commerce_probe.sql
- supabase/migrations/20260819001100_item_media_role_video.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

Both draft findings quote real SQL from their respective migrations verbatim — `link_observations_source_check` and `item_media_role_check` are indeed dropped and re-added in a single `DROP CONSTRAINT` / `ADD CONSTRAINT` step without a `NOT VALID` + `VALIDATE CONSTRAINT` split, so the underlying technical observation is accurate.

However, both drafts carry confidence below 0.7 (0.6 and 0.55), and neither rises to a real security or data-integrity issue: `routing.link_observations` and `content.item_media` are pre-pilot tables with negligible row counts today (no customer data yet per project status), so the practical cost of a brief `ACCESS EXCLUSIVE` validation scan during this specific deploy is minimal — this is deploy-operational hygiene, not a scenario that ships bad behavior to a real user today. Per the always-drop calibration for borderline sub-0.7 findings that aren't concrete security/data issues, both are dropped. The underlying pattern (favor `NOT VALID` + later `VALIDATE CONSTRAINT` for CHECK widenings on tables expected to carry live traffic) is still worth keeping in mind for future migrations against these tables once they carry real volume, but it doesn't clear the bar for a standing finding at current scale.

No additional schema-side findings (RLS, search_path, index hygiene, trigger correctness, UUID/PK, JSONB, append-only) were identified in the two files in scope — both are narrowly-scoped `CHECK` constraint widenings with no other schema surface touched.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.
