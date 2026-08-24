# Caching Coverage Gaps Audit — 2026-08-24

**Branch:** development
**Lens:** Caching coverage gaps — hot, expensive reads with no cache at all (bundle chunks: hot-reads-app, hot-reads-platforms, hot-reads-controllers-user, hot-reads-controllers-public, hot-reads-controllers-catalog-routing, hot-reads-routing-probes)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/Site/UpdateSiteAction.php
- app/Services/Platforms/AppleSearch.php
- app/Services/Platforms/ConnectionDisplayName.php
- app/Services/Platforms/GoogleBusinessApifyScraper.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/GoogleBusinessService.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Services/Platforms/LinkInBioApiUnroller.php
- app/Services/Platforms/LinkInBioDetector.php
- app/Services/Platforms/LinkInBioInlinePayloadReader.php
- app/Services/Platforms/LinkRouter.php
- app/Services/Platforms/MediaPageReader.php
- app/Services/Platforms/MediaSeeder.php
- app/Services/Platforms/Payloads/GoogleBusinessPayload.php
- app/Services/Platforms/Registry/PlatformDescriptor.php
- app/Services/Platforms/Strategies/Connect/YoutubeConnect.php
- app/Services/Platforms/Strategies/Fetch/AppleMusicFetch.php
- app/Services/Platforms/Strategies/Fetch/ShopFetch.php
- app/Services/Platforms/YoutubeScraper.php
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/ClaimController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php
- app/Http/Middleware/Auth/RequireStrongAuth.php
- app/Http/Controllers/Api/Content/PoolController.php
- app/Http/Controllers/Api/Routing/RoutingController.php
- app/Http/Controllers/Api/Routing/SuggestionsController.php
- app/Routing/Probes/LinkProbeWorker.php
- app/Site/Actions/ActionCandidates.php
- app/Site/Actions/ActionId.php
- app/Site/Actions/ActionSettings.php
- app/Site/Actions/ActionSlots.php
- app/Site/Actions/ConnectionProfileUrl.php
- app/Site/Pools/PoolOrdering.php
- app/Site/Pools/PoolResolver.php
- app/Site/Pools/PoolSectionProvisioner.php
- app/Site/Pools/PoolWire.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## Adjudication notes (why every draft finding was dropped)

- **`UserSiteActionsController::show` (draft CCG-1, controllers-user chunk).** The controller's own header comment states plainly: *"Read-only; writes go through PATCH /api/site settings.actions. This does a full pool hydration per call — owner-only and uncached by design."* This is a documented architectural decision, not an oversight — and the endpoint is single-owner-facing (a professional viewing their own actions dashboard), which fails the lens's bar-3 "many concurrent callers" test the way the public sitepage payload path (which *is* cached) does not. It's also the direct target of a same-day perf fix (`a0739ffd6`/`f263a284a`, "Batch pool hydration: plan -> one hydrate -> assemble") that took this exact call from 244 queries/58.8s to a single batched hydrate — the team already addressed the cost via query-shape, not caching, and documented why caching was rejected. Not a CCG finding.
- **`SuggestionsController::resolveSwapIncumbent` (draft CCG-1, catalog-routing chunk).** Verified: the per-intent `IntegrationConnection::query()` call inside the `index()` map loop is real. But this is a classic N+1/fan-out shape (one query per loop iteration against varying `surface_key` filters), not a repeated-identical-read — and DeepSeek's own proposed fix ("pre-load the user's IntegrationConnection rows grouped by surface key before mapping intents") is literally an eager-loading/batch-query fix. Per this lens's explicit scope carve-out, "a read whose correct fix is `with()` eager loading... the query is badly *shaped*, not missing a *cache*" belongs to `database-and-queue-scaling.md` (`SCALE`), not `CCG`. Dropped from this lens.
- **`PoolResolver::itemPayloads` fan-out (draft CCG-1, routing-probes chunk).** Verified the query fan-out is real (~20 facet queries), but the class docblock states explicitly: *"The ONE pool read — live, no document cache (owner chose Option B: 'always as live as is possible'). The dashboard's pool page and the public payload both call this, so what the owner curates and what a visitor sees cannot be two different resolutions."* This is a stated owner ruling against caching this layer, not an unnoticed gap. The public sitepage path is **not** actually uncached — inline comments throughout the file confirm it "sits behind the 60s payload cache on the public path," and `popularityRanks()` (referenced in the same method) already has its own `rememberLocked`-backed cache (`CacheKeyGenerator::sitePopularityRanks()`, 900s TTL, per the `CCG-102` comment at line 85). The draft finding proposes reintroducing caching the codebase deliberately removed the equivalent of at this layer. Dropped.
- **`PoolResolver::statsFor` (draft CCG-2, routing-probes chunk).** Same root cause and same explicit "always live" design ruling as above — this method runs inside `assemble()`, which is downstream of the same documented decision. Its own inline comment (`#LIFE-2`) explains the query was recently *hardened* (added a liveness filter) rather than slated for caching. Dropped for the same reason as CCG-1 in this chunk — same root cause, same disposition.

No findings from this lens survive adjudication for the audited scope. `AccountCapabilities::for()` (checked opportunistically, since it's a canonical hot-path candidate per the lens) is already correctly memoized per-request via a static `WeakMap` (labelled `audit SCALE-1` in the source) — confirmed not a gap. `RequireStrongAuth` middleware does no DB reads at all (pure JWT-attribute check). `DevInsightsController` is explicitly labelled a dev/testing-only endpoint ("Not production-critical... no cache — the same ad-hoc norm the sibling UserAnalyticsController uses") and is out of scope as a rarely-hit, non-hot path. `AnalyticsController` is a write-only ingest path with no reads to cache.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.
