<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\ProfessionalConfirmationPreference;
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
    public function getForProfessional(string $professionalId): array
    {
        $defaults = $this->defaultMap();

        if (trim($professionalId) === '') {
            return $defaults;
        }

        $rows = ProfessionalConfirmationPreference::query()
            ->where('professional_id', $professionalId)
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
    public function updateForProfessional(string $professionalId, array $updates): array
    {
        $normalizedUpdates = $this->normalizeUpdates($updates);
        if (trim($professionalId) === '' || $normalizedUpdates === []) {
            return $this->getForProfessional($professionalId);
        }

        DB::transaction(function () use ($professionalId, $normalizedUpdates): void {
            foreach ($normalizedUpdates as $actionKey => $skipConfirmation) {
                ProfessionalConfirmationPreference::query()->updateOrCreate(
                    [
                        'professional_id' => $professionalId,
                        'action_key' => $actionKey,
                    ],
                    [
                        'skip_confirmation' => $skipConfirmation,
                    ]
                );
            }
        });

        return $this->getForProfessional($professionalId);
    }

    public function enableForProfessional(string $professionalId, string $actionKey): void
    {
        $professionalId = trim($professionalId);
        if ($professionalId === '' || ! in_array($actionKey, self::SUPPORTED_ACTIONS, true)) {
            return;
        }

        ProfessionalConfirmationPreference::query()->updateOrCreate(
            [
                'professional_id' => $professionalId,
                'action_key' => $actionKey,
            ],
            [
                'skip_confirmation' => true,
            ]
        );
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
