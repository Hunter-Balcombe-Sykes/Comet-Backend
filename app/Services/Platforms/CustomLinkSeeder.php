<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Universal link gateway (2026-07-25, Phase 5).
 *
 * seed() — the gateway. Calls LinkRouter::route(), delegates, and only falls
 * through to seedCustom() when the router says outcome === 'custom'.
 *
 * seedCustom() — the raw custom-link write. Today's body verbatim (previous-
 * website skip, integration.custom gate, MAX_LINKS, lock, EnrichLinkCardJob).
 * Never routes. This is what every fallback path calls.
 *
 * Rule: only an entry point may call seed(). Everything downstream calls
 * seedCustom(). This avoids mutual recursion with LinkRouter.
 */
class CustomLinkSeeder
{
    private const MAX_LINKS = 20;

    public function __construct(
        private readonly LinkCardScraper $scraper,
        private readonly LinkRouter $router,
    ) {}

    /**
     * Gateway — route first, fall through to seedCustom() only when the router
     * says 'custom'. Entry points call this. Everything downstream calls
     * seedCustom() instead.
     */
    public function seed(User $user, string $url): ?IntegrationConnection
    {
        if ($user->isPendingDeletion()) {
            return null;
        }

        $result = $this->router->route($user, $url, new RouteContext(maxProbes: 6));

        if ($result->outcome === 'custom') {
            return $this->seedCustom($user, $url);
        }

        // 'seeded', 'pending', 'skipped' — not a custom link.
        return null;
    }

    /**
     * Raw custom-link write — today's body verbatim. Never routes.
     * Every fallback path calls this, not seed().
     */
    public function seedCustom(User $user, string $url): ?IntegrationConnection
    {
        if ($user->isPendingDeletion()) {
            return null;
        }
        if (! FeatureAvailability::for($user)->allows('integration.custom')) {
            return null;
        }

        $normalized = $this->scraper->normalizeUrl($url);
        if ($normalized === null) {
            return null;
        }

        $previousWebsite = $user->site?->workplace?->previous_website;
        if ($previousWebsite !== null && $this->matchesPreviousWebsite($normalized, $previousWebsite)) {
            Log::info('platforms.custom_link_seeder.skipped_previous_website', ['user_id' => (string) $user->id]);

            return null;
        }

        $rid = 'link-'.substr(sha1(strtolower($normalized)), 0, 16);
        $payload = ['kind' => 'link', ...$this->scraper->minimalCard($normalized)];

        $key = CacheKeyGenerator::platformConnectionLock('custom', (string) $user->id);
        try {
            [$row, $isNew] = Cache::lock($key, 10)->block(5, function () use ($user, $rid, $payload) {
                $existing = IntegrationConnection::query()
                    ->where('user_id', $user->id)->where('platform', 'custom')->where('resource_id', $rid)->first();

                if ($existing === null) {
                    $currentCount = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'custom')->count();
                    if ($currentCount >= self::MAX_LINKS) {
                        return [null, false];
                    }
                }

                $row = IntegrationConnection::updateOrCreate(
                    ['user_id' => $user->id, 'platform' => 'custom', 'resource_id' => $rid],
                    ['resource_kind' => 'link', 'payload' => $payload, 'is_active' => true, 'last_refresh_status' => 'pending'],
                );

                return [$row, $existing === null];
            });
        } catch (LockTimeoutException) {
            Log::warning('platforms.custom_link_seeder.lock_timeout', ['user_id' => (string) $user->id, 'resource_id' => $rid]);

            return null;
        }

        if ($row !== null && $isNew) {
            EnrichLinkCardJob::dispatch((string) $user->id, 'custom', $rid, $normalized)->afterCommit();
        }

        return $row;
    }

    /**
     * True when $normalizedUrl is the user's previous website or any page on
     * the same host — so a scrape never re-adds the old site we're replacing
     * as a link. Hosts compared lowercased with a leading "www." stripped, by
     * EQUALITY (never substring containment — "notoven.com.au" must not match
     * "oven.com.au"). An unparseable previous website never matches. Only
     * auto-seeded links reach this class, so manual link-adds are unaffected.
     *
     * NB host-level match is intentional so subpages are caught too. If the
     * previous website is ever a shared-host service (e.g. linktr.ee/<user>),
     * this would also skip other links on that host — acceptable given
     * previous_website is effectively always the user's own domain; revisit
     * only if that assumption breaks.
     */
    private function matchesPreviousWebsite(string $normalizedUrl, string $previousWebsite): bool
    {
        $prev = $this->scraper->normalizeUrl($previousWebsite);
        if ($prev === null) {
            return false;
        }

        $host = static function (string $url): ?string {
            $h = parse_url($url, PHP_URL_HOST);

            return is_string($h) && $h !== '' ? preg_replace('/^www\./i', '', strtolower($h)) : null;
        };

        $linkHost = $host($normalizedUrl);
        $prevHost = $host($prev);

        return $linkHost !== null && $linkHost === $prevHost;
    }
}
