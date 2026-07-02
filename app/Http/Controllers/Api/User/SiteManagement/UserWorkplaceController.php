<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\UpsertWorkplaceRequest;
use App\Models\Core\Site\Workplace;
use App\Services\User\SectionVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Store and retrieve a professional's workplace card data — business name,
// address, contact details. The dashboard's editor offers a Google Places
// autofill on the name field; whichever way the visitor populates the
// record, the stored shape is the same. Data lives in site.workplaces (FOUND-4).
class UserWorkplaceController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);

        $workplace = Workplace::query()->where('site_id', $site->id)->first();

        return $this->success([
            'workplace' => $this->normalizeProfile($workplace),
        ]);
    }

    public function upsert(UpsertWorkplaceRequest $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        $data = $request->validated();

        $workplace = Workplace::updateOrCreate(
            ['site_id' => (string) $site->id],
            [
                'name' => (string) $data['name'],
                'address' => $this->trimOrNull($data['address'] ?? null),
                // Structured address components stored alongside the formatted
                // string so manual edits survive the save round-trip.
                'address_line1' => $this->trimOrNull($data['address_line1'] ?? null),
                'city' => $this->trimOrNull($data['city'] ?? null),
                'state' => $this->trimOrNull($data['state'] ?? null),
                'postcode' => $this->trimOrNull($data['postcode'] ?? null),
                'country' => $this->trimOrNull($data['country'] ?? null),
                'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
                'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
                'phone' => $this->trimOrNull($data['phone'] ?? null),
                'website' => $this->trimOrNull($data['website'] ?? null),
                // Archive of the business's old website + Google-sourced category
                // and editorial description (auto-filled from Google Business).
                'previous_website' => $this->trimOrNull($data['previous_website'] ?? null),
                'category' => $this->trimOrNull($data['category'] ?? null),
                'description' => $this->trimOrNull($data['description'] ?? null),
            ],
        );

        // The 'workplace' section block reads its visibility from site.workplaces.
        // Re-evaluate is_enabled so the dashboard's Live toggle frees up the
        // instant the workplace has a name or address.
        $this->visibilityService->reevaluateEnabled(
            (string) $professional->id,
            (string) $site->id,
            'workplace',
        );

        return $this->success([
            'workplace' => $this->normalizeProfile($workplace),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);

        // Instance delete (not a bulk query delete) so model events fire —
        // WorkplaceObserver::deleted sweeps the previous-website design-preset
        // contributions when the card (and its archived URL) goes away.
        Workplace::query()->where('site_id', (string) $site->id)->first()?->delete();

        // The workplace row is gone — re-eval flips is_enabled back to false
        // so the dashboard's Live toggle locks and the public render path stops
        // emitting the (now deleted) section.
        $this->visibilityService->reevaluateEnabled(
            (string) $professional->id,
            (string) $site->id,
            'workplace',
        );

        return $this->success(['workplace' => null]);
    }

    // GET /site/workplace/previous-website — the archived old website, read
    // independently of the rest of the workplace so the Settings card works even
    // before a workplace name is set.
    public function showPreviousWebsite(Request $request): JsonResponse
    {
        $site = $this->currentSite($this->currentUser($request));
        $workplace = Workplace::query()->where('site_id', (string) $site->id)->first();

        return $this->success([
            'previousWebsite' => $workplace ? $this->trimOrNull($workplace->previous_website) : null,
        ]);
    }

    // PATCH /site/workplace/previous-website — set just the archived old website,
    // writing or creating the workplace row without touching the other fields
    // (no name requirement, unlike the full upsert).
    public function setPreviousWebsite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'previous_website' => ['nullable', 'url', 'max:2048'],
        ]);

        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        $previousWebsite = $this->trimOrNull($validated['previous_website'] ?? null);

        Workplace::updateOrCreate(
            ['site_id' => (string) $site->id],
            ['previous_website' => $previousWebsite],
        );

        return $this->success([
            'previousWebsite' => $previousWebsite,
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
    // is optional; the dashboard can save a workplace with just a name.
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
            'previous_website' => $this->trimOrNull($workplace->previous_website),
            'category' => $this->trimOrNull($workplace->category),
            'description' => $this->trimOrNull($workplace->description),
        ];
    }
}
