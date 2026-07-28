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
    public const MAX_LINKS = 20;

    public function __construct(
        private readonly LinkCardScraper $scraper,
        private readonly LinkRouter $router,
    ) {}

    /**
     * Gateway — route first, fall through to seedCustom() only when the router
     * says 'custom'. Entry points call this. Everything downstream calls
     * seedCustom() instead.
     *
     * A caller looping over many URLs MUST create one RouteContext and pass it
     * to every call — that is what carries the per-run commerce probe budget
     * and the first-link-per-platform dedupe. Letting it default inside a loop
     * gives every URL a fresh budget of RouteContext::DEFAULT_MAX_PROBES, i.e.
     * no cap at all, which is how one link-in-bio page can fan out an unbounded
     * number of probes onto the scraping queue. The default exists only for
     * genuine single-URL entry points.
     */
    public function seed(User $user, string $url, ?RouteContext $ctx = null): ?IntegrationConnection
    {
        if ($user->isPendingDeletion()) {
            return null;
        }

        $result = $this->router->route($user, $url, $ctx ?? new RouteContext);

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

        return $this->writeCard($user, $normalized)['row'];
    }

    /**
     * Manual add from the routing endpoint (a Verdict::Note on POST
     * /routing/links). Same write as seedCustom() with two deliberate
     * differences: the previous-website skip does NOT apply (an explicit
     * paste is user intent, not a scrape re-discovering the old site), and
     * the outcome is discriminated so the controller can shape a real HTTP
     * answer instead of a silent null (cap → 422, lock → 423).
     *
     * @return array{status: 'created'|'exists'|'cap_full'|'busy'|'invalid'|'unavailable', row: ?IntegrationConnection}
     */
    public function addManual(User $user, string $url): array
    {
        if ($user->isPendingDeletion() || ! FeatureAvailability::for($user)->allows('integration.custom')) {
            return ['status' => 'unavailable', 'row' => null];
        }

        $normalized = $this->scraper->normalizeUrl($url);
        if ($normalized === null) {
            return ['status' => 'invalid', 'row' => null];
        }

        return $this->writeCard($user, $normalized);
    }

    /**
     * The one custom-link write body (extracted verbatim from seedCustom,
     * which itself carried CustomLinksController::addLink's semantics): rid
     * from the lowercased URL, minimal card payload, per-user custom lock,
     * dedupe by rid, MAX_LINKS cap, EnrichLinkCardJob on genuine creates.
     *
     * @return array{status: 'created'|'exists'|'cap_full'|'busy', row: ?IntegrationConnection}
     */
    private function writeCard(User $user, string $normalized): array
    {
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

            return ['status' => 'busy', 'row' => null];
        }

        if ($row === null) {
            return ['status' => 'cap_full', 'row' => null];
        }

        if ($isNew) {
            EnrichLinkCardJob::dispatch((string) $user->id, 'custom', $rid, $normalized)->afterCommit();
        }

        return ['status' => $isNew ? 'created' : 'exists', 'row' => $row];
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
