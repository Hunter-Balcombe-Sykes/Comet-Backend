<?php

namespace App\Services\User;

use App\Models\Core\User\UserConfirmationPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Manages per-professional "skip confirmation" preferences for destructive actions (delete customer, delete media).
class ConfirmationPreferenceService
{
    public const ACTION_DELETE_CUSTOMER = 'delete_customer';

    public const ACTION_DELETE_MEDIA = 'delete_media';

    public const SUPPORTED_ACTIONS = [
        self::ACTION_DELETE_CUSTOMER,
        self::ACTION_DELETE_MEDIA,
    ];

    /**
     * @return array{delete_customer: bool, delete_media: bool}
     */
    public function getForProfessional(string $userId): array
    {
        $defaults = $this->defaultMap();

        if (trim($userId) === '') {
            return $defaults;
        }

        $rows = UserConfirmationPreference::query()
            ->where('user_id', $userId)
            ->whereIn('action_key', self::SUPPORTED_ACTIONS)
            ->pluck('skip_confirmation', 'action_key')
            ->all();

        foreach ($rows as $actionKey => $skipConfirmation) {
            if (array_key_exists($actionKey, $defaults)) {
                $defaults[$actionKey] = (bool) $skipConfirmation;
            }
        }

        return $defaults;
    }

    /**
     * @param  array<string, bool>  $updates
     * @return array{delete_customer: bool, delete_media: bool}
     */
    public function updateForProfessional(string $userId, array $updates): array
    {
        $normalizedUpdates = $this->normalizeUpdates($updates);
        if (trim($userId) === '' || $normalizedUpdates === []) {
            return $this->getForProfessional($userId);
        }

        DB::transaction(function () use ($userId, $normalizedUpdates): void {
            foreach ($normalizedUpdates as $actionKey => $skipConfirmation) {
                // SEC-1: user_id is no longer fillable, and updateOrCreate()'s INSERT
                // path mass-assigns its search keys — firstOrNew + direct assignment
                // keeps user_id set on a newly-created row.
                $pref = UserConfirmationPreference::query()->firstOrNew([
                    'user_id' => $userId,
                    'action_key' => $actionKey,
                ]);
                $pref->user_id = $userId;
                $pref->skip_confirmation = $skipConfirmation;
                $pref->save();
            }
        });

        return $this->getForProfessional($userId);
    }

    public function shouldRemember(Request $request): bool
    {
        return $request->boolean('remember_confirmation_preference')
            || $request->boolean('always_allow_confirmation')
            || $request->boolean('dont_ask_again');
    }

    public function enableForProfessional(string $userId, string $actionKey): void
    {
        $userId = trim($userId);
        if ($userId === '' || ! in_array($actionKey, self::SUPPORTED_ACTIONS, true)) {
            return;
        }

        // SEC-1: user_id is no longer fillable, and updateOrCreate()'s INSERT path
        // mass-assigns its search keys — firstOrNew + direct assignment keeps
        // user_id set on a newly-created row.
        $pref = UserConfirmationPreference::query()->firstOrNew([
            'user_id' => $userId,
            'action_key' => $actionKey,
        ]);
        $pref->user_id = $userId;
        $pref->skip_confirmation = true;
        $pref->save();
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, bool>
     */
    private function normalizeUpdates(array $updates): array
    {
        $normalized = [];

        foreach (self::SUPPORTED_ACTIONS as $actionKey) {
            if (! array_key_exists($actionKey, $updates)) {
                continue;
            }

            $normalized[$actionKey] = (bool) $updates[$actionKey];
        }

        return $normalized;
    }

    /**
     * @return array{delete_customer: bool, delete_media: bool}
     */
    private function defaultMap(): array
    {
        return [
            self::ACTION_DELETE_CUSTOMER => false,
            self::ACTION_DELETE_MEDIA => false,
        ];
    }
}
