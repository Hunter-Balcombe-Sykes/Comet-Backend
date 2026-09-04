<?php

namespace App\Services\Setup;

use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\Onboarding\OnboardingSuggestions;
use App\Services\Platforms\ConnectionDisplayName;
use App\Services\Platforms\MenuPayloadComposer;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\PreAccount\BuildProgressReader;
use App\Site\Pools\PoolResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Composes GET /site/setup (A.9, wire §1). One reader for everything the
 * dialog renders: candidates for the listing pass, banded suggestions per
 * platform pass (hidden pre-scrape rows ride along as syncing), pool
 * library rows for item passes, and the build-progress readiness per pass.
 */
class SetupPayload
{
    /** origin → the phrase the card shows (wire §1 originLabel). */
    private const ORIGIN_LABELS = [
        'link_in_bio' => 'From your link page',
        'bio_harvest' => 'From your Instagram bio',
        'website_import' => 'From your website',
        'google_business' => 'From your Google listing',
        'commerce_probe' => 'From your store',
    ];

    public function __construct(
        private readonly BuildProgressReader $progress,
        private readonly PoolResolver $pools,
        private readonly OnboardingSuggestions $onboarding,
        private readonly PlatformRegistry $registry,
        private readonly MenuPayloadComposer $menus,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $site = $user->site;
        $build = PreAccountBuild::query()->where('user_id', $user->id)->latest('created_at')->first();
        $openStages = $build === null ? [] : $this->openStages($build);

        $suggestions = $this->suggestionRows($user);
        $onboarding = $this->onboarding->for($user);

        $passes = [];
        foreach (SetupPassRegistry::keysFor($user) as $key) {
            $pass = $this->composePass($user, $site, $key, $suggestions, $onboarding, $openStages);
            if ($pass !== null) {
                $passes[] = $pass;
            }
        }

        return [
            'step' => $site?->setup_step,
            'completed_at' => $site?->setup_completed_at?->toIso8601String(),
            'account_type' => (string) $user->account_type->value,
            'progress' => $build === null ? ['done' => true, 'stage' => null] : $this->progress->forSite($build),
            'passes' => $passes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     * @param  array<string, mixed>  $onboarding
     * @param  array<string, true>  $openStages
     * @return array<string, mixed>|null
     */
    private function composePass(User $user, ?Site $site, string $key, array $suggestions, array $onboarding, array $openStages): ?array
    {
        $ready = ! isset($openStages[SetupPassRegistry::READY_STAGES[$key] ?? '']);
        $base = ['key' => $key, 'ready' => $ready];

        if ($key === 'done') {
            return ['key' => 'done', 'ready' => true];
        }

        if ($key === 'listing') {
            return $base + ['candidates' => $this->listingCandidates($user)];
        }

        if (str_starts_with($key, 'platforms.')) {
            $categories = SetupPassRegistry::GROUP_CATEGORIES[$key] ?? [];
            $rows = array_values(array_filter($suggestions, fn (array $row) => in_array($row['_category'], $categories, true)));
            foreach ($rows as &$row) {
                unset($row['_category']);
            }

            return $base + [
                'suggestions' => $rows,
                'top' => $this->topFor($onboarding, $categories),
            ];
        }

        $itemPool = SetupPassRegistry::itemPool($key);
        if ($itemPool !== null) {
            if ($site === null) {
                return null;
            }
            $resolved = $this->pools->resolve($site, $itemPool);
            $items = $resolved['library'];
            if ($items === []) {
                return null; // the server omits an empty item pass (wire §2)
            }

            return $base + [
                'sources' => $this->sourcesFor($user, $itemPool),
                'items' => $items,
            ];
        }

        if ($key === 'media' || $key === 'links') {
            if ($site === null) {
                return null;
            }
            $pool = $key === 'media' ? 'media' : 'custom_links';
            $resolved = $this->pools->resolve($site, $pool);

            return $base + ['items' => $resolved['library']];
        }

        if ($key === 'services') {
            return $site === null ? null : $base + $this->servicesPass($user, $site);
        }

        if ($key === 'menu') {
            return $site === null ? null : $base + $this->menuPass($user);
        }

        if ($key === 'logo') {
            return $base + $this->logoPass($site);
        }

        return $base;
    }

    /** @return list<array<string, mixed>> */
    private function listingCandidates(User $user): array
    {
        // A connected listing renders as a candidate row too (owner,
        // 2026-09-03, item 12): picking one from search must ADD it to the
        // list auto-selected, keeping every other suggestion — not blank the
        // pass the way the old early-return did.
        $rows = [];
        $connected = $user->integrationConnections()
            ->where('platform', 'google-business')
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();
        if ($connected !== null) {
            $payload = (array) ($connected->payload ?? []);
            $rows[] = [
                'id' => 'connected:'.$connected->id,
                'name' => (string) ($payload['name'] ?? 'Your listing'),
                'address' => isset($payload['address']) && is_string($payload['address']) ? $payload['address'] : null,
                'photo' => self::listingPhoto($payload),
                'rating' => isset($payload['rating']) && is_numeric($payload['rating']) ? (float) $payload['rating'] : null,
                'reviewCount' => isset($payload['reviewCount']) && is_numeric($payload['reviewCount']) ? (int) $payload['reviewCount'] : null,
                'band' => 'auto',
                'preselected' => true,
                'source' => 'connected',
                'state' => 'connected',
            ];
        }

        return [...$rows, ...$this->proposedWorkplaceRows($user)];
    }

    /** @return list<array<string, mixed>> */
    private function proposedWorkplaceRows(User $user): array
    {
        return DB::table('site.workplace_candidates')
            ->where('user_id', $user->id)
            ->where('state', 'proposed')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (object $row): array {
                $corroboration = json_decode((string) $row->corroboration, true);
                $band = is_array($corroboration) && count($corroboration) >= 2 ? 'auto' : 'suggest';

                return [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'address' => $row->address,
                    'photo' => $row->photo_url,
                    'rating' => $row->rating !== null ? (float) $row->rating : null,
                    'reviewCount' => $row->review_count !== null ? (int) $row->review_count : null,
                    'band' => $band,
                    'preselected' => $band === 'auto',
                    'source' => (string) $row->source,
                    'state' => (string) $row->state,
                ];
            })->all();
    }

    /**
     * Every suggestion row the dialog can render, category-tagged for the
     * pass filter: proposed/blocked intents (the ordinary offer) plus
     * APPLIED intents whose connection is still hidden — the pre-scrape
     * rows, rendered ticked and syncing (A.3/A.4).
     *
     * @return list<array<string, mixed>>
     */
    private function suggestionRows(User $user): array
    {
        $intents = DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            // 'verifying' is a LIVE row the dialog must keep rendering — the
            // person ticked it and is owed a "checking this link" state, not a
            // row that vanishes mid-setup (2026-09-03).
            ->whereIn('state', ['proposed', 'verifying', 'blocked', 'applied'])
            ->orderByDesc('first_seen_at')
            ->limit(200)
            ->get();

        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->get(['id', 'surface_key', 'resource_id', 'platform', 'visibility', 'last_refresh_status', 'payload']);
        $byKey = $connections->keyBy(fn (IntegrationConnection $c) => $c->surface_key.'|'.$c->resource_id);

        $rows = [];
        foreach ($intents as $intent) {
            $surface = CompiledCatalog::surface((string) $intent->surface_key);
            if ($surface === null) {
                continue;
            }
            $legacy = LegacyPlatformMap::legacyFor((string) $intent->surface_key);
            $category = $this->registry->get((string) $legacy)?->getCategory()?->value;
            // A registry-less brand (shopify — purged with commerce — routes
            // to a real surface but has no platform registry entry) still
            // deserves its pass: the surface's routing class is in the pass
            // map's category vocabulary for every class we route (A.12 proof
            // catch — jordan's auto-band shopify.store never rendered).
            $category ??= (($surface['routing_class'] ?? '') !== '') ? (string) $surface['routing_class'] : null;
            if ($category === null) {
                continue;
            }

            $connection = $byKey->get($intent->surface_key.'|'.$intent->identifier);
            $hidden = $connection !== null && $connection->isHidden();
            $visible = $connection !== null && ! $connection->isHidden();

            if ((string) $intent->state === 'applied' && ! $hidden && ! $visible) {
                continue; // settled long ago, nothing to render
            }

            $band = $intent->band ?? null;
            // 'verifying' is the one state the PERSON cannot clear: they ticked
            // it, Continue accepted it, and we are the ones still working. A
            // row that came back looking exactly as it did before the tick
            // reads as "nothing happened" — so the state goes on the wire and
            // the card says it is being checked.
            $verifying = (string) $intent->state === 'verifying';
            $rows[] = [
                '_category' => $category,
                'id' => (string) $intent->id,
                'surfaceKey' => (string) $intent->surface_key,
                'brandKey' => $surface['brand_key'] ?? null,
                'displayName' => $surface['display_name'] ?? (string) $intent->surface_key,
                // The account's OWN name, always when one can be known (owner,
                // 2026-09-03): the probe's label, then the synced connection's
                // payload, then a URL/handle-derived last resort — the card
                // must read "Studio MJ", never just "fresha.com".
                'accountName' => $intent->identifier_label
                    ?? self::connectionName($connection)
                    ?? self::derivedAccountName((string) $intent->surface_key, $intent->canonical_url, (string) $intent->identifier),
                'avatar' => $intent->identifier_icon
                    ?? self::connectionIcon($connection)
                    ?? self::storeFavicon((string) $intent->surface_key, $intent->canonical_url),
                'url' => $intent->canonical_url,
                'origin' => (string) $intent->origin,
                'originLabel' => self::ORIGIN_LABELS[(string) $intent->origin] ?? null,
                'band' => $band,
                // A verifying row stays TICKED. The tick is the person's own
                // answer and it was accepted; un-ticking it while we check
                // would ask them the same question twice.
                'preselected' => $band === 'auto' || $hidden || $verifying,
                'syncing' => $hidden && (string) ($connection->last_refresh_status ?? '') === 'pending',
                'verifying' => $verifying,
                'connectionId' => $visible ? (string) $connection->id : null,
                // Nothing to accept or dismiss while the queue holds it — the
                // accept lane would re-park it and a dismiss would race the job.
                'actions' => $verifying ? [] : ['accept', 'dismiss'],
            ];
        }

        // Item 23 (owner, 2026-09-03): a connection minted WITHOUT an intent
        // (the add panel's manual connect, an OAuth return) must still render
        // in its pass — the pass rows used to come only from intents, which
        // is why "Connect does nothing". Union intent-less connections in as
        // rows of their own — VISIBLE ones as already-connected rows, and
        // (owner, 2026-09-04) HIDDEN ones the same way the intent loop above
        // renders a hidden pre-scrape row: ticked, syncing, with an id the
        // accept lane can reveal — reusing the mechanism the automatic
        // pre-scrape path built, now reachable from a manual pick too
        // (Get Started's setup-variant ConnectionSheet).
        $covered = $intents->map(fn (object $i) => $i->surface_key.'|'.$i->identifier)->flip();
        foreach ($connections as $connection) {
            if ((string) $connection->platform === 'google-business') {
                continue; // the listing pass's job, not a platform pass row
            }
            if (isset($covered[$connection->surface_key.'|'.$connection->resource_id])) {
                continue;
            }
            $surface = CompiledCatalog::surface((string) $connection->surface_key);
            if ($surface === null) {
                continue;
            }
            $legacy = LegacyPlatformMap::legacyFor((string) $connection->surface_key);
            $category = $this->registry->get((string) $legacy)?->getCategory()->value
                ?? ((($surface['routing_class'] ?? '') !== '') ? (string) $surface['routing_class'] : null);
            if ($category === null) {
                continue;
            }
            $payload = (array) ($connection->payload ?? []);
            $url = isset($payload['url']) && is_string($payload['url']) ? $payload['url'] : null;
            $hidden = $connection->isHidden();
            $rows[] = [
                '_category' => $category,
                'id' => 'connection:'.$connection->id,
                'surfaceKey' => (string) $connection->surface_key,
                'brandKey' => $surface['brand_key'] ?? null,
                'displayName' => $surface['display_name'] ?? (string) $connection->surface_key,
                'accountName' => self::connectionName($connection)
                    ?? self::derivedAccountName((string) $connection->surface_key, $url, (string) $connection->resource_id),
                'avatar' => self::connectionIcon($connection)
                    ?? self::storeFavicon((string) $connection->surface_key, $url),
                'url' => $url,
                'origin' => 'manual',
                'originLabel' => null,
                'band' => null,
                'preselected' => true,
                'syncing' => $hidden && (string) ($connection->last_refresh_status ?? '') === 'pending',
                // An intent-less connection already exists, so there is no
                // check outstanding — the key is present on every row so the
                // client never has to distinguish absent from false.
                'verifying' => false,
                // Hidden: no real connection to report yet as far as the
                // client's "already connected" logic is concerned — same
                // null the hidden-intent branch above sends, which is what
                // keeps this row travelling through accept/dismiss instead
                // of being treated as a settled connect.
                'connectionId' => $hidden ? null : (string) $connection->id,
                'actions' => $hidden ? ['accept', 'dismiss'] : [],
            ];
        }

        return $rows;
    }

    /**
     * Item 14 (owner, 2026-09-03): a store card must wear the store's own
     * mark. When neither the probe nor the sync produced an icon, the
     * storefront's favicon (via Google's favicon service — a plain image URL
     * the browser fetches) beats the platform brand tile.
     */
    private static function storeFavicon(string $surfaceKey, ?string $url): ?string
    {
        if (! str_ends_with($surfaceKey, '.store') || ! is_string($url)) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return 'https://www.google.com/s2/favicons?domain='.rawurlencode($host).'&sz=128';
    }

    /** A human name off the synced connection's payload, when one exists. */
    private static function connectionName(?IntegrationConnection $connection): ?string
    {
        if ($connection === null) {
            return null;
        }

        // Through the canonical resolver, not a private key list (2026-09-04):
        // this method's own ['name', 'fullName', ...] precedence rendered the
        // latest VIDEO title as a youtube account's name — payload['name'] is
        // the newest item's title on every feed-style platform, and
        // ConnectionDisplayName::for() already knows which surfaces those are.
        // RoutingConnectionResource has always read accountName this way; the
        // walk now matches the Platforms page instead of drifting beside it.
        $name = ConnectionDisplayName::for((string) $connection->surface_key, (array) ($connection->payload ?? []));

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** The account's own icon off the synced connection's payload. */
    private static function connectionIcon(?IntegrationConnection $connection): ?string
    {
        if ($connection === null) {
            return null;
        }
        $payload = (array) ($connection->payload ?? []);
        // 'thumbnail' is the youtube sync's key (a channel avatar off
        // i.ytimg.com) — without it a youtube suggestion rendered the brand
        // tile even after its scrape had the real face (2026-09-04).
        // Second sweep, same day: 'avatarUrl' (YoutubeConnect + FeedPayload),
        // 'profilePic' (InstagramConnectionSeeder's third spelling) and
        // 'image' (FeedPayload) were still missing — every key any connection
        // writer actually stamps is on this list now, profile-ish before
        // brand-ish.
        foreach (['profilePicUrl', 'avatarUrl', 'avatar', 'profilePic', 'logo', 'favicon', 'thumbnail', 'image'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && str_starts_with($value, 'http')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The connected google-business listing's card photo. The sync stores a
     * `photos` LIST (GoogleBusinessEnrichJob); only the legacy probe wrote a
     * singular `photo`. Reading only the singular is why a connected listing
     * with nine synced photos still rendered the map-pin placeholder
     * (2026-09-04, simondoylehair).
     */
    private static function listingPhoto(array $payload): ?string
    {
        if (isset($payload['photo']) && is_string($payload['photo']) && str_starts_with($payload['photo'], 'http')) {
            return $payload['photo'];
        }
        $photos = $payload['photos'] ?? null;
        if (! is_array($photos)) {
            return null;
        }
        $first = $photos[array_key_first($photos) ?? 0] ?? null;
        if (is_string($first) && str_starts_with($first, 'http')) {
            return $first;
        }
        if (is_array($first)) {
            foreach (['url', 'src'] as $key) {
                $value = $first[$key] ?? null;
                if (is_string($value) && str_starts_with($value, 'http')) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Last-resort name derivation when neither a probe nor a sync has named
     * the account yet. Fresha booking slugs always end in a venue hash
     * (`studio-mj-erbhmhhy`), so the trailing token drops and the rest
     * title-cases; profile/channel surfaces fall back to the handle itself
     * unless it is an opaque platform id (a YouTube UC… channel id).
     */
    private static function derivedAccountName(string $surfaceKey, ?string $url, string $identifier): ?string
    {
        if (str_starts_with($surfaceKey, 'fresha.') && is_string($url)) {
            if (preg_match('#fresha\.com/(?:book-now|a)/([a-z0-9-]+)#i', $url, $m) === 1) {
                $tokens = array_values(array_filter(explode('-', strtolower($m[1]))));
                if (count($tokens) >= 2) {
                    array_pop($tokens);
                }
                if ($tokens !== []) {
                    return ucwords(implode(' ', $tokens));
                }
            }

            return null;
        }

        if (str_ends_with($surfaceKey, '.profile') || str_ends_with($surfaceKey, '.channel')) {
            $handle = ltrim($identifier, '@');
            $opaque = preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $handle) === 1;
            if (! $opaque && preg_match('/^[A-Za-z0-9._-]{2,30}$/', $handle) === 1) {
                return $handle;
            }
        }

        return null;
    }

    /**
     * The sector asks that belong to this pass's categories (≤ 6; the client
     * fills the rest of the row from its own connect roster).
     *
     * @param  array<string, mixed>  $onboarding
     * @param  list<string>  $categories
     * @return list<array<string, string>>
     */
    private function topFor(array $onboarding, array $categories): array
    {
        $top = [];
        foreach ((array) ($onboarding['suggestions'] ?? []) as $ask) {
            $category = $this->registry->get((string) $ask['key'])?->getCategory()?->value;
            if ($category !== null && in_array($category, $categories, true)) {
                $top[] = ['key' => (string) $ask['key'], 'label' => (string) $ask['label'], 'reason' => 'sector'];
            }
            if (count($top) >= 6) {
                break;
            }
        }

        return $top;
    }

    /** @return list<string> platforms of accepted (visible) connections feeding this pool's categories */
    private function sourcesFor(User $user, string $pool): array
    {
        return IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->visible()
            ->get(['platform'])
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * One menu dish or service, in the shape the setup wire declares
     * (SetupServiceItem: id, name, selected, price, durationMinutes, photo).
     *
     * Both passes used to hand their source rows straight through, which is
     * why neither ever carried `selected` — the dashboard seeds its ticks from
     * that key, found `undefined`, and rendered every item OFF under a heading
     * that reads "Everything's on. Untick anything that's off the menu."
     * (owner, 2026-09-03). Nothing was wrong with the copy; the wire simply
     * never answered the question it was asking.
     *
     * The two sources name things differently — the menu composer emits
     * `name`/`image`/`basePrice`, the pool resolver `headline`/`thumbnail`/
     * `price` — so this reads both rather than making the callers agree.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function setupItem(array $item, bool $selected): array
    {
        $first = static function (array $row, string ...$keys): ?string {
            foreach ($keys as $key) {
                $value = $row[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }
            }

            return null;
        };

        $duration = $item['durationMinutes'] ?? $item['duration_minutes'] ?? null;

        return [
            'id' => (string) ($item['id'] ?? ''),
            'name' => $first($item, 'name', 'headline', 'title') ?? '',
            'selected' => $selected,
            'price' => $first($item, 'price', 'basePrice', 'base_price'),
            'durationMinutes' => is_numeric($duration) ? (int) $duration : null,
            'photo' => $first($item, 'image', 'thumbnail', 'imageUrl', 'image_url'),
        ];
    }

    /** @return array<string, mixed> */
    private function servicesPass(User $user, Site $site): array
    {
        $booking = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->whereIn('routing_class', ['booking'])
            ->whereNull('deleted_at')
            ->first(['id', 'platform', 'payload']);

        $resolved = $this->pools->resolve($site, 'services');
        $items = $resolved['library'];

        // Group by the category each row carries; uncategorised rows share one bucket.
        $categories = [];
        foreach ($items as $item) {
            $name = (string) ($item['category'] ?? 'Services');
            $categories[$name] ??= ['id' => md5($name), 'name' => $name, 'items' => []];
            $categories[$name]['items'][] = $this->setupItem($item, true);
        }

        $payload = $booking === null ? [] : (array) $booking->payload;

        return [
            'platform' => $booking?->platform,
            // Fresha's pick is payload.selection; Square's is payload.teamMember
            // (SquareAutoSelectJob / the shared picker's square arm stamp it on
            // the URL rewrite). Reading only selection told the walk a picked
            // Square row was still waiting on its picker (2026-09-04 sweep).
            'teamPicked' => is_array($payload['selection'] ?? null) || is_array($payload['teamMember'] ?? null),
            'categories' => array_values($categories),
        ];
    }

    /**
     * The menu pass, from the composer's dashboard shape (A.10, decision 12):
     * platform + owner-manual dishes in their real categories, scan and
     * website-scan discoveries in `found`. `found` is deduped by dish id — a
     * multi-category dish is one decision, not one per membership.
     *
     * EVERYTHING now arrives selected, `found` included (owner, 2026-09-03:
     * "ensure all menu items and services start as auto selected not
     * unselected as they do now"). This reverses decision 12's "discoveries
     * arrive unselected for the person to confirm" for the `found` arm
     * specifically — noted rather than quietly dropped, because it is the one
     * place where a scan result now ships unless someone unticks it. The
     * categories arm was ALREADY meant to be pre-selected and merely never
     * said so on the wire, so for that half this is the fix, not a reversal.
     *
     * @return array<string, mixed>
     */
    private function menuPass(User $user): array
    {
        $composed = $this->menus->compose($user, $this->menus->load($user));

        $categories = [];
        $found = [];
        $foundSeen = [];
        foreach ($composed['categories'] as $category) {
            $keep = [];
            foreach ($category['items'] as $item) {
                $provenance = (string) ($item['provenance'] ?? 'platform');
                if ($provenance === 'platform' || $provenance === 'manual') {
                    $keep[] = $this->setupItem($item, true);
                } elseif (! isset($foundSeen[(string) $item['id']])) {
                    $found[] = $this->setupItem($item, true);
                    $foundSeen[(string) $item['id']] = true;
                }
            }
            if ($keep !== []) {
                $categories[] = ['id' => $category['id'], 'name' => $category['name'], 'items' => $keep];
            }
        }

        // `found` is a list of CATEGORIES on the wire, not of items — the
        // dashboard maps it the same way it maps `categories` and reads
        // `.items` off each entry. It had been sending bare items, so any
        // account with a scan discovery would have hit `.items.map` on an
        // undefined; it never showed because no account in dev has one yet.
        // One synthetic category, and only when there is something in it.
        $foundGroups = $found === [] ? [] : [[
            'id' => 'found',
            'name' => 'Found on your website and photos',
            'items' => $found,
        ]];

        return ['categories' => $categories, 'found' => $foundGroups];
    }

    /** @return array<string, mixed> */
    private function logoPass(?Site $site): array
    {
        $candidates = ['square' => [], 'full' => []];
        if ($site !== null) {
            try {
                foreach (DB::table('site.logo_candidates')->where('site_id', $site->id)->where('state', 'proposed')->orderByDesc('trust')->get() as $row) {
                    $slot = (string) $row->slot;
                    $candidates[$slot][] = [
                        'id' => (string) $row->id,
                        'url' => $row->source_url,
                        'w' => $row->width !== null ? (int) $row->width : null,
                        'h' => $row->height !== null ? (int) $row->height : null,
                    ];
                }
            } catch (\Throwable) {
                // SQLite lanes without the sites stand-in — an empty offer.
            }
        }

        return ['candidates' => $candidates, 'slots' => ['square' => null, 'full' => null]];
    }

    /** @return array<string, true> stages whose last ledger row is an unanswered 'started' */
    private function openStages(PreAccountBuild $build): array
    {
        // A settled build holds no open stages, whatever the ledger says: the
        // build pipeline has declared itself done, and a leaked STARTED row
        // must not outrank that. Observed live (2026-09-04, simondoylehair):
        // a link-page scan left stage 'platforms' at STARTED forever, which
        // pinned every platforms.* pass at ready:false — the walk showed
        // "Still looking…" 40 minutes after the build settled.
        if ($build->settled_at !== null) {
            return [];
        }

        $rows = DB::table('core.pre_account_build_events')
            ->where('build_id', $build->id)
            ->orderBy('created_at')
            ->get(['stage', 'status', 'created_at']);

        $last = [];
        $lastAt = [];
        foreach ($rows as $row) {
            $last[(string) $row->stage] = (string) $row->status;
            $lastAt[(string) $row->stage] = (string) $row->created_at;
        }

        // Same leak, before settlement: a STARTED left unanswered past any
        // plausible scrape duration is treated as answered. Ten minutes is
        // over double the slowest stage measured on live builds; the ledger
        // row itself is left alone (the feed still shows what happened).
        $open = [];
        foreach ($last as $stage => $status) {
            if ($status !== PreAccountBuildEvent::STATUS_STARTED) {
                continue;
            }
            $startedAt = CarbonImmutable::parse($lastAt[$stage]);
            if ($startedAt->lt(now()->subMinutes(10))) {
                continue;
            }
            $open[$stage] = true;
        }

        return $open;
    }
}
