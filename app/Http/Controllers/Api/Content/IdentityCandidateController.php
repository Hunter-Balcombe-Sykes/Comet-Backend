<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\ContentLibrary\RuleIdentityCandidateRequest;
use App\Http\Resources\Content\IdentityCandidateResource;
use App\Models\Content\IdentityCandidate;
use App\Models\Content\Item;
use App\Services\Content\ItemMerger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The "possible duplicates" queue (plan §5).
 *
 * The resolver merges on joining keys and, cross-source, on unambiguous
 * corroborating keys. Everything weaker lands here rather than being merged:
 * false-split over false-merge, with this queue as the declared recovery path.
 * That trade is only honest if the queue is somewhere a person can actually
 * resolve — a candidate nobody can answer is just a silent split.
 *
 * A ruling is recorded as DECISIONS over coords, which is the vocabulary the
 * pure resolver reads. Item ids are the resolver's output, so a ruling stored
 * against them would evaporate the next time identity was recomputed.
 */
class IdentityCandidateController extends ApiController
{
    use ResolveCurrentUser;

    /** Bounded like the suggestions inbox: a queue nobody can finish is noise. */
    private const MAX_CANDIDATES = 100;

    public function __construct(private readonly ItemMerger $merger) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $candidates = IdentityCandidate::query()
            ->where('user_id', $user->id)
            ->whereNull('dismissed_at')
            ->with(['leftItem', 'rightItem'])
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        return $this->success([
            'candidates' => IdentityCandidateResource::collection($candidates),
            // The count badge on the Library page. Bounded list, unbounded
            // count: the user should know there are 400 even if we show 100.
            'openCount' => IdentityCandidate::query()
                ->where('user_id', $user->id)
                ->whereNull('dismissed_at')
                ->count(),
        ]);
    }

    /**
     * Rule on a pair. `same` folds the two into one item; `different` records
     * a CUT the resolver applies after every union, so a shared key can never
     * re-merge them behind the user's back (C8).
     */
    public function rule(RuleIdentityCandidateRequest $request, string $candidateId): JsonResponse
    {
        $user = $this->currentUser($request);
        $candidate = $this->findCandidate($user->id, $candidateId);

        $this->authorizeForUser($user, 'curate', $candidate);

        $left = $this->findItem($user->id, (string) $candidate->left_item_id);
        $right = $this->findItem($user->id, (string) $candidate->right_item_id);

        if ($this->merger->exceedsPairBound($left, $right)) {
            return $this->error(
                'These two have more source records than a single ruling can settle. Split them by source first.',
                422,
                extra: ['code' => 'identity_pair_too_large', 'maxPairs' => ItemMerger::pairBound()],
            );
        }

        $verdict = $request->validated()['verdict'];

        $outcome = $verdict === 'same'
            ? $this->merger->merge($user, $left, $right, 'user ruled same from duplicates queue')
            : $this->merger->separate($user, $left, $right);

        // Either verdict settles the card. A resolved question that keeps
        // being asked is how an inbox becomes something people stop reading.
        $this->settle($candidate);

        return $this->success(['verdict' => $verdict] + $outcome);
    }

    /**
     * Dismiss without ruling: "stop asking me". Permanent by design — the
     * resolver will keep producing this candidate every run, so a dismissal
     * that expired would be the same question forever.
     */
    public function dismiss(Request $request, string $candidateId): JsonResponse
    {
        $user = $this->currentUser($request);
        $candidate = $this->findCandidate($user->id, $candidateId);

        $this->authorizeForUser($user, 'curate', $candidate);
        $this->settle($candidate);

        return $this->success(['dismissed' => true]);
    }

    private function settle(IdentityCandidate $candidate): void
    {
        // Written raw: the row may already have been dismissed by a merge that
        // touched the same pair, and this must stay idempotent.
        DB::table('content.identity_candidates')
            ->where('id', $candidate->id)
            ->whereNull('dismissed_at')
            ->update(['dismissed_at' => now()]);
    }

    private function findCandidate(string $userId, string $candidateId): IdentityCandidate
    {
        $candidate = IdentityCandidate::query()
            ->where('id', $candidateId)
            ->where('user_id', $userId)
            ->whereNull('dismissed_at')
            ->first();

        if ($candidate === null) {
            abort(404, 'That duplicate is no longer in your queue.');
        }

        return $candidate;
    }

    private function findItem(string $userId, string $itemId): Item
    {
        $item = Item::query()->where('id', $itemId)->where('user_id', $userId)->first();

        if ($item === null) {
            abort(404, 'Item not found.');
        }

        return $item;
    }
}
