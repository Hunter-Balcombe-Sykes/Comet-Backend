<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Resolves, from a user's online-ordering links, BOTH:
//  (a) which platform's menu to scrape + the store URL (the content source), and
//  (b) the per-item order links shown on each menu item (read-time, never stored).
//
// Content priority is Uber Eats > DoorDash — if the user has an Uber Eats link
// the menu content comes from there; DoorDash is the fallback. Order LINKS route
// independently by mode: pickup / delivery point at the most-recently-added entry
// of each type (the type is set by the Google Business harvest; manual links have
// none), and when no typed entry exists the menu shows a single Order button (the
// most-recent link). Content and links can therefore come from different
// platforms — intentional.
class MenuSource
{
    // Per-instance entries cache: avoids repeated DB queries when a single request
    // calls multiple public methods (e.g. MenuController::show calls resolveAll
    // then links for the same user). Keyed by user id (string) so the cache is
    // still correct when the service is shared across contexts in tests.
    private array $entriesCache = [];

    // slug => host_pattern, in content-priority order (Uber Eats wins). Lazily
    // built from config('partna.menu.platforms') (FOUND-23 registry) — memoized
    // per instance since it never changes mid-request.
    private ?array $hostPatterns = null;

    /** @return array<string,string> slug => host_pattern, registry order */
    private function hostPatterns(): array
    {
        return $this->hostPatterns ??= array_map(fn (array $m) => $m['host_pattern'], config('partna.menu.platforms'));
    }

    /**
     * The platform + store URL to scrape, or null when the user has no
     * Uber Eats / DoorDash ordering link. storeUrl is the most-recent matching
     * entry's url, normalized (query/tracking + trailing slash stripped) so
     * pickup vs delivery variants of one store collapse to a single URL for
     * change-detection.
     *
     * @return array{platform:string, storeUrl:string}|null
     */
    public function resolve(User|string $user): ?array
    {
        $entries = $this->entries($user);
        foreach (array_keys(config('partna.menu.platforms')) as $platform) {
            $match = $entries->first(fn (array $e) => $this->platformOf($e['url'] ?? null) === $platform);
            if (is_array($match)) {
                return ['platform' => $platform, 'storeUrl' => $this->normalize((string) $match['url'])];
            }
        }

        return null;
    }

    /**
     * The full scrape plan for a user's menu, or null when no registry platform
     * (Uber Eats / DoorDash) is connected:
     *   - contentSource    — platform whose structure is canonical (highest
     *                        registry-priority connected platform wins; UE first)
     *   - storeUrls        — slug-keyed normalized store URL per registry platform
     *                        (null for a platform with no connected link)
     *   - pickupPlatform   — platform (uber-eats/doordash) pricing pickup, taken
     *                        from the newest pickup-typed ordering link that is one
     *                        of those two (null when none is)
     *   - deliveryPlatform — same, for delivery
     *   - address          — city/state locale for DoorDash (city + state only; never the full street)
     *
     * @return array{contentSource:string, storeUrls:array<string,?string>, pickupPlatform:?string, deliveryPlatform:?string, address:?string}|null
     */
    public function resolveAll(User|string $user): ?array
    {
        $entries = $this->entries($user);

        // Newest matching entry per registry platform, in priority order.
        $firsts = [];
        foreach (array_keys(config('partna.menu.platforms')) as $slug) {
            $firsts[$slug] = $entries->first(fn (array $e) => $this->platformOf($e['url'] ?? null) === $slug);
        }
        $present = array_filter($firsts, 'is_array');
        if ($present === []) {
            return null;
        }

        $storeUrls = [];
        foreach (array_keys(config('partna.menu.platforms')) as $slug) {
            $storeUrls[$slug] = isset($present[$slug]) ? $this->normalize((string) $present[$slug]['url']) : null;
        }

        return [
            // array_filter preserves key order, so the first key is the
            // highest-priority connected platform — identical to the old
            // UE-preferred ternary.
            'contentSource' => array_key_first($present),
            'storeUrls' => $storeUrls,
            'pickupPlatform' => $this->modePlatform($entries, 'pickup'),
            'deliveryPlatform' => $this->modePlatform($entries, 'delivery'),
            'address' => $this->address($user),
        ];
    }

