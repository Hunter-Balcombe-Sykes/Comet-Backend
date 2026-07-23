<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\PublicSite\SiteActionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Dashboard picker data for the design page's "Pages" + "Action buttons"
// controls: the live action pool (everything orderable), the currently-served
// rankedActions (override-applied — what the sitepage lander shows right now),
// and the stored ordering preferences. Read-only; writes go through
// PATCH /api/site settings (smart_page_order / manual_page_order /
// smart_actions / manual_actions).
class UserSiteActionsController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function show(
        Request $request,
        SiteActionsService $actions,
        ContentPopularityReader $popularity,
    ): JsonResponse {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        $this->authorizeForUser($professional, 'view', $site);

        $pool = $actions->pool($professional, $site);
        $stored = $popularity->rankedActionsForSite($site->id);
        $ordering = $actions->orderingSettings($site);

        // Pool entries carry their stored score when the job has ranked them
        // (score null = not yet scored — fresh connection before the next tick).
        $scores = [];
        foreach ($stored as $row) {
            $scores[$row['key']] = $row['score'];
        }

        return $this->success([
            'pool' => array_map(
                fn (array $entry): array => $actions->toWire($entry, $scores[$entry['id']] ?? null),
                $pool,
            ),
            'rankedActions' => $actions->resolveRankedActions(
                $pool,
                $stored,
                $ordering['smart_actions'],
                $ordering['manual_actions'],
            ),
            'ordering' => $actions->orderingWire($ordering),
        ]);
    }
}
