<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;

// Staff inspector for a professional's stored workplace card data.
// Mirrors UserWorkplaceController::show. Upsert is out of scope here —
// staff write paths are admin-only and not wired through this controller.
class StaffWorkplaceController extends ApiController
{
    private const SETTINGS_KEY = 'workplace';

    /**
     * GET /staff/professionals/{professional}/site/workplace
     */
    public function show(User $professional): JsonResponse
    {
        $site = Site::query()
            ->where('user_id', $professional->id)
            ->first();

        if (! $site) {
            return $this->error('Site not found for professional.', 404);
        }

        $settings = is_array($site->settings) ? $site->settings : [];

        return $this->success([
            'workplace' => $this->normalizeProfile($settings[self::SETTINGS_KEY] ?? null),
        ]);
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeProfile(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        // A row with no name has no identity — drop it. Every other field
        // is optional.
        $name = $this->trimOrNull($raw['name'] ?? null);
        if (! $name) {
            return null;
        }

        return [
            'name' => $name,
            'address' => $this->trimOrNull($raw['address'] ?? null),
            'address_line1' => $this->trimOrNull($raw['address_line1'] ?? null),
            'city' => $this->trimOrNull($raw['city'] ?? null),
            'state' => $this->trimOrNull($raw['state'] ?? null),
            'postcode' => $this->trimOrNull($raw['postcode'] ?? null),
            'country' => $this->trimOrNull($raw['country'] ?? null),
            'latitude' => is_numeric($raw['latitude'] ?? null) ? (float) $raw['latitude'] : null,
            'longitude' => is_numeric($raw['longitude'] ?? null) ? (float) $raw['longitude'] : null,
            'phone' => $this->trimOrNull($raw['phone'] ?? null),
            'website' => $this->trimOrNull($raw['website'] ?? null),
        ];
    }
}
