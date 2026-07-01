<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;

// Staff inspector for a professional's stored workplace card data.
// Mirrors UserWorkplaceController::show. Reads from site.workplaces (FOUND-4).
// Upsert is out of scope here — staff write paths are admin-only and not
// wired through this controller.
class StaffWorkplaceController extends ApiController
{
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

        $workplace = Workplace::query()->where('site_id', (string) $site->id)->first();

        return $this->success([
            'workplace' => $this->normalizeProfile($workplace),
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

    // A row with no name has no identity — return null. Every other field
    // is optional. Staff view exposes 11 keys (no previous_website, category,
    // description — those are internal/operational fields).
    private function normalizeProfile(?Workplace $workplace): ?array
    {
        if (! $workplace) {
            return null;
        }

        $name = $this->trimOrNull($workplace->name);
        if (! $name) {
            return null;
        }

        return [
            'name' => $name,
            'address' => $this->trimOrNull($workplace->address),
            'address_line1' => $this->trimOrNull($workplace->address_line1),
            'city' => $this->trimOrNull($workplace->city),
            'state' => $this->trimOrNull($workplace->state),
            'postcode' => $this->trimOrNull($workplace->postcode),
            'country' => $this->trimOrNull($workplace->country),
            'latitude' => $workplace->latitude !== null ? (float) $workplace->latitude : null,
            'longitude' => $workplace->longitude !== null ? (float) $workplace->longitude : null,
            'phone' => $this->trimOrNull($workplace->phone),
            'website' => $this->trimOrNull($workplace->website),
        ];
    }
}
