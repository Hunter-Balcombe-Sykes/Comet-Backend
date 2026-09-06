-- setup-timing.sql — Get Started rebuild, file 00 pre-flight (Task 2).
--
-- Plain-SQL equivalent of `php artisan setup:timing {user}`'s raw read, for
-- use through the Supabase MCP when artisan isn't at hand. Returns the FULL,
-- uncapped, ordered event history for a user's LATEST core.pre_account_builds
-- row — the same query `SetupTimingCommand::loadRows()` runs, and the same
-- one `SetupPayload::openStages()` reads (app/Services/Setup/SetupPayload.php).
-- Never `BuildProgressReader::events()` for this: it caps at 50 rows and is
-- built for the live signup poll, not an accurate timing read.
--
-- Replace :user_id below with the target user's core.users.id (or inline a
-- lookup: `(select id from core.users where handle = 'the-handle')`).

select
    e.stage,
    e.status,
    e.label,
    e.payload,
    e.created_at,
    e.id
from core.pre_account_build_events e
where e.build_id = (
    select b.id
    from core.pre_account_builds b
    where b.user_id = :user_id
    order by b.created_at desc
    limit 1
)
-- created_at is only second-resolution; the ordered-UUID id breaks ties in
-- write order (two rows landing in the same wall-clock second — a socials
-- sync logging STARTED and LANDED inside one — would otherwise come back in
-- whatever order Postgres happens to find them, per SetupPayload::openStages()).
order by e.created_at asc, e.id asc;

-- PAIRING LOGIC — applied BY HAND when reading this output, since a plain
-- SQL query cannot express "next matching row" cheaply. Same rule the
-- artisan command implements (see BuildProgress's docblock,
-- app/Services/PreAccount/BuildProgress.php):
--
--   1. A row's pairing KEY is `stage` if `payload->>'token'` is null/empty,
--      else `stage` + that token value. Two rows are the same "head" only
--      if their key matches exactly.
--
--   2. Walk the rows top to bottom (this query's order). For each key, the
--      first `status = 'started'` row you see OPENS a pair; the next
--      `status in ('landed','skipped','failed')` row with the SAME key
--      CLOSES it (FIFO — if a key has two unanswered 'started' rows in a
--      row, that's a reader-visible anomaly, not a second pair to open).
--      elapsed = closed.created_at - started.created_at.
--
--   3. A 'started' row with no later terminal row of the same key by the
--      time you finish reading is STILL OPEN — that stage/token hasn't
--      answered yet.
--
--   4. A terminal row with no earlier 'started' row of the same key is an
--      ORPHAN close (a one-shot producer that only ever logs the landed
--      row) — record it with no start time rather than guessing one.
--
-- "identity landed" = the created_at of the first row where
-- stage = 'identity' and status = 'landed', minus the build's own
-- created_at (core.pre_account_builds.created_at) — not the first event's
-- timestamp, matching the "seconds_since_created" telemetry convention
-- PreAccountBuild::observeTierMarkers() already logs by.
--
-- "all platforms.* ready" = the LATEST closed.created_at among every pair
-- whose stage is one of platforms|listing|website|connect|verify, minus the
-- build's created_at — but only once EVERY such pair has closed (any one
-- still open means "not ready yet"). A stage name in that list that never
-- appears in the output at all contributes nothing — it is not an error,
-- just nothing to time.
