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
use App\Services\Platforms\MenuPayloadComposer;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\PreAccount\BuildProgressReader;
use App\Site\Pools\PoolResolver;
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
                'photo' => isset($payload['photo']) && is_string($payload['photo']) ? $payload['photo'] : null,
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
            ->whereIn('state', ['proposed', 'blocked', 'applied'])
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
                'preselected' => $band === 'auto' || $hidden,
                'syncing' => $hidden && (string) ($connection->last_refresh_status ?? '') === 'pending',
                'connectionId' => $visible ? (string) $connection->id : null,
                'actions' => ['accept', 'dismiss'],
            ];
        }

        // Item 23 (owner, 2026-09-03): a connection minted WITHOUT an intent
        // (the add panel's manual connect, an OAuth return) must still render
        // in its pass — the pass rows used to come only from intents, which
        // is why "Connect does nothing". Union visible intent-less
        // connections in as rows of their own.
        $covered = $intents->map(fn (object $i) => $i->surface_key.'|'.$i->identifier)->flip();
        foreach ($connections as $connection) {
            if ((string) $connection->platform === 'google-business') {
                continue; // the listing pass's job, not a platform pass row
            }
            if ($connection->isHidden() || isset($covered[$connection->surface_key.'|'.$connection->resource_id])) {
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
                'syncing' => (string) ($connection->last_refresh_status ?? '') === 'pending',
                'connectionId' => (string) $connection->id,
                'actions' => [],
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
        $payload = (array) ($connection->payload ?? []);
        foreach (['name', 'fullName', 'shop_name', 'store', 'title'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** The account's own icon off the synced connection's payload. */
    private static function connectionIcon(?IntegrationConnection $connection): ?string
    {
        if ($connection === null) {
            return null;
        }
        $payload = (array) ($connection->payload ?? []);
        foreach (['profilePicUrl', 'logo', 'favicon', 'avatar'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && str_starts_with($value, 'http')) {
                return $value;
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
            $categories[$name]['items'][] = $item;
        }

        $payload = $booking === null ? [] : (array) $booking->payload;

        return [
            'platform' => $booking?->platform,
            'teamPicked' => is_array($payload['selection'] ?? null),
            'categories' => array_values($categories),
        ];
    }

    /**
     * The menu pass, from the composer's dashboard shape (A.10, decision 12):
     * platform + owner-manual dishes arrive pre-selected in their real
     * categories; scan/website-scan discoveries arrive unselected in `found`
     * for the person to confirm. `found` is deduped by dish id — a
     * multi-category dish is one decision, not one per membership.
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
                    $keep[] = $item;
                } elseif (! isset($foundSeen[(string) $item['id']])) {
                    $found[] = $item;
                    $foundSeen[(string) $item['id']] = true;
                }
            }
            if ($keep !== []) {
                $categories[] = ['id' => $category['id'], 'name' => $category['name'], 'items' => $keep];
            }
        }

        return ['categories' => $categories, 'found' => $found];
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
        $rows = DB::table('core.pre_account_build_events')
            ->where('build_id', $build->id)
            ->orderBy('created_at')
            ->get(['stage', 'status']);

        $last = [];
        foreach ($rows as $row) {
            $last[(string) $row->stage] = (string) $row->status;
        }

        return array_fill_keys(
            array_keys(array_filter($last, fn (string $s) => $s === PreAccountBuildEvent::STATUS_STARTED)),
            true,
        );
    }
}
