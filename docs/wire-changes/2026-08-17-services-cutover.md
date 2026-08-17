# Wire changes — services cutover

## 2026-08-17 · KV render payload: `services[].id` domain change (dev)

`site.public_site_payload`'s `services` key now composes from `content.*`
(migration `20260817000000`). Each entry's `id` is the `content.items` id,
where the pre-image emitted the `site.services` id. The public API
(`GET /api/public/profiles/{handle}`) has emitted content ids since slice 3a,
so KV and API now agree.

Verified at apply time on dev (`glncumufgaqcmqhzwrxm`), re-run rather than cited:

- **Content is unchanged.** The `services` key was snapshotted across all 22
  published sites before and after the apply; counts and titles are identical
  element-for-element. Only the id domain moved. Three sites carry services
  (`loadtest` 15, `ollies` 2, `broken-oven` 1); the other 19 emit `[]`.
- **Ids are content-domain.** Every emitted `services[].id` resolves in
  `content.items` and none resolves in `site.services`.
- **Both surfaces agree.** For `ollies`, the cached render payload
  (`SiteCacheService::getPublicSitePayload`) and the public profiles API return
  the same two ids — `ec5cdf51-…` (Hair cut) and `d77155c7-…` (fsdf) — with
  matching price, duration and description.
- **The view is off the legacy tables.** The `pg_depend` rewrite query returns
  zero rows for `site.services`, `site.service_categories` and
  `site.service_category_assignments`, which is Unit 1's acceptance criterion
  and unblocks the DROP unit.

Cache: the payload cache is keyed per subdomain and holds the pre-image ids
until busted, so all 22 published sites were invalidated
(`SiteCacheService::invalidateSitePayload`, which clears both the primary and
`:stale` SWR keys) and re-warmed at apply time. Note that
`SyncSubdomainToKvJob` writes only the `{type:"individual"}` routing pointer —
it does not carry the payload — so a KV re-sync is not what refreshes this key.

### Not changed by this migration

The view has no *section*-level gate, and never had one: the pre-image filtered
`sv.is_active` (the service row's flag), not the site's `services` section
block. The profiles API does gate on the section via `sectionEnvelope`. So a
site whose `services` section is `is_active = false` emits services from the
view while the API returns `[]` — `broken-oven` is in exactly this state today.
This divergence pre-dates the migration and is unaffected by it, but it is the
one place where "KV and API agree" does not hold, and it is worth settling
before the DROP unit.
