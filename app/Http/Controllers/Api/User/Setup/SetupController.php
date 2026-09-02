<?php

namespace App\Http\Controllers\Api\User\Setup;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Setup\SetupBatchApplier;
use App\Services\Setup\SetupPassRegistry;
use App\Services\Setup\SetupPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The setup dialog's wire (A.9). GET composes the passes; PUT records the
 * pass being shown (Back and Continue both write it) and `completed: true`
 * stamps the one done-bit /me exposes; POST accept is one Continue's batch.
 */
class SetupController extends ApiController
{
    use ResolveCurrentUser;

    public function show(Request $request, SetupPayload $payload): JsonResponse
    {
        return $this->success($payload->for($this->currentUser($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $user->site;
        if ($site === null) {
            return $this->error('No site.', 404);
        }

        $data = $request->validate([
            'step' => ['sometimes', 'string', 'max:40'],
            'completed' => ['sometimes', 'boolean'],
        ]);

        if (($data['completed'] ?? false) === true) {
            $site->forceFill([
                'setup_step' => 'done',
                'setup_completed_at' => $site->setup_completed_at ?? now(),
            ])->save();
        } elseif (isset($data['step'])) {
            if (! SetupPassRegistry::isValidStep($user, $data['step'])) {
                return $this->error('Unknown setup step.', 422);
            }
            $site->forceFill(['setup_step' => $data['step']])->save();
        }

        $fresh = $site->fresh();

        return $this->success([
            'setup' => [
                'step' => $fresh->setup_step,
                'completed_at' => $fresh->setup_completed_at?->toIso8601String(),
            ],
        ]);
    }

    public function accept(Request $request, SetupBatchApplier $applier, SetupPayload $payload): JsonResponse
    {
        $user = $this->currentUser($request);

        $data = $request->validate([
            'pass' => ['required', 'string', 'max:40'],
            'accept' => ['sometimes', 'array', 'max:100'],
            'accept.*' => ['string'],
            'select' => ['sometimes', 'array', 'max:200'],
            'select.*' => ['string'],
            'exclude' => ['sometimes', 'array', 'max:200'],
            'exclude.*' => ['string'],
            'adopt' => ['sometimes', 'nullable', 'string'],
            'teamMember' => ['sometimes', 'nullable', 'string'],
            'logo' => ['sometimes', 'nullable', 'array'],
        ]);
        if (! SetupPassRegistry::isValidStep($user, $data['pass'])) {
            return $this->error('Unknown setup pass.', 422);
        }

        $result = $applier->apply($user, $data);

        // The refreshed pass, so one Continue round-trips the new truth.
        $refreshed = collect($payload->for($user)['passes'])->firstWhere('key', $data['pass']);

        return $this->success([
            'pass' => $refreshed,
            'errors' => $result['errors'] === [] ? (object) [] : $result['errors'],
        ]);
    }
}
