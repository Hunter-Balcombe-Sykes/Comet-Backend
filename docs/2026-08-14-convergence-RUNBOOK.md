# Platform & Pool Convergence — RUNBOOK

Autonomous overnight execution, 2026-08-14. Owner asleep; full authority
granted including merge and deploy to dev.

**Goal:** the backend runs entirely on `content.*` + `app/Ingest`. Every legacy
parallel store retired, every declared-but-unbuilt seam deleted. One write path
and one read path per content type.

**Spec:** `docs/2026-08-14-platform-and-pool-convergence-scope.md` (verified
against dev, not inherited from older plans).

---

## How this run works

These conventions are chosen for *this* work — autonomous, backend, database-
mutating, unattended. They deliberately do not follow the repo's existing
frontend-oriented run conventions.

**1. Inline and sequential. No subagents.**
The work is serialized by four small shared files — `ConnectorRegistry`,
`ProjectorRegistry`, `KindRegistry`, `PoolRegistry` — which nearly every phase
edits. Parallel agents would contend on them, buying no wall-clock and adding
coordination failure modes nobody is awake to referee.

**2. Feature branch in the main checkout, not a worktree.**
`feat/platform-pool-convergence` off `origin/development`. A worktree would
need `.env` recreated; the working DB credentials live here and re-deriving
them at 3am is a self-inflicted outage.

**3. The commit is the unit of recovery.**
Every commit: tests green, `pint` clean, one coherent change, reasoning in the
message. Anything can be undone with `git revert <sha>` plus, where relevant, a
migration rollback.

**4. Verification is evidence, never assertion.**
- Behaviour being *added* → failing test first, then implementation.
- Data being *moved* → SQL assertion against dev before and after, counts compared.
- Anything touching a writer → dry-run or live assertion. **Green Pest on
  SQLite proves nothing about Postgres.**
- Between phases: full suite (`php artisan test`).

**5. Write state down as it happens.**
`docs/convergence-log.md` — appended at every phase boundary and every decision.
Context will compact overnight; the file is what survives. Decisions get logged
*before* acting on them.

**6. Stop rules.**
- Phase 0/1/2 fail → **halt**. They are foundational; later phases built on a
  broken identity layer would produce wrong data that looks right.
- Phase 3+ fail → log `docs/blocked-<phase>-<ts>.md`, skip to the next phase
  with no dependency on it, continue.
- Anything that would exceed the Apify cap → log as a finding, do not spend.
- Phase 7 dump gate fails → do not drop anything, full stop on that phase.

**7. Incidental issues.** Fix them when they are real, small, and in the lane
being touched. Verify the fix is needed before making it (two of the four
Nightwatch exceptions inspected so far were already fixed in code). Log each in
the decision file with why.

---

## Hard constraints

### Scope
- **Backend only.** Never edit `partna-monorepo/apps/dashboard` or
  `.../apps/pages`. Wire changes are expected — record them in
  `docs/wire-changes/`.
- **Production is never touched.** Dev = `glncumufgaqcmqhzwrxm`.
  Prod = `edplucmvkcnokyygxqsb`; no tool call may name it.

### Environment traps (each already cost a failed command today)
- `pg_dump` is **not on PATH** → `/opt/homebrew/opt/libpq/bin/pg_dump`
- DB password must come from Laravel config, never shell-parsed from `.env`
  (shell parsing auth-failed while Laravel connected fine):
  ```bash
  PGPASSWORD=$(php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("database.connections.pgsql.password");')
  ```
- No `timeout(1)` on this machine — use the tool's own timeout.
- After any `app/Catalog/Definitions/*` change:
  `php artisan catalog:compile && php artisan routing:corpus`, then
  `./vendor/bin/pint` on the generated artefacts, or the diff is thousands of
  formatting-only lines.

### Money
Apify hard cap **US$18**. Baseline $2.75 of $29 used. Check before each batch:
```bash
curl -s -H "Authorization: Bearer $APIFY_TOKEN" https://api.apify.com/v2/users/me/usage/monthly
```
The ~$8 left over is deliberate: burning the cycle silently stops dev's
scheduled ingest and presents as a broken pipeline.

---

## Baseline (dev, 2026-08-14 — assert against these)

