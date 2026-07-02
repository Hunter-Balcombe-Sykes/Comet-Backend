<?php

namespace App\Http\Controllers\Api\User\Site;

use App\Http\Controllers\Api\ApiController;
use App\Services\Site\SubdomainAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /site/subdomain-availability?subdomain=<candidate>
 *
 * Read-only live check for the dashboard URL-change flow. Runs the exact
 * validation the PATCH /site save would (SubdomainAvailabilityService), so
 * "Available" while typing can never disagree with the save. Also returns
 * the user's change-window state (can_change / next_change_available_at)
 * so the locked state renders from one call.
 */
class SubdomainAvailabilityController extends ApiController
{
    public function __invoke(Request $request, SubdomainAvailabilityService $availability): JsonResponse
    {
        $validated = $request->validate([
            'subdomain' => ['required', 'string', 'max:255'],
        ]);

        $professional = $request->attributes->get('professional');

        return $this->success(
            $availability->checkForUser($professional, (string) $validated['subdomain'])
        );
    }
}
