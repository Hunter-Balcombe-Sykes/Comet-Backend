<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\UpsertWorkplaceRequest;
use App\Services\User\SectionVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Store and retrieve a professional's workplace card data — business name,
// address, contact details. The dashboard's editor offers a Google Places
// autofill on the name field; whichever way the visitor populates the
// record, the stored shape is the same.
class UserWorkplaceController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    private const SETTINGS_KEY = 'workplace';

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        $settings = is_array($site->settings) ? $site->settings : [];

        return $this->success([
            'workplace' => $this->normalizeProfile($settings[self::SETTINGS_KEY] ?? null),
        ]);
    }

    public function upsert(UpsertWorkplaceRequest $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        $data = $request->validated();

        $profile = [
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
            // Archive of the business's old website + Google-sourced category and
            // editorial description (all also auto-filled from Google Business).
            'previous_website' => $this->trimOrNull($data['previous_website'] ?? null),
            'category' => $this->trimOrNull($data['category'] ?? null),
            'description' => $this->trimOrNull($data['description'] ?? null),
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
            'workplace' => $this->normalizeProfile($settings[self::SETTINGS_KEY] ?? null),
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

        return $this->success(['workplace' => null]);
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
        // is optional; the dashboard can save a workplace with just a name.
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
            'previous_website' => $this->trimOrNull($raw['previous_website'] ?? null),
            'category' => $this->trimOrNull($raw['category'] ?? null),
            'description' => $this->trimOrNull($raw['description'] ?? null),
        ];
    }
}
