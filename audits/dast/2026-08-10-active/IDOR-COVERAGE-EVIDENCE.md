# IDOR coverage — positive transcript, 2026-08-10

`IDOR assertions passed` is the **absence** of a `Difference in response code values`
line, and absence is exactly the failure shape this lane exists to eliminate — it is
what let the lane report PASS while completely unauthenticated for months
(`71f027b11`). `audits/dast/2026-08-09-active/IDOR-INVERSION-EVIDENCE.md` answered
that by inverting every expectation so ZAP printed each real status code.

This run used a **different method for the same purpose**, and it is cheaper: fire
every control and probe directly against the running lane, before ZAP. Same positive
transcript, but it takes seconds instead of a full plan, so it catches a bad fixture
up front rather than at the end of a multi-hour run. Both methods are kept; neither
replaces the other (the inversion transcript proves the *gate* is live, this proves
the *surfaces* are).

## Method

With `bring-up.sh` up and `seed-identities.php` run, for each row of
`IDOR_SURFACES`:

- **probe** — identity A's token against **identity B's** resource → expect `404`
- **control** — identity A's token against **its own** equivalent → expect `200`

The probe is fired **before** the control, deliberately: several controls are
destructive `DELETE`s that consume their fixture, so probing first means a control
that eats its row cannot mask a broken probe.

## Result — 29/29 controls `200`, 29/29 probes `404`

| Surface | Verb | Control | Probe |
|---|---|---|---|
| customer | GET | 200 | 404 |
| enquiry-read | POST | 200 | 404 |
| enquiry-spam | POST | 200 | 404 |
| gallery-image | DELETE | 200 | 404 |
| upload-image | DELETE | 200 | 404 |
| document | DELETE | 200 | 404 |
| content-upload | DELETE | 200 | 404 |
| service | GET | 200 | 404 |
| service-category | GET | 200 | 404 |
| section | GET | 200 | 404 |
| section-items | GET | 200 | 404 |
| section-groups | GET | 200 | 404 |
| section-trace | GET | 200 | 404 |
| page | DELETE | 200 | 404 |
| feedback | GET | 200 | 404 |
| restyle-undo | POST | 200 | 404 |
| notification-read | POST | 200 | 404 |
| content-item | DELETE | 200 | 404 |
| pool-deselect | DELETE | 200 | 404 |
| item-link | DELETE | 200 | 404 |
| item-override | DELETE | 200 | 404 |
| routing-primary | POST | 200 | 404 |
| routing-suggestion-dismiss | POST | 200 | 404 |
| section-item-upsert | PUT | 200 | 404 |
| **platform-account** | DELETE | 200 | 404 |
| **platform-events-account** | DELETE | 200 | 404 |
| **platform-events-event** | DELETE | 200 | 404 |
| **platform-custom-link** | DELETE | 200 | 404 |
| **platform-custom-event** | DELETE | 200 | 404 |

The five bold rows are the `api/platforms/*` deciders added 2026-08-10.

**`section-item-upsert` needed a second pass, and the reason is worth recording.**
The first attempt reported `422` for both directions. That was the *harness*, not the
lane: the throwaway extractor read the surface table out of the shell **source** with
`sed`, so the body field arrived as `{\"state\":\"excluded\"}` — with literal
backslashes, which is invalid JSON, so Laravel's `FormRequest` rejected it before the
controller ran. Re-running with the array `eval`'d the way `zap-active.sh` does it —
letting **bash** resolve `\"` to `"` — gave `{"state":"excluded"}` and the expected
`200`/`404`. A tool that reads a bash array by pattern-matching its source text is
reading a different value from the one bash will use.

## Conditions this transcript was taken under

- The served app connected as **`app_backend`**, the restricted runtime role, not the
  `postgres` superuser. `bring-up.sh` Step 6b asserted `current_user = app_backend`
  from a live connection first.
- **Seeding also ran as `app_backend`** and completed with no grant failures across
  `core`, `site`, `content`, `notifications` and `routing` — so no missing-`GRANT`
  finding came out of this change. That is a result, not an absence: the seeder does
  raw inserts into exactly the tables the app writes in prod.

## Corroborating gates from the same run

```
zap-active: exclusion check passed — no excluded path in the run log beyond the 29 declared IDOR probes
zap-active: plan integrity check passed — ZAP recognised every parameter in the plan
zap-active: IDOR request count check passed — 58/58 requests issued
zap-active: IDOR assertions passed — foreign resources 404'd and every control returned 200
diff-baseline: clean — no new findings at/above --fail-on=high
```

**The exclusion filter was verified to be doing real work, not passing vacuously.**
Against this run's own log: **20** excluded-path hits without the
`grep -vFf idor-urls.txt` filter — which is precisely the trap that would have killed
the lane after a multi-hour wait — and **0** with it. So the filter removes exactly
the declared probe URLs and nothing else was hiding behind them.
