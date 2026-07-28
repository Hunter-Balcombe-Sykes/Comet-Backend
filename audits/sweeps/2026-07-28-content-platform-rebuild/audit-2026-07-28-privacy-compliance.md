# Privacy & Data-Rights Compliance Audit — 2026-07-28

**Branch:** development
**Lens:** Privacy & data-rights compliance — PII inventory, export/delete completeness, retention enforcement, processor flows (bundle: rights-machinery / collection-retention / console-mail / ingest-third-party / schema-pii)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Console/Commands/PurgeSoftDeleted.php`, `PruneNotifications.php`
- `app/Services/Notifications/NotificationPublisher.php`
- `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php`
- `app/Models/Core/Site/IntegrationConnection.php`, `SiteMedia.php`
- `app/Ingest/Connectors/GoogleBusinessConnector.php`, `app/Ingest/Landing/Lander.php`, `app/Ingest/Manifest/Manifest.php`
- `app/Services/Analytics/AnalyticsQueryService.php`, `AnalyticsEventSanitizer.php`, `Writers/PostgresEventWriter.php`
- `config/partna.php`, `routes/console.php`
- `supabase/migrations/20260727130000_ingest_schema.sql`, `20260727140000_content_schema.sql`, `20260727120000_routing_schema.sql`

## Progress

- P0 Blockers: 1 of 1 complete
- P1 High: 1 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 2 complete

---

## P0 — Must fix before any real user touches the system

- [x] **#PRIV-1** · P0 — The new ingest connector-fleet's PII store (`ingest.record_versions`) has no foreign key to its parent chain — account deletion never reaches it and nothing purges it
    - **Where:** `supabase/migrations/20260727130000_ingest_schema.sql:107-172` (`ingest.record_versions`, `ingest.effects`); `app/Services/User/AccountDeletionService.php` (no reference to `ingest.*` anywhere in `purge()`)
    - **Affects:** Every professional who has connected a platform through the new ingest fleet (Instagram, Google Business, Spotify, YouTube, Twitch, Skool, Strava, Gumroad, menu connectors, etc. — the ten §11 connectors merged in `11c399ab`), plus third parties whose data those connectors land (Google reviewers' names/photos, venue/organiser details). Their PII is captured here and outlives account deletion indefinitely.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add an explicit purge step to `AccountDeletionService::purge()` that deletes `ingest.record_versions` / `ingest.record_state` / `ingest.effects` rows for every stream under the user's `ingest.sources` rows, mirroring the pattern already used for `analytics.item_views`/`analytics.action_events` (PRIV-3) and `moderation.evidence` (PRIV-4) — tables that also lack a usable cascade path.
        - Add a companion purge for `ingest.effects.body_ref` — the on-disk response bodies referenced by that column live outside Postgres entirely and need an explicit file-delete alongside the row delete.
        - Decide and document whether `content.sources`/`content.items` (which DO carry `ON DELETE CASCADE` from `core.users` and are therefore already erased correctly) should also be added to `DataExportPayloadBuilder::COVERED_PII_TABLES` so a professional's synced business content (their own tracks/posts/menu items) surfaces in their DSAR.
    - **Technical:** `ingest.sources`, `ingest.streams`, and `ingest.record_state` all carry proper `ON DELETE CASCADE` chains back to `core.users`, so `AccountDeletionService::purge()`'s comment "DB handles cascades (42 FKs CASCADE...)" is correct for those tables. But `ingest.record_versions` — the actual PII-bearing content store, hash-partitioned across 8 partitions for scale — declares `"stream_id" uuid NOT NULL` with **no `REFERENCES` clause at all**, and `ingest.effects` declares `"source_id" uuid` with no FK either. Verified via `Grep` across `app/`: nothing in the codebase issues an explicit `DELETE FROM ingest.record_versions` or `ingest.effects` keyed by user/source — `AccountDeletionService.php` contains zero references to any `ingest.*` table. This is the same "FK doesn't reach it, needs an explicit purge call" shape the codebase has already fixed four times over (PRIV-3, PRIV-4, PRIV-7 Gap 1/2 markers throughout `AccountDeletionService`) — it just hasn't been done yet for the newest schema, which landed this week (`11c399ab`, `694906b7`). This is a deletion path that silently abandons PII forever with no retention bound, which is this lens's explicit P0 criterion.
    - **Plain English:** Partna just shipped a new system that pulls in a professional's content from ten new platforms — Instagram, Spotify, Google reviews, and more. That system keeps a permanent, versioned copy of everything it fetches, including other people's names and photos (like Google reviewers). Every other place in Partna that holds personal data has a matching "when the account is deleted, wipe this too" step — this new system is the one place that step was never built. Today, if someone deletes their Partna account, this stash of fetched content — including strangers' names and photos — just keeps sitting in the database forever with no expiry date and no way to remove it. That's the kind of gap that turns into an unfixable legal problem the day someone files a deletion or breach request.
    - **Evidence:**
        ```sql
        CREATE TABLE "ingest"."record_versions" (
            "id" bigserial NOT NULL,
            "stream_id" uuid NOT NULL,
            "key" text NOT NULL,
            "doc_hash" text NOT NULL,
            "doc" jsonb NOT NULL,                    -- verbatim, POST-redaction
            "first_seen_run" uuid,
            "first_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
            "is_current" boolean NOT NULL DEFAULT true,
            PRIMARY KEY ("id", "stream_id")
        ) PARTITION BY HASH ("stream_id");
        ```
        ```sql
        CREATE TABLE "ingest"."effects" (
            "digest" text PRIMARY KEY,
            "run_id" uuid,
            "source_id" uuid,
            ...
            -- Response bodies live on the private ingest disk, not in Postgres.
            "body_ref" text,
            "meta" jsonb NOT NULL DEFAULT '{}'::jsonb
        );
        ```

## P1 — Fix before pilot launch

- [x] **#PRIV-2** · P1 — GDPR data export ships third-party PII (Google reviewer names/photos, event organiser/venue details) verbatim from `IntegrationConnection.payload`
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:341-359` (`streamIntegrations()`); `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:78-224` (`ALLOWLIST`)
    - **Affects:** Any account holder who has connected Google Business, Eventbrite, or Humanitix and requests their data export — the export includes reviewer display names, review text, and organiser/venue identity: real people who never signed up for Partna and never consented to appearing in someone else's DSAR archive.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a per-platform "export allowlist" to `DataExportPayloadBuilder::streamIntegrations()` — a sibling to `PublicIntegrationConnectionResource::ALLOWLIST` but scoped for DSAR use (wider than the public contract, since the account holder is entitled to their own operational data, but still excluding third-party fields like `reviews`, `reviewSummary`, `organiser`).
        - Document which keys are withheld and why, in the same style as the existing `PII_DISCLOSURE` constant.
        - This is the same gap tracked in a prior audit as "271-PRIV-2 — Google reviewer PII leaks into DSAR" (open, flagged for pre-pilot revisit) — this closes it.
    - **Technical:** `DataExportPayloadBuilder::streamIntegrations()` selects `site.platform_connections.payload`, decodes the JSONB, and yields it **unfiltered** into the export (`$row['payload'] = $this->decodeJsonColumn($row['payload'] ?? null); yield $row;` — no allowlist, no redaction). Confirmed the `google-business` payload key set includes `reviews`, `reviewSummary` and the `eventbrite`/`humanitix` key sets include `organiser`, `venue`, `location` (verified verbatim in `PublicIntegrationConnectionResource::ALLOWLIST`, which — notably — DOES filter these correctly for the *public* sitepage view via `filterPayload()`; the DSAR path is the one export surface that was never given the same treatment). Every other section of this builder (`streamCustomers`, `streamEnquiries`, `streamContentReports`) carries an explicit allowlist with a documented PRIV rationale — this is the one section that still does a raw pass-through, which is inconsistent with the rest of the file's own established pattern.
    - **Plain English:** When someone connects their Google Business page to Partna, Partna also pulls in their Google reviews — including the reviewer's name and what they wrote. If that professional later asks Partna "send me everything you have about me," Partna currently hands over those reviewers' names and comments too, even though the reviewers never agreed to that. It's like asking for your own personnel file and getting a stack of your customers' feedback forms stapled in by mistake. The fix is to teach the export tool the same "who does this actually belong to" filter the public-facing page already uses.
    - **Evidence:**
        ```php
        // DataExportPayloadBuilder.php — streamIntegrations()
        foreach ($this->lazyRows(...) as $row) {
            $row['payload'] = $this->decodeJsonColumn($row['payload'] ?? null);
            $row['display_settings'] = $this->decodeJsonColumn($row['display_settings'] ?? null);
            yield $row;
        }
        ```
        ```php
        // PublicIntegrationConnectionResource.php — ALLOWLIST (the filter the public page gets, the DSAR export doesn't)
        'google-business' => ['url', 'name', 'address', 'lat', 'lng', 'rating', 'reviewCount', 'businessStatus', 'category', 'phone', 'website', 'hours', 'links', 'reviews', 'reviewSummary', 'editorialSummary', 'amenities', 'photos'],
        'eventbrite' => ['url', 'organiser', 'next', 'upcoming', 'kind', 'id', 'name', 'venue', 'location', ...],
        ```

