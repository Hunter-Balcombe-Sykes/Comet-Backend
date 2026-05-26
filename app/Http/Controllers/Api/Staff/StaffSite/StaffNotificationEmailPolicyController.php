<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\Notifications\UpdateNotificationEmailPoliciesRequest;
use App\Models\Core\User\User;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: Staff manages notification email policies (force_on, force_off, default) at global and per-professional level.
class StaffNotificationEmailPolicyController extends ApiController
{
    public function indexGlobal(): JsonResponse
    {
        $policies = DB::table('notifications.notification_email_policies')
            ->whereNull('user_id')
            ->get(['category_key', 'mode'])
            ->keyBy('category_key');

        $result = array_map(fn (string $cat): array => [
            'category' => $cat,
            'mode' => $policies->get($cat)?->mode ?? 'default',
        ], NotificationPublisher::categories());

        return $this->success(['policies' => array_values($result)]);
    }

    public function updateGlobal(UpdateNotificationEmailPoliciesRequest $request): JsonResponse
    {
        foreach ($request->validated()['policies'] as $update) {
            DB::statement(
                'INSERT INTO notifications.notification_email_policies (id, user_id, category_key, mode, created_at, updated_at)
                 VALUES (?, NULL, ?, ?, NOW(), NOW())
                 ON CONFLICT (category_key) WHERE user_id IS NULL
                 DO UPDATE SET mode = EXCLUDED.mode, updated_at = NOW()',
                [(string) Str::uuid(), $update['category'], $update['mode']]
            );
        }

        // Global policy affects every professional — bump the cache version
        // so every per-pro entry naturally invalidates on next lookup.
        NotificationPublisher::bumpGlobalVersion();

        return $this->success(['ok' => true]);
    }

    public function indexProfessional(User $professional): JsonResponse
    {
        $policies = DB::table('notifications.notification_email_policies')
            ->where('user_id', $professional->id)
            ->get(['category_key', 'mode'])
            ->keyBy('category_key');

        $result = array_map(fn (string $cat): array => [
            'category' => $cat,
            'mode' => $policies->get($cat)?->mode ?? 'default',
        ], NotificationPublisher::categories());

        return $this->success(['policies' => array_values($result)]);
    }

    public function updateProfessional(UpdateNotificationEmailPoliciesRequest $request, User $professional): JsonResponse
    {
        foreach ($request->validated()['policies'] as $update) {
            if ($update['mode'] === 'default') {
                DB::table('notifications.notification_email_policies')
                    ->where('user_id', $professional->id)
                    ->where('category_key', $update['category'])
                    ->delete();
            } else {
                DB::statement(
                    'INSERT INTO notifications.notification_email_policies (id, user_id, category_key, mode, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NOW(), NOW())
                     ON CONFLICT (user_id, category_key) WHERE user_id IS NOT NULL
                     DO UPDATE SET mode = EXCLUDED.mode, updated_at = NOW()',
                    [(string) Str::uuid(), $professional->id, $update['category'], $update['mode']]
                );
            }
        }

        // Targeted invalidation — only this professional's cache needs to drop.
        NotificationPublisher::forget($professional->id);

        return $this->success(['ok' => true]);
    }
}
