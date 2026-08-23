<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Analytics\ContentPopularityReader;
use App\Site\Actions\ActionCandidates;
use App\Site\Actions\ActionSettings;
use App\Site\Actions\ActionSlots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /api/site/actions — the dashboard's /actions page data (spec §7):
//   mode        the owner's mode
//   slots       the stored slots, each flagged `unavailable` when its id is no
//               longer a candidate (so the table can say why, not drop it)
//   entries     EXACTLY the public resolution for the current state — same
//               candidates, same scores, same ActionSlots — so preview and
//               lander cannot drift
//   candidates  the full searchable set for the swap popover, in smart order,
//               with score + scoreShare (score ÷ site max) and connectedAt
// Read-only; writes go through PATCH /api/site settings.actions. This does a
// full pool hydration per call — owner-only and uncached by design.
class UserSiteActionsController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function show(
        Request $request,
        ActionCandidates $candidates,
        ContentPopularityReader $popularity,
    ): JsonResponse {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        $this->authorizeForUser($professional, 'view', $site);

        $settings = ActionSettings::fromSite($site);
        $set = $candidates->forSite($professional, $site);
        $scores = $popularity->actionScoresForSite($site->id);
        $resolved = ActionSlots::resolve($set, $settings->mode === 'smart' ? $scores : [], $settings, (int) config('partna.actions.slots', 10));

        $unavailable = array_flip($resolved['unavailable']);
        $max = $scores === [] ? 0.0 : max($scores);

        return $this->success([
            'mode' => $settings->mode,
            'slots' => array_map(
                static fn (array $slot): array => $slot + ['unavailable' => isset($unavailable[$slot['id']])],
                $settings->slots,
            ),
            'entries' => $resolved['entries'],
            'candidates' => array_map(
                static function (array $c) use ($scores, $max): array {
                    $score = $scores[$c['id']] ?? null;

                    return [
                        'id' => $c['id'],
                        'kind' => $c['kind'],
                        'label' => $c['label'],
                        'url' => $c['url'],
                        'thumb' => $c['thumb'],
                        'connectedAt' => $c['connectedAt'],
                        'score' => $score === null ? null : round($score, 4),
                        'scoreShare' => $score === null || $max <= 0.0 ? null : round($score / $max, 4),
                        'ref' => $c['ref'],
                        'meta' => $c['meta'],
                    ];
                },
                ActionSlots::order($set, $scores),
            ),
        ]);
    }
}