## P2 — Should fix

- [ ] **#PRIV-3** · P2 — `content.f_review` stores third-party reviewer PII with no independent retention bound or reviewer-initiated erasure path
    - **Where:** `supabase/migrations/20260727140000_content_schema.sql:307-317` (`content.f_review`)
    - **Affects:** Reviewers on Google/other platforms whose name, photo, and written review are landed into a professional's content catalog. Account deletion correctly cascades this data away (verified: `item_id`/`source_id` both `ON DELETE CASCADE` through `content.items`/`content.sources` → `core.users`), but for as long as the connection stays active, there is no TTL on stale reviews and no mechanism for a reviewer to have their own entry found and redacted independently of the professional's account lifecycle.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a bounded retention window for `content.f_review` rows whose source connection has gone stale/disconnected (mirrors the `analytics_raw_event_retention_days` pattern already used elsewhere).
        - Document that reviewer-level erasure (a reviewer contacting Partna directly, independent of the professional deleting their account) currently has no lookup path, and decide whether one is needed pre-pilot or is an accepted gap given the connector fleet is new.
    - **Technical:** `content.f_review` (`author_name`, `author_photo_url`, `text`) is a typed facet table, singleton per `(item_id, source_id)`, refreshed on every ingest run. Deletion-on-account-close is correctly handled by cascade — this is not a deletion gap. The gap is retention while the connection is live: nothing expires a review that's years stale, and there's no reverse index from a reviewer's name back to which rows mention them, which a reviewer's own APP 12/13 request would need.
    - **Plain English:** When Partna pulls in a professional's Google reviews, it keeps the reviewer's name, photo, and comment as long as the professional's account is connected — potentially for years, with no freshness check. If a reviewer ever asked "what do you have about me and can you delete it," there's currently no way to search the system for their name and find every place it appears. This isn't urgent — the reviews do get deleted if the professional's account closes — but it's a gap worth closing before real customer volume makes it a real request.
    - **Evidence:**
        ```sql
        CREATE TABLE "content"."f_review" (
            "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
            "source_id" uuid NOT NULL REFERENCES "content"."sources" ("id") ON DELETE CASCADE,
            "author_name" text,
            "author_photo_url" text,
            "rating" double precision,
            "text" text,
            "reviewed_at" timestamp with time zone,
            "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
            PRIMARY KEY ("item_id", "source_id")
        );
        ```

