<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\UserSite\StaffToggleIntegrationRequest;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OV-A: staff view + enable/disable of a user's platform integrations.
 * Toggling flips is_active on every connection of that platform — the payload
 * builder and lander only surface active connections, so this is the staff
 * kill-switch for a broken/abusive integration without deleting user data.
 */
class StaffIntegrationManagementController extends ApiController
{
    /** GET /staff/professionals/{professional}/integrations */
    public function index(Request $request, User $professional): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        return $this->success(['integrations' => $this->integrationsSummary($professional)]);
    }

    /** PATCH /staff/professionals/{professional}/integrations/{platform} */
    public function update(StaffToggleIntegrationRequest $request, User $professional, string $platform): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $connections = IntegrationConnection::query()
            ->where('user_id', $professional->id)
            ->where('platform', $platform)
            ->get();

        if ($connections->isEmpty()) {
            return $this->error('No connections for this platform.', 404);
        }

        $isActive = (bool) $request->validated('is_active');

        foreach ($connections as $connection) {
            $connection->is_active = $isActive;
            $connection->save(); // per-model save so observers/cache-busting fire
        }

        return $this->success([
            'platform' => $platform,
            'is_active' => $isActive,
            'updated_count' => $connections->count(),
            'integrations' => $this->integrationsSummary($professional),
        ]);
    }

    /** @return array<int, array<string, mixed>> one entry per platform */
    private function integrationsSummary(User $professional): array
    {
        return IntegrationConnection::query()
            ->where('user_id', $professional->id)
            ->orderBy('platform')
            ->get(['id', 'platform', 'is_active', 'last_refreshed_at', 'last_refresh_status'])
            ->groupBy('platform')
            ->map(fn ($group, $platform) => [
                'platform' => (string) $platform,
                'connection_count' => $group->count(),
                'is_active' => $group->contains(fn ($row) => (bool) $row->is_active),
                'last_refreshed_at' => $group->pluck('last_refreshed_at')->max(),
                'has_refresh_error' => $group->contains(fn ($row) => $row->last_refresh_status === 'error'),
            ])
            ->values()
            ->all();
    }
}
