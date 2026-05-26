<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\User\Site\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// V2: Site settings management (subdomain, theme, settings JSON, publish status). Powers the mini-site builder.
class UserSiteController extends ApiController
{
    use ResolveCurrentUser;
    use ResolveCurrentSite;


    public function show(Request $request)
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);

        return $this->success(['site' => new SiteResource($site)]);
    }

    public function update(UpdateSiteRequest $request, UpdateSiteAction $action)
    {
        $professional = $this->currentUser($request);
        $data = $request->validated();
        $site = $action->execute($professional, $data);

        return $this->success(['site' => new SiteResource($site)]);
    }

    /**
     * Dedicated endpoint for booking mode + external URL.
     * Scoped validation so the frontend doesn't need to use the generic site update.
     */
    public function updateBookingSettings(Request $request, UpdateSiteAction $action): JsonResponse
    {
        $allowedModes = ['manual'];

        $validator = Validator::make($request->all(), [
            'booking_mode' => ['required', 'string', Rule::in($allowedModes)],
            'manual_booking_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $professional = $this->currentUser($request);

        $site = $action->execute($professional, [
            'settings' => [
                'booking_mode' => $validated['booking_mode'],
                'manual_booking_url' => $validated['manual_booking_url'] ?? null,
            ],
        ]);

        $settings = is_array($site->settings) ? $site->settings : [];

        return $this->success([
            'booking_mode' => $settings['booking_mode'] ?? 'manual',
            'manual_booking_url' => $settings['manual_booking_url'] ?? null,
        ]);
    }

    public function visibility(UpdateSiteRequest $request, UpdateSiteAction $action)
    {
        $professional = $this->currentUser($request);
        $data = $request->validated();
        $site = $action->execute($professional, $data);

        return $this->success(['site' => new SiteResource($site)]);
    }
}