    /**
     * Per-item order links computed from the live ordering entries:
     *  - pickupUrl   = newest entry with data.type === 'pickup'
     *  - deliveryUrl = newest entry with data.type === 'delivery'
     *  - orderUrl    = newest entry overall, used ONLY when neither typed link exists
     *
     * @return array{pickupUrl:?string, deliveryUrl:?string, orderUrl:?string}
     */
    public function links(User|string $user): array
    {
        $entries = $this->entries($user);

        $pickup = $entries->first(fn (array $e) => data_get($e, 'data.type') === 'pickup');
        $delivery = $entries->first(fn (array $e) => data_get($e, 'data.type') === 'delivery');
        $newest = $entries->first();

        $pickupUrl = is_array($pickup) ? ($pickup['url'] ?? null) : null;
        $deliveryUrl = is_array($delivery) ? ($delivery['url'] ?? null) : null;
        // A single Order button only when neither a pickup nor a delivery typed
        // link exists — otherwise the per-mode buttons cover ordering.
        $orderUrl = ($pickupUrl === null && $deliveryUrl === null && is_array($newest))
            ? ($newest['url'] ?? null)
            : null;

        return ['pickupUrl' => $pickupUrl, 'deliveryUrl' => $deliveryUrl, 'orderUrl' => $orderUrl];
    }

    /**
     * The consolidated ordering-store link set per scraped platform — the input
     * the menu uses for each item's per-platform `modes` + `url`.
     *
     * Online-ordering rows for ONE store can split into two entries (a pickup-
     * typed one and a delivery-typed one, e.g. from the Google Business harvest);
     * here they collapse into a single logical store keyed by
     * (platform, normalized store path) — normalize() strips the query string, so
     * the mode-distinguishing params (Uber Eats ?diningMode / ?ps / ?mod) fall
     * away and pickup + delivery for one store map to the same key. The newest
     * store per platform wins (entries are newest-first).
     *
     * Per platform:
     *   - pickupUrl   = a pickup-typed entry's url for that store (newest), else null
     *   - deliveryUrl = a delivery-typed entry's url for that store (newest), else null
     *   - storeUrl    = the store's representative url (newest entry's raw url)
     *   - modes       = ['pickup'] / ['delivery'] / both — the modes the store's
     *                   links carry; BOTH when the store has no type info at all
     *                   (we can't tell, so offer both).
     *
     * @return array<string, array{pickupUrl:?string, deliveryUrl:?string, storeUrl:?string, modes:list<string>}>
     */
    public function storeLinks(User|string $user): array
    {
        $entries = $this->entries($user);

        // First (newest) store seen per platform wins — mirrors content/link
        // recency everywhere else. Key by platform → its consolidated store.
        $byPlatform = [];
        foreach ($entries as $entry) {
            $platform = $this->platformOf($entry['url'] ?? null);
            if ($platform === null) {
                continue;
            }
            $storeKey = $platform.'|'.$this->normalize((string) $entry['url']);

            // A newer store for this platform already claimed the slot — but only
            // skip when it's a DIFFERENT store. Same-store entries (pickup +
            // delivery rows) must keep merging their typed urls into one set.
            $current = $byPlatform[$platform] ?? null;
            if ($current !== null && $current['key'] !== $storeKey) {
                continue;
            }

            $type = data_get($entry, 'data.type');
            $url = $entry['url'] ?? null;
            $byPlatform[$platform] ??= [
                'key' => $storeKey,
                'pickupUrl' => null,
                'deliveryUrl' => null,
                'storeUrl' => $url,
            ];
            // Newest typed url per mode wins (entries are newest-first → first set sticks).
            if ($type === 'pickup') {
                $byPlatform[$platform]['pickupUrl'] ??= $url;
            } elseif ($type === 'delivery') {
                $byPlatform[$platform]['deliveryUrl'] ??= $url;
            }
        }

        $out = [];
        foreach ($byPlatform as $platform => $store) {
            $modes = [];
            if ($store['pickupUrl'] !== null) {
                $modes[] = 'pickup';
            }
            if ($store['deliveryUrl'] !== null) {
                $modes[] = 'delivery';
            }
            // No type info on any of this store's links → can't tell, so offer both.
            if ($modes === []) {
                $modes = ['pickup', 'delivery'];
            }

            $out[$platform] = [
                'pickupUrl' => $store['pickupUrl'],
                'deliveryUrl' => $store['deliveryUrl'],
                'storeUrl' => $store['storeUrl'],
                'modes' => $modes,
            ];
        }

        return $out;
    }

