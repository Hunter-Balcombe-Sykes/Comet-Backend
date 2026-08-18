# Prod deploy runbook — overnight 2026-08-18 changes

Owner ruling R20: Fable writes the runbook, the owner runs it. Every step is
idempotent; run them in order after `development` → `main` is merged and the
production deployment has **succeeded** (schema first, code second — the
migrations below add a CHECK value and a media role the new code writes).

Prod Supabase ref: `edplucmvkcnokyygxqsb` (never the dev ref). Nothing here
touches env vars — no cloud env change was made during the run.

## 0. Before deploying code

```bash
# From Comet-Backend on main. Pushes 20260819000200 … 20260819001100
# (shop rehome drops, shop grandfather pins, link_observations commerce_probe,
# item_media role=video, f_catalog.collection_title). Review the pending list first.
supabase migration list --linked
supabase db push --linked
```

## 1. Deploy

Merge `development` → `main`, let Laravel Cloud deploy, confirm
`https://api.partna.au/api/health` → `{"ok":true}`.

## 2. One-off data steps (production environment, in this order)

```bash
# 2a. Legacy Google-photo records (pre-R6 name-keyed) → retired; their
#     record_state tombstoned so the next GB run does not resurrect them.
cloud command:run production --cmd="content:retire-legacy-gphoto-records --dry-run"
cloud command:run production --cmd="content:retire-legacy-gphoto-records"

# 2b. YouTube: F3 fixed handle→channel resolution; a stream that resolved a
#     handle BEFORE the fix may hold a wrong cached channel_id. Clear the
#     watch-stream cursors so the next run re-resolves (one extra request).
cloud tinker production
>>> DB::table('ingest.streams')->where('stream_name','watch')
      ->whereIn('source_id', DB::table('ingest.sources')->where('source_key','youtube')->select('id'))
      ->update(['cursor' => '{}']);

# 2c. Pool sections → current canonical shapes (media opt-in N=5, shop opt-in).
#     Reports hand-edited rules and leaves them alone.
cloud command:run production --cmd="content:reshape-pool-sections media --dry-run"
cloud command:run production --cmd="content:reshape-pool-sections media"
cloud command:run production --cmd="content:reshape-pool-sections shop --dry-run"
cloud command:run production --cmd="content:reshape-pool-sections shop"

# 2d. Paid connectors that became scheduler-eligible (R8 allow-list:
#     google_business, spotify, soundcloud) — flip auto_sync on for existing rows.
cloud command:run production --cmd="ingest:backfill-sources"

# 2e. Item caches that missed a projection refresh (X4). Stale-only.
cloud command:run production --cmd="content:refresh-item-caches --dry-run"
cloud command:run production --cmd="content:refresh-item-caches"

# 2f. Link cards still pending enrichment (F4 lineage). Also scheduled daily.
cloud command:run production --cmd="platforms:enrich-pending-cards --older-than=30"

# 2g. Listen restructure: track-only platforms' switch moved from
#     auto_sync_latest → auto_sync_latest_track (dev had 0 such rows; prod may).
cloud tinker production
>>> DB::update("UPDATE site.platform_connections
      SET display_settings = (display_settings - 'auto_sync_latest')
        || jsonb_build_object('auto_sync_latest_track', display_settings->'auto_sync_latest')
      WHERE platform IN ('spotify','soundcloud','youtube-music')
        AND jsonb_exists(display_settings, 'auto_sync_latest')");

# 2h. Listen restructure: reproject music so releases carry their format and
#     tracks their album (projector versions bumped) — per user, or all.
cloud command:run production --cmd="ingest:project"
```

`cloud command:run … --cmd` was flaky from Fable's shell tonight (hung >4 min,
then `command.failure` with no output); if it misbehaves, run the same
artisan commands from `cloud tinker production` via `Artisan::call(...)`.

## 3. Verify (production, read-only)

- `GET /api/platforms/{key}/refresh` still 429s on rapid repeat (cooldown).
- One YouTube source: `ingest.runs` shows a `schedule` run within the next
  `ingest:dispatch` tick and the cursor holds a `UC…` id.
- Media pool for an IG-connected user: selection = 5 newest, `origin=auto`.
- Services pool for a Fresha user: currency populated on new runs
  (`sources[].lastSyncedAt` on the dashboard wire, prices no longer `null`).
- Sitepage: a reel renders `<video>` (kind=video frame with poster).

## 4. Watch for 48h

- `ingest.anomalies` kind `delete_guard` — now reachable for Fresha and the
  three menu connectors (R18). A trip means an actor returned a suspiciously
  short catalogue; nothing is deleted while tripped, it self-clears when the
  population returns.
- Apify + Places spend against `partna.limits.*` caps (eager-on-connect runs
  for GB/spotify/soundcloud/instagram now fire on every new connection).
