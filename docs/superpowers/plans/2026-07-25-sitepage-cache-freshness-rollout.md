# Sitepage cache freshness — rollout

Three deploy surfaces, three separate actions. The Laravel changes are safe on
their own; the Worker change is only *useful* once the Laravel changes are live.

## 1. Laravel (backend)

Ships with `development`. No migration, no config to set — `resolve_floor_ttl`
(600) and `purge_followup_schedule` ([120, 300, 900]) have code defaults.

Removed: `PARTNA_CACHE_PURGE_FOLLOWUP_SECONDS`. It is not set in any environment
(verified against `.env.example`), but if it has been set by hand on
`development` or `production`, it is now dead — remove it via
`cloud environment:get <env> --json --fields=environmentVariables` to confirm,
then unset.

## 2. Cloudflare Worker

Does **not** ship with the Laravel deploy. Needs its own:

```bash
cd cloudflare-worker && wrangler deploy
```

## 3. Frontend (PartnaAu/partna-frontend)

One line, applied separately in that repo — `app/(app)/account/(dashboard)/design/page.tsx:96`:

```diff
-<iframe key={bump} src={url} title="Live preview of your site" className="size-full" />
+<iframe key={bump} src={`${url}?preview=1`} title="Live preview of your site" className="size-full" />
```

The concat is safe: `sitepageUrl()` never emits a query string. When a custom
domain is primary and active it returns the custom domain — covered, because
custom domains route through the same `serveIndividual()` where the bypass lives.

The 900ms debounce and 400ms `MIN_GAP_MS` stay as they are. Once both the edge
and the API are guaranteed fresh, ~1.5s is a correct reload point.

## Verification

Change a `design_kits` column for a test handle, then poll both layers:

```bash
# origin (bypasses the edge) vs the edge, same page
curl -sS "https://<handle>.partna.au/?architecture=staple" | grep -oE "\-\-dk-border-radius:[^;]*"
curl -sS -D- -o /dev/null "https://<handle>.partna.au/" | grep -i "x-partna-cache"
```

**Success criteria:** after a save, the origin reflects the change on the *next*
request — no 30s window — and the edge reflects it within one purge cycle with no
stale re-pin. The failure signature to watch for is the one from the diagnosis:
edge EVICTED at ~+4s, then HIT again at ~+8s still serving the old value.

Also confirm `?preview=1` returns `cache-control: no-store`:

```bash
curl -sS -D- -o /dev/null "https://<handle>.partna.au/?preview=1" | grep -iE "cache-control|x-partna-cache"
```

## Known gap, accepted not fixed

The floor covers the **timestamp** variant of the resolve race. It does not cover
the `['not_found' => true]` variant — a stale-set can re-install that, and the
controller 404s before reaching the `max()`. That matters for first publish/claim
(a just-published site can 404 briefly and the edge could pin that render), not
for design edits on an existing site. The follow-up purge schedule is the rescue.
