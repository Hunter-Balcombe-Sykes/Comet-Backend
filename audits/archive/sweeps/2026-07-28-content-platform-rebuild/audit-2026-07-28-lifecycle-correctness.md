# Lifecycle Correctness Audit — 2026-07-28

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Services/Profile/FieldBindingResolver.php, FieldBindingSeeder.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Policies/DesignKitRestylePolicy.php, SectionPolicy.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php, IntegrationNotifier.php
- app/Ingest/Runtime/EffectLedger.php, HttpIo.php, RunExecutor.php, SourceScheduler.php
- app/Ingest/Landing/Lander.php
- app/Ingest/Connectors/TwitchConnector.php, AppleMusicConnector.php
- app/Ingest/SourceProvisioner.php
- app/Routing/Importers/ImportRun.php
- app/Routing/SourceReconciler.php
- app/Routing/Probes/LinkProbeWorker.php
- app/Services/Platforms/GoogleBusinessService.php
- app/Services/Http/SafeUrlFetcher.php
- app/Site/Documents/DocumentBuilder.php, app/Jobs/Site/BuildSiteDocumentJob.php
- app/Site/Presets/PresetInstantiator.php
- routes/console.php
- supabase/migrations/20260726000000_baseline_pilot.sql, 20260727120000_routing_schema.sql, 20260727130000_ingest_schema.sql, 20260727150000_sections_and_documents.sql, 20260728150000_field_bindings.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 1 of 1 complete
- P2 Medium: 15 of 21 complete
- P3 Low: 0 of 10 complete

---

## P1 — Fix before pilot launch

- [x] **LIFE-1** · P1 — `safeQuery` swallows database failures silently, hiding whole page sections from public traffic with zero Nightwatch signal
    - **Where:** app/Services/PublicSite/SitepageDataResolverService.php:369-383
    - **Affects:** Every public sitepage visitor. A `QueryException` on any presence probe (links, gallery, menu, services, GBP display settings) silently drops that page from the site's navigation instead of surfacing an error.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Keep the graceful-degradation posture for genuinely transient blips, but escalate: increment a metric or call `report()` on the caught exception so Nightwatch's exception detector sees it, in addition to the existing `Log::warning`.
        - Add a scheduled health-check command that runs each presence probe against a known-good site and alerts (throws / `$this->fail()`) if any probe's shape has drifted — the case this code is actually built to survive (schema drift during a deploy) is exactly the case that needs paging, not a log line.
    - **Technical:** `safeQuery()` catches `QueryException` broadly and returns a caller-supplied default (`[]`/`false`/`null`), so a broken presence probe silently degrades to "this page has nothing" instead of failing loudly. The class comment frames this as a deliberate resilience posture ("same resilience posture as `AnalyticsQueryService`") for partial test environments, but in production the same catch also swallows the class of bug this repo has hit before — schema drift between a Postgres migration and a deploy (see `reference_pgsql_driver_sqlite_in_tests.md`, `project_gdpr_export_broken_prod.md`, `reference_prod_rebaseline_gotchas.md` in project memory) — with only a `Log::warning`, which Nightwatch does not alert on. At thousands of sites, a single broken probe after a bad migration/deploy ordering would silently strip a section from every public sitepage until someone notices via support tickets, not monitoring.
    - **Plain English:** Imagine a restaurant's specials board that quietly goes blank whenever the kitchen printer jams — the staff never gets an alarm, they only find out when a customer asks where the specials went. This code does the same thing with entire sections of a person's public page (their menu, their gallery, their links): if the underlying database query breaks, that section just vanishes with only a quiet note in a log nobody is watching. The fix is to make sure a real break rings a bell, not just leaves a sticky note.
    - **Evidence:**
        ```php
        private function safeQuery(\Closure $query, mixed $default, ?string $probe = null, ?Site $site = null): mixed
        {
            try {
                return $query();
            } catch (QueryException $e) {
                Log::warning('sitepage.presence_probe_failed', [
                    'probe' => $probe,
                    'site_id' => $site?->id,
                    'user_id' => $site?->user_id,
                    'error' => $e->getMessage(),
                ]);

                return $default;
            }
        }
        ```

## P2 — Should fix

