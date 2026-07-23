<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff inspector for a professional's stored workplace card data.
// Mirrors UserWorkplaceController::show. Reads from site.workplaces (FOUND-4).
// Upsert is out of scope here — staff write paths are admin-only and not
// wired through this controller.
class StaffWorkplaceController extends ApiController
{
    /**
     * GET /staff/professionals/{professional}/site/workplace
     */
    public function show(Request $request, User $professional): JsonResponse
    {
        // #SEC-5: staff-dashboard read surface — any staff role.
        /** @var PartnaStaff|null $staff */
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $site = Site::query()
            ->where('user_id', $professional->id)
            ->first();

        if (! $site) {
            return $this->error('Site not found for professional.', 404);
        }

        $workplace = Workplace::query()->where('site_id', (string) $site->id)->first();

        // #PRIV-1: phone/address are PII belonging to the professional — only
        // admin staff see them, mirroring StaffUserController's $showPii gate.
        $showPii = $staff && $staff->isAdmin();

        return $this->success([
            'workplace' => $this->normalizeProfile($workplace, $showPii),
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
    //
    // #PRIV-1 PII gate: address/phone/geo are only shown to admin staff.
    // Note this data is weaker "PII" than a typical gate — once the
    // professional publishes the workplace section block, the exact same
    // fields are public on their sitepage (IndividualProfilePayloadBuilder::
    // buildWorkplace()). The gate still matters for the unpublished case
    // (a workplace row can exist without the section being live), so it's
    // kept for defence-in-depth and parity with StaffUserController's
    // $showPii convention, not because this is always-private data.
    private function normalizeProfile(?Workplace $workplace, bool $showPii = false): ?array
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
            'address_line1' => $showPii ? $this->trimOrNull($workplace->address_line1) : null,
            'city' => $showPii ? $this->trimOrNull($workplace->city) : null,
            'state' => $showPii ? $this->trimOrNull($workplace->state) : null,
            'postcode' => $showPii ? $this->trimOrNull($workplace->postcode) : null,
            'country' => $showPii ? $this->trimOrNull($workplace->country) : null,
            'latitude' => $showPii && $workplace->latitude !== null ? (float) $workplace->latitude : null,
            'longitude' => $showPii && $workplace->longitude !== null ? (float) $workplace->longitude : null,
            'phone' => $showPii ? $this->trimOrNull($workplace->phone) : null,
            'website' => $this->trimOrNull($workplace->website),
        ];
    }
}
