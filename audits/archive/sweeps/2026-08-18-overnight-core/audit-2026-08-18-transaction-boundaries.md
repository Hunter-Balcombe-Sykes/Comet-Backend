# Transaction boundary correctness Audit — 2026-08-18

**Branch:** audit-fix/instagram-wave-findings-2026-08-18
**Lens:** Transaction boundary correctness — hunt every `DB::transaction`/`DB::beginTransaction` site and measure it against the gold-standard discipline: no external I/O, queue dispatch, cache write, event dispatch with side-effecting listeners, or non-`afterCommit` observer side effect inside a database transaction; bounded transaction scope; safe retry/nesting/lock-ordering semantics.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php
- app/Services/Http/SafeUrlFetcher.php
- app/Services/Platforms/ConnectionDisplayName.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/Normalizers/MediumNormalizer.php
- app/Services/Platforms/NowBookitService.php
- app/Services/Platforms/Registry/DerivedDescriptorFactory.php
- app/Services/Platforms/Strategies/Connect/BrandLinkConnect.php
- app/Http/Controllers/Api/Routing/RoutingController.php
- app/Content/Identity/KeyClass.php
- app/Content/Identity/Resolver.php
- app/Ingest/Connectors/{AppleMusicConnector,FreshaConnector,GoogleBusinessConnector,SoundcloudTracksConnector,SpotifyTracksConnector,YoutubeRssConnector}.php
- app/Ingest/Landing/Lander.php
- app/Ingest/Manifest/StreamSpec.php
- app/Ingest/Projection/{AppleMusicReleaseProjector,AppleMusicTrackProjector,FreshaServiceProjector,IdentityKeyDeriver,InstagramMediaProjector,ProjectionWriter,ProjectorRegistry}.php
- app/Ingest/SourceProvisioner.php
- app/Site/Pools/{BorrowedMedia,ItemLinkRules,LiveSourceScope,PoolRegistry,PoolResolver}.php
- app/Site/Sections/{RuleOperator,SectionCandidates}.php
- app/Console/Commands/{AsUserRequestCommand,ConnectSweepCommand,EnrichPendingCardsCommand,IngestRunCommand,RefreshItemCachesCommand,ResetTestUserCommand,ReshapePoolSectionsCommand,RetireLegacyGooglePhotoRecordsCommand}.php
- app/Services/Media/MediaMirror.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

No findings survived adjudication.

DeepSeek's two draft findings (`SourceProvisioner::sync()`'s selection-change precharge, and `Lander::clearGuardIfRecovered()`'s two-write guard clear) both carried confidence < 0.7 and, on inspection against the actual code, are not the transaction-boundary failure mode this lens targets — neither is external I/O, a queue dispatch, a cache write, or an observer side-effect leaking into or out of a `DB::transaction` block; both are pairs of plain, same-connection DB writes with no transaction at all. On top of that, both are self-healing in the failure window they describe: `SourceProvisioner::sync()`'s next run re-derives `selection_ref` from the connection's already-persisted payload and would re-attempt/converge the same update, and a record precharged-then-unconfirmed simply reappears as "seen" on the very next successful run, resetting `absent_runs`. Per the adjudication rule (confidence < 0.7 and not a real security/data issue → drop), both were dropped.

An independent sweep of every `DB::transaction`/`DB::beginTransaction` call site in scope (`app/Ingest/Landing/Lander.php` and `app/Ingest/Projection/ProjectionWriter.php` — the only two files in the audited scope that use either) found the codebase already at gold-standard discipline for this lens:

- Every transaction wraps DB-only work (upserts/deletes across `ingest.record_versions`, `ingest.record_state`, `content.source_items`, `content.identity_keys`, `content.item_media`/`offers`/`item_tags`/`item_variants`/`collection_items`). No `Http::`/`SafeUrlFetcher`/mail call, no `dispatch(...)`, no `Cache::` write, and no `Event::dispatch` appears inside any transaction closure in either file.
- `MirrorMediaAssetJob::dispatch(...)` (`ProjectionWriter::dispatchMirrors()`) and the cache-lane bumps (`refreshItemCaches()`, `bumpSite()`, `invalidateSiteLanes()`) all execute strictly after their governing `DB::transaction()` call returns, not inside it — confirmed by reading the call sites, not just the docblocks.
- `Lander::landChunk()`'s per-chunk transaction and `foldAbsence()`'s guard-trip transaction both carry explicit docblock reasoning about why no `try/catch` may sit inside the closure (Postgres 25P02 poisoning) and why the transaction is scoped where it is — matches the lens's "bounded transaction scope" and "no caught-and-recovered failure inside an open transaction" requirements exactly.
- `IntegrationConnectionObserver` sets `public bool $afterCommit = true` at the class level (with a docblock citing the specific `SourceReconciler` transaction it defers past), which defers every hook (`saved`, `updated`, `deleted`, `restored`) — and therefore every `dispatch(...)` call inside them — until after the triggering save's transaction commits. This is the correct, already-implemented version of category (4)/(11)'s canonical fix, not a finding.
- No other file in the audited scope (`app/Site`, `app/Services/Platforms`, `app/Http/Controllers/Api/Routing`, `app/Content`, `app/Console/Commands`, `app/Services/Media`, the platform jobs/connectors) contains a `DB::transaction`/`DB::beginTransaction` call at all — there is nothing in those files for this lens to evaluate.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.
