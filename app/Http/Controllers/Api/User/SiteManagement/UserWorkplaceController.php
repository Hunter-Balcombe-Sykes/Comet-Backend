<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\UpsertWorkplaceRequest;
use App\Http\Resources\WorkplaceResource;
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
            'workplace' => WorkplaceResource::forWorkplace($workplace),
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
                'address' => trim_or_null($data['address'] ?? null),
                // Structured address components stored alongside the formatted
                // string so manual edits survive the save round-trip.
                'address_line1' => trim_or_null($data['address_line1'] ?? null),
                'city' => trim_or_null($data['city'] ?? null),
                'state' => trim_or_null($data['state'] ?? null),
                'postcode' => trim_or_null($data['postcode'] ?? null),
                'country' => trim_or_null($data['country'] ?? null),
                'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
                'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
                'phone' => trim_or_null($data['phone'] ?? null),
                'website' => trim_or_null($data['website'] ?? null),
                // Archive of the business's old website + Google-sourced category
                // and editorial description (auto-filled from Google Business).
                'previous_website' => trim_or_null($data['previous_website'] ?? null),
                'category' => trim_or_null($data['category'] ?? null),
                'description' => trim_or_null($data['description'] ?? null),
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
            'workplace' => WorkplaceResource::forWorkplace($workplace),
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
            'previousWebsite' => $workplace ? trim_or_null($workplace->previous_website) : null,
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
        $previousWebsite = trim_or_null($validated['previous_website'] ?? null);

        Workplace::updateOrCreate(
            ['site_id' => (string) $site->id],
            ['previous_website' => $previousWebsite],
        );

        return $this->success([
            'previousWebsite' => $previousWebsite,
        ]);
    }
}
