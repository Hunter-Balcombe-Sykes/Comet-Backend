<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\User;
use App\Models\Core\Site\Site;
use Illuminate\Http\JsonResponse;

// Staff inspector for a brand's Google Business Profile config (#GBP-1).
// Mirrors ProfessionalGoogleBusinessProfileController::show. Upsert is an admin
// write — out of scope here.
class StaffGoogleBusinessProfileController extends ApiController
{
    private const SETTINGS_KEY = 'google_business_profile';

    /**
     * GET /staff/professionals/{professional}/site/google-business-profile
     */
    public function show(User $professional): JsonResponse
    {
        $site = Site::query()
            ->where('professional_id', $professional->id)
            ->first();

        if (! $site) {
            return $this->error('Site not found for professional.', 404);
        }

        $settings = is_array($site->settings) ? $site->settings : [];

        return $this->success([
            'google_business_profile' => $this->normalizeProfile($settings[self::SETTINGS_KEY] ?? null),
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

        // place_id is optional — manual workplace entries (no Google pick)
        // still surface to staff. A row with no name has no identity, drop it.
        $placeId = $this->trimOrNull($raw['place_id'] ?? null);
        $name = $this->trimOrNull($raw['name'] ?? null);
        if (! $name) {
            return null;
        }

        $hours = [];
        if (isset($raw['hours']) && is_array($raw['hours'])) {
            $hours = array_values(array_filter($raw['hours'], function ($value) {
                return is_string($value) && trim($value) !== '';
            }));
        }

        return [
            'place_id' => $placeId,
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
            'hours' => $hours,
        ];
    }
}