| Metric | Value |
|---|---|
| `content.items` | 707 |
| `content.identity_keys` | 1264 — `platform_object` 707 + `canonical_url` 557 (**2 of 17 key classes**) |
| `content.item_merges` | 0 — merge engine has never run |
| `content.f_embed` | 141 (video: youtube 73, vimeo 59 · channel: twitch 4, spotify 4, soundcloud 1) |
| `content.f_channel` | 9 |
| `content.item_links` | 1 |
| `content.collections` | 25 |
| `ingest.sources` | 50 across 14 keys; **7 connectors never provisioned** |
| `site.menu_items` | 318 |
| `site.item_slugs` (menu_item) | 318, **0 retired** |
| `site.content_selection` | 95 |
| `site.shop_products` / `site.services` / `site.workplaces` | 51 / 82 / 11 |
| live `partna.*` connections | 41 |
| `content.source_items` kind=`menu_item` | **0** — `MenuItemProjector` has never run |

---

## Locked decisions

1. Substack demoted; `article` kind deleted.
2. Gumroad demoted to link-only.
3. `channel` kind + `ChannelCardProjector` + `f_channel` deleted.
4. `document` kind deleted.
5. YouTube Music **kept and provisioned** — free, keyless RSS, the only real
   `track` producer.
6. Spotify/SoundCloud → Apify track sourcing; channel projectors deleted.
7. `ingest:backfill-sources` run — the end-to-end proof.
8. Migrations applied straight to dev.
9. Merge + deploy permitted (dev only).
10. Blocked → log and skip.
11. Twitch de-sourced — do not re-investigate.
12. `commerce_probe` constraint bug fixed in this run.

`profile_fields` is **deleted as a seam**, not built: `IdentitySync` remains the
identity implementation and `site.workplaces` is NOT legacy — it is the identity
store, and there is only one of it.

---

## Phases

Each is gated on evidence, not assertion.

**0 — Baseline.** Branch created; full suite run and counts recorded; baseline
table above re-confirmed.

**1 — Lean out + `commerce_probe` fix.** Demote twitch/skool/strava/gumroad/
substack. Delete `channel`/`article`/`document` kinds, `ChannelCardProjector`,
the `profile_fields` seam and its three `profile` streams. Add `commerce_probe`
to `source_intents_origin_check`.
*Exit:* suite green; kinds gone from `KindRegistry`; a real `CommerceProbeJob`
intent write succeeds on dev.

**2 — Identity keys.** Emit the missing `KeyClass` values (`Isrc`,
`TitleDuration`, `TitleRelease`, `OfferingName*`, `Gtin14`, `FeedGuid`,
`EnclosureUrl`, `ContentDigest`). Exercise the never-run merge engine.
*Exit:* >2 key classes present on dev after `ingest:project --rebuild`; merges
reviewed row-by-row, not just counted.

**3 — Pools: menu + links.** `PoolRegistry` gains both. Migrate 318 slugs to
`content.item_slugs` (0 retired today — cheapest it will ever be). Re-home slug
allocation off `MenuItemObserver`. Move the 41 `partna.*` connections into the
links pool.
*Exit:* both pools return via `/content/pools/{pool}`; slug count preserved.

**4 — Listen sourcing.** Provision `youtube_music`. Select and validate Apify
actors for Spotify/SoundCloud; write connectors + `track` projectors; delete the
channel projectors.
*Exit:* `track` rows exist; cross-platform duplicates merged via Phase 2 keys.

**5 — Live verification (spends money).** `ingest:backfill-sources`, dispatch,
project. Prove scraped content reaches pools.
*Exit:* `content.source_items` kind=`menu_item` > 0; menu pool returns them;
spend within cap.

**6 — Pseudo-platform retirement.** Six categories stop being connectable;
`PlatformCategory` remains as grouping. Promote `uber_eats`/`doordash`/`menulog`
to real surfaces. Gating unaffected — `RoutingCapabilityGate` keys on
`routing_class`.
*Exit:* no new `partna.*` connections possible; existing migrated.

**7 — Cutover + teardown (gated).**
- 7a `pg_dump` every table to be dropped → `docs/teardown-backup-<ts>.sql`
- 7b **GATE:** dumped row count == live row count, per table. Verified working
  today (318/44/402 exact). Fails → nothing is dropped.
- 7c Flip reads off legacy; drop parallel `site.*` item tables; close the
  `site.content_selection` write path.

**8 — Documentation truth pass.** Rewrite root + backend `CLAUDE.md`; correct
the convergence spec's stale figures; `docs/wire-changes/` entries for every
contract change; record access/logistics (Supabase MCP ids, `cloud env:logs`,
tinker, `pg_dump` path, Nightwatch) so this research is never repeated.

---

## Morning report

Written to the top of `docs/convergence-log.md`: what shipped, what was verified
and with what evidence, what is open, what was skipped and why, Apify spend.
