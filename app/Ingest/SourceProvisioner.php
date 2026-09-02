<?php

namespace App\Ingest;

use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Payloads\FreshaSelection;
use App\Services\Platforms\TiktokShopScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The seam between a platform connection and the ingest pipeline (plan §4):
 * one ingest.sources row per (connection × registered connector), created when
 * the connection lands and kept in step as it changes.
 *
 * The mapping is deliberately derivational, not configured: a connection's
 * surface key is `{brand}.{product}`, and a brand with a registered connector
 * is ingestible — nothing else has to be told about a new connector beyond
 * its ConnectorRegistry line.
 *
 * The hard part is the identifier. Legacy connections carry their identity in
 * `payload` under per-platform keys (the exact blob shape this pipeline
 * replaces), newer seeded rows carry it in `resource_id`, and Google carries
 * it in `place_id`. Each derivation here reads those in preference order and
 * returns null rather than guess — a source row with a wrong identifier would
 * fetch somebody else's catalogue, which is far worse than no row plus a
 * skip report the backfill command surfaces.
 */
class SourceProvisioner
{
    /** Never let a manifest band collapse to a point: cap-floor at 7 days. */
    private const MAX_INTERVAL_FLOOR_SECS = 604800;

    /**
     * Create or update the ingest.sources row for one connection.
     *
     * @return array{status: string, source_key?: string, reason?: string}
     */
    public function sync(IntegrationConnection $connection): array
    {
        if ($connection->resource_kind !== null) {
            // event-/link-grade rows are routing artefacts, not accounts —
            // there is nothing to poll behind them.
            return ['status' => 'skipped', 'reason' => 'resource_row'];
        }

        $sourceKey = self::sourceKeyFor((string) $connection->getAttributes()['surface_key']);
        if ($sourceKey === null) {
            return ['status' => 'skipped', 'reason' => 'no_connector'];
        }

        // A FORCE delete leaves no row behind and no deleted_at to read, so
        // trashed() is false and the provisioning below would insert an
        // ingest.sources row pointing at a platform_connections id that no
        // longer exists — a 23503 the observer then has to catch and report
        // (Nightwatch #469). Force deletion is a real path: account erasure,
        // GDPR, and the connection-cleanup lanes all use it. Retiring here is
        // both correct and cheap; the row's own FK cascade removes the source.
        if ($connection->trashed() || $connection->isForceDeleting()) {
            $this->setAutoSync($connection->id, $sourceKey, false);

            return ['status' => 'retired', 'source_key' => $sourceKey];
        }

        if (! $connection->is_active) {
            // A deactivated connection keeps its landed history but stops
            // being scheduled; never CREATE a row for one.
            $this->setAutoSync($connection->id, $sourceKey, false);

            return ['status' => 'deactivated', 'source_key' => $sourceKey];
        }

        $identifier = $this->identifierFor($sourceKey, $connection);
        if ($identifier === null) {
            // An EXISTING row is not the same case as a missing one, and this
            // check sits in FRONT of both the retirement path above and the
            // identifier update below — so skipping both left the row that
            // provoked the profile.php fix (identifier
            // "https://www.facebook.com/profile.php") holding a dead
            // identifier and its auto_sync untouched, indefinitely: for a
            // schedulable connector that is cost_units spent every tick on a
            // fetch that can only report unavailable.
            //
            // Retire, not delete: the row owns the streams and records already
            // landed under it, and the stale identifier is the only evidence
            // of what the connection used to claim. Nor is it a one-way door —
            // the update path below turns auto_sync back on and re-dates
            // next_attempt_at the moment a resolvable identifier appears, so a
            // payload that is merely mid-write costs one unscheduled tick.
            if ($this->existingRow($connection->id, $sourceKey) !== null) {
                $this->setAutoSync($connection->id, $sourceKey, false);

                return ['status' => 'retired', 'reason' => 'no_identifier', 'source_key' => $sourceKey];
            }

            return ['status' => 'skipped', 'reason' => 'no_identifier', 'source_key' => $sourceKey];
        }

        $manifest = ConnectorRegistry::manifestFor($sourceKey);

        $selectionRef = $this->selectionRefFor($sourceKey, $connection);

        $existing = $this->existingRow($connection->id, $sourceKey);

        if ($existing === null) {
            // #LIFE-14: was a bare pre-read SELECT -> conditional INSERT with no
            // catch anywhere in the call chain — two concurrent saves for the
            // SAME connection (e.g. the observer's saved() firing twice in close
            // succession) would both see "no existing row" and race straight
            // into sources_unique_per_connection, and the loser raised an
            // unhandled UniqueConstraintViolationException. insertOrIgnore
            // compiles to `ON CONFLICT DO NOTHING` (pgsql) / `INSERT OR IGNORE`
            // (sqlite) — same fix, same wording as
            // App\Routing\SourceReconciler::upsertIntent() — so a 0-row return
            // means a concurrent winner, not a failure. The loser then falls
            // through into the update path below exactly as a caller that
            // arrived 1ms later would: it must NOT report 'created' for a row it
            // did not insert (see maybeRunEagerly()'s created/reselected gate),
            // and it must still land its own identifier/selection_ref, which
            // the winner's row may not carry (e.g. a deferred-connect payload
            // fill racing the initial connect).
            $inserted = DB::table('ingest.sources')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $connection->user_id,
                'connection_id' => $connection->id,
                'source_key' => $sourceKey,
                'surface_key' => (string) $connection->getAttributes()['surface_key'],
                'identifier' => $identifier,
                'selection_ref' => $selectionRef,
                'cost_units' => $manifest->cost->budgetWeight(),
                'min_interval_secs' => $manifest->defaultIntervalSeconds,
                'max_interval_secs' => max($manifest->defaultIntervalSeconds, self::MAX_INTERVAL_FLOOR_SECS),
                'next_attempt_at' => now(),
                'auto_sync' => self::schedulable($manifest),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted > 0) {
                return ['status' => 'created', 'source_key' => $sourceKey];
            }

            $existing = $this->existingRow($connection->id, $sourceKey);

            if ($existing === null) {
                // The winner's row was deleted between the conflict and this
                // re-read (e.g. account deletion racing the connect) — nothing
                // left to update.
                return ['status' => 'skipped', 'reason' => 'insert_lost', 'source_key' => $sourceKey];
            }
        }

