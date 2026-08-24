# Full-suite status — P1 sweep, branch `audit-fix/p1-sweep-2026-08-24`

## Final: GREEN

```
Tests:  1 warning, 3 skipped, 9051 passed (32130 assertions)
Duration: 617.70s
```
`COMPOSER_PROCESS_TIMEOUT=0 composer test`, run at the end of the sweep against the
final tree. The 3 skips and 1 warning are pre-existing and unrelated to this work.

## Gates

| Gate | Result |
|---|---|
| `vendor/bin/pint --test` | passed |
| `composer analyse` (PHPStan L5, 1407 files) | No errors |
| `composer guard:no-unsafe-migrations` | passed |
| `tests/Postgres/` lane (disposable `postgres:16`, `PG_LANE_REQUIRED=1`) | 8 passed (35 assertions) |

## The first full-suite run was RED — worth knowing why

Run 1 ended `1 failed, 9050 passed`. The failure was
`tests/Feature/Architecture/TestSuiteProcessHygieneTest`: the new
`tests/Postgres/UnifiedActionsLegacyScrubTest.php` declared top-level helpers
(`seedActionEvent`, `seedSiteWithSettings`, `seedScoreRow`) that collide with
declarations in `tests/Feature/Analytics/ActionScorerTest.php`,
`tests/Feature/Account/AccountDeletionPurgeActionEventsPiiTest.php` and
`tests/Feature/Console/PurgeRawAnalyticsEventsCommandTest.php`.

Unit 7's independent review **spotted this exact risk and reasoned it away** — correctly,
as far as runtime goes: `tests/Postgres/` runs under `phpunit.pg.xml`, which never loads
`tests/Feature/`, so there is no redeclare fatal. What it missed is that
`TestSuiteProcessHygieneTest` scans **every** test file on disk regardless of lane,
precisely because a lane boundary is not a guarantee worth relying on.

Fixed by prefixing all three helpers `uals*`. The PG lane was re-run after the rename
(8 passed) to confirm the rename did not break it, then the whole suite re-run clean.

## The pattern behind both of this run's escapes

This is the **second** time a per-unit review passed while a repo-wide guard would have
failed. The first was unit 2's PHPStan regression (`8956839f9`), which slipped because my
review brief for that unit omitted `composer analyse`.

Per-unit test selection systematically under-covers **architecture guards**, because those
guards live nowhere near the code being changed and are not discoverable from the diff.
For a future run of this shape, the per-unit review brief should include, unconditionally:

```
vendor/bin/pint --test
composer analyse
php artisan test tests/Feature/Architecture
```

That last line is the cheap one that would have caught both.
