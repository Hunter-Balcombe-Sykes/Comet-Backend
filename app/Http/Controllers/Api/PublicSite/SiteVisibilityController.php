<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\PublicSite\UpdateVisibilityRequest;
use App\Http\Resources\SiteResource;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;

// V2: Toggles whether a professional's mini-site is publicly published or hidden.
class SiteVisibilityController extends ApiController
{
    public function update(UpdateVisibilityRequest $request): JsonResponse
    {
        /** @var User $professional */
        $professional = $request->attributes->get('professional');

        // Extra safety: if someone ever bypasses middleware, don't allow disabled accounts.
        if (! $professional || $professional->status !== 'active') {
            return $this->error('Account is not active.', 403);
        }

        $site = Site::query()
            ->where('user_id', $professional->id)
            ->firstOrFail();

        // Ownership + pending-deletion guard before any mutation.
        $this->authorizeForUser($professional, 'update', $site);

        $site->published = (bool) $request->validated('published');
        $site->save();

        return $this->success([
            'site' => new SiteResource($site->fresh()),
        ]);
    }
}