        // Update ONLY identity + activation — scheduling state (claims,
        // backoff, EWMA, health) belongs to the scheduler, and clobbering it
        // here would reset a source's learned cadence on every payload write.
        $update = ['updated_at' => now()];
        if ((string) $existing->identifier !== $identifier) {
            $update['identifier'] = $identifier;
            // A different identifier is a different remote thing: fetch soon.
            $update['next_attempt_at'] = now();
        }
        if ((string) ($existing->selection_ref ?? '') !== (string) ($selectionRef ?? '')) {
            $update['selection_ref'] = $selectionRef;
            // A different selection is a different menu at different prices:
            // without this the change waits out max_interval_secs (7 days).
            $update['next_attempt_at'] = now();
            // ...and what the NEW menu does not list is gone NOW, not after
            // tombstone_runs (3) more absences — a business that narrows to
            // one stylist must not keep publishing the other 5 storewide-only
            // services for a week (overnight 2026-08-18 W6). Pre-charge every
            // live record so the very next run's absence fold tombstones it.
            $tombstoneRuns = max(1, (int) config('partna.ingest.tombstone_runs', 3));
            DB::table('ingest.record_state')
                ->whereIn('stream_id', DB::table('ingest.streams')->where('source_id', $existing->id)->select('id'))
                ->whereNull('tombstoned_at')
                ->update(['absent_runs' => $tombstoneRuns - 1]);
        }
        if (! $existing->auto_sync && self::schedulable($manifest)) {
            $update['auto_sync'] = true;
        }
        // ...and OFF again when a connector has BECOME paid. Phase 4 flipped
        // spotify/soundcloud from keyless oEmbed to Apify actors; without this
        // their free-era rows would have kept auto-dispatching a now-billed
        // connector on the scheduler's cadence. The seam that makes a connector
        // paid has to be the seam that stops it auto-running, because nothing
        // else looks at cost class again after the row is created.
        if ($existing->auto_sync && ! self::schedulable($manifest)) {
            $update['auto_sync'] = false;
        }
        // cost_units was written once at insert and never revisited, so a
        // connector that changed cost class kept charging the scheduler its OLD
        // weight — a paid actor budgeted as if it were free RSS.
        if ((int) $existing->cost_units !== $manifest->cost->budgetWeight()) {
            $update['cost_units'] = $manifest->cost->budgetWeight();
        }
        DB::table('ingest.sources')->where('id', $existing->id)->update($update);

        // A changed selection (a different Fresha team member, a different
        // storewide/employee mode) is a different menu: report it distinctly so
        // the observer can run the source NOW instead of leaving the pool
        // empty until the scheduler's next tick (overnight 2026-08-18 W6).
        if (array_key_exists('selection_ref', $update)) {
            return ['status' => 'reselected', 'source_key' => $sourceKey];
        }

