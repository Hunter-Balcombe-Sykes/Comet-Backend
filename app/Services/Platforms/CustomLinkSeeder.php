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
 * Job-triggered custom-link creation — new callers only (Instagram bio-link
 * auto-save, link-in-bio scan, previous-website link harvest). Deliberately
 * NOT wired into CustomLinksController::addLink(), which stays untouched: its
 * MAX_LINKS/resource_id/EnrichLinkCardJob logic is lock-guarded, tested,
 * working code, and refactoring it here for a ~2-line DRY gain would need to
 * duplicate its "is this row new" check anyway. This class owns the same
 * contract for callers that never go through that controller, so it enforces
 * its own capability gate (integration.custom) that route-level middleware
 * would otherwise provide for free.
 */
class CustomLinkSeeder
{
    private const MAX_LINKS = 20;

    public function __construct(private LinkCardScraper $scraper) {}

    public function seed(User $user, string $url): ?IntegrationConnection
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

        $rid = 'link-'.substr(sha1(strtolower($normalized)), 0, 16);
        // Built outside the lock — pure/local (no HTTP fetch; see LinkCardScraper).
        $payload = ['kind' => 'link', ...$this->scraper->minimalCard($normalized)];

        // Races CustomLinksController::addLink(), which takes the same
        // per-user/platform lock (ManagesIntegrationConnection::withConnectionLock)
        // around its own existing-row check + MAX_LINKS count + write. Only the
        // authoritative read + write go inside the lock — the pre-checks above
        // and the payload build stay outside, and the job dispatch below stays
        // outside too (never hold a lock across an inline dispatch).
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
            // Best-effort job-triggered seed — the ?IntegrationConnection contract
            // already returns null on "couldn't seed".
            Log::warning('platforms.custom_link_seeder.lock_timeout', ['user_id' => (string) $user->id, 'resource_id' => $rid]);

            return null;
        }

        if ($row !== null && $isNew) {
            EnrichLinkCardJob::dispatch((string) $user->id, 'custom', $rid, $normalized)->afterCommit();
        }

        return $row;
    }
}
