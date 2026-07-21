<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;

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
        $existing = IntegrationConnection::query()
            ->where('user_id', $user->id)->where('platform', 'custom')->where('resource_id', $rid)->first();

        if ($existing === null) {
            $currentCount = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'custom')->count();
            if ($currentCount >= self::MAX_LINKS) {
                return null;
            }
        }

        $payload = ['kind' => 'link', ...$this->scraper->minimalCard($normalized)];
        $row = IntegrationConnection::updateOrCreate(
            ['user_id' => $user->id, 'platform' => 'custom', 'resource_id' => $rid],
            ['resource_kind' => 'link', 'payload' => $payload, 'is_active' => true, 'last_refresh_status' => 'pending'],
        );

        if ($existing === null) {
            EnrichLinkCardJob::dispatch((string) $user->id, 'custom', $rid, $normalized)->afterCommit();
        }

        return $row;
    }
}
