<?php

namespace App\Http\Resources;

use App\Enums\PublicFeature;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\DesignRationaleService;
use App\Services\Design\ProfileDesignPresets;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Http\Request;

// API resource for site.sites rows (dashboard + staff side).
// `settings` is passed through unchanged — the dashboard reads non-design
// settings (booking, GBP, etc). Per-user design vars live in site.design_kits
// (separate table), exposed via the design-kit payload. architecture_id is
// back on the wire since 2026-08-24 (it left 2026-08-20, reopened for the
// 'scroll' architecture — see Site::ARCHITECTURE_IDS).
/**
 * @mixin Site
 */
class SiteResource extends ApiResource
{
    /**
     * Include the design transparency line (spec §3). OFF by default: it costs two
     * extra DB reads (contribution ledger + manual kit), so only the design-editor
     * round-trips (site show/update) opt in via withRationale() — staff/self/
     * visibility responses that never render the line skip the cost.
     */
    private bool $withRationale = false;

    /** Opt this resource into emitting design_rationale. Fluent, for the design GET/PATCH. */
    public function withRationale(bool $with = true): static
    {
        $this->withRationale = $with;

        return $this;
    }

    /** Owner whose sector presets to overlay; null = emit raw stored kit only (default, backward compatible). */
    private ?User $resolvedDesignKitOwner = null;

    /**
     * Opt this resource into emitting the preset-merged effective design_kit
     * (ProfileDesignPresets overlaid by the site's manual site.design_kits
     * columns, manual wins) plus design_kit_manual (the manual column names).
     * Fluent, for the design GET/PATCH — the dashboard editor's only read
     * surface, so the /account/design round-trip can show the same
     * auto-determined design the public sitepage already renders instead of
     * a raw, preset-blind partial (I1).
     */
    public function withResolvedDesignKit(User $owner): static
    {
        $this->resolvedDesignKitOwner = $owner;

        return $this;
    }

    /** Owner whose feature availability to emit; null = don't emit the map. */
    private ?User $featureAvailabilityOwner = null;

    /** Opt this resource into emitting feature_availability for $owner. Fluent. */
    public function withFeatureAvailability(User $owner): static
    {
        $this->featureAvailabilityOwner = $owner;

        return $this;
    }

    /**
     * feature_key value => is-available, for every enforceable PublicFeature.
     * Resolves the per-user availability snapshot ONCE (not per key).
     *
     * @return array<string, bool>
     */
    private function featureAvailabilityMap(User $owner): array
    {
        $availability = FeatureAvailability::for($owner);

        return collect(PublicFeature::cases())
            ->mapWithKeys(fn (PublicFeature $feature) => [
                $feature->value => $availability->allows($feature->availabilityKey()),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        // FOUND-16: the 5 promoted columns are the source of truth. Re-merge
        // the non-null ones into the settings object so the response shape is
        // identical before/after the JSONB strip. !== null keeps booleans like
        // show_branding=false while omitting unset keys (absent, not null).
        $promoted = array_filter([
            'show_branding' => $this->show_branding,
            'charlie_enabled' => $this->charlie_enabled,
            'services_auto_sync_enabled' => $this->services_auto_sync_enabled,
            'booking_mode' => $this->booking_mode,
            'manual_booking_url' => $this->manual_booking_url,
        ], static fn ($value): bool => $value !== null);

        $settings = array_merge($settings, $promoted);

        // Stored design-kit vars (non-null partial from site.design_kits).
        $manualDesignKit = $this->resource->designKitVars();

        // I1: when opted in, overlay ProfileDesignPresets (industry-derived,
        // read-time, never persisted) UNDER the manual columns — manual always
        // wins per column, matching the public sitepage's own resolution
        // (IndividualProfilePayloadBuilder::loadDesignKit). Default (opt-out)
        // path is unchanged: raw stored columns only, no manual marker.
        $designKit = $this->resolvedDesignKitOwner !== null
            ? array_merge(ProfileDesignPresets::forUser($this->resolvedDesignKitOwner), $manualDesignKit)
            : $manualDesignKit;

        return array_merge([
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'subdomain' => $this->subdomain,
            'is_published' => $this->is_published,
            'architecture_id' => $this->architecture_id,
            'subdomain_changed_at' => $this->subdomain_changed_at?->toIso8601String(),
            'unpublished_at' => $this->unpublished_at?->toIso8601String(),
            'settings' => (object) $settings,
            // The /account/design editor reads this to show saved choices on
            // reload — until now its only read surface was same-session cache
            // seeding after saves. (object) so an empty kit serialises as {}.
            'design_kit' => (object) $designKit,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ],
            // I4: which design_kit keys are the site's own manual columns (vs
            // preset-derived) — lets the editor show Auto/manual badges and a
            // reset-to-auto affordance. Only emitted alongside the resolved kit;
            // absent (not empty) when opted out, so opt-out clients see no shape
            // change at all.
            $this->resolvedDesignKitOwner !== null
                ? ['design_kit_manual' => array_keys($manualDesignKit)]
                : [],
            // Transparency line (spec §3): plain-language WHY the design looks the
            // way it does, from the contribution ledger + manual overrides. Never
            // exposes raw column names or sensitive detail. Opt-in (withRationale)
            // — only the design-editor round-trips pay its two DB reads; the
            // dashboard renders the summary + an expandable per-area list.
            $this->withRationale
                ? ['design_rationale' => app(DesignRationaleService::class)->forSite(
                    (string) $this->id,
                    $this->user_id,
                )]
                : [],
            // Booking settings surfaced at the top level for the dashboard's
            // booking editor — mirrors the dedicated updateBookingSettings endpoint.
            // Conditionally merged so the keys are absent (not null) when unset,
            // keeping the shape clean for clients that don't care about booking.
            array_key_exists('booking_mode', $settings) ? ['booking_mode' => $settings['booking_mode']] : [],
            array_key_exists('manual_booking_url', $settings) ? ['manual_booking_url' => $settings['manual_booking_url']] : [],
            // Owner-facing feature availability (spec §7). Opt-in via
            // withFeatureAvailability() so only the owner's own site read pays the
            // (already-cached) snapshot lookup; staff/self/visibility responses skip it.
            $this->featureAvailabilityOwner !== null
                ? ['feature_availability' => $this->featureAvailabilityMap($this->featureAvailabilityOwner)]
                : []);
    }
}
