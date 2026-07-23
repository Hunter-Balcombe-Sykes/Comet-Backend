<?php

namespace App\Http\Controllers\Api\User\Onboarding;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Onboarding\OnboardingSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /api/onboarding/suggestions — the server-computed payload driving the
// post-claim signup setup steps (signup-v2 E/F). Read-only; all decisions
// (which steps to show, which ≤2 integrations to suggest, prefills) live in
// OnboardingSuggestions so both account types share one endpoint.
class OnboardingController extends ApiController
{
    use ResolveCurrentUser;

    public function suggestions(Request $request, OnboardingSuggestions $suggestions): JsonResponse
    {
        $user = $this->currentUser($request);
        $user->loadMissing('site');

        return $this->success($suggestions->for($user));
    }
}