        return ['status' => count($update) > 1 ? 'updated' : 'unchanged', 'source_key' => $sourceKey];
    }

    /** The row this connection's source lives in, with exactly the columns sync() compares. */
    private function existingRow(string $connectionId, string $sourceKey): ?object
    {
        return DB::table('ingest.sources')
            ->where('connection_id', $connectionId)
            ->where('source_key', $sourceKey)
            ->first(['id', 'identifier', 'auto_sync', 'selection_ref', 'cost_units']);
    }

    /**
     * Whether the scheduler may run this source TODAY. Billed connectors stay
     * OFF the dispatcher even now that their drivers exist (slice 0): enabling
     * paid auto-sync is a spend decision that belongs to the slice which uses the
     * data, not to the seam that makes it possible. Flipping this predicate is a
     * one-line change plus a deliberate look at PlacesBudget/ApifyBudget headroom.
     */
    private static function schedulable(Manifest $manifest): bool
    {
        if ($manifest->cost === CostClass::Free) {
            return true;
        }
        // Owner ruling R8 (overnight 2026-08-18): named paid connectors run
        // on the scheduler under their budget caps — google_business every
        // 2d, spotify/soundcloud weekly (manifest defaultIntervalSeconds).
        // Instagram stays connect+Resync only; the menu actors stay on the
        // legacy MenuFetchJob lane. Config-driven so a spend incident is an
        // env change, not a deploy.
        $allowed = (array) config('partna.ingest_scheduled_paid_sources', []);

        return in_array($manifest->source->value, $allowed, true);
    }

    /** The brand half of a surface key, when a registered connector serves it. */
    public static function sourceKeyFor(string $surfaceKey): ?string
    {
        // A surface may own a connector of its own (square.book → square_book)
        // when its brand's connector serves a DIFFERENT surface (square →
        // SquareMenuConnector serves square.order). Surface-specific first,
        // brand second; the brand fallback is unchanged for every other key.
        if (str_contains($surfaceKey, '.')) {
            $bySurface = str_replace('.', '_', $surfaceKey);
            if (ConnectorRegistry::has($bySurface)) {
                return $bySurface;
            }
        }
        $brand = strstr($surfaceKey, '.', true);
        if ($brand === false || $brand === '') {
            return null;
        }

        return ConnectorRegistry::has($brand) ? $brand : null;
    }

    private function setAutoSync(string $connectionId, string $sourceKey, bool $on): void
    {
        DB::table('ingest.sources')
            ->where('connection_id', $connectionId)
            ->where('source_key', $sourceKey)
            ->update(['auto_sync' => $on, 'updated_at' => now()]);
    }

    /**
     * Derive the connector's Pull identifier from what the connection
     * actually stores. Preference order per platform: the typed payload key
     * the legacy fetcher read, then a resource_id that is a REAL identifier
     * (seeded/newer rows), never a legacy placeholder slug or an acct- ref.
     */
    private function identifierFor(string $sourceKey, IntegrationConnection $connection): ?string
    {
        $payload = $connection->payload ?? [];
        $resource = trim((string) $connection->resource_id);

        return match ($sourceKey) {
            'bandcamp' => $this->httpUrl($payload['url'] ?? null, 'bandcamp.com')
                ?? $this->httpUrl($resource, 'bandcamp.com'),
            'eventbrite' => $this->eventbriteOrgUrl($payload['url'] ?? null)
                ?? $this->eventbriteOrgUrl($resource),
            'humanitix' => $this->humanitixHostUrl($payload['url'] ?? null)
                ?? $this->humanitixHostUrl($resource),
            'vimeo' => $this->cleanString($payload['apiPath'] ?? null)
                ?? $this->bareSlug($resource, 'vimeo'),
            // `handle` is the legacy connect flow's key; `username` is what
            // ConnectionPayload::forWrite writes for every handle-kind surface
            // the router places. Same fact, two spellings — both provision.
            'youtube' => $this->cleanString($payload['channelId'] ?? null)
                ?? $this->youtubeChannelId($resource)
                ?? $this->bareSlug($payload['handle'] ?? null, 'youtube')
                ?? $this->bareSlug($payload['username'] ?? null, 'youtube'),
            // No handle fallback: the legacy youtube-music connect flow always
            // resolved and stored the Topic channel's UC… id.
            'youtube_music' => $this->youtubeChannelId((string) ($payload['channelId'] ?? ''))
                ?? $this->youtubeChannelId($resource),
            'soundcloud' => $this->soundcloudUrl($payload['url'] ?? $payload['link'] ?? null)
                ?? $this->soundcloudUrl($resource)
                ?? ($this->bareSlug($resource, 'soundcloud') === null
                    ? null
                    : 'https://soundcloud.com/'.strtolower((string) $this->bareSlug($resource, 'soundcloud'))),
            'spotify' => $this->httpUrl($payload['url'] ?? $payload['link'] ?? null, 'spotify.com')
                ?? $this->spotifyEntityUrl($resource),
            'spotify_podcasts' => $this->httpUrl($payload['url'] ?? $payload['link'] ?? null, 'spotify.com')
                ?? $this->spotifyEntityUrl($resource),
            'apple_music', 'apple_podcasts' => $this->appleId($payload['input'] ?? null)
                ?? $this->appleId($resource),
            'fresha' => $this->freshaSlug($payload['url'] ?? null)
                ?? $this->bareSlug($resource, 'fresha'),
            'instagram' => $this->instagramUsername($payload['username'] ?? null)
                ?? $this->instagramUsername($this->bareSlug($resource, 'instagram')),
            // Wave 4 (2026-09-01): threads handles mirror instagram usernames.
            'threads' => $this->instagramUsername($payload['username'] ?? null)
                ?? $this->instagramUsername($this->bareSlug($resource, 'threads')),
            'bluesky' => $this->bareSlug($payload['username'] ?? null, 'bluesky')
                ?? $this->bareSlug($resource, 'bluesky'),
            // Item 10c follow-up (2026-09-02, found in the wiring campaign):
            // pinterest had NO arm, so a connected profile never provisioned
            // its boards source. The profile URL's first path segment is the
            // handle (BrandLinkConnect payloads carry url only).
            'pinterest' => $this->bareSlug($payload['username'] ?? null, 'pinterest')
                ?? $this->pinterestHandle($payload['url'] ?? $payload['link'] ?? null)
                ?? $this->bareSlug($resource, 'pinterest'),
            'twitch' => $this->bareSlug($payload['username'] ?? null, 'twitch')
                ?? $this->bareSlug($resource, 'twitch'),
            'tiktok_shop' => TiktokShopScraper::sellerIdFrom($resource),
            // T27c: the handle off a tiktok.com/@… connect (handle-kind
            // surfaces write `username`; older rows may carry it in resource).
            'tiktok' => $this->tiktokUsername($payload['username'] ?? null)
                ?? $this->tiktokUsername($this->bareSlug($resource, 'tiktok')),
            // T27c: the canonical page URL off a facebook.com handle connect.
            // Per-candidate fall-through, not one call over `url ?? username`:
            // ?? short-circuits on the PRESENT key, so a url that normalises
            // to nothing took the username's answer down with it. profile.php
            // is exactly that pair — GoogleBusinessAutoSync seeds {username:
            // <id>, url: profile.php?id=<id>} for it (bondi-junction-dental,
            // 2026-08-31) — and the id alone resolves, so the arm below is a
            // working identifier the old shape discarded.
            'facebook' => $this->facebookPageUrl($payload['url'] ?? null)
                ?? $this->facebookPageUrl($payload['username'] ?? null)
                ?? $this->facebookPageUrl($this->bareSlug($resource, 'facebook')),
            // Wave 2: the artist slug off a dice.fm/artist/<slug> URL.
            'dice' => $this->diceSlug($payload['url'] ?? $payload['link'] ?? null)
                ?? $this->diceSlug($resource),
            // Wave 2: the numeric artist id off a deezer.com/artist URL.
            'deezer' => $this->deezerArtistId($payload['url'] ?? $payload['link'] ?? null)
                ?? $this->deezerArtistId($resource),
            // T27b: the calendar slug off a lu.ma URL (multi-segment kept —
            // personal calendars live at /u/<id>).
            'luma' => $this->lumaSlug($payload['url'] ?? $payload['link'] ?? null)
                ?? $this->lumaSlug($resource)
                ?? $this->bareSlug($resource, 'luma'),
            // T27b: the locale-qualified venue path off a booksy.com URL.
            'booksy' => $this->booksyPath($payload['url'] ?? $payload['link'] ?? null)
                ?? $this->booksyPath($resource),
            // T27b: the venue page URL itself (locale TLDs vary; the URL is
            // the stable identity).
            'treatwell' => $this->treatwellUrl($payload['url'] ?? $payload['link'] ?? null)
                ?? $this->treatwellUrl($resource),
            // T27b: the artist slug off an ra.co/dj/<slug> URL.
            'resident_advisor' => $this->residentAdvisorSlug($payload['url'] ?? $payload['link'] ?? null)
                ?? $this->residentAdvisorSlug($resource)
                ?? $this->bareSlug($resource, 'resident_advisor'),
            // T27b: the profile URL's first path segment IS the API username.
            'mixcloud' => $this->mixcloudUsername($payload['url'] ?? $payload['link'] ?? null)
                ?? $this->mixcloudUsername($resource)
                ?? $this->bareSlug($resource, 'mixcloud'),
            'google_business' => $this->cleanString($connection->place_id)
                ?? $this->cleanString($payload['placeId'] ?? null)
                ?? $this->googlePlaceId($resource),
            // Menu brands: only a URL the platform's own menu host-pattern
            // recognises is a scrapeable store — a square.book (squareup.com)
            // booking link must never provision a menu source.
            // Square Appointments (2026-09-02): the booking page URL itself,
            // cleaned. A bare square.site root carries no merchant id and is
            // NOT a booking page we can read — null, which also ends the menu
            // scrape a booking connection used to provision for that host.
            'square_book' => $this->squareBookingUrl($payload['url'] ?? null)
                ?? $this->squareBookingUrl($resource),
            'square' => $this->menuStoreUrl('square', $payload['url'] ?? null)
                ?? $this->menuStoreUrl('square', $resource),
            'uber_eats' => $this->menuStoreUrl('uber-eats', $payload['url'] ?? null)
                ?? $this->menuStoreUrl('uber-eats', $resource),
            'doordash' => $this->menuStoreUrl('doordash', $payload['url'] ?? null)
                ?? $this->menuStoreUrl('doordash', $resource),
            default => null,
        };
    }

    /**
     * Which sub-account's view of the remote thing to fetch. Only Fresha has
     * one today; the match arm exists so a later connector adds a line, not a
     * mechanism. Returns null when nothing has been chosen -- the connector
     * treats that as "land nothing", never as "fetch everything" (spec §2).
     */
    private function selectionRefFor(string $sourceKey, IntegrationConnection $connection): ?string
    {
        return match ($sourceKey) {
            'fresha' => $this->freshaSelectionRef($connection->payload['selection'] ?? null),
            default => null,
        };
    }

    /**
     * 'employee' -> the employee id; 'storewide' -> the reserved token; else
     * null. Mode must say 'employee' explicitly -- mirrors FreshaFetch's own
     * gate (App\Services\Platforms\Strategies\Fetch\FreshaFetch::fetch(),
     * `$isEmployeeMode = ($selection['mode'] ?? null) === 'employee' && ...`)
     * so a stale employee object with no/other mode can never make this class
     * believe someone is selected when the scheduled re-fetch would not.
     */
    private function freshaSelectionRef(mixed $selection): ?string
    {
        if (! is_array($selection)) {
            return null;
        }

        $dto = FreshaSelection::fromArray($selection);
        if ($dto->mode() === 'storewide') {
            return 'storewide';
        }
        if ($dto->mode() !== 'employee') {
            return null;
        }

        $employeeId = $dto->employee()['employeeId'] ?? null;
        if (! is_scalar($employeeId)) {
            return null;
        }

        $employeeId = trim((string) $employeeId);

        // A malformed/adversarial scrape could hand back an id equal to the
        // reserved token; without this guard that id would make the connector
        // fetch the WHOLE STORE'S menu for one individual's page.
        return $employeeId === '' || $employeeId === 'storewide' ? null : $employeeId;
    }

    private function cleanString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** An http(s) URL whose registrable host ends in $hostSuffix. */
    private function httpUrl(mixed $value, string $hostSuffix): ?string
    {
        $value = $this->cleanString($value);
        if ($value === null || ! preg_match('~^https?://~i', $value)) {
            return null;
        }
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        return $host === $hostSuffix || str_ends_with($host, '.'.$hostSuffix) ? $value : null;
    }

    /**
     * A bare identifier that is genuinely one: not the legacy per-platform
     * placeholder slug, not a synthetic acct-/link-/order-/event- ref, no
     * URL-ish shape.
     */
    /** The locale-qualified venue path of a booksy.com URL, or null. */
    /**
     * The Square Appointments booking URL reduced to what identifies the
     * page: merchant, optional location, and the team_member_id the owner's
     * link carries. Presentation params (buttonTextColor, color, locale,
     * referrer) are dropped so a re-paste with different button colours is
     * the same source.
     */
    private function squareBookingUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('~^https?://~i', $value)) {
            return null;
        }
        $parts = parse_url(trim($value));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, ['book.squareup.com', 'app.squareup.com', 'squareup.com', 'www.squareup.com'], true)) {
            return null;
        }
        if (preg_match('~^/appointments/(?:book/)?([a-z0-9]{8,32})(?:/(?:location/)?([A-Z0-9]{8,32}))?~i', (string) ($parts['path'] ?? ''), $m) !== 1) {
            return null;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $teamMember = isset($query['team_member_id']) && is_string($query['team_member_id'])
            && preg_match('/^TM[A-Za-z0-9_-]{4,64}$/', $query['team_member_id']) === 1
            ? $query['team_member_id'] : null;
        $url = 'https://book.squareup.com/appointments/'.strtolower($m[1]);
        if (isset($m[2]) && $m[2] !== '') {
            $url .= '/location/'.strtoupper($m[2]);
        }

        return $teamMember === null ? $url : $url.'?team_member_id='.rawurlencode($teamMember);
    }

    private function booksyPath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('~^https?://(?:www\.)?booksy\.com/([a-z]{2}(?:-[a-z]{2})?/[0-9]+_[A-Za-z0-9_-]+)/?~i', trim($value), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** A treatwell venue-page URL (any locale TLD), or null. */
    private function treatwellUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('~^https?://(?:www\.)?treatwell\.(?:com|co\.uk|de|fr|nl|es|it|be|at|ch|ie|pt|lt|lv|gr)/place/[A-Za-z0-9_-]+/?~i', $value) === 1) {
            return $value;
        }

        return null;
    }

    /** The artist slug of an ra.co/dj/<slug> URL, or null. */
    private function residentAdvisorSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('~^https?://(?:www\.)?ra\.co/dj/([A-Za-z0-9_-]+)/?~i', trim($value), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** The calendar slug of a lu.ma URL (path, sans leading slash), or null. */
    private function lumaSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('~^https?://(?:www\.)?lu\.ma/([A-Za-z0-9_/-]+?)/?$~i', trim($value), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** The username segment of a mixcloud.com profile/show URL, or null. */
    /** The pinterest handle off a pinterest.com profile URL's first path segment. */
    private function pinterestHandle(mixed $url): ?string
    {
        $url = $this->cleanString($url);
        if ($url === null) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! preg_match('/(^|\.)pinterest\.[a-z.]+$/', $host) && $host !== 'pin.it') {
            return null;
        }
        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));
        $first = $segments[0] ?? null;

        return is_string($first) && preg_match('/^[A-Za-z0-9_]{1,60}$/', $first) === 1 ? strtolower($first) : null;
    }

    private function mixcloudUsername(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('~^https?://(?:www\.)?mixcloud\.com/([A-Za-z0-9_-]+)/?~i', trim($value), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function bareSlug(mixed $value, string $legacyPlaceholder): ?string
    {
        $value = $this->cleanString($value);
        if ($value === null
            || strcasecmp($value, $legacyPlaceholder) === 0
            || preg_match('/^(acct|link|order|event)-[0-9a-f]{16}$/i', $value)
            || str_contains($value, '://')) {
            return null;
        }

        return ltrim($value, '@');
    }

    private function youtubeChannelId(string $value): ?string
    {
        return preg_match('/^UC[A-Za-z0-9_-]{22}$/', $value) ? $value : null;
    }

    private function spotifyEntityUrl(string $value): ?string
    {
        return preg_match('~^(artist|album|track|playlist|show|episode)/[A-Za-z0-9]+$~', $value)
            ? 'https://open.spotify.com/'.$value
            : null;
    }

    /**
     * iTunes numeric id, from the raw connect input (an artist/podcast URL or
     * the id itself). Handles both Apple URL grammars: podcasts carry
     * `/id{digits}`, artists end in a bare `/{digits}` path segment.
     */
    private function appleId(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d+$/', $value)) {
            return $value;
        }
        if (preg_match('~/id(\d+)(?:[/?#]|$)~', $value, $m)) {
            return $m[1];
        }
        if (preg_match('~^https?://(?:music|itunes)\.apple\.com/.*/(\d+)(?:[?#]|$)~', $value, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Canonical Eventbrite organiser URL (/o/<slug-id>) from any regional
     * host, normalized to www.eventbrite.com. Enumerated TLDs — an open glob
     * would re-open the spoofable-host hole (§17).
     */
    private function eventbriteOrgUrl(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value === null) {
            return null;
        }
        $tlds = '(?:com|com\.au|co\.uk|co\.nz|ca|de|fr|es|it|nl|pt|ie|at|ch|dk|fi|se|be|sg|hk|com\.br|com\.mx|com\.ar|com\.pe|cl)';
        if (preg_match('~^https?://(?:www\.)?eventbrite\.'.$tlds.'/o/([a-z0-9-]+)~i', $value, $m)) {
            return 'https://www.eventbrite.com/o/'.strtolower($m[1]);
        }

        return null;
    }

    /**
     * A store URL the given menu platform's host pattern recognises
     * (config partna.menu.platforms — the same registry MenuSource reads),
     * normalized like MenuSource::normalize: query and trailing slash
     * stripped so pickup/delivery variants collapse to one identifier.
     */
    private function menuStoreUrl(string $platform, mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value === null || ! preg_match('~^https?://~i', $value)) {
            return null;
        }

        $pattern = (string) config("partna.menu.platforms.{$platform}.host_pattern");
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        if ($pattern === '' || $host === '' || ! preg_match($pattern, $host)) {
            return null;
        }

        // The host says WHOSE marketplace; only the path says whether this is
        // a store. ubereats.com/au/brand/<chain> is a landing page with no
        // menu behind it, and provisioning from one books a source that can
        // never run (guzman-y-gomez, 2026-08-31) — the same failure the
        // `dice` arm below refuses a bare slug to avoid. A brand whose store
        // has no distinguishing path segment (square: the storefront IS the
        // host root) registers no pattern, and keeps the host-only rule.
        $pathPattern = (string) config("partna.menu.platforms.{$platform}.store_path_pattern");
        $path = (string) parse_url($value, PHP_URL_PATH);
        if ($pathPattern !== '' && ! preg_match($pathPattern, $path === '' ? '/' : $path)) {
            return null;
        }

        return rtrim((string) strtok($value, '?#'), '/');
    }

    /**
     * The artist slug off a dice.fm/ARTIST url.
     *
     * Deliberately no bare-slug fallback, unlike the other arms. The
     * `dice.events` surface detects artist, venue, promoter and partner
     * pages on one rule, so a venue connection's resource_id is a slug that
     * looks exactly like an artist's — and DiceConnector reads
     * dice.fm/artist/{slug}, which for a venue is a 404. Requiring the full
     * artist URL is what keeps a venue link a link card instead of
     * provisioning a source that can only ever fail.
     */
    private function diceSlug(mixed $value): ?string
    {
        $value = $this->cleanString($value);

        return $value !== null && preg_match('~^https?://(?:www\.)?dice\.fm/artist/([A-Za-z0-9-]{1,80})~i', $value, $m) === 1
            ? $m[1]
            : null;
    }

    /** The numeric artist id off a deezer.com/artist URL (locale-tolerant), or a bare id. */
    private function deezerArtistId(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value === null) {
            return null;
        }
        if (preg_match('~^https?://(?:www\.)?deezer\.com(?:/[a-z]{2})?/artist/(\d{1,15})~i', $value, $m)) {
            return $m[1];
        }

        return preg_match('/^\d{1,15}$/', $value) === 1 ? $value : null;
    }

    /** A TikTok username: letters/digits/underscore/dots, @-tolerant, lowercased. */
    private function tiktokUsername(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value !== null && preg_match('/^@?([A-Za-z0-9._]{1,60})$/', $value, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * The canonical facebook.com page URL from either a stored URL or a bare
     * handle. Reserved widget/share paths never reach here — the catalog
     * detector already refuses them at routing time. The URL arm accepts
     * hyphens (legacy pretty-URLs like /Le-Taj-Restaurant-Lounge-186…), and
     * the /pages/<name>/<id> legacy form canonicalises to the numeric page
     * id, which Facebook resolves — both shapes arrive verbatim from
     * google-business enrichment payloads.
     */
    private function facebookPageUrl(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value === null) {
            return null;
        }
        // profile.php is an ID-CARRYING endpoint, not a slug: the identity is
        // the ?id= that the slug branch below truncates away, leaving
        // "facebook.com/profile.php" — a source that can only ever be
        // unavailable (bondi-junction-dental, 2026-08-31). Refusing the URL is
        // not refusing the account: FacebookNormalizer lifts that ?id= into
        // the payload's `username`, and identifierFor()'s facebook arm asks
        // this method again with it, where the bare-value branch resolves it
        // to facebook.com/<id>. Nothing upstream drops the shape on our
        // behalf — Catalog/Definitions/Facebook.php reserves profile.php
        // rather than mis-capture it, and GoogleBusinessAutoSync's own
        // profile.php guard lives in socialUsername()'s regex table, which
        // facebook never reaches because that method short-circuits to the
        // normalizer first.
        if (preg_match('~^https?://(?:www\.|m\.)?(?:facebook|fb)\.com/profile\.php(?:[/?#]|$)~i', $value)) {
            return null;
        }
        if (preg_match('~^https?://(?:www\.|m\.)?(?:facebook|fb)\.com/pages/[^/?#]+/(\d{5,20})/?(?:[?#]|$)~i', $value, $m)) {
            return 'https://www.facebook.com/'.$m[1];
        }
        if (preg_match('~^https?://(?:www\.|m\.)?(?:facebook|fb)\.com/(?!pages(?:/|$))([A-Za-z0-9.-]{1,100})/?(?:[?#]|$)~i', $value, $m)) {
            return 'https://www.facebook.com/'.$m[1];
        }
        // The bare-value branch, and the guard above does not cover it: a dot
        // is in this charset, so the literal 'profile.php' (or '@profile.php')
        // matched here and rebuilt the exact dead URL the guard exists to
        // refuse — the one door left open after that fix shipped. Excluded by
        // literal rather than by charset: dots are load-bearing in real page
        // handles, and no page owns facebook.com/profile.php.
        if (preg_match('/^@?([A-Za-z0-9.]{1,100})$/', $value, $m) && strcasecmp($m[1], 'profile.php') !== 0) {
            return 'https://www.facebook.com/'.$m[1];
        }

        return null;
    }

    /** An instagram username: letters/digits/underscore/dots, no trailing dot, ≤30. */
    private function instagramUsername(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value !== null && preg_match('/^@?([A-Za-z0-9_](?:[A-Za-z0-9._]{0,28}[A-Za-z0-9_])?)$/', $value, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /** Canonical SoundCloud entity URL (profile/track/set, ≤3 path segments). */
    private function soundcloudUrl(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value !== null && preg_match('~^https?://(?:www\.|m\.)?soundcloud\.com(/[a-z0-9_-]+(?:/[a-z0-9_-]+){0,2})~i', $value, $m)) {
            return 'https://soundcloud.com'.strtolower(rtrim($m[1], '/'));
        }

        return null;
    }

    /** Canonical Humanitix host-page URL from a host or event URL. */
    private function humanitixHostUrl(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value !== null && preg_match('~^https?://(?:events\.)?humanitix\.com/host/([a-z0-9-]+)~i', $value, $m)) {
            return 'https://events.humanitix.com/host/'.strtolower($m[1]);
        }

        return null;
    }

    private function freshaSlug(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        // Anchored like every sibling extractor: an unanchored match would pull
        // a slug out of a hostile host's query string (§17). The optional
        // locale group is load-bearing, not decorative — legacy/seeded rows
        // may still hold a `/en-au/a/…` path from before FreshaScraper::
        // stripLocale existed, and a bare anchor would silently stop
        // provisioning those.
        //
        // `book-now` is the share URL Fresha's own app hands out
        // (`/book-now/<slug>/all-offer?share=true&pId=…`). Both write paths
        // canonicalise it to `/a/<slug>` (FreshaScraper::canonicalUrl, called
        // by resolveWrite and resolveBookingWrite), but rows written before
        // that existed still hold the raw form and provisioned NO source at
        // all. Kept as an alternative inside this same pattern rather than a
        // second preg_match so the host anchor and locale group cannot drift
        // apart between the two shapes.
        //
        // pId is deliberately discarded. It is almost certainly the Fresha
        // professional id — same numeric space as the employeeIds we scrape —
        // but selection belongs to selectionRefFor(), which reads the stored
        // selection blob, and inferring it from a URL would silently change
        // which menu a live connection publishes.
        if ($value !== null && preg_match('~^https?://(?:www\.)?fresha\.com/(?:[a-z]{2,3}(?:-[a-z]{2})?/)?(?:a|book-now)/([a-z0-9][a-z0-9-]*)~i', $value, $m)) {
            return $m[1];
        }

        return null;
    }

    private function googlePlaceId(string $value): ?string
    {
        // Place ids in the wild start ChIJ/GhIJ/EiC…; accept the conservative
        // common case only — anything else is a legacy placeholder.
        return preg_match('/^ChIJ[A-Za-z0-9_-]{10,}$/', $value) ? $value : null;
    }
}
