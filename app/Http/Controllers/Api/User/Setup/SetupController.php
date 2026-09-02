<?php

namespace App\Http\Controllers\Api\User\Setup;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Platforms\MenuPhotoSweepJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
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
            $previous = $site->setup_step;
            $site->forceFill(['setup_step' => $data['step']])->save();
            $this->maybeDispatchMenuSweep($user, $previous, $data['step']);
        }

        $fresh = $site->fresh();

        return $this->success([
            'setup' => [
                'step' => $fresh->setup_step,
                'completed_at' => $fresh->setup_completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * A.10: crossing OUT of the platforms passes on a food business with no
     * ordering connection is the moment the platform lane has definitively
     * not produced a menu AND the person is live in the dialog waiting for
     * one — dispatch the deferred paid photo sweep (the tier 2 that
     * GoogleMenuPhotoScanJob skips on sign-up builds). The job re-checks the
     * ordering connection at run time and is unique per user per day, so a
     * Back/Continue bounce never re-bills.
     */
    private function maybeDispatchMenuSweep(User $user, ?string $previous, string $step): void
    {
        $keys = SetupPassRegistry::keysFor($user);
        $lastPlatforms = null;
        foreach ($keys as $i => $key) {
            if (str_starts_with($key, 'platforms.')) {
                $lastPlatforms = $i;
            }
        }
        $stepIdx = array_search($step, $keys, true);
        $prevIdx = $previous === null ? 0 : array_search($previous, $keys, true);
        if ($lastPlatforms === null || $stepIdx === false || $prevIdx === false) {
            return;
        }
        if ($stepIdx <= $lastPlatforms || $prevIdx > $lastPlatforms) {
            return; // not the platforms→content crossing
        }

        if (! AccountCapabilities::for($user)->can_use_menu) {
            return;
        }

        $hasOrdering = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('routing_class', 'ordering')
            ->exists();
        if ($hasOrdering) {
            return;
        }

        MenuPhotoSweepJob::dispatch((string) $user->id);
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
