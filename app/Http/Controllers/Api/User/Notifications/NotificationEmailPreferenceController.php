<?php

namespace App\Http\Controllers\Api\User\Notifications;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Notifications\UpdateNotificationEmailPreferencesRequest;
use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: Per-category email notification opt-in/out with staff policy overrides support.
class NotificationEmailPreferenceController extends ApiController
{
    use ResolveCurrentUser;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);

        $prefs = DB::table('notifications.notification_email_preferences')
            ->where('user_id', $pro->id)
            ->get(['category_key', 'enabled'])
            ->keyBy('category_key');

        $perProPolicies = DB::table('notifications.notification_email_policies')
            ->where('user_id', $pro->id)
            ->get(['category_key', 'mode'])
            ->keyBy('category_key');

        $globalPolicies = DB::table('notifications.notification_email_policies')
            ->whereNull('user_id')
            ->get(['category_key', 'mode'])
            ->keyBy('category_key');

        // Filter to categories this account type can receive. Gated categories
        // (those in CAPABILITY_GATE_MAP) are excluded when the capability is false.
        // Universal categories (analytics_weekly, profile_tasks, etc.) are always included.
        $caps = AccountCapabilities::for($pro);
        $gateMap = SendTransactionalNotificationEmailJob::capabilityGateMap();
        $visibleCategories = array_filter(
            NotificationPublisher::categories(),
            static function (string $category) use ($caps, $gateMap): bool {
                $cap = $gateMap[$category] ?? null;

                return $cap === null || $caps->{$cap};
            }
        );

        $result = array_map(function (string $category) use ($prefs, $perProPolicies, $globalPolicies): array {
            $perProMode = $perProPolicies->get($category)?->mode;
            $globalMode = $globalPolicies->get($category)?->mode;
            $prefRow = $prefs->get($category);
            $prefValue = $prefRow !== null ? (bool) $prefRow->enabled : null;
            $mandatory = NotificationPublisher::isMandatory($category);

            // Mandatory categories short-circuit every rung. They ship regardless
            // of policy or preference and the toggle is non-editable in the UI.
            if ($mandatory) {
                $effective = true;
            } elseif ($perProMode === 'force_on') {
                $effective = true;
            } elseif ($perProMode === 'force_off') {
                $effective = false;
            } elseif ($globalMode === 'force_on') {
                $effective = true;
            } elseif ($globalMode === 'force_off') {
                $effective = false;
            } elseif ($prefValue !== null) {
                $effective = $prefValue;
            } else {
                $effective = true;
            }

            return [
                'category' => $category,
                'enabled' => $effective,
                'preference_set' => $prefValue !== null,
                'mandatory' => $mandatory,
                'overridden_by_policy' => $mandatory
                    || in_array($perProMode, ['force_on', 'force_off'], true)
                    || in_array($globalMode, ['force_on', 'force_off'], true),
            ];
        }, $visibleCategories);

        return $this->success(['preferences' => array_values($result)]);
    }

    public function update(UpdateNotificationEmailPreferencesRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $updates = $request->validated()['preferences'];

        // Reject updates for categories the actor's account_type cannot receive.
        $caps = AccountCapabilities::for($pro);
        $gateMap = SendTransactionalNotificationEmailJob::capabilityGateMap();
        foreach ($updates as $update) {
            $cap = $gateMap[$update['category']] ?? null;
            if ($cap !== null && ! $caps->{$cap}) {
                return $this->error('INVALID_CATEGORY_FOR_ACCOUNT_TYPE', 422, [
                    'category' => $update['category'],
                ]);
            }
        }

        foreach ($updates as $update) {
            DB::statement(
                'INSERT INTO notifications.notification_email_preferences (id, user_id, category_key, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())
                 ON CONFLICT (user_id, category_key)
                 DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = NOW()',
                [(string) Str::uuid(), $pro->id, $update['category'], $update['enabled']]
            );
        }

        // Bust the per-professional cache so the next send sees the new pref.
        NotificationPublisher::forget($pro->id);

        return $this->success(['ok' => true]);
    }
}