- [ ] **LIFE-2** · P2 — `FieldBindingResolver::apply` reads binding rules before the transaction/lock opens
    - **Where:** app/Services/Profile/FieldBindingResolver.php:63-104
    - **Affects:** The §14 identity-fold path (not yet the live writer — see LIFE-25). Once wired, a binding toggled mid-fold (user disables a platform's identity feed while a refresh is in flight) can be evaluated against a stale enable/priority snapshot.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `FieldBinding::query()->...->get()` read inside the `DB::transaction()` closure, after the `Workplace` `lockForUpdate()`.
    - **Technical:** Category (2), pattern `lockForUpdate + UNIQUE`. `$bindings` is snapshotted before the transaction opens and captured into the closure via `use ($bindings)`; only the `Workplace` row is re-read under `lockForUpdate()`. A concurrent binding toggle between the snapshot and the lock is invisible to `decide()`. This class is not yet reached from any production caller (`grep` finds only tests/docs), which is why this is P2 rather than P1 — but it is the class the pipeline swap will wire in next (recent commit `28a3a1d8 feat(profile): §14 field_bindings`), so the fix should land before that cutover, not after.
    - **Plain English:** A bouncer photocopies the guest list, then locks the door and starts checking IDs against the photocopy instead of the live list. If someone gets removed from the list after the photocopy was made, the bouncer still lets them in. The fix is to grab the real list only after the door is locked.
    - **Evidence:**
        ```php
        $bindings = FieldBinding::query()
            ->where('site_id', (string) $site->id)
            ->whereIn('field', array_keys($candidates))
            ->get()
            ->groupBy('field');

        $written = [];

        DB::connection($site->getConnectionName())->transaction(function () use ($site, $sourceKey, $candidates, $bindings, &$written) {
            $workplace = Workplace::query()->where('site_id', (string) $site->id)->lockForUpdate()->first()
                ?? new Workplace(['site_id' => (string) $site->id]);
        ```

- [x] **LIFE-3** · P2 — `Lander::foldAbsence` increments `absent_runs` via an unlocked read-modify-write
    <!-- premise no longer holds: RESOLVED by aa1b5782 (P0/P1 SCALE-4/SCALE-5). Lander.php ~434-447 already uses DB::raw('absent_runs + 1') plus a conditional tombstone UPDATE whose affected-row count is the return value — the exact prescribed fix. No change made by Unit B; verified by the Unit B implementer and independently by its reviewer. -->
    - **Where:** app/Ingest/Landing/Lander.php:164-178
    - **Affects:** The tombstoning path for every `mayDelete()` stream (live, scheduled connectors). A lost increment under concurrency understates consecutive absences and can delay/prevent legitimate deletion.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the read-then-write with an atomic `UPDATE ... SET absent_runs = absent_runs + 1 ...` in one statement, or take a row lock before reading.
    - **Technical:** Category (2), pattern `lockForUpdate + UNIQUE`. `$runs = (int) $row->absent_runs + 1;` then a separate `UPDATE` is a classic lost-update shape with no schema backstop (this is an `UPDATE`, not an `INSERT`, so no `UNIQUE` constraint catches a collision — a lost increment is silent, not an exception). Currently dormant: `SourceScheduler::claimDue()` serializes runs per source (`in_flight_since IS NULL` conditional claim), so two runs can't process the same stream concurrently today. That serialization is the *only* thing preventing this from being a live bug on every currently-scheduled connector (Twitch, YouTube, Bandcamp, etc.), which is why this stays P2 rather than P0/P1 rather than being dropped.
    - **Plain English:** Two people look at a "3 misses and you're out" counter at the same moment, both see "2," and both write "3" back — the count never reaches "4" even though there really were 4 misses. Today only one worker ever touches a given stream at a time, so this can't happen yet, but the code itself doesn't guarantee that — it relies on something outside this file to keep it safe.
    - **Evidence:**
        ```php
        foreach ($dominatedAbsent as $row) {
            $runs = (int) $row->absent_runs + 1;
            $update = ['absent_runs' => $runs, 'absent_since' => DB::raw('COALESCE(absent_since, now())')];

            if ($runs >= self::TOMBSTONE_RUNS) {
                $update['tombstoned_at'] = now();
                $tombstoned++;
            }

            DB::table('ingest.record_state')
                ->where('stream_id', $streamId)
                ->where('key', $row->key)
                ->update($update);
        }
        ```

- [x] **LIFE-4** · P2 — `Lander::land` re-reads `current_version_id` in a separate statement after the insert, racing a concurrent landing of the same key
    <!-- premise narrowed since adjudication: idx_record_versions_one_current (migration 20260729130001, added post-adjudication) now catches the changed-key collision loudly and self-heals via the per-record fallback. Unit B fixed the one hole that index does NOT cover: the $wasCurrent===true branch, where nothing arbitrates the pointer write. NOTE: the LIFE-4 concurrency test discriminates but only ~1 run in 13 (narrow race window); it is stable green post-fix. -->
    - **Where:** app/Ingest/Landing/Lander.php:60-97
    - **Affects:** `ingest.record_state.current_version_id` — same dormant-race caveat as LIFE-3 (protected today only by `SourceScheduler`'s per-source claim).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Derive `current_version_id` from the `insertOrIgnore` result directly (e.g. `RETURNING id`) rather than a follow-up `SELECT`, or wrap the read+upsert in a row lock on `record_state`.
    - **Technical:** Category (2), pattern `lockForUpdate + UNIQUE`. Between the `insertOrIgnore` and the `SELECT ... value('id')`, a concurrent landing for the same key with a different hash can interleave, and the subsequent `record_state` upsert can point `current_version_id` at a version that isn't actually the latest. Same source-level claim mitigation as LIFE-3 applies today.
    - **Plain English:** Two librarians catalogue the same new book at once, each stamping a different edition code, then both go update the "current edition" board with their own code — whoever writes last wins, even if their edition is the older one.
    - **Evidence:**
        ```php
        $inserted = DB::table('ingest.record_versions')->insertOrIgnore([...]);
        ...
        $versionId = DB::table('ingest.record_versions')
            ->where('stream_id', $streamId)
            ->where('key', $record->key)
            ->where('doc_hash', $hash)
            ->value('id');

        DB::table('ingest.record_state')->upsert([[
            'stream_id' => $streamId, 'key' => $record->key, 'current_version_id' => $versionId, ...
        ]], ['stream_id', 'key'], ['current_version_id', ...]);
        ```

- [x] **LIFE-5** · P2 — `RunExecutor::recordStreamFailure` increments `consecutive_failures` via an unlocked read-modify-write
    - **Where:** app/Ingest/Runtime/RunExecutor.php:197-207
    - **Affects:** Per-stream backoff accuracy for the same set of currently-scheduled connectors — same dormant-race shape and mitigation as LIFE-3/LIFE-4.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use an atomic `UPDATE ... SET consecutive_failures = consecutive_failures + 1` instead of a PHP round-trip read+write.
    - **Technical:** Category (2), pattern `lockForUpdate + UNIQUE`. `$failures = (int) DB::table('ingest.streams')->where('id', $streamId)->value('consecutive_failures') + 1;` followed by a separate `update()` loses increments under concurrency, producing a shorter backoff than intended. Same `SourceScheduler` per-source claim currently prevents this from firing.
    - **Plain English:** Same "two people reading the same counter and both writing +1" problem as LIFE-3, applied to the failure-backoff counter instead of the absence counter — a lost increment means the system waits less time than it should before retrying a failing vendor.
    - **Evidence:**
        ```php
        $failures = (int) DB::table('ingest.streams')->where('id', $streamId)->value('consecutive_failures') + 1;
        $backoffMinutes = min(10080, 60 * (2 ** min(7, max(0, $failures - 1))));
        DB::table('ingest.streams')->where('id', $streamId)->update([
            'health' => $errorClass === 'budget' ? 'degraded' : 'unavailable',
            'consecutive_failures' => $failures,
            'suppressed_until' => $failures >= 3 ? now()->addMinutes($backoffMinutes) : null,
            'updated_at' => now(),
        ]);
        ```

- [x] **LIFE-6** · P2 — `EffectLedger::once` catches all `\Throwable` on the claim insert instead of the typed unique-violation exception
    - **Where:** app/Ingest/Runtime/EffectLedger.php:63-81
    - **Affects:** Billed-effect connectors (Instagram, Google Business, Square/UberEats/DoorDash menus). Currently these sources are gated off the auto-scheduler (`SourceProvisioner::schedulable()` requires `CostClass::Free`, so `google_business`/`Actor`/`Metered` sources are never claimed by `SourceScheduler::claimDue()`), which limits live exposure today — verify this gate is still in place before treating the risk as fully dormant.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (\Throwable)` with `catch (UniqueConstraintViolationException $e)`; let any other exception (a genuine transport/DB error) propagate so the caller and Nightwatch see it.
    - **Technical:** Category (1), pattern `UniqueConstraintViolationException`. A transient DB error during the claim `insert()` (connection reset, deadlock) is indistinguishable from "another worker already claimed this digest" — both fall into the same catch, and since the insert genuinely failed, the fallback `SELECT` finds no row and returns `'refused'` with no exception raised, no `report()`, and no retry within the same freshness window. This is the exact `catch (QueryException $e) + string-matching` anti-pattern the house doctrine calls out, just with `\Throwable` instead of a message match.
    - **Plain English:** A chef drops an order ticket, and instead of noticing, assumes another chef already picked it up and moves on — the customer never gets their meal, and nobody knows anything went wrong. Here, a real database hiccup gets treated exactly like "someone else is already handling this," so a paid API call can get silently skipped instead of retried or reported.
    - **Evidence:**
        ```php
        try {
            DB::table('ingest.effects')->insert([...]);
        } catch (\Throwable) {
            $row = DB::table('ingest.effects')->where('digest', $digest)->first();
            return $row === null
                ? ['status' => 'refused', 'result' => null, 'cached' => false]
                : $this->verdictFor($row);
        }
        ```

- [x] **LIFE-7** · P2 — `HttpIo::post` skips redirect re-validation and the byte cap that `SafeUrlFetcher::fetch()` enforces on GET
    - **Where:** app/Ingest/Runtime/HttpIo.php:48-62
    - **Affects:** Any connector POSTing through the shared `Io` abstraction. Today only `TwitchConnector::mintAppToken()` calls `post()`, against a fixed config URL (`services.twitch.token_url`), so there is no live user-controlled-URL exposure — but the gap is in the shared abstraction every future connector inherits.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either add POST support to `SafeUrlFetcher` (manual redirect loop + re-validate each hop + byte cap, matching `fetch()`), or have `HttpIo::post()` disable auto-redirects and manually re-run `assertPublicUrl()` on each `Location` header before following it.
    - **Technical:** Category (6), pattern `SafeUrlFetcher`. `post()` does call `$this->fetcher->assertPublicUrl($url)` before the request — it is not a bare bypass — but the raw `Http::post()` call that follows does not disable redirects, so a 3xx response from the target host is auto-followed by Guzzle to a new host that is never re-validated (`SafeUrlFetcher::fetch()`'s GET path explicitly does this per-hop re-validation and calls it out as the open-redirect-SSRF defense). It also skips `assertWithinByteCap()`. Low live risk today because the only caller's URL is a fixed, non-attacker-controlled config value.
    - **Plain English:** There's a secure delivery tunnel that checks every stop along the way for outbound GET requests. For POST requests, the system checks the destination once at the start, then hands the request to a bare truck with no further checks — including if that first stop tries to redirect the truck somewhere else entirely. Today the only POST destination is a fixed address, so this can't be abused yet, but any future connector that starts POSTing to a vendor-supplied URL would inherit this gap.
    - **Evidence:**
        ```php
        public function post(string $url, array $body = [], array $headers = []): array
        {
            $this->admit($url);
            $this->fetcher->assertPublicUrl($url);

            $response = Http::withHeaders($headers)
                ->timeout((int) config('partna.http_fetch.timeout_seconds', 8))
                ->connectTimeout((int) config('partna.http_fetch.connect_timeout_seconds', 3))
                ->post($url, $body);
        ```

- [ ] **LIFE-8** · P2 — Twitch Helix API calls carry no explicit API-version pin
    - **Where:** app/Ingest/Connectors/TwitchConnector.php:153-157, 236
    - **Affects:** The `vods` stream of every live, scheduled Twitch connection (`cost: CostClass::Free`, confirmed auto-scheduled).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Document/pin the Helix contract the connector was built against; add an `Accept`/version header if Twitch's Helix API exposes one, or at minimum assert the expected response shape and alert on drift rather than silently mis-parsing.
    - **Technical:** Category (6), pattern vendor API version pinning. `$io->get('https://api.twitch.tv/helix/videos?...', $headers)` and the `/helix/users` call carry only `Authorization`/`Client-ID` — no version declaration. Helix's shape has changed before; an unannounced field change would silently degrade `mapVideo()`'s parsing rather than fail loudly.
    - **Plain English:** If Twitch changes how they send video data, this connector could start silently mis-reading it — no error, just wrong or missing data on affected users' pages until someone notices.
    - **Evidence:**
        ```php
        $response = $io->get('https://api.twitch.tv/helix/videos?'.http_build_query([
            'user_id' => $userId,
            'first' => 30,
            'type' => 'archive',
        ]), $headers);
        ```

- [ ] **LIFE-9** · P2 — Every connector's `Unavailable` message discards the vendor's raw error body
    - **Where:** app/Ingest/Message/Unavailable.php:10-16 (consumed by every connector, e.g. app/Ingest/Connectors/AppleMusicConnector.php:91-116)
    - **Affects:** Debugging any connector failure across all 20+ live connectors — Nightwatch/on-call has only a terse string, never the actual vendor response.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an optional `body`/`headers` field to `Unavailable` (bounded, e.g. first N KB) and have connectors pass the raw response through instead of paraphrasing into `reason`.
        - Persist it on the run's `ingest.anomalies`/`ingest.runs.detail` record so it's inspectable without a re-run.
    - **Technical:** Category (6), pattern verbatim vendor error capture. `Unavailable` is a `readonly` DTO with only `string $reason` and `?int $status` — there is structurally nowhere to carry the vendor's actual response body. This is one root cause (the shared message shape) manifesting identically across every connector that emits `Unavailable`, not a per-connector bug — fixed once at the `Message` class level, it fixes every connector.
    - **Plain English:** When a supplier sends a confusing packing slip, you want to keep the actual slip, not just a note saying "slip looked weird." Right now the system only keeps the short note — if a vendor's error page or a changed response shape is the real cause, there's no way to look at it after the fact.
    - **Evidence:**
        ```php
        final readonly class Unavailable extends Message
        {
            public function __construct(
                public string $reason,
                public ?int $status = null,
            ) {}
        }
        ```

- [ ] **LIFE-10** · P2 — `IntegrationConnectionObserver::reconcileContentInstagramSlots` has a TOCTOU race between the slot-existence check and slot creation
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:406-445
    - **Affects:** Instagram connect flow — two concurrent payload writes for the same connection could create duplicate content-selection slots.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Push the guard into `ContentSelectionService::setInstagramAuto()` as an atomic insert-if-not-exists (or add a `UNIQUE(site_id, entry_type)` partial index), rather than checking-then-calling from the observer.
    - **Technical:** Category (2), pattern `lockForUpdate + UNIQUE`. `ContentSelection::exists()` is checked, then `setInstagramAuto()` is called with no lock between them; two concurrent saves of the same connection (overlapping refresh dispatch, retry storm) can both pass the check and both create slots.
    - **Plain English:** Two waiters both check if a table has a bread basket, see it doesn't, and both bring one — now there are two. The fix is to make "check, then add" one indivisible step at the database level.
    - **Evidence:**
        ```php
        $hasSlots = ContentSelection::query()
            ->where('site_id', $site->id)
            ->whereIn('entry_type', ContentSelection::IG_TYPES)
            ->exists();
        if ($hasSlots) {
            return;
        }
        ...
        app(ContentSelectionService::class)->setInstagramAuto($site, true);
        ```

- [x] **LIFE-11** · P2 — `IntegrationConnectionObserver::cleanupMirroredMedia` is the only best-effort side-effect method in the class without a try/catch
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:447-455, 538-544
    - **Affects:** Instagram disconnect flow — a malformed/null payload throws out of `deleted()`, skipping the subsequent `retireEventSlugsOnDelete()` and `syncIngestSource()` calls for that same disconnect.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the method body in `try { ... } catch (\Throwable $e) { report($e); Log::warning(...); }`, matching every sibling private method in this class.
    - **Technical:** Category (10)/(5). Every other best-effort method in this observer (`syncEventSlugs`, `retireVanishedEventSlugs`, `seedContentFromGoogle`, `enableContentInstagramAuto`, `reconcileContentInstagramSlots`, `syncIngestSource`) wraps its body in a try/catch per the class's own documented contract ("Best-effort — a failure here must never break the connection save"). `cleanupMirroredMedia()` calls `InstagramPayload::fromArray($connection->payload)->folder` unguarded; a throw here escapes `deleted()` and silently skips the two calls that follow it in the same method.
    - **Plain English:** A disconnect checklist has a safety net on every item except one — if that one item trips, the whole checklist stops and the remaining cleanup steps never run, even though the user's disconnect itself appeared to succeed.
    - **Evidence:**
        ```php
        private function cleanupMirroredMedia(IntegrationConnection $connection): void
        {
            $folder = InstagramPayload::fromArray($connection->payload)->folder;
            if ($connection->platform === Platform::Instagram->value && $folder) {
                DeleteMirroredMediaJob::dispatch($folder);
            }
        }
        ```

- [x] **LIFE-12** · P2 — `PlatformHealthNotifier::menuScrapeFailed` dedupe key has no failure-episode boundary
    - **Where:** app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php:65-78
    - **Affects:** Any user whose menu scrape fails, recovers, then fails again within the 14-day `content_scrape` retention window — only the first episode notifies.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Scope the dedupe key to an episode marker the way `connectionRefreshFailing()` already does (`last_refreshed_at`/equivalent "last success" timestamp), so a recovery-then-refail mints a fresh key.
    - **Technical:** Category (9), pattern JSONB dedup / episode boundary. `connectionRefreshFailing()` in the same class already fixes this exact shape (visible in a `// LIFE-9:` comment documenting a prior fix), scoping its dedupe key to `last_refreshed_at`. `menuScrapeFailed()` uses a flat `"content_scrape:menu_failed:{$userId}"` key with no episode component; `NotificationPublisher::publish()`'s `insertOrIgnore` on `(user_id, dedupe_key)` means a re-failure within the row's 14-day `ends_at` window is silently dropped.
    - **Plain English:** A smoke detector that only beeps the first time it senses smoke stays silent if the smoke clears and comes back later — the user never finds out their menu broke again. The sibling notification right next to this one in the same file already fixes this by giving each new failure its own "episode" marker; this one was missed.
    - **Evidence:**
        ```php
        dedupeKey: "content_scrape:menu_failed:{$userId}",
        // vs. the sibling method's fix:
        $episode = $connection->last_refreshed_at?->toISOString() ?? 'never';
        dedupeKey: "platform_connection_failed:{$connection->id}:{$episode}",
        ```

- [x] **LIFE-13** · P2 — `LinkProbeWorker::cascade` swallows `Throwable` from every probe with zero visibility
    - **Where:** app/Routing/Probes/LinkProbeWorker.php:138-144
    - **Affects:** Operations debugging — a probe that throws from a real code bug (not a vendor outage) is silently discarded forever, indistinguishable from a legitimate miss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` (or at minimum a `Log::warning` with the probe name and IRI) before `continue`.
    - **Technical:** Category (10), pattern distinct logs for distinct failure modes. The comment explicitly says "a probe that throws is a probe that missed" and treats every throw as a benign outage — but a `TypeError`/`null` dereference from a code regression is not an outage, it's a defect that will silently zero out that probe's hit rate indefinitely with no trace.
    - **Plain English:** The system tries five different platform detectors in order; if one crashes from a bug, today it just shrugs and tries the next one — with nothing logged. If a detector breaks, the only symptom is "fewer stores get identified lately," with no way to trace it back to the broken detector.
    - **Evidence:**
        ```php
        try {
            $hit = $probe->attempt($iri);
        } catch (Throwable) {
            continue;
        }
        ```

- [ ] **LIFE-14** · P2 — `GoogleBusinessService::streetViewPano` logs failures without the `place_id` that triggered them
    - **Where:** app/Services/Platforms/GoogleBusinessService.php:475-493 (called from `fetchPlaceDetails`, line 205)
    - **Affects:** Operators triaging Street View probe failures across thousands of Google Business connections — the log carries lat/lng but not the connection the coordinates belong to.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pass `$placeId` (already in scope at the call site) into `streetViewPano` and add it to the `Log::warning` context.
    - **Technical:** Category (10), pattern `Log-with-context`. `fetchPlaceDetails()` has `$placeId` in scope and passes it to `resolvePhotoUrls()` on the line just above, but not into `streetViewPano()`. The resulting warning is keyed on raw coordinates, forcing a reverse-geocode to identify the affected business.
    - **Plain English:** When a Street View check fails, the log says "failed at these GPS coordinates" instead of "failed for this business" — an operator has to do extra detective work to find out whose page is affected, even though the business ID was sitting right there in the calling code.
    - **Evidence:**
        ```php
        private function streetViewPano(string $key, float $lat, float $lng): ?array
        {
            try {
                $res = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/streetview/metadata', [...]);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('google_business.streetview_probe_failed', [
                    'lat' => $lat,
                    'lng' => $lng,
                    'message' => $e->getMessage(),
                ]);
                return null;
            }
        ```

- [x] **LIFE-15** · P2 — `SourceReconciler::upsertIntent` races on intent creation despite an existing UNIQUE index
    - **Where:** app/Routing/SourceReconciler.php:115-160
    - **Affects:** Concurrent link-identification for the same user/surface/identifier (a link-in-bio scan re-finding a profile the user is simultaneously pasting directly).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the insert in `catch (UniqueConstraintViolationException $e)` and re-read the row that won, instead of trusting the select-then-insert check alone.
    - **Technical:** Category (1), pattern `UniqueConstraintViolationException`. `routing.source_intents` already has `idx_source_intents_live`, a `UNIQUE` index on `(user_id, surface_key, identifier) WHERE state IN ('proposed','applied','blocked')` — so a genuine race cannot create duplicate rows, but it CAN throw an unhandled exception up through `reconcile()`, which has no catch of its own, into whatever request/job invoked it (surfacing as a 500 or a failed job rather than a graceful "someone else already handled this").
    - **Plain English:** The database already refuses to store two identical intents at once — but nothing in this code is prepared for that refusal, so instead of quietly saying "already handled," a genuine race currently crashes the request.
    - **Evidence:**
        ```php
        $existing = DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            ->where('surface_key', $placement->surfaceKey)
            ->where('identifier', $identifier)
            ->whereIn('state', ['proposed', 'applied', 'blocked'])
            ->first();

        if ($existing !== null) { ... }

        $id = (string) Str::uuid();
        DB::table('routing.source_intents')->insert([...]);
        ```

- [x] **LIFE-16** · P2 — `SourceReconciler::reconcile` writes the intent and its resulting connection in two non-atomic steps
    - **Where:** app/Routing/SourceReconciler.php:70-82
    - **Affects:** Users whose auto-applied Place intent hits a mid-write failure — the intent row is left `'applied'` with no `connection_id`, and only self-heals if the user re-pastes the same link.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `upsertIntent` + `applyIntent` + the final intent-state update in one `DB::transaction`, or mark the intent `'blocked'`/`block_reason: 'write_failed'` on `applyIntent` failure so it surfaces in the suggestions inbox instead of silently dangling.
    - **Technical:** Category (5)/(11), pattern `DB::afterCommit` discipline. If `applyIntent()` throws after `upsertIntent()` already committed `state='applied'`, the intent row is stuck applied-with-no-connection until the user re-triggers the same paste (which does self-heal, per the code path, but with no visibility into the interim broken state).
    - **Plain English:** When the system auto-connects a link it recognized, it writes two records — "we decided to apply this" and "here's the actual connection" — as two separate steps. If the second step fails, the first record says "applied" even though nothing was actually connected, and the user's page is quietly missing what they expected, with no indication anything went wrong.
    - **Evidence:**
        ```php
        $intentId = $this->upsertIntent($user, $placement, $context, $iri, $routingClass, $identifier, $verdict, $blockReason, $conflictId);
        ...
        if ($verdict === Verdict::Place) {
            $connectionId = $this->applyIntent($user, $placement->surfaceKey, $routingClass, $identifier, $iri, $context);
            DB::table('routing.source_intents')->where('id', $intentId)->update(['connection_id' => $connectionId, ...]);
        }
        ```

- [ ] **LIFE-17** · P2 — `ImportRun::start` races past the daily per-kind cooldown
    - **Where:** app/Routing/Importers/ImportRun.php:23-41
    - **Affects:** Users who trigger two imports of the same kind in quick succession (double-click, overlapping bio-harvest + website-scan trigger) — the 3-per-day limit can be exceeded by one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Take a user-scoped advisory lock (or `lockForUpdate` on a per-user cooldown row) around the count-check + insert.
    - **Technical:** Category (2), pattern `lockForUpdate + UNIQUE`. `isOnCooldown()` is a plain `COUNT(*) >= 3` check with no lock, then a bare insert follows — two requests both at count=2 both pass and both insert, yielding 4 runs. Low-harm (a soft cost-tracking cooldown, not security/money), but a genuine unguarded race.
    - **Plain English:** Two people both see a parking lot sign say "2 spots left" and both drive in — the count only works if checking and claiming a spot happen as one locked step, and today they don't.
    - **Evidence:**
        ```php
        public static function start(string $userId, string $kind, ?string $sourceUrl = null): ?string
        {
            if (self::isOnCooldown($userId, $kind)) {
                return null;
            }
            $id = (string) Str::uuid();
            DB::table('routing.import_runs')->insert([...]);
            return $id;
        }
        ```

- [x] **LIFE-18** · P2 — No scheduled reconcile job releases `ingest.effects` rows stuck `claimed` after a worker crash
    - **Where:** supabase/migrations/20260727130000_ingest_schema.sql (`idx_effects_unsettled`); routes/console.php (no matching schedule entry); referenced-but-nonexistent `ingest:effects --resolve` command in app/Ingest/Runtime/EffectLedger.php:17
    - **Affects:** Billed-effect budget slots for connectors that declare paid effects (Instagram, Google Business, menu Apify actors) — currently gated off the auto-scheduler (verify `SourceProvisioner::schedulable()`'s `CostClass::Free` gate is still in place before assuming full dormancy).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled command sweeping `ingest.effects` where `settled_at IS NULL AND claimed_at < now() - interval` and mark them `abandoned` (matching `EffectLedger::verdictFor`'s own abandon logic), or implement the `ingest:effects --resolve` command the class docblock already references but that doesn't exist.
    - **Technical:** Category (4), pattern daily reconcile job. The schema's own index (`idx_effects_unsettled ON (claimed_at) WHERE settled_at IS NULL`) and `EffectLedger`'s docblock ("Resolving it is a deliberate act (`ingest:effects --resolve`)") both presume a resolver command exists — it does not, and nothing is scheduled. Today this is lower-risk than it looks: `runBilledEffect()` currently throws unconditionally (no P7 driver is wired), so most claims settle to `'failed'` almost immediately rather than hanging — the exposure is narrowed to an actual worker crash between claim-insert and the catch running, not routine failures.
    - **Plain English:** The system has a shelf for "payment claimed but we don't know if it went through" — with a label and an index to find items on it quickly, but nobody scheduled to actually check the shelf. A worker crash at just the wrong moment permanently locks up that budget slot until a human notices.
    - **Evidence:**
        ```sql
        CREATE INDEX "idx_effects_unsettled" ON "ingest"."effects" ("claimed_at")
            WHERE ("settled_at" IS NULL);
        ```

- [x] **LIFE-19** · P2 — No automated alert for `routing.source_intents` stuck `proposed`/`blocked`
    <!-- the finding's suggested per-row alert was REJECTED as a fatigue trap: every reachable stuck state (below_threshold / conflict / cap_reached) is a user's own inbox question, not an operator page. Implemented as an AGGREGATE alarm instead — 500-row threshold AND 14-day age — with an explicit negative test asserting a handful of stuck intents does NOT page. -->
    - **Where:** supabase/migrations/20260727120000_routing_schema.sql:86-89 (`idx_source_intents_stuck`); routes/console.php (no matching schedule entry)
    - **Affects:** Users whose imported links never resolve into connections — the row sits with no automated notification to staff.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a scheduled command querying `idx_source_intents_stuck` for intents older than a threshold and `report()`/throw so Nightwatch pages on it, instead of relying on a human periodically checking a staff dashboard.
    - **Technical:** Category (4), pattern daily reconcile job. The index comment ("Staff stuck-intents view feed") confirms this is meant to back an operator-facing signal, but nothing in `routes/console.php` proactively surfaces it.
    - **Plain English:** There's a labeled shelf for "imports that got stuck and need a human" — but nothing checks it on a schedule, so items can sit there for weeks unnoticed.
    - **Evidence:**
        ```sql
        -- Staff stuck-intents view feed: anything proposed/blocked for a long time.
        CREATE INDEX "idx_source_intents_stuck"
            ON "routing"."source_intents" ("state", "first_seen_at")
            WHERE ("state" IN ('proposed', 'blocked'));
        ```

- [x] **LIFE-20** · P2 — No automated alert for unresolved `critical` `ingest.anomalies`
    - **Where:** supabase/migrations/20260727130000_ingest_schema.sql (`idx_anomalies_open`); routes/console.php (no matching schedule entry)
    - **Affects:** A tripped delete-guard or schema-drift anomaly (e.g. `Lander`'s `delete_guard` trip in LIFE-3/LIFE-4's file) freezes deletion for a stream and sits unresolved with no page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a scheduled command that queries `idx_anomalies_open` for `severity='critical' AND resolved_at IS NULL` older than a threshold and throws/`report()`s so Nightwatch alerts. `warning`-severity can stay log-only.
    - **Technical:** Category (4), pattern daily reconcile job. `ingest.anomalies` is the system's explicit "a human must look at this" queue (delete-guard trips, shape drift, stranded sources); the open-anomalies index exists to power a dashboard, but nothing proactively pages on a critical, long-unresolved row.
    - **Plain English:** The ingest system has a fire-alarm panel that logs incidents neatly by type and time — but there's no siren. A critical incident, like a deletion guard freezing a user's content, just sits on the panel until someone happens to check it.
    - **Evidence:**
        ```sql
        CREATE INDEX "idx_anomalies_open" ON "ingest"."anomalies" ("kind", "detected_at" DESC)
            WHERE ("resolved_at" IS NULL);
        ```

- [ ] **LIFE-21** · P2 — `handles:notify-expiry`'s dedupe relies on per-column timestamps plus a 60-minute scheduler lock, not an atomic JSONB write
    - **Where:** routes/console.php:184-189; `notified_t3_at`/`notified_t1_at` columns in supabase/migrations/20260726000000_baseline_pilot.sql:1087-1088, 2139-2140
    - **Affects:** Handle/subdomain alias holders — a lock-expiry or partial-job failure during the daily expiry-warning run can double-fire (or, if the two column writes land in separate statements, drop) a T-3/T-1 warning.
    - **Effort:** M (~2–4h) — schema change, so kept Standalone (see below)
    - **What to do:**
        - Replace `notified_t3_at`/`notified_t1_at` with a single JSONB `notifications_sent` column, written atomically in one `UPDATE`, so the scheduler's `withoutOverlapping(60)` lock stops being the sole correctness guarantee.
    - **Technical:** Category (3), pattern JSONB dedup. The scheduler comment itself names the fragility: `withoutOverlapping(60) // 60min lock — closes a race between application-level whereNull guards on the notified_t* stamp columns.` Two separate timestamp columns updated by application-level `whereNull` checks are vulnerable to a partial update (crash between writing `notified_t3_at` and `notified_t1_at`) in a way a single atomic JSONB write is not.
    - **Plain English:** The system tracks "did we send the 3-day warning?" and "did we send the 1-day warning?" as two separate sticky notes. If the job crashes after writing one note but not the other, a retry can either re-send a warning that already went out, or miss one — and today the only thing preventing that is a scheduler lock, not the data itself being written safely.
    - **Evidence:**
        ```php
        Schedule::command('handles:notify-expiry')
            ->dailyAt('09:00')
            ->onOneServer()
            ->withoutOverlapping(60) // 60min lock — closes a race between application-level whereNull guards on the notified_t* stamp columns.
        ```

- [x] **LIFE-22** · P2 — `DesignKitRestylePolicy::create()` returns a raw boolean (403) on ownership mismatch instead of the class's own documented 404 contract
    - **Where:** app/Policies/DesignKitRestylePolicy.php:27-34
    - **Affects:** The create-restyle endpoint (`RestyleController::store`). Today the controller always builds `$skeleton` from the actor's *own* site (`$skeleton->site_id = $site->id`), so `ownerMatches()` cannot currently return `false` through this call site — but the inconsistency remains a live trap for any future caller.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `return $this->ownerMatches($actor, $skeleton) ? true : $this->denyAsNotFound();` — matching `view()`/`update()` in the same class.
    - **Technical:** Category (7), pattern Policy over inline role-scoping / 403-vs-404. The class docblock states plainly: "a mismatch is a 404, never a 403 (existence must not leak)." `view()` and `update()` honor this; `create()` returns the raw `ownerMatches()` boolean, which Laravel turns into a 403. Verified via `RestyleController.php:42-44` that today's only call site always sets `$skeleton->site_id` from the actor's own resolved site, so the mismatch branch is currently unreachable — this is a defense-in-depth/consistency fix, not a live exploit, hence P2 rather than P1.
    - **Plain English:** A coat-check that responds "that coat belongs to someone else" instead of "no coat found" is confirming the coat exists — useful information for someone probing IDs. This one method returns that leaky response while its siblings in the same file already give the safe "not found" answer; today's only caller happens to never trigger it, but it's one inconsistent line away from doing so.
    - **Evidence:**
        ```php
        public function create(User $actor, Model $skeleton): bool|Response
        {
            if ($denied = $this->denyIfPendingDeletion($actor)) {
                return $denied;
            }

            return $this->ownerMatches($actor, $skeleton);
        }
        ```

- [x] **LIFE-23** · P2 — `SectionPolicy::create()` has the identical 403-instead-of-404 inconsistency as LIFE-22
    - **Where:** app/Policies/SectionPolicy.php:33-40
    - **Affects:** `SectionController::store` — same "always the actor's own site" mitigation confirmed via `SectionController.php:78-83` (`$section->site_id = $site->id;` before `authorizeForUser`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as LIFE-22: wrap the `ownerMatches()` result in `? true : $this->denyAsNotFound()`.
    - **Technical:** Same root cause and same tier as LIFE-22 per the "same root cause, same tier" rule. `view()`, `update()`, and `delete()` in this class all correctly call `denyAsNotFound()`; `create()` is the sole outlier.
    - **Plain English:** Same coat-check problem as LIFE-22, in the sibling policy for page sections instead of design-kit restyles.
    - **Evidence:**
        ```php
        public function create(User $actor, Model $skeleton): bool|Response
        {
            if ($denied = $this->denyIfPendingDeletion($actor)) {
                return $denied;
            }

            return $this->ownerMatches($actor, $skeleton);
        }
        ```

## P3 — Nice to have

- [ ] **LIFE-24** · P3 — `RunExecutor::drain`'s unknown-message log carries no run/source/stream correlation context
    - **Where:** app/Ingest/Runtime/RunExecutor.php:216
    - **Affects:** Operator triage if a connector ever emits a `Message` subtype the executor doesn't handle — only reachable via a genuine code bug (a new `Message` type shipped without updating `drain()`'s match arms), not routine operation.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `run_id`, `source_id`, and `stream_name` to the log context.
    - **Technical:** Category (10), pattern `Log-with-context`. `Log::warning('ingest.unknown_message', ['class' => $message::class])` has no identifying fields, but this branch only fires on a programming defect (an unhandled `Message` subtype), not an environmental condition, which is why it's P3 rather than the P2 given to LIFE-14's structurally similar gap.
    - **Evidence:**
        ```php
        default => Log::warning('ingest.unknown_message', ['class' => $message::class]),
        ```

- [ ] **LIFE-25** · P3 — `Workplace::first()->save()` races on the first-ever write for a new site
    - **Where:** app/Services/Profile/FieldBindingResolver.php:75-76
    - **Affects:** Currently nothing — `FieldBindingResolver::apply()` has no production caller (only tests/docs reference it); this subsystem is not yet wired into any live identity-fold path (see LIFE-2).
    - **Effort:** S (~0.5–1h)
    - **What to do:** Use `firstOrCreate` or catch `UniqueConstraintViolationException` on the save.
    - **Technical:** Category (2). `site.workplaces` has `PRIMARY KEY (site_id)` (confirmed in `supabase/migrations/20260726000000_baseline_pilot.sql:2589-2590`) and no auto-insert trigger the way `site.design_kits` has — so two concurrent first-writes for a brand-new site would race on `lockForUpdate()->first()` both returning `null`, both building a new model, and the second `save()` throwing an uncaught PK violation. The PK backstop means this fails loud, not silently, and the code path is currently unreached.
    - **Evidence:**
        ```php
        $workplace = Workplace::query()->where('site_id', (string) $site->id)->lockForUpdate()->first()
            ?? new Workplace(['site_id' => (string) $site->id]);
        ```

- [ ] **LIFE-26** · P3 — `FieldBindingSeeder::seed` is a non-atomic check-then-bulk-insert despite claiming idempotency
    - **Where:** app/Services/Profile/FieldBindingSeeder.php:70-101
    - **Affects:** Currently nothing live — only called from `PresetInstantiator::instantiate()`, which itself has no production caller.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Wrap in a transaction with a row lock, or use `INSERT ... ON CONFLICT (site_id, field, source_key) DO NOTHING` and catch the typed exception on the plain path.
    - **Technical:** Category (1). `site.field_bindings` already has `UNIQUE(site_id, field, source_key)` (confirmed in `supabase/migrations/20260728150000_field_bindings.sql:40`), so a race throws rather than duplicates — but the docblock's claim "Idempotent and NON-DESTRUCTIVE" isn't true under concurrency without a catch.
    - **Evidence:**
        ```php
        $existing = FieldBinding::query()->where('site_id', ...)->where('source_key', ...)->pluck('field')->flip();
        ...
        if ($rows !== []) {
            DB::connection('pgsql')->table('site.field_bindings')->insert($rows);
        }
        ```

- [ ] **LIFE-27** · P3 — `PresetInstantiator::instantiate` reads existing pages/sections outside any lock before inserting missing ones
    - **Where:** app/Site/Presets/PresetInstantiator.php:47-108
    - **Affects:** Currently nothing — no production caller exists anywhere in `app/` (tests/docs only).
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `lockForUpdate()` to the existing-pages/sections reads inside the transaction, or catch the typed unique-violation on save.
    - **Technical:** Category (2). `site.pages` and `site.sections` both carry `UNIQUE(site_id, key)` (confirmed in `supabase/migrations/20260727150000_sections_and_documents.sql:27, 64`), so a concurrent double-instantiation throws rather than duplicates — worth fixing before this class is wired into a live onboarding path, not urgent while it's dormant.
    - **Evidence:**
        ```php
        $existingPages = Page::query()->where('site_id', $siteId)->get()->keyBy('key');
        ...
        if ($page === null) {
            $page = new Page([...]);
            $page->save();
        }
        ```

- [ ] **LIFE-28** · P3 — `SourceProvisioner::sync` is a select-then-insert race, already fully backstopped
    - **Where:** app/Ingest/SourceProvisioner.php:73-96
    - **Affects:** Ingest source provisioning on connection save — low live risk given the mitigations below.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `catch (UniqueConstraintViolationException $e)` around the insert for a cleaner, more specific log than the caller's current broad `catch (QueryException)`.
    - **Technical:** Category (1). `ingest.sources` already has `UNIQUE(connection_id, source_key)` (confirmed in `supabase/migrations/20260727130000_ingest_schema.sql:44`), and this method's only two callers — `IntegrationConnectionObserver::syncIngestSource` and `IngestBackfillSourcesCommand` — already wrap the call in a broad try/catch that logs and swallows a `QueryException` at debug level. A race here is fully contained today: no duplicate row, no crash, just a slightly noisy debug log — hence P3, down from the draft's P1.
    - **Evidence:**
        ```php
        $existing = DB::table('ingest.sources')->where('connection_id', $connection->id)->where('source_key', $sourceKey)->first(['id', 'identifier', 'auto_sync']);
        if ($existing === null) {
            DB::table('ingest.sources')->insert([...]);
        }
        ```

- [ ] **LIFE-29** · P3 — `RunExecutor::ensureStream` is a select-then-insert race, already fully backstopped
    - **Where:** app/Ingest/Runtime/RunExecutor.php:164-180
    - **Affects:** `ingest.streams` row creation — same dormant-and-backstopped shape as LIFE-28.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `catch (UniqueConstraintViolationException $e)` and re-read on conflict.
    - **Technical:** Category (1). `ingest.streams` already has `UNIQUE(source_id, stream_name)` (confirmed in `supabase/migrations/20260727130000_ingest_schema.sql:75`), and `SourceScheduler::claimDue()`'s per-source claim currently prevents two runs for the same source from ever colliding here. If that serialization is ever removed, this throws cleanly rather than duplicating.
    - **Evidence:**
        ```php
        $existing = DB::table('ingest.streams')->where('source_id', $sourceId)->where('stream_name', $streamName)->value('id');
        if ($existing !== null) { return (string) $existing; }
        $id = (string) Str::uuid();
        DB::table('ingest.streams')->insert([...]);
        ```

- [ ] **LIFE-30** · P3 — `analytics:compute-popularity`'s fixed lookback window can permanently drop events across a long scheduler outage
    - **Where:** routes/console.php:120-152
    - **Affects:** Popularity ranking for a site whose only activity lands entirely inside a missed-tick gap longer than ~45 minutes before going dormant.
    - **Effort:** M (~2–4h) — a persisted watermark, per the code's own note
    - **What to do:** No new action beyond what's already tracked — the code comment documents the exact fix (a persisted `last-successful-run` watermark) and the reasons it was deliberately deferred. Re-surface at the next scheduling-reliability pass.
    - **Technical:** Category (3), pattern anchor decoupling. This is not a new finding — it's an already-documented, already-analyzed, deliberately-deferred trade-off in the code itself, explaining the 20→60min widening history and why it's bounded (self-heals on next scope-in, 90-day retention, cosmetic ranking not money/auth). Included here only so it's tracked in the lifecycle-correctness ledger, not as new information.
    - **Evidence:**
        ```php
        // ⚠️ ALSO OPEN (mitigated, not fixed) — missed-tick gap introduced by that
        // scoping: at the 15-min cadence, a W-minute lookback survives K consecutive
        // missed ticks ... Proper fix is a persisted last-successful-run watermark
        // instead of a fixed lookback — larger work, likely a schema change, deferred.
        ```

- [ ] **LIFE-31** · P3 — `keep-alive-ping` swallows `Throwable` with no debug trail
    - **Where:** routes/console.php:261-267
    - **Affects:** Diagnosing why keep-alive pings stopped if `config('app.url')` or the `/up` route itself ever breaks.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a `Log::debug(...)` in the catch (transient network failures are genuinely non-actionable, but an engine-level error like a `TypeError` from a misconfigured `app.url` shouldn't be invisible even in debug logs).
    - **Technical:** Category (10). `catch (Throwable $e) { /* Silent */ }` catches everything including PHP `Error` subtypes, not just expected network timeouts.
    - **Evidence:**
        ```php
        try {
            $url = rtrim((string) config('app.url'), '/').'/up';
            Http::timeout(3)->retry(1, 200)->get($url);
        } catch (Throwable $e) {
            // Silent — keep-alive failures aren't actionable.
        }
        ```

- [ ] **LIFE-32** · P3 — `LifestyleConnectionCleanup::forUser` deletes connections in a loop with no transaction boundary
    - **Where:** app/Services/Accounts/LifestyleConnectionCleanup.php:59-67
    - **Affects:** The rare partna→business account-type switch — if one delete throws mid-loop, earlier deletes persist and later ones don't, with no automated repair.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Wrap the `foreach` in `DB::transaction()`.
    - **Technical:** Category (5). The caller, `UserObserver::updated()`, already wraps the whole `forUser()` call in `try { ... } catch (\Throwable $e) { Log::warning(...) }`, so a mid-loop failure can't crash the request — it just leaves a partial cleanup silently logged at warning level rather than reconciled. Low frequency (account-type switch is rare) keeps this at P3.
    - **Evidence:**
        ```php
        $connections = $query->get();
        foreach ($connections as $connection) {
            $connection->delete();
        }
        return $connections->count();
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Ingest dormant-race hardening (lockForUpdate / atomic-update / typed-catch sweep):** #LIFE-3, #LIFE-4, #LIFE-5, #LIFE-6, #LIFE-15, #LIFE-28, #LIFE-29
    - **Why grouped:** Same root cause (unlocked read-modify-write or select-then-insert races in the ingest pipeline, currently dormant behind `SourceScheduler`'s per-source claim), same fix shape (atomic UPDATE or typed exception catch), spread across `Lander`, `RunExecutor`, `EffectLedger`, `SourceProvisioner`, `SourceReconciler`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — IntegrationConnectionObserver Instagram/media hardening:** #LIFE-10, #LIFE-11
    - **Why grouped:** Same file, adjacent private methods, same "best-effort must never break the host save" contract already established by every sibling method in the class.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Ingest/notification observability context:** #LIFE-12, #LIFE-13, #LIFE-14, #LIFE-24
    - **Why grouped:** All are "add correlating context / episode boundary to an existing log or dedupe key" fixes, no schema changes, mechanically similar.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Missing reconcile/alert scheduled commands:** #LIFE-18, #LIFE-19, #LIFE-20
    - **Why grouped:** Same shape — a new scheduled Artisan command that sweeps an existing index and pages Nightwatch on stale rows; no schema changes needed (indexes already exist).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Connector vendor hygiene:** #LIFE-7, #LIFE-8, #LIFE-9
    - **Why grouped:** Shared-abstraction hardening (`Io`/`Unavailable`/connector version pinning) that benefits every connector at once, no DB schema changes.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Routing lifecycle atomicity:** #LIFE-16, #LIFE-17
    - **Why grouped:** Both are `SourceReconciler`/`ImportRun` non-atomic multi-step writes in the link-routing lifecycle, fixed with transaction wrapping.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 7 — Field-bindings pre-cutover hardening (unwired §14 subsystem):** #LIFE-2, #LIFE-25, #LIFE-26, #LIFE-27
    - **Why grouped:** All four sit in the same not-yet-live §14/WAVE-2C identity-fold subsystem (`FieldBindingResolver`, `FieldBindingSeeder`, `PresetInstantiator`); fix together before the pending pipeline-swap cutover wires any of them into production.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 8 — Low-effort polish:** #LIFE-31, #LIFE-32
    - **Why grouped:** Both are small, isolated, non-security polish items with no shared file or subsystem beyond "quick fix."
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#LIFE-1 — `safeQuery` silently swallows DB failures on the public sitepage hot path** · sole P1, highest-impact/highest-breadth item — run alone with its own plan + sign-off before folding into any other session.
- **#LIFE-21 — `handles:notify-expiry` JSONB dedup** · requires a DB schema/migration change (new column, backfill of two existing timestamp columns).
- **#LIFE-22 — `DesignKitRestylePolicy::create()` 403→404** · touches authorization.
- **#LIFE-23 — `SectionPolicy::create()` 403→404** · touches authorization.
- **#LIFE-30 — `analytics:compute-popularity` missed-tick gap** · L-effort (persisted watermark, likely schema change) per the code's own deferred-fix note; not scheduled for this pass, tracked only.
