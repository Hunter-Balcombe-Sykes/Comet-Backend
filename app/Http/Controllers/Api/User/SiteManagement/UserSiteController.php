<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\UpdateBookingSettingsRequest;
use App\Http\Requests\Api\User\Site\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Site settings management (subdomain, skeleton, settings JSON, publish
// status). Powers the dashboard's site editor. Per-user design vars are
// split off into the design_kit field and written to site.design_kits
// (separate table) — the rest goes through UpdateSiteAction.
class UserSiteController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function show(Request $request)
    {
        $professional = $this->currentUser($request);
        $site = $this->currentSite($professional);

        return $this->success(['site' => new SiteResource($site)]);
    }

    public function update(UpdateSiteRequest $request, UpdateSiteAction $action)
    {
        $professional = $this->currentUser($request);

        // Authorization Doctrine: gate the mutation through SitePolicy rather
        // than relying solely on the EnforcePendingDeletionReadOnly middleware.
        // Ownership is structurally guaranteed (the site is resolved from the
        // authenticated professional), so the policy's effective job here is the
        // pending-deletion 423 gate as defence-in-depth.
        $site = $this->currentSite($professional);
        $this->authorizeForUser($professional, 'update', $site);

        $data = $request->validated();

        // design_kit writes to site.design_kits, not site.sites. Pull it out
        // before handing off to UpdateSiteAction (which only knows about the
        // sites row). At this phase no var columns exist on design_kits so
        // any keys are silently dropped — the row stays empty until layer-
        // sweep migrations add column-validated keys.
        $designKit = $data['design_kit'] ?? null;
        unset($data['design_kit']);

        $site = $action->execute($professional, $data);

        if (is_array($designKit)) {
            $this->writeDesignKit($site->id, $designKit);
            // When $data was empty (design-kit-only request), UpdateSiteAction::execute()
            // is a no-op — sites.updated_at is unchanged, SiteObserver never fires.
            // Touch explicitly so the timestamp rotates and the public.profile:* cache key orphans.
            if (!$site->wasChanged()) {
                $site->touch();
            }
            // execute() already fired invalidateSite via $site->save(), but that
            // ran BEFORE the raw design_kits write above — bust again so the new
            // kit (and the email-brand bundle that reads it) is reflected.
            app(SiteCacheService::class)->invalidateSite($site);
        }

        return $this->success(['site' => new SiteResource($site)]);
    }

    /**
     * Persist a partial design kit. Filters incoming keys to the columns
     * that actually exist on site.design_kits — keys with no matching column
     * are silently ignored (FormRequest already validated the shape).
     *
     * At cleanup-deploy time the table has zero var columns, so this is a
     * no-op. As layer-sweep steps add columns, this method automatically
     * picks them up via the information_schema query.
     */
    private function writeDesignKit(string $siteId, array $designKit): void
    {
        if ($designKit === []) {
            return;
        }

        // information_schema is read-only metadata — fetch it outside the
        // transaction so we don't hold the row lock any longer than necessary.
        $columns = DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all();

        $valid = array_intersect_key($designKit, array_flip($columns));
        unset($valid['site_id']); // never let a caller rewrite the FK

        if ($valid === []) {
            return;
        }

        // Wrap the read-then-write in a transaction with a row-level lock so
        // concurrent requests are serialised. Without this, two requests can
        // each read the same row and then each overwrite a disjoint subset of
        // columns, producing a torn state (e.g. colours from one request,
        // typography from another).
        DB::connection('pgsql')->transaction(function () use ($siteId, $valid): void {
            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->get(); // acquire the lock before writing

            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->update($valid);
        });
    }

    /**
     * Dedicated endpoint for booking mode + external URL.
     * Scoped validation so the frontend doesn't need to use the generic site update.
     */
    public function updateBookingSettings(UpdateBookingSettingsRequest $request, UpdateSiteAction $action): JsonResponse
    {
        $validated = $request->validated();
        $professional = $this->currentUser($request);

        $site = $action->execute($professional, [
            'settings' => [
                'booking_mode' => $validated['booking_mode'],
                'manual_booking_url' => $validated['manual_booking_url'] ?? null,
            ],
        ]);

        $settings = is_array($site->settings) ? $site->settings : [];

        return $this->success([
            'booking_mode' => $settings['booking_mode'] ?? 'manual',
            'manual_booking_url' => $settings['manual_booking_url'] ?? null,
        ]);
    }

    public function visibility(UpdateSiteRequest $request, UpdateSiteAction $action)
    {
        $professional = $this->currentUser($request);

        // Same policy gate as update(). NB: this method is not currently wired
        // to a route (the live visibility toggle is SiteVisibilityController);
        // the gate is here so the Authorization Doctrine holds if it is ever
        // routed.
        $site = $this->currentSite($professional);
        $this->authorizeForUser($professional, 'update', $site);

        $data = $request->validated();
        // visibility() shares the request shape with update() — strip the
        // design_kit key so it doesn't leak into the sites row update.
        unset($data['design_kit']);
        $site = $action->execute($professional, $data);

        return $this->success(['site' => new SiteResource($site)]);
    }
}
