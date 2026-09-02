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

        if (isset(SetupPassRegistry::ITEM_POOLS[$key])) {
            if ($site === null) {
                return null;
            }
            $resolved = $this->pools->resolve($site, SetupPassRegistry::ITEM_POOLS[$key]);
            $items = $resolved['library'];
            if ($items === []) {
                return null; // the server omits an empty item pass (wire §2)
            }

            return $base + [
                'sources' => $this->sourcesFor($user, SetupPassRegistry::ITEM_POOLS[$key]),
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
            return $site === null ? null : $base + $this->menuPass($site);
        }

        if ($key === 'logo') {
            return $base + $this->logoPass($site);
        }

        return $base;
    }

    /** @return list<array<string, mixed>> */
    private function listingCandidates(User $user): array
    {
        if ($user->integrationConnections()->where('platform', 'google-business')->exists()) {
            return [];
        }

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
        if ($intents->isEmpty()) {
            return [];
        }

        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->whereIn('surface_key', $intents->pluck('surface_key')->unique()->all())
            ->whereNull('deleted_at')
            ->get(['id', 'surface_key', 'resource_id', 'visibility', 'last_refresh_status']);
        $byKey = $connections->keyBy(fn (IntegrationConnection $c) => $c->surface_key.'|'.$c->resource_id);

        $rows = [];
        foreach ($intents as $intent) {
            $surface = CompiledCatalog::surface((string) $intent->surface_key);
            if ($surface === null) {
                continue;
            }
            $legacy = LegacyPlatformMap::legacyFor((string) $intent->surface_key);
            $category = $this->registry->get((string) $legacy)?->getCategory()?->value;
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
                'accountName' => $intent->identifier_label ?? null,
                'avatar' => null,
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

        return $rows;
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

    /** @return array<string, mixed> */
    private function menuPass(Site $site): array
    {
        $resolved = $this->pools->resolve($site, 'menus');
        $items = $resolved['library'];

        // Provenance split (decision 12, filled in by A.10): platform dishes
        // arrive selected, scan/website finds arrive unselected in `found`.
        $platform = [];
        $found = [];
        foreach ($items as $item) {
            $provenance = $item['provenance'] ?? 'platform';
            if ($provenance === 'platform') {
                $platform[] = $item;
            } else {
                $found[] = $item;
            }
        }

        $categories = [];
        foreach ($platform as $item) {
            $name = (string) ($item['category'] ?? 'Menu');
            $categories[$name] ??= ['id' => md5($name), 'name' => $name, 'items' => []];
            $categories[$name]['items'][] = $item;
        }

        return ['categories' => array_values($categories), 'found' => $found];
    }

    /** @return array<string, mixed> */
    private function logoPass(?Site $site): array
    {
        $candidates = ['square' => [], 'full' => []];
        if ($site !== null) {
            try {
                foreach (DB::table('site.logo_candidates')->where('site_id', $site->id)->where('state', 'proposed')->get() as $row) {
                    $slot = (string) $row->slot;
                    $candidates[$slot][] = [
                        'id' => (string) $row->id,
                        'url' => $row->source_url,
                        'w' => $row->width !== null ? (int) $row->width : null,
                        'h' => $row->height !== null ? (int) $row->height : null,
                    ];
                }
            } catch (\Throwable) {
                // Table lands with A.10 — an empty offer until then.
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