- [ ] **#PRIV-4** · P2 — `site.site_documents` accumulates unbounded PII-bearing page-version snapshots with no retention rule while a site is active
    - **Where:** `supabase/migrations/20260727150000_sections_and_documents.sql` (`site.site_documents`)
    - **Affects:** Every account holder whose site is edited repeatedly — each build inserts a new full-page snapshot (name, handle, bio, social links, addresses) with no cap. Deletion-on-account-close is fine (`site_id` is `ON DELETE CASCADE` from `site.sites`), so this is a live-account hygiene gap, not an erasure gap.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a retention rule (e.g. keep the latest N versions per channel, or versions younger than X days) and a scheduled command in `routes/console.php`, following the existing pattern of the dozen other `prune-*`/`gc-*` weekly commands already there.
    - **Technical:** The CAS design ("byte-identical rebuilds insert nothing") bounds *redundant* writes but not *distinct* ones — a frequently-edited site accumulates one full JSONB snapshot per real content change, with no cap and no existing scheduled command targeting this table (confirmed absent from `routes/console.php`'s ~30 scheduled entries).
    - **Plain English:** Every time someone tweaks their Partna page, the system saves a brand-new full copy of the finished page — including their name, bio, and contact details. After months of edits, there could be hundreds of these full snapshots sitting in the database with no rule to ever thin them out. It's not a deletion problem — closing the account does clean it up — but it's an unbounded pile of personal-data snapshots that should be capped while the account is still active.
    - **Evidence:**
        ```sql
        CREATE TABLE "site"."site_documents" (
            "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            "site_id" uuid NOT NULL REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
            "version" bigint NOT NULL,
            "channel" text NOT NULL DEFAULT 'live' CHECK ("channel" IN ('live', 'draft')),
            "document" jsonb NOT NULL,
            ...
            CONSTRAINT "site_documents_version_unique" UNIQUE ("site_id", "channel", "version")
        );
        ```

- [ ] **#PRIV-5** · P2 — Full URLs including query strings are stored verbatim across the new routing/content schemas, with no stripping of embedded PII (tokens, emails in tracking params)
    - **Where:** `routing.link_observations.raw_url`, `routing.source_intents.canonical_url` (`20260727120000_routing_schema.sql:17-18,57`); `routing.import_runs.source_url` (`:111`); `content.f_link.url`/`canonical_url`, `content.offers.url`, `content.f_file.file_url`, `content.media_assets.source_url` (`20260727140000_content_schema.sql`)
    - **Affects:** Account holders whose pasted or connector-fetched URLs carry query-string PII (email in a UTM param, an identity token in a signed URL); this data is retained indefinitely (or per the long `link_observations` partition window) with no stripping.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Strip query strings from `raw_url`/`source_url`/`canonical_url` at write time in the connectors/routing layer, keeping scheme+host+path — mirroring the fix `AnalyticsEventSanitizer::referrer()` already applies to the analytics `referrer` column (see PRIV-6 note below: this exact minimisation pattern is already proven out elsewhere in the codebase, it just hasn't been extended to the new routing/content schema's URL columns).
        - Where a specific query parameter is genuinely needed (UTM campaign attribution), extract only that key and discard the rest.
    - **Technical:** Confirmed via direct read of both migrations: none of these URL columns have any stripping applied at the type/constraint level, and the `content` schema's own comment block documents that `ingest.record_versions.doc` is "verbatim, POST-redaction" for connector-fetched JSON but does not extend that redaction discipline to the sibling URL columns landed by the same pipeline. The codebase already has a working precedent for exactly this fix (`AnalyticsEventSanitizer::referrer()` strips query+fragment before storage) — this is a matter of applying the same pattern to the newer schema, not inventing a new one.
    - **Plain English:** When someone pastes a link into their profile, or when Partna's connectors fetch a link from a platform, the whole address — including anything tacked onto the end after a `?` — gets saved forever. Some of those tacked-on bits can be tracking codes, session tokens, or even email addresses that a website put in its own links. Partna already has a fix for exactly this problem in one part of the system (the visitor-traffic tracker) — it just hasn't been applied to this newer part yet.
    - **Evidence:**
        ```sql
        "raw_url" text NOT NULL,
        "canonical_url" text,
        ```
        ```sql
        "source_url" text,
        ```

- [ ] **#PRIV-6** · P2 — Analytics stores per-visit lat/lon coordinates as distinct rows rather than a pre-aggregated city centroid
    - **Where:** `app/Services/Analytics/AnalyticsQueryService.php:514-530` (`cities()`)
    - **Affects:** Visitors whose edge-resolved geolocation is captured per visit in `analytics.site_visits.latitude`/`longitude`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - If the query-time `AVG()` + 4-decimal rounding is the intended minimisation boundary, move that rounding to the ingest path (`PostgresEventWriter`) so the raw, unrounded, per-visit coordinate never lands in Postgres in the first place — the same "sanitize at write time, not at read time" principle already applied to `referrer` and `user_agent` via `AnalyticsEventSanitizer`.
    - **Technical:** `cities()` computes `AVG(latitude)`/`AVG(longitude)` grouped by `(city, country_code)` and rounds the *result* to 4 decimal places — the fact that an average is needed confirms distinct per-visit values are stored, not a single city centroid. `PostgresEventWriter` writes `$e->latitude`/`$e->longitude` directly with no rounding or truncation at write time (confirmed via grep — no `round()`/truncation call found in the writer or in `AnalyticsEventSanitizer`, which handles `referrer` and `user_agent` but has no equivalent method for coordinates). Geo-IP resolution is inherently coarser than GPS, so the real-world exposure is likely modest, but the same write-time minimisation discipline the codebase already applies to UA/referrer has not been extended to coordinates.
    - **Plain English:** When someone visits a professional's page, the system notes roughly where they are, based on their internet connection's location (not GPS) — and saves that exact reading for every single visit, rather than saving just "this city" once. The dashboard only ever shows a city name, so keeping the precise per-visit reading indefinitely is more than the feature needs.
    - **Evidence:**
        ```php
        ->selectRaw("city, country_code, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as visitors, AVG(latitude) as latitude, AVG(longitude) as longitude")
        ->groupBy('city', 'country_code')
        ...
        'latitude' => $r->latitude !== null ? round((float) $r->latitude, 4) : null,
        ```

## P3 — Nice to have

- [ ] **#PRIV-7** · P3 — `SiteMedia.original_filename` retains user-uploaded filenames verbatim with no minimisation
    - **Where:** `app/Models/Core/Site/SiteMedia.php` (`original_filename` in `$fillable`); also exported via `DataExportPayloadBuilder::streamMedia()`
    - **Affects:** Users who upload files named after themselves or their business (e.g. `Jane_Doe_Headshot.jpg`) — retained in the DB (and included in their own export, correctly) indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If the original filename has no product use beyond processing, discard it after processing completes rather than persisting it for the media row's full lifetime.
    - **Technical:** `original_filename` is fillable and written on create with no subsequent clearing step visible in the media pipeline. It IS correctly included in the DSAR export (`streamMedia()`) and is purged on account/media deletion — this is a minimisation-at-collection nit, not an export or deletion gap.
    - **Plain English:** When someone uploads a photo called `Jane_Doe_Headshot.jpg`, that exact filename is kept in the database for as long as the photo exists. It's already included correctly in their own data export and gets deleted when they delete the photo — so there's no compliance failure here — but if the filename isn't used for anything after the image is processed, there's no reason to hold onto it at all.
    - **Evidence:**
        ```php
        protected $fillable = [
            'pool', 'bucket', 'path', 'alt_text', 'caption', 'purpose',
            'sort_order', 'is_active', 'media_type', 'processing_state',
            'processing_error', 'original_mime', 'original_filename',
            'original_size_bytes', 'duration_ms', 'poster_path',
            'dominant_color', 'palette',
        ];
        ```

- [ ] **#PRIV-8** · P3 — Several `AnalyticsQueryService` log call sites pass the raw `user_id` instead of the existing `scopeForLog()` helper
    - **Where:** `app/Services/Analytics/AnalyticsQueryService.php` (e.g. lines 243, 268, 453, 570, 591, 602, 642, 672, 717, 749, 790)
    - **Affects:** Internal telemetry only — a professional's UUID reaches Nightwatch on a query failure, where a coarser marker would do.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route every single-user log context through `scopeForLog($userId)` instead of passing `$userId` directly, for consistency with the call sites that already do this correctly.
    - **Technical:** The file already has a `scopeForLog()` helper that abstracts a user scope to `segment(N users)`/`all-users`, and roughly half the `Log::warning` call sites use it correctly. The other half (confirmed via grep — 11+ call sites) pass `'user_id' => $userId` directly. Low sensitivity (a UUID, not a name/email) and diagnostics-only, but inconsistent with the file's own established pattern.
    - **Plain English:** When an analytics chart fails to load, the system sometimes logs "which user" by their unique ID into the engineering alert stream — even though about half the similar log lines already avoid doing that. It's a small inconsistency worth cleaning up, not a real exposure.
    - **Evidence:**
        ```php
        Log::warning('analytics.insight_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — New ingest/content schema retention hardening:** #PRIV-3, #PRIV-4, #PRIV-5
    - **Why grouped:** All three are retention/minimisation gaps in the schema that landed this week (`content.*`, `routing.*`, `site.site_documents`) — same review context, same "add a scheduled prune command or write-time strip" shape.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Analytics log & geo hygiene:** #PRIV-6, #PRIV-8
    - **Why grouped:** Both live in `AnalyticsQueryService.php`/`AnalyticsEventSanitizer.php`; both are "extend an existing minimisation pattern that's already proven elsewhere in the same file."
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Media minimisation:** #PRIV-7
    - **Why grouped:** Standalone-sized (S effort), no natural pairing; grouped here only to avoid a one-item ceremony session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#PRIV-1 — ingest.record_versions has no deletion path** · P0, and touches the account-deletion service (a security/data-integrity-critical path) plus a schema-relationship decision (whether to add an FK or handle purge at the app layer) — needs its own plan and sign-off before implementation.
- **#PRIV-2 — DSAR export leaks third-party PII from platform-connection payloads** · Distinct fix scope from every other finding here (the export builder's `streamIntegrations()` method only), carries real external third-party legal exposure (a previously-tracked, still-open issue), and warrants isolated review rather than folding into a general bundle.
