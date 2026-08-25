# Wire change — staff batch-build time budget (`POST /api/staff/builds/batch`)

## The PUBLIC wire is UNCHANGED

This endpoint sits inside the staff route group (`supabase.jwt`,
`require.email_verified`, `staff`, `require.aal2`, `throttle:staff`,
`staff.audit`) — no public or dashboard-owner surface reads or writes it. The
public profile payload, `/api/public/signup/build`, and every owner-facing
endpoint are untouched by this change.

## Why: `CACHE-2` ≡ `SCALE-7`

`batch()` loops synchronously over up to 500 CSV rows, calling
`PreAccountBuildService::requestBuild()` (a DB transaction plus a job dispatch)
once per row. 500 rows will outrun the HTTP request timeout, and a timed-out
request returns staff **nothing** — not even a count of what landed before the
timeout killed the connection. There was no way to tell which rows to
re-upload.

## What changed

The loop now checks a wall-clock budget before starting each row (never before
the first — a batch always makes forward progress) and stops rather than
risking the timeout. `PreAccountBuildController::batch()` in
`app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php`.

New config: `partna.pre_account.batch_time_budget_seconds`
(`PARTNA_PRE_ACCOUNT_BATCH_TIME_BUDGET_SECONDS`, default `20`). Sized well
under the request ceiling because the check runs *before* a row, so the worst
case is budget + one row's duration. `0` processes exactly one row then stops
— that is also the test seam (no clock abstraction; `microtime(true)` is the
house pattern already used by `HealthController` and
`IndividualProfileController::show`).

### Response — four new root keys, purely additive

`built`, `reused`, `failed` and `truncated` keep their existing names, types
and meanings unchanged. `$this->success()` has no envelope, so all keys sit at
the JSON root.

| Key | Type | Semantics |
|---|---|---|
| `total` | int | Rows in the attempt set — parsed CSV data rows **after** the existing 500-row cap slice. Invariant: `processed + remaining === total`. |
| `processed` | int | Rows the loop actually attempted: successes, `reused`, and every `failed[]` entry including `INVALID_EMAIL`. |
| `remaining` | int | `total - processed`. `0` on a run that finished the whole file. |
| `time_budget_exceeded` | bool | `true` only when the loop stopped on the budget rather than running out of rows. |

`remaining` deliberately **excludes** rows discarded by the pre-existing
500-row cap — `truncated` already reports those, and folding them into
`remaining` would double-count the same rows two ways.

## Why a partial return is safe

`PreAccountBuildService::requestBuild()` dedupes on the live source ref
*before* the pairing map (spec §4.1, `docs/superpowers/specs/2026-07-18-pre-account-sites-design.md`).
Re-uploading the same CSV — including rows already built — re-serves anything
that landed as `reused` rather than creating a duplicate or erroring. So the
safe recovery from a partial batch is simply: re-upload the file (or the
remaining tail) as-is.

## Per-row failure handling

Row-level failure handling is otherwise unchanged: a `PreAccountBuildException`
(bad source/pairing) is caught and reported per-row with its `errorCode`. This
change adds one more arm — `catch (\Throwable $e)` — so a row that throws
something *other* than `PreAccountBuildException` (a DB constraint violation,
subdomain exhaustion, an unrelated bug) is also collected as a failed row
(`code: 'ROW_FAILED'`, a generic message that leaks nothing) instead of
aborting the whole upload and discarding every row that already succeeded.
The exception is still sent to `report()` (the house pattern —
`app/Ingest/Runtime/RunExecutor.php:128`, `EffectLedger.php:251`) so Nightwatch
still sees it.

## Schema

None. No migration, no new table or column.

## Not done

No business logic moved to a Service. The loop is pre-existing controller code
and this change is additive to it; extracting a runner would drag
`parseCsv()` along for no benefit here.

## Verified with

- `tests/Feature/PreAccount/StaffBatchBuildEndpointTest.php`:
  - `it builds one row per CSV line and reports the summary` (existing, extended
    with the four new key assertions)
  - `it stops on the time budget and reports where it got to` (budget=0, proves
    `processed`/`remaining`/`time_budget_exceeded` via `Queue::assertPushed`,
    i.e. rows past the budget were never started, not merely uncounted)
  - `it records a non-PreAccountBuildException row as failed and continues`
    (mutation-tested: deleting the `catch (\Throwable $e)` arm turns this test
    red with a 500)
