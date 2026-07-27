# Content & Platform Rebuild — Execution Log

Companion to `2026-07-27-content-platform-rebuild.md`. One entry per phase; updated as each phase lands. Owner approved full autonomous execution 2026-07-27 evening ("everything is sweet and good to go — execute it all"); all §23-E approvals granted by that sign-off.

| Phase | Status | Branch / commits | Gate result |
|---|---|---|---|
| P0 early hotfixes | ✅ landed | `rebuild/p0-hotfixes` → development | full suite + new regression tests green |
| P1 catalog | ⏳ next | — | — |
| P2 routing | queued | — | — |
| P3 ingest + pilots | queued | — | — |
| P4 content/identity | queued | — | — |
| P5 sections/serving + creator cohort | queued | — | — |
| P6 frontend | queued | — | — |
| P7 connectors/upgrades + food cohort | queued | — | — |
| P8 deletions + re-measure | queued | — | — |

## P0 — Early security hotfixes (2026-07-27)

**Scope (§17 EARLY):**

1. **Host-spoofing patterns closed.** Every open-ended `brand\.[a-z.]+$` suffix let `brand.<attacker-domain>` classify as the brand (reserve buttons / order links / connect flows pointing at attacker hosts). Replaced with enumerated real-TLD sets in:
   - `WebsiteLinkHarvester` — all nine (pinterest, opentable, thefork, quandoo, deliveroo, just-eat ×both stems, treatwell, eventbrite, ticketmaster)
   - `OpenTableService` — shared `TLDS` const across `parseRid`/`isOpenTableUrl`/`nameFromUrl`; the bare `?rid=` fallback now also requires a real OpenTable host (a rid from `random.com/?rid=5` no longer parses)
   - `EventbriteScraper` — all four pattern sites (org/event normalizers, org-page event extraction, link canonicaliser)
   - `GoogleBusinessService` — structured Google TLD shapes (`com`, `com.XX`, `co.XX`, `XX`) for the Maps-host gate + interstitial body extraction
   - `PlatformRegistryServiceProvider` — the four `HostMatch` detectors (eventbrite, quandoo, ticketek, ticketmaster). Interim-only: P2 replaces this whole layer, but it is live until then.
2. **`SafeUrlFetcher`:** own-infrastructure denylist (partna.au, supabase.co, laravel.cloud, r2.cloudflarestorage.com, r2.dev, workers.dev — exact or subdomain, checked before DNS resolution; config `partna.http_fetch.denied_host_suffixes`, env `PARTNA_HTTP_FETCH_DENIED_HOST_SUFFIXES`) + `connectTimeout` clamped to the hop/chunk budget window in both the serial loop and `pooledGet`.
3. **`SiteMedia::svgVariantUrl()`:** the 2026-07-17 original-file fallback is removed — only the container-sanitised vector variant is ever served. The fallback fired exactly when the sanitiser had been skipped (GD fallback / pipeline off), serving auto-grabbed third-party SVG that had passed only `LogoAutoGrabber`'s regex pre-filter. Missing vector now degrades to the webp raster.

**Tests:** `tests/Unit/Security/HostSpoofingHotfixTest.php` pins both directions (spoof rejected, real host accepted) for the harvester, OpenTableService, the registry detectors, the SafeUrlFetcher denylist, and the svg fallback removal. Full suite green.

**Behaviour notes:** unknown ccTLD variants of enumerated brands no longer auto-classify (fail-safe direction; extend the lists if a real market host surfaces). Sites whose logo processing had fallen back to GD render raster instead of unsanitised vector.
