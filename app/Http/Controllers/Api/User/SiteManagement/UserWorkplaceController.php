<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\UpsertWorkplaceRequest;
use App\Http\Resources\WorkplaceResource;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
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
        // SEC-3: defence-in-depth pending-deletion + ownership gate via SitePolicy,
        // mirroring UserSiteController::update's doctrine comment — ownership is
        // structurally guaranteed ($site resolves from the authed professional).
        $this->authorizeForUser($professional, 'update', $site);
        $data = $request->validated();

        // PARTIAL upsert: only fields present in THIS request are written.
        // The Brand Info page saves per-card slices (contact / address /
        // description / hours), so an absent key must never null a field
        // another card owns — the old build-everything-with-??-null shape
        // meant a hours-only save wiped the address and description. `name`
        // is required and always written; an explicitly-sent null (or empty
        // string, trimmed to null) still clears its field.
        $attributes = ['name' => (string) $data['name']];

        foreach ([
            // Structured address components stored alongside the formatted
            // string so manual edits survive the save round-trip.
            'address', 'address_line1', 'city', 'state', 'postcode', 'country',
            'phone', 'website',
            // Archive of the business's old website + Google-sourced category
            // and editorial description (auto-filled from Google Business).
            'previous_website', 'category', 'description',
            // contact_email is manual-only.
            'contact_email',
        ] as $key) {
            if ($request->has($key)) {
                $attributes[$key] = trim_or_null($data[$key] ?? null);
            }
        }

        if ($request->has('latitude')) {
            $attributes['latitude'] = isset($data['latitude']) ? (float) $data['latitude'] : null;
        }
        if ($request->has('longitude')) {
            $attributes['longitude'] = isset($data['longitude']) ? (float) $data['longitude'] : null;
        }
        // opening_hours is the structured per-day map; null clears it.
        if ($request->has('opening_hours')) {
            $attributes['opening_hours'] = $data['opening_hours'] ?? null;
        }

        $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
        $workplace->fill($attributes);

        // Stamp manual provenance for every identity field the user actually
        // sent, merged into the existing map so a manual edit to one field
        // never wipes another field's google-business badge (and vice-versa).
        $workplace->field_sources = $this->mergeManualSources(
            is_array($workplace->field_sources) ? $workplace->field_sources : [],
            $request,
        );
        $workplace->save();

        // Mirror the shared contact fields onto the user so the sitepage
        // contact block and the workplace card read one value. Manual entry is
        // authoritative here regardless of account type — the user is editing
        // these fields by hand right now.
        // Post-save truth: with partial saves the request may not carry these
        // keys, so mirror whatever the workplace now actually holds.
        $this->mirrorContactFields($professional, $workplace->phone, $workplace->contact_email);

        // Business accounts treat the workplace name as their public display
        // name (same rule as GoogleBusinessController::maybeAdoptGoogleName),
        // gated on the capability so the account_type read stays centralized.
        if (AccountCapabilities::for($professional)->google_business_sets_display_name
            && $professional->display_name !== $attributes['name']) {
            $professional->display_name = $attributes['name'];
            $professional->save();
        }

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

    /**
     * Merge 'manual' provenance for the identity fields present in THIS request
     * into the stored field_sources map. Only fields the user actually sent are
     * stamped; the rest of the map (e.g. a google-business badge on a field they
     * didn't touch) is preserved.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function mergeManualSources(array $existing, UpsertWorkplaceRequest $request): array
    {
        $stamp = now()->toIso8601String();

        // Identity fields whose provenance the dashboard surfaces. `name` is
        // always present (required); the rest are stamped only when sent.
        foreach (['name', 'address', 'phone', 'website', 'category', 'contact_email', 'opening_hours'] as $field) {
            if ($request->has($field)) {
                $existing[$field] = ['source' => 'manual', 'at' => $stamp];
            }
        }

        return $existing;
    }

    /**
     * Mirror the workplace phone/email onto the user's public contact columns so
     * the sitepage contact block stays coherent with the workplace card. Writes
     * only when the value actually changes to avoid a redundant save + observer.
     */
    private function mirrorContactFields(User $user, ?string $phone, ?string $email): void
    {
        $dirty = false;

        if ($user->public_contact_number !== $phone) {
            $user->public_contact_number = $phone;
            $dirty = true;
        }
        if ($user->public_contact_email !== $email) {
            $user->public_contact_email = $email;
            $dirty = true;
        }

        if ($dirty) {
            $user->save();
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);
        // SEC-3: defence-in-depth pending-deletion + ownership gate via SitePolicy.
        $this->authorizeForUser($professional, 'update', $site);

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
        // SEC-3: defence-in-depth pending-deletion + ownership gate via SitePolicy.
        $this->authorizeForUser($professional, 'update', $site);
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
