<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Collection;

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
    // host pattern → platform, in content-priority order (Uber Eats wins).
    private const PLATFORMS = [
        'uber-eats' => '~(^|\.)ubereats\.com$~',
        'doordash' => '~(^|\.)doordash\.com$~',
    ];

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
        foreach (array_keys(self::PLATFORMS) as $platform) {
            $match = $entries->first(fn (array $e) => $this->platformOf($e['url'] ?? null) === $platform);
            if (is_array($match)) {
                return ['platform' => $platform, 'storeUrl' => $this->normalize((string) $match['url'])];
            }
        }

        return null;
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
     * The user's online-ordering payloads, newest-first, each guaranteed a
     * non-empty string `url`. Soft-deleted rows are excluded by the model scope;
     * online-ordering rows share sort_order 0, so created_at is the recency key.
     *
     * @return Collection<int, array<string,mixed>>
     */
    private function entries(User|string $user): Collection
    {
        $userId = $user instanceof User ? $user->id : $user;

        return IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', 'online-ordering')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (IntegrationConnection $r) => is_array($r->payload) ? $r->payload : [])
            ->filter(fn (array $p) => is_string($p['url'] ?? null) && $p['url'] !== '')
            ->values();
    }

    private function platformOf(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::PLATFORMS as $platform => $pattern) {
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
