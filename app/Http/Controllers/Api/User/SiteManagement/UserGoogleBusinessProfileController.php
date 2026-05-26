<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\User\Site\UpsertGoogleBusinessProfileRequest;
use App\Services\User\SectionVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Store and retrieve Google Business Profile settings (place ID, hours, location, contact info).
class UserGoogleBusinessProfileController extends ApiController
{
    use ResolveCurrentUser;
    use ResolveCurrentSite;

    private const SETTINGS_KEY = 'google_business_profile';

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        $settings = is_array($site->settings) ? $site->settings : [];

        return $this->success([
            'google_business_profile' => $this->normalizeProfile($settings[self::SETTINGS_KEY] ?? null),
        ]);
    }

    public function upsert(UpsertGoogleBusinessProfileRequest $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        $data = $request->validated();

        // place_id is nullable to allow manual entries (no Google pick). Persist
        // null rather than the literal string "null" so downstream consumers
        // can branch on isset/!== ''.
        $placeId = $this->trimOrNull($data['place_id'] ?? null);

        $profile = [
            'place_id' => $placeId,
            'name' => (string) $data['name'],
            'address' => $this->trimOrNull($data['address'] ?? null),
            // Structured address components stored alongside the formatted
            // string so manual edits to a single component (e.g. fixing a
            // postcode) survive the save round-trip without rebuilding the
            // formatted form.
            'address_line1' => $this->trimOrNull($data['address_line1'] ?? null),
            'city' => $this->trimOrNull($data['city'] ?? null),
            'state' => $this->trimOrNull($data['state'] ?? null),
            'postcode' => $this->trimOrNull($data['postcode'] ?? null),
            'country' => $this->trimOrNull($data['country'] ?? null),
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'phone' => $this->trimOrNull($data['phone'] ?? null),
            'website' => $this->trimOrNull($data['website'] ?? null),
            'hours' => array_values(array_filter($data['hours'] ?? [], function ($value) {
                return is_string($value) && trim($value) !== '';
            })),
        ];

        $settings = is_array($site->settings) ? $site->settings : [];
        $settings[self::SETTINGS_KEY] = $profile;
        $site->settings = $settings;
        $site->save();

        // The 'workplace' section block reads its visibility from this JSONB.
        // Re-evaluate is_enabled so the dashboard's Live toggle frees up the
        // instant the workplace has a name or address.
        $this->visibilityService->reevaluateEnabled(
            (string) $professional->id,
            (string) $site->id,
            'workplace',
        );

        return $this->success([
            'google_business_profile' => $this->normalizeProfile($settings[self::SETTINGS_KEY] ?? null),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);

        $settings = is_array($site->settings) ? $site->settings : [];
        unset($settings[self::SETTINGS_KEY]);
        $site->settings = $settings;
        $site->save();

        // The 'workplace' section block now has no data — re-eval flips
        // is_enabled back to false so the dashboard's Live toggle locks
        // and the public render path stops emitting the (gone) section.
        $this->visibilityService->reevaluateEnabled(
            (string) $professional->id,
            (string) $site->id,
            'workplace',
        );

        return $this->success(['google_business_profile' => null]);
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

        // place_id is optional for manual entries — a workplace with just a
        // name is a valid record. Reject only if name is missing, since
        // without a name the row carries no identity.
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