    /**
     * The user's online-ordering payloads, newest-first, each guaranteed a
     * non-empty string `url`. Soft-deleted rows are excluded by the model scope;
     * online-ordering rows share sort_order 0, so created_at is the recency key.
     *
     * Memoized per user-id for the lifetime of this instance: MenuController::show
     * calls both resolveAll() and links() in one request, which would otherwise
     * double-query the same table. Safe because this service is read-only — no
     * path through MenuSource mutates online-ordering rows.
     *
     * @return Collection<int, array<string,mixed>>
     */
    private function entries(User|string $user): Collection
    {
        $userId = (string) ($user instanceof User ? $user->id : $user);

        if (! isset($this->entriesCache[$userId])) {
            $this->entriesCache[$userId] = IntegrationConnection::query()
                ->where('user_id', $userId)
                ->where('platform', Platform::OnlineOrdering->value)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (IntegrationConnection $r) => CardPayload::fromArray($r->payload)->toArray())
                // FOUND-36 (documented-defer): `url` is filtered here only, over ≤MAX_ENTRIES (10)
                // online-ordering rows per user — no DB filter/index/uniqueness need exists today.
                // Revisit only if a second reader needs to query by url.
                ->filter(fn (array $p) => is_string($p['url'] ?? null) && $p['url'] !== '')
                ->values();
        }

        return $this->entriesCache[$userId];
    }

    /**
     * Platform (uber-eats/doordash) of the newest ordering link carrying this
     * mode type, or null when no such link resolves to a scraped platform.
     */
    private function modePlatform(Collection $entries, string $type): ?string
    {
        $match = $entries->first(fn (array $e) => data_get($e, 'data.type') === $type
            && $this->platformOf($e['url'] ?? null) !== null);

        return is_array($match) ? $this->platformOf($match['url']) : null;
    }

    /**
     * City + state locale string for DoorDash's consumer address field, or null
     * when neither city nor state is stored (the scraper falls back to
     * DOORDASH_FALLBACK_ADDRESS). Reads from site.workplaces (FOUND-4).
     *
     * Data minimisation: the workplace street address — which a sole trader may
     * have entered as a home address — must not reach a US third-party processor
     * (Apify). City + state is sufficient for the actor's locale-fix purpose
     * (preventing the default Chicago, IL) without exposing a home address.
     */
    private function address(User|string $user): ?string
    {
        $userId = $user instanceof User ? $user->id : $user;

        // Join through site to get the user's workplace row.
        $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $userId)->value('id');
        if (! $siteId) {
            return null;
        }

        $workplace = Workplace::query()->where('site_id', $siteId)->first();

        $parts = array_filter(
            [$workplace?->city, $workplace?->state],
            fn ($v) => is_string($v) && trim($v) !== '',
        );

        return $parts === [] ? null : implode(', ', $parts).', Australia';
    }

    private function platformOf(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach ($this->hostPatterns() as $platform => $pattern) {
            if (preg_match($pattern, $host) === 1) {
                return $platform;
            }
        }

        return null;
    }

    /** scheme://host/path with query + fragment + trailing slash stripped. */
    private function normalize(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.$host.$path;
    }
}
