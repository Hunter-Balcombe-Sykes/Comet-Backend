-- F12 (P2): csam_quarantine.case_id is an ON DELETE RESTRICT FK with no covering
-- index, so every moderation.cases delete triggers a seqscan of csam_quarantine
-- to check the constraint. Add the covering index.
--
-- Index file: CONCURRENTLY, no BEGIN/COMMIT (CONVENTIONS.md §1).

CREATE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_case_id_idx
    ON moderation.csam_quarantine (case_id);
