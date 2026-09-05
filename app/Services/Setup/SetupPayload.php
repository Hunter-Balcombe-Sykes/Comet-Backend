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
        $resolvedPools = $site === null ? [] : $this->resolveAllPools($site, $this->poolsFor($user));

        $passes = [];
        foreach (SetupPassRegistry::keysFor($user) as $key) {
            $pass = $this->composePass($user, $site, $key, $suggestions, $onboarding, $openStages, $resolvedPools);
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
     * One pass, for the accept response (A.9, wire §4). Continue needs only
     * the pass it just wrote back, and composing all fifteen to return one
     * hydrated six pools nobody read.
     *
     * Sections are still preloaded for EVERY pool, not just this one:
     * preloadSections provisions a missing section row as a side effect, and
     * narrowing that to the pass being built would silently stop provisioning
     * the rest. It is one batched query.
     *
     * @return array<string, mixed>|null null when the key is not this user's,
     *                                   or the pass is one the wire omits
     */
    public function forPass(User $user, string $key): ?array
    {
        if (! in_array($key, SetupPassRegistry::keysFor($user), true)) {
            return null;
        }

        $site = $user->site;
        $build = PreAccountBuild::query()->where('user_id', $user->id)->latest('created_at')->first();
        $openStages = $build === null ? [] : $this->openStages($build);

        // Only the platforms.* passes read these two, and they are the
        // expensive half of the prelude.
        $needsSuggestions = $this->readsSuggestions($key);
        $suggestions = $needsSuggestions ? $this->suggestionRows($user) : [];
        $onboarding = $needsSuggestions ? $this->onboarding->for($user) : [];

        $pools = $this->poolsFor($user);
        $pool = $this->poolForPassKey($key);
        $needed = $pool === null ? [] : [$pool];

        $resolvedPools = [];
        if ($site !== null) {
            // Provision every pool's section (side effect), hydrate only the
            // one this pass renders.
            $this->pools->preloadSections($site, $pools);
            $resolvedPools = $this->resolveAllPools($site, $needed);
        }

        return $this->composePass($user, $site, $key, $suggestions, $onboarding, $openStages, $resolvedPools);
    }

    /**
     * The content pool an item-bearing pass renders, or null for a pass that
     * renders no pool. Keeps the mapping in one place — forPass() needs the
     * same answer for a single key.
     */
    private function poolForPassKey(string $key): ?string
    {
        return SetupPassRegistry::itemPool($key) ?? match ($key) {
            'media' => 'media',
            'links' => 'custom_links',
            'services' => 'services',
            default => null,
        };
    }

    /**
     * The content pools this user's pass list will resolve. Derived from the
     * pass keys rather than hardcoded so a capability difference (menu instead
     * of services) never resolves a pool the dialog will not render.
     *
     * @return list<string>
     */
    private function poolsFor(User $user): array
    {
        $pools = [];
        foreach (SetupPassRegistry::keysFor($user) as $key) {
            $pool = $this->poolForPassKey($key);
            if ($pool !== null) {
                $pools[] = $pool;
            }
        }

        return array_values(array_unique($pools));
    }

    /**
     * plan → ONE shared hydrate → assemble, the seam PoolWire::forSite uses.
     * Resolving each pool independently ran itemPayloads' ~20 facet queries
     * once per pool; the ids are planned per pool (cheap), hydrated once as a
     * union, and each pool assembles from the shared map.
     *
     * Unlike PoolWire this unions libraryIds too and keeps withLibrary — the
     * setup dialog renders the LIBRARY, not the selection.
     *
     * @param  list<string>  $pools
     * @return array<string, array<string, mixed>> pool => resolve()-shaped array
     */
    private function resolveAllPools(Site $site, array $pools): array
    {
        if ($pools === []) {
            return [];
        }

        $sections = $this->pools->preloadSections($site, $pools);
        $curationBySection = $this->pools->preloadCuration($sections);

        $plans = [];
        $ids = [];
        foreach ($pools as $pool) {
            $section = $sections[$pool];
            $plans[$pool] = $this->pools->plan(
                $site,
                $pool,
                $section,
                $curationBySection[(string) $section->id] ?? collect(),
            );
            array_push($ids, ...$plans[$pool]['selectionIds'], ...$plans[$pool]['libraryIds']);
        }

        // withDuplicateCandidates: true keeps this byte-identical to the
        // resolve() calls it replaces.
        //
        // Investigated for Task 3 (2026-09-04) and found LOAD-BEARING: the
        // premise that setupItem() never reads a duplicate-candidates key is
        // true for the 'services' pass only. The 'items.*'/'media'/'links'
        // branches in composePass() put resolvedPools['library'] rows on the
        // wire VERBATIM — no setupItem() transform — so duplicateCandidates
        // IS a live setup-wire field for six of the seven pools. Flipping
        // this to false was proven wire-breaking by
        // SetupPoolBatchingTest.php's "the setup wire carries a populated
        // duplicateCandidates today" test: it passes on this code and fails
        // the moment the flag flips. Do not flip it without re-deriving
        // duplicateCandidates some other way for those four pools first.
        [$payloads, $stores] = $this->pools->hydrateItems(
            $site,
            array_values(array_unique($ids)),
            withDuplicateCandidates: true,
        );

        $resolved = [];
        foreach ($pools as $pool) {
            $resolved[$pool] = $this->pools->assemble($site, $pool, $plans[$pool], $payloads, $stores);
        }

        return $resolved;
    }

    /**
     * Whether this pass reads the suggestion roster and onboarding tops. The
     * platforms.* branch in composePass() is the only reader, and they are the
     * expensive half of the prelude — forPass() skips them for every other pass,
     * so the predicate lives here, next to the branch it describes, rather than
     * inline at the skip.
     */
    private function readsSuggestions(string $key): bool
    {
        return str_starts_with($key, 'platforms.');
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     * @param  array<string, mixed>  $onboarding
     * @param  array<string, true>  $openStages
     * @param  array<string, array<string, mixed>>  $resolvedPools  pool => resolve() shape, hydrated once by resolveAllPools()
     * @return array<string, mixed>|null
     */
    private function composePass(User $user, ?Site $site, string $key, array $suggestions, array $onboarding, array $openStages, array $resolvedPools): ?array
    {
        $ready = ! isset($openStages[SetupPassRegistry::READY_STAGES[$key] ?? '']);
        $base = ['key' => $key, 'ready' => $ready];

        if ($key === 'done') {
            return ['key' => 'done', 'ready' => true];
        }

        if ($key === 'listing') {
            return $base + ['candidates' => $this->listingCandidates($user)];
        }

        if ($this->readsSuggestions($key)) {
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
            $resolved = $resolvedPools[$itemPool] ?? null;
            if ($resolved === null) {
                return null;
            }
            $items = $resolved['library'];
            if ($items === []) {
                return null; // the server omits an empty item pass (wire §2)
            }

            $pass = $base + [
                'sources' => $this->sourcesFor($user, $itemPool),
                'items' => $items,
            ];

            // Item 4: the "Your products" step needs a store dropdown to
            // filter by, and every product row already carries collectionIds
            // (PoolResolver::ITEM_KEYS) to filter against. resolved['collections']
            // will not do here — it is scoped to the CURATED selection
            // (PoolResolver::assemble), and at setup time a freshly scraped
            // store's products are still library-only, nothing pinned yet
            // (the exact JRLUSA/squeakprobarber shape this item exists for) —
            // so it queries the collections these $items actually reference
            // directly, unscoped by curation state.
            if ($itemPool === 'shop') {
                $collectionIds = collect($items)->flatMap(fn (array $i) => $i['collectionIds'] ?? [])->unique()->values();
                $pass['stores'] = $collectionIds->isEmpty() ? [] : DB::connection('pgsql')
                    ->table('content.collections')
                    ->whereIn('id', $collectionIds->all())
                    ->where('user_id', $user->id)
                    ->orderBy('label')
                    ->get(['id', 'label'])
                    ->map(fn ($row) => ['collectionId' => (string) $row->id, 'name' => (string) $row->label])
                    ->values()
                    ->all();
            }

            return $pass;
        }

        if ($key === 'media' || $key === 'links') {
            if ($site === null) {
                return null;
            }
            $pool = $key === 'media' ? 'media' : 'custom_links';
            $resolved = $resolvedPools[$pool] ?? null;
            if ($resolved === null) {
                return null;
            }

            return $base + ['items' => $resolved['library']];
        }

        if ($key === 'services') {
            if ($site === null) {
                return null;
            }
            $pass = $this->servicesPass($user, $site, $resolvedPools);

            return $ready && ! $this->rendersSomething($pass) ? null : $base + $pass;
        }

        if ($key === 'menu') {
            if ($site === null) {
                return null;
            }
            $pass = $this->menuPass($user);

            return $ready && ! $this->rendersSomething($pass) ? null : $base + $pass;
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

    /**
     * Whether a composed services/menu pass has anything for the walk to draw.
     *
     * Both passes render exactly two things: the categories, and — for the two
     * booking platforms that ship a team — the "which one is you?" picker. A
     * pass with neither is a heading and a Continue button over empty space,
     * which is what a Timely signup got on 2026-09-04: Timely is a booking
     * LINK with no connector (ConnectorRegistry has no 'timely' key), so no
     * service item can ever arrive for it and the step could never fill in.
     * Omit it instead, the way an empty item pass is already omitted (wire
     * §2). Nothing is lost while a fetch is still in flight — the walk polls
     * every 3s and re-folds the pass list, so a pass whose items land later
     * appears then, and the picker keeps the fresha/square step present
     * through the window before their services arrive. A pass that is not
     * READY yet is never judged by this at all (see the caller): the menu
     * pass has a build stage behind it, and an empty-but-loading menu is
     * owed its "Still looking…", not a disappearance.
     *
     * @param  array<string, mixed>  $pass
     */
    private function rendersSomething(array $pass): bool
    {
        if (($pass['categories'] ?? []) !== [] || ($pass['found'] ?? []) !== []) {
            return true;
        }

        // Mirrors the dashboard's own condition for drawing the picker.
        return ($pass['teamPicked'] ?? null) === false
            && in_array($pass['platform'] ?? null, ['square', 'fresha'], true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $resolvedPools  pool => resolve() shape, hydrated once by resolveAllPools()
     * @return array<string, mixed>
     */
    private function servicesPass(User $user, Site $site, array $resolvedPools): array
    {
        $booking = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->whereIn('routing_class', ['booking'])
            ->whereNull('deleted_at')
            ->first(['id', 'platform', 'payload']);

        $items = $resolvedPools['services']['library'] ?? [];

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

        if ($build->settled_at !== null) {
            // The scrape's own stages are closed, whatever the ledger says —
            // but item 3 (2026-09-05) has SetupBatchApplier::acceptOne()
            // dispatch a CommerceProbeJob well after settlement, on the
            // person's own Continue, and it logs its own started/landed pair
            // on the SAME ledger. Dropping every stage wholesale at settle
            // made items.shop report ready:true with no items while that
            // probe was still in flight. A stage started BEFORE settlement is
            // still the pre-settle leak the check above exists to ignore.
            $open = [];
            foreach ($last as $stage => $status) {
                if ($status !== PreAccountBuildEvent::STATUS_STARTED) {
                    continue;
                }
                if (CarbonImmutable::parse($lastAt[$stage])->lte($build->settled_at)) {
                    continue;
                }
                $open[$stage] = true;
            }

            return $open;
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
