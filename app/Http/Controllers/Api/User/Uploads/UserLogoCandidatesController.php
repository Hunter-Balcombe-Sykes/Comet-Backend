<?php

namespace App\Http\Controllers\Api\User\Uploads;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Design\LogoCandidates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The stored logo candidates a sign-up business build collected (A.10) —
 * the choose-your-logo half of the design-media singletons next door.
 * GET lists the proposed rows per slot; POST promote turns one into the
 * slot's real singleton from its mirrored bytes.
 */
class UserLogoCandidatesController extends ApiController
{
    use ResolveCurrentUser;

    public function index(Request $request, LogoCandidates $candidates): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $user->site;
        if ($site === null) {
            return $this->error('No site.', 404);
        }

        $out = [];
        foreach (['square', 'full'] as $slot) {
            $out[$slot] = array_map(fn (object $row): array => [
                'id' => (string) $row->id,
                'url' => $row->source_url,
                'w' => $row->width !== null ? (int) $row->width : null,
                'h' => $row->height !== null ? (int) $row->height : null,
            ], $candidates->proposed($site, $slot));
        }

        return $this->success(['candidates' => $out]);
    }

    public function promote(Request $request, LogoCandidates $candidates, string $candidate): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $user->site;
        if ($site === null) {
            return $this->error('No site.', 404);
        }

        // 404, not 403, for a foreign or spent id — promote() scopes by
        // site_id, so an id that isn't this site's proposed row reads as
        // absent (public-enumeration convention).
        if (! $candidates->promote($user, $site, $candidate)) {
            return $this->error('No such logo candidate.', 404);
        }

        return $this->success(['promoted' => $candidate]);
    }
}
