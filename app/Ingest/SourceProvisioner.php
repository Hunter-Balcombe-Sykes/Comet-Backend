<?php

namespace App\Ingest;

use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Payloads\FreshaSelection;
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

        if ($connection->trashed()) {
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
            return ['status' => 'skipped', 'reason' => 'no_identifier', 'source_key' => $sourceKey];
        }

        $manifest = ConnectorRegistry::manifestFor($sourceKey);

        $selectionRef = $this->selectionRefFor($sourceKey, $connection);

        $existing = DB::table('ingest.sources')
            ->where('connection_id', $connection->id)
            ->where('source_key', $sourceKey)
            ->first(['id', 'identifier', 'auto_sync', 'selection_ref']);

        if ($existing === null) {
            DB::table('ingest.sources')->insert([
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

            return ['status' => 'created', 'source_key' => $sourceKey];
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
        }
        if (! $existing->auto_sync && self::schedulable($manifest)) {
            $update['auto_sync'] = true;
        }
        DB::table('ingest.sources')->where('id', $existing->id)->update($update);

        return ['status' => count($update) > 1 ? 'updated' : 'unchanged', 'source_key' => $sourceKey];
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
        return $manifest->cost === CostClass::Free;
    }

    /** The brand half of a surface key, when a registered connector serves it. */
    public static function sourceKeyFor(string $surfaceKey): ?string
    {
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
            'youtube' => $this->cleanString($payload['channelId'] ?? null)
                ?? $this->youtubeChannelId($resource)
                ?? $this->bareSlug($payload['handle'] ?? null, 'youtube'),
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
            'apple_music', 'apple_podcasts' => $this->appleId($payload['input'] ?? null)
                ?? $this->appleId($resource),
            'fresha' => $this->freshaSlug($payload['url'] ?? null)
                ?? $this->bareSlug($resource, 'fresha'),
            'instagram' => $this->instagramUsername($payload['username'] ?? null)
                ?? $this->instagramUsername($this->bareSlug($resource, 'instagram')),
            'gumroad' => $this->gumroadSubdomain($payload['url'] ?? null)
                ?? $this->gumroadSubdomain($resource)
                ?? $this->bareSlug($resource, 'gumroad'),
            'google_business' => $this->cleanString($connection->place_id)
                ?? $this->cleanString($payload['placeId'] ?? null)
                ?? $this->googlePlaceId($resource),
            // Menu brands: only a URL the platform's own menu host-pattern
            // recognises is a scrapeable store — a square.book (squareup.com)
            // booking link must never provision a menu source.
            'square' => $this->menuStoreUrl('square', $payload['url'] ?? null)
                ?? $this->menuStoreUrl('square', $resource),
            'uber_eats' => $this->menuStoreUrl('uber-eats', $payload['url'] ?? null)
                ?? $this->menuStoreUrl('uber-eats', $resource),
            'doordash' => $this->menuStoreUrl('doordash', $payload['url'] ?? null)
                ?? $this->menuStoreUrl('doordash', $resource),
            'skool' => $this->skoolUrl($payload['url'] ?? null)
                ?? $this->skoolUrl($this->bareSlug($resource, 'skool')),
            'strava' => $this->stravaClubUrl($payload['url'] ?? null)
                ?? $this->stravaClubUrl($this->bareSlug($resource, 'strava')),
            'substack' => $this->bareSlug($resource, 'substack')
                ?? $this->substackSlug($payload['url'] ?? null),
            'twitch' => $this->twitchLogin($payload['login'] ?? null)
                ?? $this->twitchLogin($this->bareSlug($resource, 'twitch')),
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

        return rtrim((string) strtok($value, '?#'), '/');
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

    /** The store subdomain from a {sub}.gumroad.com URL (never gumroad.com itself). */
    private function gumroadSubdomain(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value !== null && preg_match('~^https?://([a-z0-9][a-z0-9-]*)\.gumroad\.com~i', $value, $m)
            && strtolower($m[1]) !== 'www' && strtolower($m[1]) !== 'app') {
            return strtolower($m[1]);
        }

        return null;
    }

    /** Canonical Skool community URL from a skool.com link or a bare slug. */
    private function skoolUrl(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value === null) {
            return null;
        }
        if (preg_match('~^https?://(?:www\.)?skool\.com/([a-z0-9][a-z0-9-]*)~i', $value, $m)) {
            $slug = strtolower($m[1]);
        } elseif (preg_match('~^[a-z0-9][a-z0-9-]*$~i', $value)) {
            $slug = strtolower($value);
        } else {
            return null;
        }

        // Product pages, not communities.
        if (in_array($slug, ['signup', 'login', 'discovery', 'games', 'about', 'legal', 'careers', 'affiliates'], true)) {
            return null;
        }

        return 'https://www.skool.com/'.$slug;
    }

    /** Canonical Strava club URL from a strava.com/clubs link or a bare slug/id. */
    private function stravaClubUrl(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value === null) {
            return null;
        }
        if (preg_match('~^https?://(?:www\.)?strava\.com/clubs/([A-Za-z0-9_-]+)~i', $value, $m)) {
            return 'https://www.strava.com/clubs/'.$m[1];
        }
        if (preg_match('~^[A-Za-z0-9_-]{2,60}$~', $value)) {
            return 'https://www.strava.com/clubs/'.$value;
        }

        return null;
    }

    /** A twitch login: 3–25 word chars, lowercased. */
    private function twitchLogin(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value !== null && preg_match('/^@?([A-Za-z0-9_]{3,25})$/', $value, $m)) {
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
        if ($value !== null && preg_match('~^https?://(?:www\.)?fresha\.com/(?:[a-z]{2,3}(?:-[a-z]{2})?/)?a/([a-z0-9][a-z0-9-]*)~i', $value, $m)) {
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

    private function substackSlug(mixed $value): ?string
    {
        $value = $this->cleanString($value);
        if ($value !== null && preg_match('~^https?://([a-z0-9-]+)\.substack\.com~i', $value, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }
}
