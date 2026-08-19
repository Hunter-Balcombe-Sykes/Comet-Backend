# `missing_key` refresh failures — router-created connections (2026-08-19)

Repo: `Comet-Backend` only. No frontend change required — the dashboard badge
is a faithful read of `last_refresh_status`; fix the data and the badge clears.

## Root cause (proven, not inferred)

A connection's payload has two authors that disagree on key names.

- **Writer** — `app/Routing/ConnectionPayload.php:43-45`. When the surface's
  `identifier_kind` is `handle`, the router stores the resolved identity as
  **`username`**. Full payload is `{url, source, username}`.
- **Reader** — `app/Services/Platforms/Strategies/Fetch/YoutubeFetch.php:20-23`
  reads **`payload['handle']`** and throws
  `FetchShapeException('missing_key: handle')` when absent.

So every router-created YouTube connection fails the first time the LEGACY
refresh lane touches it. Evidence: all 4 live YouTube connections carrying
`handle` (old connect flow, `source: null`) are `ok`; all 3 in `error` are
router-created carrying `username`.

### The latch — why it never self-heals

The NEW ingest lane is healthy for these rows. gsnwilliams' `ingest.sources`
row (identifier `dvlpmnttv`) reads `health: ok`, `consecutive_failures: 0`, and
its run returned `outcome: ok`, `{"streams":{"watch":"ok"}}`. Two video items
landed at `last_seen 2026-08-18 15:24:02`.

But `IngestStatusWriteback::afterRun()` (`app/Ingest/Runtime/IngestStatus
Writeback.php:51`) only moves rows at `pending` / `action_needed`. Once the
legacy lane stamps `error`, a SUCCESSFUL ingest run can never clear it. Success
and failure are recorded on the same row and the failure wins permanently.

### The circuit breaker — why a code fix alone changes nothing

`scopeDueForRefresh` requires `consecutive_failures < max_consecutive_failures`
(`IntegrationConnection.php:341`, cap = 10 at `config/partna.php:1889`). FIVE of
the six affected rows are already AT 10. The cron will never select them again,
so shipping the code fix without a backfill fixes nothing for them.

## Scope — smaller than it first looks

Six rows, but only TWO real accounts, with DIFFERENT defects:

| account | platform | payload | defect |
|---|---|---|---|
| `gsnwilliams` | youtube | `username: "dvlpmnttv"` | **A** — key mismatch only. Clean data. |
| `kebab-acai-kingz-melbourne` | youtube | `username: ""` | **B** — router resolved an EMPTY identifier. URL was `…/@dvlpmnttv?si=…`. |
| `showcase-creator` ×4 | youtube, vimeo, apple-music, apple-podcast | `url: "vimeo"`, `url: "1419227"`, … | **C** — malformed demo seed data, not a customer bug. |

Defect A is the one worth shipping. B and C are separate and are NOT fixed by
the same change — do not conflate them.

## Phase 1 — Defect A: the key mismatch

1. `YoutubeFetch::fetch()` — accept `payload['username']` as a fallback when
   `handle` is absent. Fix at the READER, not by making the router write both
   keys: the router is the newer, canonical writer and `ConnectionPayload`'s
   docblock is explicit that its key set is a contract with
   `PublicIntegrationConnectionResource::ALLOWLIST` and the sitepage renderer.
   Widening it to satisfy a legacy reader pushes the compatibility shim into
   the wrong layer.
2. Guard the fallback: it must be a non-empty string and must not contain `/`
   (mirrors the `identifier_kind === 'handle'` test the writer already applies).
   An empty `username` must still throw — that is defect B, and silently
   accepting `""` would turn a loud failure into a confusing vendor error.
3. Test: a router-shaped payload (`{url, source, username}`) fetches; a payload
   with neither key still throws `missing_key: handle`; an empty `username`
   still throws.

## Phase 2 — backfill (REQUIRED, not optional)

4. Reset `last_refresh_status`, `last_refresh_error` and `consecutive_failures`
   on the rows Phase 1 actually repairs — i.e. router-created YouTube rows with
   a non-empty `username`. Today that is gsnwilliams only.
   - Do NOT blanket-reset every `missing_key` row: defects B and C are not
     fixed by Phase 1, so resetting them just replays the same failure and
     walks them back up to 10.
   - Reset to `pending` (not `ok`) so the next run reports the real outcome
     rather than asserting a health nobody verified.
5. Verify: gsnwilliams' connection returns to `ok` after one refresh cycle, and
   the sheet's "needs attention" badge and the sync alert both clear.

## Phase 3 — Defect B (separate investigation, do not bundle)

6. Find why the router resolved an EMPTY identifier for
   `kebab-acai-kingz-melbourne` when the URL plainly contains `@dvlpmnttv`.
   Prime suspect is the `?si=…` tracking parameter defeating handle extraction
   — same URL without the param resolved fine for gsnwilliams. Start at the
   surface's identifier resolver, not at the fetch strategy: this is a WRITE
   defect, and the `missing_key` error is only its symptom.
7. Whatever the cause, an empty `username` should never be persisted — the
   writer should refuse it rather than storing `""`.

## Phase 4 — Defect C (decide, then act)

8. `showcase-creator`'s four rows carry junk (`url: "vimeo"`,
   `url: "1419227"` for a URL field). These are demo payloads, so the honest
   fix is to RESEED or delete them, not to teach four fetch strategies to
   tolerate malformed input.
   OPEN QUESTION for the owner: reseed the showcase account, or delete the four
   connections outright?
9. Note the other strategies are NOT one-line fallbacks even if we wanted them:
   `VimeoFetch` needs an `apiPath` and `YoutubeMusicFetch` a `channelId`, and
   the router writes neither — those would need derivation, which is real work
   for zero real users.

## Known architectural issue (flagged, NOT in scope)

The latch above is the actual systemic defect: two lanes write one column, and
the stale loser wins forever. `IngestStatusWriteback`'s narrowness is a
deliberate guard against two writers fighting, so widening it is not a casual
change. Worth its own decision — but Phases 1-2 do not depend on it, and this
plan does not touch it.

## Verification

- `php artisan test --filter=Youtube`
- Tinker: build a router-shaped payload and confirm `YoutubeFetch` resolves it
- DB after backfill: gsnwilliams' row shows `last_refresh_status='ok'`,
  `consecutive_failures=0`
- Dashboard: YouTube sheet shows "Last synced …", no warning Alert

## Out of scope

- Any dashboard/Astro change — the badge is a correct read of the data.
- Fixing the `IngestStatusWriteback` latch.
- Teaching vimeo / apple-music / apple-podcast strategies to accept router
  payloads (no real users affected).
