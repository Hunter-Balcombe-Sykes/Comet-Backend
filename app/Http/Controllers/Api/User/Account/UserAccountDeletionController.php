<?php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// V2: Self-service account deletion endpoints. Thin HTTP layer that delegates to
// AccountDeletionService. Three endpoints: request (initiate), confirm (apply
// via email token), cancel (revert during grace period).
class UserAccountDeletionController extends ApiController
{
    public function __construct(
        private readonly AccountDeletionService $deletionService,
    ) {}

    /**
     * POST /api/professional/me/deletion/request
     * Sends a confirmation email with a token-bearing link.
     */
    public function request(Request $request): JsonResponse
    {
        /** @var User $professional */
        $professional = $request->attributes->get('professional');

        if ($professional->status === 'pending_deletion') {
            $deletesAt = null;
            if ($professional->deletion_confirmed_at) {
                $retentionDays = (int) config('partna.soft_delete_retention_days', 30);
                $deletesAt = Carbon::parse((string) $professional->deletion_confirmed_at)
                    ->addDays($retentionDays)
                    ->toIso8601String();
            }

            return $this->error('Account deletion already in progress.', 409, [
                'deletes_at' => $deletesAt,
            ]);
        }

        if (in_array($professional->status, ['suspended', 'disabled'], true)) {
            return $this->error('Suspended accounts cannot request deletion. Contact support.', 403);
        }

        // Gate through the policy AFTER the status checks above — 'update' denies
        // pending_deletion actors (handled with a friendlier 409 above) and does not
        // cover suspended/disabled, so it runs only for an active owner here. Mirrors
        // the doctrine gate in confirm().
        $this->authorizeForUser($professional, 'update', $professional);

        $result = $this->deletionService->request($professional, $request);

        if (! $result['success']) {
            $errors = [];
            if (isset($result['reasons'])) {
                $errors['reasons'] = $result['reasons'];
            }

            return $this->error($result['error'] ?? 'Request failed.', $result['code'], $errors);
        }

        return $this->success([
            'message' => 'Confirmation email sent. Check your inbox to confirm deletion.',
        ]);
    }

    /**
     * POST /api/professional/me/deletion/confirm
     * Body: { "token": "<raw_token>" }
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'min:32'],
        ]);

        /** @var User $professional */
        $professional = $request->attributes->get('professional');
        // 'update' policy gates ownership + pending-deletion block; no separate 'confirm' ability needed.
        $this->authorizeForUser($professional, 'update', $professional);

        $result = $this->deletionService->confirm($professional, (string) $request->input('token'), $request);

        if (! $result['success']) {
            return $this->error($result['error'] ?? 'Confirmation failed.', $result['code']);
        }

        return $this->success([
            'message' => 'Account deletion scheduled.',
            'deletes_at' => $result['deletes_at'],
        ]);
    }

    /**
     * POST /api/professional/me/deletion/cancel
     * Exempted from EnforcePendingDeletionReadOnly middleware via route definition.
     */
    public function cancel(Request $request): JsonResponse
    {
        /** @var User $professional */
        $professional = $request->attributes->get('professional');

        if ($professional->status !== 'pending_deletion') {
            return $this->error('No pending deletion to cancel.', 409);
        }

        // Ownership gate via cancelDeletion (NOT update): update() denies pending_deletion
        // actors, but cancel exists precisely to serve them.
        $this->authorizeForUser($professional, 'cancelDeletion', $professional);

        $this->deletionService->cancel($professional, $request);

        return $this->success([
            'message' => 'Account deletion cancelled. Your account is active again.',
        ]);
    }
}
