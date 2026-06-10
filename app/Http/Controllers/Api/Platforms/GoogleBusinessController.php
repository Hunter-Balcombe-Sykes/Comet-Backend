<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectGoogleBusinessRequest;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Services\Platforms\GoogleBusinessService;
use Illuminate\Http\JsonResponse;

// Google Business — connect by Maps share link. Google exposes no keyless
// ratings/reviews API, so the card is the honest subset: place name +
// coordinates parsed from the link, a live keyless map embed on the
// sitepage, and open-in-Maps / directions actions.
class GoogleBusinessController extends SingleSelectionPlatformController
{
    public function __construct(private readonly GoogleBusinessService $service) {}

    protected function platform(): string
    {
        return 'google-business';
    }

    protected function resourceClass(): string
    {
        return GoogleBusinessConnectionResource::class;
    }

    // POST /api/platforms/google-business/connect
    public function connect(ConnectGoogleBusinessRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $place = $this->service->resolve($request->validated()['url']);
        if ($place === null) {
            return $this->error('Paste your Google Maps link — open your business on Google Maps, hit Share, and copy the link.', 422);
        }

        return $this->connected($user, $place);
    }
}
