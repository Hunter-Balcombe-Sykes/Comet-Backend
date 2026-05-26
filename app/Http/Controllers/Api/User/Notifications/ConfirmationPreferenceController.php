<?php

namespace App\Http\Controllers\Api\User\Notifications;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\User\ConfirmationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Manages UI confirmation dialog skip preferences for destructive actions (delete customer, delete media, etc.).
class ConfirmationPreferenceController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly ConfirmationPreferenceService $preferences
    ) {}

    public function show(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);

        return $this->success([
            'confirmation_preferences' => $this->preferences->getForProfessional((string) $professional->id),
        ]);
    }

    public function update(\App\Http\Requests\Api\User\UpdateConfirmationPreferenceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        if ($validated === []) {
            return $this->error(
                'No confirmation preference fields were provided.',
                422
            );
        }

        $professional = $this->currentUser($request);
        $updated = $this->preferences->updateForProfessional((string) $professional->id, $validated);

        return $this->success([
            'confirmation_preferences' => $updated,
        ]);
    }
}
