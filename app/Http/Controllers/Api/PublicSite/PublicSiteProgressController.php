<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\BuildProgressReader;
use Illuminate\Http\JsonResponse;

/**
 * GET /public/sites/{handle}/progress — the sitepage's "still being set up"
 * overlay (2026-09-02). Keyed by HANDLE because a visitor holds no build
 * id; answers only {done, stage}: no labels, no thumbnails, nothing about
 * the person that the live page does not already show. A handle with no
 * live unclaimed build — claimed, expired, or never built — is simply
 * `done`, which is the honest answer for an overlay: nothing to wait for.
 * Rate-limited like the prewarm route (site-progress).
 */
class PublicSiteProgressController extends ApiController
{
    public function __invoke(string $handle, BuildProgressReader $reader): JsonResponse
    {
        $lowered = strtolower(trim($handle));
        if (! preg_match('/^[a-z0-9-]{1,63}$/', $lowered)) {
            return $this->success(['done' => true, 'stage' => null]);
        }

        $userId = User::query()->where('handle_lc', $lowered)->value('id');
        $build = $userId
            ? PreAccountBuild::query()->where('user_id', $userId)->whereNull('claimed_at')->orderByDesc('created_at')->first()
            : null;

        if (! $build) {
            return $this->success(['done' => true, 'stage' => null]);
        }

        // The poll is what stamps the tiers; a visitor's overlay may be the
        // only reader of a build nobody is polling, so observe here too.
        $build->observeTierMarkers();

        return $this->success($reader->forSite($build));
    }
}
